<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/TechnicalManager.php';
require_once __DIR__ . '/../core/StockControl.php';
require_once __DIR__ . '/../core/AuditLogger.php';

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

$testCount = 0;
$passCount = 0;

function assertTest(bool $condition, string $description): void {
    global $testCount, $passCount;
    $testCount++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
    }
}

echo "\n=======================================================\n";
echo "  TECHNICAL & WAREHOUSE FLOW TEST SUITE\n";
echo "=======================================================\n\n";

// 0. Locate sample users
$techUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role IN ('technical', 'mechanic') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$warehouseUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role IN ('stock_control', 'warehouse') LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$techUser || !$warehouseUser) {
    echo "[ABORT] Missing technical or stock_control user in database.\n";
    exit(1);
}

$techId = (int)$techUser['id'];
$techName = $techUser['full_name'];
$scId = (int)$warehouseUser['id'];
$scName = $warehouseUser['full_name'];

echo "Staff setup: Technician = {$techName} (ID: {$techId}), Stock Control = {$scName} (ID: {$scId})\n\n";

// Clean up any old test order in Bay 4
$pdo->prepare("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 4")->execute();

// Setup sample products
$prodOilId = (int)$pdo->query("SELECT id FROM products WHERE category = 'engine_oil' AND is_active = 1 LIMIT 1")->fetchColumn();
if (!$prodOilId) {
    $pdo->prepare("INSERT INTO products (name_en, name_km, category, price_usd, price_khr, stock_qty, min_stock_alert, is_active) VALUES ('Shell Helix Ultra 5W-40 (4L)', 'ប្រេង Shell', 'engine_oil', 45.00, 184500, 25, 5, 1)")->execute();
    $prodOilId = (int)$pdo->lastInsertId();
}
$prodFilterId = (int)$pdo->query("SELECT id FROM products WHERE is_active = 1 AND id != {$prodOilId} LIMIT 1")->fetchColumn();
if (!$prodFilterId) {
    $pdo->prepare("INSERT INTO products (name_en, name_km, category, price_usd, price_khr, stock_qty, min_stock_alert, is_active) VALUES ('OEM Oil Filter V8', 'តម្រងប្រេង', 'oil_filter', 12.00, 49200, 20, 3, 1)")->execute();
    $prodFilterId = (int)$pdo->lastInsertId();
}

// Reset filter stock to known baseline e.g. 20
$pdo->prepare("UPDATE products SET stock_qty = 20 WHERE id = ?")->execute([$prodFilterId]);
$pdo->prepare("UPDATE products SET stock_qty = 25 WHERE id = ?")->execute([$prodOilId]);

// -----------------------------------------------------------------
// TEST 1: INTAKE ORDER & TECHNICIAN RECEIVES CAR -> JOB ORDER & 2 LANES
// -----------------------------------------------------------------
echo "1. Testing Vehicle Receive & Technical Job Order Creation (2 Lanes)...\n";

$oNum = 'TEST-TECH-' . date('His');
$pdo->prepare("
    INSERT INTO orders (
        order_number, order_uid, plate_number, vehicle_type, status, repair_status, payment_status, receipt_printed_at, receipt_issued_at, total_usd, total_khr,
        arrived_at, queue_priority, priority_level, created_at, is_active
    ) VALUES (?, ?, 'TEST-8899', 'suv', 'waiting', 'waiting', 'paid', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 75.00, 307500, CURRENT_TIMESTAMP, 0, 'P3', CURRENT_TIMESTAMP, 1)
")->execute([$oNum, generate_unique_id('ORD')]);
$orderId = (int)$pdo->lastInsertId();

// Add order items: Oil Change service & Oil product
$pdo->prepare("INSERT INTO order_items (order_id, product_id, service_name, item_type, qty, price_usd) VALUES (?, ?, 'Synthetic Oil Change (4L)', 'service', 4.0, 45.00)")
    ->execute([$orderId, $prodOilId]);

// Receive car into Bay 4 by Technician
$recRes = BayManager::assignCarToBay($pdo, 4, $orderId, $techId, $techName, 'technical');
if (empty($recRes['success'])) {
    echo "DEBUG assignCarToBay failed: " . json_encode($recRes) . "\n";
}
assertTest(!empty($recRes['success']), "Car assigned to Bay 4 (Oil Bay)");

// Create Job Order and simultaneous lanes
$jobOrderRes = TechnicalManager::ensureJobOrder($pdo, $orderId, $techId, $techName);
assertTest(!empty($jobOrderRes['success']) && !empty($jobOrderRes['job_order_uid']), "Job Order initialized with UID: " . ($jobOrderRes['job_order_uid'] ?? ''));

// Verify 2 simultaneous lanes created in order_tasks
$tasks = $pdo->query("SELECT * FROM order_tasks WHERE order_id = {$orderId} ORDER BY sequence ASC")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($tasks) >= 2, "Found " . count($tasks) . " simultaneous tasks for this order");

$lane1 = array_filter($tasks, fn($t) => ($t['lane'] ?? '') === 'lane_1');
$lane2 = array_filter($tasks, fn($t) => ($t['lane'] ?? '') === 'lane_2');
assertTest(count($lane1) >= 1, "Lane 1 (Mechanical/Oil Service) created");
assertTest(count($lane2) >= 1, "Lane 2 (Guided Safety Inspection) created");

// Verify default inspection checklist items created
$checklists = $pdo->query("SELECT * FROM inspection_checklists WHERE order_id = {$orderId}")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($checklists) >= 4, "Created " . count($checklists) . " guided inspection checklist items");

// -----------------------------------------------------------------
// TEST 2: TECHNICIAN PARTS REQUEST -> "WAITING FOR PARTS"
// -----------------------------------------------------------------
echo "\n2. Testing Parts Request Submission (Status = 'Waiting for Parts')...\n";

$prRes = TechnicalManager::requestParts($pdo, $orderId, $prodFilterId, 2, $techId, $techName, null, "Urgent filter replacement");
assertTest(!empty($prRes['success']) && $prRes['status'] === 'pending', "Parts request submitted with UID: {$prRes['request_uid']}");

// Check order flag has_pending_parts_request
$hasPending = (int)$pdo->query("SELECT has_pending_parts_request FROM orders WHERE id = {$orderId}")->fetchColumn();
assertTest($hasPending === 1, "Order correctly flagged as has_pending_parts_request = 1");

$taskPartStatus = $pdo->query("SELECT parts_status FROM order_tasks WHERE order_id = {$orderId} LIMIT 1")->fetchColumn();
assertTest($taskPartStatus === 'waiting_parts', "Task parts_status updated to 'waiting_parts'");

// -----------------------------------------------------------------
// TEST 3: WAREHOUSE STOCK CONTROL REJECTS PARTS REQUEST WITH REASON
// -----------------------------------------------------------------
echo "\n3. Testing Warehouse Stock Control Rejects Request with Mandatory Reason...\n";

$req1Id = (int)$pdo->query("SELECT id FROM parts_requests WHERE request_uid = '{$prRes['request_uid']}'")->fetchColumn();

// Test empty reason rejection blocked
$rejectFailed = false;
try {
    TechnicalManager::rejectPartsRequest($pdo, $req1Id, "", $scId, $scName, 'stock_control');
} catch (Throwable $e) {
    $rejectFailed = true;
}
assertTest($rejectFailed, "Rejection without reason is strictly rejected by server");

// Reject with proper reason
$rejRes = TechnicalManager::rejectPartsRequest($pdo, $req1Id, "Requested filter model incompatible with engine type", $scId, $scName, 'stock_control');
assertTest(!empty($rejRes['success']), "Parts request successfully rejected with reason stored");

$reqRow = $pdo->query("SELECT status, review_reason, reviewed_by_name FROM parts_requests WHERE id = {$req1Id}")->fetch(PDO::FETCH_ASSOC);
assertTest($reqRow['status'] === 'rejected' && str_contains($reqRow['review_reason'], 'incompatible'), "Status is 'rejected' and reason logged");

// -----------------------------------------------------------------
// TEST 4: TECHNICIAN REQUESTS VALID PART & STOCK CONTROL APPROVES
// -----------------------------------------------------------------
echo "\n4. Testing Parts Request Approval -> Stock Moves Out -> 'Parts Received'...\n";

// Technician submits a new request for 1 filter
$prRes2 = TechnicalManager::requestParts($pdo, $orderId, $prodFilterId, 1, $techId, $techName, null, "Correct V8 filter requested");
$req2Id = (int)$pdo->query("SELECT id FROM parts_requests WHERE request_uid = '{$prRes2['request_uid']}'")->fetchColumn();

$stockBefore = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodFilterId}")->fetchColumn();

// Stock control approves
$appRes = TechnicalManager::approvePartsRequest($pdo, $req2Id, $scId, $scName, 'stock_control');
assertTest(!empty($appRes['success']), "Stock Control approved parts request");

$stockAfter = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodFilterId}")->fetchColumn();
assertTest($stockAfter === ($stockBefore - 1), "Physical stock decremented from {$stockBefore} to {$stockAfter}");

// Verify movement recorded in immutable stock ledger
$mov = $pdo->query("SELECT * FROM stock_movements WHERE reference_id = '{$oNum}' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($mov) && $mov['movement_type'] === 'used', "Immutable stock movement ledger entry recorded (Type: used, Qty: 1.0)");

// Verify task status becomes 'parts_received'
$taskPartStatus2 = $pdo->query("SELECT parts_status FROM order_tasks WHERE order_id = {$orderId} LIMIT 1")->fetchColumn();
assertTest($taskPartStatus2 === 'parts_received', "Task parts_status updated to 'parts_received' on technician screen");

// Verify item was added to order_items
$addedItem = $pdo->query("SELECT * FROM order_items WHERE order_id = {$orderId} AND product_id = {$prodFilterId}")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($addedItem), "Approved spare part added to customer order items for billing");

// -----------------------------------------------------------------
// TEST 5: OIL USAGE ADJUSTMENT (ACTUAL USED > RESERVED)
// -----------------------------------------------------------------
echo "\n5. Testing Oil Usage Adjustment & Final Stock Deduction...\n";

$oilBefore = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodOilId}")->fetchColumn();

// Technician used 5.0L instead of 4.0L reserved (+1.0L extra)
$oilAdjRes = TechnicalManager::adjustOilUsage($pdo, $orderId, 5.0, $techId, $techName);
assertTest(!empty($oilAdjRes['success']), "Technician adjusted oil quantity to 5.0L");

$oilAfter = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodOilId}")->fetchColumn();
assertTest($oilAfter === ($oilBefore - 1), "Additional 1.0L oil automatically deducted from stock ({$oilBefore} -> {$oilAfter})");

$oilItem = $pdo->query("SELECT qty FROM order_items WHERE order_id = {$orderId} AND product_id = {$prodOilId}")->fetch(PDO::FETCH_ASSOC);
assertTest((float)$oilItem['qty'] === 5.0, "Order item quantity updated to reflect actual oil used: 5.0L");

// Complete Lane 1 task
$oilTask = $pdo->query("SELECT id FROM order_tasks WHERE order_id = {$orderId} AND task_type = 'oil_change' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$finishT1 = TechnicalManager::finishTask($pdo, (int)$oilTask['id'], $techId, $techName, 5.0);
assertTest(!empty($finishT1['success']), "Lane 1 (Oil Drain & Refill) finished successfully");

// -----------------------------------------------------------------
// TEST 6: GUIDED INSPECTION CHECKLIST & MANDATORY ENFORCEMENT
// -----------------------------------------------------------------
echo "\n6. Testing Guided Inspection Checklist & Mandatory Pass Enforcement...\n";

$items = $pdo->query("SELECT * FROM inspection_checklists WHERE order_id = {$orderId} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
assertTest(count($items) >= 4, "Inspecting " . count($items) . " checklist items");

// Update Item 1: Brake Pad (PASS with photo)
TechnicalManager::updateChecklistItem($pdo, (int)$items[0]['id'], 'pass', '5.5mm', 'uploads/inspections/sample_brake.jpg', 'Pads in good condition', $techId, $techName);

// Update Item 2: Tire Tread (PASS with photo)
TechnicalManager::updateChecklistItem($pdo, (int)$items[1]['id'], 'pass', '4.2mm', 'uploads/inspections/sample_tire.jpg', 'Tread depth legal', $techId, $techName);

// Update Item 3: Battery (PASS with photo)
TechnicalManager::updateChecklistItem($pdo, (int)$items[2]['id'], 'pass', '12.6V', 'uploads/inspections/sample_battery.jpg', 'Battery voltage healthy', $techId, $techName);

// Leave Item 4 (Underbody Leak) as 'pending' with NO photo!
echo "  Attempting to finish job order while mandatory item #4 is incomplete...\n";
$finishBlocked = false;
$blockedMessage = '';
try {
    TechnicalManager::finishJobOrder($pdo, $orderId, $techId, $techName, 'technical', 0);
} catch (Throwable $e) {
    $finishBlocked = true;
    $blockedMessage = $e->getMessage();
}
assertTest($finishBlocked, "Job completion BLOCKED because mandatory inspection item is incomplete");
echo "  Block message caught: \"{$blockedMessage}\"\n";

// Verify Bay 4 is STILL occupied and order is NOT finished
$bayStatus = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertTest($bayStatus['status'] === 'busy' && (int)$bayStatus['current_order_id'] === $orderId, "Bay 4 remains BUSY (cannot be freed until inspection passes)");

// Now pass Item 4 with photo
TechnicalManager::updateChecklistItem($pdo, (int)$items[3]['id'], 'pass', 'No Leaks', 'uploads/inspections/sample_leak.jpg', 'All seals tight', $techId, $techName);

// Finish Lane 2 task
$inspTask = $pdo->query("SELECT id FROM order_tasks WHERE order_id = {$orderId} AND task_type = 'inspection' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$finishT2 = TechnicalManager::finishTask($pdo, (int)$inspTask['id'], $techId, $techName);
assertTest(!empty($finishT2['success']), "Lane 2 (Guided Inspection) completed");

// Now finish full Job Order
$finalJobFinish = TechnicalManager::finishJobOrder($pdo, $orderId, $techId, $techName, 'technical', 0);
assertTest(!empty($finalJobFinish['success']), "Full Job Order finished successfully after all mandatory items passed");

// Verify Bay 4 is now Free and order status is 'finished'
$bayStatusAfter = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertTest($bayStatusAfter['status'] === 'free' && empty($bayStatusAfter['current_order_id']), "Bay 4 is now FREE and ready for next car");

$orderFinalStatus = $pdo->query("SELECT status, repair_status FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_ASSOC);
assertTest($orderFinalStatus['status'] === 'finished' && $orderFinalStatus['repair_status'] === 'finished', "Order status is 'finished' (Ready for Cashier checkout)");

// -----------------------------------------------------------------
// TEST 7: WAREHOUSE PHYSICAL COUNT DISCREPANCY LOG
// -----------------------------------------------------------------
echo "\n7. Testing Physical Count Discrepancy Logging in Warehouse...\n";

$sysQty = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodFilterId}")->fetchColumn();
$physicalCount = $sysQty - 2; // e.g. 2 short

$discRes = TechnicalManager::logDiscrepancy(
    $pdo,
    $prodFilterId,
    $physicalCount,
    "Physical weekly count found 2 damaged filter boxes",
    $scId,
    $scName,
    'stock_control'
);
assertTest(!empty($discRes['success']), "Discrepancy logged with UID: {$discRes['discrepancy_uid']}");
assertTest($discRes['difference'] === -2, "Difference correctly recorded as -2");

// Verify product stock is updated to physical count
$newProdStock = (int)$pdo->query("SELECT stock_qty FROM products WHERE id = {$prodFilterId}")->fetchColumn();
assertTest($newProdStock === $physicalCount, "Product stock updated from {$sysQty} to {$physicalCount}");

// Verify discrepancy table record
$discRow = $pdo->query("SELECT * FROM stock_discrepancies WHERE discrepancy_uid = '{$discRes['discrepancy_uid']}'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($discRow) && (int)$discRow['difference_qty'] === -2, "Discrepancy record saved in stock_discrepancies table");

// Verify correcting movement in stock ledger
$discMov = $pdo->query("SELECT * FROM stock_movements WHERE reference_id = '{$discRes['discrepancy_uid']}'")->fetch(PDO::FETCH_ASSOC);
assertTest(!empty($discMov) && $discMov['movement_type'] === 'adjusted', "Correcting movement logged in immutable stock ledger");

// -----------------------------------------------------------------
// TEST 8: STOCK CONTROL CANNOT TAKE PAYMENTS
// -----------------------------------------------------------------
echo "\n8. Testing Stock Control Payment Restriction...\n";

$scCanPos = Auth::userHasPermission($scId, 'pos.access');
$scCanPay = Auth::userHasPermission($scId, 'pos.pay_order');
assertTest(!$scCanPos, "Stock Control user does NOT have 'pos.access' permission");
assertTest(!$scCanPay, "Stock Control user does NOT have 'pos.pay_order' permission");

// -----------------------------------------------------------------
// TEST 9: COMPLETE AUDIT LOG INTEGRITY
// -----------------------------------------------------------------
echo "\n9. Testing Complete Audit Log Trail...\n";

$requiredActions = [
    'JOB_ORDER_CREATED',
    'PARTS_REQUESTED',
    'PARTS_REJECTED',
    'PARTS_APPROVED',
    'OIL_ADJUSTED',
    'INSPECTION_ITEM_UPDATED',
    'JOB_ORDER_FINISHED',
    'STOCK_DISCREPANCY'
];

$loggedActions = $pdo->query("SELECT DISTINCT action FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchAll(PDO::FETCH_COLUMN);

foreach ($requiredActions as $act) {
    assertTest(in_array($act, $loggedActions, true), "Audit log entry recorded for: {$act}");
}

// -----------------------------------------------------------------
// SUMMARY
// -----------------------------------------------------------------
echo "\n=======================================================\n";
echo "  TEST SUMMARY: {$passCount} / {$testCount} PASSED\n";
echo "=======================================================\n";

if ($passCount === $testCount) {
    echo "\n>>> ALL TECHNICAL & WAREHOUSE TESTS PASSED PERFECTLY! <<<\n\n";
    exit(0);
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n\n";
    exit(1);
}
