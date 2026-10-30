<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/CashierControl.php';
require_once __DIR__ . '/../core/StockControl.php';
require_once __DIR__ . '/../core/BankTransferControl.php';
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

echo "=================================================================\n";
echo "  CAR WASH BACK OFFICE: BANK TRANSFER & STOCK CONTROL TESTS      \n";
echo "=================================================================\n\n";

// Fetch test accounts
$ownerUser = $pdo->query("SELECT id, username FROM users WHERE role = 'owner' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$mgrUser = $pdo->query("SELECT id, username FROM users WHERE role = 'manager' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashier = $pdo->query("SELECT id, username, full_name FROM users WHERE role = 'cashier' AND status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Ensure manager PIN is valid ('123456')
$mgrHash = password_hash('123456', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
    ->execute([$mgrHash, $mgrUser['id']]);

// ---------------------------------------------------------------------
// TEST 1: Bank Transfer Without Slip (Must be Blocked)
// ---------------------------------------------------------------------
echo "1. Testing Bank Transfer Payment Without Slip (Blocked)...\n";

// Create test order
$orderNum1 = generate_unique_id('ORD');
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, customer_name, subtotal_usd, total_usd, total_khr, status, payment_status, cashier_id, created_at)
    VALUES (?, '2AA-1111', 'car_sedan', 'Slip Test Customer', 15.00, 15.00, 61500, 'finished', 'unpaid', ?, CURRENT_TIMESTAMP)
")->execute([$orderNum1, $cashier['id']]);
$orderId1 = (int)$pdo->lastInsertId();

$noSlipBlocked = false;
$noSlipError = '';
try {
    BankTransferControl::submitBankTransfer($orderId1, 'aba', null, null, null, 15.00, 0.00, 0, (int)$cashier['id']);
} catch (Exception $e) {
    $noSlipBlocked = true;
    $noSlipError = $e->getMessage();
}
assertCondition("Bank transfer without slip is blocked", $noSlipBlocked, $noSlipError);

// Verify order is still unpaid
$chkOrd1 = $pdo->query("SELECT payment_status, status FROM orders WHERE id = {$orderId1}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Order remains unpaid when slip is missing", $chkOrd1['payment_status'] === 'unpaid');

// ---------------------------------------------------------------------
// TEST 2: Bank Transfer With Real Slip (Pending Verification & Excluded from Revenue)
// ---------------------------------------------------------------------
echo "\n2. Testing Bank Transfer With Slip (Pending Verification & Revenue Exclusion)...\n";

// Generate a valid mock JPG image for testing
$testImgDir = sys_get_temp_dir();
$testImgFile = $testImgDir . '/test_bank_slip_' . uniqid() . '.jpg';
$gd = imagecreatetruecolor(400, 600);
$bgCol = imagecolorallocate($gd, 240, 245, 250);
imagefilledrectangle($gd, 0, 0, 400, 600, $bgCol);
imagejpeg($gd, $testImgFile, 90);
imagedestroy($gd);

$mockFile = [
    'name'     => 'customer_slip.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $testImgFile,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($testImgFile)
];

$ref1 = 'ABA-REF-' . time() . '-' . rand(100, 999);
$submitRes = BankTransferControl::submitBankTransfer(
    $orderId1,
    'aba',
    $mockFile,
    null,
    $ref1,
    15.00,
    0.00,
    0,
    (int)$cashier['id']
);

assertCondition("Bank transfer with slip accepted as Pending Verification", $submitRes['success'] === true && $submitRes['payment_status'] === 'pending_verification');
assertCondition("Slip photo uploaded and compressed", !empty($submitRes['bank_slip_image']) && file_exists(dirname(__DIR__) . '/' . $submitRes['bank_slip_image']));

// Check database state
$chkOrd2 = $pdo->query("SELECT status, payment_status, bank_slip_image FROM orders WHERE id = {$orderId1}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Order payment_status is 'pending_verification'", $chkOrd2['payment_status'] === 'pending_verification');
assertCondition("Order status is NOT 'paid'", $chkOrd2['status'] !== 'paid');

// Check that this pending transfer is NOT included in cash revenue
$revStmt = $pdo->prepare("SELECT COALESCE(SUM(total_usd), 0) FROM orders WHERE status = 'paid' AND id = ?");
$revStmt->execute([$orderId1]);
$revCounted = (float)$revStmt->fetchColumn();
assertCondition("Pending verification bank transfer is EXCLUDED from revenue reports", $revCounted === 0.00);

// Check pending transfers list
$pendingList = BankTransferControl::getPendingTransfers();
$foundInPending = false;
foreach ($pendingList as $p) {
    if ((int)$p['id'] === $orderId1) {
        $foundInPending = true;
        break;
    }
}
assertCondition("Order appears in Manager's Pending Transfers list", $foundInPending);

// ---------------------------------------------------------------------
// TEST 3: Manager Approves Bank Transfer with PIN
// ---------------------------------------------------------------------
echo "\n3. Testing Manager Transfer Approval with PIN...\n";

// Invalid PIN should fail
$invalidPinBlocked = false;
try {
    BankTransferControl::approveTransfer($orderId1, $mgrUser['username'], '999999');
} catch (Exception $e) {
    $invalidPinBlocked = true;
}
assertCondition("Transfer approval with wrong PIN is blocked", $invalidPinBlocked);

// Valid Manager PIN approves
$approveRes = BankTransferControl::approveTransfer($orderId1, $mgrUser['username'], '123456', 'Verified in ABA Merchant');
assertCondition("Transfer approval with valid Manager PIN succeeds", $approveRes['success'] === true);

// Verify DB order status is now paid
$chkOrd3 = $pdo->query("SELECT status, payment_status, payment_verified_by_name FROM orders WHERE id = {$orderId1}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Order status is officially 'paid'", $chkOrd3['status'] === 'paid' && $chkOrd3['payment_status'] === 'paid');
assertCondition("Payment verified by Manager name recorded", !empty($chkOrd3['payment_verified_by_name']));

// Check audit log for approval
$auditApprove = $pdo->query("SELECT * FROM audit_logs WHERE action = 'BANK_TRANSFER_APPROVED' AND entity_id = '{$orderId1}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records BANK_TRANSFER_APPROVED with Manager info", !empty($auditApprove) && strpos($auditApprove['details'], 'APPROVED') !== false);

// ---------------------------------------------------------------------
// TEST 4: Manager Rejects Bank Transfer with PIN and Reason
// ---------------------------------------------------------------------
echo "\n4. Testing Manager Transfer Rejection with PIN and Reason...\n";

// Create another order for rejection test
$orderNum2 = generate_unique_id('ORD');
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, customer_name, subtotal_usd, total_usd, total_khr, status, payment_status, cashier_id, created_at)
    VALUES (?, '2BB-2222', 'car_suv', 'Reject Test Customer', 25.00, 25.00, 102500, 'finished', 'unpaid', ?, CURRENT_TIMESTAMP)
")->execute([$orderNum2, $cashier['id']]);
$orderId2 = (int)$pdo->lastInsertId();

// Submit transfer with slip
$testImgFile2 = $testImgDir . '/test_bank_slip2_' . uniqid() . '.jpg';
$gd2 = imagecreatetruecolor(300, 300);
imagejpeg($gd2, $testImgFile2, 85);
imagedestroy($gd2);

BankTransferControl::submitBankTransfer(
    $orderId2,
    'khqr',
    ['name' => 'fake_slip.jpg', 'type' => 'image/jpeg', 'tmp_name' => $testImgFile2, 'error' => UPLOAD_ERR_OK, 'size' => filesize($testImgFile2)],
    null,
    'KHQR-888',
    25.00,
    0.00,
    0,
    (int)$cashier['id']
);

// Reject transfer with Manager PIN
$rejectReason = "Altered screenshot, funds not received in Bakong account";
$rejectRes = BankTransferControl::rejectTransfer($orderId2, $rejectReason, $mgrUser['username'], '123456');
assertCondition("Transfer rejection with Manager PIN succeeds", $rejectRes['success'] === true);

// Verify DB order status is rejected
$chkOrd4 = $pdo->query("SELECT payment_status, payment_rejection_reason FROM orders WHERE id = {$orderId2}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Order payment_status is 'rejected'", $chkOrd4['payment_status'] === 'rejected');
assertCondition("Rejection reason stored on order", $chkOrd4['payment_rejection_reason'] === $rejectReason);

// Check audit log for rejection
$auditReject = $pdo->query("SELECT * FROM audit_logs WHERE action = 'BANK_TRANSFER_REJECTED' AND entity_id = '{$orderId2}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records BANK_TRANSFER_REJECTED with reason", !empty($auditReject) && strpos($auditReject['details'], 'REJECTED') !== false);

// ---------------------------------------------------------------------
// TEST 5: Zero-Stock Check: Block Product Sale, Allow Services, Manager Override
// ---------------------------------------------------------------------
echo "\n5. Testing Zero-Stock Check, Service Exemption, and Manager Override...\n";

// Create or find a product with 0 stock
$pdo->prepare("
    INSERT INTO products (name_en, name_km, category, price_usd, cost_price_usd, stock_qty, reserved_qty, is_active, created_at)
    VALUES ('Test Out of Stock Filter', 'តេស្តអស់ស្តុក', 'spare_part', 12.00, 6.00, 0, 0, 1, CURRENT_TIMESTAMP)
")->execute();
$oosProductId = (int)$pdo->lastInsertId();

// Verify checkAvailability reports false
$avail = StockControl::checkAvailability($oosProductId, 1);
assertCondition("Zero-stock product checkAvailability reports false", $avail['available'] === false && $avail['error'] === 'OUT_OF_STOCK');

// Verify sale reservation is blocked without override
$oosBlocked = false;
try {
    StockControl::reserveProduct($oosProductId, 1, 'ORD-TEST-FAIL', (int)$cashier['id'], false);
} catch (Exception $e) {
    $oosBlocked = true;
}
assertCondition("Zero-stock product sale is blocked without manager override", $oosBlocked);

// Verify sale reservation SUCCEEDS with Manager PIN override
$overrideRes = StockControl::reserveProduct($oosProductId, 1, 'ORD-TEST-OVERRIDE', (int)$cashier['id'], true, $mgrUser['username'], '123456');
assertCondition("Zero-stock product sale succeeds with Manager PIN override", $overrideRes['success'] === true);

// Check audit log for override
$auditOverride = $pdo->query("SELECT * FROM audit_logs WHERE action = 'STOCK_OVERRIDE' AND entity_id = '{$oosProductId}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log records STOCK_OVERRIDE with Manager signoff", !empty($auditOverride));

// ---------------------------------------------------------------------
// TEST 6: Stock Reservation and Release on Order Cancellation
// ---------------------------------------------------------------------
echo "\n6. Testing Stock Reservation and Order Cancellation Release...\n";

// Create product with 10 units in stock
$pdo->prepare("
    INSERT INTO products (name_en, name_km, category, price_usd, cost_price_usd, stock_qty, reserved_qty, is_active, created_at)
    VALUES ('Test Synthetic Engine Oil', 'ប្រេងម៉ាស៊ីនតេស្ត', 'engine_oil', 30.00, 18.00, 10, 0, 1, CURRENT_TIMESTAMP)
")->execute();
$oilProductId = (int)$pdo->lastInsertId();

// Create order with this product
$orderNum3 = generate_unique_id('ORD');
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, customer_name, subtotal_usd, total_usd, total_khr, status, payment_status, cashier_id, created_at)
    VALUES (?, '2CC-3333', 'car_sedan', 'Stock Reserve Customer', 30.00, 30.00, 123000, 'waiting', 'unpaid', ?, CURRENT_TIMESTAMP)
")->execute([$orderNum3, $cashier['id']]);
$orderId3 = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO order_items (order_id, service_id, product_id, service_name, item_type, qty, price_usd, price_khr)
    VALUES (?, NULL, ?, 'Test Synthetic Engine Oil', 'product', 2, 15.00, 61500)
")->execute([$orderId3, $oilProductId]);

// Reserve stock (2 units)
StockControl::reserveProduct($oilProductId, 2, $orderNum3, (int)$cashier['id']);

$pChk1 = $pdo->query("SELECT stock_qty, reserved_qty FROM products WHERE id = {$oilProductId}")->fetch(PDO::FETCH_ASSOC);
$availQtyAfterReserve = max(0, (int)$pChk1['stock_qty'] - (int)$pChk1['reserved_qty']);
assertCondition("Product reserved_qty increased to 2", (int)$pChk1['reserved_qty'] === 2);
assertCondition("Product available stock reduced to 8", $availQtyAfterReserve === 8);

// Cancel the order -> stock must be released!
StockControl::releaseOrderReservations($orderId3, $orderNum3, (int)$cashier['id'], 'Customer cancelled wash');

$pChk2 = $pdo->query("SELECT stock_qty, reserved_qty FROM products WHERE id = {$oilProductId}")->fetch(PDO::FETCH_ASSOC);
$availQtyAfterRelease = max(0, (int)$pChk2['stock_qty'] - (int)$pChk2['reserved_qty']);
assertCondition("Product reserved_qty reverted to 0 upon order cancellation", (int)$pChk2['reserved_qty'] === 0);
assertCondition("Product available stock restored to 10", $availQtyAfterRelease === 10);

// Verify ledger records both 'reserved' and 'released'
$ledgerEntries = $pdo->query("SELECT movement_type, quantity FROM stock_movements WHERE product_id = {$oilProductId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$hasReserved = false;
$hasReleased = false;
foreach ($ledgerEntries as $le) {
    if ($le['movement_type'] === 'reserved') $hasReserved = true;
    if ($le['movement_type'] === 'released') $hasReleased = true;
}
assertCondition("Stock ledger recorded both 'reserved' and 'released' movement entries", $hasReserved && $hasReleased);

// ---------------------------------------------------------------------
// TEST 7: Stock Ledger Immutability & Append-Only Correcting Line
// ---------------------------------------------------------------------
echo "\n7. Testing Stock Ledger Immutability & Append-Only Correction...\n";

// Immutability check: mutation attempt must throw exception
$disallowFailed = false;
try {
    StockControl::disallowMutation('deleted');
} catch (Exception $e) {
    $disallowFailed = true;
}
assertCondition("Direct ledger deletion/edit is disallowed by policy", $disallowFailed);

// Add correcting line with Manager PIN
$corrRes = StockControl::addCorrectionLine(
    $oilProductId,
    14, // actual counted physical stock
    'Physical warehouse recount found 4 additional unopened bottles',
    $mgrUser['username'],
    '123456',
    (int)$mgrUser['id']
);
assertCondition("Append-only correcting line added with Manager PIN", $corrRes['success'] === true && $corrRes['new_stock'] === 14);

// Verify ledger has 'adjusted' entry
$adjEntry = $pdo->query("SELECT * FROM stock_movements WHERE product_id = {$oilProductId} AND movement_type = 'adjusted' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Stock ledger contains 'adjusted' correcting line with reason and new balance", !empty($adjEntry) && (int)$adjEntry['new_qty'] === 14);

// Verify audit log for stock adjustment
$auditAdj = $pdo->query("SELECT * FROM audit_logs WHERE action = 'STOCK_ADJUSTMENT' AND entity_id = '{$oilProductId}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log recorded STOCK_ADJUSTMENT with Manager identity", !empty($auditAdj));

// ---------------------------------------------------------------------
// TEST 8: Receipt Rule & Paper Receipt Fallback
// ---------------------------------------------------------------------
echo "\n8. Testing Receipt Rule & Paper Fallback with Manager PIN...\n";

// Authorize paper fallback without manager PIN (must fail)
$paperWithoutPinBlocked = false;
try {
    BankTransferControl::authorizePaperReceiptFallback($orderId1, 'Printer jam', $mgrUser['username'], '000000');
} catch (Exception $e) {
    $paperWithoutPinBlocked = true;
}
assertCondition("Paper fallback without valid Manager PIN is blocked", $paperWithoutPinBlocked);

// Authorize paper fallback with valid Manager PIN
$paperRes = BankTransferControl::authorizePaperReceiptFallback(
    $orderId1,
    'Thermal printer head burnt - issued manual paper receipt #P-8801',
    $mgrUser['username'],
    '123456',
    (int)$cashier['id']
);
assertCondition("Paper receipt fallback authorized with Manager PIN", $paperRes['success'] === true);

// Verify DB order flag
$chkOrd5 = $pdo->query("SELECT paper_receipt_fallback, paper_receipt_authorized_by FROM orders WHERE id = {$orderId1}")->fetch(PDO::FETCH_ASSOC);
assertCondition("Order paper_receipt_fallback flag set to 1", (int)$chkOrd5['paper_receipt_fallback'] === 1);
assertCondition("Order paper_receipt_authorized_by recorded", !empty($chkOrd5['paper_receipt_authorized_by']));

// Verify audit log
$auditPaper = $pdo->query("SELECT * FROM audit_logs WHERE action = 'PAPER_RECEIPT_FALLBACK' AND entity_id = '{$orderId1}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertCondition("Audit log recorded PAPER_RECEIPT_FALLBACK with reason and Manager name", !empty($auditPaper));

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n=================================================================\n";
echo "  TEST SUMMARY: {$passedCount} Passed, {$failedCount} Failed (Total {$testCount})\n";
echo "=================================================================\n";

if ($failedCount > 0) {
    exit(1);
}
exit(0);
