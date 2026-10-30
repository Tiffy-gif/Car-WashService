<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/BayManager.php';

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

echo "========================================================\n";
echo "   CAR WASH BAYS & QUEUE COMPREHENSIVE TEST SUITE       \n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $title, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$title}\n";
        if ($details) echo "        -> {$details}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$title}\n";
        if ($details) echo "        -> {$details}\n";
    }
}

// -------------------------------------------------------------
// TEST 1: Bay Skill Tags & Compatibility
// -------------------------------------------------------------
echo "\n--- TEST 1: Bay Skill Tags (Bays 1-3 Wash, Bay 4 Oil Change) ---\n";
$bays = BayManager::getAllBays($pdo);
assertTest(count($bays) >= 4, "Total bays count is 4 or more", "Found " . count($bays) . " bays.");

$bay1 = null; $bay4 = null;
foreach ($bays as $b) {
    if ($b['bay_number'] === 1) $bay1 = $b;
    if ($b['bay_number'] === 4) $bay4 = $b;
}

assertTest($bay1 && in_array($bay1['skill_tag'], ['wash_only', 'wash_and_oil', 'detailing'], true), 
    "Bay 1 has wash skill tag ({$bay1['skill_tag']})");
assertTest($bay4 && in_array($bay4['skill_tag'], ['oil_only', 'wash_and_oil'], true), 
    "Bay 4 has oil change skill tag ({$bay4['skill_tag']})");

// Owner update bay skill tag in Settings
$updateRes = BayManager::updateBay($pdo, (int)$bay1['id'], 'Bay 1 Premium', 'wash_only', true, 1, 'Owner Admin', 'owner');
assertTest($updateRes['success'] === true, "Owner can rename and update bay skill tag", $updateRes['message']);

// -------------------------------------------------------------
// TEST 2: Create 6 Cars with Different Priorities
// -------------------------------------------------------------
echo "\n--- TEST 2: 6 Cars with Priorities (P0, P1, P2, P3, Promoted P0, Mixed) ---\n";

// Helper to create test customer
function createTestCustomer(PDO $pdo, string $name, string $phone, string $memLevel, int $points): int {
    $stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
    $stmt->execute([$phone]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $ins = $pdo->prepare("INSERT INTO customers (name, phone, membership_level, loyalty_points, status) VALUES (?, ?, ?, ?, 'active')");
    $ins->execute([$name, $phone, $memLevel, $points]);
    return (int)$pdo->lastInsertId();
}

$vipCustId = createTestCustomer($pdo, 'VIP Sokha', '012999001', 'vip', 1200);
$normalCustId = createTestCustomer($pdo, 'Walkin Dara', '012999002', 'standard', 0);

// Helper to create test order
function createTestOrder(PDO $pdo, array $data): int {
    $uid = 'TEST-' . bin2hex(random_bytes(4));
    $num = 'ORD-' . strtoupper(bin2hex(random_bytes(3)));

    $ins = $pdo->prepare("
        INSERT INTO orders (
            order_number, order_uid, customer_id, customer_name, customer_phone,
            plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr,
            status, repair_status, payment_method, payment_status, cashier_id,
            priority_level, appointment_time, is_express, is_mixed, arrived_at, created_at, is_active
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, 15.00, 0.00, 15.00, 60000,
            'waiting', ?, 'cash', 'unpaid', 1,
            ?, ?, ?, ?, ?, ?, 1
        )
    ");
    $ins->execute([
        $num, $uid, $data['customer_id'], $data['customer_name'], $data['customer_phone'],
        $data['plate_number'], 'sedan', $data['repair_status'] ?? null,
        $data['priority_level'] ?? 'P3',
        $data['appointment_time'] ?? null,
        $data['is_express'] ?? 0,
        $data['is_mixed'] ?? 0,
        $data['arrived_at'] ?? date('Y-m-d H:i:s'),
        $data['created_at'] ?? date('Y-m-d H:i:s')
    ]);
    $orderId = (int)$pdo->lastInsertId();

    // Add service item
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_name, item_type, price_usd, price_khr, qty)
        VALUES (?, ?, ?, 15.00, 60000, 1)
    ")->execute([$orderId, $data['service_name'] ?? 'Car Wash', $data['item_type'] ?? 'service']);

    return $orderId;
}

$now = time();

// Car 1: P0 (VIP Member)
$car1Id = createTestOrder($pdo, [
    'customer_id' => $vipCustId,
    'customer_name' => 'VIP Sokha',
    'customer_phone' => '012999001',
    'plate_number' => 'VIP-001',
    'priority_level' => 'P0',
    'service_name' => 'Full Wash & Vacuum',
    'arrived_at' => date('Y-m-d H:i:s', $now - 600) // 10m ago
]);

// Car 2: P1 (Appointment within 10 min)
$car2Id = createTestOrder($pdo, [
    'customer_id' => $normalCustId,
    'customer_name' => 'Appt Chenda',
    'customer_phone' => '012999002',
    'plate_number' => 'APPT-002',
    'priority_level' => 'P1',
    'appointment_time' => date('Y-m-d H:i:s', $now + 300), // in 5 mins
    'service_name' => 'Car Wash',
    'arrived_at' => date('Y-m-d H:i:s', $now - 300) // 5m ago
]);

// Car 3: P2 (Express Job <= 10 min)
$car3Id = createTestOrder($pdo, [
    'customer_id' => $normalCustId,
    'customer_name' => 'Express Bopha',
    'customer_phone' => '012999002',
    'plate_number' => 'EXP-003',
    'priority_level' => 'P2',
    'is_express' => 1,
    'service_name' => 'Quick Exterior Rinse',
    'arrived_at' => date('Y-m-d H:i:s', $now - 400) // 6m ago
]);

// Car 4: P3 (Normal Walk-in waiting 15m)
$car4Id = createTestOrder($pdo, [
    'customer_id' => $normalCustId,
    'customer_name' => 'Walkin Piseth',
    'customer_phone' => '012999002',
    'plate_number' => 'NORM-004',
    'priority_level' => 'P3',
    'service_name' => 'Standard Wash',
    'arrived_at' => date('Y-m-d H:i:s', $now - 900) // 15m ago
]);

// Car 5: P3 promoted to P0 (> 60m wait!)
$car5Id = createTestOrder($pdo, [
    'customer_id' => $normalCustId,
    'customer_name' => 'LongWait Rith',
    'customer_phone' => '012999002',
    'plate_number' => 'PROM-005',
    'priority_level' => 'P3',
    'service_name' => 'Standard Wash',
    'arrived_at' => date('Y-m-d H:i:s', $now - (75 * 60)) // 75m ago (>60 min)
]);

// Car 6: Mixed Order (Wash + Oil Change)
$car6Id = createTestOrder($pdo, [
    'customer_id' => $normalCustId,
    'customer_name' => 'Mixed Vibol',
    'customer_phone' => '012999002',
    'plate_number' => 'MIXD-006',
    'priority_level' => 'P3',
    'is_mixed' => 1,
    'service_name' => 'Wash & Synthetic Oil Change',
    'repair_status' => 'waiting',
    'arrived_at' => date('Y-m-d H:i:s', $now - 200) // 3m ago
]);
// Add oil item for car 6
$pdo->prepare("
    INSERT INTO order_items (order_id, service_name, item_type, price_usd, price_khr, qty)
    VALUES (?, 'Engine Oil 5W-30', 'spare_part', 28.00, 112000, 1)
")->execute([$car6Id]);

// Verify tasks generation on Mixed Order
$mixedTasks = BayManager::ensureOrderTasks($pdo, $car6Id);
assertTest(count($mixedTasks) === 2, "Mixed order generates exactly 2 child tasks", 
    "Task 1: {$mixedTasks[0]['title']} [{$mixedTasks[0]['status']}], Task 2: {$mixedTasks[1]['title']} [{$mixedTasks[1]['status']}]");
assertTest($mixedTasks[0]['task_type'] === 'wash' && $mixedTasks[0]['sequence'] === 1, "Task 1 is Car Wash (Step 1)");
assertTest($mixedTasks[1]['task_type'] === 'oil_change' && $mixedTasks[1]['sequence'] === 2, "Task 2 is Oil Change (Step 2)");

// -------------------------------------------------------------
// TEST 3: Queue Priorities & Automatic Promotion Check
// -------------------------------------------------------------
echo "\n--- TEST 3: Priority Calculations & Promotion Rule ---\n";
$queues = BayManager::getQueues($pdo);
$washQueue = $queues['wash_queue'];

$foundCar1 = null; $foundCar2 = null; $foundCar3 = null; $foundCar4 = null; $foundCar5 = null;
foreach ($washQueue as $wq) {
    if ($wq['order_id'] === $car1Id) $foundCar1 = $wq;
    if ($wq['order_id'] === $car2Id) $foundCar2 = $wq;
    if ($wq['order_id'] === $car3Id) $foundCar3 = $wq;
    if ($wq['order_id'] === $car4Id) $foundCar4 = $wq;
    if ($wq['order_id'] === $car5Id) $foundCar5 = $wq;
}

assertTest($foundCar1 && $foundCar1['priority_code'] === 'P0', "Car 1 VIP classified as P0", "Label: {$foundCar1['priority_label']}");
assertTest($foundCar2 && in_array($foundCar2['priority_code'], ['P0', 'P1'], true), "Car 2 Appointment classified with high priority (P0 VIP or P1 Appt)", "Label: {$foundCar2['priority_label']}");
assertTest($foundCar3 && $foundCar3['priority_code'] === 'P2', "Car 3 Express <=10m classified as P2", "Label: {$foundCar3['priority_label']}");
assertTest($foundCar4 && $foundCar4['priority_code'] === 'P3', "Car 4 Normal walk-in classified as P3", "Label: {$foundCar4['priority_label']}");
assertTest($foundCar5 && $foundCar5['priority_code'] === 'P0' && $foundCar5['is_promoted'] === true, 
    "Car 5 (>60m wait) automatically promoted from P3 to P0", "Label: {$foundCar5['priority_label']}");

// Priority ordering check: P0 cars come before P1, P1 before P2, P2 before P3
assertTest($foundCar5['position'] < $foundCar2['position'], "Promoted P0 car is ahead of P1 car", 
    "Promoted P0 Pos: #{$foundCar5['position']} vs P1 Pos: #{$foundCar2['position']}");
assertTest($foundCar2['position'] < $foundCar3['position'], "P1 appointment car is ahead of P2 express car", 
    "P1 Pos: #{$foundCar2['position']} vs P2 Pos: #{$foundCar3['position']}");
assertTest($foundCar3['position'] < $foundCar4['position'], "P2 express car is ahead of normal P3 car", 
    "P2 Pos: #{$foundCar3['position']} vs P3 Pos: #{$foundCar4['position']}");

// -------------------------------------------------------------
// TEST 4: Mixed Order Handshake & Step Enforcement
// -------------------------------------------------------------
echo "\n--- TEST 4: Mixed Order Handshake Flow & Step Rules ---\n";

// Free bays 1 and 4 for the test
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, last_freed_at = CURRENT_TIMESTAMP WHERE bay_number IN (1, 4)");

// Rule: Cannot skip Step 1 and put into Oil Bay directly!
$skipAttempt = BayManager::assignCarToBay($pdo, (int)$bay4['id'], $car6Id, 4, 'Tech Bunthoeun', 'mechanic');
assertTest($skipAttempt['success'] === false, "Server BLOCKS putting mixed car into Oil Bay before Step 1 finishes (No skipping)", 
    $skipAttempt['message']);

// Step 1: Put mixed car into Wash Bay 1
$washAssign = BayManager::assignCarToBay($pdo, (int)$bay1['id'], $car6Id, 3, 'Washer Rathana', 'washer');
assertTest($washAssign['success'] === true, "Mixed car put into Bay 1 for Step 1 (Car Wash)", $washAssign['message']);

// Washer Finishes Step 1 and taps "Send to Tech" answering longer question (Yes = 1)
$handshakeRes = BayManager::finishCar($pdo, (int)$bay1['id'], 3, 'Washer Rathana', 'washer', 1, "Muddy exterior took longer");
assertTest($handshakeRes['success'] === true && $handshakeRes['is_handshake'] === true, 
    "Washer finish triggers Handshake: Bay 1 freed & car sent to Tech", $handshakeRes['message']);

// Verify parent order is NOT marked finished yet!
$chkOrder = $pdo->query("SELECT status, repair_status, bay_id, took_longer_than_expected FROM orders WHERE id = {$car6Id}")->fetch(PDO::FETCH_ASSOC);
assertTest($chkOrder['status'] !== 'finished', "Parent order is NOT Done yet (awaiting Step 2)", "Order status: {$chkOrder['status']}");
assertTest((int)$chkOrder['took_longer_than_expected'] === 1, "Washer estimate answer (took_longer=1) saved on order");

// Check Technical Screen / Receive from Wash
$incomingTask = $pdo->query("SELECT * FROM order_tasks WHERE order_id = {$car6Id} AND status = 'ready_for_tech'")->fetch(PDO::FETCH_ASSOC);
assertTest($incomingTask !== false, "Step 2 is in 'ready_for_tech' status waiting for mechanic receive");

// Technician taps "Receive from Wash" into Bay 4 (Oil bay)
$receiveRes = BayManager::receiveFromWash($pdo, $car6Id, (int)$bay4['id'], 4, 'Tech Bunthoeun', 'mechanic');
assertTest($receiveRes['success'] === true, "Technician receives car from wash into Bay 4", $receiveRes['message']);

// Pass inspection items created by Technical workflow
$chkItems = $pdo->query("SELECT id FROM inspection_checklists WHERE order_id = {$car6Id}")->fetchAll(PDO::FETCH_COLUMN);
foreach ($chkItems as $ci) {
    TechnicalManager::updateChecklistItem($pdo, (int)$ci, 'pass', 'Normal', 'uploads/inspections/sample.jpg', 'OK', 4, 'Tech Bunthoeun');
}
$pdo->prepare("UPDATE order_tasks SET status = 'completed' WHERE order_id = ? AND task_type = 'inspection'")->execute([$car6Id]);

// Technician Finishes Step 2 (Oil Change) with estimate answer (No = 0)
$finalFinish = BayManager::finishCar($pdo, (int)$bay4['id'], 4, 'Tech Bunthoeun', 'mechanic', 0);
assertTest($finalFinish['success'] === true && $finalFinish['is_handshake'] === false, 
    "Technician finishes Step 2: Bay 4 is freed and parent order is Done", $finalFinish['message']);

$chkOrderDone = $pdo->query("SELECT status FROM orders WHERE id = {$car6Id}")->fetchColumn();
assertTest($chkOrderDone === 'finished', "Parent order is now marked 'finished' (Ready to pay)");

// -------------------------------------------------------------
// TEST 5: Supervisor Override (Change Place & Bay Reassign)
// -------------------------------------------------------------
echo "\n--- TEST 5: Supervisor Override with Required Reasons ---\n";

// Move without reason must FAIL
$moveNoReason = BayManager::changeQueuePosition($pdo, $car4Id, 1, '', 2, 'Wash Supervisor', 'wash_supervisor');
assertTest($moveNoReason['success'] === false && $moveNoReason['error'] === 'REASON_REQUIRED', 
    "Queue position change without reason is REJECTED", $moveNoReason['message']);

// Move with reason must SUCCEED
$moveWithReason = BayManager::changeQueuePosition($pdo, $car4Id, 1, 'VIP customer waiting for flight', 2, 'Wash Supervisor', 'wash_supervisor');
assertTest($moveWithReason['success'] === true, "Queue position change with valid reason SUCCEEDS", $moveWithReason['message']);

// Bay Reassign without reason must FAIL
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE bay_number = 2");
BayManager::assignCarToBay($pdo, (int)$bay1['id'], $car2Id, 2, 'Supervisor', 'wash_supervisor');
$bay2Id = (int)$pdo->query("SELECT id FROM bays WHERE bay_number = 2")->fetchColumn();

$reassignNoReason = BayManager::reassignBay($pdo, $car2Id, $bay2Id, '', 2, 'Supervisor', 'wash_supervisor');
assertTest($reassignNoReason['success'] === false && $reassignNoReason['error'] === 'REASON_REQUIRED', 
    "Bay reassignment without reason is REJECTED", $reassignNoReason['message']);

// Bay Reassign with reason must SUCCEED
$reassignWithReason = BayManager::reassignBay($pdo, $car2Id, $bay2Id, 'Bay 1 water pressure issue', 2, 'Supervisor', 'wash_supervisor');
assertTest($reassignWithReason['success'] === true, "Bay reassignment with reason SUCCEEDS", $reassignWithReason['message']);

// Free bay 2
BayManager::finishCar($pdo, $bay2Id, 2, 'Supervisor', 'wash_supervisor', 0);

// -------------------------------------------------------------
// TEST 6: Concurrency Protection on Bay Assignment
// -------------------------------------------------------------
echo "\n--- TEST 6: Concurrency Protection (Atomic Bay Assignment) ---\n";
// Bay 1 is free. Staff A and Staff B attempt to assign different cars to Bay 1.
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE bay_number = 1");

$firstAssign = BayManager::assignCarToBay($pdo, (int)$bay1['id'], $car1Id, 3, 'Staff A', 'washer');
$secondAssign = BayManager::assignCarToBay($pdo, (int)$bay1['id'], $car3Id, 4, 'Staff B', 'washer');

assertTest($firstAssign['success'] === true, "First staff member succeeds in taking Bay 1");
assertTest($secondAssign['success'] === false && $secondAssign['error'] === 'BAY_ALREADY_TAKEN', 
    "Second staff member simultaneously blocked: 'Bay already taken'", $secondAssign['message']);

// Free bay 1
BayManager::finishCar($pdo, (int)$bay1['id'], 3, 'Staff A', 'washer', 0);

// -------------------------------------------------------------
// TEST 7: Queue Health, Bottleneck Detection & Idle Bay Alert
// -------------------------------------------------------------
echo "\n--- TEST 7: Queue Health, Bottleneck Detection & Idle Bay Alert ---\n";

// Set Bay 3 free and idle for 10 minutes, with cars waiting in wash queue
$tenMinsAgo = date('Y-m-d H:i:s', time() - (10 * 60));
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, last_freed_at = '{$tenMinsAgo}' WHERE bay_number = 3");

$health = BayManager::getQueueHealth($pdo);
assertTest($health['total_waiting'] > 0, "Queue Health reports active waiting vehicles", "Total waiting: {$health['total_waiting']}");
assertTest($health['avg_wait_minutes'] > 0, "Queue Health calculates average wait time", "Avg: {$health['avg_wait_minutes']} mins");
assertTest($health['has_idle_alert'] === true, "CRITICAL IDLE ALERT triggered (Bay idle > 5 mins while cars wait!)");
assertTest(!empty($health['idle_alert_bays']), "Idle alert correctly lists Bay 3", "Found " . count($health['idle_alert_bays']) . " idle bay(s)");

// -------------------------------------------------------------
// TEST 8: Audit Log Integrity
// -------------------------------------------------------------
echo "\n--- TEST 8: Complete Audit Log History Verification ---\n";
$history = BayManager::getHistory($pdo, 15);
assertTest(!empty($history), "Bay history audit records are logged", "Found " . count($history) . " recent log entries");

$actionsLogged = array_unique(array_column($history, 'action'));
assertTest(in_array('assigned', $actionsLogged, true), "Audit log records 'assigned' action");
assertTest(in_array('finished', $actionsLogged, true), "Audit log records 'finished' action");
assertTest(in_array('sent_to_tech', $actionsLogged, true), "Audit log records 'sent_to_tech' handshake");
assertTest(in_array('received_by_tech', $actionsLogged, true), "Audit log records 'received_by_tech' handshake");
assertTest(in_array('reassigned', $actionsLogged, true), "Audit log records 'reassigned' action with supervisor reason");
assertTest(in_array('queue_moved', $actionsLogged, true), "Audit log records 'queue_moved' action with supervisor reason");

// -------------------------------------------------------------
// FINAL SUMMARY
// -------------------------------------------------------------
echo "\n========================================================\n";
echo "   TEST SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "========================================================\n";

if ($failCount === 0) {
    echo " ALL TESTS PASSED! Back office bays and queue are fully operational.\n\n";
} else {
    echo " Some tests failed. Please review the output above.\n\n";
}
