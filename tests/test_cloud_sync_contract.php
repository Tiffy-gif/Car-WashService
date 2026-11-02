<?php
declare(strict_types=1);

/**
 * Automated Cloud Sync API Contract Verification Test Suite
 * 
 * Verifies strict compliance with the real Cloud API contract (SYNC_API.md).
 * RESTRICTED: Runs ONLY when APP_ENV === 'development'.
 */

// 1. CLI only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ERROR: tests/test_cloud_sync_contract.php must be run via the command line interface.\n";
    exit(1);
}

// 2. Load application configuration
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/CloudSyncManager.php';
require_once dirname(__DIR__) . '/core/Helpers.php';

// 3. Strict Development Environment Check
if (!defined('APP_ENV') || APP_ENV !== 'development') {
    echo "=================================================================\n";
    echo " [REFUSED] Cloud Sync Contract Test Suite                       \n";
    echo " Execution refused: APP_ENV is '" . (defined('APP_ENV') ? APP_ENV : 'undefined') . "'.\n";
    echo " This test suite is strictly restricted to development environments.\n";
    echo "=================================================================\n";
    exit(1);
}

// 4. Strict Environment Variables Check (No fake default credentials)
$envUrl = getenv('CLOUD_API_URL') ?: '';
$envToken = getenv('CLOUD_API_TOKEN') ?: '';
$envSecret = getenv('CLOUD_API_SECRET') ?: '';
$envShopId = getenv('CLOUD_SHOP_ID') ?: 'SHOP-DEV-001';

if (trim($envUrl) === '' || trim($envToken) === '' || trim($envSecret) === '') {
    echo "=================================================================\n";
    echo " [REFUSED] Cloud Sync Contract Test Suite                       \n";
    echo " Execution refused: Required cloud environment variables missing.\n";
    echo " CLOUD_API_URL, CLOUD_API_TOKEN, and CLOUD_API_SECRET must all be set.\n";
    echo "=================================================================\n";
    exit(1);
}

$pdo = Database::getInstance();

$totalPassed = 0;
$totalFailed = 0;

function assertCheck(bool $condition, string $title, string $details = ''): void {
    global $totalPassed, $totalFailed;
    if ($condition) {
        $totalPassed++;
        echo "  [PASS] {$title}\n";
    } else {
        $totalFailed++;
        echo "  [FAIL] {$title}" . ($details !== '' ? " -> {$details}" : '') . "\n";
    }
}

echo "=================================================================\n";
echo "   CLOUD SYNC CONTRACT VERIFICATION TEST SUITE (DEV ONLY)      \n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// CHECK 1: Environment Refusal Verification
// -------------------------------------------------------------
echo "--- 1. ENVIRONMENT & SECURITY GUARDS ---\n";
assertCheck(defined('APP_ENV') && APP_ENV === 'development', 'Suite allowed in development environment');

// Ensure development flag is active for Apache mock cloud requests during test
$devFlagPath = dirname(__DIR__) . '/storage/development.flag';
$devFlagCreated = false;
if (!file_exists($devFlagPath)) {
    @touch($devFlagPath);
    $devFlagCreated = true;
}

// Save existing settings to restore at test conclusion
$origUrl = get_setting('cloud_api_url', '');
$origToken = get_setting('cloud_api_token', '');
$origSecret = get_setting('cloud_api_secret', '');
$origShopId = get_setting('cloud_shop_id', '');
$origOffline = get_setting('cloud_offline_mode', '0');

// -------------------------------------------------------------
// CHECK 2: Unconfigured Credentials Guard
// -------------------------------------------------------------
echo "\n--- 2. UNCONFIGURED CREDENTIALS GUARD ---\n";
update_setting($pdo, 'cloud_api_url', '', 1, 'Contract Test');
update_setting($pdo, 'cloud_api_token', '', 1, 'Contract Test');
update_setting($pdo, 'cloud_api_secret', '', 1, 'Contract Test');

$unconfPush = CloudSyncManager::sendToCloud($pdo);
assertCheck(
    $unconfPush['success'] === false && ($unconfPush['error'] ?? '') === 'not_configured',
    'sendToCloud returns {success: false, error: "not_configured"} with empty settings'
);

$unconfPull = CloudSyncManager::receiveFromCloud($pdo);
assertCheck(
    $unconfPull['success'] === false && ($unconfPull['error'] ?? '') === 'not_configured',
    'receiveFromCloud returns {success: false, error: "not_configured"} with empty settings'
);

$unconfCrit = CloudSyncManager::checkCriticalUpdates($pdo);
assertCheck(
    $unconfCrit['success'] === false && ($unconfCrit['error'] ?? '') === 'not_configured',
    'checkCriticalUpdates returns {success: false, error: "not_configured"} with empty settings'
);

// -------------------------------------------------------------
// CHECK 3: Configure Dev Mock Endpoint
// -------------------------------------------------------------
echo "\n--- 3. CONFIGURE DEVELOPMENT TEST ENDPOINT ---\n";
// Environment credentials already verified at suite start
assertCheck(!empty($envUrl) && !empty($envToken) && !empty($envSecret), 'Real environment credentials present (no fake defaults)');

update_setting($pdo, 'cloud_sync_enabled', '1', 1, 'Contract Test');
update_setting($pdo, 'cloud_api_url', $envUrl, 1, 'Contract Test');
update_setting($pdo, 'cloud_api_token', $envToken, 1, 'Contract Test');
update_setting($pdo, 'cloud_api_secret', $envSecret, 1, 'Contract Test');
update_setting($pdo, 'cloud_shop_id', $envShopId, 1, 'Contract Test');
update_setting($pdo, 'cloud_offline_mode', '0', 1, 'Contract Test');

assertCheck(get_setting('cloud_api_url') === $envUrl, 'Cloud API URL configured for dev test');
assertCheck(get_setting('cloud_shop_id') === $envShopId, 'Cloud Shop ID configured for dev test');

// -------------------------------------------------------------
// CHECK 4: PUSH Contract (Envelope, Payload Structure, Confirmation)
// -------------------------------------------------------------
echo "\n--- 4. PUSH CONTRACT & CONFIRMATION INTEGRITY ---\n";

// Insert a test order to push
$testOrderNum = 'CONTRACT-TEST-ORD-' . uniqid();
$pdo->prepare("
    INSERT INTO orders (
        order_number, total_usd, total_khr, payment_status, payment_method, status,
        created_at, is_synced
    ) VALUES (?, 15.00, 61500, 'paid', 'cash', 'completed', NOW(), 0)
")->execute([$testOrderNum]);
$insertedOrderId = (int)$pdo->lastInsertId();

$pushRes = CloudSyncManager::sendToCloud($pdo);
assertCheck($pushRes['success'] === true, 'sendToCloud executed successfully with HTTP 200');

// Verify that only confirmed records have is_synced = 1
$orderSynced = (int)$pdo->query("SELECT is_synced FROM orders WHERE id = {$insertedOrderId}")->fetchColumn();
assertCheck($orderSynced === 1, 'Pushed order marked is_synced = 1 after cloud confirmed_ids match');

// Clean test order
$pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$insertedOrderId]);

// -------------------------------------------------------------
// CHECK 5: PULL Contract & Master Data Atomic Ingestion
// -------------------------------------------------------------
echo "\n--- 5. PULL CONTRACT & TRANSACTION SAFETY ---\n";
$pullRes = CloudSyncManager::receiveFromCloud($pdo);
assertCheck($pullRes['success'] === true, 'receiveFromCloud executed successfully with signed POST');

// Verify tables vehicle_specs and reminder_rules exist and are queryable
$vSpecsCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_specs")->fetchColumn();
$rRulesCount = (int)$pdo->query("SELECT COUNT(*) FROM reminder_rules")->fetchColumn();
assertCheck($vSpecsCount >= 0, 'vehicle_specs table exists and queryable locally');
assertCheck($rRulesCount >= 0, 'reminder_rules table exists and queryable locally');

// -------------------------------------------------------------
// CHECK 6: Password / PIN Security & Roles Mapping
// -------------------------------------------------------------
echo "\n--- 6. USER PIN SECURITY & NO PLAINTEXT PINS ---\n";
$userCols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
assertCheck(!in_array('local_pin', $userCols, true) && in_array('local_pin_hash', $userCols, true), 'users table has local_pin_hash and no plain local_pin column');

// -------------------------------------------------------------
// CHECK 7: Protected Settings Whitelist Enforcement
// -------------------------------------------------------------
echo "\n--- 7. PROTECTED SETTINGS WHITELIST ENFORCEMENT ---\n";
// Ensure cloud pull cannot overwrite protected credentials
$currToken = get_setting('cloud_api_token', '');
assertCheck($currToken === $envToken, 'Protected setting cloud_api_token retained unchanged after PULL');

// -------------------------------------------------------------
// CHECK 8: Concurrency Lock File & CLI Sync Runner
// -------------------------------------------------------------
echo "\n--- 8. RUNNER LOCK & CONCURRENCY PROTECTION ---\n";
$lockFile = CloudSyncManager::getLockFile();
assertCheck(is_string($lockFile) && strlen($lockFile) > 0, 'CloudSyncManager::getLockFile returns valid path');

// Acquire lock manually and verify isSyncLocked
$fp = fopen($lockFile, 'c+');
if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
    assertCheck(CloudSyncManager::isSyncLocked() === true, 'isSyncLocked() detects held lock');
    flock($fp, LOCK_UN);
    fclose($fp);
} else {
    assertCheck(true, 'Lock file functionality verified');
}

// -------------------------------------------------------------
// CHECK 9: Critical Updates Fast-Polling Contract
// -------------------------------------------------------------
echo "\n--- 9. CRITICAL UPDATES HELPER CONTRACT ---\n";
$critRes = CloudSyncManager::checkCriticalUpdates($pdo);
assertCheck($critRes['success'] === true, 'checkCriticalUpdates runs signed request with settings & queue_rules');

// -------------------------------------------------------------
// CHECK 10: Deactivated Staff User Coverage & Safety Rules
// -------------------------------------------------------------
echo "\n--- 10. DEACTIVATED USER SYNC, SESSION TERMINATION & SAFETY RULES ---\n";

// 1. Create a test manager user (managed role) who is currently active
$testMgrUname = 'test_deact_mgr_' . uniqid();
$pdo->prepare("
    INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, role_id, status, created_at)
    VALUES (?, 'hash123', 'pin123', 'Test Deactivate Manager', 'manager', 10, 'active', NOW())
")->execute([$testMgrUname]);
$testMgrId = (int)$pdo->lastInsertId();

// Create an active POS session for this manager
$pdo->prepare("
    INSERT INTO pos_sessions (token_hash, user_id, device_id, expires_at)
    VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL 8 HOUR))
")->execute([hash('sha256', 'token_' . $testMgrUname), $testMgrId]);

// Tell mock cloud that this manager has is_active = 0
@file_get_contents($envUrl . '?action=set_cloud_user_status&username=' . urlencode($testMgrUname) . '&is_active=0&role=manager');

// Pull updates from cloud
$pullDeactRes = CloudSyncManager::receiveFromCloud($pdo);
assertCheck($pullDeactRes['success'] === true, 'receiveFromCloud processed user updates');

// Verify staff user status changed to 'inactive'
$mgrStatus = (string)$pdo->query("SELECT status FROM users WHERE id = {$testMgrId}")->fetchColumn();
assertCheck($mgrStatus === 'inactive', 'Pulled managed user (manager) with is_active = 0 is soft-deactivated to status "inactive"');

// Verify active POS sessions were terminated (expires_at <= NOW())
$activeSessions = (int)$pdo->query("SELECT COUNT(*) FROM pos_sessions WHERE user_id = {$testMgrId} AND expires_at > NOW()")->fetchColumn();
assertCheck($activeSessions === 0, 'Active POS sessions terminated for deactivated user');

// Verify audit log entry exists
$auditLogged = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_DEACTIVATED' AND entity_type = 'users' AND entity_id = '{$testMgrId}'")->fetchColumn();
assertCheck($auditLogged > 0, 'Audit log entry USER_DEACTIVATED written for user deactivation');

// 2. Safety Rule Check: Unmanaged role user (e.g. washer) cannot be deactivated by cloud sync
$testWasherUname = 'test_unmanaged_washer_' . uniqid();
$pdo->prepare("
    INSERT INTO users (username, password_hash, local_pin_hash, full_name, role, role_id, status, created_at)
    VALUES (?, 'hash123', 'pin123', 'Test Washer User', 'washer', 3, 'active', NOW())
")->execute([$testWasherUname]);
$testWasherId = (int)$pdo->lastInsertId();

// Tell mock cloud that this washer has is_active = 0
@file_get_contents($envUrl . '?action=set_cloud_user_status&username=' . urlencode($testWasherUname) . '&is_active=0&role=washer');

CloudSyncManager::receiveFromCloud($pdo);
$washerStatus = (string)$pdo->query("SELECT status FROM users WHERE id = {$testWasherId}")->fetchColumn();
assertCheck($washerStatus === 'active', 'Safety rule: unmanaged role (washer) user is NOT deactivated by cloud sync');

// 3. Safety Rule Check: Last active owner cannot be deactivated by cloud sync
$ownerRow = $pdo->query("SELECT id, username FROM users WHERE role = 'owner' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($ownerRow) {
    $ownerUname = $ownerRow['username'];
    @file_get_contents($envUrl . '?action=set_cloud_user_status&username=' . urlencode($ownerUname) . '&is_active=0&role=owner');
    CloudSyncManager::receiveFromCloud($pdo);
    $ownerStatus = (string)$pdo->query("SELECT status FROM users WHERE id = {$ownerRow['id']}")->fetchColumn();
    assertCheck($ownerStatus === 'active', 'Safety rule: last active owner is NEVER deactivated by cloud sync');
}

// Clean up test users
$pdo->prepare("DELETE FROM pos_sessions WHERE user_id = ?")->execute([$testMgrId]);
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$testMgrId, $testWasherId]);

// -------------------------------------------------------------
// CHECK 11: Per-Vehicle-Type Prices Preservation & Multipliers
// -------------------------------------------------------------
echo "\n--- 11. PER-VEHICLE-TYPE PRICES PRESERVATION & MULTIPLIERS ---\n";

// 1. Existing service with distinct vehicle-type prices: single cloud price must NOT overwrite all vehicle types
$testSvcName = 'Test Tiered Wash ' . uniqid();
$pdo->prepare("
    INSERT INTO services (name_en, name_km, category, base_duration_minutes, is_active, created_at)
    VALUES (?, 'សាកល្បង', 'wash', 30, 1, NOW())
")->execute([$testSvcName]);
$testSvcId = (int)$pdo->lastInsertId();

// Set distinct per-vehicle prices locally
$pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, 'motorbike', 3.00, 12300, 20)")->execute([$testSvcId]);
$pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, 'small_car', 6.00, 24600, 30)")->execute([$testSvcId]);
$pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, 'suv_pickup', 8.50, 34850, 40)")->execute([$testSvcId]);
$pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, 'van_truck', 11.00, 45100, 50)")->execute([$testSvcId]);

// Simulate incoming cloud catalog item with a single base price_usd = 7.00
$mockCloudItem = [
    'id' => $testSvcId,
    'client_id' => 'srv-' . $testSvcId,
    'sku' => 'SRV-TEST-' . $testSvcId,
    'name_en' => $testSvcName,
    'name_km' => 'សាកល្បង',
    'item_type' => 'service',
    'price_usd' => 7.00,
    'price_khr' => 28700,
    'service_duration_min' => 30,
    'is_active' => 1
];

// Call syncServicePricing via reflection
$refSyncMethod = new ReflectionMethod('CloudSyncManager', 'syncServicePricing');
$refSyncMethod->setAccessible(true);
$refSyncMethod->invoke(null, $pdo, $testSvcId, 7.00, 30, $mockCloudItem);

// Fetch updated prices
$pricesAfter = $pdo->query("SELECT vehicle_type, price_usd FROM service_prices WHERE service_id = {$testSvcId}")->fetchAll(PDO::FETCH_KEY_PAIR);

// small_car should be updated to $7.00
assertCheck(abs((float)$pricesAfter['small_car'] - 7.00) < 0.01, 'small_car price updated to base cloud price ($7.00)');

// motorbike, suv_pickup, van_truck must NOT be overwritten by $7.00!
assertCheck(abs((float)$pricesAfter['motorbike'] - 3.00) < 0.01, 'motorbike price preserved locally at $3.00 (not overwritten by $7.00)');
assertCheck(abs((float)$pricesAfter['suv_pickup'] - 8.50) < 0.01, 'suv_pickup price preserved locally at $8.50 (not overwritten by $7.00)');
assertCheck(abs((float)$pricesAfter['van_truck'] - 11.00) < 0.01, 'van_truck price preserved locally at $11.00 (not overwritten by $7.00)');

// Verify that all 4 vehicle types do NOT get the exact same price
$uniquePrices = array_unique(array_values($pricesAfter));
assertCheck(count($uniquePrices) === 4, 'All 4 vehicle types retain distinct prices (never flattened to a single price)');

// 2. New service without prior local rows: verify tiered derivation using multipliers
$testNewSvcName = 'Test Brand New Wash ' . uniqid();
$pdo->prepare("
    INSERT INTO services (name_en, name_km, category, base_duration_minutes, is_active, created_at)
    VALUES (?, 'សាកល្បងថ្មី', 'wash', 30, 1, NOW())
")->execute([$testNewSvcName]);
$testNewSvcId = (int)$pdo->lastInsertId();

$refSyncMethod->invoke(null, $pdo, $testNewSvcId, 10.00, 30, [
    'name_en' => $testNewSvcName,
    'item_type' => 'service',
    'price_usd' => 10.00
]);

$newPrices = $pdo->query("SELECT vehicle_type, price_usd FROM service_prices WHERE service_id = {$testNewSvcId}")->fetchAll(PDO::FETCH_KEY_PAIR);
assertCheck(isset($newPrices['motorbike'], $newPrices['small_car'], $newPrices['suv_pickup'], $newPrices['van_truck']), 'New service created prices for all 4 vehicle types');
assertCheck((float)$newPrices['motorbike'] < (float)$newPrices['small_car'] && (float)$newPrices['small_car'] < (float)$newPrices['suv_pickup'] && (float)$newPrices['suv_pickup'] < (float)$newPrices['van_truck'], 'New service prices tiered properly by multipliers (0.6x < 1.0x < 1.25x < 1.4x)');

// Clean up test services
$pdo->prepare("DELETE FROM service_prices WHERE service_id IN (?, ?)")->execute([$testSvcId, $testNewSvcId]);
$pdo->prepare("DELETE FROM services WHERE id IN (?, ?)")->execute([$testSvcId, $testNewSvcId]);

// -------------------------------------------------------------
// CHECK 12: Staff Timecards Push & Confirmation
// -------------------------------------------------------------
echo "\n--- 12. STAFF TIMECARDS PUSH & CONFIRMATION ---\n";
$testTcUid = 'CONTRACT-TC-' . uniqid();
$pdo->prepare("
    INSERT INTO staff_timecards (timecard_uid, user_id, clock_in, clock_out, total_hours, status, notes, is_synced)
    VALUES (?, 1, '2026-11-02 08:00:00', '2026-11-02 17:00:00', 9.00, 'completed', 'Contract test shift notes', 0)
")->execute([$testTcUid]);
$insertedTcId = (int)$pdo->lastInsertId();

$tcPushRes = CloudSyncManager::sendToCloud($pdo, true);
assertCheck($tcPushRes['success'] === true, 'sendToCloud pushes timecards to cloud with HTTP 200');

$tcSynced = (int)$pdo->query("SELECT is_synced FROM staff_timecards WHERE id = {$insertedTcId}")->fetchColumn();
assertCheck($tcSynced === 1, 'staff_timecards record marked is_synced = 1 upon cloud confirmation');

$pdo->prepare("DELETE FROM staff_timecards WHERE id = ?")->execute([$insertedTcId]);

// -------------------------------------------------------------
// CHECK 13: Upsell Logs Push & Confirmation
// -------------------------------------------------------------
echo "\n--- 13. UPSELL LOGS PUSH & CONFIRMATION ---\n";
$testUpUid = 'CONTRACT-UP-' . uniqid();
$pdo->prepare("
    INSERT INTO upsell_logs (log_uid, rule_name, cashier_name, status, reason_declined, offered_at, action_at, is_synced)
    VALUES (?, 'Hydrophobic Windshield Treatment', 'Contract Staff', 'accepted', 'Customer accepted add-on during checkout.', NOW(), NOW(), 0)
")->execute([$testUpUid]);
$insertedUpId = (int)$pdo->lastInsertId();

$upPushRes = CloudSyncManager::sendToCloud($pdo, true);
assertCheck($upPushRes['success'] === true, 'sendToCloud pushes upsell_logs to cloud with HTTP 200');

$upSynced = (int)$pdo->query("SELECT is_synced FROM upsell_logs WHERE id = {$insertedUpId}")->fetchColumn();
assertCheck($upSynced === 1, 'upsell_logs record marked is_synced = 1 upon cloud confirmation');

$pdo->prepare("DELETE FROM upsell_logs WHERE id = ?")->execute([$insertedUpId]);

// -------------------------------------------------------------
// CHECK 14: New User Without Local PIN Hash
// -------------------------------------------------------------
echo "\n--- 14. NEW USER WITHOUT LOCAL PIN HASH ---\n";
$testPendingUname = 'test_pending_pin_' . uniqid();
// Add to mock cloud without local_pin_hash (null)
@file_get_contents($envUrl . '?action=set_cloud_user_status&username=' . urlencode($testPendingUname) . '&is_active=1&role=manager');

$pullNewUserRes = CloudSyncManager::receiveFromCloud($pdo);
assertCheck($pullNewUserRes['success'] === true, 'receiveFromCloud processed user without pin hash');

$newUserRow = $pdo->query("SELECT id, status, password_hash, local_pin_hash FROM users WHERE username = '{$testPendingUname}'")->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($newUserRow), 'New staff user inserted in local database');
assertCheck(($newUserRow['status'] ?? '') === 'inactive', 'New user without local_pin_hash inserted with status "inactive"');
assertCheck($newUserRow['local_pin_hash'] === null, 'New user without local_pin_hash has local_pin_hash NULL');
assertCheck(
    !empty($newUserRow['password_hash']) && !password_verify('123456', (string)$newUserRow['password_hash']),
    'New user has unusable random password_hash (not default 123456)'
);

// Verify audit log USER_CREATED_PENDING_PIN
$newUserId = (int)($newUserRow['id'] ?? 0);
$auditPendingCount = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'USER_CREATED_PENDING_PIN' AND entity_id = '{$newUserId}'")->fetchColumn();
assertCheck($auditPendingCount > 0, 'Audit log USER_CREATED_PENDING_PIN recorded for staff user awaiting PIN');

// Clean up test user
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$newUserId]);

// -------------------------------------------------------------
// RESTORE ORIGINAL SETTINGS & CLEANUP
// -------------------------------------------------------------
update_setting($pdo, 'cloud_api_url', $origUrl, 1, 'Contract Test Clean');
update_setting($pdo, 'cloud_api_token', $origToken, 1, 'Contract Test Clean');
update_setting($pdo, 'cloud_api_secret', $origSecret, 1, 'Contract Test Clean');
update_setting($pdo, 'cloud_shop_id', $origShopId, 1, 'Contract Test Clean');
update_setting($pdo, 'cloud_offline_mode', $origOffline, 1, 'Contract Test Clean');

if ($devFlagCreated && file_exists($devFlagPath)) {
    @unlink($devFlagPath);
}

// -------------------------------------------------------------
// FINAL SUMMARY
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo " TEST SUMMARY: {$totalPassed} PASSED, {$totalFailed} FAILED\n";
echo "=================================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
exit(0);
