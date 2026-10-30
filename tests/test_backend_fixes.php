<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Loyalty.php';
require_once __DIR__ . '/../core/Auth.php';

$pdo = Database::getInstance();

echo "========================================================\n";
echo "    BACKEND FIXES VERIFICATION TEST SUITE (8 ITEMS)     \n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passCount++;
    } else {
        echo " [FAIL] {$title} - {$details}\n";
        $failCount++;
    }
}

// ----------------------------------------------------
// Setup test environment
// ----------------------------------------------------
// Get or create test device
$testKey = 'test_backend_fix_device_key_' . bin2hex(random_bytes(4));
$pdo->prepare("INSERT INTO pos_devices (device_name, device_uid, api_key, location, status) VALUES ('Test Terminal', ?, ?, 'Test Bay', 'active')")
    ->execute(['UID-' . uniqid(), $testKey]);
$devId = (int)$pdo->lastInsertId();

// Helper to make simulated API calls
function callPosApi(string $key, string $action, array $payload): array {
    $apiUrl = API_BASE_URL . '/api/pos/index.php?action=' . urlencode($action);
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Device-Key: ' . $key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return ['status' => $code, 'data' => $data, 'raw' => $resp];
}

// Get user IDs
$ownerUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'owner' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashierUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'cashier' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($ownerUser) && !empty($cashierUser), "Users initialized (Owner ID: {$ownerUser['id']}, Cashier ID: {$cashierUser['id']})");

// ====================================================
// ITEM 1: LOYALTY POINTS (Overdraft Prevention & History)
// ====================================================
echo "\n--- 1. Testing Loyalty Points ---\n";
// Create clean customer with 50 points
$custName = "Loyalty Test " . uniqid();
$pdo->prepare("INSERT INTO customers (name, phone, loyalty_points, lifetime_points, membership_level, status) VALUES (?, '012999000', 50, 50, 'standard', 'active')")
    ->execute([$custName]);
$testCustId = (int)$pdo->lastInsertId();

// 1.1 Try to create order using 60 points when customer only has 50 -> MUST REJECT
$overdraftRes = callPosApi($testKey, 'create_order', [
    'customer_id' => $testCustId,
    'plate_number' => '2AB-0001',
    'points_redeemed' => 60,
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($overdraftRes['status'] === 400 && str_contains($overdraftRes['data']['message'] ?? '', 'only has 50 points'),
    "Overdraft rejected when customer has 50 pts but attempts to redeem 60 pts",
    json_encode($overdraftRes['data']));

// 1.2 Create first UNPAID order using 30 points -> Customer should immediately drop to 20 points!
$unpaidOrd1Res = callPosApi($testKey, 'create_order', [
    'customer_id' => $testCustId,
    'plate_number' => '2AB-0001',
    'points_redeemed' => 30,
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($unpaidOrd1Res['status'] === 200, "Unpaid order #1 created using 30 points");
$unpaidOrd1Id = (int)$unpaidOrd1Res['data']['order']['id'];

// Check customer balance immediately in DB
$cBal1 = (int)$pdo->query("SELECT loyalty_points FROM customers WHERE id = {$testCustId}")->fetchColumn();
assertTest($cBal1 === 20, "Customer balance immediately reduced from 50 to 20 pts upon unpaid order placement");

// Check loyalty_transactions history
$tx1 = $pdo->query("SELECT type, points, balance_after FROM loyalty_transactions WHERE order_id = {$unpaidOrd1Id} AND type = 'redeem'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($tx1) && (int)$tx1['points'] === -30 && (int)$tx1['balance_after'] === 20,
    "loyalty_transactions recorded redeem entry of -30 pts with balance_after = 20");

// 1.3 Try to create second UNPAID order requesting 25 points -> MUST REJECT because only 20 pts remain!
$unpaidOrd2Res = callPosApi($testKey, 'create_order', [
    'customer_id' => $testCustId,
    'plate_number' => '2AB-0001',
    'points_redeemed' => 25,
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($unpaidOrd2Res['status'] === 400 && str_contains($unpaidOrd2Res['data']['message'] ?? '', 'only has 20 points'),
    "Second unpaid order rejected: customer cannot exceed remaining 20 pts across multiple orders",
    json_encode($unpaidOrd2Res['data']));

// 1.4 Cancel unpaid order #1 -> Points must be immediately refunded and recorded as 'reversal' in history
$cancelUnpaidRes = callPosApi($testKey, 'cancel_order', [
    'order_id' => $unpaidOrd1Id,
    'reason' => 'Customer cancelled service before washing',
    'staff_id' => $cashierUser['id']
]);
assertTest($cancelUnpaidRes['status'] === 200, "Unpaid order #1 cancelled");

$cBalAfterCancel = (int)$pdo->query("SELECT loyalty_points FROM customers WHERE id = {$testCustId}")->fetchColumn();
assertTest($cBalAfterCancel === 50, "Customer balance refunded back to 50 pts upon order cancellation");

$revTx = $pdo->query("SELECT type, points, balance_after FROM loyalty_transactions WHERE order_id = {$unpaidOrd1Id} AND type = 'reversal'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($revTx) && (int)$revTx['points'] === 30 && (int)$revTx['balance_after'] === 50,
    "loyalty_transactions recorded reversal refund of +30 pts with balance_after = 50");

// ====================================================
// ITEM 2: CLOSED DAY ENFORCEMENT
// ====================================================
echo "\n--- 2. Testing Closed Day Enforcement ---\n";
$today = date('Y-m-d');
// Close the day on test device
$closeRes = callPosApi($testKey, 'save_pos_closing', [
    'date' => $today,
    'opening_cash' => 50.00,
    'counted_cash_usd' => 50.00,
    'counted_cash_khr' => 0,
    'staff_id' => $cashierUser['id'],
    'notes' => 'End of test shift'
]);
assertTest($closeRes['status'] === 200, "POS Day closed successfully for test device");

// Try to create order on closed device -> MUST BE BLOCKED (HTTP 403, DAY_CLOSED)
$blockedCreate = callPosApi($testKey, 'create_order', [
    'customer_id' => $testCustId,
    'plate_number' => '2AB-0002',
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($blockedCreate['status'] === 403 && ($blockedCreate['data']['error'] ?? '') === 'DAY_CLOSED',
    "create_order blocked on closed device with HTTP 403 DAY_CLOSED",
    json_encode($blockedCreate['data']));

// Try to pay an existing order on closed device -> MUST BE BLOCKED
$blockedPay = callPosApi($testKey, 'sync_order_status', [
    'order_id' => $unpaidOrd1Id,
    'status' => 'paid',
    'payment_method' => 'cash',
    'amount_tendered_usd' => 10.00,
    'staff_id' => $cashierUser['id']
]);
assertTest($blockedPay['status'] === 403 && ($blockedPay['data']['error'] ?? '') === 'DAY_CLOSED',
    "pay_order / sync_order_status blocked on closed device with HTTP 403 DAY_CLOSED",
    json_encode($blockedPay['data']));

// ====================================================
// ITEM 3: SERVER-SIDE ROLE ENFORCEMENT
// ====================================================
echo "\n--- 3. Testing Server-side Role Check ---\n";
// 3.1 Cashier attempts to reopen closed day by sending fake staff_role='owner' but their staff_id is Cashier -> MUST REJECT!
$spoofReopen = callPosApi($testKey, 'reopen_daily_close', [
    'date' => $today,
    'staff_id' => $cashierUser['id'],
    'staff_role' => 'owner' // SPOOFED! Real role in DB is cashier
]);
assertTest($spoofReopen['status'] === 403 && str_contains($spoofReopen['data']['message'] ?? '', 'Only Owner or Manager'),
    "Reopen rejected when Cashier attempts to spoof staff_role='owner'",
    json_encode($spoofReopen['data']));

// 3.2 Real Owner reopens day -> MUST SUCCEED
$ownerReopen = callPosApi($testKey, 'reopen_daily_close', [
    'date' => $today,
    'staff_id' => $ownerUser['id'],
    'staff_role' => 'cashier' // Even if client says cashier, server checks DB and knows they are Owner!
]);
assertTest($ownerReopen['status'] === 200, "Reopen succeeds with real Owner staff ID in database");

// After reopen, creating order works again!
$postReopenCreate = callPosApi($testKey, 'create_order', [
    'customer_id' => $testCustId,
    'plate_number' => '2AB-0003',
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'is_paid' => true,
    'payment_method' => 'cash',
    'amount_tendered_usd' => 10.00,
    'staff_id' => $cashierUser['id']
]);
assertTest($postReopenCreate['status'] === 200, "create_order succeeds immediately after day is reopened");
$paidOrdId = (int)$postReopenCreate['data']['order']['id'];

// 3.3 Cashier attempts to cancel PAID order by sending fake staff_role='owner' -> MUST REJECT!
$spoofCancelPaid = callPosApi($testKey, 'cancel_order', [
    'order_id' => $paidOrdId,
    'reason' => 'Customer wants refund',
    'staff_id' => $cashierUser['id'],
    'staff_role' => 'owner' // SPOOFED!
]);
assertTest($spoofCancelPaid['status'] === 403 && str_contains($spoofCancelPaid['data']['message'] ?? '', 'Only Owner or Manager'),
    "Cancel paid order rejected when Cashier attempts to spoof staff_role='owner'",
    json_encode($spoofCancelPaid['data']));

// 3.4 Real Owner cancels PAID order -> MUST SUCCEED and record who did it in history
$ownerCancelPaid = callPosApi($testKey, 'cancel_order', [
    'order_id' => $paidOrdId,
    'reason' => 'Defective wash approved by management',
    'staff_id' => $ownerUser['id']
]);
assertTest($ownerCancelPaid['status'] === 200, "Real Owner cancels paid order successfully");

$ordCancelHistory = $pdo->query("SELECT cancel_reason, notes FROM orders WHERE id = {$paidOrdId}")->fetch(PDO::FETCH_ASSOC);
assertTest(str_contains($ordCancelHistory['cancel_reason'], $ownerUser['full_name']) && str_contains($ordCancelHistory['cancel_reason'], 'Owner'),
    "Cancel history records staff name and Owner role: '{$ordCancelHistory['cancel_reason']}'");

// ====================================================
// ITEM 4: CLOSE DAY SCREEN (Status, Reopen & Oil follow-up)
// ====================================================
echo "\n--- 4. Testing Close Day Status, Reopen & Oil Follow-up ---\n";
// 4.1 get_daily_close_status
$statusRes = callPosApi($testKey, 'get_daily_close_status', ['date' => $today]);
assertTest($statusRes['status'] === 200 && isset($statusRes['data']['is_closed']),
    "get_daily_close_status returns valid response with boolean 'is_closed'");

// 4.2 Oil Follow-up (get_oil_reminders)
$oilRemindersRes = callPosApi($testKey, 'get_oil_reminders', ['filter' => 'all']);
assertTest($oilRemindersRes['status'] === 200 && isset($oilRemindersRes['data']['reminders']),
    "get_oil_reminders returns vehicles needing oil follow-up");

// 4.3 Oil Follow-up Contact Update (update_oil_contact)
$vId = (int)$pdo->query("SELECT id FROM vehicles LIMIT 1")->fetchColumn();
$updateOilRes = callPosApi($testKey, 'update_oil_contact', [
    'vehicle_id' => $vId,
    'action_type' => 'snooze_7',
    'notes' => 'Customer travelling, call back next Monday'
]);
assertTest($updateOilRes['status'] === 200 && !empty($updateOilRes['data']['snooze_until']),
    "update_oil_contact records follow-up and sets snooze_until date: {$updateOilRes['data']['snooze_until']}");

// ====================================================
// ITEM 5: DISCOUNT VALIDATION
// ====================================================
echo "\n--- 5. Testing Discount Validation ---\n";
// 5.1 create_order with discount greater than subtotal -> MUST REJECT
$badDiscountRes = callPosApi($testKey, 'create_order', [
    'plate_number' => '2AB-0004',
    'discount_usd' => 50.00, // Subtotal is $3.50, discount is $50.00
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($badDiscountRes['status'] === 400 && ($badDiscountRes['data']['error'] ?? '') === 'DISCOUNT_EXCEEDS_TOTAL',
    "create_order rejects discount ($50.00) exceeding order total ($3.50)",
    json_encode($badDiscountRes['data']));

// 5.2 Negative discount -> MUST REJECT
$negDiscountRes = callPosApi($testKey, 'create_order', [
    'plate_number' => '2AB-0005',
    'discount_usd' => -5.00,
    'items' => [['id' => 1, 'type' => 'service', 'qty' => 1]],
    'staff_id' => $cashierUser['id']
]);
assertTest($negDiscountRes['status'] === 400, "create_order rejects negative discount");

// ====================================================
// ITEM 6: UNIFIED SETUP SCRIPT & COLLATION
// ====================================================
echo "\n--- 6. Testing Setup Script & Collation ---\n";
// Check all table collations
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$allUnicode = true;
$badCollations = [];
foreach ($tables as $t) {
    $stat = $pdo->query("SHOW TABLE STATUS LIKE '{$t}'")->fetch(PDO::FETCH_ASSOC);
    if (($stat['Collation'] ?? '') !== 'utf8mb4_unicode_ci') {
        $allUnicode = false;
        $badCollations[] = "{$t} ({$stat['Collation']})";
    }
}
assertTest($allUnicode, "All " . count($tables) . " database tables use utf8mb4_unicode_ci collation", implode(', ', $badCollations));

// ====================================================
// ITEM 7: ROLES PAGE DISPLAY & TRANSLATIONS
// ====================================================
echo "\n--- 7. Testing Roles Page & Translations ---\n";
$enLang = json_decode(file_get_contents(dirname(__DIR__) . '/lang/en.json'), true);
$kmLang = json_decode(file_get_contents(dirname(__DIR__) . '/lang/km.json'), true);

assertTest(($enLang['status_active'] ?? '') === 'Active', "lang/en.json has 'status_active' => 'Active'");
assertTest(($kmLang['status_active'] ?? '') === 'សកម្ម', "lang/km.json has 'status_active' => 'សកម្ម'");

$rolesPhp = file_get_contents(dirname(__DIR__) . '/views/owner/roles.php');
assertTest(str_contains($rolesPhp, 'min-width: 280px'), "views/owner/roles.php has widened Role Name column (min-width: 280px)");
assertTest(str_contains($rolesPhp, "__('status_active', 'Active')"), "views/owner/roles.php translates 'status_active' to Active");

// ====================================================
// ITEM 8: LOGIN COOKIE SECURITY
// ====================================================
echo "\n--- 8. Testing Login Cookie Configuration ---\n";
$authPhp = file_get_contents(dirname(__DIR__) . '/core/Auth.php');
assertTest(str_contains($authPhp, "'httponly' => true") && str_contains($authPhp, "'samesite' => 'Lax'"),
    "core/Auth.php configures session cookies with HttpOnly=true and SameSite=Lax");

$appPhp = file_get_contents(dirname(__DIR__) . '/config/app.php');
assertTest(str_contains($appPhp, "ini_set('session.cookie_httponly', '1')") && str_contains($appPhp, "ini_set('session.cookie_samesite', 'Lax')"),
    "config/app.php enforces session.cookie_httponly and session.cookie_samesite");

// Cleanup test pos device
$pdo->prepare("DELETE FROM pos_devices WHERE id = ?")->execute([$devId]);

echo "\n========================================================\n";
echo "   TEST SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "========================================================\n";
if ($failCount === 0) {
    echo ">>> ALL 8 ITEMS VERIFIED AND PASSED PERFECTLY! <<<\n";
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
}
