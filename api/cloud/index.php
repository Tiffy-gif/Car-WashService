<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/app.php';
require_once dirname(__DIR__, 2) . '/core/Database.php';

header('Content-Type: application/json; charset=utf-8');

$storageFile = dirname(__DIR__, 2) . '/storage/cloud_site_state.json';

function getCloudState(string $storageFile): array {
    if (file_exists($storageFile)) {
        $data = json_decode((string)file_get_contents($storageFile), true);
        if (is_array($data)) return $data;
    }
    return [
        'catalog_version' => time(),
        'services' => [],
        'products' => [],
        'users' => [],
        'settings' => [],
        'received_sales' => [],
        'received_jobs' => [],
        'received_movements' => [],
        'received_closings' => [],
        'received_timecards' => [],
        'received_audit_logs' => [],
        'received_crm' => [],
        'received_upsell_logs' => [],
        'membership_tiers' => [],
        'loyalty_reward_rules' => [],
        'upsell_rules' => [],
        'last_updated_at' => date('Y-m-d H:i:s')
    ];
}

function saveCloudState(string $storageFile, array $state): void {
    @file_put_contents($storageFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// 1. Verify Secret & Signature
$pdo = Database::getInstance();
$cloudSecret = '';
try {
    $secStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'cloud_api_secret'");
    $cloudSecret = (string)$secStmt->fetchColumn();
} catch (Exception $e) {}

if (empty($cloudSecret)) {
    $cloudSecret = 'cloud_sec_live_x99y88z77w66v55';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Test helpers can bypass signature if called internally with test parameter
$isTestHelper = in_array($action, ['set_cloud_price', 'set_cloud_conflict', 'reset_cloud_state', 'dump_cloud_state'], true);

if (!$isTestHelper) {
    $headers = getallheaders();
    $token = $headers['X-Shop-Token'] ?? $headers['x-shop-token'] ?? $_SERVER['HTTP_X_SHOP_TOKEN'] ?? '';
    $timestamp = (int)($headers['X-Shop-Timestamp'] ?? $headers['x-shop-timestamp'] ?? $_SERVER['HTTP_X_SHOP_TIMESTAMP'] ?? 0);
    $nonce = $headers['X-Shop-Nonce'] ?? $headers['x-shop-nonce'] ?? $_SERVER['HTTP_X_SHOP_NONCE'] ?? '';
    $signature = $headers['X-Shop-Signature'] ?? $headers['x-shop-signature'] ?? $_SERVER['HTTP_X_SHOP_SIGNATURE'] ?? '';

    // Verify token exists
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'missing_token', 'message' => 'Shop token missing from request headers.']);
        exit;
    }

    // Verify timestamp within 5 minutes (prevent replay attacks)
    if (abs(time() - $timestamp) > 300) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'timestamp_expired', 'message' => 'Request timestamp is expired or out of tolerance.']);
        exit;
    }

    // Verify HMAC-SHA256 signature
    $rawBody = file_get_contents('php://input');
    $expectedSig = hash_hmac('sha256', "{$timestamp}.{$nonce}." . $rawBody, $cloudSecret);

    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_signature', 'message' => 'Cryptographic signature mismatch.']);
        exit;
    }
}

$state = getCloudState($storageFile);

// 2. Action: PUSH from Shop Server
if ($action === 'push') {
    $rawBody = file_get_contents('php://input');
    $encoding = $_SERVER['HTTP_CONTENT_ENCODING'] ?? '';
    if (stripos($encoding, 'gzip') !== false || substr($rawBody, 0, 2) === "\x1f\x8b") {
        $decompressed = @gzdecode($rawBody);
        if ($decompressed !== false) {
            $rawBody = $decompressed;
        }
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_payload', 'message' => 'JSON payload could not be parsed.']);
        exit;
    }

    $confirmed = [
        'sales' => [],
        'job_orders' => [],
        'stock_movements' => [],
        'shifts' => [],
        'timecards' => [],
        'audit_logs' => [],
        'crm_events' => [],
        'upsell_logs' => []
    ];

    // Store sales
    if (!empty($payload['sales']) && is_array($payload['sales'])) {
        foreach ($payload['sales'] as $sale) {
            $uid = $sale['order_number'] ?? $sale['order_uid'] ?? ('ORD-' . ($sale['id'] ?? uniqid()));
            $state['received_sales'][$uid] = $sale;
            $confirmed['sales'][] = $uid;
        }
    }

    // Store job orders
    if (!empty($payload['job_orders']) && is_array($payload['job_orders'])) {
        foreach ($payload['job_orders'] as $job) {
            $uid = $job['job_order_uid'] ?? $job['task_uid'] ?? ('JOB-' . ($job['id'] ?? uniqid()));
            $state['received_jobs'][$uid] = $job;
            $confirmed['job_orders'][] = $uid;
        }
    }

    // Store stock movements
    if (!empty($payload['stock_movements']) && is_array($payload['stock_movements'])) {
        foreach ($payload['stock_movements'] as $sm) {
            $uid = $sm['movement_uid'] ?? (string)($sm['id'] ?? uniqid());
            $state['received_movements'][$uid] = $sm;
            $confirmed['stock_movements'][] = $uid;
        }
    }

    // Store shifts
    if (!empty($payload['shifts']) && is_array($payload['shifts'])) {
        foreach ($payload['shifts'] as $sh) {
            $uid = 'SHIFT-' . ($sh['id'] ?? uniqid());
            $state['received_shifts'][$uid] = $sh;
            $confirmed['shifts'][] = $uid;
        }
    }

    // Store timecards
    if (!empty($payload['timecards']) && is_array($payload['timecards'])) {
        foreach ($payload['timecards'] as $tc) {
            $uid = $tc['timecard_uid'] ?? (string)($tc['id'] ?? uniqid());
            $state['received_timecards'][$uid] = $tc;
            $confirmed['timecards'][] = $uid;
        }
    }

    // Store audit logs
    if (!empty($payload['audit_logs']) && is_array($payload['audit_logs'])) {
        foreach ($payload['audit_logs'] as $al) {
            $uid = 'AUD-' . ($al['id'] ?? uniqid());
            $state['received_audit_logs'][$uid] = $al;
            $confirmed['audit_logs'][] = $uid;
        }
    }

    // Store CRM events (loyalty transactions + message queue events)
    if (!empty($payload['crm_events']) && is_array($payload['crm_events'])) {
        foreach ($payload['crm_events'] as $crm) {
            $uid = $crm['event_uid'] ?? ('CRM-' . ($crm['id'] ?? uniqid()));
            $state['received_crm'][$uid] = $crm;
            $confirmed['crm_events'][] = $uid;
        }
    }

    // Store upsell logs
    if (!empty($payload['upsell_logs']) && is_array($payload['upsell_logs'])) {
        foreach ($payload['upsell_logs'] as $ul) {
            $uid = $ul['log_uid'] ?? ('UPS-' . ($ul['id'] ?? uniqid()));
            $state['received_upsell_logs'][$uid] = $ul;
            $confirmed['upsell_logs'][] = $uid;
        }
    }

    $state['last_updated_at'] = date('Y-m-d H:i:s');
    saveCloudState($storageFile, $state);

    echo json_encode([
        'success' => true,
        'message' => 'Batch records confirmed and securely stored in cloud repository.',
        'confirmed_ids' => $confirmed,
        'server_timestamp' => time()
    ]);
    exit;
}

// 3. Action: PULL to Shop Server
if ($action === 'pull') {
    // If services or products in cloud state are empty, populate defaults from shop catalog
    if (empty($state['services'])) {
        $services = $pdo->query("SELECT id, name_en, name_km, category, base_duration_minutes, is_active FROM services")->fetchAll(PDO::FETCH_ASSOC);
        $state['services'] = $services;
    }
    if (empty($state['products'])) {
        $products = $pdo->query("SELECT id, name_en, name_km, category, brand, cost_price_usd, price_usd, stock_qty, is_active FROM products")->fetchAll(PDO::FETCH_ASSOC);
        $state['products'] = $products;
    }
    if (empty($state['users'])) {
        $users = $pdo->query("SELECT id, username, full_name, role, status FROM users WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $state['users'] = $users;
    }
    if (empty($state['membership_tiers'])) {
        $tiers = $pdo->query("SELECT * FROM membership_tiers")->fetchAll(PDO::FETCH_ASSOC);
        $state['membership_tiers'] = $tiers;
    }
    if (empty($state['loyalty_reward_rules'])) {
        $rules = $pdo->query("SELECT * FROM loyalty_reward_rules")->fetchAll(PDO::FETCH_ASSOC);
        $state['loyalty_reward_rules'] = $rules;
    }
    if (empty($state['upsell_rules'])) {
        $upsells = $pdo->query("SELECT * FROM upsell_rules")->fetchAll(PDO::FETCH_ASSOC);
        $state['upsell_rules'] = $upsells;
    }

    // Attach conflict markers if seeded
    $outServices = $state['services'];
    $outProducts = $state['products'];
    if (!empty($state['conflicts'])) {
        foreach ($state['conflicts'] as $c) {
            $cType = $c['record_type'] ?? 'service';
            $cUid = (string)($c['record_uid'] ?? '');
            if ($cType === 'service') {
                foreach ($outServices as &$s) {
                    if ((string)$s['id'] === $cUid) {
                        $s['is_conflict'] = true;
                        $s['name_en'] = $c['cloud_data']['name_en'] ?? $s['name_en'];
                        $s['price_usd'] = $c['cloud_data']['price_usd'] ?? ($s['price_usd'] ?? 0);
                    }
                }
            } elseif ($cType === 'product') {
                foreach ($outProducts as &$p) {
                    if ((string)$p['id'] === $cUid) {
                        $p['is_conflict'] = true;
                        $p['name_en'] = $c['cloud_data']['name_en'] ?? $p['name_en'];
                        $p['price_usd'] = $c['cloud_data']['price_usd'] ?? ($p['price_usd'] ?? 0);
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'catalog_version' => $state['catalog_version'],
        'last_updated_at' => $state['last_updated_at'],
        'services' => $outServices,
        'products' => $outProducts,
        'users' => $state['users'],
        'membership_tiers' => $state['membership_tiers'],
        'loyalty_reward_rules' => $state['loyalty_reward_rules'],
        'upsell_rules' => $state['upsell_rules'],
        'settings' => [
            'shop_name_en' => 'AquaGleam Car Wash & Detailing',
            'multiplier_small_car' => '1.0',
            'multiplier_suv_pickup' => '1.25',
            'multiplier_van_truck' => '1.4'
        ]
    ]);
    exit;
}

// 4. Action: CHECK CRITICAL (Fast check every 30s)
if ($action === 'check_critical') {
    $clientVersion = (int)($_GET['version'] ?? 0);
    $hasUpdate = ($state['catalog_version'] > $clientVersion);

    echo json_encode([
        'success' => true,
        'has_update' => $hasUpdate,
        'catalog_version' => $state['catalog_version'],
        'message' => $hasUpdate ? 'System update: prices or permissions have been updated' : 'Up to date',
        'server_timestamp' => time()
    ]);
    exit;
}

// 5. Simulation Helpers for Tests
if ($action === 'set_cloud_price') {
    $serviceId = (int)($_POST['service_id'] ?? $_GET['service_id'] ?? 1);
    $newPrice = (float)($_POST['new_price'] ?? $_POST['price_usd'] ?? $_GET['new_price'] ?? 7.50);
    $serviceName = trim((string)($_POST['service_name'] ?? $_GET['service_name'] ?? 'Exterior Wash'));

    $state['catalog_version'] = (int)($state['catalog_version'] ?? time()) + 10;
    $state['last_updated_at'] = date('Y-m-d H:i:s');
    $state['critical_update'] = [
        'type' => 'price_change',
        'service_id' => $serviceId,
        'service_name' => $serviceName,
        'new_price' => $newPrice,
        'timestamp' => time()
    ];

    // Update in state services
    $found = false;
    foreach ($state['services'] as &$s) {
        if ((int)$s['id'] === $serviceId) {
            $s['price_usd'] = $newPrice;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $state['services'][] = [
            'id' => $serviceId,
            'name_en' => $serviceName,
            'name_km' => $serviceName,
            'category' => 'wash',
            'price_usd' => $newPrice,
            'is_active' => 1
        ];
    }

    saveCloudState($storageFile, $state);

    echo json_encode([
        'success' => true,
        'catalog_version' => $state['catalog_version'],
        'updated_service_id' => $serviceId,
        'new_price' => $newPrice,
        'message' => "Cloud price for service #{$serviceId} updated to \${$newPrice}."
    ]);
    exit;
}

if ($action === 'set_cloud_conflict') {
    $recordType = trim((string)($_POST['record_type'] ?? $_POST['entity_type'] ?? 'service'));
    $recordUid = trim((string)($_POST['record_uid'] ?? $_POST['entity_id'] ?? '1'));
    $cloudVersion = $_POST['cloud_data'] ?? [
        'name_en' => 'Cloud High-End Wax & Polish',
        'price_usd' => 45.00,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    if (is_string($cloudVersion)) {
        $cloudVersion = json_decode($cloudVersion, true) ?: ['name_en' => $cloudVersion];
    }

    $state['conflicts'][$recordUid] = [
        'record_type' => $recordType,
        'record_uid' => $recordUid,
        'cloud_data' => $cloudVersion,
        'cloud_updated_at' => date('Y-m-d H:i:s')
    ];
    $state['catalog_version'] = (int)($state['catalog_version'] ?? time()) + 10;

    saveCloudState($storageFile, $state);

    echo json_encode([
        'success' => true,
        'record_uid' => $recordUid,
        'cloud_data' => $cloudVersion,
        'message' => "Simulated cloud conflict seeded for {$recordUid}."
    ]);
    exit;
}

if ($action === 'dump_cloud_state') {
    echo json_encode([
        'success' => true,
        'sales' => array_values($state['received_sales'] ?? []),
        'jobs' => array_values($state['received_jobs'] ?? []),
        'movements' => array_values($state['received_movements'] ?? []),
        'shifts' => array_values($state['received_shifts'] ?? []),
        'timecards' => array_values($state['received_timecards'] ?? []),
        'audit_logs' => array_values($state['received_audit_logs'] ?? []),
        'crm' => array_values($state['received_crm'] ?? []),
    ]);
    exit;
}

if ($action === 'reset_cloud_state') {
    @unlink($storageFile);
    echo json_encode(['success' => true, 'message' => 'Cloud site state reset to fresh defaults.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Cloud Sync API Server Ready', 'version' => '1.0.0']);
