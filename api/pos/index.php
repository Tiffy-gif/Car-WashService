<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';
require_once dirname(__DIR__, 2) . '/core/Helpers.php';
require_once dirname(__DIR__, 2) . '/core/ImageStorage.php';
require_once dirname(__DIR__, 2) . '/core/Loyalty.php';
require_once dirname(__DIR__, 2) . '/core/Auth.php';
require_once dirname(__DIR__, 2) . '/core/BayManager.php';
require_once dirname(__DIR__, 2) . '/core/TechnicalManager.php';
require_once dirname(__DIR__, 2) . '/core/NotificationManager.php';
require_once dirname(__DIR__, 2) . '/core/CashierControl.php';
require_once dirname(__DIR__, 2) . '/core/BankTransferControl.php';
require_once dirname(__DIR__, 2) . '/core/SmartQueueEngine.php';
require_once dirname(__DIR__, 2) . '/core/MembershipManager.php';
require_once dirname(__DIR__, 2) . '/core/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/core/OrderGate.php';

function getSlipFileFromInput(?string $base64Data, ?array $uploadedFile = null): ?array {
    if (!empty($uploadedFile) && !empty($uploadedFile['tmp_name'])) {
        return $uploadedFile;
    }
    if (!empty($base64Data)) {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            $ext = strtolower($type[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
        } else {
            $ext = 'jpg';
        }
        $data = base64_decode($base64Data);
        if ($data !== false && strlen($data) > 0) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'pos_slip_');
            file_put_contents($tmpFile, $data);
            return [
                'name' => 'slip_' . time() . '.' . $ext,
                'type' => 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext),
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($data)
            ];
        }
    }
    return null;
}

$appTimezone = get_setting('app_timezone', 'Asia/Phnom_Penh');
if (!empty($appTimezone)) {
    @date_default_timezone_set($appTimezone);
}

// Set JSON headers and CORS for POS app client
// CORS Origin Validation: Never use wildcard '*'
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isAllowedOrigin = false;

if (!empty($origin)) {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $currentHost = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST));

    // Check if origin is loopback, matching host, or private RFC1918 LAN IP
    $isLoopback = in_array($originHost, ['localhost', '127.0.0.1', '::1'], true);
    $isMatchingHost = ($originHost !== '' && $originHost === $currentHost);
    $isIp = filter_var($originHost, FILTER_VALIDATE_IP) !== false;
    $isPrivateLan = $isIp && (filter_var($originHost, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

    if ($isLoopback || $isMatchingHost || $isPrivateLan) {
        $isAllowedOrigin = true;
    }
}

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Vary: Origin');

    if ($isAllowedOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-Key, X-Device-UID, X-Idempotency-Key, X-Staff-Token');
        header('Access-Control-Expose-Headers: X-Staff-Token');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    if (!empty($origin) && !$isAllowedOrigin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN_CORS_ORIGIN']);
        exit;
    }
    http_response_code(204);
    exit;
}

if (!empty($origin) && !$isAllowedOrigin) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'FORBIDDEN_CORS_ORIGIN',
        'message' => 'Cross-origin requests from external web origins are prohibited.'
    ]);
    exit;
}

$pdo = Database::getInstance();

// Rate limiting on device key authentication failures
$clientIp = class_exists('AuditLogger') ? AuditLogger::getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
try {
    $sinceTenMins = date('Y-m-d H:i:s', time() - 600);
    $checkFail = $pdo->prepare("
        SELECT COUNT(*) FROM audit_logs
        WHERE ip_address = ?
          AND action = 'POS_DEVICE_AUTH_FAILED'
          AND created_at >= ?
    ");
    $checkFail->execute([$clientIp, $sinceTenMins]);
    $isLocalhost = in_array($clientIp, ['127.0.0.1', '::1'], true);
    $maxDeviceAttempts = (defined('APP_ENV') && APP_ENV === 'development') || $isLocalhost ? 200 : 20;
    if ((int)$checkFail->fetchColumn() >= $maxDeviceAttempts) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Too many failed device authentication attempts. Please try again later.'
        ]);
        exit;
    }
} catch (Throwable $t) {
    // Fail open safely if table busy
}

// 1. Authenticate POS Device
// Read API Key from Header: X-Device-Key or Authorization: Bearer <key>
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (empty($headers)) {
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) {
            $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$key] = $v;
        }
    }
}
$apiKey = $headers['X-Device-Key'] ?? $headers['x-device-key'] ?? $_SERVER['HTTP_X_DEVICE_KEY'] ?? null;
if (!$apiKey) {
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $apiKey = $matches[1];
    }
}

if (!$apiKey) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'DEVICE_KEY_REQUIRED',
        'message' => 'Unauthorized: POS Device API Key is missing. Pass header X-Device-Key: <key>'
    ]);
    exit;
}

// Check device status in pos_devices table by SHA-256 hash
$keyHash = hash('sha256', $apiKey);
$devStmt = $pdo->prepare("SELECT * FROM pos_devices WHERE api_key_hash = ?");
$devStmt->execute([$keyHash]);
$device = $devStmt->fetch();

if (!$device) {
    if (class_exists('AuditLogger')) {
        AuditLogger::log('POS_DEVICE_AUTH_FAILED', 'Unrecognized POS device key attempted from IP ' . $clientIp, 'device');
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'UNAPPROVED_DEVICE',
        'message' => 'Forbidden: This POS device is not registered or the API key is invalid.'
    ]);
    exit;
}

if ($device['status'] !== 'active') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'DEVICE_BLOCKED',
        'message' => 'Forbidden: This POS device has been blocked by the Owner.'
    ]);
    exit;
}

// Update device heartbeat
$clientIp = $_SERVER['REMOTE_ADDR'] ?? null;
$pdo->prepare("UPDATE pos_devices SET last_seen_at = CURRENT_TIMESTAMP, last_ip = ? WHERE id = ?")
    ->execute([$clientIp, $device['id']]);

$authDeviceId = (int)($device['id'] ?? 0);
$GLOBALS['authDeviceId'] = $authDeviceId;

// Opportunistic cleanup of expired POS sessions (~1 in 50 requests)
if (mt_rand(1, 50) === 1) {
    try {
        $isSqlite = (Database::getDriver() === 'sqlite');
        $expiryClause = $isSqlite
            ? "expires_at < datetime('now') OR last_seen_at < datetime('now', '-60 minutes')"
            : "expires_at < NOW() OR last_seen_at < DATE_SUB(NOW(), INTERVAL 60 MINUTE)";
        $pdo->exec("DELETE FROM pos_sessions WHERE {$expiryClause}");
    } catch (Throwable $t) {
        // Silently continue
    }
}

// System Revision Fingerprint Generator (Detects price, user and permission changes)
function compute_system_revision(PDO $pdo, int $userId = 0): array {
    try {
        $priceHash = $pdo->query("SELECT MD5(CONCAT(
            IFNULL((SELECT GROUP_CONCAT(CONCAT(service_id, ':', vehicle_type, ':', price_usd) ORDER BY service_id, vehicle_type) FROM service_prices), ''),
            '|',
            IFNULL((SELECT GROUP_CONCAT(CONCAT(id, ':', price_usd) ORDER BY id) FROM products), '')
        ))")->fetchColumn();

        $userHash = $pdo->query("SELECT MD5(CONCAT(
            IFNULL((SELECT GROUP_CONCAT(CONCAT(id, ':', role, ':', status) ORDER BY id) FROM users), ''),
            '|',
            IFNULL((SELECT GROUP_CONCAT(CONCAT(role_id, ':', permission_id) ORDER BY role_id, permission_id) FROM role_permissions), '')
        ))")->fetchColumn();

        $userPerms = [];
        if ($userId > 0 && class_exists('Auth') && method_exists('Auth', 'getPermissionsByUserId')) {
            $userPerms = Auth::getPermissionsByUserId($userId);
        }
        $permHash = md5(json_encode($userPerms));

        $combinedHash = md5($priceHash . ':' . $userHash . ':' . $permHash);

        return [
            'hash' => $combinedHash,
            'prices_hash' => $priceHash ?: '',
            'users_hash' => $userHash ?: '',
            'permissions_hash' => $permHash ?: '',
            'user_permissions' => $userPerms
        ];
    } catch (Exception $e) {
        return [
            'hash' => md5((string)time()),
            'prices_hash' => '',
            'users_hash' => '',
            'permissions_hash' => '',
            'user_permissions' => []
        ];
    }
}

// Router for API action
$rawInput = file_get_contents('php://input');
$inputData = [];
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $inputData = $decoded;
    }
}
// Merge $_POST if present
if (!empty($_POST)) {
    $inputData = array_merge($inputData, $_POST);
}

$action = $_GET['action'] ?? $inputData['action'] ?? '';

// Plain health-check action
if ($action === 'ping' || $action === 'health') {
    echo json_encode([
        'success' => true,
        'status' => 'healthy',
        'device' => $device['device_name'] ?? 'Unknown'
    ]);
    exit;
}

// ========================================================
// CENTRAL AUTHENTICATION & SESSION VALIDATION
// ========================================================
$DEVICE_ONLY_ACTIONS = [
    'staff_login',
    'get_web_session',
    'sync_catalog',
    'get_day_lock_status',
    'ping',
    'health'
];

$sessionUser = null;
$verifiedUserId = 0;

if (!in_array($action, $DEVICE_ONLY_ACTIONS, true)) {
    // Read X-Staff-Token header
    $staffToken = $_SERVER['HTTP_X_STAFF_TOKEN'] ?? '';
    if (!$staffToken && function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-Staff-Token') === 0) {
                $staffToken = trim((string)$v);
                break;
            }
        }
    }
    $staffToken = trim((string)$staffToken);
    $tokenHash = null;

    // If staffToken header is missing, check if request is authenticated via web session cookie
    if ($staffToken === '') {
        if (class_exists('Auth')) {
            Auth::startSession();
            if (Auth::check()) {
                $webUserId = (int)Auth::getUserId();
                $nowStr = date('Y-m-d H:i:s');
                $checkSess = $pdo->prepare("
                    SELECT s.id, s.token_hash 
                    FROM pos_sessions s
                    WHERE s.user_id = ? AND s.device_id = ? AND s.expires_at > ?
                    ORDER BY s.id DESC LIMIT 1
                ");
                $checkSess->execute([$webUserId, (int)$device['id'], $nowStr]);
                $existingSess = $checkSess->fetch(PDO::FETCH_ASSOC);

                if ($existingSess && !empty($existingSess['token_hash'])) {
                    $tokenHash = $existingSess['token_hash'];
                } else {
                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);
                    $expiresAtStr = date('Y-m-d H:i:s', time() + 12 * 3600);
                    $insSess = $pdo->prepare("INSERT INTO pos_sessions (token_hash, user_id, device_id, created_at, last_seen_at, expires_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $insSess->execute([$tokenHash, $webUserId, (int)$device['id'], $nowStr, $nowStr, $expiresAtStr, $clientIp]);
                    if (!headers_sent()) {
                        header('X-Staff-Token: ' . $rawToken);
                    }
                }
            }
        }
    }

    if ($staffToken === '' && empty($tokenHash)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Staff session token required. Pass header X-Staff-Token.'
        ]);
        exit;
    }

    if (empty($tokenHash)) {
        $tokenHash = hash('sha256', $staffToken);
    }
    $sessStmt = $pdo->prepare("
        SELECT s.id AS session_id, s.token_hash, s.user_id, s.device_id, s.created_at, s.last_seen_at, s.expires_at,
               u.id AS id, u.username, u.full_name, u.role, u.status AS user_status
        FROM pos_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.token_hash = ?
    ");
    $sessStmt->execute([$tokenHash]);
    $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Invalid staff session token.'
        ]);
        exit;
    }

    if ($session['user_status'] !== 'active') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Staff account is deactivated or inactive.'
        ]);
        exit;
    }

    if ((int)$session['device_id'] !== $authDeviceId) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Session does not belong to this POS device.'
        ]);
        exit;
    }

    $nowTime = time();
    $expiresTime = strtotime((string)$session['expires_at']);
    if ($expiresTime <= $nowTime) {
        $pdo->prepare("DELETE FROM pos_sessions WHERE id = ?")->execute([$session['session_id']]);
        if ($action === 'staff_logout') {
            echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
            exit;
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Staff session has expired (max 12 hours reached).'
        ]);
        exit;
    }

    $lastSeenTime = strtotime((string)($session['last_seen_at'] ?? $session['created_at']));
    if (($nowTime - $lastSeenTime) > 3600) {
        $pdo->prepare("DELETE FROM pos_sessions WHERE id = ?")->execute([$session['session_id']]);
        if ($action === 'staff_logout') {
            echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
            exit;
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'SESSION_INVALID',
            'message' => 'Staff session has timed out due to 60 minutes of inactivity.'
        ]);
        exit;
    }

    // Slide last_seen_at
    $pdo->prepare("UPDATE pos_sessions SET last_seen_at = CURRENT_TIMESTAMP, ip = ? WHERE id = ?")
        ->execute([$clientIp, $session['session_id']]);

    // Overwrite staff_id, user_id, cashier_id with verified user id and unset client staff_role
    $verifiedUserId = (int)$session['user_id'];
    $session['id'] = $verifiedUserId;
    $session['user_id'] = $verifiedUserId;
    $sessionUser = $session;
    $GLOBALS['sessionUser'] = $session;
    $GLOBALS['authUserId'] = $verifiedUserId;

    $inputData['staff_id'] = $verifiedUserId;
    $inputData['user_id'] = $verifiedUserId;
    $inputData['cashier_id'] = $verifiedUserId;
    unset($inputData['staff_role'], $inputData['role']);

    $_POST['staff_id'] = $verifiedUserId;
    $_POST['user_id'] = $verifiedUserId;
    $_POST['cashier_id'] = $verifiedUserId;
    unset($_POST['staff_role'], $_POST['role']);

    $_GET['staff_id'] = $verifiedUserId;
    $_GET['user_id'] = $verifiedUserId;
    $_GET['cashier_id'] = $verifiedUserId;
    unset($_GET['staff_role'], $_GET['role']);
}

// ========================================================
// CENTRAL PERMISSION MAP & AUTHORIZATION ENFORCEMENT
// ========================================================
$ACTION_PERMISSIONS = [
    // Staff Session
    'staff_logout' => ['*'],

    // Catalog & Customers
    'sync_customers' => ['customers.list'],
    'add_customer_vehicle' => ['customers.add', 'customers.edit'],
    'get_customer_membership' => ['memberships.view', 'customers.list', 'pos.access'],
    'check_customer_membership' => ['memberships.view', 'customers.list', 'pos.access'],

    // Upsell & CRM
    'get_matching_upsells' => ['pos.create_order', 'pos.access'],
    'get_upsell_offers' => ['pos.create_order', 'pos.access'],
    'log_upsell_action' => ['pos.create_order', 'pos.access'],
    'record_upsell_action' => ['pos.create_order', 'pos.access'],
    'evaluate_cart_upsell' => ['pos.create_order', 'pos.access'],
    'process_crm_reminders' => ['crm.reminders'],
    'generate_reminders' => ['crm.reminders'],
    'get_oil_reminders' => ['crm.reminders'],
    'oil_followups' => ['crm.reminders'],
    'get_oil_followups' => ['crm.reminders'],
    'update_oil_contact' => ['crm.reminders'],
    'record_oil_followup' => ['crm.reminders'],
    'save_oil_followup' => ['crm.reminders'],

    // Orders & Sales
    'create_order' => ['pos.create_order'],
    'mark_receipt_printed' => ['pos.create_order'],
    'ack_receipt_issued' => ['pos.create_order', 'pos.pay_order', 'pos.access'],
    'sync_order_status' => ['pos.pay_order', 'orders.change_status'],
    'pay_order' => ['pos.pay_order', 'orders.change_status'],
    'get_today_orders' => ['orders.list', 'pos.access'],
    'get_order' => ['orders.list', 'pos.access'],
    'cancel_order' => ['pos.access'],
    'assign_crew' => ['orders.change_crew'],
    'process_refund' => ['pos.cancel_order', 'orders.cancel'],
    'submit_bank_transfer' => ['pos.pay_order'],
    'approve_bank_transfer' => ['pos.pay_order'],

    // Wash Bays & Queue
    'get_queue' => ['bays.view'],
    'get_bays_and_queue' => ['bays.view'],
    'get_bays' => ['bays.view'],
    'customer_arrived' => ['bays.arrive'],
    'mark_arrived' => ['bays.arrive'],
    'assign_bay' => ['bays.assign'],
    'assign_car_to_bay' => ['bays.assign'],
    'switch_service_sequence' => ['bays.assign', 'pos.access', 'bays.view'],
    'set_service_sequence' => ['bays.assign', 'pos.access', 'bays.view'],
    'finish_car' => ['bays.finish'],
    'finish_job' => ['bays.finish'],
    'finish_bay' => ['bays.finish'],
    'pass_qc' => ['bays.finish', 'pos.access', 'orders.change_status'],
    'qc_pass' => ['bays.finish', 'pos.access', 'orders.change_status'],
    'qc_fail' => ['bays.finish', 'pos.access', 'orders.change_status'],
    'move_queue_car' => ['bays.move_queue'],
    'queue_move_up' => ['bays.move_queue'],
    'get_wait_times' => ['bays.view', 'pos.access'],

    // Daily Reports & Cashier Shifts
    'get_daily_report' => ['reports.view'],
    'save_pos_closing' => ['daily_close.close'],
    'close_day' => ['daily_close.close'],
    'save_daily_close' => ['daily_close.close'],
    'reopen_daily_close' => ['daily_close.close'],
    'reopen_day' => ['daily_close.close'],
    'get_daily_close_status' => ['daily_close.view'],
    'daily_close_status' => ['daily_close.view'],
    'get_day_status' => ['daily_close.view'],
    'close_day_zreport' => ['daily_close.close'],
    'shift_start' => ['pos.access'],
    'shift_end' => ['pos.access'],
    'get_shift_status' => ['pos.access'],
    'open_drawer_no_sale' => ['pos.access'],
    'spot_audit' => ['daily_close.view', 'reports.view'],
    'verify_manager_pin' => ['pos.access'],

    // Workshop & Mechanics
    'save_repair_note' => ['mechanic_jobs.notes'],
    'get_washer_jobs' => ['washer_jobs.view'],
    'get_technical_jobs' => ['mechanic_jobs.view'],
    'receive_from_wash' => ['mechanic_jobs.update'],
    'start_tech_task' => ['mechanic_jobs.update'],
    'finish_tech_task' => ['mechanic_jobs.update'],
    'update_checklist_item' => ['inspection.manage', 'mechanic_jobs.update'],
    'pass_all_inspection' => ['inspection.manage', 'mechanic_jobs.update'],
    'request_parts' => ['parts_requests.create'],
    'finish_tech_job' => ['mechanic_jobs.update'],

    // Notifications & System Sync
    'get_notifications' => ['pos.access', 'bays.view', 'washer_jobs.view', 'mechanic_jobs.view'],
    'check_system_updates' => ['pos.access', 'bays.view', 'washer_jobs.view', 'mechanic_jobs.view'],
    'mark_notification_read' => ['pos.access', 'bays.view', 'washer_jobs.view', 'mechanic_jobs.view'],
    'mark_all_notifications_read' => ['pos.access', 'bays.view', 'washer_jobs.view', 'mechanic_jobs.view'],

    // Appointments & Recognition
    'get_appointment_slots' => ['pos.access'],
    'get_appointments' => ['pos.access'],
    'create_appointment' => ['pos.access'],
    'appointment_arrived' => ['bays.arrive', 'pos.access'],
    'recognize_vehicle' => ['pos.access'],
];

if (!in_array($action, $DEVICE_ONLY_ACTIONS, true)) {
    // 1. Default deny: Unmapped actions get 403 and UNMAPPED_ACTION audit log
    if (!array_key_exists($action, $ACTION_PERMISSIONS)) {
        if (class_exists('AuditLogger')) {
            AuditLogger::log(
                'UNMAPPED_ACTION',
                "Unmapped POS API action '{$action}' attempted by user #{$verifiedUserId}",
                'pos_api',
                $action,
                $verifiedUserId,
                $sessionUser['username'] ?? null,
                $sessionUser['role'] ?? null
            );
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'UNMAPPED_ACTION',
            'message' => "Forbidden: Action '{$action}' is not mapped or allowed."
        ]);
        exit;
    }

    // 2. Enforce action permissions centrally with Auth::userHasPermission
    $requiredPerms = (array)$ACTION_PERMISSIONS[$action];
    $isAllowed = false;

    // Owners keep full access shortcut
    if (($sessionUser['role'] ?? '') === 'owner') {
        $isAllowed = true;
    } else {
        foreach ($requiredPerms as $perm) {
            if ($perm === '*' || (class_exists('Auth') && Auth::userHasPermission($verifiedUserId, $perm))) {
                $isAllowed = true;
                break;
            }
        }
    }

    if (!$isAllowed) {
        if (class_exists('AuditLogger')) {
            AuditLogger::log(
                'PERMISSION_DENIED',
                "User #{$verifiedUserId} ({$sessionUser['username']}) lacked permission for action '{$action}'. Required: " . implode(', ', $requiredPerms),
                'pos_api',
                $action,
                $verifiedUserId,
                $sessionUser['username'] ?? null,
                $sessionUser['role'] ?? null
            );
        }
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PERMISSION_DENIED',
            'message' => "You do not have permission to perform action '{$action}'."
        ]);
        exit;
    }
}

// ========================================================
// 1. ACTION: staff_login
// Log in using the same staff accounts as this system
// ========================================================
if ($action === 'staff_login') {
    // Device-level rate limiting: Max 15 failed logins per device per 10 minutes
    if ($authDeviceId > 0 && CashierControl::isDeviceLoginThrottled($authDeviceId)) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'DEVICE_THROTTLED',
            'message' => 'Too many failed login attempts from this POS terminal. Please wait 10 minutes before trying again.'
        ]);
        exit;
    }

    $username = trim($inputData['username'] ?? $_POST['username'] ?? '');
    $pin = (string)($inputData['pin'] ?? $_POST['pin'] ?? $inputData['password'] ?? $_POST['password'] ?? '');

    if ($username === '' || $pin === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'MISSING_CREDENTIALS',
            'message' => 'Username and 6-digit PIN are required.'
        ]);
        exit;
    }

    if (!Auth::login($username, $pin)) {
        if ($authDeviceId > 0) {
            CashierControl::recordFailedDeviceLogin($authDeviceId);
        }
        $lastErr = Auth::getLastError();
        $errData = Auth::getLastErrorData();

        if ($lastErr === 'account_locked') {
            $remaining = (int)($errData['remaining_minutes'] ?? 10);
            http_response_code(423);
            echo json_encode([
                'success' => false,
                'error' => 'ACCOUNT_LOCKED',
                'message' => "Account is locked due to too many failed attempts. Please try again in {$remaining} minute(s).",
                'remaining_minutes' => $remaining
            ]);
            exit;
        }

        if ($lastErr === 'demo_disabled') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'DEMO_DISABLED',
                'message' => 'Sample test account login is disabled because Test Mode is OFF.'
            ]);
            exit;
        }

        if ($lastErr === 'customer_no_login') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'CUSTOMER_NO_LOGIN',
                'message' => 'Customer account cannot log in to POS.'
            ]);
            exit;
        }

        if ($lastErr === 'account_inactive') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'ACCOUNT_INACTIVE',
                'message' => 'This account has been deactivated.'
            ]);
            exit;
        }

        $remAttempts = $errData['remaining_attempts'] ?? null;
        $msg = ($remAttempts !== null)
            ? "Incorrect PIN. {$remAttempts} attempt" . ($remAttempts === 1 ? '' : 's') . " remaining before lock."
            : "Invalid username or PIN.";

        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_PIN',
            'message' => $msg,
            'remaining_attempts' => $remAttempts
        ]);
        exit;
    }

    if ($authDeviceId > 0) {
        CashierControl::clearFailedDeviceLogins($authDeviceId);
    }

    $uStmt = $pdo->prepare("SELECT id, username, full_name, role, phone, commission_type, commission_rate, status, is_sample_account FROM users WHERE username = ?");
    $uStmt->execute([$username]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    // Fetch all system settings from settings table
    $settings = [];
    $sRows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sRows as $sr) {
        $settings[$sr['setting_key']] = $sr['setting_value'];
    }

    $logoUrl = !empty($settings['shop_logo']) ? (API_BASE_URL . '/' . ltrim($settings['shop_logo'], '/')) : null;
    $khqrUrl = !empty($settings['khqr_image']) ? (API_BASE_URL . '/' . ltrim($settings['khqr_image'], '/')) : null;

    $settings['shop_logo_url'] = $logoUrl;
    $settings['logo_url'] = $logoUrl;
    $settings['khqr_url'] = $khqrUrl;

    $shopData = [
        // Standard full setting keys
        'shop_name_en' => $settings['shop_name_en'] ?? 'Car Wash & Detailing',
        'shop_name_km' => $settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
        'shop_address' => $settings['shop_address'] ?? '',
        'shop_phone' => $settings['shop_phone'] ?? '',
        'shop_logo' => $settings['shop_logo'] ?? '',
        'shop_logo_url' => $logoUrl,
        // Short aliases for compatibility
        'name_en' => $settings['shop_name_en'] ?? 'Car Wash & Detailing',
        'name_km' => $settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
        'address' => $settings['shop_address'] ?? '',
        'phone' => $settings['shop_phone'] ?? '',
        'logo' => $settings['shop_logo'] ?? '',
        'logo_url' => $logoUrl,
        // Receipt settings
        'receipt_footer' => $settings['receipt_footer'] ?? 'Thank you for your visit! / សូមអរគុណ!',
        'receipt_show_logo' => ($settings['receipt_show_logo'] ?? '1') === '1',
        'receipt_language' => $settings['receipt_language'] ?? 'both',
        // Money & Payments
        'exchange_rate' => get_exchange_rate(),
        'aba_account_name' => $settings['aba_account_name'] ?? '',
        'aba_account_num' => $settings['aba_account_num'] ?? '',
        'khqr_image' => $settings['khqr_image'] ?? '',
        'khqr_url' => $khqrUrl,
        // Oil reminders
        'default_oil_mileage' => (int)($settings['default_oil_mileage'] ?? 10000),
        'default_oil_months' => (int)($settings['default_oil_months'] ?? 3),
        // System
        'default_language' => $settings['default_language'] ?? 'en',
        'demo_mode' => (int)($settings['demo_mode'] ?? 0),
        'app_timezone' => $settings['app_timezone'] ?? 'Asia/Phnom_Penh'
    ];

    unset($user['password_hash']);
    $roleDetails = Auth::getUserRoleDetails((int)$user['id']);
    $user['role_code'] = $roleDetails['role_code'] ?? $user['role'];
    $user['role_name_en'] = $roleDetails['role_name_en'] ?? ucfirst($user['role']);
    $user['role_name_km'] = $roleDetails['role_name_km'] ?? $user['role'];
    $user['permissions'] = Auth::getPermissionsByUserId((int)$user['id']);

    // Create session bound to authenticated device
    // Absolute max 12 hours, idle max 60 minutes
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresIn = 12 * 3600; // 12 hours in seconds
    $nowStr = date('Y-m-d H:i:s');
    $expiresAtStr = date('Y-m-d H:i:s', time() + $expiresIn);

    $sessStmt = $pdo->prepare("INSERT INTO pos_sessions (token_hash, user_id, device_id, created_at, last_seen_at, expires_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $sessStmt->execute([$tokenHash, (int)$user['id'], (int)$device['id'], $nowStr, $nowStr, $expiresAtStr, $clientIp]);

    echo json_encode([
        'success' => true,
        'message' => 'Staff login successful',
        'session_token' => $rawToken,
        'expires_in' => $expiresIn,
        'idle_timeout' => 3600,
        'user' => $user,
        'device' => [
            'id' => $device['id'],
            'device_name' => $device['device_name'],
            'device_uid' => $device['device_uid'],
            'location' => $device['location']
        ],
        'exchange_rate' => get_exchange_rate(),
        'settings' => $settings,
        'shop' => $shopData
    ]);
    exit;
}

// ========================================================
// 1a-2. ACTION: get_web_session
// Bridge active Back Office web session (cookie) to POS staff session token
// ========================================================
if ($action === 'get_web_session') {
    Auth::startSession();
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'NO_WEB_SESSION',
            'message' => 'No active authenticated web session.'
        ]);
        exit;
    }

    $webUserId = (int)Auth::getUserId();
    $uStmt = $pdo->prepare("SELECT id, username, full_name, role, phone, commission_type, commission_rate, status, is_sample_account FROM users WHERE id = ?");
    $uStmt->execute([$webUserId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || $user['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'USER_INACTIVE',
            'message' => 'Staff account is inactive or not found.'
        ]);
        exit;
    }

    unset($user['password_hash']);
    $roleDetails = Auth::getUserRoleDetails((int)$user['id']);
    $user['role_code'] = $roleDetails['role_code'] ?? $user['role'];
    $user['role_name_en'] = $roleDetails['role_name_en'] ?? ucfirst($user['role']);
    $user['role_name_km'] = $roleDetails['role_name_km'] ?? $user['role'];
    $user['permissions'] = Auth::getPermissionsByUserId((int)$user['id']);

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresIn = 12 * 3600; // 12 hours
    $nowStr = date('Y-m-d H:i:s');
    $expiresAtStr = date('Y-m-d H:i:s', time() + $expiresIn);

    $sessStmt = $pdo->prepare("INSERT INTO pos_sessions (token_hash, user_id, device_id, created_at, last_seen_at, expires_at, ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $sessStmt->execute([$tokenHash, (int)$user['id'], (int)$device['id'], $nowStr, $nowStr, $expiresAtStr, $clientIp]);

    echo json_encode([
        'success' => true,
        'message' => 'Web session bridged to POS staff session',
        'session_token' => $rawToken,
        'expires_in' => $expiresIn,
        'idle_timeout' => 3600,
        'user' => $user,
        'device' => [
            'id' => $device['id'],
            'device_name' => $device['device_name'],
            'device_uid' => $device['device_uid'],
            'location' => $device['location']
        ]
    ]);
    exit;
}

// ========================================================
// 1b. ACTION: staff_logout
// Delete the active staff session
// ========================================================
if ($action === 'staff_logout') {
    $rawToken = $_SERVER['HTTP_X_STAFF_TOKEN'] ?? $inputData['session_token'] ?? '';
    if (!empty($rawToken)) {
        $tokenHash = hash('sha256', $rawToken);
        $delStmt = $pdo->prepare("DELETE FROM pos_sessions WHERE token_hash = ?");
        $delStmt->execute([$tokenHash]);
    }
    if (class_exists('Auth')) {
        Auth::logout();
    }
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully.'
    ]);
    exit;
}

// ========================================================
// 2. ACTION: sync_catalog
// Get services, tiered prices, products, stock, active washers & mechanics
// ========================================================
if ($action === 'sync_catalog') {
    // 0. Categories (Active service & product categories from Category Control, sorted by sort_order)
    $categoriesRaw = $pdo->query("
        SELECT id, type, code, name_en, name_km, icon, color, sort_order, is_active, updated_at, created_at 
        FROM categories 
        WHERE is_active = 1 AND type IN ('service', 'product') 
        ORDER BY sort_order ASC, id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $catCodeToId = [];
    $catIdToCode = [];
    $categories = array_map(function($c) use (&$catCodeToId, &$catIdToCode) {
        $id = (int)$c['id'];
        $code = (string)$c['code'];
        $type = (string)$c['type'];
        $catCodeToId[$type . ':' . $code] = $id;
        $catCodeToId[$code] = $id;
        $catIdToCode[$id] = $code;

        return [
            'id' => $id,
            'type' => $type,
            'code' => $code,
            'name_en' => $c['name_en'],
            'name_km' => $c['name_km'],
            'icon' => $c['icon'],
            'color' => $c['color'],
            'sort_order' => (int)$c['sort_order'],
            'is_active' => (int)$c['is_active'],
            'updated_at' => $c['updated_at'] ?? $c['created_at'] ?? null
        ];
    }, $categoriesRaw);

    // List updated_at and version calculation
    $latestCatTimestamp = $pdo->query("
        SELECT MAX(COALESCE(updated_at, created_at)) 
        FROM categories 
        WHERE type IN ('service', 'product')
    ")->fetchColumn();

    $catFingerprint = implode(';', array_map(function($c) {
        return "{$c['id']}:{$c['type']}:{$c['code']}:{$c['name_en']}:{$c['name_km']}:{$c['icon']}:{$c['color']}:{$c['sort_order']}:{$c['is_active']}";
    }, $categories));
    $categoriesVersion = md5($catFingerprint);
    $categoriesUpdatedAt = $latestCatTimestamp ?: date('Y-m-d H:i:s');

    // 1. Services
    $servicesRaw = $pdo->query("SELECT id, name_en, name_km, category, category_id, description, icon, is_active, image_path, thumb_path, image_updated_at FROM services WHERE is_active = 1 ORDER BY category_id ASC, category ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $services = array_map(function($s) use ($catCodeToId, $catIdToCode) {
        $s['id'] = (int)$s['id'];
        if ($s['category_id'] !== null && $s['category_id'] !== '') {
            $s['category_id'] = (int)$s['category_id'];
        } elseif (!empty($s['category'])) {
            $s['category_id'] = $catCodeToId['service:' . $s['category']] ?? $catCodeToId[$s['category']] ?? null;
        } else {
            $s['category_id'] = null;
        }

        if (empty($s['category']) && $s['category_id'] && isset($catIdToCode[$s['category_id']])) {
            $s['category'] = $catIdToCode[$s['category_id']];
        }

        $s['image_url'] = ImageStorage::getUrl($s['image_path'] ?? null);
        $s['thumb_url'] = ImageStorage::getUrl($s['thumb_path'] ?? null);
        return $s;
    }, $servicesRaw);

    // 2. Service Pricing Matrix (from service_prices)
    $prices = $pdo->query("SELECT service_id, vehicle_type, price_usd, price_khr, estimated_minutes FROM service_prices")->fetchAll(PDO::FETCH_ASSOC);
    $pricingMap = [];
    foreach ($prices as $pr) {
        $pricingMap[$pr['service_id']][$pr['vehicle_type']] = [
            'usd' => (float)$pr['price_usd'],
            'khr' => (int)$pr['price_khr'],
            'minutes' => (int)($pr['estimated_minutes'] ?? 30)
        ];
    }

    // 3. Products & Spare Parts
    $productsRaw = $pdo->query("SELECT id, name_en, name_km, category, category_id, brand, unit, price_usd, price_khr, stock_qty, min_stock_alert, is_active, image_path, thumb_path, image_updated_at FROM products WHERE is_active = 1 ORDER BY category_id ASC, category ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $products = array_map(function($p) use ($catCodeToId, $catIdToCode) {
        $p['id'] = (int)$p['id'];
        if ($p['category_id'] !== null && $p['category_id'] !== '') {
            $p['category_id'] = (int)$p['category_id'];
        } elseif (!empty($p['category'])) {
            $p['category_id'] = $catCodeToId['product:' . $p['category']] ?? $catCodeToId[$p['category']] ?? null;
        } else {
            $p['category_id'] = null;
        }

        if (empty($p['category']) && $p['category_id'] && isset($catIdToCode[$p['category_id']])) {
            $p['category'] = $catIdToCode[$p['category_id']];
        }

        $p['image_url'] = ImageStorage::getUrl($p['image_path'] ?? null);
        $p['thumb_url'] = ImageStorage::getUrl($p['thumb_path'] ?? null);
        return $p;
    }, $productsRaw);

    // 4. Washers & Mechanics on duty (Check role capability flags or legacy role)
    $washers = $pdo->query("
        SELECT u.id, u.full_name, u.role, u.commission_type, u.commission_rate 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE (r.can_assign_washer = 1 OR u.role = 'washer') 
          AND u.status = 'active' 
        ORDER BY u.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $mechanics = $pdo->query("
        SELECT u.id, u.full_name, u.role, u.commission_type, u.commission_rate 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        WHERE (r.can_assign_mechanic = 1 OR u.role = 'mechanic') 
          AND u.status = 'active' 
        ORDER BY u.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 5. System settings
    $settings = [];
    $sRows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sRows as $sr) {
        $settings[$sr['setting_key']] = $sr['setting_value'];
    }

    $logoUrl = !empty($settings['shop_logo']) ? (API_BASE_URL . '/' . ltrim($settings['shop_logo'], '/')) : null;
    $khqrUrl = !empty($settings['khqr_image']) ? (API_BASE_URL . '/' . ltrim($settings['khqr_image'], '/')) : null;

    $settings['shop_logo_url'] = $logoUrl;
    $settings['logo_url'] = $logoUrl;
    $settings['khqr_url'] = $khqrUrl;

    $shopData = [
        // Standard full setting keys
        'shop_name_en' => $settings['shop_name_en'] ?? 'Car Wash & Detailing',
        'shop_name_km' => $settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
        'shop_address' => $settings['shop_address'] ?? '',
        'shop_phone' => $settings['shop_phone'] ?? '',
        'shop_logo' => $settings['shop_logo'] ?? '',
        'shop_logo_url' => $logoUrl,
        // Short aliases for compatibility
        'name_en' => $settings['shop_name_en'] ?? 'Car Wash & Detailing',
        'name_km' => $settings['shop_name_km'] ?? 'សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
        'address' => $settings['shop_address'] ?? '',
        'phone' => $settings['shop_phone'] ?? '',
        'logo' => $settings['shop_logo'] ?? '',
        'logo_url' => $logoUrl,
        // Receipt settings
        'receipt_footer' => $settings['receipt_footer'] ?? 'Thank you for your visit! / សូមអរគុណ!',
        'receipt_show_logo' => ($settings['receipt_show_logo'] ?? '1') === '1',
        'receipt_language' => $settings['receipt_language'] ?? 'both',
        // Money & Payments
        'exchange_rate' => get_exchange_rate(),
        'aba_account_name' => $settings['aba_account_name'] ?? '',
        'aba_account_num' => $settings['aba_account_num'] ?? '',
        'khqr_image' => $settings['khqr_image'] ?? '',
        'khqr_url' => $khqrUrl,
        // Oil reminders
        'default_oil_mileage' => (int)($settings['default_oil_mileage'] ?? 10000),
        'default_oil_months' => (int)($settings['default_oil_months'] ?? 3),
        // System
        'default_language' => $settings['default_language'] ?? 'en',
        'demo_mode' => (int)($settings['demo_mode'] ?? 0),
        'app_timezone' => $settings['app_timezone'] ?? 'Asia/Phnom_Penh'
    ];

    echo json_encode([
        'success' => true,
        'exchange_rate' => get_exchange_rate(),
        'categories' => $categories,
        'categories_updated_at' => $categoriesUpdatedAt,
        'categories_version' => $categoriesVersion,
        'services' => $services,
        'pricing_matrix' => $pricingMap,
        'products' => $products,
        'washers' => $washers,
        'mechanics' => $mechanics,
        'settings' => $settings,
        'shop' => $shopData,
        'loyalty' => [
            'earn_rate' => Loyalty::getEarnRate(),
            'redeem_rate' => Loyalty::getRedeemRate(),
            'tiers' => Loyalty::getTiersConfig(),
            'reward_rules' => $pdo->query("SELECT * FROM loyalty_reward_rules WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC)
        ],
        'membership_tiers' => MembershipManager::getTiers($pdo),
        'upsell_rules' => $pdo->query("SELECT * FROM upsell_rules WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC),
        'device' => [
            'id' => $device['id'],
            'name' => $device['device_name']
        ],
        'system_revision' => compute_system_revision($pdo),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 3. ACTION: sync_customers
// Get saved customers and vehicles list with real columns
// ========================================================
if ($action === 'sync_customers') {
    // Only select real columns from customers and calculate total visits / total spent
    $customersRaw = $pdo->query("
        SELECT c.id, c.name, c.phone, c.notes, c.status, c.loyalty_points, c.lifetime_points, c.membership_level, c.message_permission, c.created_at,
               COUNT(CASE WHEN o.status != 'cancelled' THEN o.id ELSE NULL END) as total_visits,
               COALESCE(SUM(CASE WHEN o.status = 'paid' AND o.payment_status = 'paid' THEN o.total_usd ELSE 0 END), 0) as total_spent_usd
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id
        WHERE c.status = 'active' OR c.status IS NULL
        GROUP BY c.id
        ORDER BY c.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $tiers = Loyalty::getTiersConfig();
    $customers = array_map(function($c) use ($tiers) {
        $lvl = strtolower($c['membership_level'] ?? 'standard');
        $tierInfo = $tiers[$lvl] ?? $tiers['standard'];
        $c['lifetime_points'] = (int)($c['lifetime_points'] ?? $c['loyalty_points']);
        $c['multiplier'] = (float)$tierInfo['multiplier'];
        $c['wax_discount_percent'] = (float)$tierInfo['wax_discount'];
        $c['redeemable_usd'] = Loyalty::calculateCreditForPoints((int)$c['loyalty_points']);
        $c['message_permission'] = isset($c['message_permission']) ? (int)$c['message_permission'] : 1;
        return $c;
    }, $customersRaw);

    $vehicles = $pdo->query("
        SELECT id, customer_id, plate_number, plate_province, vehicle_type, make_model, color, created_at, last_mileage, next_oil_mileage, next_oil_date
        FROM vehicles
        ORDER BY plate_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'customers' => $customers,
        'vehicles' => $vehicles,
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 4. ACTION: add_customer_vehicle
// Send new customer and vehicle details (phone optional)
// ========================================================
if ($action === 'add_customer_vehicle') {
    $custName = trim($inputData['customer_name'] ?? $_POST['customer_name'] ?? '');
    $custPhone = trim($inputData['customer_phone'] ?? $_POST['customer_phone'] ?? '');
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));
    $vehicleType = trim($inputData['vehicle_type'] ?? $_POST['vehicle_type'] ?? 'small_car');
    $makeModel = trim($inputData['make_model'] ?? $_POST['make_model'] ?? '');
    $color = trim($inputData['color'] ?? $_POST['color'] ?? '');
    $lastMileage = (int)($inputData['mileage'] ?? $_POST['mileage'] ?? 0);
    $msgPermission = isset($inputData['message_permission']) ? (int)$inputData['message_permission'] : (isset($_POST['message_permission']) ? (int)$_POST['message_permission'] : 1);

    if ($plateNumber === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Plate number is required']);
        exit;
    }

    if ($custName === '') {
        $custName = 'Guest (' . $plateNumber . ')';
    }

    $smsConsentNote = "SMS Consent: " . ($msgPermission ? "Yes" : "No");

    // 1. Check or insert customer (Phone is optional)
    $customerId = null;
    if ($custPhone !== '') {
        $findCust = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
        $findCust->execute([$custPhone]);
        $customerId = $findCust->fetchColumn() ?: null;
    }

    if (!$customerId) {
        $insCust = $pdo->prepare("INSERT INTO customers (name, phone, message_permission, notes) VALUES (?, ?, ?, ?)");
        $insCust->execute([$custName, ($custPhone !== '' ? $custPhone : null), $msgPermission, $smsConsentNote]);
        $customerId = (int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE customers SET message_permission = ?, notes = CONCAT(COALESCE(notes, ''), '\n', ?) WHERE id = ?")
            ->execute([$msgPermission, $smsConsentNote, $customerId]);
    }

    // 2. Check or insert vehicle
    $findV = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
    $findV->execute([$plateNumber]);
    $vehicleId = $findV->fetchColumn() ?: null;

    if ($vehicleId) {
        $pdo->prepare("UPDATE vehicles SET customer_id = COALESCE(customer_id, ?), vehicle_type = ?, make_model = COALESCE(NULLIF(?, ''), make_model), color = COALESCE(NULLIF(?, ''), color), last_mileage = CASE WHEN ? > 0 THEN ? ELSE last_mileage END WHERE id = ?")
            ->execute([$customerId, $vehicleType, $makeModel, $color, $lastMileage, $lastMileage, $vehicleId]);
    } else {
        $insV = $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, vehicle_type, make_model, color, last_mileage) VALUES (?, ?, ?, ?, ?, ?)");
        $insV->execute([$customerId, $plateNumber, $vehicleType, $makeModel ?: null, $color ?: null, ($lastMileage > 0 ? $lastMileage : null)]);
        $vehicleId = (int)$pdo->lastInsertId();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Customer & vehicle recorded successfully',
        'customer_id' => $customerId,
        'vehicle_id' => $vehicleId,
        'plate_number' => $plateNumber,
        'customer_name' => $custName,
        'message_permission' => $msgPermission
    ]);
    exit;
}

// ========================================================
// Membership Eligibility & Loyalty Rewards
// ========================================================
if ($action === 'get_customer_membership' || $action === 'check_customer_membership') {
    $customerId = (int)($inputData['customer_id'] ?? $_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $vehicleId = !empty($inputData['vehicle_id']) ? (int)$inputData['vehicle_id'] : (!empty($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : null);
    $services = $inputData['services'] ?? $_GET['services'] ?? $_POST['services'] ?? [];
    if (!is_array($services)) $services = explode(',', (string)$services);
    $serviceIds = array_filter(array_map('intval', $services));

    $tierInfo = MembershipManager::getCustomerTier($pdo, $customerId, $vehicleId);
    $servicesResult = [];
    foreach ($serviceIds as $sid) {
        $servicesResult[$sid] = MembershipManager::checkIncludedServiceEligibility($pdo, $customerId, $vehicleId, $sid);
    }
    $rewards = MembershipManager::checkLoyaltyRewardMilestones($pdo, $customerId);

    echo json_encode([
        'success' => true,
        'customer_id' => $customerId,
        'tier_code' => $tierInfo['tier_code'],
        'tier_name' => $tierInfo['tier_name'],
        'is_member' => $tierInfo['is_member'],
        'services' => $servicesResult,
        'rewards' => $rewards
    ]);
    exit;
}

// ========================================================
// Matching Upsells
// ========================================================
if ($action === 'get_matching_upsells' || $action === 'get_upsell_offers') {
    $services = $inputData['services'] ?? $_GET['services'] ?? $_POST['services'] ?? [];
    $products = $inputData['products'] ?? $_GET['products'] ?? $_POST['products'] ?? [];
    if (!is_array($services)) $services = explode(',', (string)$services);
    if (!is_array($products)) $products = explode(',', (string)$products);
    $serviceIds = array_filter(array_map('intval', $services));
    $productIds = array_filter(array_map('intval', $products));
    $vehicleType = trim((string)($inputData['vehicle_type'] ?? $_GET['vehicle_type'] ?? $_POST['vehicle_type'] ?? 'small_car'));
    $mileage = !empty($inputData['mileage']) ? (int)$inputData['mileage'] : (!empty($_GET['mileage']) ? (int)$_GET['mileage'] : null);

    $offers = MembershipManager::getMatchingUpsellOffers($pdo, $serviceIds, $productIds, $vehicleType, $mileage);
    echo json_encode(['success' => true, 'offers' => $offers]);
    exit;
}

// ========================================================
// Log Upsell Action
// ========================================================
if ($action === 'log_upsell_action' || $action === 'record_upsell_action') {
    $ruleId = (int)($inputData['rule_id'] ?? $_POST['rule_id'] ?? 0);
    $status = trim((string)($inputData['status'] ?? $_POST['status'] ?? 'shown'));
    $cashierId = (int)($inputData['cashier_id'] ?? $_POST['cashier_id'] ?? Auth::getUserId() ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required to log upsell action.']);
        exit;
    }
    $orderId = !empty($inputData['order_id']) ? (int)$inputData['order_id'] : null;
    $customerId = !empty($inputData['customer_id']) ? (int)$inputData['customer_id'] : null;
    $vehicleId = !empty($inputData['vehicle_id']) ? (int)$inputData['vehicle_id'] : null;
    $plate = trim((string)($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));
    $reason = trim((string)($inputData['reason_declined'] ?? $_POST['reason_declined'] ?? ''));

    if ($ruleId > 0 && in_array($status, ['shown', 'accepted', 'declined'], true)) {
        $logId = MembershipManager::recordUpsellAction($pdo, $ruleId, $cashierId, $status, $orderId, $customerId, $vehicleId, $plate ?: null, $reason ?: null);
        echo json_encode(['success' => true, 'log_id' => $logId]);
    } else {
        echo json_encode(['success' => false, 'error' => 'invalid_params']);
    }
    exit;
}

// ========================================================
// Process Due Reminders (Oil & Wash)
// ========================================================
if ($action === 'process_crm_reminders' || $action === 'generate_reminders') {
    $res = MembershipManager::processDueReminders($pdo, true);
    echo json_encode(array_merge(['success' => true], $res));
    exit;
}

// ========================================================
// 5. ACTION: create_order
// Server-authoritative pricing, atomic transaction, stock check
// ========================================================
if ($action === 'create_order') {
    // 5A. Check Idempotency Key
    $idempotencyKey = $headers['X-Idempotency-Key'] ?? $headers['x-idempotency-key'] ?? ($inputData['idempotency_key'] ?? $_POST['idempotency_key'] ?? null);

    if ($idempotencyKey) {
        $existingOrder = $pdo->prepare("SELECT id, order_number, status, payment_status, total_usd, total_khr, created_at FROM orders WHERE idempotency_key = ?");
        $existingOrder->execute([$idempotencyKey]);
        $dup = $existingOrder->fetch(PDO::FETCH_ASSOC);

        if ($dup) {
            echo json_encode([
                'success' => true,
                'is_duplicate' => true,
                'message' => 'Order already recorded previously (Idempotent replay)',
                'order' => $dup
            ]);
            exit;
        }
    }

    // Check if sales are locked (11:59 PM cutoff, unclosed previous day, or day closed)
    $dayLock = CashierControl::isDayLocked();
    if (!empty($dayLock['locked'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'SALES_LOCKED',
            'message' => $dayLock['message']
        ]);
        exit;
    }

    // Check if the day is closed for this device or shop
    $todayDate = date('Y-m-d');
    $chkClosedStmt = $pdo->prepare("
        SELECT id, close_date, closed_at, cashier_name 
        FROM pos_closings 
        WHERE close_date = ? AND pos_device_id = ? AND status = 'closed'
        ORDER BY id DESC LIMIT 1
    ");
    $chkClosedStmt->execute([$todayDate, $authDeviceId]);
    $closedDayRec = $chkClosedStmt->fetch(PDO::FETCH_ASSOC);
    if (!$closedDayRec) {
        $chkOldStmt = $pdo->prepare("
            SELECT id, close_date, closed_at, closed_by_name as cashier_name 
            FROM pos_daily_closes 
            WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL) AND status = 'closed'
            ORDER BY id DESC LIMIT 1
        ");
        $chkOldStmt->execute([$todayDate, $authDeviceId]);
        $closedDayRec = $chkOldStmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($closedDayRec) {
        http_response_code(403);
        $closedByName = $closedDayRec['cashier_name'] ?? 'Manager';
        echo json_encode([
            'success' => false,
            'error' => 'DAY_CLOSED',
            'message' => "Sales are locked. The day has been closed on this terminal by {$closedByName}. Reopen the day to resume."
        ]);
        exit;
    }

    // 5B. Extract & Validate Order Payload
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));
    $customerId = !empty($inputData['customer_id']) ? (int)$inputData['customer_id'] : (!empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null);
    $customerName = trim($inputData['customer_name'] ?? $_POST['customer_name'] ?? '');
    $customerPhone = trim($inputData['customer_phone'] ?? $_POST['customer_phone'] ?? '');
    $vehicleType = trim($inputData['vehicle_type'] ?? $_POST['vehicle_type'] ?? 'small_car');
    $vehicleId = !empty($inputData['vehicle_id']) ? (int)$inputData['vehicle_id'] : (!empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null);

    if ($customerId) {
        $cLookup = $pdo->prepare("SELECT name, phone FROM customers WHERE id = ?");
        $cLookup->execute([$customerId]);
        $cFound = $cLookup->fetch(PDO::FETCH_ASSOC);
        if ($cFound) {
            if ($customerName === '' || $customerName === 'Walk-in Guest') {
                $customerName = $cFound['name'];
            }
            if ($customerPhone === '') {
                $customerPhone = (string)($cFound['phone'] ?? '');
            }
        }
    }
    if ($customerName === '') {
        $customerName = 'Walk-in Guest';
    }

    if ($plateNumber === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Plate number is required']);
        exit;
    }

    $validVehicleTypes = ['motorbike', 'small_car', 'suv_pickup', 'van_truck'];
    if (!in_array($vehicleType, $validVehicleTypes, true)) {
        $vehicleType = 'small_car';
    }

    // Status Validation: New orders entering queue are always 'waiting' unless actively washing or finished
    $allowedStatuses = ['waiting', 'washing', 'finished', 'cancelled'];
    $isPaid = !empty($inputData['is_paid']) || !empty($_POST['is_paid']);
    $rawStatus = trim($inputData['status'] ?? $_POST['status'] ?? 'waiting');
    // Operational status for newly created queue order is 'waiting' (not 'paid')
    $status = ($rawStatus === 'paid' || empty($rawStatus)) ? 'waiting' : $rawStatus;
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'waiting';
    }

    // Auto find or create customer & vehicle (Phone is optional)
    if (!$customerId && $customerPhone !== '') {
        $cStmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
        $cStmt->execute([$customerPhone]);
        $customerId = $cStmt->fetchColumn() ?: null;
    }
    if (!$customerId && $customerName !== '' && $customerName !== 'Walk-in Guest') {
        $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)")
            ->execute([$customerName, ($customerPhone !== '' ? $customerPhone : null)]);
        $customerId = (int)$pdo->lastInsertId();
    }

    if (!$vehicleId && $plateNumber !== '') {
        $vStmt = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
        $vStmt->execute([$plateNumber]);
        $vehicleId = $vStmt->fetchColumn() ?: null;
        if (!$vehicleId) {
            $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, vehicle_type) VALUES (?, ?, ?)")
                ->execute([$customerId, $plateNumber, $vehicleType]);
            $vehicleId = (int)$pdo->lastInsertId();
        }
    }

    // Items array
    $items = $inputData['items'] ?? [];
    if (empty($items) && isset($_POST['items'])) {
        $items = json_decode($_POST['items'], true) ?: [];
    }

    if (empty($items)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'At least one service or product item must be selected']);
        exit;
    }

    $pointsToRedeem = max(0, (int)($inputData['points_redeemed'] ?? $_POST['points_redeemed'] ?? 0));
    $rawDiscount = isset($inputData['discount_usd']) ? (float)$inputData['discount_usd'] : (isset($_POST['discount_usd']) ? (float)$_POST['discount_usd'] : 0.0);
    if ($rawDiscount < 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'DISCOUNT_EXCEEDS_TOTAL',
            'message' => "Discount cannot be negative. Total cannot be below zero."
        ]);
        exit;
    }
    $manualDiscountUsd = $rawDiscount;
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');
    $repairNotes = trim($inputData['repair_notes'] ?? $_POST['repair_notes'] ?? '');
    $currentMileage = !empty($inputData['current_mileage']) ? (int)$inputData['current_mileage'] : (!empty($_POST['current_mileage']) ? (int)$_POST['current_mileage'] : null);
    $defaultOilKm = (int)get_setting('default_oil_mileage', '10000');
    $defaultOilMo = (int)get_setting('default_oil_months', '3');
    $mileageInterval = (int)($inputData['mileage_interval'] ?? $_POST['mileage_interval'] ?? $defaultOilKm);
    $monthInterval = (int)($inputData['month_interval'] ?? $_POST['month_interval'] ?? $defaultOilMo);

    $nextOilMileage = ($currentMileage && $currentMileage > 0) ? ($currentMileage + $mileageInterval) : null;
    $nextOilDate = ($currentMileage && $currentMileage > 0) ? date('Y-m-d', strtotime("+{$monthInterval} months")) : null;

    $paymentMethod = trim($inputData['payment_method'] ?? $_POST['payment_method'] ?? ($isPaid ? 'cash' : 'unpaid'));
    $tenderedUsd = (float)($inputData['amount_tendered_usd'] ?? $_POST['amount_tendered_usd'] ?? 0);
    $paymentRef = trim($inputData['payment_ref'] ?? $_POST['payment_ref'] ?? '');
    $staffUserId = !empty($inputData['staff_id']) ? (int)$inputData['staff_id'] : (!empty($_POST['staff_id']) ? (int)$_POST['staff_id'] : null);

    $rawRepairStatus = trim((string)($inputData['repair_status'] ?? $_POST['repair_status'] ?? ''));
    $repairStatus = ($rawRepairStatus !== '' && $rawRepairStatus !== 'paid') ? $rawRepairStatus : null;

    $assignedWashers = $inputData['washers'] ?? $_POST['washers'] ?? [];
    $assignedMechanics = $inputData['mechanics'] ?? $_POST['mechanics'] ?? [];

    // Offline order & creator extraction (Item 5)
    $rawCreatedBy = !empty($inputData['created_by_staff_id']) ? (int)$inputData['created_by_staff_id'] : (!empty($_POST['created_by_staff_id']) ? (int)$_POST['created_by_staff_id'] : 0);
    $rawCreatedAt = trim((string)($inputData['created_at'] ?? $_POST['created_at'] ?? ''));
    $isOfflineSync = (!empty($rawCreatedBy) || !empty($inputData['is_offline_sync']) || !empty($_POST['is_offline_sync']) || !empty($rawCreatedAt));

    $orderCreatedBy = null;
    $creatorStaffUser = null;
    $creatorName = null;

    if ($rawCreatedBy > 0) {
        $cStfStmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM users WHERE id = ?");
        $cStfStmt->execute([$rawCreatedBy]);
        $cStf = $cStfStmt->fetch(PDO::FETCH_ASSOC);
        if ($cStf && $cStf['status'] === 'active') {
            $orderCreatedBy = (int)$cStf['id'];
            $creatorStaffUser = $cStf;
            $creatorName = $cStf['full_name'];
        } else {
            // ONLY if user exists and is active, otherwise null
            $orderCreatedBy = null;
            $creatorName = "Inactive or unknown user #{$rawCreatedBy}";
        }
    } else {
        $orderCreatedBy = $verifiedUserId;
        $creatorStaffUser = $sessionUser;
        $creatorName = $sessionUser['full_name'] ?? 'Staff';
    }

    $orderSyncedBy = $verifiedUserId;

    // Validate created_at from offline payload if present
    $orderCreatedAt = date('Y-m-d H:i:s');
    if (!empty($rawCreatedAt)) {
        $ts = strtotime($rawCreatedAt);
        if ($ts !== false && $ts > 0 && $ts <= (time() + 300)) {
            $orderCreatedAt = date('Y-m-d H:i:s', $ts);
        }
    }

    // Validate Cashier / Staff ID: must exist, active, and have permission to create order
    if (empty($staffUserId)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_REQUIRED',
            'message' => 'Staff ID is required to create an order.'
        ]);
        exit;
    }

    $stfStmt = $pdo->prepare("SELECT id, role, role_id, status FROM users WHERE id = ?");
    $stfStmt->execute([$staffUserId]);
    $stf = $stfStmt->fetch();
    $hasCreatePerm = $stf && ($stf['role'] === 'owner' || in_array($stf['role'], ['cashier', 'owner'], true) || Auth::userHasPermission($staffUserId, 'pos.create_order') || Auth::userHasPermission($staffUserId, 'pos.access'));
    if (!$stf || $stf['status'] !== 'active' || !$hasCreatePerm) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PERMISSION_DENIED',
            'message' => "Order staff ID {$staffUserId} does not have permission to create orders."
        ]);
        exit;
    }

        // Validate discount permission if discount is applied
        if ($manualDiscountUsd > 0.001) {
            if ($stf['role'] !== 'owner' && !Auth::userHasPermission($staffUserId, 'pos.discount')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Order staff ID {$staffUserId} does not have permission to apply discounts (pos.discount required)."
                ]);
                exit;
            }
            $creatorId = !empty($creatorStaffUser['id']) ? (int)$creatorStaffUser['id'] : (!empty($creatorStaffUser['user_id']) ? (int)$creatorStaffUser['user_id'] : (int)$orderCreatedBy);
            if ($creatorStaffUser && ($creatorStaffUser['role'] ?? '') !== 'owner' && $creatorId > 0 && !Auth::userHasPermission($creatorId, 'pos.discount')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Original order creator (ID {$creatorId}) did not have permission to apply discounts."
                ]);
                exit;
            }
        }

        // ========================================================
        // 5B. Overrides in create_order (Zero-stock, Discount, Price Overrides)
        // Require manager_username + manager_pin, verify on server, reject bare flags, log both IDs
        // ========================================================
        $requiresOverride = false;
        $overrideReasons = [];

        $overrideFlags = [
            'override', 'has_override', 'is_override',
            'manager_override', 'has_manager_override',
            'zero_stock_override', 'has_zero_stock_override',
            'price_override', 'has_price_override',
            'discount_override', 'has_discount_override'
        ];
        foreach ($overrideFlags as $flg) {
            if (!empty($inputData[$flg]) || !empty($_POST[$flg])) {
                $requiresOverride = true;
                $overrideReasons[] = "Order override flag '{$flg}'";
            }
        }

        foreach ($items as $chkIt) {
            foreach ($overrideFlags as $flg) {
                if (!empty($chkIt[$flg])) {
                    $requiresOverride = true;
                    $overrideReasons[] = "Item override flag '{$flg}'";
                    break 2;
                }
            }
        }

        // Check stock availability in advance for zero-stock override requirement
        $preliminaryProductQtys = [];
        foreach ($items as $chkIt) {
            $chkType = $chkIt['type'] ?? 'service';
            $chkPId = !empty($chkIt['product_id']) ? (int)$chkIt['product_id'] : (!empty($chkIt['id']) && in_array($chkType, ['product', 'spare_part'], true) ? (int)$chkIt['id'] : null);
            $chkQty = max(1, (int)($chkIt['qty'] ?? 1));
            if ($chkPId && in_array($chkType, ['product', 'spare_part'], true)) {
                $preliminaryProductQtys[$chkPId] = ($preliminaryProductQtys[$chkPId] ?? 0) + $chkQty;
            }
        }

        if (!empty($preliminaryProductQtys)) {
            $chkStockStmt = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ?");
            foreach ($preliminaryProductQtys as $pId => $reqQty) {
                $chkStockStmt->execute([$pId]);
                $pRow = $chkStockStmt->fetch(PDO::FETCH_ASSOC);
                if ($pRow && (int)$pRow['stock_qty'] < $reqQty) {
                    $requiresOverride = true;
                    $overrideReasons[] = "Zero-stock for '{$pRow['name_en']}' (Available: {$pRow['stock_qty']}, Requested: {$reqQty})";
                }
            }
        }

        $verifiedOverrideManager = null;
        if ($requiresOverride) {
            $mgrUser = trim((string)($inputData['manager_username'] ?? $_POST['manager_username'] ?? ''));
            $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');

            if ($mgrUser === '' || $mgrPin === '') {
                foreach ($items as $chkIt) {
                    if (!empty($chkIt['manager_username']) && !empty($chkIt['manager_pin'])) {
                        $mgrUser = trim((string)$chkIt['manager_username']);
                        $mgrPin = (string)$chkIt['manager_pin'];
                        break;
                    }
                }
            }

            if ($mgrUser === '' || $mgrPin === '') {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'MANAGER_PIN_REQUIRED',
                    'message' => 'Manager username and PIN are required for authorization overrides.'
                ]);
                exit;
            }

            try {
                $verifiedOverrideManager = CashierControl::verifyManagerPin($mgrUser, $mgrPin, $authDeviceId);
            } catch (ManagerPinThrottledException $e) {
                http_response_code(429);
                echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
                exit;
            } catch (Exception $e) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid manager credentials', 'message' => $e->getMessage()]);
                exit;
            }

            if (!in_array($verifiedOverrideManager['manager_role'], ['manager', 'owner', 'system_admin'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid manager credentials', 'message' => 'Approver must have manager or owner role.']);
                exit;
            }

            $overrideDetails = "Manager {$verifiedOverrideManager['manager_name']} (#{$verifiedOverrideManager['manager_id']}) authorized order override for Cashier #{$staffUserId} on Device #{$authDeviceId}. Reasons: " . implode('; ', array_unique($overrideReasons));
            try {
                AuditLogger::logStrict($pdo, 'ORDER_OVERRIDE', $overrideDetails, 'order', null, $staffUserId, $stf['username'] ?? '', $stf['role'] ?? '');
            } catch (Exception $ae) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'AUDIT_LOG_FAILED',
                    'message' => 'Order override rejected: Failed to record mandatory audit record (' . $ae->getMessage() . ').'
                ]);
                exit;
            }
        }

        // Validate payment permission if paid upfront
        if ($isPaid) {
            if ($stf['role'] !== 'owner' && !Auth::userHasPermission($staffUserId, 'pos.pay_order')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Order staff ID {$staffUserId} does not have permission to process payments (pos.pay_order required)."
                ]);
                exit;
            }
            $creatorId = !empty($creatorStaffUser['id']) ? (int)$creatorStaffUser['id'] : (!empty($creatorStaffUser['user_id']) ? (int)$creatorStaffUser['user_id'] : (int)$orderCreatedBy);
            if ($creatorStaffUser && ($creatorStaffUser['role'] ?? '') !== 'owner' && $creatorId > 0 && !Auth::userHasPermission($creatorId, 'pos.pay_order')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Original order creator (ID {$creatorId}) did not have permission to process payments."
                ]);
                exit;
            }
        }

    // Validate Assigned Washers: must exist, active, role washer OR can_assign_washer = 1
    if (!empty($assignedWashers) && is_array($assignedWashers)) {
        $chkW = $pdo->prepare("SELECT u.id, u.role, u.status, r.can_assign_washer FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        foreach ($assignedWashers as $wId) {
            $chkW->execute([(int)$wId]);
            $wUser = $chkW->fetch();
            $isWashEligible = $wUser && ($wUser['role'] === 'washer' || (!empty($wUser['can_assign_washer']) && (int)$wUser['can_assign_washer'] === 1));
            if (!$wUser || $wUser['status'] !== 'active' || !$isWashEligible) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_WASHER_ROLE',
                    'message' => "Invalid washer assigned (ID {$wId}): Must be an active user with washer capability."
                ]);
                exit;
            }
        }
    }

    // Validate Assigned Mechanics: must exist, active, role mechanic OR can_assign_mechanic = 1
    if (!empty($assignedMechanics) && is_array($assignedMechanics)) {
        $chkM = $pdo->prepare("SELECT u.id, u.role, u.status, r.can_assign_mechanic FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        foreach ($assignedMechanics as $mId) {
            $chkM->execute([(int)$mId]);
            $mUser = $chkM->fetch();
            $isMechEligible = $mUser && ($mUser['role'] === 'mechanic' || (!empty($mUser['can_assign_mechanic']) && (int)$mUser['can_assign_mechanic'] === 1));
            if (!$mUser || $mUser['status'] !== 'active' || !$isMechEligible) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_MECHANIC_ROLE',
                    'message' => "Invalid mechanic assigned (ID {$mId}): Must be an active user with mechanic capability."
                ]);
                exit;
            }
        }
    }

    // BEGIN ATOMIC TRANSACTION
    $pdo->beginTransaction();
    try {
        // Aggregate product quantities to verify stock
        $productQuantities = [];
        foreach ($items as $it) {
            $type = $it['type'] ?? 'service';
            $pId = !empty($it['product_id']) ? (int)$it['product_id'] : (!empty($it['id']) && in_array($type, ['product', 'spare_part'], true) ? (int)$it['id'] : null);
            $qty = max(1, (int)($it['qty'] ?? 1));
            if ($pId && in_array($type, ['product', 'spare_part'], true)) {
                $productQuantities[$pId] = ($productQuantities[$pId] ?? 0) + $qty;
            }
        }

        // Check stock availability with row-level locks
        foreach ($productQuantities as $prodId => $reqQty) {
            $pCheck = $pdo->prepare("SELECT id, name_en, stock_qty FROM products WHERE id = ? FOR UPDATE");
            $pCheck->execute([$prodId]);
            $prodRow = $pCheck->fetch(PDO::FETCH_ASSOC);

            if (!$prodRow) {
                throw new Exception("Product ID {$prodId} not found in system.");
            }

            if ((int)$prodRow['stock_qty'] < $reqQty) {
                if (!$verifiedOverrideManager) {
                    throw new Exception("ITEM_OUT_OF_STOCK: Insufficient stock for '{$prodRow['name_en']}'. Available: {$prodRow['stock_qty']}, Requested: {$reqQty}. Manager override required.");
                }
                AuditLogger::log('STOCK_OVERRIDE', "Manager {$verifiedOverrideManager['manager_name']} (#{$verifiedOverrideManager['manager_id']}) authorized zero-stock sale for product ID {$prodId} ({$prodRow['name_en']}) for Cashier #{$staffUserId}", 'order', null, $staffUserId, $stf['username'] ?? '', $stf['role'] ?? '');
            }
        }

        // Server-authoritative Pricing: look up prices from database, ignore POS prices
        $subtotalUsd = 0.00;
        $repairLaborTotal = 0.00;
        $sparePartsTotal = 0.00;
        $oilProductName = null;
        $preparedItems = [];
        $coveredMembershipUsages = [];

        // Collect cart service & product IDs for server-side upsell verification
        $cartServiceIds = [];
        $cartProductIds = [];
        foreach ($items as $chkIt) {
            $chkType = $chkIt['type'] ?? 'service';
            $chkSId = !empty($chkIt['service_id']) ? (int)$chkIt['service_id'] : (!empty($chkIt['id']) && in_array($chkType, ['service', 'repair'], true) ? (int)$chkIt['id'] : null);
            $chkPId = !empty($chkIt['product_id']) ? (int)$chkIt['product_id'] : (!empty($chkIt['id']) && in_array($chkType, ['product', 'spare_part'], true) ? (int)$chkIt['id'] : null);
            if ($chkSId) $cartServiceIds[] = $chkSId;
            if ($chkPId) $cartProductIds[] = $chkPId;
        }
        $matchingUpsellOffers = MembershipManager::getMatchingUpsellOffers($pdo, $cartServiceIds, $cartProductIds, $vehicleType, $currentMileage);

        // Prepared statements for price lookup
        $svcPriceStmt = $pdo->prepare("SELECT price_usd FROM service_prices WHERE service_id = ? AND vehicle_type = ?");
        $svcFallbackStmt = $pdo->prepare("SELECT price_usd FROM service_prices WHERE service_id = ? LIMIT 1");
        $svcInfoStmt = $pdo->prepare("SELECT name_en, category FROM services WHERE id = ?");
        $prodInfoStmt = $pdo->prepare("SELECT name_en, category, price_usd, stock_qty FROM products WHERE id = ?");

        foreach ($items as $it) {
            $type = $it['type'] ?? 'service';
            $pId = !empty($it['product_id']) ? (int)$it['product_id'] : (!empty($it['id']) && in_array($type, ['product', 'spare_part'], true) ? (int)$it['id'] : null);
            $sId = !empty($it['service_id']) ? (int)$it['service_id'] : (!empty($it['id']) && in_array($type, ['service', 'repair'], true) ? (int)$it['id'] : null);
            $qty = max(1, (int)($it['qty'] ?? 1));

            $unitPriceUsd = 0.00;
            $itemName = $it['name'] ?? 'Item';

            if (in_array($type, ['service', 'repair'], true) && $sId) {
                $svcPriceStmt->execute([$sId, $vehicleType]);
                $priceRow = $svcPriceStmt->fetch();
                if ($priceRow) {
                    $unitPriceUsd = (float)$priceRow['price_usd'];
                } else {
                    $svcFallbackStmt->execute([$sId]);
                    $fallbackRow = $svcFallbackStmt->fetch();
                    $unitPriceUsd = $fallbackRow ? (float)$fallbackRow['price_usd'] : 0.00;
                }

                $svcInfoStmt->execute([$sId]);
                $svcInfo = $svcInfoStmt->fetch();
                if ($svcInfo) {
                    $itemName = $svcInfo['name_en'];
                }

                // Check for included Member Benefit (Requirement 1: Included services show at no charge automatically)
                $isFreeBenefit = false;
                if ($customerId) {
                    $elig = MembershipManager::checkIncludedServiceEligibility($pdo, (int)$customerId, $vehicleId, $sId);
                    if ($elig['is_free']) {
                        $isFreeBenefit = true;
                        $coveredMembershipUsages[] = [
                            'service_id' => $sId,
                            'tier_code' => $elig['tier_code']
                        ];
                    } else {
                        // Fallback check against customer membership_level column
                        $cTier = $pdo->prepare("SELECT membership_level FROM customers WHERE id = ?");
                        $cTier->execute([$customerId]);
                        $mTier = strtolower(trim($cTier->fetchColumn() ?: ''));
                        if (in_array($mTier, ['gold', 'platinum'], true) || !empty($it['is_member_benefit'])) {
                            $allowedQuota = ($mTier === 'gold' && $sId == 1) ? 1 : (($mTier === 'platinum' && in_array($sId, [1, 2])) ? 2 : 1);
                            $uStmt = $pdo->prepare("
                                SELECT COUNT(oi.id) FROM order_items oi
                                JOIN orders o ON oi.order_id = o.id
                                WHERE (o.customer_id = ? OR o.plate_number = ?)
                                  AND o.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')
                                  AND o.status != 'cancelled'
                                  AND oi.service_id = ?
                                  AND (oi.price_usd = 0 OR oi.service_name LIKE '%Included%')
                            ");
                            $uStmt->execute([$customerId, $plateNumber, $sId]);
                            $usedCount = (int)$uStmt->fetchColumn();
                            if ($usedCount < $allowedQuota) {
                                $isFreeBenefit = true;
                            }
                        }
                    }
                }

                if ($isFreeBenefit) {
                    $unitPriceUsd = 0.00;
                    $itemName .= ' (Included Member Benefit)';
                }

                // Server-side upsell verification: never trust client offer_price_usd
                if (!empty($it['is_upsell'])) {
                    $matchedUpsellPrice = null;
                    foreach ($matchingUpsellOffers as $uOffer) {
                        if (!empty($uOffer['offer_service_id']) && (int)$uOffer['offer_service_id'] === (int)$sId && $uOffer['offer_price_usd'] !== null) {
                            $matchedUpsellPrice = round((float)$uOffer['offer_price_usd'], 2);
                            break;
                        }
                    }
                    if ($matchedUpsellPrice !== null) {
                        $unitPriceUsd = $matchedUpsellPrice;
                    }
                    // If no active rule matches, normal catalog price ($unitPriceUsd) is retained
                }
            } elseif (in_array($type, ['product', 'spare_part'], true) && $pId) {
                $prodInfoStmt->execute([$pId]);
                $pRow = $prodInfoStmt->fetch();
                if ($pRow) {
                    $unitPriceUsd = (float)$pRow['price_usd'];
                    $itemName = $pRow['name_en'];
                    if (stripos($pRow['name_en'], 'oil') !== false || $pRow['category'] === 'engine_oil') {
                        $oilProductName = $pRow['name_en'];
                    }
                }

                // Server-side upsell verification: never trust client offer_price_usd
                if (!empty($it['is_upsell'])) {
                    $matchedUpsellPrice = null;
                    foreach ($matchingUpsellOffers as $uOffer) {
                        if (!empty($uOffer['offer_product_id']) && (int)$uOffer['offer_product_id'] === (int)$pId && $uOffer['offer_price_usd'] !== null) {
                            $matchedUpsellPrice = round((float)$uOffer['offer_price_usd'], 2);
                            break;
                        }
                    }
                    if ($matchedUpsellPrice !== null) {
                        $unitPriceUsd = $matchedUpsellPrice;
                    }
                    // If no active rule matches, normal catalog price ($unitPriceUsd) is retained
                }
            } else {
                $unitPriceUsd = (float)($it['price_usd'] ?? 0);
            }

            // Reject negative or zero prices unless the item is an included member benefit
            $isBenefitCovered = !empty($isFreeBenefit);
            if ($unitPriceUsd <= 0.0 && !$isBenefitCovered) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_ITEM_PRICE',
                    'message' => "Item '{$itemName}' has an invalid or zero price and is not an included member benefit."
                ]);
                exit;
            }

            $lineTotalUsd = round($unitPriceUsd * $qty, 2);
            $subtotalUsd += $lineTotalUsd;

            if ($type === 'repair') {
                $repairLaborTotal += $lineTotalUsd;
            } elseif ($type === 'spare_part') {
                $sparePartsTotal += $lineTotalUsd;
            }

            $preparedItems[] = [
                'service_id' => $sId,
                'product_id' => $pId,
                'type' => $type,
                'name' => $itemName,
                'unit_price_usd' => $unitPriceUsd,
                'price_usd' => $unitPriceUsd,
                'line_total_usd' => $lineTotalUsd,
                'price_khr' => (int)round($unitPriceUsd * get_exchange_rate()),
                'qty' => $qty
            ];
        }

        // Validate manual discount: cannot be negative and cannot exceed subtotal
        if ($manualDiscountUsd < 0 || $manualDiscountUsd > $subtotalUsd) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'DISCOUNT_EXCEEDS_TOTAL',
                'message' => "Discount ($" . number_format($manualDiscountUsd, 2) . ") cannot exceed order total ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero."
            ]);
            exit;
        }

        // Calculate points redemption credit and enforce real-time balance check
        $actualPointsRedeemed = 0;
        $pointsDiscountUsd = 0.00;
        if ($pointsToRedeem > 0) {
            if (!$customerId) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_CUSTOMER',
                    'message' => 'Walk-in customers cannot redeem loyalty points.'
                ]);
                exit;
            }

            // Lock customer row and check available points
            $cStmt = $pdo->prepare("SELECT loyalty_points FROM customers WHERE id = ? FOR UPDATE");
            $cStmt->execute([$customerId]);
            $availablePts = (int)$cStmt->fetchColumn();

            if ($pointsToRedeem > $availablePts) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'INSUFFICIENT_POINTS',
                    'message' => "Cannot redeem {$pointsToRedeem} points. Customer only has {$availablePts} points available."
                ]);
                exit;
            }

            $maxUsdAllowed = max(0.00, $subtotalUsd - $manualDiscountUsd);
            $redeemRate = Loyalty::getRedeemRate();
            $maxPossibleCredit = round($pointsToRedeem / $redeemRate, 2);
            $pointsDiscountUsd = min($maxUsdAllowed, $maxPossibleCredit);
            $actualPointsRedeemed = (int)ceil($pointsDiscountUsd * $redeemRate);
            if ($actualPointsRedeemed > $availablePts) {
                $actualPointsRedeemed = $availablePts;
                $pointsDiscountUsd = round($actualPointsRedeemed / $redeemRate, 2);
            }
        }

        $totalDiscountUsd = round($manualDiscountUsd + $pointsDiscountUsd, 2);
        if ($totalDiscountUsd > $subtotalUsd) {
            $totalDiscountUsd = $subtotalUsd;
        }
        $totalUsd = max(0.00, round($subtotalUsd - $totalDiscountUsd, 2));
        $totalKhr = (int)round($totalUsd * get_exchange_rate());

        if ($isPaid) {
            if ($paymentMethod === 'cash') {
                if ($tenderedUsd <= 0 && $totalUsd > 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Cash amount cannot be empty or zero for cash payment']);
                    exit;
                }
                if ($tenderedUsd < $totalUsd) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Cash tendered ($' . number_format($tenderedUsd, 2) . ') is less than total amount ($' . number_format($totalUsd, 2) . ')']);
                    exit;
                }
                $changeUsd = round(max(0, $tenderedUsd - $totalUsd), 2);
            } else {
                $tenderedUsd = $totalUsd;
                $changeUsd = 0.00;
                if (in_array($paymentMethod, ['aba', 'khqr'], true) && empty($paymentRef)) {
                    $paymentRef = 'TRX-' . strtoupper(substr(md5(uniqid()), 0, 8));
                }
            }
        } else {
            $changeUsd = 0.00;
        }

        // Generate Order Number
        $todayPrefix = 'ORD-' . date('ymd') . '-';
        $seqStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE order_number LIKE ?");
        $seqStmt->execute([$todayPrefix . '%']);
        $seqCount = (int)$seqStmt->fetchColumn() + 1;
        $orderNumber = $todayPrefix . str_pad((string)$seqCount, 3, '0', STR_PAD_LEFT);

        $startedAt = ($status === 'washing') ? date('Y-m-d H:i:s') : null;
        $finishedAt = ($status === 'finished') ? date('Y-m-d H:i:s') : null;
        $paidAt = $isPaid ? date('Y-m-d H:i:s') : null;
        $paymentStatus = $isPaid ? 'paid' : 'unpaid';

        $bankSlipImg = null;
        if ($isPaid && in_array($paymentMethod, ['aba', 'khqr'], true)) {
            $slipInput = getSlipFileFromInput($inputData['slip_base64'] ?? $_POST['slip_base64'] ?? null, $_FILES['slip_image'] ?? $_FILES['slip_file'] ?? null);
            if (!$slipInput) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'SLIP_REQUIRED',
                    'message' => 'A photo of the bank transfer slip is required for ABA and KHQR payments.'
                ]);
                exit;
            }
            $uploadRes = BankTransferControl::processSlipUpload($slipInput);
            if (!$uploadRes['success']) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'SLIP_UPLOAD_FAILED',
                    'message' => $uploadRes['error']
                ]);
                exit;
            }
            $bankSlipImg = $uploadRes['file_path'];
            $paymentStatus = 'pending_verification';
            $status = ($status === 'paid') ? 'waiting' : $status;
            $paidAt = null;
        }

        // Insert Order
        $insOrder = $pdo->prepare("
            INSERT INTO orders (
                order_number, idempotency_key, source, pos_device_id, pos_device_name,
                customer_id, vehicle_id, customer_name, customer_phone, plate_number, vehicle_type,
                subtotal_usd, discount_usd, points_redeemed, points_discount_usd, total_usd, total_khr,
                status, repair_status, repair_notes,
                payment_method, payment_status, amount_tendered_usd, change_usd, payment_ref,
                cashier_id, created_by, synced_by, is_synced, synced_at,
                notes, started_at, finished_at, paid_at, created_at,
                current_mileage, next_oil_mileage, next_oil_date,
                receipt_issued_at
            ) VALUES (
                ?, ?, 'pos_app', ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?
            )
        ");

        $receiptIssuedVal = ($paymentStatus === 'paid') ? ($paidAt ?: date('Y-m-d H:i:s')) : null;

        $insOrder->execute([
            $orderNumber, $idempotencyKey, $device['id'], $device['device_name'],
            $customerId, $vehicleId, $customerName, ($customerPhone !== '' ? $customerPhone : null), $plateNumber, $vehicleType,
            $subtotalUsd, $totalDiscountUsd, $actualPointsRedeemed, $pointsDiscountUsd, $totalUsd, $totalKhr,
            $status, $repairStatus, $repairNotes,
            $paymentMethod, $paymentStatus, $tenderedUsd, $changeUsd, $paymentRef,
            ($orderCreatedBy ?: $verifiedUserId), $orderCreatedBy, $orderSyncedBy, ($isOfflineSync ? 1 : 0), ($isOfflineSync ? date('Y-m-d H:i:s') : null),
            $notes, $startedAt, $finishedAt, $paidAt, $orderCreatedAt,
            $currentMileage, $nextOilMileage, $nextOilDate,
            $receiptIssuedVal
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $serviceSequence = in_array(($inputData['service_sequence'] ?? $_POST['service_sequence'] ?? 'lift_first'), ['lift_first', 'wash_first'], true)
            ? ($inputData['service_sequence'] ?? $_POST['service_sequence'] ?? 'lift_first')
            : 'lift_first';
        if ($serviceSequence === 'wash_first') {
            $pdo->prepare("UPDATE orders SET service_sequence = 'wash_first', repair_status = 'waiting_wash_first' WHERE id = ?")->execute([$orderId]);
        }
        BayManager::ensureOrderTasks($pdo, $orderId);

        // Audit-log order creation with created_by and synced_by
        if (class_exists('AuditLogger')) {
            AuditLogger::log(
                $isOfflineSync ? 'OFFLINE_ORDER_SYNCED' : 'ORDER_CREATED',
                "Order {$orderNumber} (" . ($isOfflineSync ? "offline synced" : "direct") . ") created_by: " . ($orderCreatedBy ? "user #{$orderCreatedBy} ({$creatorName})" : "none/unverified") . ", synced_by: user #{$orderSyncedBy} ({$sessionUser['username']}) on device #{$device['id']} ({$device['device_name']})",
                'order',
                (string)$orderId,
                $orderSyncedBy,
                $sessionUser['username'] ?? null,
                $sessionUser['role'] ?? null
            );
        }

        // Record Loyalty Reward prompt action (Requirement 3: record whether the cashier gave it)
        $loyaltyRewardGiven = isset($inputData['loyalty_reward_given']) ? ($inputData['loyalty_reward_given'] ? 1 : 0) : (isset($_POST['loyalty_reward_given']) ? ($_POST['loyalty_reward_given'] ? 1 : 0) : null);
        $loyaltyRewardName = !empty($inputData['loyalty_reward_name']) ? trim($inputData['loyalty_reward_name']) : (!empty($_POST['loyalty_reward_name']) ? trim($_POST['loyalty_reward_name']) : null);

        if ($loyaltyRewardGiven !== null) {
            $pdo->prepare("UPDATE orders SET loyalty_reward_given = ?, loyalty_reward_name = ? WHERE id = ?")
                ->execute([$loyaltyRewardGiven, $loyaltyRewardName, $orderId]);

            if ($loyaltyRewardGiven === 1 && $customerId > 0) {
                $insTx = $pdo->prepare("INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at) VALUES (?, ?, 'reward', 0, COALESCE((SELECT loyalty_points FROM customers WHERE id = ?), 0), ?, ?, CURRENT_TIMESTAMP)");
                $insTx->execute([$customerId, $orderId, $customerId, "Loyalty reward given: " . ($loyaltyRewardName ?: 'Free air freshener'), $staffUserId]);
            }
        }

        if (!empty($bankSlipImg)) {
            $pdo->prepare("UPDATE orders SET bank_slip_image = ?, bank_slip_uploaded_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$bankSlipImg, $orderId]);
        }

        // Insert Order Items and Deduct Stock
        $insItem = $pdo->prepare("
            INSERT INTO order_items (order_id, service_id, product_id, item_type, service_name, price_usd, price_khr, qty)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stockUpdate = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");

        if ($loyaltyRewardGiven === 1) {
            $insItem->execute([
                $orderId, null, null, 'reward',
                $loyaltyRewardName ?: 'Free air freshener (loyalty reward)',
                0.00, 0, 1
            ]);
        }

        foreach ($preparedItems as $pit) {
            $insItem->execute([
                $orderId, $pit['service_id'], $pit['product_id'], $pit['type'],
                $pit['name'], $pit['price_usd'], $pit['price_khr'], $pit['qty']
            ]);

            if (!empty($pit['product_id']) && in_array($pit['type'], ['product', 'spare_part'], true)) {
                $stockUpdate->execute([$pit['qty'], $pit['product_id']]);
            }
        }

        // Record Membership Service Usage
        if ($customerId && !empty($coveredMembershipUsages)) {
            foreach ($coveredMembershipUsages as $cmu) {
                MembershipManager::recordServiceUsage(
                    $pdo,
                    (int)$customerId,
                    $vehicleId,
                    $plateNumber,
                    $cmu['tier_code'],
                    (int)$cmu['service_id'],
                    $orderId,
                    true
                );
            }
        }

        // Oil change log
        if ($currentMileage && $currentMileage > 0) {
            $pdo->prepare("
                INSERT INTO oil_change_logs (
                    order_id, vehicle_id, customer_id, plate_number, customer_phone,
                    customer_name, oil_product_name, current_mileage, next_due_mileage,
                    oil_change_date, next_due_date, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)
            ")->execute([
                $orderId, $vehicleId, $customerId, $plateNumber, ($customerPhone !== '' ? $customerPhone : null),
                $customerName, ($oilProductName ?: 'Engine Oil & Filter Service'),
                $currentMileage, $nextOilMileage, $nextOilDate, $notes
            ]);

            if ($vehicleId) {
                $pdo->prepare("UPDATE vehicles SET last_mileage = ?, next_oil_mileage = ?, next_oil_date = ? WHERE id = ?")
                    ->execute([$currentMileage, $nextOilMileage, $nextOilDate, $vehicleId]);
            }
        }

        // Assign Washers & Commission Calculation
        if (!empty($assignedWashers) && is_array($assignedWashers)) {
            $wIns = $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, ?, ?)");
            $uStmt = $pdo->prepare("SELECT commission_type, commission_rate FROM users WHERE id = ?");
            $numW = count($assignedWashers);

            foreach ($assignedWashers as $wId) {
                $wId = (int)$wId;
                if ($wId <= 0) continue;
                $uStmt->execute([$wId]);
                $wRow = $uStmt->fetch();
                $wComm = 0.00;
                if ($wRow) {
                    if ($wRow['commission_type'] === 'percentage') {
                        $wComm = round(($totalUsd * ((float)$wRow['commission_rate'] / 100)) / $numW, 2);
                    } else {
                        $wComm = round((float)$wRow['commission_rate'] / $numW, 2);
                    }
                }
                $wIns->execute([$orderId, $wId, $wComm]);
            }
        }

        // Assign Mechanics & Commission Calculation
        if (!empty($assignedMechanics) && is_array($assignedMechanics)) {
            $mIns = $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, ?)");
            $uStmt = $pdo->prepare("SELECT commission_type, commission_rate FROM users WHERE id = ?");
            $numM = count($assignedMechanics);
            $mechBasis = ($repairLaborTotal > 0) ? $repairLaborTotal : $totalUsd;

            foreach ($assignedMechanics as $mId) {
                $mId = (int)$mId;
                if ($mId <= 0) continue;
                $uStmt->execute([$mId]);
                $mRow = $uStmt->fetch();
                $mComm = 0.00;
                if ($mRow) {
                    if ($mRow['commission_type'] === 'percentage') {
                        $mComm = round(($mechBasis * ((float)$mRow['commission_rate'] / 100)) / $numM, 2);
                    } else {
                        $mComm = round((float)$mRow['commission_rate'] / $numM, 2);
                    }
                }
                $mIns->execute([$orderId, $mId, $mComm]);
            }
        }

        // Repair history log
        if ($repairLaborTotal > 0 || $sparePartsTotal > 0 || !empty($repairNotes)) {
            $repServicesNames = [];
            $repPartsNames = [];
            foreach ($preparedItems as $pit) {
                if ($pit['type'] === 'repair') $repServicesNames[] = $pit['name'];
                if ($pit['type'] === 'spare_part') $repPartsNames[] = $pit['name'];
            }

            $mechNames = [];
            if (!empty($assignedMechanics)) {
                $mIds = array_map('intval', array_filter($assignedMechanics));
                if (!empty($mIds)) {
                    $mNamesStmt = $pdo->query("SELECT full_name FROM users WHERE id IN (" . implode(',', $mIds) . ")");
                    $mechNames = $mNamesStmt->fetchAll(PDO::FETCH_COLUMN);
                }
            }

            $pdo->prepare("
                INSERT INTO vehicle_repair_history (
                    order_id, vehicle_id, plate_number, customer_name,
                    mechanic_name, services_performed, parts_replaced,
                    labor_cost_usd, parts_cost_usd, total_cost_usd, mileage, repair_notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $orderId, $vehicleId, $plateNumber, $customerName,
                implode(', ', $mechNames) ?: 'Duty Mechanic',
                implode(', ', $repServicesNames) ?: 'General Inspection & Maintenance',
                implode(', ', $repPartsNames) ?: 'N/A',
                $repairLaborTotal, $sparePartsTotal, $totalUsd,
                $currentMileage, $repairNotes
            ]);
        }

        // Deduct points immediately if redeemed (inside atomic transaction)
        if ($customerId && $actualPointsRedeemed > 0) {
            $redRes = Loyalty::redeemPoints($pdo, $customerId, $orderId, $actualPointsRedeemed, $pointsDiscountUsd, $staffUserId);
            if (!$redRes['success']) {
                throw new Exception($redRes['error'] ?? "Failed to redeem loyalty points.");
            }
        }

        // Create initial tasks at order creation lifecycle event (prevents queue read DB writes)
        BayManager::ensureOrderTasks($pdo, $orderId);

        // ALL operations successful -> Commit
        $pdo->commit();

        // Process Loyalty Earning outside transaction (idempotent & safe)
        $loyaltyEarned = 0;
        $newBalance = 0;
        if ($customerId) {
            try {
                if ($isPaid) {
                    $loyaltyEarned = Loyalty::awardEarnedPoints($orderId, $staffUserId);
                }
                $newBalance = (int)$pdo->query("SELECT loyalty_points FROM customers WHERE id = " . (int)$customerId)->fetchColumn();
            } catch (Exception $e) {
                error_log("Loyalty error in POS create_order: " . $e->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Order created and synced successfully',
            'order' => [
                'id' => $orderId,
                'order_number' => $orderNumber,
                'plate_number' => $plateNumber,
                'customer_name' => $customerName,
                'subtotal_usd' => $subtotalUsd,
                'discount_usd' => $totalDiscountUsd,
                'points_redeemed' => $actualPointsRedeemed,
                'points_discount_usd' => $pointsDiscountUsd,
                'points_earned' => $loyaltyEarned,
                'new_loyalty_balance' => $newBalance,
                'total_usd' => $totalUsd,
                'total_khr' => $totalKhr,
                'status' => $status,
                'payment_status' => $paymentStatus,
                'payment_method' => $paymentMethod,
                'source' => 'pos_app',
                'pos_device_name' => $device['device_name'],
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => (strpos($e->getMessage(), 'ITEM_OUT_OF_STOCK') !== false) ? 'ITEM_OUT_OF_STOCK' : 'ORDER_SAVE_FAILED',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// ========================================================
// 5b. ACTION: mark_receipt_printed
// ========================================================
if ($action === 'mark_receipt_printed') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');

    if ($orderId <= 0 && $orderNumber === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'order_id or order_number is required']);
        exit;
    }

    if ($orderId <= 0 && $orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$orderNumber]);
        $orderId = (int)$stmt->fetchColumn();
    }

    if ($orderId <= 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found']);
        exit;
    }

    $actorId = $sessionUser['id'] ?? null;
    $actorName = $sessionUser['full_name'] ?? ($sessionUser['username'] ?? 'Staff');
    $actorRole = $sessionUser['role'] ?? 'cashier';

    $ok = OrderGate::markReceiptPrinted($pdo, $orderId, $actorId, $actorName, $actorRole);
    if ($ok) {
        echo json_encode([
            'success' => true,
            'message' => 'Receipt marked as printed successfully',
            'order_id' => $orderId,
            'receipt_printed_at' => date('Y-m-d H:i:s')
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Failed to mark receipt printed']);
    }
    exit;
}

// ========================================================
// 5c. ACTION: ack_receipt_issued
// Client acknowledgement that electronic receipt was received
// ========================================================
if ($action === 'ack_receipt_issued') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');

    if ($orderId <= 0 && $orderNumber === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'order_id or order_number is required']);
        exit;
    }

    if ($orderId <= 0 && $orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE order_number = ? LIMIT 1");
        $stmt->execute([$orderNumber]);
        $orderId = (int)$stmt->fetchColumn();
    }

    if ($orderId <= 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'ORDER_NOT_FOUND', 'message' => 'Order not found']);
        exit;
    }

    $actorId = $sessionUser['id'] ?? null;
    $actorName = $sessionUser['full_name'] ?? ($sessionUser['username'] ?? 'Staff');
    $actorRole = $sessionUser['role'] ?? 'cashier';
    $authDeviceId = $device['id'] ?? null;

    try {
        $res = OrderGate::ackReceiptIssued($pdo, $orderId, ($orderNumber !== '' ? $orderNumber : null), $actorId, $actorName, $actorRole, $authDeviceId);
        echo json_encode([
            'success' => true,
            'message' => 'Electronic receipt acknowledged successfully',
            'order_id' => $res['order_id'],
            'order_number' => $res['order_number'],
            'receipt_issued_at' => $res['receipt_issued_at'],
            'already_acknowledged' => $res['already_acknowledged']
        ]);
    } catch (PaymentRequiredException $pe) {
        http_response_code(402);
        echo json_encode(['success' => false, 'error' => 'PAYMENT_REQUIRED', 'message' => $pe->getMessage()]);
    } catch (Exception $e) {
        $code = ($e->getCode() >= 400 && $e->getCode() <= 499) ? $e->getCode() : 400;
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => 'ACK_FAILED', 'message' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 6. ACTION: sync_order_status & pay_order
// Handles later payment with discount, cash received, change,
// payment ref, and recalculated commissions (identical to web)
// ========================================================
if ($action === 'sync_order_status' || $action === 'pay_order') {
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $newStatus = trim($inputData['status'] ?? $_POST['status'] ?? ($action === 'pay_order' ? 'paid' : ''));
    $paymentMethod = trim($inputData['payment_method'] ?? $_POST['payment_method'] ?? 'cash');
    $tenderedUsd = isset($inputData['amount_tendered_usd']) ? (float)$inputData['amount_tendered_usd'] : (isset($_POST['amount_tendered_usd']) ? (float)$_POST['amount_tendered_usd'] : null);
    $changeUsd = isset($inputData['change_usd']) ? (float)$inputData['change_usd'] : (isset($_POST['change_usd']) ? (float)$_POST['change_usd'] : null);
    $paymentRef = trim($inputData['payment_ref'] ?? $_POST['payment_ref'] ?? '');

    // Validate status
    $allowedStatuses = ['waiting', 'washing', 'finished', 'paid', 'cancelled'];
    if (!in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_STATUS',
            'message' => "Invalid order status '{$newStatus}'. Allowed: " . implode(', ', $allowedStatuses)
        ]);
        exit;
    }

    // Check if sales are locked (11:59 PM cutoff, unclosed previous day, or day closed)
    if ($newStatus === 'paid') {
        $dayLock = CashierControl::isDayLocked();
        if (!empty($dayLock['locked'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'SALES_LOCKED',
                'message' => $dayLock['message']
            ]);
            exit;
        }
        $todayDate = date('Y-m-d');
        $chkClosedStmt = $pdo->prepare("
            SELECT id, close_date, closed_at, cashier_name 
            FROM pos_closings 
            WHERE close_date = ? AND pos_device_id = ? AND status = 'closed'
            ORDER BY id DESC LIMIT 1
        ");
        $chkClosedStmt->execute([$todayDate, $authDeviceId]);
        $closedDayRec = $chkClosedStmt->fetch(PDO::FETCH_ASSOC);
        if (!$closedDayRec) {
            $chkOldStmt = $pdo->prepare("
                SELECT id, close_date, closed_at, closed_by_name as cashier_name 
                FROM pos_daily_closes 
                WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL) AND status = 'closed'
                ORDER BY id DESC LIMIT 1
            ");
            $chkOldStmt->execute([$todayDate, $authDeviceId]);
            $closedDayRec = $chkOldStmt->fetch(PDO::FETCH_ASSOC);
        }
        if ($closedDayRec) {
            http_response_code(403);
            $closedByName = $closedDayRec['cashier_name'] ?? 'Manager';
            echo json_encode([
                'success' => false,
                'error' => 'DAY_CLOSED',
                'message' => "Processing payments is locked. The day has been closed on this terminal by {$closedByName}. Reopen the day to resume."
            ]);
            exit;
        }
    }

    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_REQUIRED',
            'message' => 'Staff ID is required to update order status.'
        ]);
        exit;
    }

    $stfStmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ?");
    $stfStmt->execute([$staffId]);
    $stf = $stfStmt->fetch();
    if (!$stf || $stf['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_INACTIVE',
            'message' => "Staff ID {$staffId} is inactive or not found."
        ]);
        exit;
    }
    if ($stf['role'] !== 'owner') {
            if ($newStatus === 'paid' && !Auth::userHasPermission($staffId, 'pos.pay_order') && !Auth::userHasPermission($staffId, 'orders.change_status')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Staff ID {$staffId} does not have permission to process payments."
                ]);
                exit;
            }
            if ($newStatus === 'cancelled' && !Auth::userHasPermission($staffId, 'pos.cancel_order') && !Auth::userHasPermission($staffId, 'orders.cancel')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Staff ID {$staffId} does not have permission to cancel orders."
                ]);
                exit;
            }
            if (in_array($newStatus, ['waiting', 'washing', 'finished'], true) && !Auth::userHasPermission($staffId, 'orders.change_status') && !Auth::userHasPermission($staffId, 'pos.access')) {
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'PERMISSION_DENIED',
                    'message' => "Staff ID {$staffId} does not have permission to change order status."
                ]);
                exit;
            }
        }

    $ord = null;
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch();
    } elseif ($orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $ord = $stmt->fetch();
    }

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $id = (int)$ord['id'];

    if ($newStatus === 'paid') {
        // Complete payment matching web screen logic
        $subtotalUsd = (float)$ord['subtotal_usd'];
        $actualPointsRedeemed = (int)($ord['points_redeemed'] ?? 0);
        $pointsDiscountUsd = (float)($ord['points_discount_usd'] ?? 0.00);
        $prevManualDiscount = max(0.00, round((float)$ord['discount_usd'] - $pointsDiscountUsd, 2));
        $manualDiscountUsd = isset($inputData['discount_usd']) ? (float)$inputData['discount_usd'] : $prevManualDiscount;

        if ($manualDiscountUsd < 0 || $manualDiscountUsd > $subtotalUsd) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'DISCOUNT_EXCEEDS_TOTAL',
                'message' => "Discount ($" . number_format($manualDiscountUsd, 2) . ") cannot exceed order total ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero."
            ]);
            exit;
        }

        $pointsToRedeem = max(0, (int)($inputData['points_redeemed'] ?? $_POST['points_redeemed'] ?? 0));
        $customerId = !empty($ord['customer_id']) ? (int)$ord['customer_id'] : null;

        // Only redeem if not already redeemed when order was created
        if ($pointsToRedeem > 0 && $customerId && $actualPointsRedeemed == 0) {
            $cCheck = $pdo->prepare("SELECT loyalty_points FROM customers WHERE id = ? FOR UPDATE");
            $cCheck->execute([$customerId]);
            $availPts = (int)$cCheck->fetchColumn();
            if ($pointsToRedeem > $availPts) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'INSUFFICIENT_POINTS',
                    'message' => "Cannot redeem {$pointsToRedeem} points. Customer only has {$availPts} points available."
                ]);
                exit;
            }

            try {
                $maxUsdAllowed = max(0.00, $subtotalUsd - $manualDiscountUsd);
                $redResult = Loyalty::redeemPoints($pdo, $customerId, $id, $pointsToRedeem, $maxUsdAllowed, $staffId ?: null);
                if (!empty($redResult['success'])) {
                    $actualPointsRedeemed = (int)$redResult['points_redeemed'];
                    $pointsDiscountUsd = (float)$redResult['discount_usd'];
                } else {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'LOYALTY_REDEEM_FAILED',
                        'message' => $redResult['error'] ?? 'Failed to redeem loyalty points'
                    ]);
                    exit;
                }
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'LOYALTY_REDEEM_FAILED',
                    'message' => $e->getMessage()
                ]);
                exit;
            }
        }

        $totalDiscountUsd = round($manualDiscountUsd + $pointsDiscountUsd, 2);
        if ($totalDiscountUsd > $subtotalUsd) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'DISCOUNT_EXCEEDS_TOTAL',
                'message' => "Discount ($" . number_format($totalDiscountUsd, 2) . ") cannot exceed order total ($" . number_format($subtotalUsd, 2) . "). Total cannot be below zero."
            ]);
            exit;
        }
        $totalUsd = max(0.00, round($subtotalUsd - $totalDiscountUsd, 2));
        $totalKhr = (int)round($totalUsd * get_exchange_rate());

        if ($paymentMethod === 'cash') {
            if ($tenderedUsd === null || $tenderedUsd <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cash tendered cannot be empty or zero.']);
                exit;
            }
            if ($tenderedUsd < $totalUsd) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cash tendered ($' . number_format($tenderedUsd, 2) . ') is less than total amount ($' . number_format($totalUsd, 2) . ')']);
                exit;
            }
            $changeUsd = round(max(0.00, $tenderedUsd - $totalUsd), 2);
        } else {
            $changeUsd = 0.00;
            if (in_array($paymentMethod, ['aba', 'khqr'], true) && empty($paymentRef)) {
                $paymentRef = 'TRX-' . strtoupper(substr(md5(uniqid()), 0, 8));
            }
        }

        $bankSlipImg = null;
        if (in_array($paymentMethod, ['aba', 'khqr'], true)) {
            $slipInput = getSlipFileFromInput($inputData['slip_base64'] ?? $_POST['slip_base64'] ?? null, $_FILES['slip_image'] ?? $_FILES['slip_file'] ?? null);
            if (!$slipInput && empty($ord['bank_slip_image'])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'SLIP_REQUIRED',
                    'message' => 'A photo of the bank transfer slip is required for ABA and KHQR payments.'
                ]);
                exit;
            }
            if ($slipInput) {
                $uploadRes = BankTransferControl::processSlipUpload($slipInput);
                if (!$uploadRes['success']) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'error' => 'SLIP_UPLOAD_FAILED',
                        'message' => $uploadRes['error']
                    ]);
                    exit;
                }
                $bankSlipImg = $uploadRes['file_path'];
            } else {
                $bankSlipImg = $ord['bank_slip_image'];
            }
            $targetPaymentStatus = 'pending_verification';
            $targetOrderStatus = ($ord['status'] === 'unpaid') ? 'waiting' : $ord['status'];
            $paidAtVal = null;
        } else {
            $targetPaymentStatus = 'paid';
            $targetOrderStatus = 'paid';
            $paidAtVal = date('Y-m-d H:i:s');
        }

        $uStmt = $pdo->prepare("
            UPDATE orders SET
                status = ?,
                payment_status = ?,
                payment_method = ?,
                discount_usd = ?,
                points_redeemed = ?,
                points_discount_usd = ?,
                total_usd = ?,
                total_khr = ?,
                amount_tendered_usd = ?,
                change_usd = ?,
                payment_ref = ?,
                cashier_id = COALESCE(?, cashier_id),
                pos_device_id = COALESCE(?, pos_device_id),
                pos_device_name = COALESCE(?, pos_device_name),
                paid_at = ?,
                receipt_issued_at = CASE WHEN ? = 'paid' THEN COALESCE(receipt_issued_at, CURRENT_TIMESTAMP) ELSE receipt_issued_at END,
                bank_slip_image = COALESCE(?, bank_slip_image),
                bank_slip_uploaded_at = CASE WHEN ? IS NOT NULL THEN CURRENT_TIMESTAMP ELSE bank_slip_uploaded_at END
            WHERE id = ?
        ");
        $uStmt->execute([
            $targetOrderStatus,
            $targetPaymentStatus,
            $paymentMethod, $totalDiscountUsd, $actualPointsRedeemed, $pointsDiscountUsd, $totalUsd, $totalKhr,
            $tenderedUsd, $changeUsd, $paymentRef,
            ($staffId > 0 ? $staffId : null),
            ($device['id'] ?? null),
            ($device['device_name'] ?? null),
            $paidAtVal,
            $targetPaymentStatus,
            $bankSlipImg,
            $bankSlipImg,
            $id
        ]);

        // Award loyalty points for paid order
        $ptsEarned = 0;
        if ($customerId) {
            try {
                $ptsEarned = Loyalty::awardEarnedPoints($id, null);
            } catch (Exception $e) {
                error_log("Loyalty award error in sync_order_status: " . $e->getMessage());
            }
        }

        // Recalculate washer commissions with final total
        $washers = $pdo->prepare("SELECT ow.id, ow.washer_id, u.commission_type, u.commission_rate FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = ?");
        $washers->execute([$id]);
        $wRows = $washers->fetchAll();
        $numW = count($wRows);
        if ($numW > 0) {
            $commUpdate = $pdo->prepare("UPDATE order_washers SET commission_usd = ? WHERE id = ?");
            foreach ($wRows as $wr) {
                if ($wr['commission_type'] === 'percentage') {
                    $cUsd = round(($totalUsd * ((float)$wr['commission_rate'] / 100)) / $numW, 2);
                } else {
                    $cUsd = round((float)$wr['commission_rate'] / $numW, 2);
                }
                $commUpdate->execute([$cUsd, $wr['id']]);
            }
        }

        // Recalculate mechanic commissions with final total
        $mechanics = $pdo->prepare("SELECT om.id, om.mechanic_id, u.commission_type, u.commission_rate FROM order_mechanics om JOIN users u ON om.mechanic_id = u.id WHERE om.order_id = ?");
        $mechanics->execute([$id]);
        $mRows = $mechanics->fetchAll();
        $numM = count($mRows);
        if ($numM > 0) {
            $mCommUpdate = $pdo->prepare("UPDATE order_mechanics SET commission_usd = ? WHERE id = ?");
            foreach ($mRows as $mr) {
                if ($mr['commission_type'] === 'percentage') {
                    $cUsd = round(($totalUsd * ((float)$mr['commission_rate'] / 100)) / $numM, 2);
                } else {
                    $cUsd = round((float)$mr['commission_rate'] / $numM, 2);
                }
                $mCommUpdate->execute([$cUsd, $mr['id']]);
            }
        }

        // Update vehicle_repair_history total if entry exists
        $pdo->prepare("UPDATE vehicle_repair_history SET total_cost_usd = ? WHERE order_id = ?")->execute([$totalUsd, $id]);

    } elseif ($newStatus === 'finished') {
        $pdo->prepare("UPDATE orders SET status = 'finished', finished_at = CURRENT_TIMESTAMP, repair_status = CASE WHEN repair_status IS NOT NULL THEN 'finished' ELSE repair_status END WHERE id = ?")
            ->execute([$id]);
    } elseif ($newStatus === 'washing') {
        $mgrPin = $inputData['manager_pin'] ?? $_POST['manager_pin'] ?? null;
        $overrideReason = $inputData['override_reason'] ?? $_POST['override_reason'] ?? ($inputData['reason'] ?? $_POST['reason'] ?? null);
        $mgrUser = $inputData['manager_username'] ?? $_POST['manager_username'] ?? null;
        try {
            OrderGate::assertReceiptPrinted($pdo, $id, $mgrPin, $overrideReason, $mgrUser, $sessionUser['id'] ?? null, $sessionUser['full_name'] ?? ($sessionUser['username'] ?? 'Staff'), $sessionUser['role'] ?? null);
        } catch (PaymentRequiredException $pe) {
            http_response_code(402);
            echo json_encode(['success' => false, 'error' => 'PAYMENT_REQUIRED', 'message' => $pe->getMessage()]);
            exit;
        } catch (ReceiptRequiredException $re) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'RECEIPT_REQUIRED', 'message' => $re->getMessage()]);
            exit;
        } catch (Throwable $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'RECEIPT_REQUIRED', 'message' => $e->getMessage()]);
            exit;
        }

        $pdo->prepare("UPDATE orders SET status = 'washing', started_at = COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id = ?")
            ->execute([$id]);
    } elseif ($newStatus === 'waiting') {
        $pdo->prepare("UPDATE orders SET status = 'waiting' WHERE id = ?")
            ->execute([$id]);
    } elseif ($newStatus === 'cancelled') {
        // Putting stock back on cancellation handled in cancel_order action
        $pdo->prepare("UPDATE orders SET status = 'cancelled', payment_status = 'cancelled', repair_status = 'cancelled', cancelled_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$id]);
        try {
            Loyalty::reversePointsForOrder($pdo, $id, $staffId ?: null, "Cancelled via status update");
        } catch (Exception $e) {
            error_log("Loyalty reversal error in status update: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Order #{$ord['order_number']} status updated to {$newStatus}",
        'order' => [
            'id' => $id,
            'order_number' => $ord['order_number'],
            'status' => $newStatus,
            'total_usd' => isset($totalUsd) ? $totalUsd : (float)$ord['total_usd']
        ]
    ]);
    exit;
}

// ========================================================
// 7. ACTION: get_queue
// Return live job queue for POS, including web & washer updates
// ========================================================
if ($action === 'get_queue') {
    $role = trim($inputData['role'] ?? $_POST['role'] ?? '');
    $userId = (int)($inputData['user_id'] ?? $_POST['user_id'] ?? 0);

    if ($role === 'washer' && $userId > 0) {
        $stmt = $pdo->prepare("
            SELECT o.*, ow.commission_usd,
                   (SELECT GROUP_CONCAT(CONCAT(oi.service_name, ' (x', oi.qty, ')') SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as item_names
            FROM orders o
            JOIN order_washers ow ON o.id = ow.order_id
            WHERE ow.washer_id = ? AND o.status != 'cancelled'
            ORDER BY CASE o.status
                WHEN 'washing' THEN 1
                WHEN 'waiting' THEN 2
                WHEN 'finished' THEN 3
                WHEN 'paid' THEN 4
                ELSE 5 END, o.id DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $earnStmt = $pdo->prepare("
            SELECT COALESCE(SUM(ow.commission_usd), 0) as total_earned, COUNT(DISTINCT o.id) as jobs_count
            FROM orders o
            JOIN order_washers ow ON o.id = ow.order_id
            WHERE ow.washer_id = ? AND (o.status = 'paid' OR o.payment_status = 'paid') AND DATE(o.created_at) = CURDATE()
        ");
        $earnStmt->execute([$userId]);
        $summary = $earnStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'summary' => $summary,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    if ($role === 'mechanic' && $userId > 0) {
        $stmt = $pdo->prepare("
            SELECT o.*, om.commission_usd,
                   (SELECT GROUP_CONCAT(CONCAT(oi.service_name, ' (x', oi.qty, ')') SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as item_names
            FROM orders o
            JOIN order_mechanics om ON o.id = om.order_id
            WHERE om.mechanic_id = ? AND o.status != 'cancelled'
            ORDER BY CASE o.status
                WHEN 'washing' THEN 1
                WHEN 'waiting' THEN 2
                WHEN 'finished' THEN 3
                WHEN 'paid' THEN 4
                ELSE 5 END, o.id DESC
            LIMIT 50
        ");
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $earnStmt = $pdo->prepare("
            SELECT COALESCE(SUM(om.commission_usd), 0) as total_earned, COUNT(DISTINCT o.id) as jobs_count
            FROM orders o
            JOIN order_mechanics om ON o.id = om.order_id
            WHERE om.mechanic_id = ? AND (o.status = 'paid' OR o.payment_status = 'paid') AND DATE(o.created_at) = CURDATE()
        ");
        $earnStmt->execute([$userId]);
        $summary = $earnStmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'orders' => $orders,
            'summary' => $summary,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    // Default: Entire active queue & today's orders (web + POS)
    $ordersStmt = $pdo->query("
        SELECT o.*,
               (SELECT GROUP_CONCAT(CONCAT(oi.service_name, ' (x', oi.qty, ')') SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as item_names,
               (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = o.id) as washer_names,
               (SELECT GROUP_CONCAT(ow.washer_id) FROM order_washers ow WHERE ow.order_id = o.id) as washer_ids,
               (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM order_mechanics om JOIN users u ON om.mechanic_id = u.id WHERE om.order_id = o.id) as mechanic_names,
               (SELECT GROUP_CONCAT(om.mechanic_id) FROM order_mechanics om WHERE om.order_id = o.id) as mechanic_ids,
               (SELECT ot.task_type FROM order_tasks ot WHERE ot.order_id = o.id AND ot.status != 'completed' ORDER BY ot.sequence ASC LIMIT 1) as current_task_type,
               (SELECT ot.title FROM order_tasks ot WHERE ot.order_id = o.id AND ot.status != 'completed' ORDER BY ot.sequence ASC LIMIT 1) as current_task_title
        FROM orders o
        WHERE (DATE(o.created_at) = CURDATE() OR o.status IN ('waiting', 'washing')) AND o.status != 'cancelled'
        ORDER BY CASE o.status
            WHEN 'washing' THEN 1
            WHEN 'waiting' THEN 2
            WHEN 'finished' THEN 3
            WHEN 'paid' THEN 4
            ELSE 5 END, o.id DESC
        LIMIT 100
    ");
    $orders = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// ACTION: get_bays_and_queue / get_bays
// Live bay board (Bay 1, Bay 2, Bay 3, Oil Change Bay) and FIFO queues
// ========================================================
if ($action === 'get_bays_and_queue' || $action === 'get_bays') {
    $bays = BayManager::getAllBays($pdo);
    $queues = BayManager::getQueues($pdo);
    $history = BayManager::getHistory($pdo, 30);

    echo json_encode([
        'success' => true,
        'bays' => $bays,
        'wash_queue' => $queues['wash_queue'],
        'technical_queue' => $queues['technical_queue'],
        'qc_queue' => $queues['qc_queue'],
        'finishing_pad_queue' => $queues['finishing_pad_queue'],
        'all_queue' => $queues['all_queue'],
        'total_waiting' => $queues['total_waiting'],
        'history' => $history,
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// ACTION: customer_arrived
// Mark car arrived and place in FIFO waiting queue
// ========================================================
if ($action === 'customer_arrived' || $action === 'mark_arrived') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $plateNumber = trim((string)($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));

    if ($orderId <= 0 && $plateNumber !== '') {
        $findStmt = $pdo->prepare("SELECT id FROM orders WHERE plate_number = ? AND status IN ('waiting', 'draft', 'pending') ORDER BY id DESC LIMIT 1");
        $findStmt->execute([$plateNumber]);
        $orderId = (int)$findStmt->fetchColumn();
    }

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id or plate_number required']);
        exit;
    }

    $result = BayManager::markCustomerArrived($pdo, $orderId, $stfUser['id'], $stfUser['full_name'], $stfUser['effective_role']);
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// ========================================================
// ACTION: assign_bay
// Assign car to free bay. Checked on server for concurrency (Bay already taken)
// Real staff role checked on server (never trust client role parameter)
// ========================================================
if ($action === 'assign_bay' || $action === 'assign_car_to_bay') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    // Role check: cashier, washer, mechanic, manager, owner
    $allowedAssignRoles = ['cashier', 'washer', 'mechanic', 'manager', 'owner'];
    if (!in_array($stfUser['effective_role'], $allowedAssignRoles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Staff role not permitted to assign bays']);
        exit;
    }

    $bayId = (int)($inputData['bay_id'] ?? $_POST['bay_id'] ?? 0);
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $reason = trim((string)($inputData['reason'] ?? $_POST['reason'] ?? ''));
    $managerPin = trim((string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? ''));
    $managerUsername = trim((string)($inputData['manager_username'] ?? $_POST['manager_username'] ?? ''));
    $allowWashFirst = !empty($inputData['allow_wash_first']) || !empty($_POST['allow_wash_first']) || !empty($inputData['wash_first']) || !empty($_POST['wash_first']);

    if ($bayId <= 0 || $orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'bay_id and order_id are required']);
        exit;
    }

    $res = BayManager::assignCarToBay(
        $pdo,
        $bayId,
        $orderId,
        $stfUser['id'],
        $stfUser['full_name'],
        $stfUser['effective_role'],
        $reason !== '' ? $reason : null,
        $managerPin !== '' ? $managerPin : null,
        $managerUsername !== '' ? $managerUsername : null,
        $allowWashFirst
    );

    if (!$res['success']) {
        if ($res['error'] === 'BAY_ALREADY_TAKEN') {
            http_response_code(409); // Conflict
        } elseif ($res['error'] === 'RECEIPT_REQUIRED') {
            http_response_code(422); // Unprocessable Entity
        } elseif ($res['error'] === 'PAYMENT_REQUIRED') {
            http_response_code(402); // Payment Required
        } elseif ($res['error'] === 'OVERRIDE_REASON_REQUIRED') {
            http_response_code(422); // Unprocessable Entity
        } elseif ($res['error'] === 'AUDIT_LOG_FAILED') {
            http_response_code(500); // Internal Server Error
        } else {
            http_response_code(400);
        }
    }
    echo json_encode($res);
    exit;
}

// ========================================================
// ACTION: switch_service_sequence / set_service_sequence
// Switch workflow between Lift First and Wash First
// ========================================================
if ($action === 'switch_service_sequence' || $action === 'set_service_sequence') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? $verifiedUserId);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $seq = trim((string)($inputData['service_sequence'] ?? $_POST['service_sequence'] ?? 'wash_first'));

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'order_id is required']);
        exit;
    }

    $userId = $stfUser ? (int)$stfUser['id'] : $verifiedUserId;
    $userName = $stfUser ? (string)$stfUser['full_name'] : ($sessionUser['username'] ?? 'Staff');
    $userRole = $stfUser ? (string)$stfUser['effective_role'] : ($sessionUser['role'] ?? 'cashier');

    $res = BayManager::switchServiceSequence($pdo, $orderId, $seq, $userId, $userName, $userRole);
    if (empty($res['success'])) {
        http_response_code(400);
    }
    echo json_encode($res);
    exit;
}

// ========================================================
// ACTION: finish_car / finish_job
// Frees the bay and marks order "Ready to pay"
// ========================================================
if ($action === 'finish_car' || $action === 'finish_job' || $action === 'finish_bay') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    $allowedFinishRoles = ['washer', 'mechanic', 'technical', 'cashier', 'manager', 'owner'];
    if (!in_array($stfUser['effective_role'], $allowedFinishRoles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'FORBIDDEN', 'message' => 'Staff role not permitted to finish jobs']);
        exit;
    }

    $bayId = (isset($inputData['bay_id']) || isset($_POST['bay_id'])) ? (int)($inputData['bay_id'] ?? $_POST['bay_id']) : null;
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);

    // If order_id was not explicitly provided but bay_id was, resolve order_id strictly from that bay
    if ($orderId <= 0 && $bayId !== null && $bayId > 0) {
        $bayCheckStmt = $pdo->prepare("SELECT current_order_id FROM bays WHERE id = ?");
        $bayCheckStmt->execute([$bayId]);
        $orderId = (int)$bayCheckStmt->fetchColumn();
    }

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'Valid order_id is required']);
        exit;
    }

    $tookLonger = isset($inputData['took_longer']) ? (int)$inputData['took_longer'] : (isset($_POST['took_longer']) ? (int)$_POST['took_longer'] : null);
    $reason = trim((string)($inputData['reason'] ?? $_POST['reason'] ?? ''));

    $res = BayManager::finishCar($pdo, $bayId, $orderId, $stfUser['id'], $stfUser['full_name'], $stfUser['effective_role'], $tookLonger, $reason);
    if (!$res['success']) {
        $err = $res['error'] ?? '';
        if (in_array($err, ['BAY_ORDER_MISMATCH', 'BAY_ALREADY_FREE', 'ORDER_ALREADY_COMPLETED', 'CONCURRENT_MODIFICATION'], true)) {
            http_response_code(409); // Conflict
        } elseif (in_array($err, ['ORDER_NOT_FOUND', 'BAY_NOT_FOUND'], true)) {
            http_response_code(404); // Not Found
        } elseif ($err === 'MANDATORY_INSPECTION_INCOMPLETE') {
            http_response_code(422); // Unprocessable Entity
        } else {
            http_response_code(400);
        }
    }
    echo json_encode($res);
    exit;
}

// ========================================================
// ACTION: pass_qc / qc_pass
// Finishing Pad Quality Control Pass & Handover
// ========================================================
if ($action === 'pass_qc' || $action === 'qc_pass') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $sessionUser['id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        $stfUser = $sessionUser;
    }

    $userId = (int)($stfUser['id'] ?? $sessionUser['id'] ?? 1);
    $userName = (string)($stfUser['full_name'] ?? ($sessionUser['full_name'] ?? 'Staff'));
    $userRole = (string)($stfUser['effective_role'] ?? ($sessionUser['role'] ?? 'cashier'));

    $checklist = $inputData['checklist'] ?? $_POST['checklist'] ?? [];
    $notes = trim((string)($inputData['qc_notes'] ?? $_POST['qc_notes'] ?? ''));

    $res = BayManager::passQc($pdo, $orderId, $userId, $userName, $userRole, (array)$checklist, ($notes !== '' ? $notes : null));

    if (!empty($res['success'])) {
        echo json_encode($res);
    } else {
        http_response_code(400);
        echo json_encode($res);
    }
    exit;
}

if ($action === 'qc_fail') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $reason = trim((string)($inputData['qc_reason'] ?? $_POST['qc_reason'] ?? $inputData['reason'] ?? $_POST['reason'] ?? ''));
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $sessionUser['id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        $stfUser = $sessionUser;
    }

    $userId = (int)($stfUser['id'] ?? $sessionUser['id'] ?? 1);
    $userName = (string)($stfUser['full_name'] ?? ($sessionUser['full_name'] ?? 'Staff'));
    $userRole = (string)($stfUser['effective_role'] ?? ($sessionUser['role'] ?? 'cashier'));

    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'REASON_REQUIRED', 'message' => 'A specific failure reason is required to reject QC.']);
        exit;
    }

    $res = BayManager::failQc($pdo, $orderId, $reason, $userId, $userName, $userRole);

    if (!empty($res['success'])) {
        echo json_encode($res);
    } else {
        http_response_code(400);
        echo json_encode($res);
    }
    exit;
}

// ========================================================
// ACTION: move_queue_car
// Move car up in queue. Only owner or manager with reason written to history.
// Real staff role checked on server (never trust client role).
// ========================================================
if ($action === 'move_queue_car' || $action === 'queue_move_up') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    // Role check: ONLY owner or manager
    if (!in_array($stfUser['effective_role'], ['owner', 'manager'], true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'FORBIDDEN',
            'message' => 'Only the owner or manager can move a car up in queue.'
        ]);
        exit;
    }

    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $reason = trim((string)($inputData['reason'] ?? $_POST['reason'] ?? ''));

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'order_id is required']);
        exit;
    }

    if ($reason === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'REASON_REQUIRED', 'message' => 'A reason is required to move a car up in queue.']);
        exit;
    }

    $res = BayManager::moveQueueCar($pdo, $orderId, $reason, $stfUser['id'], $stfUser['full_name'], $stfUser['effective_role']);
    if (!$res['success']) {
        http_response_code(400);
    }
    echo json_encode($res);
    exit;
}

// ========================================================
// 8. ACTION: get_today_orders
// Fetch detailed list of today's orders for reprint & search
// ========================================================
if ($action === 'get_today_orders') {
    $search = trim($inputData['search'] ?? $_POST['search'] ?? '');
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    $isToday = ($targetDate === date('Y-m-d'));

    $sql = "
        SELECT o.*,
               (SELECT GROUP_CONCAT(CONCAT(oi.service_name, ' (x', oi.qty, ')') SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as item_names,
               (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = o.id) as washer_names,
               (SELECT GROUP_CONCAT(u.full_name SEPARATOR ', ') FROM order_mechanics om JOIN users u ON om.mechanic_id = u.id WHERE om.order_id = o.id) as mechanic_names
        FROM orders o
        WHERE (" . ($isToday ? "(DATE(o.created_at) = ? OR DATE(o.paid_at) = ? OR ((o.payment_status = 'unpaid' OR o.status IN ('waiting', 'washing')) AND o.status != 'cancelled'))" : "(DATE(o.created_at) = ? OR DATE(o.paid_at) = ?)") . ")
    ";
    $params = [$targetDate, $targetDate];

    if ($search !== '') {
        $sql .= " AND (o.order_number LIKE ? OR o.plate_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)";
        $params = ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"];
    }

    $sql .= " ORDER BY o.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 9. ACTION: cancel_order
// Cancels order AND restores product stock atomically
// ========================================================
if ($action === 'cancel_order') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderNumber = trim((string)($inputData['order_number'] ?? $_POST['order_number'] ?? ''));
    $reason = trim((string)($inputData['reason'] ?? $_POST['reason'] ?? ''));
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_REQUIRED',
            'message' => 'Staff ID is required to cancel an order.'
        ]);
        exit;
    }

    // Look up real staff member and their real role from the database (never trust client-supplied role)
    $stfStmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM users WHERE id = ?");
    $stfStmt->execute([$staffId]);
    $stf = $stfStmt->fetch(PDO::FETCH_ASSOC);

    if (!$stf || $stf['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_INACTIVE',
            'message' => "Staff ID {$staffId} is inactive or not found."
        ]);
        exit;
    }

    // Cancellation reason must be at least 5 characters
    if (mb_strlen($reason) < 5) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'REASON_TOO_SHORT',
            'message' => 'Cancellation reason is required and must be at least 5 characters.'
        ]);
        exit;
    }

    // Manager / Owner physical authorization check:
    // If requester role is NOT manager or owner, manager_username + manager_pin are mandatory.
    // If manager credentials are provided (by any role), they are verified and self-approval is rejected.
    $mgrUser = trim((string)($inputData['manager_username'] ?? $_POST['manager_username'] ?? ''));
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $isManagerOrOwner = in_array($stf['role'], ['manager', 'owner'], true);
    $hasManagerCredentials = ($mgrUser !== '' || $mgrPin !== '');

    if (!$isManagerOrOwner || $hasManagerCredentials) {
        if ($mgrUser === '' || $mgrPin === '') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid manager credentials',
                'message' => 'Invalid manager credentials'
            ]);
            exit;
        }

        try {
            $mgr = CashierControl::verifyManagerPin($mgrUser, $mgrPin, $authDeviceId);
        } catch (ManagerPinThrottledException $e) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'TOO_MANY_ATTEMPTS',
                'message' => $e->getMessage()
            ]);
            exit;
        } catch (Exception $e) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid manager credentials',
                'message' => 'Invalid manager credentials'
            ]);
            exit;
        }

        if (!in_array($mgr['manager_role'], ['manager', 'owner'], true)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid manager credentials',
                'message' => 'Approver must have manager or owner role.'
            ]);
            exit;
        }

        if ((int)$mgr['manager_id'] === $staffId) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'SELF_APPROVAL_NOT_ALLOWED',
                'message' => 'Approver cannot be the same user as the requester.'
            ]);
            exit;
        }

        $cancelledByUserId = $staffId;
        $cancelApprovedByUserId = (int)$mgr['manager_id'];
        $approverUsername = $mgr['manager_username'];
        $approverName = $mgr['manager_name'];
        $approverRole = $mgr['manager_role'];
    } else {
        // Direct cancellation by logged-in Manager or Owner session
        $cancelledByUserId = $staffId;
        $cancelApprovedByUserId = $staffId;
        $approverUsername = $stf['username'];
        $approverName = $stf['full_name'];
        $approverRole = $stf['role'];
    }

    $ord = null;
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    if ($ord['status'] === 'cancelled') {
        echo json_encode([
            'success' => true,
            'message' => "Order #{$ord['order_number']} is already cancelled"
        ]);
        exit;
    }

    $id = (int)$ord['id'];
    $requesterTitle = "{$stf['full_name']} (" . ucfirst($stf['role']) . ")";
    $approverTitle = "{$approverName} (" . ucfirst($approverRole) . ")";
    if ($cancelledByUserId === $cancelApprovedByUserId) {
        $fullReason = "Cancelled by {$requesterTitle}: {$reason}";
    } else {
        $fullReason = "Cancelled by {$requesterTitle}, approved by {$approverTitle}: {$reason}";
    }
    $cancelNote = " [{$fullReason}]";

    $pdo->beginTransaction();
    try {
        // 1. Restore product & spare part stock
        $itemsStmt = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ? AND product_id IS NOT NULL AND item_type IN ('product', 'spare_part')");
        $itemsStmt->execute([$id]);
        $pItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $restoreStockStmt = $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
        foreach ($pItems as $pi) {
            $restoreStockStmt->execute([$pi['qty'], $pi['product_id']]);
        }

        // 2. Soft-cancel crew commissions: Never hard delete
        $pdo->prepare("UPDATE order_washers SET is_active = 0, commission_usd = 0 WHERE order_id = ?")->execute([$id]);
        $pdo->prepare("UPDATE order_mechanics SET is_active = 0, commission_usd = 0 WHERE order_id = ?")->execute([$id]);

        // 3. Mark order cancelled with both user IDs
        $pdo->prepare("
            UPDATE orders 
            SET status = 'cancelled', 
                payment_status = 'cancelled', 
                repair_status = 'cancelled', 
                cancel_reason = ?, 
                cancelled_by_user_id = ?,
                cancel_approved_by_user_id = ?,
                cancelled_at = CURRENT_TIMESTAMP, 
                notes = CONCAT(COALESCE(notes, ''), ?) 
            WHERE id = ?
        ")->execute([$fullReason, $cancelledByUserId, $cancelApprovedByUserId, $cancelNote, $id]);

        // 4. Audit-log both IDs/usernames, order number, amount, reason, and device ID
        $orderAmount = number_format((float)($ord['total_usd'] ?? $ord['total_amount'] ?? 0), 2);
        $logDetails = "POS order #{$ord['order_number']} (Amount: \${$orderAmount}) cancelled by User #{$cancelledByUserId} ({$stf['username']}) approved by Manager #{$cancelApprovedByUserId} ({$approverUsername}) on Device #{$authDeviceId}. Reason: {$reason}";
        AuditLogger::log('ORDER_CANCEL', $logDetails, 'order', (string)$id, $cancelledByUserId, $stf['username'], $stf['role']);

        $pdo->commit();

        // 5. Reverse loyalty points (refund redeemed points and clawback earned points)
        try {
            Loyalty::reversePointsForOrder($pdo, $id, $staffId ?: null, $fullReason);
        } catch (Exception $e) {
            error_log("Loyalty reverse error on cancel_order in POS API: " . $e->getMessage());
        }

        echo json_encode([
            'success' => true,
            'message' => "Order #{$ord['order_number']} cancelled and stock restored successfully",
            'restored_items_count' => count($pItems)
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'CANCEL_FAILED',
            'message' => 'Failed to cancel order: ' . $e->getMessage()
        ]);
        exit;
    }
}

// ========================================================
// 10. ACTION: assign_crew
// Re-assign washers and mechanics on an existing order
// ========================================================
if ($action === 'assign_crew') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');
    $washers = $inputData['washers'] ?? $_POST['washers'] ?? [];
    $mechanics = $inputData['mechanics'] ?? $_POST['mechanics'] ?? [];
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_REQUIRED',
            'message' => 'Staff ID is required to assign or change crew.'
        ]);
        exit;
    }

    $stfStmt = $pdo->prepare("SELECT id, role, status FROM users WHERE id = ?");
    $stfStmt->execute([$staffId]);
    $stf = $stfStmt->fetch();
    if (!$stf || $stf['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'STAFF_INACTIVE',
            'message' => "Staff ID {$staffId} is inactive or not found."
        ]);
        exit;
    }

    if ($stf['role'] !== 'owner' && !Auth::userHasPermission($staffId, 'orders.change_crew')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PERMISSION_DENIED',
            'message' => "Staff ID {$staffId} does not have permission to assign crew (orders.change_crew required)."
        ]);
        exit;
    }

    $ord = null;
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch();
    } elseif ($orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $ord = $stmt->fetch();
    }

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $id = (int)$ord['id'];
    $totalUsd = (float)$ord['total_usd'];

    // Validate Washers: must exist, active, role washer OR can_assign_washer = 1
    if (!empty($washers) && is_array($washers)) {
        $chkW = $pdo->prepare("SELECT u.id, u.role, u.status, r.can_assign_washer FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        foreach ($washers as $wId) {
            $chkW->execute([(int)$wId]);
            $wUser = $chkW->fetch();
            $isWashEligible = $wUser && ($wUser['role'] === 'washer' || (!empty($wUser['can_assign_washer']) && (int)$wUser['can_assign_washer'] === 1));
            if (!$wUser || $wUser['status'] !== 'active' || !$isWashEligible) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_WASHER_ROLE',
                    'message' => "Invalid washer assigned (ID {$wId}): Must be an active user with washer capability."
                ]);
                exit;
            }
        }
    }

    // Validate Mechanics: must exist, active, role mechanic OR can_assign_mechanic = 1
    if (!empty($mechanics) && is_array($mechanics)) {
        $chkM = $pdo->prepare("SELECT u.id, u.role, u.status, r.can_assign_mechanic FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
        foreach ($mechanics as $mId) {
            $chkM->execute([(int)$mId]);
            $mUser = $chkM->fetch();
            $isMechEligible = $mUser && ($mUser['role'] === 'mechanic' || (!empty($mUser['can_assign_mechanic']) && (int)$mUser['can_assign_mechanic'] === 1));
            if (!$mUser || $mUser['status'] !== 'active' || !$isMechEligible) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'error' => 'INVALID_MECHANIC_ROLE',
                    'message' => "Invalid mechanic assigned (ID {$mId}): Must be an active user with mechanic capability."
                ]);
                exit;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        // Re-assign washers (soft delete previous assignments)
        $pdo->prepare("UPDATE order_washers SET is_active = 0 WHERE order_id = ?")->execute([$id]);
        if (is_array($washers) && !empty($washers)) {
            $cleanW = array_filter(array_map('intval', $washers));
            $numW = count($cleanW);
            if ($numW > 0) {
                $uStmt = $pdo->prepare("SELECT commission_type, commission_rate FROM users WHERE id = ?");
                $wIns = $pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd, is_active) VALUES (?, ?, ?, 1)");
                foreach ($cleanW as $wId) {
                    $uStmt->execute([$wId]);
                    $wRow = $uStmt->fetch();
                    $wComm = 0.00;
                    if ($wRow) {
                        if ($wRow['commission_type'] === 'percentage') {
                            $wComm = round(($totalUsd * ((float)$wRow['commission_rate'] / 100)) / $numW, 2);
                        } else {
                            $wComm = round((float)$wRow['commission_rate'] / $numW, 2);
                        }
                    }
                    $wIns->execute([$id, $wId, $wComm]);
                }
            }
        }

        // Re-assign mechanics (soft delete previous assignments)
        $pdo->prepare("UPDATE order_mechanics SET is_active = 0 WHERE order_id = ?")->execute([$id]);
        if (is_array($mechanics) && !empty($mechanics)) {
            $cleanM = array_filter(array_map('intval', $mechanics));
            $numM = count($cleanM);
            if ($numM > 0) {
                $uStmt = $pdo->prepare("SELECT commission_type, commission_rate FROM users WHERE id = ?");
                $mIns = $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, ?)");
                foreach ($cleanM as $mId) {
                    $uStmt->execute([$mId]);
                    $mRow = $uStmt->fetch();
                    $mComm = 0.00;
                    if ($mRow) {
                        if ($mRow['commission_type'] === 'percentage') {
                            $mComm = round(($totalUsd * ((float)$mRow['commission_rate'] / 100)) / $numM, 2);
                        } else {
                            $mComm = round((float)$mRow['commission_rate'] / $numM, 2);
                        }
                    }
                    $mIns->execute([$id, $mId, $mComm]);
                }

                // Update vehicle_repair_history mechanic names
                $mNamesStmt = $pdo->query("SELECT full_name FROM users WHERE id IN (" . implode(',', $cleanM) . ")");
                $mNames = $mNamesStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($mNames)) {
                    $pdo->prepare("UPDATE vehicle_repair_history SET mechanic_name = ? WHERE order_id = ?")
                        ->execute([implode(', ', $mNames), $id]);
                }
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => "Duty crew updated for Order #{$ord['order_number']}"
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'ASSIGN_CREW_FAILED',
            'message' => 'Failed to assign crew: ' . $e->getMessage()
        ]);
        exit;
    }
}

// ========================================================
// 11. ACTION: get_daily_report
// Sales by payment type, order counts, cancelled, expected cash
// Scoped to the authenticated POS terminal by default, with optional shop-wide access for owners
// ========================================================
if ($action === 'get_daily_report') {
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    
    $authDeviceId = (int)($device['id'] ?? 0);
$GLOBALS['authDeviceId'] = $authDeviceId;
    $staffUserId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    
    // Check if shop-wide total is requested
    $requestedShopWide = !empty($inputData['shop_wide']) || !empty($_POST['shop_wide']) || (($inputData['scope'] ?? $_POST['scope'] ?? '') === 'shop');
    $canViewShopWide = false;
    if ($requestedShopWide && $staffUserId > 0) {
        $stfStmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stfStmt->execute([$staffUserId]);
        $stfUser = $stfStmt->fetch();
        if ($stfUser && ($stfUser['role'] === 'owner' || Auth::userHasPermission($staffUserId, 'reports.view'))) {
            $canViewShopWide = true;
        }
    }

    // Default to authenticated device only; only owners/report viewers can get shop-wide
    $filterDeviceId = $canViewShopWide ? 0 : $authDeviceId;

    $sql = "
        SELECT
            COUNT(*) as total_orders_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders_count,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN 1 ELSE 0 END) as paid_orders_count,
            SUM(CASE WHEN (payment_status = 'unpaid' OR status = 'unpaid' OR status IN ('waiting', 'washing', 'finished')) AND status != 'cancelled' AND (status != 'paid' AND payment_status != 'paid') THEN 1 ELSE 0 END) as unpaid_orders_count,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'cash' THEN total_usd ELSE 0 END) as cash_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'aba' THEN total_usd ELSE 0 END) as aba_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'khqr' THEN total_usd ELSE 0 END) as khqr_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END) as total_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN subtotal_usd ELSE 0 END) as gross_sales_usd,
            SUM(CASE WHEN status = 'cancelled' THEN total_usd ELSE 0 END) as cancelled_sales_usd,
            SUM(CASE WHEN (payment_status = 'unpaid' OR status = 'unpaid' OR status IN ('waiting', 'washing', 'finished')) AND status != 'cancelled' AND (status != 'paid' AND payment_status != 'paid') THEN total_usd ELSE 0 END) as unpaid_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN discount_usd ELSE 0 END) as discount_total_usd
        FROM orders
        WHERE (DATE(COALESCE(paid_at, created_at)) = ?)
    ";
    $params = [$targetDate];
    if ($filterDeviceId > 0) {
        $sql .= " AND pos_device_id = ?";
        $params[] = $filterDeviceId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $cashSales = (float)($stats['cash_sales_usd'] ?? 0);
    $abaSales = (float)($stats['aba_sales_usd'] ?? 0);
    $khqrSales = (float)($stats['khqr_sales_usd'] ?? 0);
    $totalSales = (float)($stats['total_sales_usd'] ?? 0);
    $grossSales = (float)($stats['gross_sales_usd'] ?? 0);
    $totalOrders = (int)($stats['total_orders_count'] ?? 0);
    $cancelledOrders = (int)($stats['cancelled_orders_count'] ?? 0);
    $paidOrders = (int)($stats['paid_orders_count'] ?? 0);
    $unpaidOrders = (int)($stats['unpaid_orders_count'] ?? 0);
    $cancelledSales = (float)($stats['cancelled_sales_usd'] ?? 0);
    $unpaidSales = (float)($stats['unpaid_sales_usd'] ?? 0);
    $discountTotal = (float)($stats['discount_total_usd'] ?? 0);

    // Opening cash for drawer (support both opening_cash_usd and opening_cash)
    $openingCashUsd = (float)($inputData['opening_cash_usd'] ?? $_POST['opening_cash_usd'] ?? ($inputData['opening_cash'] ?? $_POST['opening_cash'] ?? 0.00));

    // Today's expenses (if device-specific expenses are stored, or shop-wide expenses if permitted)
    $expStmt = $pdo->prepare("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date = ?");
    $expStmt->execute([$targetDate]);
    $todayExpensesUsd = ($filterDeviceId === 0) ? (float)$expStmt->fetchColumn() : 0.00;

    $expectedCashUsd = max(0.00, round($openingCashUsd + $cashSales - $todayExpensesUsd, 2));

    // Shift orders list (orders for this date and device)
    $orderSql = "
        SELECT o.id, o.order_number, o.plate_number, o.customer_name, o.payment_method, o.payment_status, o.subtotal_usd, o.total_usd, o.total_khr, o.discount_usd, o.status, o.cashier_id, o.pos_device_id, o.pos_device_name, o.created_at, o.paid_at,
               (SELECT GROUP_CONCAT(CONCAT(oi.service_name, ' (x', oi.qty, ')') SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) as item_names
        FROM orders o
        WHERE (DATE(COALESCE(o.paid_at, o.created_at)) = ?)
    ";
    $orderParams = [$targetDate];
    if ($filterDeviceId > 0) {
        $orderSql .= " AND o.pos_device_id = ?";
        $orderParams[] = $filterDeviceId;
    }
    $orderSql .= " ORDER BY o.id DESC";
    $ordStmt = $pdo->prepare($orderSql);
    $ordStmt->execute($orderParams);
    $shiftOrders = $ordStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if day is already closed in pos_closings or pos_daily_closes
    $closeStmt = $pdo->prepare("
        SELECT id, close_date, closed_at, cashier_name, expected_cash_usd, counted_cash_usd, counted_cash_khr, difference_usd, notes, status
        FROM pos_closings 
        WHERE close_date = ? AND pos_device_id = ? AND status = 'closed'
        ORDER BY id DESC LIMIT 1
    ");
    $closeStmt->execute([$targetDate, $authDeviceId]);
    $closeRec = $closeStmt->fetch(PDO::FETCH_ASSOC);

    // Fallback check to pos_daily_closes if not in pos_closings
    if (!$closeRec) {
        $oldCloseStmt = $pdo->prepare("
            SELECT id, close_date, closed_at, closed_by_name as cashier_name, expected_cash_usd, counted_cash_usd, counted_cash_khr, difference_usd, notes, status
            FROM pos_daily_closes 
            WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL) AND status = 'closed'
            ORDER BY id DESC LIMIT 1
        ");
        $oldCloseStmt->execute([$targetDate, $authDeviceId]);
        $closeRec = $oldCloseStmt->fetch(PDO::FETCH_ASSOC);
    }

    // List of active devices
    $devicesList = $pdo->query("SELECT id, device_name, location FROM pos_devices WHERE status = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

    $reportPayload = [
        'report_date' => $targetDate,
        'device_id' => $authDeviceId,
        'device_name' => $device['device_name'] ?? 'POS Terminal',
        'device_location' => $device['location'] ?? 'Main Counter',
        'is_shop_wide' => $canViewShopWide,
        'is_closed' => !empty($closeRec),
        'close_record' => $closeRec ?: null,
        'opening_cash' => $openingCashUsd,
        'opening_cash_usd' => $openingCashUsd,
        'gross_sales' => $grossSales,
        'gross_sales_usd' => $grossSales,
        'total_sales' => $totalSales,
        'total_sales_usd' => $totalSales,
        'total_sales_khr' => (int)round($totalSales * get_exchange_rate()),
        'cash_sales' => $cashSales,
        'cash_sales_usd' => $cashSales,
        'cash_sales_khr' => (int)round($cashSales * get_exchange_rate()),
        'aba_sales' => $abaSales,
        'aba_sales_usd' => $abaSales,
        'aba_sales_khr' => (int)round($abaSales * get_exchange_rate()),
        'khqr_sales' => $khqrSales,
        'khqr_sales_usd' => $khqrSales,
        'khqr_sales_khr' => (int)round($khqrSales * get_exchange_rate()),
        'expenses' => $todayExpensesUsd,
        'expenses_usd' => $todayExpensesUsd,
        'expected_cash' => $expectedCashUsd,
        'expected_cash_usd' => $expectedCashUsd,
        'expected_cash_khr' => (int)round($expectedCashUsd * get_exchange_rate()),
        'discount_total' => $discountTotal,
        'discount_total_usd' => $discountTotal,
        'total_orders' => $totalOrders,
        'cancelled_orders' => $cancelledOrders,
        'cancelled_sales' => $cancelledSales,
        'cancelled_sales_usd' => $cancelledSales,
        'paid_orders' => $paidOrders,
        'paid_orders_count' => $paidOrders,
        'unpaid_orders' => $unpaidOrders,
        'unpaid_orders_count' => $unpaidOrders,
        'unpaid_sales' => $unpaidSales,
        'unpaid_sales_usd' => $unpaidSales,
        'orders' => $shiftOrders,
        'synced_at' => date('Y-m-d H:i:s')
    ];

    echo json_encode(array_merge([
        'success' => true,
        'devices' => $devicesList,
        'report' => $reportPayload
    ], $reportPayload));
    exit;
}

// ========================================================
// 12. ACTION: get_oil_reminders / oil_followups
// Vehicles whose next oil change is due soon (<= 30 days or overdue)
// ========================================================
if ($action === 'get_oil_reminders' || $action === 'oil_followups' || $action === 'get_oil_followups') {
    $filter = trim($inputData['filter'] ?? $_POST['filter'] ?? 'all');
    $search = trim($inputData['search'] ?? $_POST['search'] ?? '');

    $sql = "
        SELECT
            v.id as vehicle_id,
            v.customer_id,
            v.plate_number,
            v.vehicle_type,
            v.make_model,
            v.color,
            COALESCE(c.name, 'Valued Customer') as customer_name,
            c.phone as customer_phone,
            v.last_mileage,
            v.next_oil_mileage,
            v.next_oil_date,
            v.last_contacted_at,
            v.contact_notes,
            v.snooze_until,
            DATEDIFF(v.next_oil_date, CURDATE()) as days_remaining,
            CASE
                WHEN v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE() THEN 'overdue'
                WHEN v.next_oil_date IS NOT NULL AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'due_soon'
                WHEN v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500) THEN 'due_soon'
                ELSE 'ok'
            END as reminder_status
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.id
        WHERE ((v.next_oil_date IS NOT NULL AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
           OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500)))
    ";
    $params = [];

    if ($filter === 'overdue') {
        $sql .= " AND (v.next_oil_date IS NOT NULL AND v.next_oil_date < CURDATE())";
    } elseif ($filter === 'due_soon') {
        $sql .= " AND ((v.next_oil_date IS NOT NULL AND v.next_oil_date >= CURDATE() AND v.next_oil_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) OR (v.next_oil_mileage IS NOT NULL AND v.last_mileage IS NOT NULL AND v.last_mileage >= (v.next_oil_mileage - 500)))";
    }

    if ($filter !== 'all') {
        $sql .= " AND (v.snooze_until IS NULL OR v.snooze_until <= CURDATE())";
    }

    if ($search !== '') {
        $sql .= " AND (v.plate_number LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $sql .= "
        ORDER BY
            CASE WHEN v.next_oil_date < CURDATE() THEN 1 ELSE 2 END,
            v.next_oil_date ASC,
            v.next_oil_mileage ASC
        LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'reminders' => $reminders,
        'count' => count($reminders),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 13. ACTION: save_repair_note
// ========================================================
if ($action === 'save_repair_note') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');
    $notes = trim($inputData['repair_notes'] ?? $_POST['repair_notes'] ?? '');

    $ord = null;
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT id, order_number FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch();
    } elseif ($orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT id, order_number FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $ord = $stmt->fetch();
    }

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $id = (int)$ord['id'];
    $pdo->prepare("UPDATE orders SET repair_notes = ? WHERE id = ?")->execute([$notes, $id]);
    try {
        $pdo->prepare("UPDATE vehicle_repair_history SET repair_notes = ? WHERE order_id = ?")->execute([$notes, $id]);
    } catch (Exception $e) {}

    echo json_encode([
        'success' => true,
        'message' => "Repair notes updated for order #{$ord['order_number']}"
    ]);
    exit;
}


// ========================================================
// 14. ACTION: get_order (For Reprint & Verification)
// ========================================================
if ($action === 'get_order') {
    $orderNumber = trim($inputData['order_number'] ?? $_POST['order_number'] ?? '');
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);

    $ord = null;
    if ($orderId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($orderNumber !== '') {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = ?");
        $stmt->execute([$orderNumber]);
        $ord = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$ord) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $id = (int)$ord['id'];
    $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$id]);
    $ord['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $wStmt = $pdo->prepare("SELECT u.id, u.full_name FROM order_washers ow JOIN users u ON ow.washer_id = u.id WHERE ow.order_id = ?");
    $wStmt->execute([$id]);
    $ord['washers'] = $wStmt->fetchAll(PDO::FETCH_ASSOC);
    $ord['washer_names'] = implode(', ', array_column($ord['washers'], 'full_name'));

    $mStmt = $pdo->prepare("SELECT u.id, u.full_name FROM order_mechanics om JOIN users u ON om.mechanic_id = u.id WHERE om.order_id = ?");
    $mStmt->execute([$id]);
    $ord['mechanics'] = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    $ord['mechanic_names'] = implode(', ', array_column($ord['mechanics'], 'full_name'));

    echo json_encode(['success' => true, 'order' => $ord]);
    exit;
}

// ========================================================
// 15. ACTION: update_oil_contact / record_oil_followup
// ========================================================
if ($action === 'update_oil_contact' || $action === 'record_oil_followup' || $action === 'save_oil_followup') {
    $vehicleId = (int)($inputData['vehicle_id'] ?? $inputData['id'] ?? $_POST['vehicle_id'] ?? $_POST['id'] ?? 0);
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));
    $actionType = trim($inputData['action_type'] ?? $_POST['action_type'] ?? 'contacted');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    if (!$vehicleId && $plateNumber !== '') {
        $vLookup = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ? LIMIT 1");
        $vLookup->execute([$plateNumber]);
        $vehicleId = (int)$vLookup->fetchColumn();
    }

    if (!$vehicleId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Vehicle ID or plate number is required']);
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $snoozeDate = null;
    if ($actionType === 'snooze_7') {
        $snoozeDate = date('Y-m-d', strtotime('+7 days'));
    } elseif ($actionType === 'snooze_30') {
        $snoozeDate = date('Y-m-d', strtotime('+30 days'));
    }

    if ($snoozeDate) {
        $stmt = $pdo->prepare("UPDATE vehicles SET snooze_until = ?, contact_notes = ? WHERE id = ?");
        $stmt->execute([$snoozeDate, $notes ?: "Snoozed until {$snoozeDate}", $vehicleId]);
    } else {
        $stmt = $pdo->prepare("UPDATE vehicles SET last_contacted_at = ?, contact_notes = ? WHERE id = ?");
        $stmt->execute([$now, $notes ?: 'Customer contacted', $vehicleId]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Oil follow-up recorded',
        'vehicle_id' => $vehicleId,
        'snooze_until' => $snoozeDate,
        'last_contacted_at' => $now
    ]);
    exit;
}

// ========================================================
// 16. ACTION: save_pos_closing / close_day / save_daily_close
// Saves the end-of-shift / daily close per device into pos_closings
// ========================================================
if ($action === 'save_pos_closing' || $action === 'close_day' || $action === 'save_daily_close') {
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        $targetDate = date('Y-m-d');
    }
    $openingCashUsd = (float)($inputData['opening_cash_usd'] ?? $_POST['opening_cash_usd'] ?? ($inputData['opening_cash'] ?? $_POST['opening_cash'] ?? 0));
    $countedUsd = (float)($inputData['counted_cash_usd'] ?? $_POST['counted_cash_usd'] ?? ($inputData['counted_cash'] ?? $_POST['counted_cash'] ?? 0));
    $countedKhr = (int)($inputData['counted_cash_khr'] ?? $_POST['counted_cash_khr'] ?? 0);
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? ($inputData['note'] ?? $_POST['note'] ?? ''));
    $staffUserId = (int)($inputData['user_id'] ?? $_POST['user_id'] ?? $inputData['staff_id'] ?? $_POST['staff_id'] ?? 0);
    $staffName = trim($inputData['user_name'] ?? $_POST['user_name'] ?? '');
    $staffRole = trim($inputData['user_role'] ?? $_POST['user_role'] ?? '');
    $deviceId = (int)($device['id'] ?? 0);
    $deviceName = $device['device_name'] ?? 'POS Terminal';

    if ($staffUserId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required to close the shift or day.']);
        exit;
    }

    $uStmt = $pdo->prepare("SELECT id, full_name, role, status FROM users WHERE id = ?");
    $uStmt->execute([$staffUserId]);
    $u = $uStmt->fetch();
    if (!$u || $u['status'] !== 'active') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'STAFF_INACTIVE', 'message' => 'Staff user account is inactive or not found.']);
        exit;
    }
    $staffName = $u['full_name'];
    $staffRole = $u['role'];
    if ($staffRole !== 'owner' && !Auth::userHasPermission($staffUserId, 'reports.daily_close') && !Auth::userHasPermission($staffUserId, 'pos.close_day') && !in_array($staffRole, ['cashier', 'manager'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'PERMISSION_DENIED', 'message' => 'You do not have permission to close the shift or day.']);
        exit;
    }

    if (in_array($staffRole, ['washer', 'mechanic', 'technical'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'PERMISSION_DENIED', 'message' => 'You do not have permission to close the shift or day.']);
        exit;
    }

    // Check if device already closed for target date
    $chkStmt = $pdo->prepare("SELECT id, closed_at, cashier_name FROM pos_closings WHERE close_date = ? AND pos_device_id = ? AND status = 'closed'");
    $chkStmt->execute([$targetDate, $deviceId]);
    $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'ALREADY_CLOSED',
            'message' => "Shift for device '{$deviceName}' already closed for {$targetDate} by {$existing['cashier_name']} at {$existing['closed_at']}."
        ]);
        exit;
    }

    $sql = "
        SELECT
            COUNT(*) as total_orders_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders_count,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN 1 ELSE 0 END) as paid_orders_count,
            SUM(CASE WHEN (payment_status = 'unpaid' OR status = 'unpaid' OR status IN ('waiting', 'washing', 'finished')) AND status != 'cancelled' AND (status != 'paid' AND payment_status != 'paid') THEN 1 ELSE 0 END) as unpaid_orders_count,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'cash' THEN total_usd ELSE 0 END) as cash_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'aba' THEN total_usd ELSE 0 END) as aba_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' AND payment_method = 'khqr' THEN total_usd ELSE 0 END) as khqr_sales_usd,
            SUM(CASE WHEN (status = 'paid' OR payment_status = 'paid') AND status != 'cancelled' THEN total_usd ELSE 0 END) as total_sales_usd,
            SUM(CASE WHEN status != 'cancelled' THEN discount_usd ELSE 0 END) as discount_total_usd
        FROM orders
        WHERE (DATE(COALESCE(paid_at, created_at)) = ?) AND pos_device_id = ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$targetDate, $deviceId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $cashSales = (float)($stats['cash_sales_usd'] ?? 0);
    $abaSales = (float)($stats['aba_sales_usd'] ?? 0);
    $khqrSales = (float)($stats['khqr_sales_usd'] ?? 0);
    $totalSales = (float)($stats['total_sales_usd'] ?? 0);
    $discountTotal = (float)($stats['discount_total_usd'] ?? 0);
    $paidCount = (int)($stats['paid_orders_count'] ?? 0);
    $unpaidCount = (int)($stats['unpaid_orders_count'] ?? 0);
    $cancelledCount = (int)($stats['cancelled_orders_count'] ?? 0);

    $expensesUsd = 0.00; // Device-level cash drawer expenses (if any)
    $expectedCashUsd = max(0.00, round($openingCashUsd + $cashSales - $expensesUsd, 2));
    $rate = get_exchange_rate();
    $totalCountedUsd = round($countedUsd + ($countedKhr / $rate), 2);
    $diffUsd = round($totalCountedUsd - $expectedCashUsd, 2);

    // 1. Insert into pos_closings
    $insClosing = $pdo->prepare("
        INSERT INTO pos_closings (
            pos_device_id, device_name, cashier_id, cashier_name,
            closed_at, close_date, opening_cash_usd, expected_cash_usd,
            counted_cash_usd, counted_cash_khr, difference_usd,
            total_sales_usd, cash_sales_usd, aba_sales_usd, khqr_sales_usd,
            expenses_usd, discount_total_usd, paid_orders_count,
            unpaid_orders_count, cancelled_orders_count, notes, status
        ) VALUES (
            ?, ?, ?, ?,
            CURRENT_TIMESTAMP, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, 'closed'
        )
    ");
    $insClosing->execute([
        $deviceId, $deviceName, $staffUserId, $staffName,
        $targetDate, $openingCashUsd, $expectedCashUsd,
        $countedUsd, $countedKhr, $diffUsd,
        $totalSales, $cashSales, $abaSales, $khqrSales,
        $expensesUsd, $discountTotal, $paidCount,
        $unpaidCount, $cancelledCount, $notes
    ]);
    $closingId = (int)$pdo->lastInsertId();

    // 2. Synchronize into pos_daily_closes for compatibility
    try {
        $insDaily = $pdo->prepare("
            INSERT INTO pos_daily_closes (
                close_date, pos_device_id, device_name, closed_by_user_id, closed_by_name, role,
                closed_at, total_sales_usd, total_sales_khr, cash_sales_usd, aba_sales_usd, khqr_sales_usd,
                expenses_usd, discount_total_usd, expected_cash_usd, counted_cash_usd, counted_cash_khr,
                difference_usd, paid_orders_count, unpaid_orders_count, cancelled_orders_count, notes, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, 'closed'
            )
        ");
        $insDaily->execute([
            $targetDate, $deviceId, $deviceName, $staffUserId, $staffName, $staffRole,
            $totalSales, (int)round($totalSales * $rate), $cashSales, $abaSales, $khqrSales,
            $expensesUsd, $discountTotal, $expectedCashUsd, $countedUsd, $countedKhr,
            $diffUsd, $paidCount, $unpaidCount, $cancelledCount, $notes
        ]);
    } catch (Exception $e) {
        // Ignored if duplicate or already closed
    }

    $closingObj = [
        'id' => $closingId,
        'pos_device_id' => $deviceId,
        'device_name' => $deviceName,
        'cashier_name' => $staffName,
        'close_date' => $targetDate,
        'closed_at' => date('Y-m-d H:i:s'),
        'opening_cash' => $openingCashUsd,
        'opening_cash_usd' => $openingCashUsd,
        'expected_cash' => $expectedCashUsd,
        'expected_cash_usd' => $expectedCashUsd,
        'counted_total_usd' => $totalCountedUsd,
        'counted_cash' => $countedUsd,
        'counted_cash_usd' => $countedUsd,
        'counted_cash_khr' => $countedKhr,
        'difference' => $diffUsd,
        'difference_usd' => $diffUsd,
        'total_sales' => $totalSales,
        'total_sales_usd' => $totalSales,
        'cash_sales' => $cashSales,
        'cash_sales_usd' => $cashSales,
        'aba_sales' => $abaSales,
        'aba_sales_usd' => $abaSales,
        'khqr_sales' => $khqrSales,
        'khqr_sales_usd' => $khqrSales,
        'paid_orders_count' => $paidCount,
        'unpaid_orders_count' => $unpaidCount,
        'cancelled_orders_count' => $cancelledCount,
        'notes' => $notes,
        'note' => $notes
    ];

    echo json_encode(array_merge([
        'success' => true,
        'message' => "Daily close for device '{$deviceName}' saved successfully",
        'close_id' => $closingId,
        'closing' => $closingObj
    ], $closingObj));
    exit;
}

// ========================================================
// 17. ACTION: reopen_daily_close / reopen_day
// ========================================================
if ($action === 'reopen_daily_close' || $action === 'reopen_day') {
    $staffUserId = (int)($inputData['staff_id'] ?? $inputData['user_id'] ?? $_POST['staff_id'] ?? $_POST['user_id'] ?? 0);
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    $deviceId = (int)($device['id'] ?? 0);

    // SERVER-SIDE ROLE CHECK: Check real staff member from DB, never trust client input
    $stf = null;
    if ($staffUserId > 0) {
        $stfStmt = $pdo->prepare("SELECT id, username, full_name, role, status FROM users WHERE id = ?");
        $stfStmt->execute([$staffUserId]);
        $stf = $stfStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$stf || $stf['status'] !== 'active' || !in_array($stf['role'], ['owner', 'manager'], true)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PERMISSION_DENIED',
            'message' => 'Only Owner or Manager can reopen a closed day.'
        ]);
        exit;
    }

    // 1. Update pos_closings
    $pdo->prepare("
        UPDATE pos_closings 
        SET status = 'reopened', reopened_by_user_id = ?, reopened_at = CURRENT_TIMESTAMP 
        WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL OR ? = 0) AND status = 'closed'
    ")->execute([$staffUserId, $targetDate, $deviceId, $deviceId]);

    // 2. Update pos_daily_closes
    $pdo->prepare("
        UPDATE pos_daily_closes 
        SET status = 'reopened', reopened_by_user_id = ?, reopened_at = CURRENT_TIMESTAMP 
        WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL OR ? = 0) AND status = 'closed'
    ")->execute([$staffUserId, $targetDate, $deviceId, $deviceId]);

    $reopenerTitle = "{$stf['full_name']} (" . ucfirst($stf['role']) . ")";
    echo json_encode([
        'success' => true,
        'message' => "Shift for {$targetDate} reopened successfully by {$reopenerTitle}.",
        'reopened_by' => $reopenerTitle,
        'reopened_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 18. ACTION: get_daily_close_status / daily_close_status / get_day_status
// ========================================================
if ($action === 'get_daily_close_status' || $action === 'daily_close_status' || $action === 'get_day_status') {
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    $deviceId = (int)($device['id'] ?? 0);

    // 1. Query pos_closings first
    $stmt = $pdo->prepare("
        SELECT id, close_date, closed_at, cashier_id, cashier_name, expected_cash_usd, counted_cash_usd, counted_cash_khr, difference_usd, total_sales_usd, status 
        FROM pos_closings 
        WHERE close_date = ? AND (pos_device_id = ? OR ? = 0) AND status = 'closed'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$targetDate, $deviceId, $deviceId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Fallback to pos_daily_closes if not in pos_closings
    if (!$rec) {
        $stmt2 = $pdo->prepare("
            SELECT id, close_date, closed_at, closed_by_user_id as cashier_id, closed_by_name as cashier_name, role, expected_cash_usd, counted_cash_usd, counted_cash_khr, difference_usd, total_sales_usd, status 
            FROM pos_daily_closes 
            WHERE close_date = ? AND (pos_device_id = ? OR pos_device_id IS NULL OR ? = 0) AND status = 'closed'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt2->execute([$targetDate, $deviceId, $deviceId]);
        $rec = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    if ($rec) {
        $rec['closed_by_name'] = $rec['cashier_name'] ?? 'Staff';
        $rec['is_closed'] = ($rec['status'] === 'closed');
    }

    echo json_encode([
        'success' => true,
        'is_closed' => !empty($rec) && ($rec['status'] === 'closed'),
        'close_record' => $rec ?: null
    ]);
    exit;
}

// ========================================================
// 19. ACTION: shift_start
// ========================================================
if ($action === 'shift_start') {
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Cashier staff ID is required to start a shift.']);
        exit;
    }
    $cashierName = trim($inputData['cashier_name'] ?? $_POST['cashier_name'] ?? '');
    $openingUsd = max(0.00, (float)($inputData['opening_cash_usd'] ?? $_POST['opening_cash_usd'] ?? 0.00));
    $openingKhr = max(0, (int)($inputData['opening_cash_khr'] ?? $_POST['opening_cash_khr'] ?? 0));
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    try {
        $res = CashierControl::startShift($cashierId, $cashierName, $openingUsd, $openingKhr, $authDeviceId, $device['device_name'] ?? null, $notes);
        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 20. ACTION: shift_end (Handover)
// ========================================================
if ($action === 'shift_end') {
    $shiftId = (int)($inputData['shift_id'] ?? $_POST['shift_id'] ?? 0);
    $countedUsd = max(0.00, (float)($inputData['counted_cash_usd'] ?? $_POST['counted_cash_usd'] ?? 0.00));
    $countedKhr = max(0, (int)($inputData['counted_cash_khr'] ?? $_POST['counted_cash_khr'] ?? 0));
    $outPin = (string)($inputData['outgoing_pin'] ?? $_POST['outgoing_pin'] ?? '');
    $inUser = trim($inputData['incoming_username'] ?? $_POST['incoming_username'] ?? '');
    $inPin = (string)($inputData['incoming_pin'] ?? $_POST['incoming_pin'] ?? '');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    try {
        $res = CashierControl::endShift($shiftId, $countedUsd, $countedKhr, $outPin, $inUser, $inPin, $notes);
        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 21. ACTION: open_drawer_no_sale
// ========================================================
if ($action === 'open_drawer_no_sale') {
    $reason = trim($inputData['reason'] ?? $_POST['reason'] ?? '');
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Cashier staff ID is required to open drawer.']);
        exit;
    }

    try {
        $res = CashierControl::openDrawerNoSale($reason, $mgrUser, $mgrPin, $cashierId, $authDeviceId);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 22. ACTION: spot_audit
// ========================================================
if ($action === 'spot_audit') {
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Cashier staff ID is required for spot audit.']);
        exit;
    }
    $countedUsd = max(0.00, (float)($inputData['counted_cash_usd'] ?? $_POST['counted_cash_usd'] ?? 0.00));
    $countedKhr = max(0, (int)($inputData['counted_cash_khr'] ?? $_POST['counted_cash_khr'] ?? 0));
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    try {
        $res = CashierControl::conductSpotAudit($cashierId, $countedUsd, $countedKhr, $mgrUser, $mgrPin, $notes);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 23. ACTION: process_refund
// ========================================================
if ($action === 'process_refund') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $refundAmountUsd = (float)($inputData['refund_amount_usd'] ?? $_POST['refund_amount_usd'] ?? 0.00);
    $refundReason = trim($inputData['refund_reason'] ?? $_POST['refund_reason'] ?? '');
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Cashier staff ID is required to process refund.']);
        exit;
    }
    $isOffline = !empty($inputData['is_offline']) && ($inputData['is_offline'] === true || $inputData['is_offline'] === '1');

    if ($isOffline) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'OFFLINE_REFUND_PROHIBITED',
            'message' => 'Offline refunds are strictly prohibited. If connection is lost, use the paper logbook signed by the Manager.'
        ]);
        exit;
    }

    try {
        $res = CashierControl::processRefund($orderId, $refundAmountUsd, $refundReason, $mgrUser, $mgrPin, $cashierId, true);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 24. ACTION: close_day_zreport
// ========================================================
if ($action === 'close_day_zreport') {
    $countedUsd = max(0.00, (float)($inputData['counted_cash_usd'] ?? $_POST['counted_cash_usd'] ?? 0.00));
    $countedKhr = max(0, (int)($inputData['counted_cash_khr'] ?? $_POST['counted_cash_khr'] ?? 0));
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    try {
        $res = CashierControl::closeDayZReport($mgrUser, $mgrPin, $countedUsd, $countedKhr, $notes);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 25. ACTION: reopen_day
// ========================================================
if ($action === 'reopen_day') {
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? date('Y-m-d'));
    $reason = trim($inputData['reason'] ?? $_POST['reason'] ?? '');
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');

    try {
        $res = CashierControl::reopenClosedDay($targetDate, $reason, $mgrUser, $mgrPin);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 26. ACTION: get_shift_status
// ========================================================
if ($action === 'get_shift_status') {
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? $_GET['cashier_id'] ?? 0);
    $shift = CashierControl::getActiveShift($cashierId);
    echo json_encode([
        'success' => true,
        'has_active_shift' => !empty($shift),
        'shift' => $shift
    ]);
    exit;
}

// ========================================================
// 27. ACTION: verify_manager_pin
// ========================================================
if ($action === 'verify_manager_pin') {
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');

    try {
        $manager = CashierControl::verifyManagerPin($mgrUser, $mgrPin, $authDeviceId);
        echo json_encode([
            'success' => true,
            'manager' => [
                'id' => $manager['manager_id'],
                'username' => $manager['manager_username'],
                'full_name' => $manager['manager_name'],
                'role' => $manager['manager_role']
            ]
        ]);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 28. ACTION: get_day_lock_status
// ========================================================
if ($action === 'get_day_lock_status') {
    $targetDate = trim($inputData['date'] ?? $_POST['date'] ?? $_GET['date'] ?? date('Y-m-d'));
    $lockStatus = CashierControl::isDayLocked($targetDate);
    echo json_encode([
        'success' => true,
        'date' => $targetDate,
        'is_locked' => !empty($lockStatus['locked']),
        'lock_reason' => $lockStatus['reason'] ?? null,
        'message' => $lockStatus['message'] ?? 'Day is open'
    ]);
    exit;
}

// ========================================================
// 29. ACTION: submit_bank_transfer
// ========================================================
if ($action === 'submit_bank_transfer') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $payMethod = strtolower(trim($inputData['payment_method'] ?? $_POST['payment_method'] ?? 'aba'));
    $payRef = trim($inputData['payment_ref'] ?? $_POST['payment_ref'] ?? '');
    $tendered = (float)($inputData['tendered_usd'] ?? $_POST['tendered_usd'] ?? 0.00);
    $discount = (float)($inputData['discount_usd'] ?? $_POST['discount_usd'] ?? 0.00);
    $points = (int)($inputData['points_to_redeem'] ?? $_POST['points_to_redeem'] ?? 0);
    $cashierId = (int)($inputData['cashier_id'] ?? $inputData['staff_id'] ?? $inputData['user_id'] ?? $_POST['cashier_id'] ?? $_POST['staff_id'] ?? $_POST['user_id'] ?? 0);
    if ($cashierId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Cashier staff ID is required to submit bank transfer.']);
        exit;
    }

    $slipInput = getSlipFileFromInput($inputData['slip_base64'] ?? $_POST['slip_base64'] ?? null, $_FILES['slip_image'] ?? $_FILES['slip_file'] ?? null);

    try {
        $res = BankTransferControl::submitBankTransfer(
            $orderId,
            $payMethod,
            $slipInput,
            null,
            $payRef,
            $tendered,
            $discount,
            $points,
            $cashierId ?: null
        );
        echo json_encode($res);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 30. ACTION: approve_bank_transfer
// ========================================================
if ($action === 'approve_bank_transfer') {
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $mgrUser = trim($inputData['manager_username'] ?? $_POST['manager_username'] ?? '');
    $mgrPin = (string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? '');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    try {
        $res = BankTransferControl::approveTransfer($orderId, $mgrUser, $mgrPin, $notes);
        echo json_encode($res);
    } catch (ManagerPinThrottledException $e) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'TOO_MANY_ATTEMPTS', 'message' => $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 31. ACTION: get_washer_jobs
// ========================================================
if ($action === 'get_washer_jobs') {
    $staffId = (int)($inputData['staff_id'] ?? $_GET['staff_id'] ?? $inputData['user_id'] ?? $_GET['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    $allBays = BayManager::getAllBays($pdo);
    $washBays = array_values(array_filter($allBays, fn($b) => $b['bay_type'] === 'wash'));
    $queues = BayManager::getQueues($pdo);
    $washQueue = $queues['wash_queue'];

    $isSqlite = (Database::getDriver() === 'sqlite');
    $svcConcat = $isSqlite ? "GROUP_CONCAT(DISTINCT oi.service_name)" : "GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ')";

    // Fetch orders assigned to this washer or in progress in wash bays
    $jobs = [];
    if ($stfUser) {
        $jobsStmt = $pdo->prepare("
            SELECT o.*, COALESCE(ow.commission_usd, 0.00) as commission_usd,
                   (SELECT {$svcConcat} FROM order_items oi WHERE oi.order_id = o.id) as service_names,
                   b.bay_name,
                   (SELECT ot.status FROM order_tasks ot WHERE ot.order_id = o.id AND ot.task_type = 'wash' LIMIT 1) as wash_task_status
            FROM orders o
            LEFT JOIN order_washers ow ON o.id = ow.order_id AND ow.washer_id = ?
            LEFT JOIN bays b ON o.bay_id = b.id
            WHERE (
                ow.washer_id = ? 
                OR (b.bay_type = 'wash' AND o.status = 'washing')
            )
            AND o.status NOT IN ('cancelled', 'finished')
            GROUP BY o.id
            ORDER BY CASE o.status 
                WHEN 'washing' THEN 1
                WHEN 'waiting' THEN 2
                ELSE 3 END, o.id DESC
            LIMIT 50
        ");
        $jobsStmt->execute([$stfUser['id'], $stfUser['id']]);
        $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Today's commission for washer
    $todayEarn = ['total_earned' => 0.00, 'jobs_count' => 0];
    if ($stfUser) {
        $earnStmt = $pdo->prepare("
            SELECT COALESCE(SUM(ow.commission_usd), 0) as total_earned, COUNT(DISTINCT o.id) as jobs_count
            FROM orders o
            JOIN order_washers ow ON o.id = ow.order_id
            WHERE ow.washer_id = ? AND DATE(o.created_at) = CURDATE()
        ");
        $earnStmt->execute([$stfUser['id']]);
        $todayEarn = $earnStmt->fetch(PDO::FETCH_ASSOC) ?: $todayEarn;
    }

    echo json_encode([
        'success' => true,
        'washer' => $stfUser ? [
            'id' => $stfUser['id'],
            'full_name' => $stfUser['full_name'],
            'username' => $stfUser['username'],
            'role' => $stfUser['effective_role']
        ] : null,
        'bays' => $washBays,
        'jobs' => $jobs,
        'wash_queue' => $washQueue,
        'today_commission' => (float)$todayEarn['total_earned'],
        'jobs_count' => (int)$todayEarn['jobs_count'],
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 32. ACTION: get_technical_jobs
// ========================================================
if ($action === 'get_technical_jobs') {
    $staffId = (int)($inputData['staff_id'] ?? $_GET['staff_id'] ?? $inputData['user_id'] ?? $_GET['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    $allBays = BayManager::getAllBays($pdo);
    $queues = BayManager::getQueues($pdo);
    $techQueue = $queues['technical_queue'];

    $isSqlite = (Database::getDriver() === 'sqlite');
    $svcConcat = $isSqlite ? "GROUP_CONCAT(DISTINCT oi.service_name)" : "GROUP_CONCAT(DISTINCT oi.service_name SEPARATOR ', ')";

    // Also find cars waiting for handshake / tech receive (ready_for_tech)
    $waitingHandoverStmt = $pdo->query("
        SELECT o.*,
               (SELECT {$svcConcat} FROM order_items oi WHERE oi.order_id = o.id) as service_names
        FROM orders o
        JOIN order_tasks ot ON o.id = ot.order_id
        WHERE ot.task_type = 'oil_change' AND ot.status = 'ready_for_tech' AND o.status != 'cancelled'
        GROUP BY o.id
        ORDER BY o.id ASC
    ");
    $waitingHandover = $waitingHandoverStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active jobs for mechanic (assigned to technician OR actively in Bay 4 / oil change bay)
    $jobs = [];
    if ($stfUser) {
        $jobsStmt = $pdo->prepare("
            SELECT o.*, COALESCE(om.commission_usd, 0.00) as commission_usd,
                   COALESCE(o.repair_status, o.status) as current_job_status,
                   b.bay_name,
                   (SELECT {$svcConcat} FROM order_items oi WHERE oi.order_id = o.id) as service_names
            FROM orders o
            LEFT JOIN order_mechanics om ON o.id = om.order_id AND om.mechanic_id = ?
            LEFT JOIN bays b ON o.bay_id = b.id
            WHERE (
                om.mechanic_id = ?
                OR o.bay_id = 4
                OR b.bay_type = 'oil_change'
                OR o.repair_status IN ('waiting_tech', 'working')
            )
            AND o.status NOT IN ('cancelled', 'finished')
            AND COALESCE(o.repair_status, '') != 'finished'
            GROUP BY o.id
            ORDER BY CASE COALESCE(o.repair_status, o.status)
                WHEN 'working' THEN 1
                WHEN 'waiting' THEN 2
                ELSE 3 END, o.id DESC
            LIMIT 50
        ");
        $jobsStmt->execute([$stfUser['id'], $stfUser['id']]);
        $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $jobsStmt = $pdo->query("
            SELECT o.*, 0.00 as commission_usd,
                   COALESCE(o.repair_status, o.status) as current_job_status,
                   b.bay_name,
                   (SELECT {$svcConcat} FROM order_items oi WHERE oi.order_id = o.id) as service_names
            FROM orders o
            LEFT JOIN bays b ON o.bay_id = b.id
            WHERE (o.bay_id = 4 OR b.bay_type = 'oil_change' OR o.repair_status IN ('waiting_tech', 'working'))
              AND o.status NOT IN ('cancelled', 'finished')
              AND COALESCE(o.repair_status, '') != 'finished'
            GROUP BY o.id
            ORDER BY o.id DESC
            LIMIT 50
        ");
        $jobs = $jobsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Attach tasks, checklists, and parts requests to each job
    $activeJobDetails = [];
    foreach ($jobs as $job) {
        $jId = (int)$job['id'];
        if ($stfUser) {
            TechnicalManager::ensureJobOrder($pdo, $jId, $stfUser['id'], $stfUser['full_name']);
        }

        // Tasks for technician screen (only mechanical/inspection tasks; wash and QC belong to washer/supervisor)
        $tStmt = $pdo->prepare("
            SELECT * FROM order_tasks 
            WHERE order_id = ? AND task_type IN ('oil_change', 'repair', 'inspection') 
            ORDER BY sequence ASC, id ASC
        ");
        $tStmt->execute([$jId]);
        $tasks = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        // Checklist
        $cStmt = $pdo->prepare("SELECT * FROM inspection_checklists WHERE order_id = ? ORDER BY id ASC");
        $cStmt->execute([$jId]);
        $checklists = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // Parts requests
        $pStmt = $pdo->prepare("SELECT * FROM parts_requests WHERE order_id = ? ORDER BY id DESC");
        $pStmt->execute([$jId]);
        $partsRequests = $pStmt->fetchAll(PDO::FETCH_ASSOC);

        // Compute badge
        $hasWaitingParts = false;
        $hasPartsReceived = false;
        foreach ($partsRequests as $pr) {
            if ($pr['status'] === 'pending') $hasWaitingParts = true;
            if ($pr['status'] === 'approved') $hasPartsReceived = true;
        }

        $badge = 'Job in Progress';
        if ($hasWaitingParts) {
            $badge = 'Waiting for Parts';
        } elseif ($hasPartsReceived) {
            $badge = 'Parts Received';
        }

        $activeJobDetails[] = [
            'order' => $job,
            'tasks' => $tasks,
            'checklists' => $checklists,
            'parts_requests' => $partsRequests,
            'status_badge' => $badge
        ];
    }

    // Active products for parts request modal
    $prodStmt = $pdo->query("SELECT id, name_en, name_km, category, price_usd, stock_qty FROM products WHERE is_active = 1 AND category IN ('spare_part', 'engine_oil', 'oil_filter', 'parts') ORDER BY name_en ASC");
    $availParts = $prodStmt->fetchAll(PDO::FETCH_ASSOC);

    // Today's commission for mechanic
    $todayEarn = 0.00;
    if ($stfUser) {
        $earnStmt = $pdo->prepare("SELECT COALESCE(SUM(commission_usd), 0) FROM order_mechanics om JOIN orders o ON om.order_id = o.id WHERE om.mechanic_id = ? AND DATE(o.created_at) = CURDATE()");
        $earnStmt->execute([$stfUser['id']]);
        $todayEarn = (float)$earnStmt->fetchColumn();
    }

    // Completed jobs today for this mechanic
    $completedJobs = [];
    if ($stfUser) {
        $cStmt = $pdo->prepare("
            SELECT o.*, COALESCE(om.commission_usd, 0.00) as commission_usd,
                   (SELECT {$svcConcat} FROM order_items oi WHERE oi.order_id = o.id) as service_names
            FROM orders o
            JOIN order_mechanics om ON o.id = om.order_id
            WHERE om.mechanic_id = ? 
              AND DATE(o.created_at) = CURDATE()
              AND (o.status IN ('finished', 'paid') OR o.repair_status = 'finished')
            GROUP BY o.id
            ORDER BY o.id DESC
            LIMIT 20
        ");
        $cStmt->execute([$stfUser['id']]);
        $completedJobs = $cStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'bays' => $allBays,
        'queue' => $techQueue,
        'waiting_handover' => $waitingHandover,
        'active_jobs' => $activeJobDetails,
        'completed_jobs' => $completedJobs,
        'available_parts' => $availParts,
        'today_commission' => $todayEarn,
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 33. ACTION: receive_from_wash
// ========================================================
if ($action === 'receive_from_wash') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $bayId = (int)($inputData['bay_id'] ?? $_POST['bay_id'] ?? 4); // Bay 4 is Technical/Oil Bay

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'ORDER_ID_REQUIRED', 'message' => 'order_id is required']);
        exit;
    }

    $res = BayManager::receiveFromWash($pdo, $orderId, $bayId, $stfUser['id'], $stfUser['full_name'], $stfUser['effective_role']);
    if (!$res['success']) {
        http_response_code(400);
    } else {
        TechnicalManager::ensureJobOrder($pdo, $orderId, $stfUser['id'], $stfUser['full_name']);
        
        // Also ensure this mechanic is assigned in order_mechanics
        $chkOm = $pdo->prepare("SELECT COUNT(*) FROM order_mechanics WHERE order_id = ? AND mechanic_id = ?");
        $chkOm->execute([$orderId, $stfUser['id']]);
        if (((int)$chkOm->fetchColumn()) === 0) {
            $pdo->prepare("INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd) VALUES (?, ?, 0.00)")
                ->execute([$orderId, $stfUser['id']]);
        }

        NotificationManager::notify($pdo, [
            'target_role' => 'cashier',
            'category' => 'technical_started',
            'reference_id' => 'TECH-START-' . $orderId,
            'title_en' => 'Technical Job Started',
            'title_km' => 'ការងារបច្ចេកទេសបានចាប់ផ្តើម',
            'message_en' => "Technician {$stfUser['full_name']} received car into Bay.",
            'message_km' => "ជាងបច្ចេកទេស {$stfUser['full_name']} បានទទួលរថយន្តចូលកន្លែងធ្វើការ។",
            'sound_type' => 'chime'
        ]);
    }
    echo json_encode($res);
    exit;
}

// ========================================================
// 34. ACTION: start_tech_task
// ========================================================
if ($action === 'start_tech_task') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $taskId = (int)($inputData['task_id'] ?? $_POST['task_id'] ?? 0);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid task_id required']);
        exit;
    }

    $managerPin = trim((string)($inputData['manager_pin'] ?? $_POST['manager_pin'] ?? ''));
    $overrideReason = trim((string)($inputData['reason'] ?? $_POST['reason'] ?? ($inputData['override_reason'] ?? $_POST['override_reason'] ?? '')));
    $managerUsername = trim((string)($inputData['manager_username'] ?? $_POST['manager_username'] ?? ''));

    try {
        $res = TechnicalManager::startTask(
            $pdo,
            $taskId,
            $stfUser['id'],
            $stfUser['full_name'],
            $managerPin !== '' ? $managerPin : null,
            $overrideReason !== '' ? $overrideReason : null,
            $managerUsername !== '' ? $managerUsername : null
        );
        echo json_encode($res);
    } catch (ReceiptRequiredException $re) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'RECEIPT_REQUIRED', 'message' => $re->getMessage()]);
    } catch (Throwable $e) {
        $isReceiptReq = (strpos($e->getMessage(), 'RECEIPT_REQUIRED') !== false);
        http_response_code($isReceiptReq ? 422 : 400);
        echo json_encode(['success' => false, 'error' => $isReceiptReq ? 'RECEIPT_REQUIRED' : $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 35. ACTION: finish_tech_task
// ========================================================
if ($action === 'finish_tech_task') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $taskId = (int)($inputData['task_id'] ?? $_POST['task_id'] ?? 0);
    $actualOil = isset($inputData['actual_oil_used']) ? (float)$inputData['actual_oil_used'] : (isset($_POST['actual_oil_used']) ? (float)$_POST['actual_oil_used'] : null);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }
    if ($taskId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid task_id required']);
        exit;
    }

    try {
        $res = TechnicalManager::finishTask($pdo, $taskId, $stfUser['id'], $stfUser['full_name'], $actualOil);
        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 36. ACTION: update_checklist_item
// ========================================================
if ($action === 'update_checklist_item') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $itemId = (int)($inputData['item_id'] ?? $_POST['item_id'] ?? 0);
    $chkStatus = trim($inputData['status'] ?? $_POST['status'] ?? 'pass');
    $measVal = trim($inputData['measurement_value'] ?? $_POST['measurement_value'] ?? '');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');
    $photoUrl = trim($inputData['photo_url'] ?? $_POST['photo_url'] ?? '');

    // Handle base64 photo
    $photoBase64 = $inputData['photo_base64'] ?? $_POST['photo_base64'] ?? null;
    if (!empty($photoBase64)) {
        $photoFile = getSlipFileFromInput($photoBase64);
        if ($photoFile) {
            $upRes = ImageStorage::processUpload($photoFile, 'inspections', 'insp');
            if ($upRes['success']) {
                $photoUrl = $upRes['image_path'];
            }
        }
    }

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }
    if ($itemId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid item_id required']);
        exit;
    }

    try {
        $res = TechnicalManager::updateChecklistItem(
            $pdo, $itemId, $chkStatus, $measVal, $photoUrl ?: null, $notes, $stfUser['id'], $stfUser['full_name']
        );
        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 36b. ACTION: pass_all_inspection
// ========================================================
if ($action === 'pass_all_inspection') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        $staffId = (int)($sessionUser['id'] ?? 0);
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    if (!$stfUser) {
        $stfUser = $sessionUser;
    }
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid order_id required']);
        exit;
    }

    try {
        $res = TechnicalManager::passAllChecklistItems(
            $pdo, $orderId, $stfUser['id'] ?? null, $stfUser['full_name'] ?? 'Staff'
        );
        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
// ========================================================
if ($action === 'request_parts') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $productId = (int)($inputData['product_id'] ?? $_POST['product_id'] ?? 0);
    $qty = (int)($inputData['qty'] ?? $_POST['qty'] ?? 1);
    $taskId = !empty($inputData['task_id']) ? (int)$inputData['task_id'] : (!empty($_POST['task_id']) ? (int)$_POST['task_id'] : null);
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }
    if ($orderId <= 0 || $productId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid order_id and product_id required']);
        exit;
    }

    try {
        $res = TechnicalManager::requestParts($pdo, $orderId, $productId, $qty, $stfUser['id'], $stfUser['full_name'], $taskId, $notes);

        // Notify warehouse & manager
        NotificationManager::notify($pdo, [
            'target_role' => 'stock_control',
            'category' => 'parts_request',
            'reference_id' => 'REQ-' . $res['request_uid'],
            'title_en' => 'New Parts Request',
            'title_km' => 'សំណើសុំគ្រឿងបន្លាស់ថ្មី',
            'message_en' => "Technician {$stfUser['full_name']} requested {$qty}x '{$res['product_name']}'.",
            'message_km' => "ជាងបច្ចេកទេស {$stfUser['full_name']} បានស្នើសុំ {$qty}x '{$res['product_name']}'។",
            'sound_type' => 'chime'
        ]);

        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 38. ACTION: finish_tech_job
// ========================================================
if ($action === 'finish_tech_job') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $orderId = (int)($inputData['order_id'] ?? $_POST['order_id'] ?? 0);
    $tookLonger = isset($inputData['took_longer']) ? (int)$inputData['took_longer'] : (isset($_POST['took_longer']) ? (int)$_POST['took_longer'] : null);

    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PARAMS_REQUIRED', 'message' => 'Valid order_id required']);
        exit;
    }

    try {
        $res = TechnicalManager::finishJobOrder($pdo, $orderId, $stfUser['id'], $stfUser['full_name'], 'technical', $tookLonger);
        echo json_encode($res);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ========================================================
// 39. ACTION: get_notifications
// ========================================================
if ($action === 'get_notifications') {
    $staffId = (int)($inputData['staff_id'] ?? $_GET['staff_id'] ?? $inputData['user_id'] ?? $_GET['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $userId = $stfUser ? (int)$stfUser['id'] : 0;
    $userRole = $stfUser ? (string)$stfUser['effective_role'] : 'all';

    NotificationManager::syncSystemEvents($pdo);
    $unreadCount = NotificationManager::getUnreadCount($pdo, $userId, $userRole);
    $notifications = NotificationManager::getNotifications($pdo, $userId, $userRole, 25);
    $isMuted = NotificationManager::isSoundMuted($pdo, $userId);

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
        'sound_muted' => $isMuted,
        'system_revision' => compute_system_revision($pdo, $userId),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// ACTION: check_system_updates (Requirement 1, 2)
// ========================================================
if ($action === 'check_system_updates') {
    $staffId = (int)($inputData['staff_id'] ?? $_GET['staff_id'] ?? $inputData['user_id'] ?? $_GET['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $userId = $stfUser ? (int)$stfUser['id'] : 0;

    echo json_encode([
        'success' => true,
        'system_revision' => compute_system_revision($pdo, $userId),
        'synced_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 40. ACTION: mark_notification_read
// ========================================================
if ($action === 'mark_notification_read') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    $notifId = (int)($inputData['notification_id'] ?? $_POST['notification_id'] ?? 0);

    if ($notifId > 0 && $stfUser) {
        NotificationManager::markAsRead($pdo, $notifId, (int)$stfUser['id']);
    }

    $unreadCount = $stfUser ? NotificationManager::getUnreadCount($pdo, (int)$stfUser['id'], (string)$stfUser['effective_role']) : 0;

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount
    ]);
    exit;
}

// ========================================================
// 41. ACTION: mark_all_notifications_read
// ========================================================
if ($action === 'mark_all_notifications_read') {
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);

    if ($stfUser) {
        NotificationManager::markAllAsRead($pdo, (int)$stfUser['id'], (string)$stfUser['effective_role']);
    }

    echo json_encode([
        'success' => true,
        'unread_count' => 0
    ]);
    exit;
}

// ========================================================
// 42. ACTION: get_wait_times (Requirement 1 & 2)
// ========================================================
if ($action === 'get_wait_times') {
    $vehicleType = trim($inputData['vehicle_type'] ?? $_GET['vehicle_type'] ?? 'small_car');
    $cartServiceIds = $inputData['cart_service_ids'] ?? [];
    if (!is_array($cartServiceIds) && !empty($cartServiceIds)) {
        $cartServiceIds = array_filter(array_map('intval', explode(',', (string)$cartServiceIds)));
    }

    // 1. Calculate per-service wait times for catalog cards
    $activeServices = $pdo->query("SELECT id, name_en, base_duration_minutes FROM services WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    $servicesWait = [];

    // General queue wait
    $baseQueueEstimate = SmartQueueEngine::calculateEstimatedWaitTime($pdo, 0, $vehicleType);
    $baseWaitMinutes = (int)$baseQueueEstimate['estimated_wait_minutes'];

    foreach ($activeServices as $svc) {
        $svcId = (int)$svc['id'];
        $svcWait = SmartQueueEngine::calculateEstimatedWaitTime($pdo, $svcId, $vehicleType);
        $mins = max(5, (int)$svcWait['estimated_wait_minutes']);
        $servicesWait[$svcId] = [
            'service_id' => $svcId,
            'wait_minutes' => $mins,
            'wait_text_en' => "Wait about {$mins} min",
            'wait_text_km' => "រង់ចាំប្រហែល {$mins} នាទី",
            'is_over_45' => ($mins > 45)
        ];
    }

    // 2. Calculate cart wait time if cart has services
    $cartWaitData = null;
    if (!empty($cartServiceIds)) {
        $firstSvcId = (int)reset($cartServiceIds);
        $cartEstimate = SmartQueueEngine::calculateEstimatedWaitTime($pdo, $firstSvcId, $vehicleType);
        $extraMinutes = 0;
        if (count($cartServiceIds) > 1) {
            $otherIds = array_slice($cartServiceIds, 1);
            $mult = SmartQueueEngine::getVehicleMultiplier($pdo, $vehicleType);
            $inClause = implode(',', array_map('intval', $otherIds));
            $otherDur = (int)$pdo->query("SELECT SUM(base_duration_minutes) FROM services WHERE id IN ($inClause)")->fetchColumn();
            $extraMinutes = (int)round($otherDur * $mult);
        }
        $cartMins = max(5, (int)$cartEstimate['estimated_wait_minutes'] + $extraMinutes);
        $cartWaitData = [
            'wait_minutes' => $cartMins,
            'wait_text_en' => "Wait about {$cartMins} min",
            'wait_text_km' => "រង់ចាំប្រហែល {$cartMins} នាទី",
            'is_over_45' => ($cartMins > 45),
            'waiting_cars_count' => (int)$cartEstimate['waiting_cars_count'],
            'active_bays_count' => (int)$cartEstimate['active_bays_count']
        ];
    } else {
        $cartWaitData = [
            'wait_minutes' => $baseWaitMinutes,
            'wait_text_en' => "Wait about {$baseWaitMinutes} min",
            'wait_text_km' => "រង់ចាំប្រហែល {$baseWaitMinutes} នាទី",
            'is_over_45' => ($baseWaitMinutes > 45),
            'waiting_cars_count' => (int)$baseQueueEstimate['waiting_cars_count'],
            'active_bays_count' => (int)$baseQueueEstimate['active_bays_count']
        ];
    }

    echo json_encode([
        'success' => true,
        'services_wait' => $servicesWait,
        'cart_wait' => $cartWaitData,
        'calculated_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ========================================================
// 43. ACTION: get_appointment_slots (Requirement 3)
// ========================================================
if ($action === 'get_appointment_slots') {
    $date = trim($inputData['date'] ?? $_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $serviceId = (int)($inputData['service_id'] ?? $_GET['service_id'] ?? 0);
    $dur = SmartQueueEngine::getServiceBaseDuration($pdo, $serviceId);

    $slots = SmartQueueEngine::getFreeAppointmentSlots($pdo, $date, $dur);

    echo json_encode([
        'success' => true,
        'date' => $date,
        'service_id' => $serviceId,
        'service_duration' => $dur,
        'slots' => $slots
    ]);
    exit;
}

// ========================================================
// 44. ACTION: get_appointments (Requirement 3)
// ========================================================
if ($action === 'get_appointments') {
    $date = trim($inputData['date'] ?? $_GET['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $stmt = $pdo->prepare("
        SELECT a.*, 
               COALESCE(s.name_en, 'Service') as service_name_en,
               COALESCE(s.name_km, 'សេវាកម្ម') as service_name_km,
               COALESCE((SELECT sp.price_usd FROM service_prices sp WHERE sp.service_id = s.id AND sp.vehicle_type = COALESCE(a.vehicle_type, 'small_car') LIMIT 1), 3.50) as service_price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.appointment_date = ?
        ORDER BY a.appointment_time ASC, a.id ASC
    ");
    $stmt->execute([$date]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'date' => $date,
        'count' => count($appointments),
        'appointments' => $appointments
    ]);
    exit;
}

// ========================================================
// 45. ACTION: create_appointment (Requirement 3)
// ========================================================
if ($action === 'create_appointment') {
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? $_POST['plate_number'] ?? ''));
    $serviceId = (int)($inputData['service_id'] ?? $_POST['service_id'] ?? 1);
    $date = trim($inputData['appointment_date'] ?? $_POST['appointment_date'] ?? date('Y-m-d'));
    $time = trim($inputData['appointment_time'] ?? $_POST['appointment_time'] ?? '09:00');
    $customerName = trim($inputData['customer_name'] ?? $_POST['customer_name'] ?? '');
    $customerPhone = trim($inputData['customer_phone'] ?? $_POST['customer_phone'] ?? '');
    $vehicleType = trim($inputData['vehicle_type'] ?? $_POST['vehicle_type'] ?? 'small_car');
    $notes = trim($inputData['notes'] ?? $_POST['notes'] ?? '');

    if (empty($plateNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PLATE_REQUIRED', 'message' => 'Vehicle plate number is required']);
        exit;
    }

    if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    if (empty($customerName)) {
        $cFind = $pdo->prepare("SELECT c.name, c.phone FROM vehicles v JOIN customers c ON v.customer_id = c.id WHERE v.plate_number = ? LIMIT 1");
        $cFind->execute([$plateNumber]);
        $cRow = $cFind->fetch(PDO::FETCH_ASSOC);
        if ($cRow) {
            $customerName = $cRow['name'];
            if (empty($customerPhone)) $customerPhone = (string)$cRow['phone'];
        } else {
            $customerName = 'Appointment Customer';
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO appointments (
            customer_name, customer_phone, plate_number, vehicle_type,
            service_id, appointment_date, appointment_time, status, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        $customerName,
        $customerPhone,
        $plateNumber,
        $vehicleType,
        $serviceId,
        $date,
        $time,
        $notes
    ]);
    $appointmentId = (int)$pdo->lastInsertId();

    NotificationManager::ensureTables($pdo);
    NotificationManager::notify($pdo, [
        'target_role' => 'wash_supervisor',
        'category' => 'appointment_booked',
        'reference_id' => 'APPT-' . $appointmentId,
        'title_en' => 'New Appointment Booked',
        'title_km' => 'មានការកក់ការណាត់ជួបថ្មី',
        'message_en' => "Appointment booked for {$plateNumber} on {$date} at {$time}",
        'message_km' => "ការណាត់ជួបសម្រាប់ {$plateNumber} នៅថ្ងៃ {$date} ម៉ោង {$time}",
        'action_url' => 'index.php?view=appointments',
        'sound_type' => 'chime'
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Appointment booked successfully',
        'appointment_id' => $appointmentId,
        'appointment' => [
            'id' => $appointmentId,
            'plate_number' => $plateNumber,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'appointment_date' => $date,
            'appointment_time' => $time,
            'service_id' => $serviceId,
            'status' => 'confirmed'
        ]
    ]);
    exit;
}

// ========================================================
// 46. ACTION: appointment_arrived (Requirement 3)
// ========================================================
if ($action === 'appointment_arrived') {
    $appointmentId = (int)($inputData['appointment_id'] ?? $_POST['appointment_id'] ?? 0);
    $staffId = (int)($inputData['staff_id'] ?? $_POST['staff_id'] ?? $inputData['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($staffId <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_REQUIRED', 'message' => 'Staff ID is required for appointment arrival.']);
        exit;
    }
    $stfUser = BayManager::getRealStaffUser($pdo, $staffId);
    if (!$stfUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'STAFF_NOT_FOUND', 'message' => 'Unauthorized: Invalid or inactive staff user']);
        exit;
    }

    if ($appointmentId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'INVALID_ID', 'message' => 'Valid appointment ID required']);
        exit;
    }

    $appStmt = $pdo->prepare("
        SELECT a.*, s.name_en as service_name,
               COALESCE((SELECT sp.price_usd FROM service_prices sp WHERE sp.service_id = s.id AND sp.vehicle_type = COALESCE(a.vehicle_type, 'small_car') LIMIT 1), 3.50) as service_price
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.id = ?
    ");
    $appStmt->execute([$appointmentId]);
    $appt = $appStmt->fetch(PDO::FETCH_ASSOC);

    if (!$appt) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'NOT_FOUND', 'message' => 'Appointment not found']);
        exit;
    }

    $pdo->prepare("UPDATE appointments SET status = 'arrived' WHERE id = ?")->execute([$appointmentId]);

    $plate = strtoupper(trim($appt['plate_number']));
    $ordStmt = $pdo->prepare("SELECT id FROM orders WHERE plate_number = ? AND DATE(created_at) = CURRENT_DATE() AND status IN ('waiting', 'washing') LIMIT 1");
    $ordStmt->execute([$plate]);
    $existingOrderId = $ordStmt->fetchColumn();

    $orderId = null;
    $apptFullTime = $appt['appointment_date'] . ' ' . $appt['appointment_time'];
    if ($existingOrderId) {
        $orderId = (int)$existingOrderId;
        $pdo->prepare("UPDATE orders SET appointment_time = ? WHERE id = ?")->execute([$apptFullTime, $orderId]);
    } else {
        $svcId = (int)($appt['service_id'] ?: 1);
        $svcRow = $pdo->query("SELECT id, name_en, category FROM services WHERE id = {$svcId}")->fetch(PDO::FETCH_ASSOC);
        $price = (float)$appt['service_price'] ?: 3.50;
        $orderUid = generate_unique_id('ORD');
        $orderNum = 'ORD-' . date('ymd') . '-' . sprintf('%03d', rand(100, 999));

        $insOrder = $pdo->prepare("
            INSERT INTO orders (
                order_number, order_uid, plate_number, customer_name, customer_phone,
                vehicle_type, subtotal_usd, total_usd, total_khr, status, payment_status,
                payment_method, appointment_time, arrived_at, source, created_at,
                paid_at, receipt_issued_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', 'paid', 'cash', ?, CURRENT_TIMESTAMP, 'pos_app', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $khr = (int)round($price * get_exchange_rate());
        $insOrder->execute([
            $orderNum, $orderUid, $plate, $appt['customer_name'], $appt['customer_phone'],
            $appt['vehicle_type'] ?: 'small_car', $price, $price, $khr, $apptFullTime
        ]);
        $orderId = (int)$pdo->lastInsertId();

        $serviceSequence = in_array(($inputData['service_sequence'] ?? $_POST['service_sequence'] ?? 'lift_first'), ['lift_first', 'wash_first'], true)
            ? ($inputData['service_sequence'] ?? $_POST['service_sequence'] ?? 'lift_first')
            : 'lift_first';
        if ($serviceSequence === 'wash_first') {
            $pdo->prepare("UPDATE orders SET service_sequence = 'wash_first', repair_status = 'waiting_wash_first' WHERE id = ?")->execute([$orderId]);
        }
        BayManager::ensureOrderTasks($pdo, $orderId);

        $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd, price_khr, item_type, qty, created_at) VALUES (?, ?, ?, ?, ?, 'service', 1, CURRENT_TIMESTAMP)")
            ->execute([$orderId, $svcId, $svcRow['name_en'] ?? 'Car Wash', $price, $khr]);

        BayManager::ensureOrderTasks($pdo, $orderId);
    }

    $arrivalResult = BayManager::markCustomerArrived($pdo, $orderId, (int)$stfUser['id'], (string)$stfUser['full_name'], (string)$stfUser['effective_role']);

    echo json_encode([
        'success' => true,
        'message' => "Appointment marked arrived for {$plate}. Vehicle has entered the queue.",
        'order_id' => $orderId,
        'appointment_id' => $appointmentId,
        'arrival_result' => $arrivalResult
    ]);
    exit;
}

// ========================================================
// 47. ACTION: recognize_vehicle (Requirement 4)
// ========================================================
if ($action === 'recognize_vehicle') {
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? $_GET['plate_number'] ?? $_POST['plate_number'] ?? ''));

    if (empty($plateNumber)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'PLATE_REQUIRED', 'message' => 'Plate number is required']);
        exit;
    }

    $vStmt = $pdo->prepare("
        SELECT v.*, c.id as cust_id, c.name as cust_name, c.phone as cust_phone,
               c.notes as cust_notes, c.loyalty_points, c.lifetime_points, c.membership_level
        FROM vehicles v
        LEFT JOIN customers c ON v.customer_id = c.id
        WHERE v.plate_number = ?
        LIMIT 1
    ");
    $vStmt->execute([$plateNumber]);
    $veh = $vStmt->fetch(PDO::FETCH_ASSOC);

    $customerName = $veh['cust_name'] ?? '';
    $customerPhone = $veh['cust_phone'] ?? '';
    $vehicleType = $veh['vehicle_type'] ?? 'small_car';
    $lifetimePoints = (int)($veh['lifetime_points'] ?? $veh['loyalty_points'] ?? 0);
    $customerNotes = $veh['cust_notes'] ?? '';

    if (!$veh) {
        $ordStmt = $pdo->prepare("
            SELECT customer_name, customer_phone, vehicle_type, notes
            FROM orders
            WHERE plate_number = ?
            ORDER BY id DESC LIMIT 1
        ");
        $ordStmt->execute([$plateNumber]);
        $lastOrder = $ordStmt->fetch(PDO::FETCH_ASSOC);
        if ($lastOrder) {
            $customerName = $lastOrder['customer_name'] ?? 'Walk-in Guest';
            $customerPhone = $lastOrder['customer_phone'] ?? '';
            $vehicleType = $lastOrder['vehicle_type'] ?? 'small_car';
            $customerNotes = $lastOrder['notes'] ?? '';
        }
    }

    if (empty($customerName)) {
        $customerName = 'Walk-in Guest';
    }

    $washCountStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE plate_number = ? AND status IN ('finished', 'paid')");
    $washCountStmt->execute([$plateNumber]);
    $washCount = (int)$washCountStmt->fetchColumn();

    $oilStmt = $pdo->prepare("
        SELECT oil_product_name, current_mileage, next_due_mileage, oil_change_date, next_due_date
        FROM oil_change_logs
        WHERE plate_number = ?
        ORDER BY id DESC LIMIT 1
    ");
    $oilStmt->execute([$plateNumber]);
    $oilLog = $oilStmt->fetch(PDO::FETCH_ASSOC);

    $lastOilDate = $oilLog['oil_change_date'] ?? null;
    $lastOilMileage = $oilLog['current_mileage'] ?? null;
    $nextOilMileage = $oilLog['next_due_mileage'] ?? null;
    $nextOilDate = $oilLog['next_due_date'] ?? null;

    if (!$oilLog) {
        $ordOil = $pdo->prepare("
            SELECT actual_oil_used, current_mileage, next_oil_mileage, next_oil_date, finished_at, paid_at, created_at
            FROM orders
            WHERE plate_number = ? AND (actual_oil_used IS NOT NULL OR current_mileage > 0)
            ORDER BY id DESC LIMIT 1
        ");
        $ordOil->execute([$plateNumber]);
        $ordOilRow = $ordOil->fetch(PDO::FETCH_ASSOC);
        if ($ordOilRow) {
            $lastOilDate = substr($ordOilRow['finished_at'] ?? $ordOilRow['paid_at'] ?? $ordOilRow['created_at'], 0, 10);
            $lastOilMileage = (int)($ordOilRow['current_mileage'] ?? 0) ?: null;
            $nextOilMileage = (int)($ordOilRow['next_oil_mileage'] ?? 0) ?: null;
            $nextOilDate = $ordOilRow['next_oil_date'] ?? null;
        }
    }

    $customerId = (int)($veh['cust_id'] ?? 0);
    if (!$customerId) {
        $cLookup = $pdo->prepare("SELECT customer_id FROM orders WHERE plate_number = ? AND customer_id IS NOT NULL ORDER BY id DESC LIMIT 1");
        $cLookup->execute([$plateNumber]);
        $customerId = (int)($cLookup->fetchColumn() ?: 0);
    }

    $currentPoints = (int)($veh['loyalty_points'] ?? 0);
    $firstDayOfMonth = date('Y-m-01 00:00:00');
    $vMonStmt = $pdo->prepare("
        SELECT COUNT(*) FROM orders 
        WHERE (customer_id = ? OR (customer_id IS NULL AND plate_number = ?))
          AND created_at >= ?
          AND status != 'cancelled'
    ");
    $vMonStmt->execute([$customerId ?: 0, $plateNumber, $firstDayOfMonth]);
    $visitsThisMonth = (int)$vMonStmt->fetchColumn();

    $rawTier = strtolower(trim($veh['membership_level'] ?? ''));
    $tier = $rawTier !== '' ? ucfirst($rawTier) : Loyalty::determineTier($lifetimePoints);
    $isMember = in_array(strtolower($tier), ['gold', 'platinum'], true);

    $tierConfig = Loyalty::getTiersConfig()[$tier] ?? [
        'name' => 'Standard',
        'name_km' => 'កម្រិតទូទៅ',
        'badge_color' => '#64748b'
    ];

    // Authoritative Member Benefits Calculation (Requirement 1: included vs used up)
    $benefits = [];
    if (strtolower($tier) === 'gold') {
        $svcId = 1; // Exterior Wash
        $allowed = 1;
        $uStmt = $pdo->prepare("
            SELECT COUNT(oi.id) FROM order_items oi
            JOIN orders o ON oi.order_id = o.id
            WHERE (o.customer_id = ? OR o.plate_number = ?)
              AND o.created_at >= ?
              AND o.status != 'cancelled'
              AND oi.service_id = ?
              AND (oi.price_usd = 0 OR oi.service_name LIKE '%Included%')
        ");
        $uStmt->execute([$customerId ?: 0, $plateNumber, $firstDayOfMonth, $svcId]);
        $used = (int)$uStmt->fetchColumn();
        $remaining = max(0, $allowed - $used);
        $isUsedUp = ($remaining <= 0);

        $benefits[] = [
            'service_id' => $svcId,
            'service_name' => 'Exterior Wash',
            'service_name_km' => 'លាងសម្អាតខាងក្រៅ',
            'allowed' => $allowed,
            'used' => $used,
            'remaining' => $remaining,
            'is_used_up' => $isUsedUp,
            'status_text' => "{$used}/{$allowed} used" . ($isUsedUp ? " (Used up)" : " (Included)"),
            'status_text_km' => "បានប្រើ {$used}/{$allowed}" . ($isUsedUp ? " (អស់កូតា)" : " (ឥតគិតថ្លៃ)"),
            'auto_free' => !$isUsedUp
        ];
    } elseif (strtolower($tier) === 'platinum') {
        $defList = [
            ['id' => 1, 'name' => 'Exterior Wash', 'name_km' => 'លាងសម្អាតខាងក្រៅ', 'allowed' => 2],
            ['id' => 2, 'name' => 'Interior Cleaning', 'name_km' => 'បោសសម្អាតខាងក្នុង', 'allowed' => 1]
        ];
        foreach ($defList as $def) {
            $uStmt = $pdo->prepare("
                SELECT COUNT(oi.id) FROM order_items oi
                JOIN orders o ON oi.order_id = o.id
                WHERE (o.customer_id = ? OR o.plate_number = ?)
                  AND o.created_at >= ?
                  AND o.status != 'cancelled'
                  AND oi.service_id = ?
                  AND (oi.price_usd = 0 OR oi.service_name LIKE '%Included%')
            ");
            $uStmt->execute([$customerId ?: 0, $plateNumber, $firstDayOfMonth, $def['id']]);
            $used = (int)$uStmt->fetchColumn();
            $remaining = max(0, $def['allowed'] - $used);
            $isUsedUp = ($remaining <= 0);

            $benefits[] = [
                'service_id' => $def['id'],
                'service_name' => $def['name'],
                'service_name_km' => $def['name_km'],
                'allowed' => $def['allowed'],
                'used' => $used,
                'remaining' => $remaining,
                'is_used_up' => $isUsedUp,
                'status_text' => "{$used}/{$def['allowed']} used" . ($isUsedUp ? " (Used up)" : " (Included)"),
                'status_text_km' => "បានប្រើ {$used}/{$def['allowed']}" . ($isUsedUp ? " (អស់កូតា)" : " (ឥតគិតថ្លៃ)"),
                'auto_free' => !$isUsedUp
            ];
        }
    }

    // Authoritative Loyalty Reward Calculation (Requirement 3: when a reward is earned)
    $loyaltyReward = null;
    $rewardEligible = ($customerId > 0 && ($currentPoints >= 50 || $lifetimePoints >= 50 || $washCount >= 5 || $isMember));
    if ($rewardEligible) {
        $chkGiven = $pdo->prepare("
            SELECT COUNT(*) FROM orders 
            WHERE (customer_id = ? OR plate_number = ?) 
              AND loyalty_reward_given = 1 
              AND DATE(created_at) = CURRENT_DATE()
        ");
        $chkGiven->execute([$customerId ?: 0, $plateNumber]);
        $alreadyGiven = (int)$chkGiven->fetchColumn() > 0;
        if (!$alreadyGiven) {
            $loyaltyReward = [
                'reward_id' => 'loyalty_air_freshener',
                'reward_name' => 'Free air freshener (loyalty reward)',
                'prompt_text' => 'Offer a free air freshener (loyalty reward)',
                'prompt_text_km' => 'ផ្តល់ជូនប្រេងក្រអូបឥតគិតថ្លៃ (រង្វាន់ភក្ដីភាព)',
                'item_type' => 'product',
                'item_name' => 'Car Air Freshener'
            ];
        }
    }

    $flags = [];

    $isVip = (
        stripos($customerName, 'VIP') !== false ||
        stripos($customerNotes, 'VIP') !== false ||
        stripos($plateNumber, '8888') !== false ||
        in_array($tier, ['Platinum', 'Gold'], true)
    );

    if ($isVip) {
        $flags[] = [
            'type' => 'vip',
            'label' => 'VIP - use premium products',
            'label_km' => 'VIP - ប្រើប្រាស់ផលិតផលគុណភាពខ្ពស់',
            'badge_color' => '#f59e0b',
            'icon' => 'fa-crown'
        ];
    }

    if ($tier !== 'Standard') {
        $flags[] = [
            'type' => 'tier',
            'label' => "{$tier} Member",
            'label_km' => "សមាជិក {$tierConfig['name_km']}",
            'badge_color' => $tierConfig['badge_color'] ?? '#6366f1',
            'icon' => 'fa-award'
        ];
    }

    if ($washCount >= 5) {
        $flags[] = [
            'type' => 'frequent',
            'label' => "Loyal ({$washCount} visits)",
            'label_km' => "អតិថិជនប្រចាំ ({$washCount} លើក)",
            'badge_color' => '#3b82f6',
            'icon' => 'fa-car-on'
        ];
    }

    if ($nextOilMileage && $lastOilMileage && ($nextOilMileage - $lastOilMileage <= 500)) {
        $flags[] = [
            'type' => 'oil_due',
            'label' => 'Oil Change Due Soon',
            'label_km' => 'ជិតដល់ពេលប្តូរប្រេងម៉ាស៊ីន',
            'badge_color' => '#ef4444',
            'icon' => 'fa-oil-can'
        ];
    }

    if (!empty($customerNotes) && stripos($customerNotes, 'VIP') === false) {
        $flags[] = [
            'type' => 'note',
            'label' => $customerNotes,
            'label_km' => $customerNotes,
            'badge_color' => '#8b5cf6',
            'icon' => 'fa-note-sticky'
        ];
    }

    echo json_encode([
        'success' => true,
        'profile' => [
            'plate_number' => $plateNumber,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'vehicle_type' => $vehicleType,
            'membership_level' => $tier,
            'membership_name_km' => $tierConfig['name_km'],
            'membership_color' => $tierConfig['badge_color'],
            'lifetime_points' => $lifetimePoints,
            'wash_history_count' => $washCount,
            'visits_this_month' => $visitsThisMonth,
            'is_member' => $isMember,
            'membership' => [
                'tier' => $tier,
                'tier_name_km' => $tierConfig['name_km'],
                'tier_color' => $tierConfig['badge_color'],
                'is_member' => $isMember,
                'visits_this_month' => $visitsThisMonth,
                'benefits' => $benefits
            ],
            'loyalty_reward' => $loyaltyReward,
            'last_oil_change_date' => $lastOilDate,
            'last_oil_change_mileage' => $lastOilMileage,
            'next_oil_mileage' => $nextOilMileage,
            'next_oil_date' => $nextOilDate,
            'flags' => $flags
        ]
    ]);
    exit;
}

// ========================================================
// 48. ACTION: evaluate_cart_upsell (Requirement 2)
// Authoritative back-office upsell rule engine
// ========================================================
if ($action === 'evaluate_cart_upsell') {
    $cart = $inputData['cart'] ?? [];
    if (empty($cart) && isset($_POST['cart'])) {
        $cart = json_decode($_POST['cart'], true) ?: [];
    }
    $customerId = !empty($inputData['customer_id']) ? (int)$inputData['customer_id'] : 0;
    $plateNumber = strtoupper(trim($inputData['plate_number'] ?? ''));
    $vehicleType = trim($inputData['vehicle_type'] ?? 'small_car');

    $serviceIds = [];
    $productIds = [];
    $totalCartUsd = 0.0;
    foreach ($cart as $item) {
        $type = $item['type'] ?? 'service';
        $sId = !empty($item['service_id']) ? (int)$item['service_id'] : (!empty($item['id']) && in_array($type, ['service', 'repair'], true) ? (int)$item['id'] : null);
        $pId = !empty($item['product_id']) ? (int)$item['product_id'] : (!empty($item['id']) && in_array($type, ['product', 'spare_part'], true) ? (int)$item['id'] : null);
        if ($sId) $serviceIds[] = $sId;
        if ($pId) $productIds[] = $pId;
        $totalCartUsd += (float)($item['price_usd'] ?? 0) * max(1, (int)($item['qty'] ?? 1));
    }

    $matchedOffer = null;

    // Rule 1: Cart has "Exterior Wash" (ID 1) only, without Full Wash (ID 3) or Hydrophobic Wax (ID 5)
    // Offer: "Upgrade to Premium Wash for $5?"
    if (in_array(1, $serviceIds, true) && !in_array(3, $serviceIds, true) && !in_array(5, $serviceIds, true)) {
        $matchedOffer = [
            'id' => 'upsell_premium_wash',
            'title' => 'Upgrade Offer',
            'title_km' => 'ការផ្តល់ជូនដំឡើងកញ្ចប់',
            'message' => 'Upgrade to Premium Wash for $5?',
            'message_km' => 'តើលោកអ្នកចង់ដំឡើងទៅ Premium Wash ត្រឹមតែ $5 ដែរឬទេ?',
            'action_type' => 'upgrade_service',
            'replace_service_id' => 1,
            'target_service_id' => 5,
            'target_name' => 'Hydrophobic Wax & Polish',
            'target_name_km' => 'បាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ',
            'offer_price_usd' => 5.00,
            'offer_price_khr' => 20500,
            'accept_label' => 'Accept',
            'accept_label_km' => 'យល់ព្រម',
            'decline_label' => 'Not now',
            'decline_label_km' => 'ទុកពេលក្រោយ'
        ];
    }
    // Rule 2: Cart has Full Wash (ID 3) without Hydrophobic Wax (ID 5)
    elseif (in_array(3, $serviceIds, true) && !in_array(5, $serviceIds, true)) {
        $matchedOffer = [
            'id' => 'upsell_add_wax',
            'title' => 'Protection Add-on',
            'title_km' => 'បន្ថែមការការពារ Wax',
            'message' => 'Add Hydrophobic Wax & Polish for $5?',
            'message_km' => 'បន្ថែមបាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ ត្រឹមតែ $5 ដែរឬទេ?',
            'action_type' => 'add_service',
            'target_service_id' => 5,
            'target_name' => 'Hydrophobic Wax & Polish',
            'target_name_km' => 'បាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ',
            'offer_price_usd' => 5.00,
            'offer_price_khr' => 20500,
            'accept_label' => 'Accept',
            'accept_label_km' => 'យល់ព្រម',
            'decline_label' => 'Not now',
            'decline_label_km' => 'ទុកពេលក្រោយ'
        ];
    }

    echo json_encode([
        'success' => true,
        'has_offer' => ($matchedOffer !== null),
        'offer' => $matchedOffer
    ]);
    exit;
}

// Unknown action fallback
http_response_code(400);
echo json_encode([
    'success' => false,
    'error' => 'INVALID_ACTION',
    'message' => "Unsupported action '{$action}'. Supported: staff_login, sync_catalog, sync_customers, add_customer_vehicle, create_order, sync_order_status, pay_order, get_queue, get_today_orders, cancel_order, assign_crew, get_daily_report, get_oil_reminders, update_oil_contact, save_pos_closing, reopen_daily_close, get_daily_close_status, save_repair_note"
]);
