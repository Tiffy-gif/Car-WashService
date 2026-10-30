<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Helpers.php';
require_once dirname(__DIR__) . '/core/BayManager.php';

echo "====================================================\n";
echo "BAYS & FIFO QUEUE SYSTEM AUTOMATED VERIFICATION TEST\n";
echo "====================================================\n\n";

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$message}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$message}\n";
        $failed++;
    }
}

// ---------------------------------------------------------
// Helper: Clean up test orders & bays before test run
// ---------------------------------------------------------
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL, is_active = 1");
$pdo->exec("DELETE FROM orders WHERE plate_number LIKE '2BC-TEST-%' OR plate_number LIKE '2BC-TECH-%'");
$pdo->exec("DELETE FROM bay_history WHERE plate_number LIKE '2BC-TEST-%' OR plate_number LIKE '2BC-TECH-%'");
// Temporarily hold existing waiting orders so queue indexing starts cleanly at 1
$pdo->exec("UPDATE orders SET status = '_temp_hold' WHERE status = 'waiting' AND (bay_id IS NULL OR bay_id = 0)");

// Get test staff users
$ownerUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'owner' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cashierUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'cashier' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$washerUser = $pdo->query("SELECT id, username, full_name, role FROM users WHERE role = 'washer' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$ownerId = (int)$ownerUser['id'];
$cashierId = (int)$cashierUser['id'];
$washerId = (int)$washerUser['id'];

// Fetch bay IDs
$allBays = BayManager::getAllBays($pdo);
$bayMap = [];
foreach ($allBays as $b) {
    $bayMap[$b['bay_number']] = $b['id'];
}
$bay1Id = $bayMap[1];
$bay2Id = $bayMap[2];
$bay3Id = $bayMap[3];
$oilBayId = $bayMap[4];

// =========================================================
// TEST 1: 5 Cars Arriving One After Another (FIFO Check)
// =========================================================
echo "1. Testing 5 Cars Arriving One After Another (FIFO Queue)...\n";

$testPlates = [
    '2BC-TEST-01',
    '2BC-TEST-02',
    '2BC-TEST-03',
    '2BC-TEST-04',
    '2BC-TEST-05',
];

$orderIds = [];
$baseTime = time() - 300; // 5 minutes ago

foreach ($testPlates as $idx => $plate) {
    $orderNum = 'ORD-T' . ($idx + 1) . '-' . time();
    $arrivedAt = date('Y-m-d H:i:s', $baseTime + ($idx * 30)); // 30 seconds apart
    $queuePriority = ($idx + 1) * 10;

    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_number, source, plate_number, vehicle_type, customer_name,
            subtotal_usd, total_usd, status, arrived_at, queue_priority, created_at
        ) VALUES (?, 'pos_app', ?, 'small_car', ?, 10.00, 10.00, 'waiting', ?, ?, ?)
    ");
    $custName = "Customer " . ($idx + 1);
    $stmt->execute([$orderNum, $plate, $custName, $arrivedAt, $queuePriority, $arrivedAt]);
    $newOrdId = (int)$pdo->lastInsertId();
    $orderIds[$plate] = $newOrdId;

    // Insert wash item
    $pdo->prepare("INSERT INTO order_items (order_id, service_name, price_usd, qty) VALUES (?, 'Full Body Wash', 10.00, 1)")
        ->execute([$newOrdId]);

    // Mark arrived in bay_history
    BayManager::markCustomerArrived($pdo, $newOrdId, $cashierId, $cashierUser['full_name'], 'cashier');
}

// Fetch queue and verify positions
$queues = BayManager::getQueues($pdo);
$washQueue = $queues['wash_queue'];

// Filter for our test cars
$testQueue = array_values(array_filter($washQueue, fn($q) => in_array($q['plate_number'], $testPlates, true)));

assertTest(count($testQueue) === 5, "All 5 cars are in the waiting queue");
assertTest($testQueue[0]['plate_number'] === '2BC-TEST-01' && $testQueue[0]['position'] === 1, "Car 1 ('2BC-TEST-01') is 1st in line (Position 1)");
assertTest($testQueue[1]['plate_number'] === '2BC-TEST-02' && $testQueue[1]['position'] === 2, "Car 2 ('2BC-TEST-02') is 2nd in line (Position 2)");
assertTest($testQueue[2]['plate_number'] === '2BC-TEST-03' && $testQueue[2]['position'] === 3, "Car 3 ('2BC-TEST-03') is 3rd in line (Position 3)");
assertTest($testQueue[3]['plate_number'] === '2BC-TEST-04' && $testQueue[3]['position'] === 4, "Car 4 ('2BC-TEST-04') is 4th in line (Position 4)");
assertTest($testQueue[4]['plate_number'] === '2BC-TEST-05' && $testQueue[4]['position'] === 5, "Car 5 ('2BC-TEST-05') is 5th in line (Position 5)");

// =========================================================
// TEST 2: Assigning Car 1 to Bay 1
// =========================================================
echo "\n2. Testing Assigning Car 1 to Free Bay 1...\n";

$assignRes = BayManager::assignCarToBay(
    $pdo,
    $bay1Id,
    $orderIds['2BC-TEST-01'],
    $cashierId,
    $cashierUser['full_name'],
    'cashier'
);

assertTest($assignRes['success'] === true, "Car 1 put into Bay 1 successfully by Cashier");

$baysAfterAssign = BayManager::getAllBays($pdo);
$bay1 = array_values(array_filter($baysAfterAssign, fn($b) => $b['id'] === $bay1Id))[0];

assertTest($bay1['status'] === 'busy', "Bay 1 status is now BUSY");
assertTest($bay1['current_car']['plate_number'] === '2BC-TEST-01', "Bay 1 current car shows '2BC-TEST-01'");

// Check queue shift: Car 2 is now 1st in line
$queues2 = BayManager::getQueues($pdo);
$testQueue2 = array_values(array_filter($queues2['wash_queue'], fn($q) => in_array($q['plate_number'], $testPlates, true)));

assertTest(count($testQueue2) === 4, "Remaining queue has 4 cars");
assertTest($testQueue2[0]['plate_number'] === '2BC-TEST-02' && $testQueue2[0]['position'] === 1, "Car 2 shifted up to 1st in line");

// =========================================================
// TEST 3: Concurrency Protection (Busy Bay Cannot be Taken Twice)
// =========================================================
echo "\n3. Testing Concurrency / Race Condition on Bay 1 (Already Taken)...\n";

// Attempt 1: Cashier tries to put Car 2 in busy Bay 1
$busyAttempt1 = BayManager::assignCarToBay(
    $pdo,
    $bay1Id,
    $orderIds['2BC-TEST-02'],
    $cashierId,
    $cashierUser['full_name'],
    'cashier'
);

assertTest($busyAttempt1['success'] === false, "Assignment to busy Bay 1 was rejected");
assertTest($busyAttempt1['error'] === 'BAY_ALREADY_TAKEN', "Error code is 'BAY_ALREADY_TAKEN'");
assertTest($busyAttempt1['message'] === 'Bay already taken', "Error message is 'Bay already taken'");

// Attempt 2: Washer tries to put Car 3 in Bay 1 at the same moment
$busyAttempt2 = BayManager::assignCarToBay(
    $pdo,
    $bay1Id,
    $orderIds['2BC-TEST-03'],
    $washerId,
    $washerUser['full_name'],
    'washer'
);

assertTest($busyAttempt2['success'] === false, "Second simultaneous attempt to take Bay 1 was also rejected");
assertTest($busyAttempt2['message'] === 'Bay already taken', "Second person received 'Bay already taken'");

// Check Bay 1 still holds Car 1
$bay1Recheck = array_values(array_filter(BayManager::getAllBays($pdo), fn($b) => $b['id'] === $bay1Id))[0];
assertTest($bay1Recheck['current_car']['plate_number'] === '2BC-TEST-01', "Bay 1 remains exclusively occupied by Car 1");

// =========================================================
// TEST 4: Assigning Car 2 to Bay 2 and Car 3 to Bay 3
// =========================================================
echo "\n4. Assigning Car 2 to Bay 2 and Car 3 to Bay 3...\n";

$assign2 = BayManager::assignCarToBay($pdo, $bay2Id, $orderIds['2BC-TEST-02'], $washerId, $washerUser['full_name'], 'washer');
$assign3 = BayManager::assignCarToBay($pdo, $bay3Id, $orderIds['2BC-TEST-03'], $washerId, $washerUser['full_name'], 'washer');

assertTest($assign2['success'] === true, "Car 2 put into Bay 2 by Washer");
assertTest($assign3['success'] === true, "Car 3 put into Bay 3 by Washer");

$allBaysCheck = BayManager::getAllBays($pdo);
$busyWashBays = array_filter($allBaysCheck, fn($b) => $b['bay_type'] === 'wash' && $b['status'] === 'busy');
assertTest(count($busyWashBays) === 3, "All 3 car wash bays (Bay 1, Bay 2, Bay 3) are now Busy");

// =========================================================
// TEST 5: Washer Finishes Car 1 (Bay 1 becomes Free, Order Ready to Pay)
// =========================================================
echo "\n5. Testing Washer Finishes Car 1 in Bay 1...\n";

$finishRes = BayManager::finishCar(
    $pdo,
    $bay1Id,
    $washerId,
    $washerUser['full_name'],
    'washer'
);

assertTest($finishRes['success'] === true, "Washer finished Car 1 successfully");

// Verify Bay 1 is now FREE
$bay1AfterFinish = array_values(array_filter(BayManager::getAllBays($pdo), fn($b) => $b['id'] === $bay1Id))[0];
assertTest($bay1AfterFinish['status'] === 'free', "Bay 1 status became FREE again");
assertTest($bay1AfterFinish['current_order_id'] === null, "Bay 1 has no current car");

// Verify Order 1 status is 'finished' ("Ready to pay")
$ord1Check = $pdo->prepare("SELECT status, finished_at FROM orders WHERE id = ?");
$ord1Check->execute([$orderIds['2BC-TEST-01']]);
$ord1Data = $ord1Check->fetch(PDO::FETCH_ASSOC);

assertTest($ord1Data['status'] === 'finished', "Car 1 order status is now 'finished' (Ready to pay)");
assertTest(!empty($ord1Data['finished_at']), "Car 1 finished_at timestamp is recorded");

// =========================================================
// TEST 6: Assign Next Waiting Car (Car 4) to Freed Bay 1
// =========================================================
echo "\n6. Assigning Next Waiting Car (Car 4) to Newly Freed Bay 1...\n";

$assign4 = BayManager::assignCarToBay(
    $pdo,
    $bay1Id,
    $orderIds['2BC-TEST-04'],
    $cashierId,
    $cashierUser['full_name'],
    'cashier'
);

assertTest($assign4['success'] === true, "Car 4 assigned to freed Bay 1 successfully");

$bay1WithCar4 = array_values(array_filter(BayManager::getAllBays($pdo), fn($b) => $b['id'] === $bay1Id))[0];
assertTest($bay1WithCar4['status'] === 'busy', "Bay 1 is now BUSY again");
assertTest($bay1WithCar4['current_car']['plate_number'] === '2BC-TEST-04', "Bay 1 now holds Car 4");

// =========================================================
// TEST 7: Queue Priority & Moving a Car Up with Reason
// =========================================================
echo "\n7. Testing Moving a Car Up in Queue with Reason (Owner/Manager Rule)...\n";

// Add 2 more waiting cars to test queue positioning
$extraOrderNum1 = 'ORD-EX1-' . time();
$extraOrderNum2 = 'ORD-EX2-' . time();

$stmt = $pdo->prepare("INSERT INTO orders (order_number, source, plate_number, vehicle_type, customer_name, subtotal_usd, total_usd, status, arrived_at, queue_priority, created_at) VALUES (?, 'pos_app', ?, 'small_car', 'Guest A', 10.00, 10.00, 'waiting', CURRENT_TIMESTAMP, 100, CURRENT_TIMESTAMP)");
$stmt->execute([$extraOrderNum1, '2BC-TEST-EX1']);
$ex1Id = (int)$pdo->lastInsertId();

$stmt->execute([$extraOrderNum2, '2BC-TEST-EX2']);
$ex2Id = (int)$pdo->lastInsertId();

// Try move up as Cashier (Unauthorized)
$cashierMove = BayManager::moveQueueCar($pdo, $ex2Id, "Emergency", $cashierId, $cashierUser['full_name'], 'cashier');
assertTest($cashierMove['success'] === false, "Cashier CANNOT move car up (Rejected)");
assertTest($cashierMove['error'] === 'FORBIDDEN', "Error is FORBIDDEN for Cashier");

// Try move up as Owner with empty reason
$emptyReasonMove = BayManager::moveQueueCar($pdo, $ex2Id, "   ", $ownerId, $ownerUser['full_name'], 'owner');
assertTest($emptyReasonMove['success'] === false, "Moving car up without a reason is rejected");
assertTest($emptyReasonMove['error'] === 'REASON_REQUIRED', "Error is REASON_REQUIRED");

// Move up as Owner with valid reason
$ownerMove = BayManager::moveQueueCar($pdo, $ex2Id, "VIP client rush appointment", $ownerId, $ownerUser['full_name'], 'owner');
assertTest($ownerMove['success'] === true, "Owner successfully moved Car EX2 up in queue");

// Check audit log in bay_history
$histCheck = $pdo->prepare("SELECT * FROM bay_history WHERE order_id = ? AND action = 'queue_moved' ORDER BY id DESC LIMIT 1");
$histCheck->execute([$ex2Id]);
$histEntry = $histCheck->fetch(PDO::FETCH_ASSOC);

assertTest(!empty($histEntry), "History entry exists for queue move");
assertTest($histEntry['performed_by_role'] === 'owner', "History recorded user role as 'owner'");
assertTest($histEntry['reason'] === 'VIP client rush appointment', "History recorded reason: 'VIP client rush appointment'");

// =========================================================
// TEST 8: POS API Connection & Real Staff Role Security
// =========================================================
echo "\n8. Testing POS API Actions & Real Server-Side Staff Role Enforcement...\n";

// Get active POS device
$devRow = $pdo->query("SELECT api_key FROM pos_devices WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$apiKey = $devRow['api_key'] ?? 'pos_sec_test_key_8899aabb';

function testPosCall(string $action, array $data, string $apiKey): array {
    $url = "http://localhost/Car-WashService/api/pos/index.php?action=" . urlencode($action);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Device-Key: ' . $apiKey
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode((string)$res, true)];
}

// 8a. Test get_bays_and_queue
$baysRes = testPosCall('get_bays_and_queue', [], $apiKey);
assertTest($baysRes['status'] === 200, "POS API 'get_bays_and_queue' returned HTTP 200");
assertTest(!empty($baysRes['data']['success']), "POS API 'get_bays_and_queue' success is true");
assertTest(count($baysRes['data']['bays']) >= 4, "POS API returned all 4 bays (Bay 1, 2, 3, Oil Bay)");
assertTest(isset($baysRes['data']['wash_queue']), "POS API returned wash_queue");
assertTest(isset($baysRes['data']['technical_queue']), "POS API returned technical_queue");

// 8b. Test customer_arrived
$arrRes = testPosCall('customer_arrived', [
    'order_id' => $ex1Id,
    'staff_id' => $cashierId
], $apiKey);
assertTest($arrRes['status'] === 200, "POS API 'customer_arrived' returned HTTP 200");
assertTest(!empty($arrRes['data']['success']), "Customer marked as arrived in FIFO queue");

// 8c. Test assign_bay on an already busy bay (Bay 1) -> must fail with 409
$conflictRes = testPosCall('assign_bay', [
    'bay_id' => $bay1Id,
    'order_id' => $ex1Id,
    'staff_id' => $cashierId
], $apiKey);
assertTest($conflictRes['status'] === 409 || $conflictRes['status'] === 400, "POS API rejected taking busy Bay 1 with conflict code (HTTP {$conflictRes['status']})");
assertTest(($conflictRes['data']['error'] ?? '') === 'BAY_ALREADY_TAKEN', "Error code is 'BAY_ALREADY_TAKEN'");
assertTest(($conflictRes['data']['message'] ?? '') === 'Bay already taken', "Error message is 'Bay already taken'");

// 8d. Test Role Security: Cashier tries to move car up by forging role="owner"
$forgedRes = testPosCall('move_queue_car', [
    'order_id' => $ex1Id,
    'reason' => 'Forged role attempt',
    'staff_id' => $cashierId,
    'role' => 'owner' // CLIENT ATTEMPTS TO FORGE ROLE!
], $apiKey);
assertTest($forgedRes['status'] === 403, "POS API rejected forged role with HTTP 403 Forbidden");
assertTest(($forgedRes['data']['error'] ?? '') === 'FORBIDDEN', "Server checked real staff role in DB and rejected unauthorized cashier");

// 8e. Test move_queue_car with valid Owner credentials
$ownerPosMove = testPosCall('move_queue_car', [
    'order_id' => $ex1Id,
    'reason' => 'Owner authorized priority',
    'staff_id' => $ownerId
], $apiKey);
assertTest($ownerPosMove['status'] === 200, "POS API allowed real Owner to move car up (HTTP 200)");

// 8f. Test finish_car via POS API
$finishPosRes = testPosCall('finish_car', [
    'bay_id' => $bay1Id,
    'staff_id' => $washerId
], $apiKey);
assertTest($finishPosRes['status'] === 200, "POS API 'finish_car' returned HTTP 200");
assertTest(!empty($finishPosRes['data']['success']), "Car finished via POS API; Bay 1 became free and order ready to pay");

// =========================================================
// TEST 9: Technical & Oil Change Bay
// =========================================================
echo "\n9. Testing Technical Screen & Oil Change Bay Queue...\n";

// Create technical order
$techOrdNum = 'ORD-TECH-' . time();
$stmt = $pdo->prepare("
    INSERT INTO orders (
        order_number, source, plate_number, vehicle_type, customer_name,
        subtotal_usd, total_usd, status, repair_status, arrived_at, queue_priority, created_at
    ) VALUES (?, 'pos_app', '2BC-TECH-99', 'suv_pickup', 'Tech Customer', 35.00, 35.00, 'waiting', 'waiting', CURRENT_TIMESTAMP, 50, CURRENT_TIMESTAMP)
");
$stmt->execute([$techOrdNum]);
$techOrdId = (int)$pdo->lastInsertId();

// Add engine oil item
$pdo->prepare("INSERT INTO order_items (order_id, service_name, price_usd, qty) VALUES (?, 'Total 5W-30 Synthetic Oil Change', 35.00, 1)")
    ->execute([$techOrdId]);

// Verify it enters technical queue
$techQueues = BayManager::getQueues($pdo);
$inTechQueue = array_values(array_filter($techQueues['technical_queue'], fn($q) => $q['plate_number'] === '2BC-TECH-99'));

assertTest(count($inTechQueue) === 1, "Technical car '2BC-TECH-99' joined the Technical Queue");
assertTest($inTechQueue[0]['position'] > 0 && !empty($inTechQueue[0]['position_label']), "Car has a valid position number in Technical Queue ({$inTechQueue[0]['position_label']})");

// Put into Oil Change Bay
$assignOil = BayManager::assignCarToBay($pdo, $oilBayId, $techOrdId, $cashierId, $cashierUser['full_name'], 'cashier');
assertTest($assignOil['success'] === true, "Car put into Oil Change Bay successfully");

$oilBayCheck = array_values(array_filter(BayManager::getAllBays($pdo), fn($b) => $b['id'] === $oilBayId))[0];
assertTest($oilBayCheck['status'] === 'busy', "Oil Change Bay is now BUSY");
assertTest($oilBayCheck['current_car']['plate_number'] === '2BC-TECH-99', "Oil Change Bay holds '2BC-TECH-99'");

// Finish technical job
$finishOil = BayManager::finishCar($pdo, $oilBayId, $ownerId, $ownerUser['full_name'], 'owner');
assertTest($finishOil['success'] === true, "Finished car in Oil Change Bay");

$oilBayFreed = array_values(array_filter(BayManager::getAllBays($pdo), fn($b) => $b['id'] === $oilBayId))[0];
assertTest($oilBayFreed['status'] === 'free', "Oil Change Bay is now FREE again");

// Clean up test data
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL, occupied_at = NULL");
$pdo->exec("DELETE FROM orders WHERE plate_number LIKE '2BC-TEST-%' OR plate_number LIKE '2BC-TECH-%'");
$pdo->exec("DELETE FROM bay_history WHERE plate_number LIKE '2BC-TEST-%' OR plate_number LIKE '2BC-TECH-%'");
$pdo->exec("UPDATE orders SET status = 'waiting' WHERE status = '_temp_hold'");

echo "\n====================================================\n";
echo "TEST RESULTS: {$passed} Passed, {$failed} Failed\n";
echo "====================================================\n";

if ($failed === 0) {
    exit(0);
} else {
    exit(1);
}
