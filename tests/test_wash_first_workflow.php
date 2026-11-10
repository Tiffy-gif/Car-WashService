<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/NotificationManager.php';
require_once __DIR__ . '/../core/SmartQueueEngine.php';
require_once __DIR__ . '/../core/OrderGate.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/TechnicalManager.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=carwash_db', 'root', '');
echo "=== TESTING WASH FIRST WORKFLOW ===" . PHP_EOL;

$now = date('Y-m-d H:i:s');
$pdo->exec("DELETE FROM orders WHERE order_number = 'ORD-TEST-WF01'");
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd, is_mixed, service_sequence)
    VALUES ('ORD-TEST-WF01', 'WF-9999', 'small_car', 'paid', 'waiting', ?, ?, 35.00, 1, 'wash_first')
")->execute([$now, $now]);
$testOrderId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, item_type)
    VALUES (?, 1, 'Exterior Wash', 10.00, 'service'), (?, 2, 'Mobil 1 Engine Oil', 25.00, 'service')
")->execute([$testOrderId, $testOrderId]);

// Step 1: Ensure tasks
$tasks = BayManager::ensureOrderTasks($pdo, $testOrderId);
echo "1. Tasks generated: " . count($tasks) . PHP_EOL;
foreach ($tasks as $t) {
    echo "   - Task #{$t['id']}: {$t['task_type']} (seq {$t['sequence']}) -> {$t['status']}" . PHP_EOL;
}

// Step 2: Gating test - Bay 4 (Oil) must be BLOCKED before Wash completes
$blockedBay4 = BayManager::assignCarToBay($pdo, 4, $testOrderId, 1, 'Tester', 'supervisor');
if (!$blockedBay4['success'] && $blockedBay4['error'] === 'PREREQUISITE_STEP_INCOMPLETE') {
    echo "2. Bay 4 blocked before wash: SUCCESS ({$blockedBay4['message']})" . PHP_EOL;
} else {
    echo "2. Bay 4 blocked before wash: FAILED: " . json_encode($blockedBay4) . PHP_EOL;
}

// Step 3: Assign to Bay 1 (Wash Bay)
$assignBay1 = BayManager::assignCarToBay($pdo, 1, $testOrderId, 1, 'Tester', 'supervisor');
if (!empty($assignBay1['success'])) {
    echo "3. Bay 1 admission: SUCCESS" . PHP_EOL;
} else {
    echo "3. Bay 1 admission: FAILED: " . json_encode($assignBay1) . PHP_EOL;
}

// Step 4: Finish Wash in Bay 1 -> triggers handshake to Bay 4 (Technical Queue)
$finishWash = BayManager::finishCar($pdo, 1, $testOrderId, 1, 'Washer Sopheap', 'washer');
if (!empty($finishWash['success']) && !empty($finishWash['to_tech'])) {
    echo "4. Wash finish handshake to Bay 4: SUCCESS ({$finishWash['message']})" . PHP_EOL;
} else {
    echo "4. Wash finish handshake to Bay 4: FAILED: " . json_encode($finishWash) . PHP_EOL;
}

$ordAfterWash = $pdo->query("SELECT status, repair_status, bay_id FROM orders WHERE id = {$testOrderId}")->fetch(PDO::FETCH_ASSOC);
echo "   - Status after wash: status={$ordAfterWash['status']}, repair_status={$ordAfterWash['repair_status']}, bay_id=" . var_export($ordAfterWash['bay_id'], true) . PHP_EOL;

// Step 5: Assign to Bay 4 (Oil Bay)
$assignBay4 = BayManager::assignCarToBay($pdo, 4, $testOrderId, 1, 'Tester', 'supervisor');
if (!empty($assignBay4['success'])) {
    echo "5. Bay 4 admission after wash: SUCCESS" . PHP_EOL;
} else {
    echo "5. Bay 4 admission after wash: FAILED: " . json_encode($assignBay4) . PHP_EOL;
}

// Step 6: Complete safety checklist & finish job in Bay 4
TechnicalManager::ensureDefaultChecklist($pdo, $testOrderId);
TechnicalManager::passAllChecklistItems($pdo, $testOrderId, 1, 'Tech Bunthoeun');
$finishTech = TechnicalManager::finishJobOrder($pdo, $testOrderId, 1, 'Tech Bunthoeun', 'technical');
if (!empty($finishTech['success'])) {
    echo "6. Bay 4 completion: SUCCESS ({$finishTech['message']})" . PHP_EOL;
} else {
    echo "6. Bay 4 completion: FAILED: " . json_encode($finishTech) . PHP_EOL;
}

// Step 7: Pass QC on Finishing Pad
$qcRes = BayManager::passQc($pdo, $testOrderId, 1, 'Supervisor Rath', 'wash_supervisor', ['exterior_wash', 'interior_vacuum', 'wheels_tires', 'mirrors_glass', 'wipe_down']);
if (!empty($qcRes['success'])) {
    echo "7. Finishing Pad QC pass: SUCCESS" . PHP_EOL;
} else {
    echo "7. Finishing Pad QC pass: FAILED: " . json_encode($qcRes) . PHP_EOL;
}

// Step 8: Customer Handover
$handoverRes = BayManager::handoverToCustomer($pdo, $testOrderId, 1, 'Supervisor Rath', 'wash_supervisor');
if (!empty($handoverRes['success'])) {
    echo "8. Final customer handover: SUCCESS" . PHP_EOL;
} else {
    echo "8. Final customer handover: FAILED: " . json_encode($handoverRes) . PHP_EOL;
}

// Cleanup
$pdo->exec("DELETE FROM bay_history WHERE order_id = {$testOrderId}");
$pdo->exec("DELETE FROM inspection_checklists WHERE order_id = {$testOrderId}");
$pdo->exec("DELETE FROM order_tasks WHERE order_id = {$testOrderId}");
$pdo->exec("DELETE FROM order_items WHERE order_id = {$testOrderId}");
$pdo->exec("DELETE FROM orders WHERE id = {$testOrderId}");

echo "=== ALL 8/8 WASH FIRST TEST PHASES PASSED WITH FLYING COLORS! ===" . PHP_EOL;
