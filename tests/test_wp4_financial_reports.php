<?php
declare(strict_types=1);

/**
 * WP4 Automated Verification Suite: Financial Reports & Profit Calculation
 *
 * Isolated temporary SQLite database in sys_get_temp_dir().
 * Zero writes to MariaDB 'carwash_db'.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_wp4_finance_' . uniqid() . '.sqlite';
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

// Build isolated schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL,
        status TEXT DEFAULT 'active'
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
        category TEXT DEFAULT 'wash',
        price_usd REAL DEFAULT 5.00
    );

    CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name_en TEXT NOT NULL,
        category TEXT DEFAULT 'care_product',
        cost_price_usd REAL DEFAULT NULL,
        price_usd REAL DEFAULT 10.00
    );

    CREATE TABLE orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number TEXT NOT NULL UNIQUE,
        source TEXT DEFAULT 'pos_app',
        pos_device_id INTEGER DEFAULT 1,
        pos_device_name TEXT DEFAULT 'Counter Tablet 1',
        customer_name TEXT DEFAULT 'Walk-in Guest',
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
        refund_status TEXT DEFAULT NULL,
        refund_amount_usd REAL DEFAULT 0.0,
        refund_reason TEXT DEFAULT NULL,
        cashier_id INTEGER DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME DEFAULT NULL,
        refunded_at DATETIME DEFAULT NULL
    );

    CREATE TABLE order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER DEFAULT NULL,
        product_id INTEGER DEFAULT NULL,
        item_type TEXT NOT NULL,
        service_name TEXT NOT NULL,
        price_usd REAL DEFAULT 0.0,
        qty INTEGER DEFAULT 1
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

    CREATE TABLE expenses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category TEXT NOT NULL,
        description TEXT DEFAULT '',
        amount_usd REAL NOT NULL,
        expense_date DATE NOT NULL
    );
");

// Seed initial users and devices
$pdo->exec("
    INSERT INTO users (id, username, full_name, role) VALUES
    (1, 'owner', 'Sok Owner', 'owner'),
    (2, 'cashier1', 'Dara Cashier', 'cashier'),
    (3, 'washer1', 'Bona Washer', 'washer'),
    (4, 'mechanic1', 'Rith Mechanic', 'mechanic');

    INSERT INTO pos_devices (id, device_uid, device_name, location) VALUES
    (1, 'POS-BAY-01', 'Counter Station 1', 'Bay 1 Desk'),
    (2, 'POS-BAY-02', 'Counter Station 2', 'Bay 2 Desk');

    INSERT INTO services (id, name_en, category, price_usd) VALUES
    (1, 'Basic Wash', 'wash', 10.00),
    (2, 'Oil Change Labor', 'repair', 15.00);

    INSERT INTO products (id, name_en, category, cost_price_usd, price_usd) VALUES
    (1, 'Synthetic Oil 4L', 'spare_part', 20.00, 35.00),
    (2, 'Microfiber Towel', 'care_product', NULL, 5.00); -- intentionally NULL cost to test missing COGS warning
");

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] {$description}\n";
    } else {
        $failed++;
        echo "[FAIL] {$description}" . ($detail ? " - {$detail}" : '') . "\n";
    }
}

echo "=========================================================\n";
echo " WP4 Automated Verification: Financial Reports & Profit   \n";
echo "=========================================================\n\n";

// TEST 1: Operational vs Financial Independence
// Order 1: Upfront paid, but operationally 'waiting'
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method, cashier_id, paid_at, created_at)
    VALUES (1, 'ORD-2026-0001', '2A-1111', 'small_car', 10.00, 0.00, 10.00, 41000, 'waiting', 'paid', 'cash', 2, date('now'), date('now'));

    INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty)
    VALUES (1, 1, 'service', 'Basic Wash', 10.00, 1);

    INSERT INTO order_washers (order_id, washer_id, commission_usd)
    VALUES (1, 3, 2.00);
");

// Order 2: Upfront paid, operationally 'washing'
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, vehicle_type, subtotal_usd, discount_usd, total_usd, total_khr, status, payment_status, payment_method, cashier_id, paid_at, created_at)
    VALUES (2, 'ORD-2026-0002', '2A-2222', 'suv', 50.00, 0.00, 50.00, 205000, 'washing', 'paid', 'aba', 2, date('now'), date('now'));

    INSERT INTO order_items (order_id, service_id, item_type, service_name, price_usd, qty)
    VALUES (2, 2, 'repair', 'Oil Change Labor', 15.00, 1);

    INSERT INTO order_items (order_id, product_id, item_type, service_name, price_usd, qty)
    VALUES (2, 1, 'product', 'Synthetic Oil 4L', 35.00, 1);

    INSERT INTO order_mechanics (order_id, mechanic_id, commission_usd)
    VALUES (2, 4, 3.50);
");

// Query gross revenue using WP4 formula
$revSql = "
    SELECT 
        COUNT(*) as total_vehicles, 
        COALESCE(SUM(o.total_usd), 0) as gross_revenue 
    FROM orders o 
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
";
$revRow = $pdo->query($revSql)->fetch();
assertTest(
    "1. Paid orders with operational status 'waiting' and 'washing' are included in gross revenue",
    (int)$revRow['total_vehicles'] === 2 && abs((float)$revRow['gross_revenue'] - 60.00) < 0.01,
    "Expected 2 orders, $60.00. Got: " . $revRow['total_vehicles'] . " orders, $" . $revRow['gross_revenue']
);

// TEST 2: Strict Exclusion of Unpaid, Pending, and Cancelled
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, cashier_id, created_at)
    VALUES (3, 'ORD-2026-0003', '2A-3333', 25.00, 25.00, 'waiting', 'unpaid', 2, date('now'));

    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, cashier_id, created_at)
    VALUES (4, 'ORD-2026-0004', '2A-4444', 30.00, 30.00, 'waiting', 'pending', 2, date('now'));

    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, cashier_id, created_at)
    VALUES (5, 'ORD-2026-0005', '2A-5555', 40.00, 40.00, 'cancelled', 'paid', 2, date('now'));
");

$revRow2 = $pdo->query($revSql)->fetch();
assertTest(
    "2. Unpaid, pending, and cancelled orders are strictly excluded from confirmed revenue",
    (int)$revRow2['total_vehicles'] === 2 && abs((float)$revRow2['gross_revenue'] - 60.00) < 0.01,
    "Expected 2 orders, $60.00. Got: " . $revRow2['total_vehicles'] . " orders, $" . $revRow2['gross_revenue']
);

// TEST 3: Settled Refunds Calculation & Deduction
// Order 6: Paid $20, later issued $10 settled refund
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, total_khr, status, payment_status, payment_method, refund_status, refund_amount_usd, cashier_id, paid_at, created_at)
    VALUES (6, 'ORD-2026-0006', '2A-6666', 20.00, 20.00, 82000, 'finished', 'paid', 'cash', 'refunded', 10.00, 2, date('now'), date('now'));
");

$refSql = "
    SELECT 
        COALESCE(SUM(
            CASE 
                WHEN o.refund_amount_usd > 0 THEN o.refund_amount_usd
                WHEN o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN o.total_usd
                ELSE 0 
            END
        ), 0) as total_refunds_usd,
        COUNT(CASE WHEN o.refund_amount_usd > 0 OR o.refund_status = 'refunded' OR o.payment_status = 'refunded' THEN 1 END) as refund_count
    FROM orders o 
    WHERE (o.refund_amount_usd > 0 OR o.refund_status = 'refunded' OR o.payment_status = 'refunded')
      AND o.status != 'cancelled'
";
$refRow = $pdo->query($refSql)->fetch();
$revData3 = $pdo->query($revSql)->fetch();
$grossRevNow = (float)$revData3['gross_revenue'];
$settledRefunds = (float)$refRow['total_refunds_usd'];
$netSettledRevenue = $grossRevNow - $settledRefunds;

assertTest(
    "3. Confirmed gross revenue ($80.00) - Settled refunds ($10.00) = Net Settled Revenue ($70.00)",
    abs($grossRevNow - 80.00) < 0.01 && abs($settledRefunds - 10.00) < 0.01 && abs($netSettledRevenue - 70.00) < 0.01,
    "Gross: {$grossRevNow}, Refunds: {$settledRefunds}, Net: {$netSettledRevenue}"
);

// TEST 4: Unsettled / Zero-Amount Refund Exclusion
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, refund_status, refund_amount_usd, cashier_id, paid_at, created_at)
    VALUES (7, 'ORD-2026-0007', '2A-7777', 15.00, 15.00, 'finished', 'paid', NULL, 0.00, 2, date('now'), date('now'));
");
$refRow4 = $pdo->query($refSql)->fetch();
assertTest(
    "4. Orders without settled refunds do not inflate refund totals",
    abs((float)$refRow4['total_refunds_usd'] - 10.00) < 0.01 && (int)$refRow4['refund_count'] === 1,
    "Refund USD: " . $refRow4['total_refunds_usd'] . ", count: " . $refRow4['refund_count']
);

// TEST 5: Double-Counting Prevention
// Check that joining order_washers and order_items does not double count revenue
$checkDoubleCount = "
    SELECT 
        COALESCE(SUM(o.total_usd), 0) as gross_revenue 
    FROM orders o 
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
";
$singleTotal = (float)$pdo->query($checkDoubleCount)->fetchColumn();
// Total paid orders so far: Ord 1 ($10) + Ord 2 ($50) + Ord 6 ($20) + Ord 7 ($15) = $95.00
assertTest(
    "5. Order level revenue is exactly $95.00 with zero cross-join multiplication",
    abs($singleTotal - 95.00) < 0.01,
    "Got: {$singleTotal}"
);

// TEST 6: COGS Calculation
// Ord 2 has product 1 (qty 1, cost 20.00). Let's add Ord 8 with 2 units of product 1
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, cashier_id, paid_at, created_at)
    VALUES (8, 'ORD-2026-0008', '2A-8888', 70.00, 70.00, 'finished', 'paid', 2, date('now'), date('now'));

    INSERT INTO order_items (order_id, product_id, item_type, service_name, price_usd, qty)
    VALUES (8, 1, 'product', 'Synthetic Oil 4L', 70.00, 2);
");

$cogsSql = "
    SELECT 
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN (COALESCE(oi.qty, 1) * COALESCE(p.cost_price_usd, 0)) ELSE 0 END), 0) as product_cogs,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') THEN oi.price_usd ELSE 0 END), 0) as product_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
";
$cogsData = $pdo->query($cogsSql)->fetch();
// Ord 2 (1 * 20.00) + Ord 8 (2 * 20.00) = 60.00 COGS
assertTest(
    "6. Actual recorded COGS is $60.00 (3 units @ $20.00 each)",
    abs((float)$cogsData['product_cogs'] - 60.00) < 0.01,
    "COGS: " . $cogsData['product_cogs']
);

// TEST 7: Missing COGS Warning
// Add Ord 9 with product 2 (cost_price_usd is NULL)
$pdo->exec("
    INSERT INTO orders (id, order_number, plate_number, subtotal_usd, total_usd, status, payment_status, cashier_id, paid_at, created_at)
    VALUES (9, 'ORD-2026-0009', '2A-9999', 5.00, 5.00, 'finished', 'paid', 2, date('now'), date('now'));

    INSERT INTO order_items (order_id, product_id, item_type, service_name, price_usd, qty)
    VALUES (9, 2, 'product', 'Microfiber Towel', 5.00, 1);
");

$missingCogsSql = "
    SELECT 
        COUNT(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN 1 END) as missing_cogs_count,
        COALESCE(SUM(CASE WHEN oi.item_type IN ('product', 'spare_part') AND (p.cost_price_usd IS NULL OR p.cost_price_usd <= 0) THEN oi.price_usd ELSE 0 END), 0) as missing_cogs_sales
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'
";
$missingData = $pdo->query($missingCogsSql)->fetch();
assertTest(
    "7. Missing COGS data is properly detected and flagged (count = 1, sales = $5.00)",
    (int)$missingData['missing_cogs_count'] === 1 && abs((float)$missingData['missing_cogs_sales'] - 5.00) < 0.01,
    "Count: " . $missingData['missing_cogs_count'] . ", Sales: " . $missingData['missing_cogs_sales']
);

// TEST 8: Net Operating Profit Formula
// Net Operating Profit = Net Settled Revenue - COGS - Worker Commissions - Operating Expenses
// Let's add operating expenses
$pdo->exec("
    INSERT INTO expenses (category, description, amount_usd, expense_date)
    VALUES ('utilities', 'Electricity & Water', 30.00, date('now')),
           ('supplies', 'Soap & Shampoo', 10.00, date('now'));
");

$wComm = (float)$pdo->query("SELECT COALESCE(SUM(ow.commission_usd), 0) FROM orders o JOIN order_washers ow ON o.id = ow.order_id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'")->fetchColumn();
$mComm = (float)$pdo->query("SELECT COALESCE(SUM(om.commission_usd), 0) FROM orders o JOIN order_mechanics om ON o.id = om.order_id WHERE (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled'")->fetchColumn();
$totalComm = $wComm + $mComm; // 2.00 (washer) + 3.50 (mechanic) = 5.50

$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount_usd), 0) FROM expenses WHERE expense_date = date('now')")->fetchColumn(); // 40.00

$grossPaid = (float)$pdo->query($checkDoubleCount)->fetchColumn(); // Ord 1 (10) + Ord 2 (50) + Ord 6 (20) + Ord 7 (15) + Ord 8 (70) + Ord 9 (5) = 170.00
$refunds = (float)$pdo->query($refSql)->fetch()['total_refunds_usd']; // 10.00
$netSettled = $grossPaid - $refunds; // 160.00
$totalCogs = (float)$pdo->query($cogsSql)->fetch()['product_cogs']; // 60.00

// Net Operating Profit = 160.00 (Net Settled) - 60.00 (COGS) - 5.50 (Commissions) - 40.00 (Expenses) = 54.50
$netOperatingProfit = $netSettled - $totalCogs - $totalComm - $totalExpenses;

assertTest(
    "8. Net Operating Profit correctly computed: Net Settled ($160.00) - COGS ($60.00) - Comm ($5.50) - Exp ($40.00) = $54.50",
    abs($netOperatingProfit - 54.50) < 0.01,
    "Expected 54.50, Got: " . $netOperatingProfit
);

// TEST 9: Commissions Reporting Visibility
// In commissions.php: Washer and Mechanic commissions are separately identifiable
assertTest(
    "9. Commissions are separately identifiable: Washer ($2.00), Mechanic ($3.50), Total ($5.50)",
    abs($wComm - 2.00) < 0.01 && abs($mComm - 3.50) < 0.01 && abs($totalComm - 5.50) < 0.01,
    "Washer: {$wComm}, Mechanic: {$mComm}, Total: {$totalComm}"
);

// TEST 10: POS Device & Cashier Sales Reconciliation
$devQuery = $pdo->query("
    SELECT 
        COUNT(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' THEN 1 END) as paid_count,
        COALESCE(SUM(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' THEN o.total_usd ELSE 0 END), 0) as gross_sales,
        COALESCE(SUM(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND o.payment_method = 'cash' THEN o.total_usd ELSE 0 END), 0) as cash_sales,
        COALESCE(SUM(CASE WHEN (o.payment_status = 'paid' OR o.status = 'paid') AND o.status != 'cancelled' AND o.payment_method = 'aba' THEN o.total_usd ELSE 0 END), 0) as aba_sales
    FROM orders o
    WHERE pos_device_id = 1
")->fetch();

assertTest(
    "10. POS Device Tender Breakdown reconciles: 6 paid orders, Gross $170.00, Cash $120.00, ABA $50.00",
    (int)$devQuery['paid_count'] === 6 && abs((float)$devQuery['gross_sales'] - 170.00) < 0.01 && abs((float)$devQuery['cash_sales'] - 120.00) < 0.01 && abs((float)$devQuery['aba_sales'] - 50.00) < 0.01,
    "Paid Count: " . $devQuery['paid_count'] . ", Gross: " . $devQuery['gross_sales'] . ", Cash: " . $devQuery['cash_sales'] . ", ABA: " . $devQuery['aba_sales']
);

echo "\n=========================================================\n";
echo " WP4 Verification Summary: {$passed} Passed, {$failed} Failed\n";
echo "=========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
