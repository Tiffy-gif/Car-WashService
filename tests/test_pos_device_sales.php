<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

echo "========================================================\n";
echo "   POS MULTI-DEVICE REPORTING & RECONCILIATION TEST\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $desc) {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] {$desc}\n";
        $passCount++;
    } else {
        echo " [FAIL] {$desc}\n";
        $failCount++;
    }
}

// 1. SETUP: Create 3 active devices + 1 idle device with distinct keys
echo "1. Setting up test devices...\n";

$devicesToSetup = [
    ['uid' => 'POS-TEST-DEV1', 'name' => 'Counter Tablet 1 (Bay 1)', 'key' => 'pos_key_test_alpha_01', 'loc' => 'Counter 1'],
    ['uid' => 'POS-TEST-DEV2', 'name' => 'Counter Tablet 2 (Bay 2)', 'key' => 'pos_key_test_beta_02',  'loc' => 'Counter 2'],
    ['uid' => 'POS-TEST-DEV3', 'name' => 'Mobile Handheld Terminal',  'key' => 'pos_key_test_gamma_03', 'loc' => 'Express Lane'],
    ['uid' => 'POS-TEST-IDLE', 'name' => 'Spare Backup Tablet',       'key' => 'pos_key_test_delta_04', 'loc' => 'Storage']
];

$deviceIds = [];

foreach ($devicesToSetup as $d) {
    $existing = $pdo->prepare("SELECT id FROM pos_devices WHERE device_uid = ?");
    $existing->execute([$d['uid']]);
    $id = $existing->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE pos_devices SET device_name = ?, api_key = ?, location = ?, status = 'active' WHERE id = ?")
            ->execute([$d['name'], $d['key'], $d['loc'], $id]);
        $deviceIds[$d['uid']] = (int)$id;
    } else {
        $pdo->prepare("INSERT INTO pos_devices (device_uid, device_name, api_key, location, status) VALUES (?, ?, ?, ?, 'active')")
            ->execute([$d['uid'], $d['name'], $d['key'], $d['loc']]);
        $deviceIds[$d['uid']] = (int)$pdo->lastInsertId();
    }
}

$dev1Id = $deviceIds['POS-TEST-DEV1'];
$dev2Id = $deviceIds['POS-TEST-DEV2'];
$dev3Id = $deviceIds['POS-TEST-DEV3'];
$idleId = $deviceIds['POS-TEST-IDLE'];

assertCondition($dev1Id > 0 && $dev2Id > 0 && $dev3Id > 0 && $idleId > 0, "4 distinct test devices registered");

// Clean existing orders for these test devices today to ensure pristine numbers
$pdo->query("DELETE FROM orders WHERE pos_device_id IN ({$dev1Id}, {$dev2Id}, {$dev3Id}, {$idleId})");
$pdo->query("DELETE FROM pos_closings WHERE pos_device_id IN ({$dev1Id}, {$dev2Id}, {$dev3Id}, {$idleId})");

// 2. SEED ORDERS
echo "\n2. Generating test orders across devices and web...\n";

// Staff users
$cashier1 = 2; // e.g. cashier
$cashier2 = 3; // e.g. washer or second cashier
$owner = 1;

// Device 1:
// - Order 1: Cash paid $10.00 (Cashier 1)
// - Order 2: ABA paid $15.00 (Cashier 2)
// - Order 3: Cancelled $8.00 (Cashier 1)
$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, paid_at, created_at)
    VALUES ('ORD-TEST-D1-1', ?, 'Counter Tablet 1 (Bay 1)', ?, 'pos_app', 'small_car', 10.00, 0.00, 10.00, 41000, 'cash', 'paid', 'paid', NOW(), NOW())
")->execute([$dev1Id, $cashier1]);

$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, paid_at, created_at)
    VALUES ('ORD-TEST-D1-2', ?, 'Counter Tablet 1 (Bay 1)', ?, 'pos_app', 'small_car', 17.00, 2.00, 15.00, 61500, 'aba', 'paid', 'paid', NOW(), NOW())
")->execute([$dev1Id, $cashier2]);

$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, created_at)
    VALUES ('ORD-TEST-D1-3', ?, 'Counter Tablet 1 (Bay 1)', ?, 'pos_app', 'small_car', 8.00, 0.00, 8.00, 32800, 'cash', 'cancelled', 'unpaid', NOW())
")->execute([$dev1Id, $cashier1]);

// Device 2:
// - Order 1: KHQR paid $25.00 (Cashier 1)
// - Order 2: Unpaid (Pay Later) $20.00 (Cashier 1)
$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, paid_at, created_at)
    VALUES ('ORD-TEST-D2-1', ?, 'Counter Tablet 2 (Bay 2)', ?, 'pos_app', 'suv_pickup', 25.00, 0.00, 25.00, 102500, 'khqr', 'paid', 'paid', NOW(), NOW())
")->execute([$dev2Id, $cashier1]);

$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, created_at)
    VALUES ('ORD-TEST-D2-2', ?, 'Counter Tablet 2 (Bay 2)', ?, 'pos_app', 'suv_pickup', 20.00, 0.00, 20.00, 82000, 'unpaid', 'waiting', 'unpaid', NOW())
")->execute([$dev2Id, $cashier1]);

// Device 3:
// - Order 1: Cash paid $30.00 (Owner)
$pdo->prepare("
    INSERT INTO orders (order_number, pos_device_id, pos_device_name, cashier_id, source, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, payment_method, status, payment_status, paid_at, created_at)
    VALUES ('ORD-TEST-D3-1', ?, 'Mobile Handheld Terminal', ?, 'pos_app', 'van_truck', 30.00, 0.00, 30.00, 123000, 'cash', 'paid', 'paid', NOW(), NOW())
")->execute([$dev3Id, $owner]);

// Device 4 (Idle):
// - 0 orders

echo "Orders created successfully.\n";

// 3. VERIFY REPORTS RECONCILIATION
echo "\n3. Testing Reports & Profits reconciliation queries...\n";

// Device 1 expected: Paid = 2, Gross = 27.00, Disc = 2.00, Net = 25.00, Cash = 10.00, ABA = 15.00, KHQR = 0.00, Cancelled = 1
$d1Stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN subtotal_usd ELSE 0 END), 0) as gross,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN discount_usd ELSE 0 END), 0) as disc,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_usd ELSE 0 END), 0) as net,
        COALESCE(SUM(CASE WHEN status = 'paid' AND payment_method = 'cash' THEN total_usd ELSE 0 END), 0) as cash,
        COALESCE(SUM(CASE WHEN status = 'paid' AND payment_method = 'aba' THEN total_usd ELSE 0 END), 0) as aba,
        COALESCE(SUM(CASE WHEN status = 'paid' AND payment_method = 'khqr' THEN total_usd ELSE 0 END), 0) as khqr,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
    FROM orders
    WHERE pos_device_id = ?
");
$d1Stmt->execute([$dev1Id]);
$d1Res = $d1Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition((int)$d1Res['paid_count'] === 2, "Device 1 paid orders count = 2");
assertCondition(abs((float)$d1Res['gross'] - 27.00) < 0.01, "Device 1 gross sales = $27.00");
assertCondition(abs((float)$d1Res['disc'] - 2.00) < 0.01, "Device 1 discounts = $2.00");
assertCondition(abs((float)$d1Res['net'] - 25.00) < 0.01, "Device 1 net sales = $25.00");
assertCondition(abs((float)$d1Res['cash'] - 10.00) < 0.01, "Device 1 cash = $10.00");
assertCondition(abs((float)$d1Res['aba'] - 15.00) < 0.01, "Device 1 ABA = $15.00");
assertCondition((int)$d1Res['cancelled'] === 1, "Device 1 cancelled count = 1");

// Device 2 expected: Paid = 1, Net = 25.00, KHQR = 25.00, Unpaid = 20.00
$d2Stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_usd ELSE 0 END), 0) as net,
        COALESCE(SUM(CASE WHEN status = 'paid' AND payment_method = 'khqr' THEN total_usd ELSE 0 END), 0) as khqr,
        COALESCE(SUM(CASE WHEN status != 'cancelled' AND payment_status = 'unpaid' THEN total_usd ELSE 0 END), 0) as unpaid
    FROM orders
    WHERE pos_device_id = ?
");
$d2Stmt->execute([$dev2Id]);
$d2Res = $d2Stmt->fetch(PDO::FETCH_ASSOC);

assertCondition((int)$d2Res['paid_count'] === 1, "Device 2 paid count = 1");
assertCondition(abs((float)$d2Res['net'] - 25.00) < 0.01, "Device 2 net sales = $25.00");
assertCondition(abs((float)$d2Res['khqr'] - 25.00) < 0.01, "Device 2 KHQR = $25.00");
assertCondition(abs((float)$d2Res['unpaid'] - 20.00) < 0.01, "Device 2 unpaid amount = $20.00");

// Check overall shop reconciliation query
$allDevSumStmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_usd ELSE 0 END), 0) as total_shop_net
    FROM orders
    WHERE DATE(COALESCE(paid_at, created_at)) = CURDATE()
");
$shopTotalToday = (float)$allDevSumStmt->fetchColumn();

// Devices sum + Web sum
$devSumStmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_usd ELSE 0 END), 0) as devices_sum
    FROM orders
    WHERE pos_device_id IS NOT NULL AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
");
$devSum = (float)$devSumStmt->fetchColumn();

$webSumStmt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_usd ELSE 0 END), 0) as web_sum
    FROM orders
    WHERE (pos_device_id IS NULL OR pos_device_id = 0 OR source = 'web') AND DATE(COALESCE(paid_at, created_at)) = CURDATE()
");
$webSum = (float)$webSumStmt->fetchColumn();

assertCondition(abs(($devSum + $webSum) - $shopTotalToday) < 0.001, "Devices Sum ($devSum) + Web Sum ($webSum) EQUALS Shop Total ($shopTotalToday) EXACTLY");

// 4. TEST POS API: get_daily_report (Strict Device Isolation)
echo "\n4. Testing POS API: get_daily_report isolation...\n";

// Function to call API directly via internal simulation
function callPosApi(string $action, string $apiKey, array $payload = []): array {
    $url = API_BASE_URL . '/api/pos/?action=' . urlencode($action);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Device-Key: ' . $apiKey
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string)$res, true)];
}

$apiRes1 = callPosApi('get_daily_report', 'pos_key_test_alpha_01', ['opening_cash' => 50.00]);
assertCondition($apiRes1['status'] === 200, "Device 1 get_daily_report HTTP 200");
$rep1 = $apiRes1['json']['report'] ?? [];

assertCondition((int)($rep1['paid_orders_count'] ?? 0) === 2, "Device 1 report: paid orders = 2");
assertCondition(abs((float)($rep1['total_sales'] ?? 0) - 25.00) < 0.01, "Device 1 report: total sales = $25.00 (Isolated from other devices)");
assertCondition(abs((float)($rep1['cash_sales'] ?? 0) - 10.00) < 0.01, "Device 1 report: cash sales = $10.00");
assertCondition(abs((float)($rep1['aba_sales'] ?? 0) - 15.00) < 0.01, "Device 1 report: aba sales = $15.00");
assertCondition(abs((float)($rep1['expected_cash'] ?? 0) - 60.00) < 0.01, "Device 1 report: expected cash = $60.00 ($50 opening + $10 cash)");

// Device 2 get_daily_report
$apiRes2 = callPosApi('get_daily_report', 'pos_key_test_beta_02', ['opening_cash' => 40.00]);
$rep2 = $apiRes2['json']['report'] ?? [];
assertCondition(abs((float)($rep2['total_sales'] ?? 0) - 25.00) < 0.01, "Device 2 report: total sales = $25.00 (KHQR only)");
assertCondition(abs((float)($rep2['cash_sales'] ?? 0) - 0.00) < 0.01, "Device 2 report: cash sales = $0.00");
assertCondition(abs((float)($rep2['khqr_sales'] ?? 0) - 25.00) < 0.01, "Device 2 report: KHQR sales = $25.00");
assertCondition(abs((float)($rep2['expected_cash'] ?? 0) - 40.00) < 0.01, "Device 2 report: expected cash = $40.00 ($40 opening + $0 cash)");

// 5. TEST POS API: save_pos_closing (Shift Close & Reconciliation)
echo "\n5. Testing POS API: save_pos_closing...\n";

// Device 1 submits closing: opening = $50, counted = $58 (Short $2.00)
$closePayload = [
    'opening_cash' => 50.00,
    'counted_cash' => 58.00,
    'cashier_id'   => $cashier1,
    'note'         => 'Test shift close - drawer missing $2 change'
];
$closeRes = callPosApi('save_pos_closing', 'pos_key_test_alpha_01', $closePayload);
assertCondition($closeRes['status'] === 200 && ($closeRes['json']['success'] ?? false), "save_pos_closing returned success");

$savedClosing = $closeRes['json']['closing'] ?? [];
assertCondition(abs((float)($savedClosing['expected_cash'] ?? 0) - 60.00) < 0.01, "Saved closing expected cash = $60.00");
assertCondition(abs((float)($savedClosing['counted_cash'] ?? 0) - 58.00) < 0.01, "Saved closing counted cash = $58.00");
assertCondition(abs((float)($savedClosing['difference'] ?? 0) - (-2.00)) < 0.01, "Saved closing difference = -$2.00 (Shortage)");

// Verify database record in pos_closings
$dbClose = $pdo->prepare("SELECT * FROM pos_closings WHERE pos_device_id = ? ORDER BY id DESC LIMIT 1");
$dbClose->execute([$dev1Id]);
$dbRow = $dbClose->fetch(PDO::FETCH_ASSOC);

assertCondition(!empty($dbRow), "pos_closings table contains row for Device 1");
$dbDiff = (float)($dbRow['difference_usd'] ?? $dbRow['difference'] ?? 0);
assertCondition(abs($dbDiff - (-2.00)) < 0.01, "pos_closings database difference is -$2.00");

// 6. TEST WARNINGS (Shared Key & Idle Device)
echo "\n6. Testing system integrity warnings...\n";

// Warning check: Idle device (POS-TEST-IDLE has 0 orders)
$neverOrderedStmt = $pdo->query("
    SELECT pd.id, pd.device_name, pd.device_uid
    FROM pos_devices pd
    LEFT JOIN orders o ON o.pos_device_id = pd.id
    WHERE pd.id = {$idleId} AND o.id IS NULL
");
$idleFound = $neverOrderedStmt->fetch(PDO::FETCH_ASSOC);
assertCondition(!empty($idleFound), "Idle device detected by 0-order alert query");

// Warning check: Duplicate key detection query logic
$sharedKeyQueryCheck = $pdo->query("
    SELECT COUNT(*) FROM (
        SELECT api_key, COUNT(*) as c 
        FROM pos_devices 
        GROUP BY api_key 
        HAVING c > 1
    ) as dupes
")->fetchColumn();
assertCondition(is_numeric($sharedKeyQueryCheck), "Duplicate key query executes correctly against pos_devices");

echo "\n========================================================\n";
echo "   SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo ">>> ALL TESTS PASSED SUCCESSFULLY! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
