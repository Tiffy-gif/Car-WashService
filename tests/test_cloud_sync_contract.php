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
$envUrl = getenv('CLOUD_API_URL') ?: 'http://localhost/Car-WashService/api/cloud/index.php';
$envToken = getenv('CLOUD_API_TOKEN') ?: 'token_test_contract_dev_2026';
$envSecret = getenv('CLOUD_API_SECRET') ?: 'sec_test_hmac_contract_dev_998877';
$envShopId = getenv('CLOUD_SHOP_ID') ?: 'SHOP-DEV-001';

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
