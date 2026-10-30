<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/AuditLogger.php';
require_once __DIR__ . '/core/Auth.php';
require_once __DIR__ . '/core/Language.php';
require_once __DIR__ . '/core/Helpers.php';
require_once __DIR__ . '/database/init.php';

Auth::startSession();
Language::init();

$appTimezone = get_setting('app_timezone', 'Asia/Phnom_Penh');
if (!empty($appTimezone)) {
    @date_default_timezone_set($appTimezone);
}

$action = $_GET['action'] ?? null;

// 1. Handle Logout
if ($action === 'logout') {
    Auth::logout();
    header('Location: ' . APP_URL . '/index.php?view=login');
    exit;
}

// 2. Handle Login POST (6-Digit PIN)
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $pin = trim($_POST['pin'] ?? $_POST['password'] ?? '');

    if (Auth::login($username, $pin)) {
        if (Auth::mustChangePin()) {
            header('Location: ' . APP_URL . '/index.php?view=change_pin');
            exit;
        }
        $defaultView = Auth::getDefaultViewForUser();
        $targetUrl = ($defaultView === 'pos' && defined('POS_UI') && POS_UI === 'new')
            ? APP_URL . '/pos/index.html#pos'
            : APP_URL . '/index.php?view=' . $defaultView;
        header('Location: ' . $targetUrl, true, 302);
        exit;
    } else {
        $err = Auth::getLastError() ?? '1';
        $errData = Auth::getLastErrorData();
        $params = ['view' => 'login', 'error' => $err];
        if (!empty($errData['remaining_minutes'])) {
            $params['rem'] = $errData['remaining_minutes'];
        }
        if (isset($errData['remaining_attempts'])) {
            $params['rem_att'] = $errData['remaining_attempts'];
        }
        header('Location: ' . APP_URL . '/index.php?' . http_build_query($params));
        exit;
    }
}

// 3. Handle Demo 1-Click Login (Works only when Test Mode in Settings is ON and from allowed local IPs)
if ($action === 'demo_login') {
    $demoMode = get_setting('demo_mode', '0');
    if ($demoMode !== '1') {
        header('Location: ' . APP_URL . '/index.php?view=login&error=demo_disabled');
        exit;
    }

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    $allowedIps = array_merge(['127.0.0.1', '::1'], defined('DEMO_ALLOWED_IPS') && is_array(DEMO_ALLOWED_IPS) ? DEMO_ALLOWED_IPS : []);
    if (!in_array($clientIp, $allowedIps, true)) {
        header('Location: ' . APP_URL . '/index.php?view=login&error=demo_disabled');
        exit;
    }

    $roleKey = $_GET['role'] ?? 'owner';
    $demoAccountsFile = __DIR__ . '/config/demo_accounts.php';
    $credentials = file_exists($demoAccountsFile) ? require $demoAccountsFile : [];

    if (isset($credentials[$roleKey])) {
        if (Auth::login($credentials[$roleKey]['username'], $credentials[$roleKey]['pin'])) {
            if (Auth::mustChangePin()) {
                header('Location: ' . APP_URL . '/index.php?view=change_pin');
                exit;
            }
            $defaultView = Auth::getDefaultViewForUser();
            $targetUrl = ($defaultView === 'pos' && defined('POS_UI') && POS_UI === 'new')
                ? APP_URL . '/pos/index.html#pos'
                : APP_URL . '/index.php?view=' . $defaultView;
            header('Location: ' . $targetUrl, true, 302);
            exit;
        } else {
            $err = Auth::getLastError() ?? '1';
            header('Location: ' . APP_URL . '/index.php?view=login&error=' . urlencode($err));
            exit;
        }
    }
}

// 4. Check if authenticated
if (!Auth::check()) {
    require_once __DIR__ . '/views/auth/login.php';
    exit;
}

// 4a. Handle forced PIN change when admin/owner reset PIN
if ($action === 'update_pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        header('Location: ' . APP_URL . '/index.php?view=change_pin&error=csrf');
        exit;
    }
    $newPin = trim($_POST['new_pin'] ?? '');
    $confirmPin = trim($_POST['confirm_pin'] ?? '');

    if (strlen($newPin) !== 6 || !ctype_digit($newPin)) {
        header('Location: ' . APP_URL . '/index.php?view=change_pin&error=invalid_length');
        exit;
    }

    if ($newPin !== $confirmPin) {
        header('Location: ' . APP_URL . '/index.php?view=change_pin&error=mismatch');
        exit;
    }

    $pdo = Database::getInstance();
    $pinHash = password_hash($newPin, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_pin = 0, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
        ->execute([$pinHash, Auth::getUserId()]);

    $_SESSION['must_change_pin'] = 0;
    audit_log('PIN_CHANGED', "User changed their PIN following an administrative reset", 'user', (string)Auth::getUserId());

    $defaultView = Auth::getDefaultViewForUser();
    $targetUrl = ($defaultView === 'pos' && defined('POS_UI') && POS_UI === 'new')
        ? APP_URL . '/pos/index.html#pos'
        : APP_URL . '/index.php?view=' . $defaultView . '&msg=pin_changed_success';
    header('Location: ' . $targetUrl, true, 302);
    exit;
}

$view = $_GET['view'] ?? null;

// If user must change PIN, block access to all other back office screens
if (Auth::mustChangePin() && $view !== 'change_pin') {
    header('Location: ' . APP_URL . '/index.php?view=change_pin');
    exit;
}

// 4b. Handle Owner / Admin Backup Download & Creation
if ($action === 'download_backup') {
    Auth::requirePermission('backup.manage');
    require_once __DIR__ . '/core/Backup.php';
    $specificFile = trim((string)($_GET['file'] ?? ''));
    if ($specificFile !== '') {
        $cleanFile = basename($specificFile);
        $fullPath = Backup::getBackupDir() . DIRECTORY_SEPARATOR . $cleanFile;
        if (file_exists($fullPath) && str_ends_with($cleanFile, '.sql')) {
            audit_log('BACKUP_DOWNLOAD', "Downloaded specific backup file: {$cleanFile}", 'system', $cleanFile);
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $cleanFile . '"');
            header('Content-Length: ' . filesize($fullPath));
            readfile($fullPath);
            exit;
        }
    }
    audit_log('BACKUP_DOWNLOAD', "Downloaded live database backup", 'system');
    Backup::downloadSqlBackup(Database::getInstance());
    exit;
}

if ($action === 'create_backup_now' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requirePermission('backup.manage');
    if (!verify_csrf()) {
        header('Location: index.php?view=settings&tab=system&error=csrf');
        exit;
    }
    require_once __DIR__ . '/core/Backup.php';
    $res = Backup::runDailyBackup(Database::getInstance(), null, 14);
    header('Location: index.php?view=settings&tab=system&msg=backup_created');
    exit;
}

// 4c. Notification & Live Sync API Endpoints
if ($action === 'live_sync') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    require_once __DIR__ . '/core/NotificationManager.php';
    $pdo = Database::getInstance();
    $userId = Auth::getUserId();
    $userRole = Auth::getRole();

    // Auto-sync real-time system alerts
    NotificationManager::syncSystemEvents($pdo);

    $unreadCount = NotificationManager::getUnreadCount($pdo, $userId, $userRole);
    $notifs = NotificationManager::getNotifications($pdo, $userId, $userRole, 15);
    $isMuted = NotificationManager::isSoundMuted($pdo, $userId);

    // Check if any fresh unread notification arrived in the last 6 seconds
    $recentSound = null;
    if (!$isMuted) {
        foreach ($notifs as $n) {
            if ((int)$n['is_read'] === 0 && (time() - strtotime($n['created_at'])) <= 6) {
                if ($n['sound_type'] === 'alert') {
                    $recentSound = 'alert';
                    break;
                } elseif ($n['sound_type'] === 'chime' && $recentSound === null) {
                    $recentSound = 'chime';
                }
            }
        }
    }

    // Screen change indicators for live dynamic updates
    $screenData = [
        'waiting_count' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'washing' AND bay_id IS NULL AND is_active = 1")->fetchColumn(),
        'busy_bays_count' => (int)$pdo->query("SELECT COUNT(*) FROM bays WHERE status = 'busy'")->fetchColumn(),
        'pending_parts_count' => (int)$pdo->query("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'")->fetchColumn(),
        'transferred_tech_count' => (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE status = 'ready_for_tech' AND task_type = 'oil_change'")->fetchColumn(),
    ];

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifs,
        'sound' => $recentSound,
        'sound_muted' => $isMuted,
        'screen' => $screenData
    ]);
    exit;
}

if ($action === 'mark_notif_read') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/NotificationManager.php';
    $notifId = (int)($_POST['notification_id'] ?? $_GET['id'] ?? 0);
    $ok = NotificationManager::markAsRead(Database::getInstance(), $notifId, Auth::getUserId());
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'mark_all_notifs_read') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/NotificationManager.php';
    $ok = NotificationManager::markAllAsRead(Database::getInstance(), Auth::getUserId(), Auth::getRole());
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'toggle_sound') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/NotificationManager.php';
    $muteState = isset($_POST['mute']) ? (bool)$_POST['mute'] : null;
    $newMute = NotificationManager::toggleSoundMute(Database::getInstance(), Auth::getUserId(), $muteState);
    echo json_encode(['success' => true, 'sound_muted' => $newMute]);
    exit;
}

// 4d. Smart Queue Engine Wait Time Calculation for POS
if ($action === 'get_estimated_wait') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    require_once __DIR__ . '/core/SmartQueueEngine.php';
    $serviceId = (int)($_GET['service_id'] ?? 0);
    $vehicleType = trim((string)($_GET['vehicle_type'] ?? 'small_car'));
    $pdo = Database::getInstance();
    $data = SmartQueueEngine::calculateEstimatedWaitTime($pdo, $serviceId, $vehicleType);
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// 4e. Smart Queue Engine Free Appointment Hourly Slots
if ($action === 'get_appointment_slots') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    require_once __DIR__ . '/core/SmartQueueEngine.php';
    $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
    $serviceLength = (int)($_GET['service_length'] ?? 30);
    $pdo = Database::getInstance();
    $slots = SmartQueueEngine::getFreeAppointmentSlots($pdo, $date, $serviceLength);
    echo json_encode(['success' => true, 'date' => $date, 'slots' => $slots]);
    exit;
}

// 4e2. CRM Memberships, Loyalty Rewards & Upsell Engine
if ($action === 'check_customer_membership') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/MembershipManager.php';
    $pdo = Database::getInstance();
    $customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $vehicleId = !empty($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : (!empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null);
    $serviceIds = $_GET['services'] ?? $_POST['services'] ?? [];
    if (!is_array($serviceIds)) $serviceIds = explode(',', (string)$serviceIds);
    $serviceIds = array_filter(array_map('intval', $serviceIds));

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

if ($action === 'get_matching_upsells') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/MembershipManager.php';
    $pdo = Database::getInstance();
    $services = $_GET['services'] ?? $_POST['services'] ?? [];
    $products = $_GET['products'] ?? $_POST['products'] ?? [];
    if (!is_array($services)) $services = explode(',', (string)$services);
    if (!is_array($products)) $products = explode(',', (string)$products);
    $serviceIds = array_filter(array_map('intval', $services));
    $productIds = array_filter(array_map('intval', $products));
    $vehicleType = trim((string)($_GET['vehicle_type'] ?? $_POST['vehicle_type'] ?? 'small_car'));
    $mileage = !empty($_GET['mileage']) ? (int)$_GET['mileage'] : (!empty($_POST['mileage']) ? (int)$_POST['mileage'] : null);

    $offers = MembershipManager::getMatchingUpsellOffers($pdo, $serviceIds, $productIds, $vehicleType, $mileage);
    echo json_encode(['success' => true, 'offers' => $offers]);
    exit;
}

if ($action === 'log_upsell_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/MembershipManager.php';
    $pdo = Database::getInstance();
    $ruleId = (int)($_POST['rule_id'] ?? 0);
    $status = trim((string)($_POST['status'] ?? 'shown')); // 'shown', 'accepted', 'declined'
    $cashierId = Auth::getUserId() ?? 1;
    $orderId = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
    $customerId = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $vehicleId = !empty($_POST['vehicle_id']) ? (int)$_POST['vehicle_id'] : null;
    $plate = trim((string)($_POST['plate_number'] ?? ''));
    $reason = trim((string)($_POST['reason_declined'] ?? ''));

    if ($ruleId > 0 && in_array($status, ['shown', 'accepted', 'declined'], true)) {
        $logId = MembershipManager::recordUpsellAction($pdo, $ruleId, $cashierId, $status, $orderId, $customerId, $vehicleId, $plate ?: null, $reason ?: null);
        echo json_encode(['success' => true, 'log_id' => $logId]);
    } else {
        echo json_encode(['success' => false, 'error' => 'invalid_params']);
    }
    exit;
}

if ($action === 'process_crm_reminders') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/MembershipManager.php';
    $pdo = Database::getInstance();
    $res = MembershipManager::processDueReminders($pdo, true);
    echo json_encode(array_merge(['success' => true], $res));
    exit;
}

// 4f. Staff Timeclock (Clock In / Clock Out)
if ($action === 'staff_timeclock_toggle') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/TimecardManager.php';
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'error' => 'csrf_failed', 'message' => 'CSRF security token verification failed.']);
        exit;
    }
    $userId = Auth::getUserId();
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => 'unauthenticated', 'message' => 'Staff authentication required.']);
        exit;
    }
    $pdo = Database::getInstance();
    $subAction = trim((string)($_POST['timeclock_action'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($subAction === 'clock_in') {
        $res = TimecardManager::clockIn($pdo, $userId, $notes !== '' ? $notes : null);
    } elseif ($subAction === 'clock_out') {
        $res = TimecardManager::clockOut($pdo, $userId, $notes !== '' ? $notes : null);
    } else {
        $active = TimecardManager::getActiveTimecard($pdo, $userId);
        if ($active) {
            $res = TimecardManager::clockOut($pdo, $userId, $notes !== '' ? $notes : null);
        } else {
            $res = TimecardManager::clockIn($pdo, $userId, $notes !== '' ? $notes : null);
        }
    }
    echo json_encode($res);
    exit;
}

if ($action === 'get_timeclock_status') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/TimecardManager.php';
    $userId = Auth::getUserId();
    $pdo = Database::getInstance();
    $active = $userId ? TimecardManager::getActiveTimecard($pdo, $userId) : null;
    echo json_encode(['success' => true, 'active' => $active]);
    exit;
}

// 4g. Timecard Correction (Restricted to Manager and Owner with mandatory reason)
if ($action === 'correct_timecard') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/core/TimecardManager.php';
    $userRole = Auth::getRole();
    if ($userRole !== 'owner' && $userRole !== 'manager') {
        echo json_encode(['success' => false, 'error' => 'permission_denied', 'message' => 'Only Manager and Owner can correct staff timecards.']);
        exit;
    }
    if (!verify_csrf()) {
        echo json_encode(['success' => false, 'error' => 'csrf_failed', 'message' => 'CSRF verification failed.']);
        exit;
    }
    $pdo = Database::getInstance();
    $timecardId = (int)($_POST['timecard_id'] ?? 0);
    $inTime = trim((string)($_POST['clock_in'] ?? ''));
    $outTime = trim((string)($_POST['clock_out'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    $managerId = Auth::getUserId() ?? 0;
    $managerName = Auth::getFullName() ?? Auth::getUsername() ?? 'Manager';

    $res = TimecardManager::correctTimecard($pdo, $timecardId, $inTime, $outTime, $reason, $managerId, $managerName);
    echo json_encode($res);
    exit;
}

// 4f. Cloud Sync: Check Critical Updates (Prices & Permissions Polling)
if ($action === 'check_critical_sync') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    require_once __DIR__ . '/core/CloudSyncManager.php';
    $pdo = Database::getInstance();
    $critRes = CloudSyncManager::checkCriticalUpdates($pdo);

    $hasUpdate = !empty($critRes['has_critical_update']);
    $newPricing = null;

    if ($hasUpdate) {
        // Automatically pull the update into the shop database
        $pullRes = CloudSyncManager::receiveFromCloud($pdo);

        // Fetch fresh pricing matrix for instant POS update without reload
        $allPrices = $pdo->query("SELECT * FROM service_prices")->fetchAll();
        $newPricing = [];
        foreach ($allPrices as $p) {
            $newPricing[$p['service_id']][$p['vehicle_type']] = [
                'usd' => (float)$p['price_usd'],
                'khr' => (int)$p['price_khr'],
                'min' => (int)$p['estimated_minutes'],
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'has_update' => $hasUpdate,
        'message' => $hasUpdate ? 'System update: prices or permissions have been updated' : null,
        'details' => $critRes['details'] ?? null,
        'pricing' => $newPricing,
        'timestamp' => time()
    ]);
    exit;
}

// 4g. Cloud Sync: Fetch Latest Catalog
if ($action === 'fetch_latest_catalog') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $pdo = Database::getInstance();
    $allPrices = $pdo->query("SELECT * FROM service_prices")->fetchAll();
    $matrix = [];
    foreach ($allPrices as $p) {
        $matrix[$p['service_id']][$p['vehicle_type']] = [
            'usd' => (float)$p['price_usd'],
            'khr' => (int)$p['price_khr'],
            'min' => (int)$p['estimated_minutes'],
        ];
    }
    echo json_encode(['success' => true, 'pricing' => $matrix]);
    exit;
}

// 4h. Cloud Sync: Trigger Force Sync (Manager & Owner)
if ($action === 'trigger_force_sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/core/CloudSyncManager.php';
    Auth::requirePermission('sync.view');
    if (!verify_csrf()) {
        $_SESSION['flash_sync_message'] = 'Security token invalid. Please try again.';
        $_SESSION['flash_sync_type'] = 'danger';
        header('Location: index.php?view=sync_monitor');
        exit;
    }
    $pdo = Database::getInstance();
    $pushRes = CloudSyncManager::sendToCloud($pdo);
    $pullRes = CloudSyncManager::receiveFromCloud($pdo);

    if ($pushRes['success'] && $pullRes['success']) {
        $_SESSION['flash_sync_message'] = "Force sync completed successfully! Sent {$pushRes['sent_count']} records, received updates.";
        $_SESSION['flash_sync_type'] = 'success';
    } else {
        $errMsg = $pushRes['error'] ?? $pullRes['error'] ?? 'Sync failed or cloud offline';
        $_SESSION['flash_sync_message'] = "Force sync completed with notes: {$errMsg}";
        $_SESSION['flash_sync_type'] = 'warning';
    }
    header('Location: index.php?view=sync_monitor');
    exit;
}

// 4i. Cloud Sync: Toggle Offline Mode
if ($action === 'toggle_offline_mode' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/core/CloudSyncManager.php';
    Auth::requirePermission('sync.view');
    if (!verify_csrf()) {
        $_SESSION['flash_sync_message'] = 'Security token invalid. Please try again.';
        $_SESSION['flash_sync_type'] = 'danger';
        header('Location: index.php?view=sync_monitor');
        exit;
    }
    $pdo = Database::getInstance();
    $current = CloudSyncManager::isOfflineMode($pdo);
    $newMode = !$current;
    CloudSyncManager::setOfflineMode($pdo, $newMode);
    $_SESSION['flash_sync_message'] = $newMode ? 'Shop server switched to Offline Mode. Network sync paused.' : 'Shop server reconnected Online. Sync resumed.';
    $_SESSION['flash_sync_type'] = $newMode ? 'warning' : 'success';
    header('Location: index.php?view=sync_monitor');
    exit;
}

// 4j. Cloud Sync: Resolve Version Conflict
if ($action === 'resolve_sync_conflict' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/core/CloudSyncManager.php';
    Auth::requirePermission('sync.view');
    if (!verify_csrf()) {
        $_SESSION['flash_sync_message'] = 'Security token invalid. Please try again.';
        $_SESSION['flash_sync_type'] = 'danger';
        header('Location: index.php?view=sync_monitor');
        exit;
    }
    $pdo = Database::getInstance();
    $conflictId = (int)($_POST['conflict_id'] ?? 0);
    $resolution = (string)($_POST['resolution'] ?? 'keep_shop');
    $userId = Auth::getUserId() ?? 0;
    $userName = Auth::getFullName() ?? Auth::getUsername() ?? 'Manager';

    $ok = CloudSyncManager::resolveConflict($pdo, $conflictId, $resolution, $userId, $userName);
    if ($ok) {
        $_SESSION['flash_sync_message'] = "Conflict #{$conflictId} resolved successfully ({$resolution}).";
        $_SESSION['flash_sync_type'] = 'success';
    } else {
        $_SESSION['flash_sync_message'] = "Failed to resolve conflict #{$conflictId}.";
        $_SESSION['flash_sync_type'] = 'danger';
    }
    header('Location: index.php?view=sync_monitor');
    exit;
}

// 4c. CSRF Protection on authenticated POST requests
$csrfError = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action !== 'update_pin') {
    if (!verify_csrf()) {
        app_log('SECURITY', 'CSRF verification failed', [
            'view' => $_GET['view'] ?? '',
            'user' => Auth::getUsername(),
            'role' => Auth::getRole()
        ]);
        $_POST = [];
        $csrfError = "Security token mismatch or expired session. For your protection, the action was blocked. Please refresh and try again.";
    }
}

// 5. Authenticated Routing with Server-Side Checks
$role = Auth::getRole();

// Default view per role/permissions if none specified
if (!$view) {
    $view = Auth::getDefaultViewForUser();
}

// When POS_UI is 'new', redirect (302) view=pos to APP_URL . '/pos/index.html#pos'. When 'legacy', map to /views/cashier/pos.php.
if ($view === 'pos' && defined('POS_UI') && POS_UI === 'new') {
    header('Location: ' . APP_URL . '/pos/index.html#pos', true, 302);
    exit;
}

$viewMap = [
    'login'           => '/views/auth/login.php',
    'change_pin'      => '/views/auth/change_pin.php',
    'dashboard'       => '/views/owner/dashboard.php',
    'services'        => '/views/owner/services.php',
    'customers'       => '/views/owner/customers.php',
    'pos'             => '/views/cashier/pos.php',
    'shift'           => '/views/cashier/shift.php',
    'close_day'       => '/views/cashier/shift.php',
    'orders'          => '/views/orders/index.php',
    'bays_queue'      => '/views/owner/bays_queue.php',
    'bays'            => '/views/owner/bays_queue.php',
    'commissions'     => '/views/owner/commissions.php',
    'receipt'         => '/views/orders/receipt.php',
    'manual_receipt'  => '/views/orders/manual_receipt.php',
    'washer_jobs'     => '/views/washer/jobs.php',
    'mechanic_jobs'   => '/views/mechanic/jobs.php',
    'vehicle_history' => '/views/owner/vehicle_history.php',
    'expenses'        => '/views/owner/expenses.php',
    'reports'         => '/views/owner/reports.php',
    'extras'          => '/views/owner/extras.php',
    'staff'           => '/views/owner/staff.php',
    'oil_care'        => '/views/owner/oil_care.php',
    'stock_ledger'    => '/views/owner/stock_ledger.php',
    'pos_devices'     => '/views/owner/pos_devices.php',
    'categories'      => '/views/owner/categories.php',
    'roles'           => '/views/owner/roles.php',
    'settings'        => '/views/owner/settings.php',
    'sync_monitor'    => '/views/owner/sync_monitor.php',
    'audit_logs'      => '/views/owner/audit_logs.php',
    'paper_forms'     => '/views/recovery/paper_forms.php',
    'recovery_bulk_entry' => '/views/recovery/bulk_entry.php',
];

$fallbackViewKey = Auth::getDefaultViewForUser();
$defaultFallback = $viewMap[$fallbackViewKey] ?? '/views/auth/login.php';

$viewFile = __DIR__ . ($viewMap[$view] ?? $defaultFallback);
if (file_exists($viewFile)) {
    require_once $viewFile;
} else {
    require_once __DIR__ . '/views/auth/login.php';
}
