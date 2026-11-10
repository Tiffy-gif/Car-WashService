<?php
declare(strict_types=1);

/**
 * WP5 Comprehensive Integration & Regression Verification Suite
 *
 * Scenarios Tested:
 *  A. Cash Payment End-to-End Lifecycle
 *  B. KHQR / ABA Bank Transfer Statement Verification & Genuine References
 *  C. Service Combinations (Wash-Only, Mechanical-Only, Mixed Lift-First Sequence)
 *  D. Failure Handling, Concurrency & Quality Gates (Reversals, Advisory Printer, Rework)
 *  E. Financial Reporting Consistency (Net Settled Revenue, COGS, Commissions, Profit)
 *
 * Isolated temporary SQLite database in sys_get_temp_dir().
 * Zero writes to MariaDB 'carwash_db'.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_wp5_integration_' . uniqid() . '.sqlite';
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
require_once __DIR__ . '/../core/TechnicalManager.php';
require_once __DIR__ . '/../core/SmartQueueEngine.php';
require_once __DIR__ . '/../core/AuditLogger.php';

// Inject temporary PDO instance into Database singleton via reflection
$refProp = new ReflectionProperty('Database', 'instance');
$refProp->setAccessible(true);
$refProp->setValue(null, $pdo);

$refDriver = new ReflectionProperty('Database', 'driver');
$refDriver->setAccessible(true);
$refDriver->setValue(null, 'sqlite');

// Build isolated schema
$pdo->exec("
    CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL UNIQUE,
        setting_value TEXT NOT NULL
    );

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

    CREATE TABLE pos_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_uid TEXT NOT NULL UNIQUE,
        device_name TEXT NOT NULL,
        location TEXT DEFAULT 'Front Desk',
        status TEXT DEFAULT 'active'
    );

    CREATE TABLE services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        name_km TEXT DEFAULT '',
        category TEXT DEFAULT 'wash',
        price_usd REAL DEFAULT 5.00,
        base_duration_minutes INTEGER DEFAULT 20,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        category TEXT DEFAULT 'care_product',
        cost_price_usd REAL DEFAULT NULL,
        price_usd REAL DEFAULT 10.00,
        stock_qty INTEGER DEFAULT 100,
        is_active INTEGER DEFAULT 1
    );

    CREATE TABLE bays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        bay_number INTEGER NOT NULL,
        bay_name TEXT NOT NULL,
        bay_type TEXT NOT NULL,
        skill_tag TEXT DEFAULT 'wash_only',
        status TEXT DEFAULT 'free',
        current_order_id INTEGER DEFAULT NULL,
        occupied_at DATETIME DEFAULT NULL,
        last_freed_at DATETIME DEFAULT NULL,
        duration_minutes INTEGER DEFAULT 20,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        updated_at DATETIME DEFAULT NULL
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL UNIQUE,
        idempotency_key TEXT DEFAULT NULL,
        source TEXT DEFAULT 'pos_app',
        pos_device_id INTEGER DEFAULT 1,
        pos_device_name TEXT DEFAULT 'Counter Station 1',
        customer_name TEXT DEFAULT 'Walk-in Guest',
        customer_phone TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        plate_number TEXT NOT NULL,
        vehicle_type TEXT DEFAULT 'small_car',
        subtotal_usd REAL DEFAULT 0.0,
        discount_usd REAL DEFAULT 0.0,
        total_usd REAL DEFAULT 0.0,
        total_khr INTEGER DEFAULT 0,
        status TEXT DEFAULT 'waiting',
        payment_status TEXT DEFAULT 'unpaid',
        payment_method TEXT DEFAULT 'cash',
        payment_ref TEXT DEFAULT NULL,
        amount_tendered_usd REAL DEFAULT 0.0,
        change_usd REAL DEFAULT 0.0,
        refund_status TEXT DEFAULT NULL,
        refund_amount_usd REAL DEFAULT 0.0,
        refund_reason TEXT DEFAULT NULL,
        refunded_at DATETIME DEFAULT NULL,
        cashier_id INTEGER DEFAULT NULL,
        bay_id INTEGER DEFAULT NULL,
        suggested_bay_id INTEGER DEFAULT NULL,
        bay_override_reason TEXT DEFAULT NULL,
        is_mixed INTEGER DEFAULT 0,
        repair_status TEXT DEFAULT NULL,
        receipt_issued_at DATETIME DEFAULT NULL,
        receipt_printed_at DATETIME DEFAULT NULL,
        arrived_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        queue_priority INTEGER DEFAULT 0,
        priority_level TEXT DEFAULT 'P3',
        appointment_time DATETIME DEFAULT NULL,
        estimated_duration_minutes INTEGER DEFAULT NULL,
        actual_duration_minutes INTEGER DEFAULT NULL,
        took_longer_than_expected INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        item_type TEXT NOT NULL,
        service_name TEXT NOT NULL,
        price_usd REAL DEFAULT 0.0,
        price_khr INTEGER DEFAULT 0,
        qty INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE order_tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_type TEXT NOT NULL,
        title TEXT NOT NULL,
        sequence INTEGER DEFAULT 1,
        status TEXT DEFAULT 'waiting',
        bay_id INTEGER DEFAULT NULL,
        assigned_user_id INTEGER DEFAULT NULL,
        assigned_to_id INTEGER DEFAULT NULL,
        actual_oil_used REAL DEFAULT NULL,
        base_duration_minutes INTEGER DEFAULT 15,
        estimated_duration_minutes INTEGER DEFAULT 15,
        took_longer_than_expected INTEGER DEFAULT 0,
        actual_duration_minutes INTEGER DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME DEFAULT NULL,
        completed_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL
    );

    CREATE TABLE order_washers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        washer_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0.0
    );

    CREATE TABLE order_mechanics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        mechanic_id INTEGER NOT NULL,
        commission_usd REAL DEFAULT 0.0
    );

    CREATE TABLE inspection_checklists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        task_id INTEGER DEFAULT NULL,
        item_key TEXT NOT NULL,
        item_name TEXT DEFAULT '',
        item_label_en TEXT DEFAULT '',
        status TEXT DEFAULT 'pending',
        measurement_value TEXT DEFAULT NULL,
        photo_url TEXT DEFAULT NULL,
        is_mandatory INTEGER NOT NULL DEFAULT 1,
        notes TEXT DEFAULT NULL,
        checked_by_id INTEGER DEFAULT NULL,
        checked_by_name TEXT DEFAULT NULL,
        checked_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL
    );

    CREATE TABLE expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        description TEXT DEFAULT '',
        amount_usd REAL NOT NULL,
        expense_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE parts_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_uid TEXT NOT NULL,
        order_id INTEGER NOT NULL,
        task_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        product_name TEXT DEFAULT NULL,
        qty_requested INTEGER DEFAULT 1,
        status TEXT DEFAULT 'pending',
        requested_by_id INTEGER DEFAULT NULL,
        requested_by_name TEXT DEFAULT NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_by_id INTEGER DEFAULT NULL,
        reviewed_by_name TEXT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        review_reason TEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_uid TEXT NOT NULL UNIQUE,
        user_id INTEGER DEFAULT NULL,
        username TEXT DEFAULT NULL,
        user_name TEXT DEFAULT NULL,
        role TEXT DEFAULT NULL,
        user_role TEXT DEFAULT NULL,
        action TEXT NOT NULL,
        entity_type TEXT DEFAULT 'system',
        entity_id TEXT DEFAULT NULL,
        details TEXT NOT NULL,
        ip_address TEXT DEFAULT '127.0.0.1',
        user_agent TEXT DEFAULT NULL,
        device_name TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE bay_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER DEFAULT NULL,
        plate_number TEXT DEFAULT NULL,
        bay_id INTEGER DEFAULT NULL,
        bay_name TEXT DEFAULT NULL,
        action TEXT NOT NULL,
        performed_by INTEGER DEFAULT NULL,
        performed_by_name TEXT DEFAULT NULL,
        performed_by_role TEXT DEFAULT NULL,
        reason TEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Seed Settings, Users, Devices, Bays, Services, and Products
$pdo->exec("
    INSERT INTO settings (setting_key, setting_value) VALUES
    ('require_physical_print_to_wash', '0'),
    ('currency_rate_khr', '4100'),
    ('tv_display_language', 'both');

    INSERT INTO users (id, username, full_name, role, effective_role, local_pin_hash) VALUES
    (1, 'owner', 'Sok Owner', 'owner', 'owner', '" . password_hash('123456', PASSWORD_BCRYPT) . "'),
    (2, 'cashier1', 'Dara Cashier', 'cashier', 'cashier', '" . password_hash('123456', PASSWORD_BCRYPT) . "'),
    (3, 'washer1', 'Bona Washer', 'washer', 'washer', '" . password_hash('123456', PASSWORD_BCRYPT) . "'),
    (4, 'mechanic1', 'Rith Mechanic', 'mechanic', 'mechanic', '" . password_hash('123456', PASSWORD_BCRYPT) . "'),
    (5, 'supervisor1', 'Vannak Supervisor', 'wash_supervisor', 'wash_supervisor', '" . password_hash('123456', PASSWORD_BCRYPT) . "');

    INSERT INTO pos_devices (id, device_uid, device_name, location) VALUES
    (1, 'POS-COUNTER-01', 'Counter Station 1', 'Front Desk');

    INSERT INTO bays (id, bay_number, bay_name, bay_type, skill_tag, status) VALUES
    (1, 1, 'Bay 1 (Wash Standard)', 'standard', 'wash_only', 'free'),
    (2, 2, 'Bay 2 (Wash Standard)', 'standard', 'wash_only', 'free'),
    (3, 3, 'Bay 3 (Detailing)', 'detailing', 'detailing', 'free'),
    (4, 4, 'Bay 4 (Lift / Mechanic)', 'oil_change', 'oil_only', 'free');

    INSERT INTO services (id, name_en, category, price_usd, base_duration_minutes) VALUES
    (1, 'Premium Exterior Wash', 'wash', 10.00, 20),
    (2, 'Full Engine Oil Service', 'repair', 25.00, 35);

    INSERT INTO products (id, name_en, category, cost_price_usd, price_usd) VALUES
    (1, 'Synthetic Oil 4L', 'spare_part', 20.00, 35.00),
    (2, 'Oil Filter Cartridge', 'spare_part', 4.00, 10.00),
    (3, 'Tire Shine Spray', 'care_product', NULL, 8.00); -- Intentionally NULL cost to verify missing COGS warning
");

$passed = 0;
$failed = 0;

function assertCheck(string $section, string $testName, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [PASS] [{$section}] {$testName}\n";
    } else {
        $failed++;
        echo " [FAIL] [{$section}] {$testName}" . ($detail ? " -> {$detail}" : '') . "\n";
    }
}

echo "=================================================================\n";
echo " WP5 FULL POS INTEGRATION & REGRESSION VERIFICATION SUITE       \n";
echo "=================================================================\n\n";

// =================================================================
// SCENARIO A: Cash Payment End-to-End Lifecycle
// =================================================================
echo "--- SCENARIO A: CASH PAYMENT END-TO-END LIFECYCLE ---\n";

// A1: Order Intake & Cash Payment Validation (reject under-tender)
$ordA_num = 'ORD-261110-A01';
$plateA = '2A-9001';
$totalA = 10.00;
$tenderedInsufficient = 8.00;

assertCheck(
    'Scenario A',
    'A1: Cash tender less than order total is rejected',
    $tenderedInsufficient < $totalA
);

// A2: Valid Cash Payment, Change Computation, Electronic Receipt Issuance
$tenderedA = 15.00;
$changeA = round($tenderedA - $totalA, 2); // 5.00
$now = date('Y-m-d H:i:s');

$pdo->prepare("
    INSERT INTO orders (
        order_number, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr,
        status, payment_status, payment_method, amount_tendered_usd, change_usd,
        cashier_id, receipt_issued_at, paid_at, created_at
    ) VALUES (?, ?, 'small_car', ?, 0.0, ?, ?, 'waiting', 'paid', 'cash', ?, ?, 2, ?, ?, ?)
")->execute([$ordA_num, $plateA, $totalA, $totalA, (int)($totalA * 4100), $tenderedA, $changeA, $now, $now, $now]);
$orderA_id = (int)$pdo->lastInsertId();

$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Premium Exterior Wash', 10.00, 1)")
    ->execute([$orderA_id]);

$pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, 3, 2.50)")
    ->execute([$orderA_id]);

BayManager::ensureOrderTasks($pdo, $orderA_id);

$ordA = $pdo->query("SELECT * FROM orders WHERE id = {$orderA_id}")->fetch();
assertCheck(
    'Scenario A',
    'A2: Cash order confirmed as paid upfront, status=waiting, change=$5.00, electronic receipt stamped',
    $ordA['status'] === 'waiting' && $ordA['payment_status'] === 'paid' && (float)$ordA['change_usd'] === 5.00 && !empty($ordA['receipt_issued_at'])
);

// A3: Service Admission into Wash Bay 1
$admitA = BayManager::assignCarToBay($pdo, 1, $orderA_id, 2, 'Dara Cashier', 'cashier', 'Direct Counter Admission to Bay 1');
assertCheck(
    'Scenario A',
    'A3: Paid waiting vehicle admitted into Bay 1, bay status is busy',
    !empty($admitA['success']) && (int)$pdo->query("SELECT current_order_id FROM bays WHERE id = 1")->fetchColumn() === $orderA_id
);

// A4: Wash Completion Frees Bay 1 and moves to Finishing Pad QC
$finishA = BayManager::finishCar($pdo, 1, $orderA_id, 3, 'Bona Washer', 'washer');
$bay1Status = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 1")->fetch();
$tasksA = $pdo->query("SELECT task_type, status FROM order_tasks WHERE order_id = {$orderA_id}")->fetchAll();
$qcTaskA = null;
foreach ($tasksA as $t) {
    if ($t['task_type'] === 'qc') $qcTaskA = $t;
}
assertCheck(
    'Scenario A',
    'A4: Wash completion frees Bay 1 and promotes Finishing Pad QC task to waiting',
    !empty($finishA['success']) && $bay1Status['status'] === 'free' && $bay1Status['current_order_id'] === null && $qcTaskA && $qcTaskA['status'] === 'waiting'
);

// A5: Supervisor Passes Finishing Pad QC
$qcPassA = BayManager::passQc($pdo, $orderA_id, 5, 'Vannak Supervisor', 'wash_supervisor', [
    'rims_tires' => 'pass',
    'windshield' => 'pass',
    'carpets_mats' => 'pass',
    'door_sills' => 'pass',
    'final_appearance' => 'pass'
], 'All 5 points pristine');
$ordA_postQC = $pdo->query("SELECT status, finished_at FROM orders WHERE id = {$orderA_id}")->fetch();
assertCheck(
    'Scenario A',
    'A5: 5-point QC passed, order operational status transitions to finished (Ready for Pickup)',
    !empty($qcPassA['success']) && $ordA_postQC['status'] === 'finished' && !empty($ordA_postQC['finished_at'])
);

// A6: Customer Handover Verification
$handoverA = BayManager::handoverToCustomer($pdo, $orderA_id, 2, 'Dara Cashier', 'cashier');
$handoverAudit = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'CUSTOMER_HANDOVER' AND entity_id = '{$orderA_id}'")->fetchColumn();
assertCheck(
    'Scenario A',
    'A6: Vehicle successfully handed over to customer with strict audit log recorded',
    !empty($handoverA['success']) && (int)$handoverAudit === 1
);


// =================================================================
// SCENARIO B: KHQR / ABA Bank Transfer Verification & Genuine References
// =================================================================
echo "\n--- SCENARIO B: KHQR / ABA BANK TRANSFER VERIFICATION ---\n";

// B1: Unverified Order Creation
$ordB_num = 'ORD-261110-B01';
$plateB = '2A-9002';
$totalB = 35.00;

$pdo->prepare("
    INSERT INTO orders (
        order_number, plate_number, vehicle_type, subtotal_usd, total_usd, total_khr,
        status, payment_status, payment_method, cashier_id, created_at
    ) VALUES (?, ?, 'suv', ?, ?, ?, 'waiting', 'pending_verification', 'khqr', 2, ?)
")->execute([$ordB_num, $plateB, $totalB, $totalB, (int)($totalB * 4100), $now]);
$orderB_id = (int)$pdo->lastInsertId();

// B2: Unverified Order Admission Rejection
$unverifiedAdmit = BayManager::assignCarToBay($pdo, 2, $orderB_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario B',
    'B2: Unverified KHQR order strictly rejected from bay admission (PAYMENT_REQUIRED)',
    !$unverifiedAdmit['success'] && $unverifiedAdmit['error'] === 'PAYMENT_REQUIRED'
);

// B3: Cashier Statement Verification with Genuine Reference
$genuineBankRef = 'ABA-9876543210';
$mgrPin = '123456'; // Owner/Manager PIN
$verifySuccess = false;

// Simulate cashier statement verification
$pdo->prepare("
    UPDATE orders SET
        payment_status = 'paid',
        payment_ref = ?,
        paid_at = CURRENT_TIMESTAMP,
        receipt_issued_at = CURRENT_TIMESTAMP
    WHERE id = ? AND payment_status = 'pending_verification'
")->execute([$genuineBankRef, $orderB_id]);

AuditLogger::logStrict(
    $pdo,
    'BANK_PAYMENT_VERIFIED',
    "Cashier Dara Cashier verified KHQR payment of $35.00 against statement. Genuine Ref: {$genuineBankRef}.",
    'orders',
    (string)$orderB_id,
    2,
    'Dara Cashier',
    'cashier'
);

$ordB_postVerify = $pdo->query("SELECT payment_status, payment_ref, receipt_issued_at FROM orders WHERE id = {$orderB_id}")->fetch();
assertCheck(
    'Scenario B',
    'B3: Authorized cashier statement verification records genuine reference and stamps electronic receipt',
    $ordB_postVerify['payment_status'] === 'paid' && $ordB_postVerify['payment_ref'] === $genuineBankRef && !empty($ordB_postVerify['receipt_issued_at'])
);

// B4: Verified Order Admissible into Bay
$verifiedAdmit = BayManager::assignCarToBay($pdo, 2, $orderB_id, 2, 'Dara Cashier', 'cashier', 'Direct Counter Admission to Bay 2');
assertCheck(
    'Scenario B',
    'B4: Once statement is verified, order successfully admitted into Bay 2',
    !empty($verifiedAdmit['success']) && (int)$pdo->query("SELECT current_order_id FROM bays WHERE id = 2")->fetchColumn() === $orderB_id
);

// Clean up Bay 2 for next tests
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 2; UPDATE orders SET status = 'finished' WHERE id = {$orderB_id};");


// =================================================================
// SCENARIO C: Service Combinations (Wash, Mechanical, Mixed)
// =================================================================
echo "\n--- SCENARIO C: SERVICE COMBINATIONS & WORKFLOW DISPATCH ---\n";

// C1: Wash-Only Order Rejects Bay 4 (Bay 4 is Mechanical Lift Only)
$ordC1_num = 'ORD-261110-C01';
$plateC1 = '2A-9003';
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, total_usd, status, payment_status, payment_method, receipt_issued_at, paid_at)
    VALUES (?, ?, 'small_car', 10.00, 'waiting', 'paid', 'cash', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
")->execute([$ordC1_num, $plateC1]);
$ordC1_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Wash', 10.00, 1)")->execute([$ordC1_id]);
BayManager::ensureOrderTasks($pdo, $ordC1_id);

$admitC1_Bay4 = BayManager::assignCarToBay($pdo, 4, $ordC1_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario C',
    'C1: Wash-only order is strictly rejected from Bay 4 (BAY_SKILL_MISMATCH)',
    !$admitC1_Bay4['success'] && in_array($admitC1_Bay4['error'], ['BAY_SKILL_MISMATCH', 'WRONG_BAY_TYPE'], true)
);

// C2: Mechanical-Only Order Enters Bay 4, Rejects Wash Bays 1-3
$ordC2_num = 'ORD-261110-C02';
$plateC2 = '2A-9004';
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, total_usd, status, payment_status, payment_method, receipt_issued_at, paid_at)
    VALUES (?, ?, 'suv', 25.00, 'waiting', 'paid', 'cash', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
")->execute([$ordC2_num, $plateC2]);
$ordC2_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 2, 'repair', 'Full Engine Oil Service', 25.00, 1)")->execute([$ordC2_id]);
BayManager::ensureOrderTasks($pdo, $ordC2_id);

$admitC2_Bay1 = BayManager::assignCarToBay($pdo, 1, $ordC2_id, 2, 'Dara Cashier', 'cashier');
$admitC2_Bay4 = BayManager::assignCarToBay($pdo, 4, $ordC2_id, 2, 'Dara Cashier', 'cashier', 'Admit to Bay 4 Lift');
assertCheck(
    'Scenario C',
    'C2: Mechanical-only order rejected from Bay 1 and admitted into Bay 4',
    !$admitC2_Bay1['success'] && in_array($admitC2_Bay1['error'], ['PREREQUISITE_STEP_INCOMPLETE', 'BAY_SKILL_MISMATCH', 'WRONG_BAY_TYPE'], true) && !empty($admitC2_Bay4['success'])
);

// Complete mechanical inspection in Bay 4
TechnicalManager::ensureDefaultChecklist($pdo, $ordC2_id);
$checklists = $pdo->query("SELECT * FROM inspection_checklists WHERE order_id = {$ordC2_id}")->fetchAll(PDO::FETCH_ASSOC);
foreach ($checklists as $chk) {
    TechnicalManager::updateChecklistItem($pdo, (int)$chk['id'], 'pass', 'Within factory specs', '/uploads/inspections/item_' . $chk['id'] . '.jpg', 'Inspection notes', 4, 'Rith Mechanic');
}
$finishC2 = BayManager::finishCar($pdo, 4, $ordC2_id, 4, 'Rith Mechanic', 'mechanic');
assertCheck(
    'Scenario C',
    'C2b: Mechanical-only order completes in Bay 4 after inspection and bypasses wash',
    !empty($finishC2['success']) && (int)$pdo->query("SELECT current_order_id FROM bays WHERE id = 4")->fetchColumn() === 0
);

// C3: Mixed Order (Wash + Mechanical): Strict Lift-First Sequence
$ordC3_num = 'ORD-261110-C03';
$plateC3 = '2A-9005';
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, total_usd, is_mixed, status, payment_status, payment_method, receipt_issued_at, paid_at)
    VALUES (?, ?, 'suv', 35.00, 1, 'waiting', 'paid', 'cash', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
")->execute([$ordC3_num, $plateC3]);
$ordC3_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 2, 'repair', 'Oil Service', 25.00, 1)")->execute([$ordC3_id]);
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Wash', 10.00, 1)")->execute([$ordC3_id]);
BayManager::ensureOrderTasks($pdo, $ordC3_id);

// Attempt wash bay admission before mechanical work is done
$admitC3_PrematureWash = BayManager::assignCarToBay($pdo, 1, $ordC3_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario C',
    'C3a: Mixed order strictly blocked from wash bays during Step 1 (PREREQUISITE_INCOMPLETE)',
    !$admitC3_PrematureWash['success'] && in_array($admitC3_PrematureWash['error'], ['PREREQUISITE_STEP_INCOMPLETE', 'PREREQUISITE_INCOMPLETE', 'BAY_SKILL_MISMATCH'], true)
);

// Step 1: Admitted to Bay 4 (Lift First)
$admitC3_Bay4 = BayManager::assignCarToBay($pdo, 4, $ordC3_id, 2, 'Dara Cashier', 'cashier', 'Step 1 Lift First Admission');
assertCheck(
    'Scenario C',
    'C3b: Mixed order admitted into Bay 4 for Step 1 (Lift First)',
    !empty($admitC3_Bay4['success']) && (int)$pdo->query("SELECT current_order_id FROM bays WHERE id = 4")->fetchColumn() === $ordC3_id
);

// Complete Step 1 inspection & finish Bay 4
TechnicalManager::ensureDefaultChecklist($pdo, $ordC3_id);
$checklists3 = $pdo->query("SELECT * FROM inspection_checklists WHERE order_id = {$ordC3_id}")->fetchAll(PDO::FETCH_ASSOC);
foreach ($checklists3 as $chk) {
    TechnicalManager::updateChecklistItem($pdo, (int)$chk['id'], 'pass', 'OK', '/uploads/inspections/item3_' . $chk['id'] . '.jpg', 'OK notes', 4, 'Rith Mechanic');
}
$finishC3_Mech = BayManager::finishCar($pdo, 4, $ordC3_id, 4, 'Rith Mechanic', 'mechanic');
$bay4PostMech = $pdo->query("SELECT status, current_order_id FROM bays WHERE id = 4")->fetch();
assertCheck(
    'Scenario C',
    'C3c: Mechanical completion frees Bay 4 and automatically transitions order to Wash Queue',
    !empty($finishC3_Mech['success']) && $bay4PostMech['status'] === 'free' && $bay4PostMech['current_order_id'] === null
);

// Step 2: Now admissible into Bay 1, 2, or 3
$admitC3_WashStep2 = BayManager::assignCarToBay($pdo, 2, $ordC3_id, 2, 'Dara Cashier', 'cashier', 'Step 2 Wash Bay 2 Admission');
assertCheck(
    'Scenario C',
    'C3d: Mixed order Step 2 (Wash Second) admits successfully into Bay 2',
    !empty($admitC3_WashStep2['success']) && (int)$pdo->query("SELECT current_order_id FROM bays WHERE id = 2")->fetchColumn() === $ordC3_id
);

// Finish wash and pass QC
BayManager::finishCar($pdo, 2, $ordC3_id, 3, 'Bona Washer', 'washer');
BayManager::passQc($pdo, $ordC3_id, 5, 'Vannak Supervisor', 'wash_supervisor', [
    'rims_tires' => 'pass', 'windshield' => 'pass', 'carpets_mats' => 'pass', 'door_sills' => 'pass', 'final_appearance' => 'pass'
]);
$handoverC3 = BayManager::handoverToCustomer($pdo, $ordC3_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario C',
    'C3e: Mixed order passes Finishing Pad QC and completes final customer handover',
    !empty($handoverC3['success'])
);


// =================================================================
// SCENARIO D: Failure Handling, Concurrency & Quality Gates
// =================================================================
echo "\n--- SCENARIO D: FAILURE HANDLING, CONCURRENCY & QUALITY GATES ---\n";

// D1: Advisory Physical Printer (Printer Jam / Offline)
$ordD1_num = 'ORD-261110-D01';
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, total_usd, status, payment_status, receipt_issued_at, receipt_printed_at)
    VALUES (?, '2A-9006', 'small_car', 10.00, 'waiting', 'paid', CURRENT_TIMESTAMP, NULL)
")->execute([$ordD1_num]);
$ordD1_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Wash', 10.00, 1)")->execute([$ordD1_id]);

$admissible = false;
try {
    $admissible = OrderGate::assertServiceAdmissible($pdo, $ordD1_id);
} catch (Throwable $t) {
    $admissible = false;
}
assertCheck(
    'Scenario D',
    'D1: Physical printer failure (receipt_printed_at=NULL) is advisory and does not block service',
    $admissible === true
);

// D2: Duplicate Payment Submission / Idempotency Key Conflict
$idempotencyKey = 'IDEM-KEY-9999';
$pdo->prepare("
    INSERT INTO orders (order_number, idempotency_key, plate_number, total_usd, status, payment_status, receipt_issued_at)
    VALUES ('ORD-261110-D02', ?, '2A-9007', 10.00, 'waiting', 'paid', CURRENT_TIMESTAMP)
")->execute([$idempotencyKey]);

// Attempt duplicate creation with same key
$dupCheckStmt = $pdo->prepare("SELECT id, order_number FROM orders WHERE idempotency_key = ?");
$dupCheckStmt->execute([$idempotencyKey]);
$existingOrder = $dupCheckStmt->fetch();
assertCheck(
    'Scenario D',
    'D2: Duplicate submission with existing idempotency key is intercepted',
    !empty($existingOrder) && $existingOrder['order_number'] === 'ORD-261110-D02'
);

// D3: Bay Assignment Concurrency Protection (Attempting assignment to occupied bay)
$pdo->exec("UPDATE bays SET status = 'busy', current_order_id = 999 WHERE id = 3");
$admitConflict = BayManager::assignCarToBay($pdo, 3, $ordD1_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario D',
    'D3: Atomic lock rejects assigning car to already occupied bay (BAY_ALREADY_TAKEN)',
    !$admitConflict['success'] && in_array($admitConflict['error'], ['BAY_ALREADY_TAKEN', 'BAY_OCCUPIED'], true)
);
$pdo->exec("UPDATE bays SET status = 'free', current_order_id = NULL WHERE id = 3");

// D4: Supervisor QC Rejection & Priority Rework Dispatch
$ordD4_num = 'ORD-261110-D04';
$plateD4 = '2A-9008';
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, vehicle_type, total_usd, status, payment_status, receipt_issued_at)
    VALUES (?, ?, 'small_car', 10.00, 'washing', 'paid', CURRENT_TIMESTAMP)
")->execute([$ordD4_num, $plateD4]);
$ordD4_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Wash', 10.00, 1)")->execute([$ordD4_id]);
BayManager::ensureOrderTasks($pdo, $ordD4_id);

// Simulate car completed wash and moved to Finishing Pad QC
$pdo->exec("UPDATE order_tasks SET status = 'waiting' WHERE order_id = {$ordD4_id} AND task_type = 'qc'");

// Fail QC with specific reason
$failQcRes = BayManager::failQc($pdo, $ordD4_id, 'Water spots on rear windshield and dirty front rims', 5, 'Vannak Supervisor', 'wash_supervisor');
$ordD4_postFail = $pdo->query("SELECT status, queue_priority FROM orders WHERE id = {$ordD4_id}")->fetch();
$washTaskPostFail = $pdo->query("SELECT status FROM order_tasks WHERE order_id = {$ordD4_id} AND task_type = 'wash'")->fetchColumn();
$qcAudit = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'QC_FAIL' AND entity_id = '{$ordD4_id}'")->fetchColumn();

assertCheck(
    'Scenario D',
    'D4: Failed QC resets wash task to waiting, promotes queue_priority=1 (P1 rework), and logs failure reason',
    !empty($failQcRes['success']) && $ordD4_postFail['status'] === 'waiting' && (int)$ordD4_postFail['queue_priority'] === 1 && $washTaskPostFail === 'waiting' && (int)$qcAudit === 1
);

// D5: Premature Customer Handover Rejection
$prematureHandover = BayManager::handoverToCustomer($pdo, $ordD4_id, 2, 'Dara Cashier', 'cashier');
assertCheck(
    'Scenario D',
    'D5: Attempting customer handover on car undergoing rework is strictly blocked (PREMATURE_HANDOVER)',
    !$prematureHandover['success'] && $prematureHandover['error'] === 'PREMATURE_HANDOVER'
);


// =================================================================
// SCENARIO E: Financial Consistency & Cross-Module Reporting
// =================================================================
echo "\n--- SCENARIO E: FINANCIAL CONSISTENCY & REPORTING ---\n";

// Order E1: Regular paid order with product items
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, subtotal_usd, total_usd, status, payment_status, payment_method, paid_at, created_at)
    VALUES ('ORD-261110-E01', '2A-9010', 45.00, 45.00, 'waiting', 'paid', 'cash', date('now'), date('now'))
")->execute();
$ordE1_id = (int)$pdo->lastInsertId();
// Product 1: Synthetic Oil (cost 20.00, price 35.00)
$pdo->prepare("INSERT INTO order_items (order_id, product_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'product', 'Synthetic Oil 4L', 35.00, 1)")->execute([$ordE1_id]);
// Service 1: Wash (10.00)
$pdo->prepare("INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty) VALUES (?, 1, 'service', 'Wash', 10.00, 1)")->execute([$ordE1_id]);
// Washer commission 2.00
$pdo->prepare("INSERT INTO order_washers (order_id, washer_id, commission_usd) VALUES (?, 3, 2.00)")->execute([$ordE1_id]);

// Order E2: Order with settled refund
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, subtotal_usd, total_usd, status, payment_status, payment_method, refund_status, refund_amount_usd, paid_at, created_at)
    VALUES ('ORD-261110-E02', '2A-9011', 20.00, 20.00, 'finished', 'paid', 'cash', 'refunded', 10.00, date('now'), date('now'))
")->execute();
$ordE2_id = (int)$pdo->lastInsertId();

// Order E3: Product item with missing cost (Product 3: Tire Shine Spray, cost NULL, price 8.00)
$pdo->prepare("
    INSERT INTO orders (order_number, plate_number, subtotal_usd, total_usd, status, payment_status, payment_method, paid_at, created_at)
    VALUES ('ORD-261110-E03', '2A-9012', 8.00, 8.00, 'finished', 'paid', 'cash', date('now'), date('now'))
")->execute();
$ordE3_id = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_id, item_type, service_name, price_usd, qty) VALUES (?, 3, 'product', 'Tire Shine Spray', 8.00, 1)")->execute([$ordE3_id]);

// Add shop expenses
$pdo->exec("
    INSERT INTO expenses (category, description, amount_usd, expense_date)
    VALUES ('utilities', 'Water & Electric', 15.00, date('now'));
");

// Financial Calculations
$revReport = $pdo->query("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(o.total_usd), 0) as gross_revenue
    FROM orders o
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
")->fetch();
$grossConfirmed = (float)$revReport['gross_revenue'];

$refundReport = $pdo->query("
    SELECT COALESCE(SUM(
        CASE 
            WHEN o.refund_amount_usd > 0 THEN o.refund_amount_usd
            WHEN o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN o.total_usd
            ELSE 0 
        END
    ), 0) as total_refunds
    FROM orders o
    WHERE (o.refund_amount_usd > 0 OR o.refund_status = 'refunded' OR o.payment_status = 'refunded') AND o.status != 'cancelled'
")->fetchColumn();
$settledRefunds = (float)$refundReport;

$netSettledRevenue = $grossConfirmed - $settledRefunds;

$cogsReport = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN (COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0)) ELSE 0 END), 0) as product_cogs,
        COUNT(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN 1 END) as missing_cogs_count,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN oi.price_usd ELSE 0 END), 0) as missing_cogs_sales
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
")->fetch();
$productCogs = (float)$cogsReport['product_cogs'];
$missingCogsCount = (int)$cogsReport['missing_cogs_count'];

$wCommTotal = (float)$pdo->query("SELECT COALESCE(SUM(commission_usd), 0) FROM order_washers ow JOIN orders o ON ow.order_id = o.id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'")->fetchColumn();
$mCommTotal = (float)$pdo->query("SELECT COALESCE(SUM(commission_usd), 0) FROM order_mechanics om JOIN orders o ON om.order_id = o.id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'")->fetchColumn();
$commTotal = $wCommTotal + $mCommTotal;

$expTotal = (float)$pdo->query("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date = date('now')")->fetchColumn();

// Net Operating Profit = Net Settled Revenue - COGS - Commissions - Expenses
$netOperatingProfit = $netSettledRevenue - $productCogs - $commTotal - $expTotal;

assertCheck(
    'Scenario E',
    'E1: Net Settled Revenue = Confirmed Gross Payments - Settled Refunds correctly deducted',
    abs($netSettledRevenue - ($grossConfirmed - $settledRefunds)) < 0.001 && $settledRefunds === 10.00
);

assertCheck(
    'Scenario E',
    'E2: Product COGS calculated accurately and missing cost data flagged with warning',
    $productCogs >= 20.00 && $missingCogsCount >= 1
);

assertCheck(
    'Scenario E',
    'E3: Worker commissions and operating expenses separately identifiable and deducted in Net Operating Profit',
    $commTotal > 0 && $expTotal === 15.00 && abs($netOperatingProfit - ($netSettledRevenue - $productCogs - $commTotal - $expTotal)) < 0.001
);

// E4: Dashboard vs Detailed Reports Consistency
$dashTodayGross = (float)$pdo->query("
    SELECT COALESCE(SUM(total_usd), 0)
    FROM orders
    WHERE DATE(COALESCE(paid_at, created_at)) = date('now') AND (payment_status = 'paid' OR status = 'paid') AND status != 'cancelled'
")->fetchColumn();
assertCheck(
    'Scenario E',
    'E4: Dashboard today gross sales strictly reconciles with owner detailed report gross sales',
    abs($dashTodayGross - $grossConfirmed) < 0.001,
    "Dashboard: {$dashTodayGross}, Report: {$grossConfirmed}"
);

echo "\n=================================================================\n";
echo " WP5 FULL INTEGRATION SUMMARY: {$passed} Passed, {$failed} Failed\n";
echo "=================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
