<?php
declare(strict_types=1);

/**
 * Phase 2.1 Critical Business Rule Corrections Regression Test Suite
 *
 * Strictly isolated temporary SQLite database tests.
 * Zero writes to MariaDB 'carwash_db'.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_phase2_1_rules_' . uniqid() . '.sqlite';
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

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

$refDriver = new ReflectionProperty('Database', 'driver');
$refDriver->setAccessible(true);
$refDriver->setValue(null, 'sqlite');

// Minimal isolated schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        effective_role TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        local_pin_hash TEXT DEFAULT NULL,
        password_hash TEXT DEFAULT NULL
    );

    CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT,
        phone TEXT,
        membership_level TEXT DEFAULT 'regular',
        loyalty_points INTEGER DEFAULT 0
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

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL UNIQUE,
        customer_id INTEGER DEFAULT NULL,
        customer_name TEXT DEFAULT NULL,
        customer_phone TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'sedan',
        total_usd REAL DEFAULT 0.0,
        total_khr INTEGER DEFAULT 0,
        payment_status TEXT DEFAULT 'unpaid',
        payment_method TEXT DEFAULT NULL,
        status TEXT DEFAULT 'waiting',
        repair_status TEXT DEFAULT NULL,
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
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        service_name TEXT,
        item_type TEXT DEFAULT 'service',
        qty INTEGER DEFAULT 1,
        price_usd REAL DEFAULT 0.0
    );

    CREATE TABLE order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_type TEXT NOT NULL,
        sequence INTEGER DEFAULT 1,
        status TEXT DEFAULT 'waiting',
        bay_id INTEGER DEFAULT NULL,
        assigned_user_id INTEGER DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
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

    CREATE TABLE IF NOT EXISTS parts_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_uid TEXT NOT NULL,
        order_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        product_name TEXT NOT NULL,
        qty_requested INTEGER DEFAULT 1,
        status TEXT DEFAULT 'pending',
        requested_by_id INTEGER DEFAULT NULL,
        requested_by_name TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS order_washers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        washer_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS order_mechanics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        mechanic_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER DEFAULT NULL,
        plate_number TEXT DEFAULT NULL,
        appointment_date DATE DEFAULT CURRENT_DATE,
        appointment_time DATETIME DEFAULT NULL,
        status TEXT DEFAULT 'pending'
    );

    CREATE TABLE settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );

    CREATE TABLE job_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL UNIQUE,
        job_number TEXT NOT NULL,
        assigned_technician_id INTEGER DEFAULT NULL,
        assigned_technician_name TEXT DEFAULT NULL,
        status TEXT DEFAULT 'pending',
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE technical_inspections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        item_key TEXT NOT NULL,
        item_label TEXT NOT NULL,
        is_mandatory INTEGER DEFAULT 1,
        is_checked INTEGER DEFAULT 0,
        checked_by_id INTEGER DEFAULT NULL,
        checked_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    INSERT INTO settings (setting_key, setting_value) VALUES
    ('require_receipt_before_wash', '1'),
    ('estimate_buffer_percent', '10'),
    ('tv_display_language', 'both');

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, sort_order) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 2),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 3),
    (4, 4, 'Bay 4 (Lift)', 'oil_change', 'oil_only', 'free', 4);
");

// Insert Manager user with PIN 1234
$mgrPinHash = password_hash('1234', PASSWORD_BCRYPT);
$pdo->prepare("
    INSERT INTO users (id, username, full_name, role, effective_role, local_pin_hash, password_hash)
    VALUES (10, 'boss_sophea', 'Manager Sophea', 'manager', 'manager', ?, ?)
")->execute([$mgrPinHash, $mgrPinHash]);

require_once __DIR__ . '/../core/OrderGate.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/AuditLogger.php';
require_once __DIR__ . '/../core/SmartQueueEngine.php';

$passed = 0;
$failed = 0;

function runCheck(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$label}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$label}\n";
    }
}

echo "=================================================================\n";
echo "PHASE 2.1 CRITICAL BUSINESS RULE CORRECTIONS REGRESSION TEST\n";
echo "Isolated Temp DB: {$tmpDb}\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// TEST 1: Unpaid order cannot enter service
// -------------------------------------------------------------
echo "--- TEST 1: UNPAID ORDER CANNOT ENTER SERVICE ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status)
    VALUES (101, 'ORD-101', '2A-1111', 'unpaid', NULL, 'waiting')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (101, 'wash', 1, 'waiting')
")->execute();

$admissible = false;
$errorReceived = null;
try {
    OrderGate::assertServiceAdmissible($pdo, 101);
    $admissible = true;
} catch (PaymentRequiredException $pe) {
    $errorReceived = 'PAYMENT_REQUIRED';
} catch (Throwable $e) {
    $errorReceived = $e->getMessage();
}
runCheck(!$admissible, "Unpaid order admission rejected");
runCheck($errorReceived === 'PAYMENT_REQUIRED', "Rejection error is strictly PAYMENT_REQUIRED (HTTP 402)");

// -------------------------------------------------------------
// TEST 2: Manager PIN cannot bypass payment confirmation
// -------------------------------------------------------------
echo "\n--- TEST 2: MANAGER PIN CANNOT BYPASS PAYMENT CONFIRMATION ---\n";
$admissibleWithPin = false;
$errorWithPin = null;
try {
    // Attempting to bypass unpaid status with valid manager PIN
    OrderGate::assertServiceAdmissible($pdo, 101, '1234', 'Manager authorized rush wash', 'boss_sophea');
    $admissibleWithPin = true;
} catch (PaymentRequiredException $pe) {
    $errorWithPin = 'PAYMENT_REQUIRED';
} catch (Throwable $e) {
    $errorWithPin = $e->getMessage();
}
runCheck(!$admissibleWithPin, "Manager PIN cannot bypass payment confirmation");
runCheck($errorWithPin === 'PAYMENT_REQUIRED', "Unpaid order with Manager PIN still strictly throws PAYMENT_REQUIRED");

// -------------------------------------------------------------
// TEST 3: Manager PIN cannot bypass electronic receipt issuance
// -------------------------------------------------------------
echo "\n--- TEST 3: MANAGER PIN CANNOT BYPASS ELECTRONIC RECEIPT ISSUANCE ---\n";
// Order is paid upfront, but electronic receipt was NOT issued/acknowledged
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status)
    VALUES (103, 'ORD-103', '2A-3333', 'paid', NULL, 'waiting')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (103, 'wash', 1, 'waiting')
")->execute();

$receiptBypassed = false;
$receiptError = null;
try {
    // Attempt to bypass missing electronic receipt with Manager PIN
    OrderGate::assertServiceAdmissible($pdo, 103, '1234', 'Customer waived electronic receipt', 'boss_sophea');
    $receiptBypassed = true;
} catch (ReceiptRequiredException $re) {
    $receiptError = 'RECEIPT_REQUIRED';
} catch (Throwable $e) {
    $receiptError = $e->getMessage();
}
runCheck(!$receiptBypassed, "Manager PIN cannot bypass electronic receipt issuance");
runCheck($receiptError === 'RECEIPT_REQUIRED', "Order missing electronic receipt still strictly throws RECEIPT_REQUIRED");

// -------------------------------------------------------------
// TEST 4: Physical printer failure does not block admission after electronic receipt issuance
// -------------------------------------------------------------
echo "\n--- TEST 4: PHYSICAL PRINTER FAILURE DOES NOT BLOCK ADMISSION AFTER ELECTRONIC ISSUANCE ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (104, 'ORD-104', '2A-4444', 'paid', '2026-11-09 10:00:00', NULL, 'waiting')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (104, 'wash', 1, 'waiting')
")->execute();

$printerFailureAdmitted = false;
try {
    $printerFailureAdmitted = OrderGate::assertServiceAdmissible($pdo, 104);
} catch (Throwable $e) {
    $printerFailureAdmitted = false;
}
runCheck($printerFailureAdmitted === true, "Admission succeeds without physical print when electronic receipt is issued");

// -------------------------------------------------------------
// TEST 5: Successful manager override creates an audit record
// -------------------------------------------------------------
echo "\n--- TEST 5: SUCCESSFUL MANAGER OVERRIDE CREATES AN AUDIT RECORD ---\n";
$logUid = AuditLogger::logStrict(
    $pdo,
    'PRICE_OVERRIDE',
    'Manager Sophea authorized 10% promotional discount for VIP customer',
    'order',
    'ORD-104',
    10,
    'boss_sophea',
    'manager'
);
runCheck(!empty($logUid), "AuditLogger::logStrict returned a valid unique log UID");

$auditStmt = $pdo->prepare("SELECT * FROM audit_logs WHERE log_uid = ?");
$auditStmt->execute([$logUid]);
$auditRec = $auditStmt->fetch();

runCheck(!empty($auditRec), "Audit record was successfully persisted in audit_logs");
runCheck(($auditRec['action'] ?? '') === 'PRICE_OVERRIDE', "Audit record action matches PRICE_OVERRIDE");
runCheck(($auditRec['username'] ?? '') === 'boss_sophea', "Audit record records the manager identity");

// -------------------------------------------------------------
// TEST 6: Audit logging failure prevents the override from silently succeeding
// -------------------------------------------------------------
echo "\n--- TEST 6: AUDIT LOGGING FAILURE PREVENTS OVERRIDE FROM SILENTLY SUCCEEDING ---\n";
// Simulate an audit write failure (e.g. temporary SQLite read-only or invalid table constraint)
$failCaught = false;
try {
    // Deliberately query non-existent or faulty table using strict mode
    $faultyPdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // Intentionally do NOT create audit_logs table on faulty connection
    AuditLogger::logStrict(
        $faultyPdo,
        'CRITICAL_OVERRIDE',
        'Should fail because audit_logs table does not exist',
        'order',
        'ORD-999'
    );
} catch (RuntimeException $re) {
    $failCaught = true;
}
runCheck($failCaught, "Audit logging failure threw RuntimeException and refused silent success");

// -------------------------------------------------------------
// TEST 7: Recommended bay assignment works
// -------------------------------------------------------------
echo "\n--- TEST 7: RECOMMENDED BAY ASSIGNMENT WORKS ---\n";
// Order 107 is paid upfront with electronic receipt
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status)
    VALUES (107, 'ORD-107', '2A-7777', 'paid', '2026-11-09 10:15:00', 'waiting')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (107, 'wash', 1, 'waiting')
")->execute();

// Best bay recommended for wash is Bay 1
$order107 = $pdo->query("SELECT * FROM orders WHERE id = 107")->fetch();
$scoredBays = SmartQueueEngine::scoreBaysForCar($pdo, $order107);
$topBayId = (int)$scoredBays[0]['bay_id'];

// Assign to the recommended top bay (Bay 1) WITHOUT override reason
$assignRes = BayManager::assignCarToBay(
    $pdo,
    $topBayId,
    107,
    1,
    'Cashier Dara',
    'cashier'
);

runCheck($assignRes['success'] === true, "Recommended bay assignment succeeds without override reason");
$bay1 = $pdo->query("SELECT * FROM bays WHERE id = {$topBayId}")->fetch();
runCheck($bay1['status'] === 'busy' && (int)$bay1['current_order_id'] === 107, "Recommended bay is now occupied by Order 107");

// -------------------------------------------------------------
// TEST 8: Alternative bay assignment requires an override reason
// -------------------------------------------------------------
echo "\n--- TEST 8: ALTERNATIVE BAY ASSIGNMENT REQUIRES AN OVERRIDE REASON ---\n";
// Order 108 is ready for wash; Bay 2 is available, but Bay 3 is chosen while Bay 2 is recommended
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status)
    VALUES (108, 'ORD-108', '2A-8888', 'paid', '2026-11-09 10:20:00', 'waiting')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (108, 'wash', 1, 'waiting')
")->execute();

// Assigning to Bay 3 (alternative) without reason
$altRes = BayManager::assignCarToBay(
    $pdo,
    3,
    108,
    1,
    'Cashier Dara',
    'cashier'
);
runCheck($altRes['success'] === false, "Alternative bay assignment without reason is rejected");
runCheck(($altRes['error'] ?? '') === 'OVERRIDE_REASON_REQUIRED', "Error code is strictly OVERRIDE_REASON_REQUIRED");

// -------------------------------------------------------------
// TEST 9: Invalid or empty override reason is rejected
// -------------------------------------------------------------
echo "\n--- TEST 9: INVALID OR EMPTY OVERRIDE REASON IS REJECTED ---\n";
// Attempt with whitespace reason
$spaceRes = BayManager::assignCarToBay(
    $pdo,
    3,
    108,
    1,
    'Cashier Dara',
    'cashier',
    '   '
);
runCheck($spaceRes['success'] === false && ($spaceRes['error'] ?? '') === 'OVERRIDE_REASON_REQUIRED', "Whitespace-only override reason is rejected");

// Attempt with too-short reason (< 3 chars)
$shortRes = BayManager::assignCarToBay(
    $pdo,
    3,
    108,
    1,
    'Cashier Dara',
    'cashier',
    'ok'
);
runCheck($shortRes['success'] === false && ($shortRes['error'] ?? '') === 'OVERRIDE_REASON_REQUIRED', "Too-short override reason (<3 chars) is rejected");

// Attempt with valid meaningful reason (>= 3 chars)
$validOverrideRes = BayManager::assignCarToBay(
    $pdo,
    3,
    108,
    1,
    'Cashier Dara',
    'cashier',
    'Customer specifically requested Bay 3 near parking exit'
);
runCheck($validOverrideRes['success'] === true, "Alternative bay assignment with meaningful override reason succeeds");

// Confirm audit record was created for the bay override
$bayOverrideLog = $pdo->query("SELECT * FROM audit_logs WHERE action = 'BAY_OVERRIDE' AND entity_id = '108'")->fetch();
runCheck(!empty($bayOverrideLog), "BAY_OVERRIDE audit log was written for alternative bay selection");

// -------------------------------------------------------------
// TEST 10: Mechanical service displays the correct status
// -------------------------------------------------------------
echo "\n--- TEST 10: MECHANICAL SERVICE DISPLAYS THE CORRECT STATUS ---\n";
// Assign mechanical car to Bay 4
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status, repair_status)
    VALUES (110, 'ORD-110', '2A-9999', 'paid', '2026-11-09 10:30:00', 'waiting', 'working')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status, bay_id)
    VALUES (110, 'oil_change', 1, 'in_progress', 4)
")->execute();
$pdo->prepare("
    UPDATE bays SET status = 'busy', current_order_id = 110 WHERE id = 4
")->execute();

$tvData = BayManager::getTvDisplayData($pdo);
$bay4Display = null;
foreach ($tvData['bays'] as $b) {
    if ((int)$b['bay_number'] === 4) {
        $bay4Display = $b;
        break;
    }
}

runCheck($bay4Display !== null, "Bay 4 found in TV display data");
runCheck(($bay4Display['badge_key'] ?? '') === 'mechanical_service', "Bay 4 badge_key is strictly 'mechanical_service'");
runCheck(($bay4Display['badge_en'] ?? '') === 'Under Mechanical Service', "Bay 4 badge_en is strictly 'Under Mechanical Service'");

// -------------------------------------------------------------
// TEST 11: Mixed order transitions display the correct status
// -------------------------------------------------------------
echo "\n--- TEST 11: MIXED ORDER TRANSITIONS DISPLAY THE CORRECT STATUS ---\n";
// Mixed order waiting for Step 1 Mechanical
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status, is_mixed, queue_priority, arrived_at)
    VALUES (111, 'ORD-111', '2A-5555', 'paid', '2026-11-09 10:40:00', 'waiting', 1, 0, '2026-11-09 10:40:00')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (111, 'oil_change', 1, 'waiting'), (111, 'wash', 2, 'pending_prereq')
")->execute();

// Mixed order finished Step 1, waiting for Step 2 Wash
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, status, repair_status, is_mixed, queue_priority, arrived_at)
    VALUES (112, 'ORD-112', '2A-6666', 'paid', '2026-11-09 10:42:00', 'waiting', 'finished', 1, 0, '2026-11-09 10:42:00')
")->execute();
$pdo->prepare("
    INSERT INTO order_tasks (order_id, task_type, sequence, status)
    VALUES (112, 'oil_change', 1, 'completed'), (112, 'wash', 2, 'waiting')
")->execute();

$tvDataMixed = BayManager::getTvDisplayData($pdo);
$waitingList = $tvDataMixed['waiting_list'];

$item111 = null;
$item112 = null;
foreach ($waitingList as $item) {
    if (strpos($item['plate_number'], '5555') !== false) {
        $item111 = $item;
    }
    if (strpos($item['plate_number'], '6666') !== false) {
        $item112 = $item;
    }
}

runCheck($item111 !== null, "Mixed Order 111 (Step 1) found in TV waiting list");
runCheck(($item111['status_key'] ?? '') === 'waiting_mechanical', "Step 1 mixed order status_key is 'waiting_mechanical'");
runCheck(($item111['status_en'] ?? '') === 'Awaiting Mechanical (Step 1)', "Step 1 mixed order status_en is 'Awaiting Mechanical (Step 1)'");

runCheck($item112 !== null, "Mixed Order 112 (Step 2) found in TV waiting list");
runCheck(($item112['status_key'] ?? '') === 'waiting_wash', "Step 2 mixed order status_key is 'waiting_wash'");
runCheck(($item112['status_en'] ?? '') === 'Awaiting Wash (Mechanical Done)', "Step 2 mixed order status_en is 'Awaiting Wash (Mechanical Done)'");

echo "\n=================================================================\n";
echo "PHASE 2.1 REGRESSION SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
