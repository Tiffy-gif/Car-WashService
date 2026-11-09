<?php
declare(strict_types=1);

/**
 * Phase 2.2 Electronic Receipt Enforcement Regression Test Suite
 *
 * Isolated temporary SQLite database.
 * Zero writes to MariaDB 'carwash_db'.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_phase2_2_receipt_' . uniqid() . '.sqlite';
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
        category TEXT DEFAULT 'wash',
        price_usd REAL DEFAULT 5.00
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
        title TEXT DEFAULT 'Service Task',
        lane TEXT DEFAULT 'Lane 1',
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

    CREATE TABLE settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    );

    CREATE TABLE appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER DEFAULT NULL,
        plate_number TEXT DEFAULT NULL,
        appointment_date DATE DEFAULT CURRENT_DATE,
        appointment_time DATETIME DEFAULT NULL,
        status TEXT DEFAULT 'pending'
    );

    CREATE TABLE parts_requests (
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

    INSERT INTO settings (setting_key, setting_value) VALUES
    ('require_receipt_before_wash', '1'),
    ('estimate_buffer_percent', '10'),
    ('tv_display_language', 'both');

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, sort_order) VALUES
    (1, 1, 'Bay 1', 'wash', 'wash_only', 'free', 1),
    (2, 2, 'Bay 2', 'wash', 'wash_only', 'free', 2),
    (3, 3, 'Bay 3', 'wash', 'wash_only', 'free', 3),
    (4, 4, 'Bay 4 (Lift)', 'oil_change', 'oil_only', 'free', 4);

    INSERT INTO users (id, username, full_name, role, effective_role) VALUES
    (1, 'cashier1', 'Cashier Dara', 'cashier', 'cashier'),
    (2, 'tech1', 'Tech Bunthoeun', 'mechanic', 'mechanic'),
    (3, 'boss1', 'Manager Boss', 'manager', 'manager');
");

$mgrPinHash = password_hash('9999', PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET local_pin_hash = ?, password_hash = ? WHERE id = 3")->execute([$mgrPinHash, $mgrPinHash]);

require_once __DIR__ . '/../core/OrderGate.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/TechnicalManager.php';

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
echo "PHASE 2.2 ELECTRONIC RECEIPT ENFORCEMENT REGRESSION TEST\n";
echo "Isolated Temp DB: {$tmpDb}\n";
echo "=================================================================\n\n";

// -------------------------------------------------------------
// 1. Electronic receipt issued + physical receipt printed = admission allowed
// -------------------------------------------------------------
echo "--- TEST 1: ELECTRONIC ISSUED + PHYSICAL PRINTED => ALLOWED ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (201, 'ORD-201', '2A-0201', 'paid', '2026-11-09 10:00:00', '2026-11-09 10:00:05', 'waiting')
")->execute();
$res1 = false;
try {
    $res1 = OrderGate::assertServiceAdmissible($pdo, 201);
} catch (Throwable $e) {
    $res1 = false;
}
runCheck($res1 === true, "Electronic issued + physical printed is admitted");

// -------------------------------------------------------------
// 2. Electronic receipt issued + physical print failed = admission allowed
// -------------------------------------------------------------
echo "\n--- TEST 2: ELECTRONIC ISSUED + PHYSICAL PRINT FAILED => ALLOWED ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (202, 'ORD-202', '2A-0202', 'paid', '2026-11-09 10:05:00', NULL, 'waiting')
")->execute();
$res2 = false;
try {
    $res2 = OrderGate::assertServiceAdmissible($pdo, 202);
} catch (Throwable $e) {
    $res2 = false;
}
runCheck($res2 === true, "Electronic issued + physical print failed is admitted (printer failure advisory only)");

// -------------------------------------------------------------
// 3. Electronic receipt missing + physical receipt printed = admission rejected
// -------------------------------------------------------------
echo "\n--- TEST 3: ELECTRONIC MISSING + PHYSICAL PRINTED => REJECTED ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (203, 'ORD-203', '2A-0203', 'paid', NULL, '2026-11-09 10:10:00', 'waiting')
")->execute();
$res3 = false;
$err3 = null;
try {
    $res3 = OrderGate::assertServiceAdmissible($pdo, 203);
} catch (ReceiptRequiredException $re) {
    $err3 = 'RECEIPT_REQUIRED';
} catch (Throwable $e) {
    $err3 = $e->getMessage();
}
runCheck($res3 === false, "Electronic receipt missing + physical printed is strictly rejected");
runCheck($err3 === 'RECEIPT_REQUIRED', "Rejection code is strictly RECEIPT_REQUIRED (receipt_printed_at does NOT substitute)");

// -------------------------------------------------------------
// 4. Both receipt timestamps missing = admission rejected
// -------------------------------------------------------------
echo "\n--- TEST 4: BOTH RECEIPT TIMESTAMPS MISSING => REJECTED ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (204, 'ORD-204', '2A-0204', 'paid', NULL, NULL, 'waiting')
")->execute();
$res4 = false;
$err4 = null;
try {
    $res4 = OrderGate::assertServiceAdmissible($pdo, 204);
} catch (ReceiptRequiredException $re) {
    $err4 = 'RECEIPT_REQUIRED';
} catch (Throwable $e) {
    $err4 = $e->getMessage();
}
runCheck($res4 === false, "Both receipt timestamps missing is strictly rejected");
runCheck($err4 === 'RECEIPT_REQUIRED', "Error code is strictly RECEIPT_REQUIRED");

// -------------------------------------------------------------
// 5. Unpaid order with electronic receipt issued = admission rejected
// -------------------------------------------------------------
echo "\n--- TEST 5: UNPAID ORDER WITH ELECTRONIC RECEIPT ISSUED => REJECTED ---\n";
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (205, 'ORD-205', '2A-0205', 'unpaid', '2026-11-09 10:15:00', NULL, 'waiting')
")->execute();
$res5 = false;
$err5 = null;
try {
    $res5 = OrderGate::assertServiceAdmissible($pdo, 205);
} catch (PaymentRequiredException $pe) {
    $err5 = 'PAYMENT_REQUIRED';
} catch (Throwable $e) {
    $err5 = $e->getMessage();
}
runCheck($res5 === false, "Unpaid order with electronic receipt is strictly rejected");
runCheck($err5 === 'PAYMENT_REQUIRED', "Rejection code is strictly PAYMENT_REQUIRED (Payment is strictly mandatory)");

// -------------------------------------------------------------
// 6. Manager PIN cannot bypass payment or electronic receipt requirements
// -------------------------------------------------------------
echo "\n--- TEST 6: MANAGER PIN CANNOT BYPASS PAYMENT OR ELECTRONIC RECEIPT ---\n";
// Attempt to bypass unpaid order with manager PIN
$pinBypassPayment = false;
$pinPayErr = null;
try {
    $pinBypassPayment = OrderGate::assertServiceAdmissible($pdo, 205, '9999', 'Supervisor rush wash', 'boss1');
} catch (PaymentRequiredException $pe) {
    $pinPayErr = 'PAYMENT_REQUIRED';
} catch (Throwable $e) {
    $pinPayErr = $e->getMessage();
}
runCheck($pinBypassPayment === false, "Manager PIN cannot bypass payment requirement");
runCheck($pinPayErr === 'PAYMENT_REQUIRED', "Unpaid order with Manager PIN strictly throws PAYMENT_REQUIRED");

// Attempt to bypass missing electronic receipt with manager PIN
$pinBypassReceipt = false;
$pinRcptErr = null;
try {
    $pinBypassReceipt = OrderGate::assertServiceAdmissible($pdo, 203, '9999', 'Supervisor paper receipt only', 'boss1');
} catch (ReceiptRequiredException $re) {
    $pinRcptErr = 'RECEIPT_REQUIRED';
} catch (Throwable $e) {
    $pinRcptErr = $e->getMessage();
}
runCheck($pinBypassReceipt === false, "Manager PIN cannot bypass electronic receipt requirement");
runCheck($pinRcptErr === 'RECEIPT_REQUIRED', "Missing electronic receipt with Manager PIN strictly throws RECEIPT_REQUIRED");

// -------------------------------------------------------------
// 7. Verify all service admission entry points use identical rule
// -------------------------------------------------------------
echo "\n--- TEST 7: ALL SERVICE ADMISSION ENTRY POINTS USE IDENTICAL RULE ---\n";
// A. BayManager::assignCarToBay (Bay admission)
$pdo->prepare("
    INSERT INTO order_tasks (id, order_id, task_type, sequence, status)
    VALUES (2031, 203, 'wash', 1, 'waiting')
")->execute();
$bayAssignRes = BayManager::assignCarToBay($pdo, 1, 203, 1, 'Cashier Dara', 'cashier');
runCheck($bayAssignRes['success'] === false, "BayManager::assignCarToBay rejects order missing electronic receipt");
runCheck(($bayAssignRes['error'] ?? '') === 'RECEIPT_REQUIRED', "BayManager error is strictly RECEIPT_REQUIRED");

// B. TechnicalManager::startTask (Mechanical task admission)
$pdo->prepare("
    INSERT INTO order_tasks (id, order_id, task_type, sequence, status)
    VALUES (2032, 203, 'oil_change', 1, 'waiting')
")->execute();
$techStartCaught = false;
$techErr = null;
try {
    TechnicalManager::startTask($pdo, 2032, 2, 'Tech Bunthoeun');
} catch (ReceiptRequiredException $re) {
    $techStartCaught = true;
    $techErr = 'RECEIPT_REQUIRED';
} catch (Throwable $e) {
    $techStartCaught = true;
    $techErr = $e->getMessage();
}
runCheck($techStartCaught && $techErr === 'RECEIPT_REQUIRED', "TechnicalManager::startTask rejects order missing electronic receipt with RECEIPT_REQUIRED");

// C. OrderGate::assertReceiptPrinted alias
$aliasRes = false;
$aliasErr = null;
try {
    $aliasRes = OrderGate::assertReceiptPrinted($pdo, 203);
} catch (ReceiptRequiredException $re) {
    $aliasErr = 'RECEIPT_REQUIRED';
}
runCheck($aliasRes === false && $aliasErr === 'RECEIPT_REQUIRED', "OrderGate::assertReceiptPrinted alias applies identical strict electronic receipt rule");

// -------------------------------------------------------------
// 8. Test configuration-independence (Both setting enabled and disabled)
// -------------------------------------------------------------
echo "\n--- TEST 8: CONFIGURATION INDEPENDENCE (ENABLED VS DISABLED) ---\n";

// Case 8.1: Setting ENABLED ('1') + Missing electronic receipt => REJECTED
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();

$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (2081, 'ORD-2081', '2A-081', 'paid', NULL, NULL, 'waiting')
")->execute();
$c81Passed = false;
try {
    OrderGate::assertServiceAdmissible($pdo, 2081);
} catch (ReceiptRequiredException $re) {
    $c81Passed = true;
}
runCheck($c81Passed, "Setting enabled + missing electronic receipt => rejected");

// Case 8.2: Setting DISABLED ('0') + Missing electronic receipt => REJECTED (Unconditional!)
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();

$c82Passed = false;
try {
    OrderGate::assertServiceAdmissible($pdo, 2081);
} catch (ReceiptRequiredException $re) {
    $c82Passed = true;
}
runCheck($c82Passed, "Setting disabled + missing electronic receipt => rejected (Unconditional enforcement)");

// Case 8.3: Setting DISABLED ('0') + Electronic receipt issued + Paid => ALLOWED
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (2083, 'ORD-2083', '2A-083', 'paid', '2026-11-09 11:00:00', NULL, 'waiting')
")->execute();
$c83Allowed = false;
try {
    $c83Allowed = OrderGate::assertServiceAdmissible($pdo, 2083);
} catch (Throwable $e) {
    $c83Allowed = false;
}
runCheck($c83Allowed === true, "Setting disabled + electronic receipt issued + paid => allowed");

// Case 8.4: Setting ENABLED ('1') + Electronic receipt issued + Paid => ALLOWED
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();

$c84Allowed = false;
try {
    $c84Allowed = OrderGate::assertServiceAdmissible($pdo, 2083);
} catch (Throwable $e) {
    $c84Allowed = false;
}
runCheck($c84Allowed === true, "Setting enabled + electronic receipt issued + paid => allowed");

// Case 8.5: Physical print missing + Electronic receipt issued + Paid => ALLOWED
$c85Allowed = false;
try {
    $c85Allowed = OrderGate::assertServiceAdmissible($pdo, 2083); // 2083 has receipt_printed_at = NULL
} catch (Throwable $e) {
    $c85Allowed = false;
}
runCheck($c85Allowed === true, "Physical print missing + electronic receipt issued + paid => allowed");

// Case 8.6: Physical print present + Electronic receipt missing + Paid => REJECTED
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (2086, 'ORD-2086', '2A-086', 'paid', NULL, '2026-11-09 11:05:00', 'waiting')
")->execute();
$c86Rejected = false;
try {
    OrderGate::assertServiceAdmissible($pdo, 2086);
} catch (ReceiptRequiredException $re) {
    $c86Rejected = true;
}
runCheck($c86Rejected, "Physical print present + electronic receipt missing + paid => rejected");

// Case 8.7: Unpaid order => REJECTED regardless of configuration or manager PIN
$pdo->prepare("
    INSERT INTO orders (id, order_number, plate_number, payment_status, receipt_issued_at, receipt_printed_at, status)
    VALUES (2087, 'ORD-2087', '2A-087', 'unpaid', '2026-11-09 11:10:00', '2026-11-09 11:10:05', 'waiting')
")->execute();

// Test with setting enabled ('1') without PIN
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();
$c87A = false;
try { OrderGate::assertServiceAdmissible($pdo, 2087); } catch (PaymentRequiredException $pe) { $c87A = true; }

// Test with setting disabled ('0') without PIN
$pdo->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();
$c87B = false;
try { OrderGate::assertServiceAdmissible($pdo, 2087); } catch (PaymentRequiredException $pe) { $c87B = true; }

// Test with setting disabled ('0') WITH Manager PIN
$c87C = false;
try { OrderGate::assertServiceAdmissible($pdo, 2087, '9999', 'Override attempt', 'boss1'); } catch (PaymentRequiredException $pe) { $c87C = true; }

runCheck($c87A && $c87B && $c87C, "Unpaid order rejected regardless of configuration or manager PIN");

// Restore setting
$pdo->exec("UPDATE settings SET setting_value = '1' WHERE setting_key = 'require_receipt_before_wash'");
refresh_settings_cache();

echo "\n=================================================================\n";
echo "PHASE 2.2 REGRESSION SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
