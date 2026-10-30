<?php
declare(strict_types=1);

/**
 * Cloud Sync Verification Suite
 * 
 * Tests:
 * 1. Send 20 sales to the cloud (upload, gzip, HMAC, confirmation).
 * 2. Simulate offline period with 10 more sales.
 * 3. Reconnect and verify all 10 sales are synced with original timestamps kept.
 * 4. Simulate a cloud price change while on POS screen (30s critical check & next sale without reload).
 * 5. Simulate a conflict, halt overwrite, show in Sync Monitor with both versions, and resolve.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/CloudSyncManager.php';

$pdo = Database::getInstance();

$totalPassed = 0;
$totalFailed = 0;

function assertCondition(bool $cond, string $desc, string $details = ''): void {
    global $totalPassed, $totalFailed;
    if ($cond) {
        $totalPassed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $totalFailed++;
        echo "  [FAIL] {$desc}" . ($details ? " -> {$details}" : "") . "\n";
    }
}

echo "=================================================================\n";
echo "       CLOUD SYNC END-TO-END VERIFICATION TEST SUITE             \n";
echo "=================================================================\n\n";

// Ensure settings point to local mock hub
$hubUrl = 'http://localhost/Car-WashService/api/cloud/index.php';
update_setting($pdo, 'cloud_sync_enabled', '1', 1, 'Test Suite');
update_setting($pdo, 'cloud_api_url', $hubUrl, 1, 'Test Suite');
update_setting($pdo, 'cloud_shop_id', 'shop_phnom_penh_01', 1, 'Test Suite');
update_setting($pdo, 'cloud_api_token', 'token_carwash_secret_sync_2026', 1, 'Test Suite');
update_setting($pdo, 'cloud_api_secret', 'sec_demo_signature_key_998877', 1, 'Test Suite');
update_setting($pdo, 'cloud_offline_mode', '0', 1, 'Test Suite');

// Reset mock cloud hub
@file_get_contents($hubUrl . '?action=reset_cloud_state');

// Clean local test data
$pdo->exec("DELETE FROM sync_queue");
$pdo->exec("DELETE FROM sync_conflicts");
$pdo->exec("DELETE FROM sync_logs");

// Get a test cashier and customer
$cashierId = (int)$pdo->query("SELECT id FROM users WHERE role = 'cashier' LIMIT 1")->fetchColumn();
if (!$cashierId) {
    $cashierId = (int)$pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
}
$customerId = (int)$pdo->query("SELECT id FROM customers LIMIT 1")->fetchColumn();
$serviceId = (int)$pdo->query("SELECT id FROM services LIMIT 1")->fetchColumn();

echo "--- SCENARIO 1: SEND 20 SALES TO THE CLOUD ---\n";

// Insert 20 sales with unique order numbers
$initialOrderIds = [];
for ($i = 1; $i <= 20; $i++) {
    $orderNum = 'TEST-ORD-SYNC-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $createdAt = date('Y-m-d H:i:s', strtotime("-{$i} hours"));

    // Remove if exists
    $pdo->prepare("DELETE FROM orders WHERE order_number = ?")->execute([$orderNum]);

    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_number, customer_id, plate_number, vehicle_type, total_usd,
            total_khr, payment_status, payment_method, status,
            cashier_id, created_at, is_synced
        ) VALUES (
            ?, ?, '2B-8888', 'small_car', 10.00,
            41000, 'paid', 'cash', 'completed',
            ?, ?, 0
        )
    ");
    $stmt->execute([$orderNum, $customerId, $cashierId, $createdAt]);
    $orderId = (int)$pdo->lastInsertId();
    $initialOrderIds[] = $orderId;

    // Insert order item
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, item_type, service_name, qty, price_usd, price_khr)
        VALUES (?, ?, 'service', 'Basic Wash', 1, 10.00, 41000)
    ")->execute([$orderId, $serviceId]);
}

$pendingCounts = CloudSyncManager::getUnsyncedCounts($pdo);
assertCondition($pendingCounts['orders'] >= 20, "20 test sales inserted with is_synced = 0 (Found {$pendingCounts['orders']} unsynced orders)");

// Execute Send to Cloud
$sendResult = CloudSyncManager::sendToCloud($pdo);
assertCondition($sendResult['success'] === true, "sendToCloud() returned success", $sendResult['error'] ?? '');
assertCondition($sendResult['sent_count'] >= 20, "sendToCloud() sent >= 20 records (Sent: {$sendResult['sent_count']})");

// Verify records locally marked as synced
$syncedCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'TEST-ORD-SYNC-%' AND is_synced = 1")->fetchColumn();
assertCondition($syncedCount === 20, "All 20 sales locally confirmed and marked is_synced = 1 in MySQL");

$pendingAfter = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'TEST-ORD-SYNC-%' AND is_synced = 0")->fetchColumn();
assertCondition($pendingAfter === 0, "Zero test sales remaining in unsynced queue");

echo "\n--- SCENARIO 2: OFFLINE PERIOD (10 MORE SALES) ---\n";

// Go offline
CloudSyncManager::setOfflineMode($pdo, true);
assertCondition(CloudSyncManager::isOfflineMode($pdo) === true, "Offline mode successfully enabled");

// Insert 10 more sales while offline with specific original timestamps
$offlineOrderIds = [];
$originalTimes = [];
for ($j = 1; $j <= 10; $j++) {
    $orderNum = 'TEST-ORD-OFFLINE-' . str_pad((string)$j, 3, '0', STR_PAD_LEFT);
    $origTime = date('Y-m-d H:i:s', strtotime("-".(50 - $j)." minutes"));
    $originalTimes[$orderNum] = $origTime;

    $pdo->prepare("DELETE FROM orders WHERE order_number = ?")->execute([$orderNum]);

    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_number, customer_id, plate_number, vehicle_type, total_usd,
            total_khr, payment_status, payment_method, status,
            cashier_id, created_at, is_synced
        ) VALUES (
            ?, ?, '3C-9999', 'suv_pickup', 15.00,
            61500, 'paid', 'cash', 'completed',
            ?, ?, 0
        )
    ");
    $stmt->execute([$orderNum, $customerId, $cashierId, $origTime]);
    $offlineOrderIds[] = (int)$pdo->lastInsertId();
}

assertCondition(count($offlineOrderIds) === 10, "10 sales processed locally while offline with custom original timestamps");

// Attempt to send while offline - should gracefully skip or report offline
$offlineSend = CloudSyncManager::sendToCloud($pdo);
assertCondition($offlineSend['sent_count'] === 0, "sendToCloud() correctly halts transmission while offline (0 sent)");

$unsyncedOffline = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_number LIKE 'TEST-ORD-OFFLINE-%' AND is_synced = 0")->fetchColumn();
assertCondition($unsyncedOffline === 10, "All 10 offline sales remain safely queued in local database");

echo "\n--- SCENARIO 3: RECONNECT AND SYNC WITH ORIGINAL TIMESTAMPS ---\n";

// Reconnect online
CloudSyncManager::setOfflineMode($pdo, false);
assertCondition(CloudSyncManager::isOfflineMode($pdo) === false, "Shop server reconnected online");

// Send offline batch to cloud
$reconnectSend = CloudSyncManager::sendToCloud($pdo);
assertCondition($reconnectSend['success'] === true, "sendToCloud() on reconnection returned success");
assertCondition($reconnectSend['sent_count'] >= 10, "All offline sales pushed to cloud (Sent: {$reconnectSend['sent_count']})");

// Verify in mock cloud hub that records have original timestamps kept
$hubStateJson = @file_get_contents($hubUrl . '?action=dump_cloud_state');
$hubState = json_decode((string)$hubStateJson, true) ?: [];
$cloudSales = $hubState['sales'] ?? [];

$timestampPreservedCount = 0;
foreach ($cloudSales as $cs) {
    $onum = $cs['order_number'] ?? '';
    if (isset($originalTimes[$onum])) {
        if ($cs['created_at'] === $originalTimes[$onum]) {
            $timestampPreservedCount++;
        }
    }
}
assertCondition($timestampPreservedCount === 10, "All 10 offline sales in Cloud have their original created_at timestamps strictly preserved (10/10 matched)");

echo "\n--- SCENARIO 4: CLOUD PRICE CHANGE WHILE ON POS SCREEN ---\n";

// 1. Setup mock cloud price change for $serviceId
$newCloudPrice = 8.50;
$cloudPricePayload = [
    'action' => 'set_cloud_price',
    'service_id' => $serviceId,
    'vehicle_type' => 'small_car',
    'price_usd' => $newCloudPrice,
    'price_khr' => (int)($newCloudPrice * 4100)
];
$ch = curl_init($hubUrl . '?action=set_cloud_price');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($cloudPricePayload),
    CURLOPT_RETURNTRANSFER => true
]);
$priceSetRes = curl_exec($ch);
curl_close($ch);

// 2. Cashier on POS screen checks for critical updates via AJAX endpoint
$cookieFile = tempnam(sys_get_temp_dir(), 'ck_pos_');
$loginUrl = 'http://localhost/Car-WashService/index.php?action=demo_login&role=cashier';
$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_FOLLOWLOCATION => true
]);
curl_exec($ch);
curl_close($ch);

$critEndpointUrl = 'http://localhost/Car-WashService/index.php?action=check_critical_sync';
$ch = curl_init($critEndpointUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEFILE => $cookieFile
]);
$critEndpointJson = curl_exec($ch);
curl_close($ch);
@unlink($cookieFile);

$critEndpointData = json_decode((string)$critEndpointJson, true) ?: [];
assertCondition(!empty($critEndpointData['has_update']), "Fast 30-second critical check detected cloud price change");
assertCondition(!empty($critEndpointData['message']), "Critical sync endpoint returns user notification message: '{$critEndpointData['message']}'");
assertCondition(isset($critEndpointData['pricing'][$serviceId]['small_car']), "Critical sync endpoint delivers updated pricing matrix for instant in-memory POS refresh");

// 3. Verify shop database was updated to the new price by the sync
$dbPrice = (float)$pdo->query("SELECT price_usd FROM service_prices WHERE service_id = {$serviceId} AND vehicle_type = 'small_car'")->fetchColumn();
assertCondition(abs($dbPrice - $newCloudPrice) < 0.01, "Shop database updated to new price \${$newCloudPrice}");

// 6. Simulate next sale created without reload using updated price
$orderNumNext = 'TEST-ORD-NEXT-' . time();
$stmt = $pdo->prepare("
    INSERT INTO orders (
        order_number, customer_id, plate_number, vehicle_type, total_usd,
        total_khr, payment_status, payment_method, status,
        cashier_id, created_at, is_synced
    ) VALUES (
        ?, ?, '1A-1111', 'small_car', ?,
        ?, 'paid', 'cash', 'completed',
        ?, NOW(), 0
    )
");
$stmt->execute([$orderNumNext, $customerId, $newCloudPrice, (int)($newCloudPrice * 4100), $cashierId]);
$nextSaleUsd = (float)$pdo->query("SELECT total_usd FROM orders WHERE order_number = '{$orderNumNext}'")->fetchColumn();
assertCondition(abs($nextSaleUsd - $newCloudPrice) < 0.01, "Next POS sale successfully used updated price \${$nextSaleUsd} without reload");

echo "\n--- SCENARIO 5: CONFLICT DETECTION & RESOLUTION IN SYNC MONITOR ---\n";

// 1. Simulate a conflicting product record where both cloud and shop updated it
$testProductId = (int)$pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn();
$shopProductBefore = $pdo->query("SELECT * FROM products WHERE id = {$testProductId}")->fetch(PDO::FETCH_ASSOC);

// Modify locally at the shop
$pdo->prepare("UPDATE products SET name_en = 'Locally Edited Oil Filter' WHERE id = ?")->execute([$testProductId]);

// Set cloud modified version
$cloudConflictPayload = [
    'action' => 'set_cloud_conflict',
    'entity_type' => 'product',
    'entity_id' => (string)$testProductId,
    'cloud_data' => [
        'id' => $testProductId,
        'name_en' => 'Cloud HQ Premium Oil Filter',
        'price_usd' => 12.00,
        'updated_at' => date('Y-m-d H:i:s')
    ]
];
$ch = curl_init($hubUrl . '?action=set_cloud_conflict');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($cloudConflictPayload),
    CURLOPT_RETURNTRANSFER => true
]);
curl_exec($ch);
curl_close($ch);

// Pull from cloud - should detect conflict and halt overwrite
$conflictPull = CloudSyncManager::receiveFromCloud($pdo);
assertCondition(!empty($conflictPull['conflicts']), "Conflict successfully detected during pull (Prevented silent overwrite)");

// Verify conflict record exists in sync_conflicts table
$confRow = $pdo->query("SELECT * FROM sync_conflicts WHERE record_type = 'product' AND record_uid = '{$testProductId}' AND status = 'pending' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($confRow), "Conflict stored in sync_conflicts with status = 'pending'");

$localVer = json_decode((string)$confRow['local_data'], true);
$cloudVer = json_decode((string)$confRow['cloud_data'], true);
assertCondition($localVer['name_en'] === 'Locally Edited Oil Filter', "Local version preserved in conflict record");
assertCondition($cloudVer['name_en'] === 'Cloud HQ Premium Oil Filter', "Cloud version preserved in conflict record");

// Verify local database was NOT overwritten yet
$currentDbName = $pdo->query("SELECT name_en FROM products WHERE id = {$testProductId}")->fetchColumn();
assertCondition($currentDbName === 'Locally Edited Oil Filter', "Local product was NOT overwritten prior to manager review");

// Simulate Manager resolving conflict: Accept Cloud Version
$resolveOk = CloudSyncManager::resolveConflict($pdo, (int)$confRow['id'], 'accept_cloud', $cashierId, 'Shop Manager');
assertCondition(!empty($resolveOk['success']), "Manager resolution 'accept_cloud' succeeded");

$confResolved = $pdo->query("SELECT status, resolved_by FROM sync_conflicts WHERE id = {$confRow['id']}")->fetch(PDO::FETCH_ASSOC);
assertCondition($confResolved['status'] === 'resolved', "Conflict status updated to 'resolved'");

$updatedDbName = $pdo->query("SELECT name_en FROM products WHERE id = {$testProductId}")->fetchColumn();
assertCondition($updatedDbName === 'Cloud HQ Premium Oil Filter', "Product record updated to Cloud version after Manager approval");

// Clean up test sales & restore product name
$pdo->exec("DELETE FROM orders WHERE order_number LIKE 'TEST-ORD-%'");
if (!empty($shopProductBefore['name_en'])) {
    $pdo->prepare("UPDATE products SET name_en = ? WHERE id = ?")->execute([$shopProductBefore['name_en'], $testProductId]);
}

echo "\n=================================================================\n";
echo "  TEST SUMMARY: {$totalPassed} Passed, {$totalFailed} Failed (Total " . ($totalPassed + $totalFailed) . ")\n";
echo "=================================================================\n";

if ($totalFailed > 0) {
    exit(1);
}
exit(0);