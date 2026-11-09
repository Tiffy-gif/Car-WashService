<?php
/**
 * Phase 2 — Business Workflow & Integration Verification Test Suite
 * 
 * Verifies all 10 core business workflow scenarios on an isolated temporary SQLite database:
 * 1. Wash-only order (skips mechanical service)
 * 2. Mechanical-only order (skips washing)
 * 3. Mixed order (Lift First — Wash Second handshake)
 * 4. Unpaid order attempting service admission (rejected with PAYMENT_REQUIRED)
 * 5. Paid order with electronic receipt (admitted to bay)
 * 6. Failed physical receipt printing (admitted to bay, printing is advisory)
 * 7. Failed technical inspection (Bay 4 release blocked)
 * 8. Successful mechanical completion followed by washing (complete end-to-end handshake)
 * 9. Duplicate completion request (rejected with ORDER_ALREADY_COMPLETED)
 * 10. Two orders competing for the same bay (rejected with BAY_ALREADY_TAKEN)
 */

declare(strict_types=1);

$tempDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_phase2_workflow_' . uniqid() . '.sqlite';
echo "=================================================================\n";
echo "PHASE 2 BUSINESS WORKFLOW & INTEGRATION VERIFICATION\n";
echo "Isolated Temp DB: {$tempDb}\n";
echo "=================================================================\n\n";

$pdo = new PDO('sqlite:' . $tempDb);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;");

// Polyfill functions
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
$pdo->sqliteCreateFunction('CURRENT_DATE', fn() => date('Y-m-d'));

require_once 'C:/xampp/htdocs/Car-WashService/core/Database.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/OrderGate.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/BayManager.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/TechnicalManager.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/NotificationManager.php';
require_once 'C:/xampp/htdocs/Car-WashService/core/AuditLogger.php';

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

$refDriver = new ReflectionProperty('Database', 'driver');
$refDriver->setAccessible(true);
$refDriver->setValue(null, 'sqlite');

// Initialize schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_kh TEXT DEFAULT '',
        category TEXT DEFAULT 'wash',
        base_duration_minutes INTEGER DEFAULT 30,
        price_usd REAL DEFAULT 5.00,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        category TEXT DEFAULT 'general',
        price_usd REAL DEFAULT 0
    );

    CREATE TABLE bays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bay_number INTEGER NOT NULL,
        bay_name TEXT NOT NULL,
        bay_type TEXT NOT NULL DEFAULT 'wash',
        skill_tag TEXT DEFAULT 'wash_only',
        status TEXT NOT NULL DEFAULT 'free',
        current_order_id INTEGER DEFAULT NULL,
        occupied_at TEXT DEFAULT NULL,
        last_freed_at TEXT DEFAULT NULL,
        sort_order INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL,
        order_uid TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        customer_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT NULL,
        customer_phone TEXT DEFAULT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        bay_id INTEGER DEFAULT NULL,
        status TEXT NOT NULL DEFAULT 'waiting',
        payment_status TEXT NOT NULL DEFAULT 'unpaid',
        payment_method TEXT DEFAULT 'cash',
        subtotal_usd REAL DEFAULT 0,
        total_usd REAL DEFAULT 0,
        total_khr INTEGER DEFAULT 0,
        repair_status TEXT DEFAULT NULL,
        repair_notes TEXT DEFAULT NULL,
        next_oil_mileage INTEGER DEFAULT NULL,
        estimated_duration_minutes INTEGER DEFAULT 25,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        arrived_at TEXT DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        finished_at TEXT DEFAULT NULL,
        paid_at TEXT DEFAULT NULL,
        receipt_printed_at TEXT DEFAULT NULL,
        receipt_issued_at TEXT DEFAULT NULL,
        is_express INTEGER DEFAULT 0,
        is_mixed INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        queue_priority INTEGER DEFAULT 0,
        priority_level TEXT DEFAULT 'P3',
        appointment_time TEXT DEFAULT NULL,
        idempotency_key TEXT DEFAULT NULL,
        pos_device_id TEXT DEFAULT NULL,
        pos_device_name TEXT DEFAULT NULL,
        source TEXT DEFAULT 'pos_app',
        delay_reason TEXT DEFAULT NULL,
        delay_logged_at DATETIME DEFAULT NULL,
        delay_logged_by INTEGER DEFAULT NULL,
        suggested_bay_id INTEGER DEFAULT NULL,
        bay_override_reason TEXT DEFAULT NULL,
        MINUTE TEXT DEFAULT 'MINUTE',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        item_type TEXT DEFAULT 'service',
        service_name TEXT NOT NULL,
        price_usd REAL DEFAULT 0,
        price_khr INTEGER DEFAULT 0,
        qty INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

    CREATE TABLE order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        bay_id INTEGER DEFAULT NULL,
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'waiting',
        assigned_user_id INTEGER DEFAULT NULL,
        base_duration_minutes INTEGER DEFAULT 15,
        estimated_duration_minutes INTEGER DEFAULT 15,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        completed_at TEXT DEFAULT NULL,
        MINUTE TEXT DEFAULT 'MINUTE',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE bay_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        bay_id INTEGER DEFAULT NULL,
        bay_name TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        action TEXT NOT NULL,
        performed_by INTEGER DEFAULT NULL,
        performed_by_name TEXT DEFAULT NULL,
        performed_by_role TEXT DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE inspection_checklists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        item_name TEXT NOT NULL,
        is_mandatory INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'pass',
        photo_url TEXT DEFAULT 'uploads/inspections/sample.jpg',
        notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE service_duration_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER DEFAULT NULL,
        service_name TEXT NOT NULL,
        vehicle_type TEXT NOT NULL,
        order_id INTEGER NOT NULL,
        task_id INTEGER DEFAULT NULL,
        estimated_minutes INTEGER NOT NULL,
        actual_minutes INTEGER NOT NULL,
        over_percentage REAL NOT NULL,
        washer_answered_longer INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending',
        reviewed_by INTEGER DEFAULT NULL,
        reviewed_at TEXT DEFAULT NULL,
        resolution_notes TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        role TEXT DEFAULT NULL,
        title TEXT,
        message TEXT,
        type TEXT DEFAULT 'info',
        is_read INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE system_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        notification_uid TEXT NOT NULL UNIQUE,
        target_role TEXT DEFAULT NULL,
        target_user_id INTEGER DEFAULT NULL,
        category TEXT NOT NULL,
        title_en TEXT NOT NULL,
        title_km TEXT DEFAULT NULL,
        message_en TEXT NOT NULL,
        message_km TEXT DEFAULT NULL,
        action_url TEXT DEFAULT NULL,
        sound_type TEXT DEFAULT 'chime',
        reference_id TEXT DEFAULT NULL,
        is_read INTEGER DEFAULT 0,
        read_at TEXT DEFAULT NULL,
        read_by_id INTEGER DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
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

    INSERT INTO users (id, username, full_name, role, effective_role) VALUES
    (1, 'cashier1', 'Cashier Dara', 'cashier', 'cashier'),
    (2, 'washer1', 'Washer Sopheap', 'washer', 'washer'),
    (3, 'tech1', 'Tech Bunthoeun', 'mechanic', 'mechanic'),
    (4, 'owner1', 'Owner Panha', 'owner', 'owner');

    INSERT INTO services (id, name_en, category, price_usd) VALUES
    (1, 'Full Exterior Wash', 'wash', 5.00),
    (2, 'Engine Oil Change & Filter', 'maintenance', 25.00);

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, sort_order) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 2),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 3),
    (4, 4, 'Bay 4 (Lift)', 'oil_change', 'oil_only', 'free', 4);

    INSERT INTO settings (setting_key, setting_value) VALUES
    ('require_receipt_before_wash', '1'),
    ('estimate_buffer_percent', '10');
");

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$label}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$label}\n";
        $failed++;
    }
}

// =================================================================
// SCENARIO 1: WASH-ONLY ORDER
// =================================================================
echo "--- SCENARIO 1: WASH-ONLY ORDER ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (1, 'ORD-WASH-001', '2AA-1111', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (1, 1, 'Full Exterior Wash', 'service')");

$tasks1 = BayManager::ensureOrderTasks($pdo, 1);
assertCheck(count($tasks1) === 1, "Wash-only order creates exactly 1 task");
assertCheck($tasks1[0]['task_type'] === 'wash', "Task type is 'wash'");
assertCheck($tasks1[0]['sequence'] === 1, "Sequence is 1");
$order1 = $pdo->query("SELECT is_mixed FROM orders WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$order1['is_mixed'] === 0, "Order is_mixed is 0 (skips mechanical service)");

// =================================================================
// SCENARIO 2: MECHANICAL-ONLY ORDER
// =================================================================
echo "\n--- SCENARIO 2: MECHANICAL-ONLY ORDER ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (2, 'ORD-MECH-002', '2BB-2222', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (2, 2, 'Engine Oil Change & Filter', 'service')");

$tasks2 = BayManager::ensureOrderTasks($pdo, 2);
assertCheck(count($tasks2) === 1, "Mechanical-only order creates exactly 1 task");
assertCheck($tasks2[0]['task_type'] === 'oil_change', "Task type is 'oil_change'");
assertCheck($tasks2[0]['sequence'] === 1, "Sequence is 1");
$order2 = $pdo->query("SELECT is_mixed FROM orders WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$order2['is_mixed'] === 0, "Order is_mixed is 0 (skips washing)");

// =================================================================
// SCENARIO 3: MIXED ORDER (LIFT FIRST — WASH SECOND)
// =================================================================
echo "\n--- SCENARIO 3: MIXED ORDER (LIFT FIRST — WASH SECOND) ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (3, 'ORD-MIX-003', '2CC-3333', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (3, 2, 'Engine Oil Change & Filter', 'service')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (3, 1, 'Full Exterior Wash', 'service')");

$tasks3 = BayManager::ensureOrderTasks($pdo, 3);
assertCheck(count($tasks3) === 2, "Mixed order creates exactly 2 sequential tasks");
assertCheck($tasks3[0]['task_type'] === 'oil_change' && $tasks3[0]['sequence'] === 1, "Step 1 is oil_change (Lift First)");
assertCheck($tasks3[0]['status'] === 'waiting', "Step 1 status is 'waiting'");
assertCheck($tasks3[1]['task_type'] === 'wash' && $tasks3[1]['sequence'] === 2, "Step 2 is wash (Wash Second)");
assertCheck($tasks3[1]['status'] === 'pending_prereq', "Step 2 status is 'pending_prereq'");
$order3 = $pdo->query("SELECT is_mixed FROM orders WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$order3['is_mixed'] === 1, "Order is_mixed is 1");

// Verify Wash Bay 1 rejects admission because mechanical step is incomplete
$washBayReject = BayManager::assignCarToBay($pdo, 1, 3, 1, 'Cashier Dara', 'cashier');
assertCheck($washBayReject['success'] === false, "Wash Bay 1 rejects mixed order with incomplete mechanical step");
assertCheck($washBayReject['error'] === 'PREREQUISITE_STEP_INCOMPLETE', "Error is strictly PREREQUISITE_STEP_INCOMPLETE");

// =================================================================
// SCENARIO 4: UNPAID ORDER ATTEMPTING ADMISSION
// =================================================================
echo "\n--- SCENARIO 4: UNPAID ORDER ATTEMPTING ADMISSION ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (4, 'ORD-UNPAID-004', '2DD-4444', 'waiting', 'unpaid', NULL)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (4, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 4);

$unpaidAssign = BayManager::assignCarToBay($pdo, 1, 4, 1, 'Cashier Dara', 'cashier');
assertCheck($unpaidAssign['success'] === false, "Unpaid order admission rejected");
assertCheck($unpaidAssign['error'] === 'PAYMENT_REQUIRED', "Error code is strictly PAYMENT_REQUIRED (HTTP 402)");
$bay1 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay1['status'] === 'free' && empty($bay1['current_order_id']), "Bay 1 remains cleanly free");

// =================================================================
// SCENARIO 5: PAID ORDER WITH ELECTRONIC RECEIPT
// =================================================================
echo "\n--- SCENARIO 5: PAID ORDER WITH ELECTRONIC RECEIPT ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (5, 'ORD-ELEC-005', '2EE-5555', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (5, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 5);

$paidAssign = BayManager::assignCarToBay($pdo, 1, 5, 1, 'Cashier Dara', 'cashier');
if (!$paidAssign['success']) {
    echo "SCENARIO 5 ERROR: " . json_encode($paidAssign) . "\n";
}
assertCheck($paidAssign['success'] === true, "Paid order with electronic receipt admitted successfully");
$bay1After = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay1After['status'] === 'busy' && (int)$bay1After['current_order_id'] === 5, "Bay 1 is now busy with Order 5");
// Clean up Bay 1 for next tests
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 1");

// =================================================================
// SCENARIO 6: FAILED PHYSICAL RECEIPT PRINTING (PRINTING IS ADVISORY)
// =================================================================
echo "\n--- SCENARIO 6: FAILED PHYSICAL RECEIPT PRINTING ---\n";
// receipt_issued_at IS SET, but receipt_printed_at IS NULL (printer jammed / failed)
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at, receipt_printed_at) VALUES (6, 'ORD-NOPRINT-006', '2FF-6666', 'waiting', 'paid', CURRENT_TIMESTAMP, NULL)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (6, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 6);

$advisoryAssign = BayManager::assignCarToBay($pdo, 1, 6, 1, 'Cashier Dara', 'cashier');
assertCheck($advisoryAssign['success'] === true, "Admission succeeds without physical print (Physical printing is advisory only)");
$bay1After6 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay1After6['status'] === 'busy' && (int)$bay1After6['current_order_id'] === 6, "Bay 1 occupied by Order 6 despite printer failure");
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 1");

// =================================================================
// SCENARIO 7: FAILED TECHNICAL INSPECTION (BAY 4 RELEASE BLOCKED)
// =================================================================
echo "\n--- SCENARIO 7: FAILED TECHNICAL INSPECTION ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at, bay_id, repair_status) VALUES (7, 'ORD-INSPFAIL-007', '2GG-7777', 'in_progress', 'paid', CURRENT_TIMESTAMP, 4, 'in_progress')");
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 7, occupied_at = CURRENT_TIMESTAMP WHERE id = 4");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (7, 2, 'Engine Oil Change & Filter', 'service')");
BayManager::ensureOrderTasks($pdo, 7);

// Insert an incomplete inspection item (mandatory item marked 'fail')
$pdo->exec("INSERT INTO inspection_checklists (order_id, item_name, is_mandatory, status, photo_url) VALUES (7, 'Brake Fluid Check', 1, 'fail', '')");

$inspectFailFinish = BayManager::finishCar($pdo, 4, 7, 3, 'Tech Bunthoeun', 'mechanic', 0);
assertCheck($inspectFailFinish['success'] === false, "Finish rejected when mandatory inspection is incomplete");
assertCheck($inspectFailFinish['error'] === 'MANDATORY_INSPECTION_INCOMPLETE', "Error is strictly MANDATORY_INSPECTION_INCOMPLETE");
$bay4 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay4['status'] === 'busy' && (int)$bay4['current_order_id'] === 7, "Bay 4 remains busy and is NOT released prematurely");
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 4");

// =================================================================
// SCENARIO 8: SUCCESSFUL MECHANICAL COMPLETION FOLLOWED BY WASHING
// =================================================================
echo "\n--- SCENARIO 8: SUCCESSFUL MECHANICAL COMPLETION FOLLOWED BY WASHING ---\n";
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at, is_mixed) VALUES (8, 'ORD-HANDSHAKE-008', '2HH-8888', 'waiting', 'paid', CURRENT_TIMESTAMP, 1)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (8, 2, 'Engine Oil Change & Filter', 'service')");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (8, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 8);

// Step 8.1: Assign to Mechanical Bay 4 (Lift First)
$assignLift = BayManager::assignCarToBay($pdo, 4, 8, 1, 'Cashier Dara', 'cashier');
assertCheck($assignLift['success'] === true, "Order 8 admitted to Mechanical Bay 4");

// Step 8.2: Complete mechanical work with valid inspection checklist
$pdo->exec("INSERT INTO inspection_checklists (order_id, item_name, is_mandatory, status, photo_url) VALUES (8, 'Brake Check', 1, 'pass', 'uploads/sample.jpg')");
$finishLift = BayManager::finishCar($pdo, 4, 8, 3, 'Tech Bunthoeun', 'mechanic', 0);
assertCheck($finishLift['success'] === true, "Step 1 mechanical service finished");
assertCheck(!empty($finishLift['is_handshake']), "Result confirms handshake transition (is_handshake === true)");

// Verify Bay 4 is freed and Order 8 joined Wash Queue
$bay4Post = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay4Post['status'] === 'free' && empty($bay4Post['current_order_id']), "Mechanical Bay 4 is now Free");
$order8Post = $pdo->query("SELECT status, repair_status, bay_id FROM orders WHERE id = 8")->fetch(PDO::FETCH_ASSOC);
assertCheck($order8Post['status'] === 'waiting', "Order status is 'waiting'");
assertCheck($order8Post['repair_status'] === 'finished', "Order repair_status is 'finished'");
assertCheck(empty($order8Post['bay_id']), "Order bay_id is cleared (in queue)");

// Verify Step 2 task transitioned to waiting
$task8Wash = $pdo->query("SELECT status FROM order_tasks WHERE order_id = 8 AND task_type = 'wash'")->fetch(PDO::FETCH_ASSOC);
assertCheck($task8Wash['status'] === 'waiting', "Wash task transitioned from 'pending_prereq' to 'waiting'");

// Step 8.3: Assign Order 8 to Wash Bay 2 with supervisor override reason
$assignWash = BayManager::assignCarToBay($pdo, 2, 8, 1, 'Cashier Dara', 'cashier', 'Supervisor selected Bay 2 for detailing rinse');
assertCheck($assignWash['success'] === true, "Order 8 admitted to Wash Bay 2 (Step 2)");

// Step 8.4: Finish wash in Bay 2
$finishWash = BayManager::finishCar($pdo, 2, 8, 2, 'Washer Sopheap', 'washer', 0);
assertCheck($finishWash['success'] === true, "Step 2 wash completed");
assertCheck(empty($finishWash['is_handshake']), "Final completion (is_handshake is false)");

$order8Final = $pdo->query("SELECT status, finished_at FROM orders WHERE id = 8")->fetch(PDO::FETCH_ASSOC);
assertCheck($order8Final['status'] === 'finished', "Order 8 status is now 'finished' (Ready for customer handover)");
assertCheck(!empty($order8Final['finished_at']), "Order finished_at timestamp is recorded");

// =================================================================
// SCENARIO 9: DUPLICATE COMPLETION REQUEST
// =================================================================
echo "\n--- SCENARIO 9: DUPLICATE COMPLETION REQUEST ---\n";
$dupFinish = BayManager::finishCar($pdo, 2, 8, 2, 'Washer Sopheap', 'washer', 0);
assertCheck($dupFinish['success'] === false, "Duplicate completion request rejected");
assertCheck($dupFinish['error'] === 'ORDER_ALREADY_COMPLETED', "Error is strictly ORDER_ALREADY_COMPLETED");

// =================================================================
// SCENARIO 10: TWO ORDERS COMPETING FOR THE SAME BAY
// =================================================================
echo "\n--- SCENARIO 10: TWO ORDERS COMPETING FOR SAME BAY ---\n";
// Bay 3 is occupied by Order 10A
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (10, 'ORD-COMP-010', '2II-1010', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (10, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 10);

$assign10A = BayManager::assignCarToBay($pdo, 3, 10, 1, 'Cashier Dara', 'cashier', 'Supervisor assigned Bay 3');
assertCheck($assign10A['success'] === true, "Order 10 admitted to Bay 3");

// Order 11 attempts to enter Bay 3 while occupied
$pdo->exec("INSERT INTO orders (id, order_number, plate_number, status, payment_status, receipt_issued_at) VALUES (11, 'ORD-COMP-011', '2JJ-1111', 'waiting', 'paid', CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO order_items (order_id, service_id, service_name, item_type) VALUES (11, 1, 'Full Exterior Wash', 'service')");
BayManager::ensureOrderTasks($pdo, 11);

$assign11B = BayManager::assignCarToBay($pdo, 3, 11, 1, 'Cashier Dara', 'cashier', 'Direct assignment attempt');
assertCheck($assign11B['success'] === false, "Competing Order 11 rejected from occupied Bay 3");
assertCheck($assign11B['error'] === 'BAY_ALREADY_TAKEN', "Error code is strictly BAY_ALREADY_TAKEN");
$bay3 = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
assertCheck((int)$bay3['current_order_id'] === 10, "Bay 3 remains exclusively assigned to Order 10");

// Clean up
unset($pdo);
@unlink($tempDb);
@unlink($tempDb . '-wal');
@unlink($tempDb . '-shm');

echo "\n=================================================================\n";
echo "PHASE 2 WORKFLOW INTEGRATION SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

exit($failed > 0 ? 1 : 0);
