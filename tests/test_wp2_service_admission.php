<?php
declare(strict_types=1);

/**
 * WP2 Automated Verification Suite: Service Admission & Dispatch Implementation
 *
 * Isolated temporary SQLite database in sys_get_temp_dir().
 * Zero writes to MariaDB 'carwash_db'.
 *
 * Covers 13 Required Test Cases:
 * 1. Paid waiting order admission
 * 2. Unpaid order rejection
 * 3. Missing electronic receipt rejection
 * 4. Wash-only workflow
 * 5. Mechanical-only workflow
 * 6. Mixed mechanical-first workflow
 * 7. Bay 4 release after mechanical completion
 * 8. Wash assignment to Bays 1, 2, and 3
 * 9. QC pass and failed QC
 * 10. Prevention of premature customer handover
 * 11. Duplicate completion rejection
 * 12. Concurrent bay assignment
 * 13. TV and queue synchronization
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_wp2_admission_' . uniqid() . '.sqlite';
register_shutdown_function(function() use ($tmpDb) {
    if (file_exists($tmpDb)) {
        @unlink($tmpDb);
    }
});

$pdo = new PDO("sqlite:{$tmpDb}", null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
]);

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/OrderGate.php';
require_once __DIR__ . '/../core/TechnicalManager.php';
require_once __DIR__ . '/../core/SmartQueueEngine.php';
require_once __DIR__ . '/../core/BayManager.php';

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

$refDriver = new ReflectionProperty('Database', 'driver');
$refDriver->setAccessible(true);
$refDriver->setValue(null, 'sqlite');

// Build isolated schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT NOT NULL,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        phone TEXT,
        membership_level TEXT DEFAULT 'standard',
        loyalty_points INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE vehicles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER DEFAULT NULL,
        plate_number TEXT NOT NULL UNIQUE,
        vehicle_type TEXT DEFAULT 'small_car'
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        category TEXT DEFAULT 'wash',
        price_usd REAL DEFAULT 5.00
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_km TEXT DEFAULT '',
        category TEXT DEFAULT 'care_product',
        price_usd REAL DEFAULT 5.00,
        stock_qty INTEGER DEFAULT 10
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT 'Walk-in Guest',
        customer_phone TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        subtotal_usd REAL DEFAULT 0.0,
        total_usd REAL DEFAULT 0.0,
        total_khr INTEGER DEFAULT 0,
        payment_status TEXT DEFAULT 'unpaid',
        payment_method TEXT DEFAULT 'cash',
        payment_ref TEXT DEFAULT NULL,
        cashier_id INTEGER DEFAULT 1,
        created_by INTEGER DEFAULT 1,
        status TEXT DEFAULT 'waiting',
        repair_status TEXT DEFAULT NULL,
        repair_notes TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_mixed INTEGER DEFAULT 0,
        bay_id INTEGER DEFAULT NULL,
        suggested_bay_id INTEGER DEFAULT NULL,
        bay_override_reason TEXT DEFAULT NULL,
        queue_priority INTEGER DEFAULT 0,
        priority_level TEXT DEFAULT 'P3',
        receipt_issued_at DATETIME DEFAULT NULL,
        receipt_printed_at DATETIME DEFAULT NULL,
        arrived_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        estimated_duration_minutes INTEGER DEFAULT 20,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        service_name TEXT,
        item_type TEXT DEFAULT 'service',
        qty INTEGER DEFAULT 1,
        price_usd REAL DEFAULT 0.0,
        price_khr INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        title TEXT DEFAULT 'Service Task',
        task_type TEXT NOT NULL,
        sequence INTEGER DEFAULT 1,
        status TEXT DEFAULT 'waiting',
        bay_id INTEGER DEFAULT NULL,
        assigned_user_id INTEGER DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        base_duration_minutes INTEGER DEFAULT 20,
        estimated_duration_minutes INTEGER DEFAULT 20,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE bays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bay_number INTEGER NOT NULL UNIQUE,
        bay_name TEXT NOT NULL,
        bay_type TEXT NOT NULL,
        skill_tag TEXT DEFAULT 'wash_only',
        status TEXT DEFAULT 'free',
        current_order_id INTEGER DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 1,
        duration_minutes INTEGER DEFAULT 20,
        last_freed_at DATETIME DEFAULT NULL,
        occupied_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE bay_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        bay_id INTEGER,
        bay_name TEXT,
        plate_number TEXT,
        action TEXT,
        performed_by INTEGER,
        performed_by_name TEXT,
        performed_by_role TEXT,
        reason TEXT,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inspection_checklists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_id INTEGER DEFAULT NULL,
        item_key TEXT NOT NULL,
        item_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        measurement_value TEXT DEFAULT NULL,
        photo_url TEXT DEFAULT NULL,
        is_mandatory INTEGER NOT NULL DEFAULT 1,
        notes TEXT DEFAULT NULL,
        checked_by_id INTEGER DEFAULT NULL,
        checked_by_name TEXT DEFAULT NULL,
        checked_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE parts_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        part_name TEXT,
        quantity INTEGER DEFAULT 1,
        status TEXT DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_uid TEXT NOT NULL,
        user_id INTEGER DEFAULT NULL,
        username TEXT DEFAULT NULL,
        role TEXT DEFAULT NULL,
        action TEXT NOT NULL,
        entity_type TEXT DEFAULT NULL,
        entity_id TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address TEXT DEFAULT NULL,
        user_agent TEXT DEFAULT NULL,
        device_name TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_washers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        washer_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE order_mechanics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        mechanic_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT 'Appt Guest',
        customer_phone TEXT DEFAULT '012345678',
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        service_id INTEGER DEFAULT 1,
        appointment_date DATE DEFAULT CURRENT_DATE,
        appointment_time TEXT DEFAULT '14:00',
        status TEXT DEFAULT 'pending'
    );

    CREATE TABLE settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );
");

// Seed bays: Bays 1-3 Wash, Bay 4 Lift & Oil
$pdo->exec("
    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, is_active, sort_order) VALUES
    (1, 1, 'Bay 1 (Wash)', 'wash', 'wash_only', 'free', 1, 1),
    (2, 2, 'Bay 2 (Wash)', 'wash', 'wash_only', 'free', 1, 2),
    (3, 3, 'Bay 3 (Wash)', 'wash', 'wash_only', 'free', 1, 3),
    (4, 4, 'Bay 4 (Lift & Oil)', 'oil_change', 'oil_only', 'free', 1, 4);

    INSERT INTO users (id, username, full_name, role, effective_role) VALUES
    (1, 'cashier1', 'Cashier Chenda', 'cashier', 'cashier'),
    (2, 'supervisor1', 'Supervisor Rath', 'wash_supervisor', 'wash_supervisor'),
    (3, 'mechanic1', 'Tech Bunthoeun', 'mechanic', 'mechanic'),
    (4, 'washer1', 'Washer Sopheap', 'washer', 'washer');

    INSERT INTO services (id, name_en, category, price_usd) VALUES
    (1, 'Full Exterior & Interior Wash', 'wash', 5.00),
    (2, 'Engine Oil Change & Filter', 'repair', 25.00);
");

$testCount = 0;
$passCount = 0;

function runTest(string $title, callable $testFn): void {
    global $testCount, $passCount;
    $testCount++;
    echo "\n[TEST {$testCount}] {$title}...\n";
    try {
        $testFn();
        $passCount++;
        echo "  --> PASSED\n";
    } catch (Throwable $e) {
        echo "  --> FAILED: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

// =========================================================================
// TEST 1: Paid waiting order admission
// =========================================================================
runTest("Test 1: Paid waiting order admission into available wash bay", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (101, 'ORD-WP2-001', '2AA-1111', 'small_car', 'paid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (101, 1, 'Full Exterior & Interior Wash', 5.00)
    ")->execute();

    $res = BayManager::assignCarToBay($pdo, 1, 101, 2, 'Supervisor Rath', 'wash_supervisor');
    if (empty($res['success'])) {
        throw new Exception("Expected admission to succeed, got: " . json_encode($res));
    }

    $bay = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if ($bay['status'] !== 'busy' || (int)$bay['current_order_id'] !== 101) {
        throw new Exception("Bay 1 should be busy with order 101, found: " . json_encode($bay));
    }

    $order = $pdo->query("SELECT status, bay_id FROM orders WHERE id = 101")->fetch(PDO::FETCH_ASSOC);
    if ($order['status'] !== 'washing' || (int)$order['bay_id'] !== 1) {
        throw new Exception("Order 101 should be status='washing' and bay_id=1, found: " . json_encode($order));
    }
});

// =========================================================================
// TEST 2: Unpaid order rejection
// =========================================================================
runTest("Test 2: Unpaid order admission rejection", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (102, 'ORD-WP2-002', '2BB-2222', 'small_car', 'unpaid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (102, 1, 'Full Exterior & Interior Wash', 5.00)
    ")->execute();

    $res = BayManager::assignCarToBay($pdo, 2, 102, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($res['success']) || ($res['error'] ?? '') !== 'PAYMENT_REQUIRED') {
        throw new Exception("Expected PAYMENT_REQUIRED rejection, got: " . json_encode($res));
    }

    $bay2 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
    if ($bay2['status'] !== 'free') {
        throw new Exception("Bay 2 must remain free after rejected admission.");
    }
});

// =========================================================================
// TEST 3: Missing electronic receipt rejection
// =========================================================================
runTest("Test 3: Missing electronic receipt admission rejection", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (103, 'ORD-WP2-003', '2CC-3333', 'small_car', 'paid', 'waiting', NULL, ?, 5.00)
    ")->execute([$now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (103, 1, 'Full Exterior & Interior Wash', 5.00)
    ")->execute();

    $res = BayManager::assignCarToBay($pdo, 2, 103, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($res['success']) || ($res['error'] ?? '') !== 'RECEIPT_REQUIRED') {
        throw new Exception("Expected RECEIPT_REQUIRED rejection, got: " . json_encode($res));
    }
});

// =========================================================================
// TEST 4: Wash-only workflow
// =========================================================================
runTest("Test 4: Wash-only workflow skips mechanical service and allows Bay 1-3", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (104, 'ORD-WP2-004', '2DD-4444', 'small_car', 'paid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (104, 1, 'Full Exterior & Interior Wash', 5.00)
    ")->execute();

    // Verify task generation: wash task sequence 1, qc task sequence 2
    $tasks = BayManager::ensureOrderTasks($pdo, 104);
    if (count($tasks) !== 2 || $tasks[0]['task_type'] !== 'wash' || $tasks[1]['task_type'] !== 'qc') {
        throw new Exception("Wash-only tasks incorrect: " . json_encode($tasks));
    }

    // Try assigning wash-only to Bay 4 (Oil Bay) -> must fail with skill mismatch
    $failBay4 = BayManager::assignCarToBay($pdo, 4, 104, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($failBay4['success']) || ($failBay4['error'] ?? '') !== 'BAY_SKILL_MISMATCH') {
        throw new Exception("Wash-only car assigned to Bay 4 should fail BAY_SKILL_MISMATCH, got: " . json_encode($failBay4));
    }

    // Assign to Bay 2 -> succeeds
    $assignBay2 = BayManager::assignCarToBay($pdo, 2, 104, 2, 'Supervisor Rath', 'wash_supervisor');
    if (empty($assignBay2['success'])) {
        throw new Exception("Wash-only assignment to Bay 2 failed: " . json_encode($assignBay2));
    }

    // Free Bay 2 for subsequent tests
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 2");
    $pdo->exec("UPDATE orders SET bay_id = NULL WHERE id = 104");
});

// =========================================================================
// TEST 5: Mechanical-only workflow
// =========================================================================
runTest("Test 5: Mechanical-only workflow completes in Bay 4 and bypasses washing", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd, repair_status)
        VALUES (105, 'ORD-WP2-005', '2EE-5555', 'small_car', 'paid', 'waiting', ?, ?, 25.00, 'pending')
    ")->execute([$now, $now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (105, 2, 'Engine Oil Change & Filter', 25.00)
    ")->execute();

    // Task generation: only 1 task (oil_change)
    $tasks = BayManager::ensureOrderTasks($pdo, 105);
    if (count($tasks) !== 1 || $tasks[0]['task_type'] !== 'oil_change') {
        throw new Exception("Mechanical-only tasks incorrect: " . json_encode($tasks));
    }

    // Try assigning to Bay 3 (Wash Bay) -> must fail with skill mismatch or prerequisite rule
    $failBay3 = BayManager::assignCarToBay($pdo, 3, 105, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($failBay3['success']) || !in_array(($failBay3['error'] ?? ''), ['BAY_SKILL_MISMATCH', 'PREREQUISITE_STEP_INCOMPLETE'], true)) {
        throw new Exception("Mechanical-only car assigned to Bay 3 should fail BAY_SKILL_MISMATCH or PREREQUISITE_STEP_INCOMPLETE, got: " . json_encode($failBay3));
    }

    // Assign to Bay 4 (Lift & Oil Bay) -> succeeds
    $assignBay4 = BayManager::assignCarToBay($pdo, 4, 105, 3, 'Tech Bunthoeun', 'mechanic');
    if (empty($assignBay4['success'])) {
        throw new Exception("Mechanical-only assignment to Bay 4 failed: " . json_encode($assignBay4));
    }

    // Finish Bay 4 without extra checklist -> completes order directly to finished
    $finishRes = BayManager::finishCar($pdo, 4, 105, 3, 'Tech Bunthoeun', 'mechanic', 0);
    if (empty($finishRes['success']) || !empty($finishRes['is_handshake'])) {
        throw new Exception("Mechanical-only finish should be final completion without handshake, got: " . json_encode($finishRes));
    }

    $bay4 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
    if ($bay4['status'] !== 'free') {
        throw new Exception("Bay 4 must be free after mechanical-only completion.");
    }

    $ord105 = $pdo->query("SELECT status, finished_at FROM orders WHERE id = 105")->fetch(PDO::FETCH_ASSOC);
    if ($ord105['status'] !== 'finished' || empty($ord105['finished_at'])) {
        throw new Exception("Order 105 should be finished with finished_at set.");
    }
});

// =========================================================================
// TEST 6: Mixed mechanical-first workflow
// =========================================================================
runTest("Test 6: Mixed order blocks wash bays, requires Bay 4 first and mandatory inspection", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd, is_mixed)
        VALUES (106, 'ORD-WP2-006', '2FF-6666', 'small_car', 'paid', 'waiting', ?, ?, 30.00, 1)
    ")->execute([$now, $now]);
    $pdo->prepare("
        INSERT INTO order_items (order_id, service_id, service_name, price_usd)
        VALUES (106, 2, 'Engine Oil Change & Filter', 25.00), (106, 1, 'Full Exterior & Interior Wash', 5.00)
    ")->execute();

    $tasks = BayManager::ensureOrderTasks($pdo, 106);
    if (count($tasks) !== 3) {
        throw new Exception("Mixed order must have 3 tasks (oil_change, wash, qc), found: " . count($tasks));
    }

    // Try assigning mixed order to Wash Bay 2 before mechanical work -> MUST BE BLOCKED
    $failWash = BayManager::assignCarToBay($pdo, 2, 106, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($failWash['success']) || ($failWash['error'] ?? '') !== 'PREREQUISITE_STEP_INCOMPLETE') {
        throw new Exception("Mixed order entering wash bay before Bay 4 should fail PREREQUISITE_STEP_INCOMPLETE, got: " . json_encode($failWash));
    }

    // Assign to Bay 4 Lift -> succeeds
    $assignBay4 = BayManager::assignCarToBay($pdo, 4, 106, 3, 'Tech Bunthoeun', 'mechanic');
    if (empty($assignBay4['success'])) {
        throw new Exception("Mixed order assignment to Bay 4 failed: " . json_encode($assignBay4));
    }

    // Seed mandatory technical inspection checklist item in pending status
    $pdo->prepare("
        INSERT INTO inspection_checklists (order_id, item_key, item_name, status, is_mandatory)
        VALUES (106, 'brake_pads', 'Brake Pad Thickness', 'pending', 1)
    ")->execute();

    // Attempting to finish Bay 4 while inspection is pending MUST FAIL
    $failFinish = BayManager::finishCar($pdo, 4, 106, 3, 'Tech Bunthoeun', 'mechanic', 0);
    if (!empty($failFinish['success']) || ($failFinish['error'] ?? '') !== 'MANDATORY_INSPECTION_INCOMPLETE') {
        throw new Exception("Finish Bay 4 without passing inspection should fail MANDATORY_INSPECTION_INCOMPLETE, got: " . json_encode($failFinish));
    }

    // Pass the inspection item with photo
    $pdo->prepare("UPDATE inspection_checklists SET status = 'pass', photo_url = 'https://example.com/photo.jpg' WHERE order_id = 106")->execute();

    // Finish Bay 4 now -> succeeds!
    $finishBay4 = BayManager::finishCar($pdo, 4, 106, 3, 'Tech Bunthoeun', 'mechanic', 0);
    if (empty($finishBay4['success']) || empty($finishBay4['is_handshake'])) {
        throw new Exception("Finishing Step 1 in Bay 4 should succeed with is_handshake=true: " . json_encode($finishBay4));
    }
});

// =========================================================================
// TEST 7: Bay 4 release after mechanical completion
// =========================================================================
runTest("Test 7: Bay 4 is freed and wash task unblocks in Wash Queue after mechanical completion", function() use ($pdo) {
    $bay4 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
    if ($bay4['status'] !== 'free' || !empty($bay4['current_order_id'])) {
        throw new Exception("Bay 4 must be completely free, found: " . json_encode($bay4));
    }

    $ord106 = $pdo->query("SELECT status, repair_status, bay_id FROM orders WHERE id = 106")->fetch(PDO::FETCH_ASSOC);
    if ($ord106['status'] !== 'waiting' || $ord106['repair_status'] !== 'finished' || !empty($ord106['bay_id'])) {
        throw new Exception("Order 106 should be status='waiting', repair_status='finished', bay_id=NULL, found: " . json_encode($ord106));
    }

    $washTask = $pdo->query("SELECT status FROM order_tasks WHERE order_id = 106 AND task_type = 'wash'")->fetch(PDO::FETCH_ASSOC);
    if ($washTask['status'] !== 'waiting') {
        throw new Exception("Step 2 wash task should now be 'waiting', found: " . $washTask['status']);
    }
});

// =========================================================================
// TEST 8: Wash assignment to Bays 1, 2, and 3
// =========================================================================
runTest("Test 8: Wash assignment to Bays 1, 2, or 3 succeeds after mechanical completion", function() use ($pdo) {
    // Make sure Bay 3 is free
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 3");

    $assignWash = BayManager::assignCarToBay($pdo, 3, 106, 4, 'Washer Sopheap', 'washer', 'Supervisor override to Bay 3');
    if (empty($assignWash['success'])) {
        throw new Exception("Assigning post-mechanical car 106 to Bay 3 failed: " . json_encode($assignWash));
    }

    $bay3 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
    if ($bay3['status'] !== 'busy' || (int)$bay3['current_order_id'] !== 106) {
        throw new Exception("Bay 3 should be busy with order 106, found: " . json_encode($bay3));
    }
});

// =========================================================================
// TEST 9: QC pass and failed QC
// =========================================================================
runTest("Test 9: Wash finish frees bay, failed QC returns to wash queue, passed QC finalizes order", function() use ($pdo) {
    // 1. Finish wash in Bay 3 -> Moves to Finishing Pad
    $washFinish = BayManager::finishCar($pdo, 3, 106, 4, 'Washer Sopheap', 'washer', 0);
    if (empty($washFinish['success']) || empty($washFinish['to_qc'])) {
        throw new Exception("Wash finish should succeed with to_qc=true: " . json_encode($washFinish));
    }

    // Bay 3 is freed immediately!
    $bay3 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
    if ($bay3['status'] !== 'free' || !empty($bay3['current_order_id'])) {
        throw new Exception("Wash Bay 3 must be freed immediately upon wash completion, found: " . json_encode($bay3));
    }

    // Car is in QC Queue
    $queues = BayManager::getQueues($pdo);
    $qcIds = array_column($queues['qc_queue'], 'order_id');
    if (!in_array(106, $qcIds)) {
        throw new Exception("Order 106 should appear in qc_queue, found: " . json_encode($qcIds));
    }

    // 2. Supervisor fails QC due to dirty rims
    $failRes = BayManager::failQc($pdo, 106, "Rims still dirty with brake dust", 2, 'Supervisor Rath', 'wash_supervisor');
    if (empty($failRes['success']) || (int)$failRes['queue_priority'] !== 1) {
        throw new Exception("failQc should succeed with queue_priority=1, got: " . json_encode($failRes));
    }

    // Verify car returned to Wash Queue
    $queuesAfterFail = BayManager::getQueues($pdo);
    $washIds = array_column($queuesAfterFail['wash_queue'], 'order_id');
    if (!in_array(106, $washIds)) {
        throw new Exception("Order 106 must return to wash_queue after failed QC.");
    }

    // Check AuditLogger for QC_FAIL
    $failLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'QC_FAIL' AND entity_id = '106'")->fetch(PDO::FETCH_ASSOC);
    if (!$failLog) {
        throw new Exception("AuditLogger must record QC_FAIL for order 106.");
    }

    // 3. Rework: Re-assign to Bay 2, re-finish wash
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 2");
    $reassignRes = BayManager::assignCarToBay($pdo, 2, 106, 4, 'Washer Sopheap', 'washer', 'Rework wash in Bay 2');
    if (empty($reassignRes['success'])) {
        throw new Exception("Rework assignment to Bay 2 failed: " . json_encode($reassignRes));
    }

    $reFinish = BayManager::finishCar($pdo, 2, 106, 4, 'Washer Sopheap', 'washer', 0);
    if (empty($reFinish['success']) || empty($reFinish['to_qc'])) {
        throw new Exception("Rework wash completion failed: " . json_encode($reFinish));
    }

    // 4. Supervisor passes QC
    $passRes = BayManager::passQc($pdo, 106, 2, 'Supervisor Rath', 'wash_supervisor');
    if (empty($passRes['success']) || $passRes['status'] !== 'finished') {
        throw new Exception("passQc should succeed with status='finished', got: " . json_encode($passRes));
    }

    // Verify 5 checklist items are all 'pass'
    $checklistItems = $pdo->query("SELECT item_key, status FROM inspection_checklists WHERE order_id = 106 AND item_key IN ('rims_tires', 'windshield', 'carpets_mats', 'door_sills', 'final_appearance')")->fetchAll(PDO::FETCH_ASSOC);
    if (count($checklistItems) !== 5) {
        throw new Exception("Must have all 5 standard QC items, found: " . count($checklistItems));
    }
    foreach ($checklistItems as $it) {
        if ($it['status'] !== 'pass') {
            throw new Exception("QC item {$it['item_key']} status must be 'pass', found: {$it['status']}");
        }
    }

    // Check AuditLogger for QC_PASS
    $passLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'QC_PASS' AND entity_id = '106'")->fetch(PDO::FETCH_ASSOC);
    if (!$passLog) {
        throw new Exception("AuditLogger must record QC_PASS for order 106.");
    }

    $finalOrder = $pdo->query("SELECT status, finished_at FROM orders WHERE id = 106")->fetch(PDO::FETCH_ASSOC);
    if ($finalOrder['status'] !== 'finished' || empty($finalOrder['finished_at'])) {
        throw new Exception("Order 106 must be finished with finished_at recorded.");
    }
});

// =========================================================================
// TEST 10: Prevention of premature customer handover
// =========================================================================
runTest("Test 10: Customer handover blocked before QC passes, permitted after QC passes", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    // Order 110: Paid, in wash queue, QC not done
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (110, 'ORD-WP2-110', '2GG-7777', 'small_car', 'paid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd) VALUES (110, 1, 'Car Wash', 5.00)")->execute();
    BayManager::ensureOrderTasks($pdo, 110);

    // Attempt customer handover before wash & QC -> MUST FAIL
    $prematureRes = BayManager::handoverToCustomer($pdo, 110, 1, 'Cashier Chenda', 'cashier');
    if (!empty($prematureRes['success']) || ($prematureRes['error'] ?? '') !== 'PREMATURE_HANDOVER') {
        throw new Exception("Premature handover should fail PREMATURE_HANDOVER, got: " . json_encode($prematureRes));
    }

    // Free Bay 1 and wash order 110
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 1");
    $assignWashRes = BayManager::assignCarToBay($pdo, 1, 110, 4, 'Washer Sopheap', 'washer');
    if (empty($assignWashRes['success'])) {
        throw new Exception("Assigning 110 to Bay 1 failed: " . json_encode($assignWashRes));
    }
    $finishWashRes = BayManager::finishCar($pdo, 1, 110, 4, 'Washer Sopheap', 'washer', 0);
    if (empty($finishWashRes['success'])) {
        throw new Exception("Finishing wash 110 in Bay 1 failed: " . json_encode($finishWashRes));
    }

    // While on Finishing Pad (awaiting QC), handover MUST STILL FAIL
    $padPrematureRes = BayManager::handoverToCustomer($pdo, 110, 1, 'Cashier Chenda', 'cashier');
    if (!empty($padPrematureRes['success']) || ($padPrematureRes['error'] ?? '') !== 'PREMATURE_HANDOVER') {
        throw new Exception("Handover while on Finishing Pad should fail PREMATURE_HANDOVER, got: " . json_encode($padPrematureRes));
    }

    // Pass QC
    BayManager::passQc($pdo, 110, 2, 'Supervisor Rath', 'wash_supervisor');

    // Customer handover now -> SUCCEEDS!
    $handoverRes = BayManager::handoverToCustomer($pdo, 110, 1, 'Cashier Chenda', 'cashier');
    if (empty($handoverRes['success'])) {
        throw new Exception("Customer handover after QC pass should succeed, got: " . json_encode($handoverRes));
    }

    $handoverLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'CUSTOMER_HANDOVER' AND entity_id = '110'")->fetch(PDO::FETCH_ASSOC);
    if (!$handoverLog) {
        throw new Exception("AuditLogger must record CUSTOMER_HANDOVER for order 110.");
    }
});

// =========================================================================
// TEST 11: Duplicate completion rejection
// =========================================================================
runTest("Test 11: Rejection of duplicate completion on finished orders and free bays", function() use ($pdo) {
    // Order 110 is already completed from Test 10
    $dupOrderRes = BayManager::finishCar($pdo, 1, 110, 4, 'Washer Sopheap', 'washer', 0);
    if (!empty($dupOrderRes['success']) || ($dupOrderRes['error'] ?? '') !== 'ORDER_ALREADY_COMPLETED') {
        throw new Exception("Duplicate finish on finished order should fail ORDER_ALREADY_COMPLETED, got: " . json_encode($dupOrderRes));
    }

    // Finishing an already free bay with explicit bay_id
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (111, 'ORD-WP2-111', '2HH-8888', 'small_car', 'paid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now]);
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 1");

    $freeBayRes = BayManager::finishCar($pdo, 1, 111, 4, 'Washer Sopheap', 'washer', 0);
    if (!empty($freeBayRes['success']) || ($freeBayRes['error'] ?? '') !== 'BAY_ALREADY_FREE') {
        throw new Exception("Finish on already free bay should fail BAY_ALREADY_FREE, got: " . json_encode($freeBayRes));
    }
});

// =========================================================================
// TEST 12: Concurrent bay assignment
// =========================================================================
runTest("Test 12: Atomic rejection of concurrent assignment to an occupied bay", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd)
        VALUES (112, 'ORD-WP2-112', '2II-9999', 'small_car', 'paid', 'waiting', ?, ?, 5.00),
               (113, 'ORD-WP2-113', '2JJ-0000', 'small_car', 'paid', 'waiting', ?, ?, 5.00)
    ")->execute([$now, $now, $now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd) VALUES (112, 1, 'Car Wash', 5.00), (113, 1, 'Car Wash', 5.00)")->execute();

    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 1");

    // First assign succeeds
    $assign1 = BayManager::assignCarToBay($pdo, 1, 112, 2, 'Supervisor Rath', 'wash_supervisor');
    if (empty($assign1['success'])) {
        throw new Exception("First assignment to Bay 1 should succeed: " . json_encode($assign1));
    }

    // Second assign to the same bay MUST FAIL with BAY_ALREADY_TAKEN
    $assign2 = BayManager::assignCarToBay($pdo, 1, 113, 2, 'Supervisor Rath', 'wash_supervisor');
    if (!empty($assign2['success']) || ($assign2['error'] ?? '') !== 'BAY_ALREADY_TAKEN') {
        throw new Exception("Concurrent assignment to busy bay should fail BAY_ALREADY_TAKEN, got: " . json_encode($assign2));
    }
});

// =========================================================================
// TEST 13: TV and queue synchronization
// =========================================================================
runTest("Test 13: TV display data and queues accurately synchronize bays, statuses, and ready cars", function() use ($pdo) {
    // Set Bay 4 busy with a mechanical order
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (id, order_number, plate_number, vehicle_type, payment_status, status, receipt_issued_at, arrived_at, total_usd, repair_status)
        VALUES (114, 'ORD-WP2-114', '2KK-1234', 'small_car', 'paid', 'waiting', ?, ?, 25.00, 'working')
    ")->execute([$now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd) VALUES (114, 2, 'Oil Change', 25.00)")->execute();
    $pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 114 WHERE id = 4");
    $pdo->exec("UPDATE orders SET bay_id = 4 WHERE id = 114");

    // Make Bay 2 and Bay 3 free
    $pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id IN (2, 3)");

    $tv = BayManager::getTvDisplayData($pdo);
    if (empty($tv['success'])) {
        throw new Exception("getTvDisplayData should succeed: " . json_encode($tv));
    }

    // Verify TV Bays: Free bays must have null plate_number
    $tvBays = $tv['bays'];
    foreach ($tvBays as $tb) {
        if (!$tb['is_busy']) {
            if ($tb['plate_number'] !== null) {
                throw new Exception("Free bay {$tb['bay_number']} must have plate_number=null to prevent stale display, got: {$tb['plate_number']}");
            }
        }
        if ($tb['bay_number'] === 4 && $tb['is_busy']) {
            if ($tb['badge_key'] !== 'mechanical_service') {
                throw new Exception("Bay 4 should have badge_key='mechanical_service', got: {$tb['badge_key']}");
            }
        }
    }

    // Verify Ready Cars on TV: Order 106 and 110 are finished and must appear in ready_cars
    $readyPlates = array_column($tv['ready_cars'], 'plate_number');
    $plate106Formatted = BayManager::formatTvPlate('2FF-6666');
    if (!in_array($plate106Formatted, $readyPlates)) {
        throw new Exception("Finished order 106 plate ({$plate106Formatted}) must appear in ready_cars, found: " . json_encode($readyPlates));
    }

    // Verify Queues
    $queues = BayManager::getQueues($pdo);
    if (!isset($queues['wash_queue']) || !isset($queues['technical_queue']) || !isset($queues['qc_queue'])) {
        throw new Exception("Queues must include wash_queue, technical_queue, and qc_queue.");
    }
});

// =========================================================================
// SUMMARY
// =========================================================================
echo "\n=======================================================\n";
echo "WP2 TEST SUITE SUMMARY: {$passCount}/{$testCount} TESTS PASSED\n";
echo "=======================================================\n";

if ($passCount === $testCount) {
    echo "SUCCESS: ALL 13 TEST CASES PASSED IN ISOLATED DATABASE!\n";
    exit(0);
} else {
    echo "ERROR: " . ($testCount - $passCount) . " TESTS FAILED!\n";
    exit(1);
}
