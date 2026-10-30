<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/CashierControl.php';
require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/Helpers.php';

$pdo = Database::getInstance();

$testCount = 0;
$passedCount = 0;
$failedCount = 0;

function assertCondition(string $name, bool $condition, string $detail = ''): void {
    global $testCount, $passedCount, $failedCount;
    $testCount++;
    if ($condition) {
        $passedCount++;
        echo "  [PASS] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    } else {
        $failedCount++;
        echo "  [FAIL] {$name}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "========================================================\n";
echo "   CAR WASH BACK OFFICE: CASHIER & MONEY CONTROLS TEST  \n";
echo "========================================================\n\n";

// Fetch test accounts
$ownerUser = $pdo->query("SELECT id, username FROM users WHERE role = 'owner' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$mgrUser = $pdo->query("SELECT id, username FROM users WHERE role = 'manager' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashier1 = $pdo->query("SELECT id, username, full_name FROM users WHERE role = 'cashier' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// If second cashier doesn't exist, create one for handover test
$cashier2 = $pdo->query("SELECT id, username, full_name FROM users WHERE role = 'cashier' AND id != {$cashier1['id']} AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$cashier2) {
    $c2Hash = password_hash('123456', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (username, full_name, role, password_hash, status, created_at) VALUES ('cashier_night', 'Evening Cashier', 'cashier', ?, 'active', CURRENT_TIMESTAMP)")
        ->execute([$c2Hash]);
    $cashier2 = [
        'id' => (int)$pdo->lastInsertId(),
        'username' => 'cashier_night',
        'full_name' => 'Evening Cashier'
    ];
}

// Reset any test lockouts or open shifts from previous runs
$pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE username IN (?, ?, ?)")
    ->execute([$mgrUser['username'], $cashier1['username'], $cashier2['username']]);
$pdo->prepare("UPDATE cashier_shifts SET status = 'ended' WHERE cashier_id IN (?, ?)")
    ->execute([$cashier1['id'], $cashier2['id']]);

// ---------------------------------------------------------------------
// TEST SUITE 1: Shifts & Blind Handover with Cash Shortage
// ---------------------------------------------------------------------
echo "1. Testing Cashier Shift & Handover Shortage Calculation...\n";

// Cashier 1 starts shift with $100.00 float
$startRes = CashierControl::startShift((int)$cashier1['id'], $cashier1['full_name'], 100.00, 0, null, null, 'Morning shift');
assertCondition("Cashier starts shift with $100.00 starting cash", $startRes['success'] === true && (float)$startRes['opening_cash_usd'] === 100.00);
$shiftId = (int)$startRes['shift_id'];

// Simulate a cash sale order during this shift ($30.00)
$orderNum = generate_unique_id('ORD');
$pdo->prepare("
    INSERT INTO orders (
        order_number, customer_name, plate_number, vehicle_type, subtotal_usd, total_usd, total_khr,
        status, payment_method, payment_status, cashier_id, created_at, is_active
    ) VALUES (?, 'Test Customer', '2AB-9999', 'small_car', 30.00, 30.00, 123000, 'paid', 'cash', 'paid', ?, CURRENT_TIMESTAMP, 1)
")->execute([$orderNum, $cashier1['id']]);
$orderId = (int)$pdo->lastInsertId();

// Expected cash in drawer should be: Opening $100.00 + Cash Sale $30.00 = $130.00
$expected = CashierControl::calculateShiftExpectedCash($shiftId);
assertCondition("Expected drawer cash is correctly computed as $130.00", abs((float)$expected['expected_cash_usd'] - 130.00) < 0.01, "$" . $expected['expected_cash_usd']);

// Blind Handover: Cashier counts physical cash blindly as $115.00 ($15.00 short!)
// Outgoing cashier PIN = 123456, Incoming cashier PIN = 123456
$handoverRes = CashierControl::endShift($shiftId, 115.00, 0, '123456', $cashier2['username'], '123456', 'Shift handover count');
assertCondition("Handover signs off with both cashiers' PINs", $handoverRes['success'] === true);
assertCondition("Shortage is correctly calculated as $15.00", abs((float)$handoverRes['shortage_owed_usd'] - 15.00) < 0.01);
assertCondition("Difference is recorded as -$15.00", abs((float)$handoverRes['difference_usd'] - (-15.00)) < 0.01);

// Verify shortage owed is stored in database and recorded against cashier
$chkShortage = $pdo->prepare("SELECT shortage_owed_usd, cashier_name, status FROM cashier_shifts WHERE id = ?");
$chkShortage->execute([$shiftId]);
$dbShift = $chkShortage->fetch(PDO::FETCH_ASSOC);
assertCondition("Database records shift status as 'ended' and shortage_owed_usd as 15.00", (float)$dbShift['shortage_owed_usd'] == 15.00 && $dbShift['status'] === 'ended');

// Verify Audit Log records SHIFT_SHORTAGE
$logStmt = $pdo->prepare("SELECT details FROM audit_logs WHERE action = 'SHIFT_SHORTAGE' AND entity_id = ? ORDER BY id DESC LIMIT 1");
$logStmt->execute([(string)$shiftId]);
$logRow = $logStmt->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records cash shortage owed by Cashier", !empty($logRow) && str_contains($logRow['details'], '$15.00'));

echo "\n";

// ---------------------------------------------------------------------
// TEST SUITE 2: Refund with and without Manager PIN & Offline Block
// ---------------------------------------------------------------------
echo "2. Testing Refunds (Manager PIN Requirement & Offline Restriction)...\n";

// Cashier 2 opens shift
CashierControl::startShift((int)$cashier2['id'], $cashier2['full_name'], 100.00, 0);

// A: Refund without Manager PIN (or wrong PIN)
$wrongPinBlocked = false;
try {
    CashierControl::processRefund($orderId, 30.00, 'Customer dissatisfied', $mgrUser['username'], '000000', (int)$cashier2['id'], true);
} catch (Exception $e) {
    $wrongPinBlocked = true;
    $errDetail = $e->getMessage();
}
assertCondition("Refund rejected without valid Manager PIN", $wrongPinBlocked, $errDetail ?? '');

// B: Refund attempted while OFFLINE
$offlineBlocked = false;
try {
    CashierControl::processRefund($orderId, 30.00, 'Customer dissatisfied', $mgrUser['username'], '123456', (int)$cashier2['id'], false);
} catch (Exception $e) {
    $offlineBlocked = true;
    $offlineMsg = $e->getMessage();
}
assertCondition("Offline refund strictly blocked with paper logbook instruction", $offlineBlocked && str_contains($offlineMsg, 'paper logbook'), $offlineMsg ?? '');

// C: Refund with valid Manager PIN while ONLINE
$refundSuccess = false;
try {
    $refRes = CashierControl::processRefund($orderId, 30.00, 'Customer dissatisfied', $mgrUser['username'], '123456', (int)$cashier2['id'], true);
    $refundSuccess = !empty($refRes['success']);
} catch (Exception $e) {
    echo "  [FAIL] Unexpected refund error: " . $e->getMessage() . "\n";
}
assertCondition("Refund accepted with Manager PIN while online", $refundSuccess);

// Verify order updated in DB
$ordChk = $pdo->prepare("SELECT refund_status, refund_amount_usd, refunded_by_manager_name FROM orders WHERE id = ?");
$ordChk->execute([$orderId]);
$ordRow = $ordChk->fetch(PDO::FETCH_ASSOC);
assertCondition("Order record marked as 'refunded' with manager name", $ordRow['refund_status'] === 'refunded' && (float)$ordRow['refund_amount_usd'] == 30.00);

// Verify Audit Log records MANAGER_REFUND with cashier and manager
$refLog = $pdo->prepare("SELECT details, username, role FROM audit_logs WHERE action = 'MANAGER_REFUND' AND entity_id = ? ORDER BY id DESC LIMIT 1");
$refLog->execute([(string)$orderId]);
$refLogRow = $refLog->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records MANAGER_REFUND with cashier and manager", !empty($refLogRow) && (str_contains($refLogRow['details'], $mgrUser['username']) || str_contains($refLogRow['details'], 'authorized by Manager')));

echo "\n";

// ---------------------------------------------------------------------
// TEST SUITE 3: Open Cash Drawer Without Sale
// ---------------------------------------------------------------------
echo "3. Testing Cash Drawer Open Without Sale...\n";

// A: Attempt without Manager PIN
$drawerWrongPinBlocked = false;
try {
    CashierControl::openDrawerNoSale('Need change for $100 bill', $mgrUser['username'], '999999', (int)$cashier2['id']);
} catch (Exception $e) {
    $drawerWrongPinBlocked = true;
}
assertCondition("Opening drawer without sale rejected with invalid PIN", $drawerWrongPinBlocked);

// B: Attempt with valid Manager PIN
$drawerRes = CashierControl::openDrawerNoSale('Need change for $100 bill', $mgrUser['username'], '123456', (int)$cashier2['id']);
assertCondition("Opening drawer without sale authorized with Manager PIN", $drawerRes['success'] === true && str_starts_with($drawerRes['event_uid'], 'DRW-'));

// Verify drawer event in database
$drwEvent = $pdo->prepare("SELECT event_type, reason, cashier_name, manager_name FROM drawer_events WHERE event_uid = ?");
$drwEvent->execute([$drawerRes['event_uid']]);
$drwRow = $drwEvent->fetch(PDO::FETCH_ASSOC);
assertCondition("Drawer event logged in database with both cashier and manager", !empty($drwRow) && $drwRow['event_type'] === 'no_sale_open' && !empty($drwRow['manager_name']));

// Verify Audit Log
$drwLog = $pdo->prepare("SELECT details FROM audit_logs WHERE action = 'DRAWER_OPEN_NO_SALE' AND entity_id = ?");
$drwLog->execute([$drawerRes['event_uid']]);
assertCondition("Audit log records DRAWER_OPEN_NO_SALE event", !empty($drwLog->fetch()));

echo "\n";

// ---------------------------------------------------------------------
// TEST SUITE 4: Unannounced Spot Audit
// ---------------------------------------------------------------------
echo "4. Testing Unannounced Spot Audit...\n";

// Manager conducts surprise spot audit on Cashier 2
// Active shift expected cash = $100.00 opening cash
// Cashier blindly counts $95.00 ($5.00 shortage)
$spotRes = CashierControl::conductSpotAudit((int)$cashier2['id'], 95.00, 0, $mgrUser['username'], '123456', 'Midday surprise spot audit');
assertCondition("Spot audit conducted successfully with Manager PIN", $spotRes['success'] === true && str_starts_with($spotRes['audit_uid'], 'SPT-'));
assertCondition("Spot audit correctly calculates difference (-$5.00)", abs((float)$spotRes['difference_usd'] - (-5.00)) < 0.01);
assertCondition("Spot audit records shortage owed ($5.00)", abs((float)$spotRes['shortage_owed_usd'] - 5.00) < 0.01);

// Verify database record
$sptStmt = $pdo->prepare("SELECT audit_uid, cashier_name, manager_name, shortage_owed_usd FROM spot_audits WHERE audit_uid = ?");
$sptStmt->execute([$spotRes['audit_uid']]);
$sptRow = $sptStmt->fetch(PDO::FETCH_ASSOC);
assertCondition("Spot audit saved in spot_audits table", !empty($sptRow) && (float)$sptRow['shortage_owed_usd'] == 5.00);

// Verify Audit Log
$sptLog = $pdo->prepare("SELECT details FROM audit_logs WHERE action = 'SPOT_SHORTAGE' AND entity_id = ?");
$sptLog->execute([(string)$pdo->query("SELECT id FROM spot_audits WHERE audit_uid = '{$spotRes['audit_uid']}'")->fetchColumn()]);
assertCondition("Audit log records SPOT_SHORTAGE event", !empty($sptLog->fetch()));

echo "\n";

// ---------------------------------------------------------------------
// TEST SUITE 5: Day Lock (11:59 PM Cutoff, Closed Day, & Reopen)
// ---------------------------------------------------------------------
echo "5. Testing Day Lock & Final Z-Report...\n";

// A: 11:59 PM Lock Check
$lockCheck = CashierControl::isDayLocked(date('Y-m-d'), '23:59:01');
assertCondition("Sales locked automatically at 11:59 PM", $lockCheck['locked'] === true && $lockCheck['reason'] === 'time_cutoff_1159');

// B: Close the Day & Generate Z-Report
$closeRes = CashierControl::closeDayZReport($mgrUser['username'], '123456', 200.00, 0, 'Final evening register close');
assertCondition("Manager closes day and creates final Z-Report", $closeRes['success'] === true && str_starts_with($closeRes['z_report_number'], 'ZREP-'));

// Verify sales are locked after closing the day
$dayLockedAfterClose = CashierControl::isDayLocked(date('Y-m-d'), '14:00:00');
assertCondition("Sales locked for the rest of the day after day close", $dayLockedAfterClose['locked'] === true && $dayLockedAfterClose['reason'] === 'day_closed');

// C: Reopen Closed Day with Manager PIN and mandatory reason
$reopenWrongReason = false;
try {
    CashierControl::reopenClosedDay(date('Y-m-d'), '', $mgrUser['username'], '123456');
} catch (Exception $e) {
    $reopenWrongReason = true;
}
assertCondition("Reopening closed day requires mandatory reason", $reopenWrongReason);

$reopenRes = CashierControl::reopenClosedDay(date('Y-m-d'), 'VIP Customer arrived late for ceramic coating', $mgrUser['username'], '123456');
assertCondition("Closed day reopened with Manager PIN and reason", $reopenRes['success'] === true);

// Verify sales unlocked after reopen
$unlockedAfterReopen = CashierControl::isDayLocked(date('Y-m-d'), '14:00:00');
assertCondition("Sales unlocked and operational after Manager reopening", $unlockedAfterReopen['locked'] === false);

// Verify Audit Log records DAY_REOPENED
$reopenLog = $pdo->prepare("SELECT details FROM audit_logs WHERE action = 'DAY_REOPENED' ORDER BY id DESC LIMIT 1");
$reopenLog->execute();
$reopenLogRow = $reopenLog->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records DAY_REOPENED with reason and Manager name", !empty($reopenLogRow) && str_contains($reopenLogRow['details'], 'VIP Customer'));

echo "\n";

// ---------------------------------------------------------------------
// SUMMARY OF TEST RESULTS
// ---------------------------------------------------------------------
echo "========================================================\n";
echo "                   TEST RESULTS SUMMARY                 \n";
echo "========================================================\n";
echo "Total Tests Run : {$testCount}\n";
echo "Tests Passed    : {$passedCount}\n";
echo "Tests Failed    : {$failedCount}\n";
if ($failedCount === 0) {
    echo "STATUS: ALL MONEY & CASHIER CONTROLS VERIFIED SUCCESSFULLY!\n";
} else {
    echo "STATUS: SOME TESTS FAILED. PLEASE REVIEW LOG ABOVE.\n";
}
echo "========================================================\n";
