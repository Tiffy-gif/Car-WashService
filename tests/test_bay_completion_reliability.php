<?php
declare(strict_types=1);

/**
 * Isolated Automated Verification Test for:
 * Phase 1 — Fix Car-WashService Bay Completion & Queue Reliability
 *
 * Uses an isolated in-memory SQLite database.
 * ZERO writes to production database.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Helpers.php';
require_once dirname(__DIR__) . '/core/BayManager.php';
require_once dirname(__DIR__) . '/core/TechnicalManager.php';

echo "=================================================================\n";
echo "ISOLATED VERIFICATION: BAY COMPLETION & QUEUE RELIABILITY\n";
echo "=================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $testName, string $details = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$testName}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$testName} - {$details}\n";
        $failed++;
    }
}

// 1. Initialize clean SQLite in-memory database
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create required schema in memory
$pdo->exec("
    CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        phone TEXT,
        customer_type TEXT DEFAULT 'retail',
        membership_level TEXT DEFAULT 'regular',
        loyalty_points INTEGER DEFAULT 0
    );

    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT DEFAULT NULL,
        commission_type TEXT DEFAULT 'percentage',
        commission_rate REAL DEFAULT 0,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        category TEXT DEFAULT 'wash',
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
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'waiting',
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT NULL,
        started_at TEXT DEFAULT NULL,
        completed_at TEXT DEFAULT NULL,
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
        task_id INTEGER DEFAULT NULL,
        item_key TEXT DEFAULT '',
        item_name TEXT NOT NULL,
        is_mandatory INTEGER NOT NULL DEFAULT 1,
        status TEXT NOT NULL DEFAULT 'pass',
        photo_url TEXT DEFAULT 'uploads/inspections/sample.jpg',
        notes TEXT DEFAULT NULL,
        checked_by_id INTEGER DEFAULT NULL,
        checked_by_name TEXT DEFAULT NULL,
        checked_at TEXT DEFAULT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
");

// Insert standard staff users
$pdo->exec("
    INSERT INTO users (id, username, full_name, role, effective_role) VALUES
    (1, 'cashier1', 'Cashier Dara', 'cashier', 'cashier'),
    (2, 'washer1', 'Washer Sopheap', 'washer', 'washer'),
    (3, 'tech1', 'Tech Bunthoeun', 'mechanic', 'mechanic'),
    (4, 'owner1', 'Owner Panha', 'owner', 'owner');
");

// Insert bays: 3 wash bays, 1 mechanical bay
$pdo->exec("
    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, sort_order) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 2),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 3),
    (4, 4, 'Bay 4 (Lift)', 'oil_change', 'oil_only', 'free', 4);
");

// Insert standard services
$pdo->exec("
    INSERT INTO services (id, name_en, category, price_usd) VALUES
    (1, 'Normal Wash', 'wash', 5.00),
    (2, 'Engine Oil Change', 'oil', 25.00);
");

echo "--- TEST SUITE 1: STALE STATE ON FREE BAYS (Issue C) ---\n";
try {
    $bays = BayManager::getAllBays($pdo);
} catch (Throwable $e) {
    echo "ERROR in getAllBays: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
assertCheck(count($bays) === 4, "getAllBays returns all 4 configured bays");

foreach ($bays as $b) {
    assertCheck($b['status'] === 'free', "Bay {$b['bay_name']} status is 'free'");
    assertCheck($b['plate_number'] === null, "Bay {$b['bay_name']} plate_number is strictly null");
    assertCheck($b['current_order_id'] === null, "Bay {$b['bay_name']} current_order_id is strictly null");
    assertCheck($b['current_car'] === null, "Bay {$b['bay_name']} current_car is strictly null");
    assertCheck($b['occupied_at'] === null, "Bay {$b['bay_name']} occupied_at is strictly null");
    assertCheck($b['duration_minutes'] === 0, "Bay {$b['bay_name']} duration_minutes is 0");
}

echo "\n--- TEST SUITE 2: ZERO-WRITE QUEUE READS & TASK DEDUPLICATION (Issue D) ---\n";
// Create Order #1 with Wash Item
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, customer_name, status, payment_status, total_usd)
    VALUES (1, 'ORD-001', '2BC-1111', 'Customer One', 'waiting', 'paid', 5.00);
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (1, 1, 'Normal Wash', 5.00, 1);
");

// Count records before getQueues
$countTasksBefore = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks")->fetchColumn();
try {
    $queues1 = BayManager::getQueues($pdo);
} catch (Throwable $e) {
    echo "ERROR in getQueues: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
$countTasksAfter1 = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks")->fetchColumn();
assertCheck($countTasksBefore === 0, "order_tasks starts empty before queue read");
assertCheck($countTasksAfter1 === 0, "getQueues() is 100% read-only with ZERO database writes");
assertCheck(!empty($queues1['wash_queue']), "Wash queue detected order from in-memory synthesis");

// Explicitly call ensureOrderTasks (Order creation lifecycle event)
try {
    $createdTasks = BayManager::ensureOrderTasks($pdo, 1);
} catch (Throwable $e) {
    echo "ERROR in ensureOrderTasks: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
$countTasksAfterEnsure = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 1")->fetchColumn();
assertCheck($countTasksAfterEnsure === 1, "ensureOrderTasks successfully created exactly 1 task");

// Call ensureOrderTasks a second time to verify deduplication
$reEnsureTasks = BayManager::ensureOrderTasks($pdo, 1);
$countTasksAfterReEnsure = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 1")->fetchColumn();
assertCheck($countTasksAfterReEnsure === 1, "ensureOrderTasks deduplication: repeated calls do NOT create duplicate tasks");

echo "\n--- TEST SUITE 3: BAY ID VS ORDER ID AMBIGUITY & MISMATCH REJECTION (Issue A) ---\n";
// Create Order #2 (ID: 2) in queue, and Order #17 (ID: 17) assigned to Bay 2
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, status, payment_status, total_usd)
    VALUES (2, 'ORD-002', '2BC-2222', 'waiting', 'paid', 5.00);
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (2, 1, 'Normal Wash', 5.00, 1);

    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (17, 'ORD-017', '2BC-1717', 2, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (17, 1, 'Normal Wash', 5.00, 1);

    UPDATE bays SET status = 'busy', current_order_id = 17, occupied_at = datetime('now') WHERE id = 2;
");
BayManager::ensureOrderTasks($pdo, 2);
BayManager::ensureOrderTasks($pdo, 17);

// Negative Case 1: Pass bay_id = 2 but order_id = 2 (Attempting to finish order 2 while Bay 2 holds order 17)
$mismatchRes = BayManager::finishCar($pdo, 2, 2, 2, 'Washer Sopheap', 'washer');
assertCheck($mismatchRes['success'] === false, "Mismatch rejected successfully");
assertCheck($mismatchRes['error'] === 'BAY_ORDER_MISMATCH', "Error code is strictly BAY_ORDER_MISMATCH");

// Check that Bay 2 is STILL occupied by Order 17
$bay2Check = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay2Check['status'] === 'busy' && (int)$bay2Check['current_order_id'] === 17, "Bay 2 remains busy with Order 17 after mismatch rejection");

// Negative Case 2: Attempting to finish a bay that is already free
$alreadyFreeRes = BayManager::finishCar($pdo, 1, 1, 2, 'Washer Sopheap', 'washer');
assertCheck($alreadyFreeRes['success'] === false && $alreadyFreeRes['error'] === 'BAY_ALREADY_FREE', "Free bay completion rejected with BAY_ALREADY_FREE");

// Negative Case 3: Missing / zero order_id
$missingOrderRes = BayManager::finishCar($pdo, 2, 0, 2, 'Washer Sopheap', 'washer');
assertCheck($missingOrderRes['success'] === false && $missingOrderRes['error'] === 'ORDER_ID_REQUIRED', "Missing order_id rejected with ORDER_ID_REQUIRED");

// Positive Case: Explicit bay_id = 2 and matching order_id = 17
$validFinishRes = BayManager::finishCar($pdo, 2, 17, 2, 'Washer Sopheap', 'washer', 0);
if (!$validFinishRes['success']) {
    echo "DEBUG validFinishRes FAIL: " . json_encode($validFinishRes) . "\n";
}
assertCheck($validFinishRes['success'] === true, "Explicit matching bay_id and order_id finished successfully");
assertCheck($validFinishRes['order_status'] === 'finished', "Order 17 status transitioned to finished");

// Verify Bay 2 is now free with clean state
$bay2After = $pdo->query("SELECT status, current_order_id, occupied_at FROM bays WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay2After['status'] === 'free', "Bay 2 status is now free");
assertCheck($bay2After['current_order_id'] === null, "Bay 2 current_order_id is null");
assertCheck($bay2After['occupied_at'] === null, "Bay 2 occupied_at is null");

// Negative Case 4: Attempting to finish Order 17 again (already completed)
$repeatRes = BayManager::finishCar($pdo, 2, 17, 2, 'Washer Sopheap', 'washer');
assertCheck($repeatRes['success'] === false && $repeatRes['error'] === 'ORDER_ALREADY_COMPLETED', "Repeated finish rejected with ORDER_ALREADY_COMPLETED");

echo "\n--- TEST SUITE 4: CONCURRENT COMPLETION & CONDITIONAL UPDATES (Issue B) ---\n";
// Create Order #30 and assign to Bay 3
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (30, 'ORD-030', '2BC-3030', 3, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (30, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 30, occupied_at = datetime('now') WHERE id = 3;
");
BayManager::ensureOrderTasks($pdo, 30);

// Simulate Process A completing the car
$p1 = BayManager::finishCar($pdo, 3, 30, 2, 'Washer Sopheap', 'washer');
assertCheck($p1['success'] === true, "Process A finish succeeded");

// Simulate Process B attempting to finish the exact same bay/order concurrently
$p2 = BayManager::finishCar($pdo, 3, 30, 2, 'Washer Sopheap', 'washer');
assertCheck($p2['success'] === false, "Process B concurrent finish rejected");
assertCheck(in_array($p2['error'], ['BAY_ALREADY_FREE', 'ORDER_ALREADY_COMPLETED', 'CONCURRENT_MODIFICATION'], true), "Process B received safe conflict error ({$p2['error']})");

echo "\n--- TEST SUITE 5: MANDATORY INSPECTION PREVENTS PREMATURE BAY RELEASE ---\n";
// Create Technical Order #40 on Bay 4 with a failed mandatory checklist item
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (40, 'ORD-040', '2BC-4040', 4, 'washing', 'paid', 25.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (40, 2, 'Engine Oil Change', 25.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 40, occupied_at = datetime('now') WHERE id = 4;
    INSERT INTO inspection_checklists (order_id, item_name, is_mandatory, status)
    VALUES (40, 'Tighten Drain Plug & Filter', 1, 'failed');
");
BayManager::ensureOrderTasks($pdo, 40);

// Attempt finish before mandatory inspection passes
$inspectFailRes = BayManager::finishCar($pdo, 4, 40, 3, 'Tech Bunthoeun', 'mechanic');
assertCheck($inspectFailRes['success'] === false, "Finish rejected when mandatory inspection is incomplete");
assertCheck($inspectFailRes['error'] === 'MANDATORY_INSPECTION_INCOMPLETE', "Error is strictly MANDATORY_INSPECTION_INCOMPLETE");

// Verify Bay 4 was NOT freed and Order 40 is NOT finished
$bay4Check = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
$order40Check = $pdo->query("SELECT status FROM orders WHERE id = 40")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay4Check['status'] === 'busy' && (int)$bay4Check['current_order_id'] === 40, "Bay 4 remains busy after inspection failure");
assertCheck($order40Check['status'] !== 'finished', "Order 40 was not marked finished");

// Pass inspection checklist
$pdo->exec("UPDATE inspection_checklists SET status = 'pass', photo_url = 'uploads/inspections/pass.jpg' WHERE order_id = 40");
$inspectPassRes = BayManager::finishCar($pdo, 4, 40, 3, 'Tech Bunthoeun', 'mechanic');
assertCheck($inspectPassRes['success'] === true, "Finish succeeds once mandatory inspection passes");

$bay4After = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay4After['status'] === 'free', "Bay 4 is freed after inspection check passes");

echo "\n--- TEST SUITE 6: MIXED ORDER HANDSHAKE (Lift First — Wash Second) ---\n";
// Create Mixed Order #50 with both Oil and Wash services
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, is_mixed, total_usd, started_at)
    VALUES (50, 'ORD-050', '2BC-5050', 4, 'washing', 'paid', 1, 30.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (50, 2, 'Engine Oil Change', 25.00, 1),
           (50, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 50, occupied_at = datetime('now') WHERE id = 4;
");
$tasks50 = BayManager::ensureOrderTasks($pdo, 50);
assertCheck(count($tasks50) === 2, "Mixed order created 2 sequential tasks (oil step 1, wash step 2)");

// Technician finishes Step 1 (Oil Change)
$handshakeRes = BayManager::finishCar($pdo, 4, 50, 3, 'Tech Bunthoeun', 'mechanic', 0);
assertCheck($handshakeRes['success'] === true, "Step 1 finish succeeded");
assertCheck($handshakeRes['is_handshake'] === true, "Response confirms handshake transition");

// Check that Bay 4 is freed immediately
$bay4Mixed = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch(PDO::FETCH_ASSOC);
assertCheck($bay4Mixed['status'] === 'free' && $bay4Mixed['current_order_id'] === null, "Mechanical Bay 4 freed after Step 1");

// Check that Order 50 moved to Wash Queue
$order50State = $pdo->query("SELECT status, repair_status, bay_id FROM orders WHERE id = 50")->fetch(PDO::FETCH_ASSOC);
assertCheck($order50State['status'] === 'waiting', "Order 50 status is 'waiting' (joins Wash Queue)");
assertCheck($order50State['repair_status'] === 'finished', "Order 50 repair_status is 'finished'");
assertCheck($order50State['bay_id'] === null, "Order 50 bay_id is cleared");

// Check bay history recorded 'sent_to_wash_queue'
$histCount = (int)$pdo->query("SELECT COUNT(*) FROM bay_history WHERE order_id = 50 AND action = 'sent_to_wash_queue'")->fetchColumn();
assertCheck($histCount === 1, "bay_history recorded 'sent_to_wash_queue' event");

echo "\n--- TEST SUITE 7: API HTTP RESPONSE CODE MAPPING (Issue A & B) ---\n";
// Helper simulating API finish_car error mapping logic
function simulateApiFinishCarResponse(array $finishRes): int {
    if (!empty($finishRes['success'])) {
        return 200;
    }
    $err = $finishRes['error'] ?? '';
    if (in_array($err, ['BAY_ORDER_MISMATCH', 'BAY_ALREADY_FREE', 'ORDER_ALREADY_COMPLETED', 'CONCURRENT_MODIFICATION'], true)) {
        return 409;
    }
    if (in_array($err, ['ORDER_NOT_FOUND', 'BAY_NOT_FOUND'], true)) {
        return 404;
    }
    if ($err === 'MANDATORY_INSPECTION_INCOMPLETE') {
        return 422;
    }
    return 400;
}

$httpMismatch = simulateApiFinishCarResponse(['success' => false, 'error' => 'BAY_ORDER_MISMATCH']);
assertCheck($httpMismatch === 409, "BAY_ORDER_MISMATCH maps to HTTP 409 Conflict");

$httpAlreadyFree = simulateApiFinishCarResponse(['success' => false, 'error' => 'BAY_ALREADY_FREE']);
assertCheck($httpAlreadyFree === 409, "BAY_ALREADY_FREE maps to HTTP 409 Conflict");

$httpOrderCompleted = simulateApiFinishCarResponse(['success' => false, 'error' => 'ORDER_ALREADY_COMPLETED']);
assertCheck($httpOrderCompleted === 409, "ORDER_ALREADY_COMPLETED maps to HTTP 409 Conflict");

$httpConcurrentMod = simulateApiFinishCarResponse(['success' => false, 'error' => 'CONCURRENT_MODIFICATION']);
assertCheck($httpConcurrentMod === 409, "CONCURRENT_MODIFICATION maps to HTTP 409 Conflict");

$httpOrderNotFound = simulateApiFinishCarResponse(['success' => false, 'error' => 'ORDER_NOT_FOUND']);
assertCheck($httpOrderNotFound === 404, "ORDER_NOT_FOUND maps to HTTP 404 Not Found");

$httpInspectIncomplete = simulateApiFinishCarResponse(['success' => false, 'error' => 'MANDATORY_INSPECTION_INCOMPLETE']);
assertCheck($httpInspectIncomplete === 422, "MANDATORY_INSPECTION_INCOMPLETE maps to HTTP 422 Unprocessable Entity");

$httpMissingParams = simulateApiFinishCarResponse(['success' => false, 'error' => 'ORDER_ID_REQUIRED']);
assertCheck($httpMissingParams === 400, "ORDER_ID_REQUIRED maps to HTTP 400 Bad Request");

echo "\n--- TEST SUITE 8: PHASE 1.1 DEFECTS A, B, C COMPREHENSIVE VERIFICATION ---\n";

// Requirement 1 & 2 & 3: Signature typing & Legacy Wrapper
// Integer User ID
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (60, 'ORD-060', '2BC-6060', 1, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (60, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 60, occupied_at = datetime('now') WHERE id = 1;
");
BayManager::ensureOrderTasks($pdo, 60);

// Call with strict integer user ID
$resIntUser = BayManager::finishCar($pdo, 1, 60, 2, 'Washer Sopheap', 'washer', 0);
assertCheck($resIntUser['success'] === true, "finishCar with integer user ID succeeds");

// Numeric-string user ID coerced by PHP type-hinting safely without argument misinterpretation
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (61, 'ORD-061', '2BC-6161', 1, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (61, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 61, occupied_at = datetime('now') WHERE id = 1;
");
BayManager::ensureOrderTasks($pdo, 61);

// Passing numeric string as userId (e.g. from JSON or string session)
$resStrUser = BayManager::finishCar($pdo, 1, 61, (int)'2', 'Washer Sopheap', 'washer', 0);
assertCheck($resStrUser['success'] === true, "finishCar with numeric-string user ID succeeds safely");

// Legacy wrapper: finishCarLegacy by bay ID
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (62, 'ORD-062', '2BC-6262', 1, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (62, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 62, occupied_at = datetime('now') WHERE id = 1;
");
BayManager::ensureOrderTasks($pdo, 62);
$legacyBayRes = BayManager::finishCarLegacy($pdo, 1, 2, 'Washer Sopheap', 'washer', 0);
assertCheck($legacyBayRes['success'] === true, "finishCarLegacy by bay_id succeeds");

// Legacy wrapper: finishCarLegacy by order ID
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (63, 'ORD-063', '2BC-6363', 1, 'washing', 'paid', 5.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (63, 1, 'Normal Wash', 5.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 63, occupied_at = datetime('now') WHERE id = 1;
");
BayManager::ensureOrderTasks($pdo, 63);
$legacyOrderRes = BayManager::finishCarLegacy($pdo, 63, 2, 'Washer Sopheap', 'washer', 0);
assertCheck($legacyOrderRes['success'] === true, "finishCarLegacy by order_id succeeds");

// TechnicalManager caller verification: finishCar(pdo, null, orderId, ...)
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, bay_id, status, payment_status, total_usd, started_at)
    VALUES (64, 'ORD-064', '2BC-6464', 4, 'washing', 'paid', 25.00, datetime('now'));
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (64, 2, 'Engine Oil Change', 25.00, 1);
    UPDATE bays SET status = 'busy', current_order_id = 64, occupied_at = datetime('now') WHERE id = 4;
    INSERT INTO inspection_checklists (order_id, item_name, is_mandatory, status, photo_url)
    VALUES (64, 'Oil Check', 1, 'pass', 'uploads/test.jpg');
");
BayManager::ensureOrderTasks($pdo, 64);
$techCallerRes = BayManager::finishCar($pdo, null, 64, 3, 'Tech Bunthoeun', 'mechanic', 0);
assertCheck($techCallerRes['success'] === true, "TechnicalManager caller pattern finishCar(pdo, null, orderId, ...) succeeds");

// Requirement 6: Existing duplicate records in order_tasks preserved without corruption
// Emulate historical Order 25 having duplicate tasks from pre-patch race condition
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, status, payment_status, total_usd)
    VALUES (25, 'ORD-025', '2BC-2525', 'waiting', 'paid', 5.00);
    INSERT INTO order_tasks (id, order_id, sequence, task_type, title, status)
    VALUES (101, 25, 1, 'wash', 'Car Wash Service', 'waiting'),
           (102, 25, 1, 'wash', 'Car Wash Service', 'waiting');
");
$dupTasksFetched = BayManager::ensureOrderTasks($pdo, 25);
assertCheck(count($dupTasksFetched) === 2, "ensureOrderTasks preserves existing duplicate tasks without altering or deleting IDs");
assertCheck((int)$dupTasksFetched[0]['id'] === 101 && (int)$dupTasksFetched[1]['id'] === 102, "Existing task IDs 101 and 102 remain intact");

$readOnlyDup = BayManager::getOrderTasksReadOnly($pdo, 25);
assertCheck(count($readOnlyDup) === 2, "getOrderTasksReadOnly returns existing duplicate records faithfully without modifying database");

// Requirement 7: Historical mixed order with repair_status = 'finished'
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, is_mixed, repair_status, status, payment_status, total_usd)
    VALUES (70, 'ORD-070', '2BC-7070', 1, 'finished', 'waiting', 'paid', 30.00);
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (70, 2, 'Engine Oil Change', 25.00, 1),
           (70, 1, 'Normal Wash', 5.00, 1);
");
$tasksHistFinished = BayManager::getOrderTasksReadOnly($pdo, 70);
assertCheck($tasksHistFinished[0]['task_type'] === 'oil_change' && $tasksHistFinished[0]['status'] === 'completed', 
    "Historical mixed order with repair_status='finished': Step 1 oil_change synthesized as 'completed'");
assertCheck($tasksHistFinished[1]['task_type'] === 'wash' && $tasksHistFinished[1]['status'] === 'waiting', 
    "Historical mixed order with repair_status='finished': Step 2 wash synthesized as 'waiting'");

$queuesCheckFinished = BayManager::getQueues($pdo);
$inWashQueue70 = false;
foreach ($queuesCheckFinished['wash_queue'] as $wq) {
    if ((int)$wq['order_id'] === 70) $inWashQueue70 = true;
}
$inTechQueue70 = false;
foreach ($queuesCheckFinished['technical_queue'] as $tq) {
    if ((int)$tq['order_id'] === 70) $inTechQueue70 = true;
}
assertCheck($inWashQueue70 === true, "Historical order with repair_status='finished' appears in Wash Queue");
assertCheck($inTechQueue70 === false, "Historical order with repair_status='finished' does NOT return to Technical Queue");

// Requirement 8: Historical mixed order with repair_status NOT finished (null / in_progress)
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, is_mixed, repair_status, status, payment_status, total_usd)
    VALUES (71, 'ORD-071', '2BC-7171', 1, 'in_progress', 'waiting', 'paid', 30.00);
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (71, 2, 'Engine Oil Change', 25.00, 1),
           (71, 1, 'Normal Wash', 5.00, 1);
");
$tasksHistUnfinished = BayManager::getOrderTasksReadOnly($pdo, 71);
assertCheck($tasksHistUnfinished[0]['task_type'] === 'oil_change' && $tasksHistUnfinished[0]['status'] === 'waiting', 
    "Historical mixed order with repair_status='in_progress': Step 1 oil_change synthesized as 'waiting'");
assertCheck($tasksHistUnfinished[1]['task_type'] === 'wash' && $tasksHistUnfinished[1]['status'] === 'pending_prereq', 
    "Historical mixed order with repair_status='in_progress': Step 2 wash synthesized as 'pending_prereq'");

$queuesCheckUnfinished = BayManager::getQueues($pdo);
$inTechQueue71 = false;
foreach ($queuesCheckUnfinished['technical_queue'] as $tq) {
    if ((int)$tq['order_id'] === 71) $inTechQueue71 = true;
}
$inWashQueue71 = false;
foreach ($queuesCheckUnfinished['wash_queue'] as $wq) {
    if ((int)$wq['order_id'] === 71) $inWashQueue71 = true;
}
assertCheck($inTechQueue71 === true, "Historical order with unfinished mechanical repair appears in Technical Queue (Lift First)");
assertCheck($inWashQueue71 === false, "Historical order with unfinished mechanical repair does NOT appear in Wash Queue yet");

// Requirement 9: Read-only queue retrieval with zero database writes
$tasksCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks")->fetchColumn();
$ordersCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
BayManager::getQueues($pdo);
$tasksCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks")->fetchColumn();
$ordersCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
assertCheck($tasksCountBefore === $tasksCountAfter, "Queue retrieval causes 0 inserts/deletes in order_tasks");
assertCheck($ordersCountBefore === $ordersCountAfter, "Queue retrieval causes 0 inserts/deletes in orders");

// Requirement 10: Existing mechanical-first and wash-only workflows
// Wash-only order
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, is_mixed, status, payment_status, total_usd)
    VALUES (72, 'ORD-072', '2BC-7272', 0, 'waiting', 'paid', 5.00);
    INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty)
    VALUES (72, 1, 'Normal Wash', 5.00, 1);
");
$washOnlyTasks = BayManager::ensureOrderTasks($pdo, 72);
assertCheck(count($washOnlyTasks) === 1 && $washOnlyTasks[0]['task_type'] === 'wash', "Wash-only order generates exactly 1 wash task, skipping mechanical bay");

// Requirement 4 & 5: Repeated & Multi-Connection Task Creation Prevention
// Repeated task creation sequentially: 5 calls produce exact same tasks, no duplicates
for ($i = 0; $i < 5; $i++) {
    $repTasks = BayManager::ensureOrderTasks($pdo, 72);
}
$count72 = (int)$pdo->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 72")->fetchColumn();
assertCheck($count72 === 1, "Repeated ensureOrderTasks() calls (5x) produce exactly 1 task record, zero duplicates");

// Multi-connection task creation on isolated file database
$tempFile = sys_get_temp_dir() . '/temp_carwash_' . uniqid() . '.sqlite';
try {
    $conn1 = new PDO('sqlite:' . $tempFile);
    $conn1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn1->exec("
        CREATE TABLE orders (id INTEGER PRIMARY KEY, is_mixed INTEGER DEFAULT 0, repair_status TEXT, next_oil_mileage INTEGER, repair_notes TEXT);
        CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, service_id INTEGER, product_id INTEGER, service_name TEXT);
        CREATE TABLE order_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, task_type TEXT, title TEXT, sequence INTEGER, status TEXT, created_at TEXT, updated_at TEXT);
        CREATE TABLE services (id INTEGER PRIMARY KEY, category TEXT);
        CREATE TABLE products (id INTEGER PRIMARY KEY, category TEXT);
        INSERT INTO orders (id) VALUES (100);
        INSERT INTO order_items (order_id, service_name) VALUES (100, 'Normal Wash');
    ");

    $conn2 = new PDO('sqlite:' . $tempFile);
    $conn2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Connection 1 ensures tasks
    $tConn1 = BayManager::ensureOrderTasks($conn1, 100);
    // Connection 2 ensures tasks
    $tConn2 = BayManager::ensureOrderTasks($conn2, 100);

    $totalConnTasks = (int)$conn1->query("SELECT COUNT(*) FROM order_tasks WHERE order_id = 100")->fetchColumn();
    assertCheck($totalConnTasks === 1, "Separate database connections (conn1 & conn2) ensure tasks without duplicate creation");
} finally {
    if (file_exists($tempFile)) {
        @unlink($tempFile);
    }
}

echo "\n=================================================================\n";
echo "TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
