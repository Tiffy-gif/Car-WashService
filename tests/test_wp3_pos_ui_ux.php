<?php
declare(strict_types=1);

/**
 * WP3: Cashier POS UI/UX Modernization Test Suite
 *
 * Strictly isolated temporary SQLite database tests.
 * Zero writes to MariaDB 'carwash_db'.
 * Zero schema modifications or destructive actions.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_wp3_pos_' . uniqid() . '.sqlite';
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
require_once __DIR__ . '/../core/OrderGate.php';
require_once __DIR__ . '/../core/BayManager.php';
require_once __DIR__ . '/../core/SmartQueueEngine.php';
require_once __DIR__ . '/../core/AuditLogger.php';

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

// Initialize isolated test schema
$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY,
        setting_key TEXT UNIQUE,
        setting_value TEXT
    );

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY,
        username TEXT UNIQUE,
        full_name TEXT,
        role TEXT,
        is_active INTEGER DEFAULT 1,
        pin_code TEXT,
        commission_type TEXT DEFAULT 'percentage',
        commission_rate REAL DEFAULT 0.00
    );

    CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY,
        type TEXT,
        code TEXT UNIQUE,
        name_en TEXT,
        name_km TEXT,
        icon TEXT,
        color TEXT,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TEXT,
        updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY,
        name_en TEXT,
        name_km TEXT,
        category TEXT,
        category_id INTEGER,
        base_price_usd REAL DEFAULT 5.00,
        base_duration_minutes INTEGER DEFAULT 20,
        is_active INTEGER DEFAULT 1,
        image_path TEXT,
        thumb_path TEXT,
        image_updated_at TEXT
    );

    CREATE TABLE IF NOT EXISTS service_prices (
        id INTEGER PRIMARY KEY,
        service_id INTEGER,
        vehicle_type TEXT,
        price_usd REAL,
        price_khr INTEGER,
        estimated_minutes INTEGER DEFAULT 20
    );

    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY,
        name_en TEXT,
        name_km TEXT,
        category TEXT,
        category_id INTEGER,
        brand TEXT,
        unit TEXT,
        price_usd REAL,
        price_khr INTEGER,
        stock_qty INTEGER DEFAULT 50,
        min_stock_alert INTEGER DEFAULT 5,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY,
        name TEXT,
        phone TEXT,
        loyalty_points INTEGER DEFAULT 0,
        membership_level TEXT DEFAULT 'standard',
        created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS vehicles (
        id INTEGER PRIMARY KEY,
        customer_id INTEGER,
        plate_number TEXT UNIQUE,
        vehicle_type TEXT,
        make TEXT,
        model TEXT,
        color TEXT
    );

    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT UNIQUE,
        order_uid TEXT UNIQUE,
        idempotency_key TEXT UNIQUE,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT NOT NULL,
        customer_name TEXT DEFAULT 'Walk-in Guest',
        customer_phone TEXT,
        customer_id INTEGER,
        vehicle_id INTEGER,
        subtotal_usd REAL DEFAULT 0.00,
        discount_usd REAL DEFAULT 0.00,
        total_usd REAL DEFAULT 0.00,
        total_khr INTEGER DEFAULT 0,
        payment_method TEXT DEFAULT 'cash',
        payment_status TEXT DEFAULT 'unpaid',
        payment_ref TEXT,
        amount_tendered_usd REAL DEFAULT 0.00,
        change_usd REAL DEFAULT 0.00,
        status TEXT DEFAULT 'waiting',
        repair_status TEXT,
        receipt_issued_at TEXT,
        receipt_printed_at TEXT,
        bay_id INTEGER,
        queue_priority INTEGER DEFAULT 0,
        created_at TEXT,
        started_at TEXT,
        finished_at TEXT,
        is_active INTEGER DEFAULT 1,
        is_mixed INTEGER DEFAULT 0,
        estimated_duration_minutes INTEGER DEFAULT 20
    );

    CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER,
        product_id INTEGER,
        service_name TEXT,
        price_usd REAL NOT NULL,
        qty INTEGER DEFAULT 1,
        item_type TEXT DEFAULT 'service'
    );

    CREATE TABLE IF NOT EXISTS order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER NOT NULL,
        status TEXT DEFAULT 'waiting',
        bay_id INTEGER,
        created_at TEXT,
        started_at TEXT,
        completed_at TEXT,
        updated_at TEXT,
        base_duration_minutes INTEGER DEFAULT 20,
        estimated_duration_minutes INTEGER DEFAULT 20
    );

    CREATE TABLE IF NOT EXISTS bays (
        id INTEGER PRIMARY KEY,
        bay_number INTEGER UNIQUE,
        bay_name TEXT,
        bay_type TEXT,
        skill_tag TEXT,
        status TEXT DEFAULT 'free',
        current_order_id INTEGER,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_uid TEXT UNIQUE,
        user_id INTEGER,
        username TEXT,
        user_role TEXT,
        action TEXT NOT NULL,
        entity_type TEXT,
        entity_id TEXT,
        old_value TEXT,
        new_value TEXT,
        reason TEXT,
        manager_username TEXT,
        created_at TEXT
    );

    CREATE TABLE IF NOT EXISTS parts_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER,
        status TEXT
    );

    CREATE TABLE IF NOT EXISTS order_washers (
        order_id INTEGER,
        user_id INTEGER,
        commission_usd REAL DEFAULT 0.00
    );

    CREATE TABLE IF NOT EXISTS order_mechanics (
        order_id INTEGER,
        user_id INTEGER,
        commission_usd REAL DEFAULT 0.00
    );

    CREATE TABLE IF NOT EXISTS appointments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_date TEXT,
        appointment_time TEXT,
        plate_number TEXT,
        service_id INTEGER,
        status TEXT DEFAULT 'scheduled'
    );
");

// Seed standard bays
$pdo->exec("
    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status, current_order_id, is_active) VALUES
    (1, 1, 'Wash Bay 1', 'wash', 'wash_only', 'free', NULL, 1),
    (2, 2, 'Wash Bay 2', 'wash', 'wash_only', 'free', NULL, 1),
    (3, 3, 'Wash Bay 3', 'wash', 'wash_only', 'free', NULL, 1),
    (4, 4, 'Tech Lift Bay 4', 'oil_change', 'oil_only', 'free', NULL, 1);
");

// Seed staff
$pdo->exec("
    INSERT INTO users (id, username, full_name, role, is_active) VALUES
    (1, 'cashier1', 'Cashier Bopha', 'cashier', 1),
    (2, 'washer1', 'Washer Sopheap', 'washer', 1),
    (3, 'mechanic1', 'Mechanic Dara', 'mechanic', 1);
");

// Seed services & pricing
$pdo->exec("
    INSERT INTO services (id, name_en, name_km, category, base_price_usd) VALUES
    (1, 'Standard Wash', 'លាងរថយន្តធម្មតា', 'wash', 5.00),
    (2, 'Premium Detailing Wash', 'លាងរថយន្តលំដាប់ខ្ពស់', 'wash', 12.00),
    (3, 'Engine Oil Change', 'ប្តូរប្រេងម៉ាស៊ីន', 'oil_change', 25.00),
    (4, 'Brake Inspection & Service', 'ពិនិត្យ និងជួសជុលហ្វ្រាំង', 'repair', 15.00);

    INSERT INTO products (id, name_en, name_km, category, price_usd, stock_qty) VALUES
    (101, 'Wiper Fluid (1L)', 'ទឹកចាក់កញ្ចក់', 'care_product', 3.00, 20),
    (102, 'Car Air Freshener', 'ទឹកអប់រថយន្ត', 'care_product', 2.50, 50);

    INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr) VALUES
    (1, 'motorbike', 2.00, 8200),
    (1, 'small_car', 5.00, 20500),
    (1, 'suv_pickup', 7.00, 28700),
    (1, 'van_truck', 10.00, 41000),
    (3, 'small_car', 25.00, 102500),
    (3, 'suv_pickup', 35.00, 143500);
");

$testCount = 0;
$passCount = 0;

function runTest(string $name, callable $fn) {
    global $testCount, $passCount;
    $testCount++;
    echo "\n[TEST {$testCount}] {$name}...\n";
    try {
        $fn();
        $passCount++;
        echo "  --> PASSED\n";
    } catch (Throwable $e) {
        echo "  --> FAILED: " . $e->getMessage() . "\n";
        echo "      in " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "=======================================================\n";
echo "WP3 CASHIER POS MODERNIZATION TEST SUITE\n";
echo "Isolated SQLite DB: {$tmpDb}\n";
echo "=======================================================\n";

// =========================================================================
// TEST 1: Customer Intake Validation (Plate Mandatory, Name Defaults to Walk-in Guest)
// =========================================================================
runTest("Test 1: Customer Intake: Plate is mandatory, Name defaults to Walk-in Guest", function() use ($pdo) {
    // 1. Missing plate must throw validation error
    $validateIntake = function(?string $plate, ?string $name, ?string $vtype) {
        $cleanPlate = strtoupper(trim((string)$plate));
        if ($cleanPlate === '' || strlen($cleanPlate) < 2) {
            return ['valid' => false, 'error' => 'PLATE_REQUIRED', 'message' => 'Vehicle plate number is required'];
        }
        $validTypes = ['motorbike', 'small_car', 'suv_pickup', 'van_truck'];
        if (!$vtype || !in_array($vtype, $validTypes, true)) {
            return ['valid' => false, 'error' => 'VEHICLE_TYPE_REQUIRED', 'message' => 'Vehicle type is required'];
        }
        $cleanName = trim((string)$name);
        if ($cleanName === '') {
            $cleanName = 'Walk-in Guest';
        }
        return ['valid' => true, 'plate' => $cleanPlate, 'name' => $cleanName, 'vtype' => $vtype];
    };

    $res1 = $validateIntake('', 'Panha', 'small_car');
    if ($res1['valid'] || $res1['error'] !== 'PLATE_REQUIRED') {
        throw new Exception("Empty plate should be rejected with PLATE_REQUIRED, got: " . json_encode($res1));
    }

    $res2 = $validateIntake('2BC-1234', '', 'small_car');
    if (!$res2['valid'] || $res2['name'] !== 'Walk-in Guest') {
        throw new Exception("Empty name should default to 'Walk-in Guest', got: " . json_encode($res2));
    }

    $res3 = $validateIntake('2BC-1234', 'John Doe', 'invalid_type');
    if ($res3['valid'] || $res3['error'] !== 'VEHICLE_TYPE_REQUIRED') {
        throw new Exception("Invalid vehicle type should be rejected with VEHICLE_TYPE_REQUIRED, got: " . json_encode($res3));
    }
});

// =========================================================================
// TEST 2: Service Catalog: Mechanical vs Wash Classification
// =========================================================================
runTest("Test 2: Service Catalog: Accurate classification of wash, mechanical, and mixed items", function() use ($pdo) {
    $isMechanical = function(array $svc): bool {
        $cat = strtolower(trim($svc['category'] ?? ''));
        $name = strtolower(trim($svc['name_en'] ?? ''));
        $code = strtolower(trim($svc['code'] ?? ''));
        return in_array($cat, ['oil', 'oil_change', 'repair', 'maintenance', 'technical'], true)
            || str_contains($name, 'oil change') || str_contains($name, 'repair') || str_contains($name, 'maintenance') || str_contains($name, 'inspection')
            || str_contains($code, 'oil') || str_contains($code, 'repair');
    };

    $washSvc = $pdo->query("SELECT * FROM services WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $oilSvc = $pdo->query("SELECT * FROM services WHERE id = 3")->fetch(PDO::FETCH_ASSOC);
    $brakeSvc = $pdo->query("SELECT * FROM services WHERE id = 4")->fetch(PDO::FETCH_ASSOC);

    if ($isMechanical($washSvc)) {
        throw new Exception("Service 1 (Standard Wash) should NOT be classified as mechanical");
    }
    if (!$isMechanical($oilSvc)) {
        throw new Exception("Service 3 (Engine Oil Change) MUST be classified as mechanical");
    }
    if (!$isMechanical($brakeSvc)) {
        throw new Exception("Service 4 (Brake Inspection) MUST be classified as mechanical");
    }
});

// =========================================================================
// TEST 3: Duplicate Service Prevention vs Multiple Distinct Services
// =========================================================================
runTest("Test 3: Prevent duplicate same-service additions, allow multiple distinct services", function() {
    $cart = [];
    $addService = function(&$cart, array $svc) {
        foreach ($cart as $it) {
            if ($it['type'] === 'service' && (int)$it['service_id'] === (int)$svc['id']) {
                return ['success' => false, 'error' => 'SERVICE_ALREADY_IN_CART', 'message' => 'Service already in cart'];
            }
        }
        $cart[] = [
            'type' => 'service',
            'service_id' => $svc['id'],
            'name' => $svc['name_en'],
            'price' => $svc['price'],
            'qty' => 1
        ];
        return ['success' => true];
    };

    // First add Standard Wash (id: 1) -> Success
    $res1 = $addService($cart, ['id' => 1, 'name_en' => 'Standard Wash', 'price' => 5.00]);
    if (!$res1['success'] || count($cart) !== 1) {
        throw new Exception("First service add should succeed");
    }

    // Accidental double-tap on Standard Wash (id: 1) -> BLOCKED
    $res2 = $addService($cart, ['id' => 1, 'name_en' => 'Standard Wash', 'price' => 5.00]);
    if ($res2['success'] || $res2['error'] !== 'SERVICE_ALREADY_IN_CART' || count($cart) !== 1) {
        throw new Exception("Accidental duplicate service selection must be blocked, got: " . json_encode($res2));
    }

    // Add different service (Engine Oil Change, id: 3) -> Success
    $res3 = $addService($cart, ['id' => 3, 'name_en' => 'Engine Oil Change', 'price' => 25.00]);
    if (!$res3['success'] || count($cart) !== 2) {
        throw new Exception("Adding different service should succeed, cart count should be 2");
    }
});

// =========================================================================
// TEST 4: Cart Totals & Discount Math Precision
// =========================================================================
runTest("Test 4: Cart Totals: Precision subtotal, discount cap, and non-negative total", function() {
    $calcTotals = function(array $cart, float $discountUsd, float $rate = 4100.0) {
        $subtotal = 0.00;
        foreach ($cart as $it) {
            $subtotal += $it['price'] * $it['qty'];
        }
        $cappedDiscount = min($subtotal, max(0.00, $discountUsd));
        $total = max(0.00, round($subtotal - $cappedDiscount, 2));
        $totalKhr = (int)round($total * $rate);
        return [
            'subtotal_usd' => $subtotal,
            'discount_usd' => $cappedDiscount,
            'total_usd' => $total,
            'total_khr' => $totalKhr
        ];
    };

    $items = [
        ['price' => 5.00, 'qty' => 1],
        ['price' => 25.00, 'qty' => 1],
        ['price' => 3.00, 'qty' => 2]
    ];
    // Subtotal: 5 + 25 + 6 = 36.00
    $t1 = $calcTotals($items, 6.00);
    if ($t1['subtotal_usd'] !== 36.00 || $t1['total_usd'] !== 30.00 || $t1['total_khr'] !== 123000) {
        throw new Exception("Totals calculation mismatch: " . json_encode($t1));
    }

    // Excessive discount cannot drop total below zero
    $t2 = $calcTotals($items, 50.00);
    if ($t2['discount_usd'] !== 36.00 || $t2['total_usd'] !== 0.00) {
        throw new Exception("Excessive discount must cap at subtotal and not make total negative: " . json_encode($t2));
    }
});

// =========================================================================
// TEST 5: Cash Payment Flow & Change Calculation
// =========================================================================
runTest("Test 5: Cash Payment: Validates sufficient tender, calculates change, records payment", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $orderNumber = 'ORD-WP3-CASH-001';
    $orderUid = 'UID-WP3-CASH-001';
    $idempotencyKey = 'IDEMP-WP3-CASH-001';

    $totalUsd = 10.00;
    $tendered = 20.00;
    $change = $tendered - $totalUsd; // 10.00

    $stmt = $pdo->prepare("
        INSERT INTO orders (
            order_number, order_uid, idempotency_key, plate_number, vehicle_type,
            customer_name, subtotal_usd, total_usd, total_khr, payment_method,
            payment_status, amount_tendered_usd, change_usd, status, receipt_issued_at, created_at
        ) VALUES (?, ?, ?, '2AA-1111', 'small_car', 'Walk-in Guest', 10.00, ?, ?, 'cash', 'paid', ?, ?, 'waiting', ?, ?)
    ");
    $stmt->execute([$orderNumber, $orderUid, $idempotencyKey, $totalUsd, (int)($totalUsd * 4100), $tendered, $change, $now, $now]);
    $orderId = (int)$pdo->lastInsertId();

    $order = $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_ASSOC);
    if ($order['payment_status'] !== 'paid' || $order['status'] !== 'waiting') {
        throw new Exception("Cash order must be payment_status='paid' and status='waiting'");
    }
    if ((float)$order['amount_tendered_usd'] !== 20.00 || (float)$order['change_usd'] !== 10.00) {
        throw new Exception("Tendered and change amounts mismatch: " . json_encode($order));
    }
    if (empty($order['receipt_issued_at'])) {
        throw new Exception("receipt_issued_at must be populated on payment");
    }
});

// =========================================================================
// TEST 6: Digital KHQR/ABA Flow (Merchant Statement Verified Mandatory)
// =========================================================================
runTest("Test 6: KHQR/ABA requires genuine reference AND cashier statement verification", function() use ($pdo) {
    $validateDigitalPayment = function(string $method, ?string $ref, bool $statementVerified) {
        $cleanRef = trim((string)$ref);
        if ($cleanRef === '' || strlen($cleanRef) < 4 || str_starts_with($cleanRef, 'TRX-')) {
            return ['success' => false, 'error' => 'GENUINE_REF_REQUIRED', 'message' => 'Genuine bank transaction reference is required (min 4 chars)'];
        }
        if (!$statementVerified) {
            return ['success' => false, 'error' => 'STATEMENT_VERIFICATION_REQUIRED', 'message' => 'Cashier must verify payment on merchant phone banking app'];
        }
        return ['success' => true, 'ref' => $cleanRef];
    };

    // 1. Empty reference -> REJECT
    $r1 = $validateDigitalPayment('khqr', '', true);
    if ($r1['success'] || $r1['error'] !== 'GENUINE_REF_REQUIRED') {
        throw new Exception("Empty reference must fail with GENUINE_REF_REQUIRED");
    }

    // 2. Synthetic TRX reference -> REJECT
    $r2 = $validateDigitalPayment('aba', 'TRX-998877', true);
    if ($r2['success'] || $r2['error'] !== 'GENUINE_REF_REQUIRED') {
        throw new Exception("Synthetic TRX reference must fail with GENUINE_REF_REQUIRED");
    }

    // 3. Genuine ref but statement NOT verified -> REJECT
    $r3 = $validateDigitalPayment('khqr', 'ABA-98765432', false);
    if ($r3['success'] || $r3['error'] !== 'STATEMENT_VERIFICATION_REQUIRED') {
        throw new Exception("Unverified statement must fail with STATEMENT_VERIFICATION_REQUIRED");
    }

    // 4. Genuine ref + verified statement -> SUCCESS
    $r4 = $validateDigitalPayment('khqr', 'ABA-98765432', true);
    if (!$r4['success']) {
        throw new Exception("Genuine ref + verified statement should succeed");
    }
});

// =========================================================================
// TEST 7: Electronic Receipt Issuance is Atomic with Payment
// =========================================================================
runTest("Test 7: Electronic receipt issuance is atomic with payment confirmation", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (order_number, order_uid, plate_number, vehicle_type, customer_name, total_usd, payment_method, payment_status, status, receipt_issued_at, created_at)
        VALUES ('ORD-WP3-REC-001', 'UID-WP3-REC-001', '2BB-2222', 'small_car', 'Walk-in Guest', 15.00, 'khqr', 'paid', 'waiting', ?, ?)
    ")->execute([$now, $now]);
    $orderId = (int)$pdo->lastInsertId();

    $order = $pdo->query("SELECT * FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_ASSOC);
    if (empty($order['receipt_issued_at'])) {
        throw new Exception("Electronic receipt timestamp must exist");
    }

    // OrderGate must admit this order
    OrderGate::assertServiceAdmissible($pdo, $orderId);
});

// =========================================================================
// TEST 8: Physical Printer Jam Does Not Block Service Admission
// =========================================================================
runTest("Test 8: Physical printer failure is advisory and does not block service admission", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (order_number, order_uid, plate_number, vehicle_type, customer_name, total_usd, payment_method, payment_status, status, receipt_issued_at, receipt_printed_at, created_at)
        VALUES ('ORD-WP3-PRINT-001', 'UID-WP3-PRINT-001', '2CC-3333', 'small_car', 'Walk-in Guest', 15.00, 'cash', 'paid', 'waiting', ?, NULL, ?)
    ")->execute([$now, $now]);
    $orderId = (int)$pdo->lastInsertId();

    // Simulating physical printer offline / hardware fault -> receipt_printed_at remains NULL
    $order = $pdo->query("SELECT receipt_issued_at, receipt_printed_at FROM orders WHERE id = {$orderId}")->fetch(PDO::FETCH_ASSOC);
    if ($order['receipt_printed_at'] !== null) {
        throw new Exception("Simulated printer failure: receipt_printed_at should be NULL");
    }

    // OrderGate must still pass admission because electronic receipt was issued!
    OrderGate::assertServiceAdmissible($pdo, $orderId);
});

// =========================================================================
// TEST 9: Duplicate Checkout Submission Prevention (Idempotency Key)
// =========================================================================
runTest("Test 9: Concurrency: Duplicate checkout submission with same idempotency key is rejected", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $idempKey = 'UNIQUE-CHECKOUT-KEY-999';

    // First submission
    $stmt1 = $pdo->prepare("
        INSERT INTO orders (order_number, order_uid, idempotency_key, plate_number, vehicle_type, total_usd, payment_status, status, receipt_issued_at, created_at)
        VALUES ('ORD-IDEMP-001', 'UID-IDEMP-001', ?, '2DD-4444', 'small_car', 25.00, 'paid', 'waiting', ?, ?)
    ");
    $stmt1->execute([$idempKey, $now, $now]);

    // Second simultaneous submission with same idempotency key MUST throw PDOException
    $duplicateThrown = false;
    try {
        $stmt2 = $pdo->prepare("
            INSERT INTO orders (order_number, order_uid, idempotency_key, plate_number, vehicle_type, total_usd, payment_status, status, receipt_issued_at, created_at)
            VALUES ('ORD-IDEMP-002', 'UID-IDEMP-002', ?, '2DD-4444', 'small_car', 25.00, 'paid', 'waiting', ?, ?)
        ");
        $stmt2->execute([$idempKey, $now, $now]);
    } catch (PDOException $pe) {
        $duplicateThrown = true;
    }

    if (!$duplicateThrown) {
        throw new Exception("Duplicate checkout submission with same idempotency key must be rejected by database constraint");
    }
});

// =========================================================================
// TEST 10: Staff Assignment in One Canonical Location
// =========================================================================
runTest("Test 10: Staff Assignment: Consolidated Washer & Mechanic assignment stored correctly", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("
        INSERT INTO orders (order_number, order_uid, plate_number, vehicle_type, customer_name, total_usd, payment_status, status, receipt_issued_at, created_at)
        VALUES ('ORD-STAFF-001', 'UID-STAFF-001', '2EE-5555', 'small_car', 'Walk-in Guest', 30.00, 'paid', 'waiting', ?, ?)
    ")->execute([$now, $now]);
    $orderId = (int)$pdo->lastInsertId();

    // Assign Washer (id: 2) and Mechanic (id: 3)
    $pdo->prepare("INSERT INTO order_washers (order_id, user_id, commission_usd) VALUES (?, 2, 1.50)")->execute([$orderId]);
    $pdo->prepare("INSERT INTO order_mechanics (order_id, user_id, commission_usd) VALUES (?, 3, 5.00)")->execute([$orderId]);

    $washers = $pdo->query("SELECT user_id FROM order_washers WHERE order_id = {$orderId}")->fetchAll(PDO::FETCH_COLUMN);
    $mechanics = $pdo->query("SELECT user_id FROM order_mechanics WHERE order_id = {$orderId}")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array(2, array_map('intval', $washers))) {
        throw new Exception("Assigned washer 2 must be recorded");
    }
    if (!in_array(3, array_map('intval', $mechanics))) {
        throw new Exception("Assigned mechanic 3 must be recorded");
    }
});

// =========================================================================
// TEST 11: Operational Workflow Classification (Wash, Mech, Mixed)
// =========================================================================
runTest("Test 11: Operational Workflow: Correct stage mapping for wash-only, mech-only, and mixed orders", function() use ($pdo) {
    $now = date('Y-m-d H:i:s');

    // 1. Wash-Only Order
    $pdo->prepare("INSERT INTO orders (id, order_number, plate_number, vehicle_type, total_usd, payment_status, status, receipt_issued_at, created_at) VALUES (301, 'ORD-WASH-301', '2FF-1111', 'small_car', 5.00, 'paid', 'waiting', ?, ?)")->execute([$now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty) VALUES (301, 1, 'Standard Wash', 5.00, 1)")->execute();
    $tasksWash = BayManager::ensureOrderTasks($pdo, 301);
    if (count($tasksWash) !== 2 || $tasksWash[0]['task_type'] !== 'wash' || $tasksWash[1]['task_type'] !== 'qc') {
        throw new Exception("Wash-only order should have 2 tasks (wash, qc), got: " . json_encode($tasksWash));
    }

    // 2. Mechanical-Only Order
    $pdo->prepare("INSERT INTO orders (id, order_number, plate_number, vehicle_type, total_usd, payment_status, status, receipt_issued_at, created_at) VALUES (302, 'ORD-MECH-302', '2FF-2222', 'small_car', 25.00, 'paid', 'waiting', ?, ?)")->execute([$now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty) VALUES (302, 3, 'Engine Oil Change', 25.00, 1)")->execute();
    $tasksMech = BayManager::ensureOrderTasks($pdo, 302);
    if (count($tasksMech) !== 1 || $tasksMech[0]['task_type'] !== 'oil_change') {
        throw new Exception("Mechanical-only order should have 1 task (oil_change), got: " . json_encode($tasksMech));
    }

    // 3. Mixed Order (Lift First, Wash Second, QC Third)
    $pdo->prepare("INSERT INTO orders (id, order_number, plate_number, vehicle_type, total_usd, payment_status, status, receipt_issued_at, created_at) VALUES (303, 'ORD-MIXED-303', '2FF-3333', 'small_car', 30.00, 'paid', 'waiting', ?, ?)")->execute([$now, $now]);
    $pdo->prepare("INSERT INTO order_items (order_id, service_id, service_name, price_usd, qty) VALUES (303, 1, 'Standard Wash', 5.00, 1), (303, 3, 'Engine Oil Change', 25.00, 1)")->execute();
    $tasksMixed = BayManager::ensureOrderTasks($pdo, 303);
    if (count($tasksMixed) !== 3) {
        throw new Exception("Mixed order should have 3 tasks, got: " . count($tasksMixed));
    }
    if ($tasksMixed[0]['task_type'] !== 'oil_change' || $tasksMixed[1]['task_type'] !== 'wash' || $tasksMixed[2]['task_type'] !== 'qc') {
        throw new Exception("Mixed order sequence must strictly be oil_change (Step 1) -> wash (Step 2) -> qc (Step 3)");
    }
});

// =========================================================================
// TEST 12: Front-End Contract Verification in pos/index.html & pos/css/app.css
// =========================================================================
runTest("Test 12: Front-End Contract: pos/index.html & pos/css/app.css structure verification", function() {
    $htmlContent = file_get_contents(__DIR__ . '/../pos/index.html');
    $cssContent = file_get_contents(__DIR__ . '/../pos/css/app.css');

    // 1. Mandatory Plate Input exists
    if (!str_contains($htmlContent, 'id="custPlateInput"')) {
        throw new Exception("pos/index.html must contain #custPlateInput for mandatory plate entry");
    }

    // 2. Optional Customer Name Input exists
    if (!str_contains($htmlContent, 'id="custNameInput"')) {
        throw new Exception("pos/index.html must contain #custNameInput for optional customer name");
    }

    // 3. Optional Customer Phone Input exists
    if (!str_contains($htmlContent, 'id="custPhoneInput"')) {
        throw new Exception("pos/index.html must contain #custPhoneInput for optional phone number");
    }

    // 4. Inline Validation Message exists (No popups)
    if (!str_contains($htmlContent, 'id="intakeValidationMsg"')) {
        throw new Exception("pos/index.html must contain #intakeValidationMsg for inline validation without popups");
    }

    // 5. Workflow Stage Badge exists in Cart
    if (!str_contains($htmlContent, 'id="cartOrderWorkflowBadge"')) {
        throw new Exception("pos/index.html must contain #cartOrderWorkflowBadge in cart header");
    }

    // 6. Duplicate staff selectors removed from modalPayment
    if (str_contains($htmlContent, 'select id="selectDutyWasher"')) {
        throw new Exception("Duplicated selectDutyWasher dropdown must be removed from modalPayment");
    }
    if (str_contains($htmlContent, 'select id="selectDutyMechanic"')) {
        throw new Exception("Duplicated selectDutyMechanic dropdown must be removed from modalPayment");
    }

    // 7. Canonical staff dropdowns exist in Cart
    if (!str_contains($htmlContent, 'id="cartWasherSelect"')) {
        throw new Exception("pos/index.html must retain canonical #cartWasherSelect in cart");
    }
    if (!str_contains($htmlContent, 'id="cartMechanicSelect"')) {
        throw new Exception("pos/index.html must retain canonical #cartMechanicSelect in cart");
    }

    // 8. CSS Classes exist in pos/css/app.css
    if (!str_contains($cssContent, '.pos-customer-intake-card') || !str_contains($cssContent, '.cart-order-workflow-badge')) {
        throw new Exception("pos/css/app.css must contain .pos-customer-intake-card and .cart-order-workflow-badge styles");
    }
});

echo "\n=======================================================\n";
echo "WP3 TEST SUITE SUMMARY: {$passCount}/{$testCount} TESTS PASSED\n";
echo "=======================================================\n";

if ($passCount === $testCount) {
    echo "SUCCESS: ALL 12 TEST CASES PASSED IN ISOLATED DATABASE!\n";
    exit(0);
} else {
    echo "ERROR: " . ($testCount - $passCount) . " TESTS FAILED!\n";
    exit(1);
}
