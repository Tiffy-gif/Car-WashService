<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();
$driver = Database::getDriver();
$autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

// 1. Products Table (Engine Oil, Filters, Car Care Products)
$pdo->exec("CREATE TABLE IF NOT EXISTS products (
    id {$autoInc},
    name_en VARCHAR(100) NOT NULL,
    name_km VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT 'engine_oil', -- engine_oil, oil_filter, fluid, care_product
    brand VARCHAR(50) DEFAULT NULL,
    unit VARCHAR(30) DEFAULT 'can',
    cost_price_usd DECIMAL(10,2) DEFAULT 0.00,
    price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    price_khr INT NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    min_stock_alert INT DEFAULT 5,
    is_active INT DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 2. Add Mileage & Oil Change Columns to Orders (if not exists)
if ($driver === 'mysql') {
    $cols = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('current_mileage', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN current_mileage INT DEFAULT NULL, ADD COLUMN next_oil_mileage INT DEFAULT NULL, ADD COLUMN next_oil_date DATE DEFAULT NULL");
    }
} else {
    // SQLite
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN current_mileage INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN next_oil_mileage INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN next_oil_date DATE DEFAULT NULL");
    } catch (Exception $e) {}
}

// 3. Add Mileage & Oil Change Columns to Vehicles (if not exists)
if ($driver === 'mysql') {
    $vCols = $pdo->query("DESCRIBE vehicles")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('last_mileage', $vCols, true)) {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN last_mileage INT DEFAULT NULL, ADD COLUMN next_oil_mileage INT DEFAULT NULL, ADD COLUMN next_oil_date DATE DEFAULT NULL");
    }
} else {
    try {
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN last_mileage INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN next_oil_mileage INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE vehicles ADD COLUMN next_oil_date DATE DEFAULT NULL");
    } catch (Exception $e) {}
}

// 4. Update order_items to support products
if ($driver === 'mysql') {
    $oiCols = $pdo->query("DESCRIBE order_items")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('item_type', $oiCols, true)) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN item_type VARCHAR(20) DEFAULT 'service', ADD COLUMN product_id INT DEFAULT NULL, ADD COLUMN qty INT DEFAULT 1");
    }
    try {
        $pdo->exec("ALTER TABLE order_items MODIFY service_id INT NULL DEFAULT NULL");
    } catch (Exception $e) {}
} else {
    try {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN item_type VARCHAR(20) DEFAULT 'service'");
        $pdo->exec("ALTER TABLE order_items ADD COLUMN product_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE order_items ADD COLUMN qty INT DEFAULT 1");
    } catch (Exception $e) {}
}

// 5. Oil Change Logs Table (for due reminders)
$pdo->exec("CREATE TABLE IF NOT EXISTS oil_change_logs (
    id {$autoInc},
    order_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,
    customer_id INT DEFAULT NULL,
    plate_number VARCHAR(50) NOT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_name VARCHAR(100) DEFAULT NULL,
    oil_product_name VARCHAR(100) DEFAULT NULL,
    current_mileage INT NOT NULL,
    next_due_mileage INT NOT NULL,
    oil_change_date DATE NOT NULL,
    next_due_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 6. Seed Sample Products if empty
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
if ($cnt === 0) {
    $products = [
        // Engine Oils
        ['Castrol EDGE 5W-30 Full Synthetic (4L)', 'ប្រេងម៉ាស៊ីន Castrol EDGE 5W-30 សំយោគពេញលេញ (4L)', 'engine_oil', 'Castrol', 'can (4L)', 28.00, 38.00, 155800, 24, 5],
        ['Mobil 1 ESP 5W-30 Advanced Synthetic (4L)', 'ប្រេងម៉ាស៊ីន Mobil 1 ESP 5W-30 សំយោគកម្រិតខ្ពស់ (4L)', 'engine_oil', 'Mobil 1', 'can (4L)', 30.00, 42.00, 172200, 18, 4],
        ['Motul 10W-40 Semi-Synthetic Motorbike (1L)', 'ប្រេងម៉ាស៊ីន Motul 10W-40 សម្រាប់ម៉ូតូ (1L)', 'engine_oil', 'Motul', 'bottle (1L)', 6.50, 9.50, 38950, 40, 8],
        ['Total Quartz 9000 5W-40 (4L)', 'ប្រេងម៉ាស៊ីន Total Quartz 9000 5W-40 (4L)', 'engine_oil', 'Total', 'can (4L)', 24.00, 34.00, 139400, 15, 3],
        
        // Oil Filters
        ['Genuine Toyota Oil Filter (Prius/Camry/Corolla)', 'តម្រងប្រេងម៉ាស៊ីន Toyota សុទ្ធ (Prius/Camry)', 'oil_filter', 'Toyota', 'pcs', 3.50, 6.00, 24600, 35, 10],
        ['Denso High-Performance Oil Filter (Ford/Lexus)', 'តម្រងប្រេងម៉ាស៊ីន Denso (Ford Ranger/Lexus)', 'oil_filter', 'Denso', 'pcs', 4.50, 8.00, 32800, 28, 8],
        ['Honda Genuine Motorcycle Oil Filter', 'តម្រងប្រេងម៉ាស៊ីនម៉ូតូ Honda សុទ្ធ', 'oil_filter', 'Honda', 'pcs', 1.50, 3.50, 14350, 50, 15],

        // Car Care & Fluids
        ['Rain-X Windshield Washer Fluid (1 Gallon)', 'ទឹកបាញ់កញ្ចក់រថយន្ត Rain-X ការពារទឹកភ្លៀង', 'fluid', 'Rain-X', 'gallon', 3.00, 5.50, 22550, 20, 5],
        ['Toyota Super Long Life Coolant Pink (4L)', 'ទឹកស្អំត្រជាក់ម៉ាស៊ីន Toyota ពណ៌ផ្កាឈូក (4L)', 'fluid', 'Toyota', 'can (4L)', 14.00, 22.00, 90200, 12, 3],
        ['California Scents Car Air Freshener Can', 'កំប៉ុងក្រអូបរថយន្ត California Scents', 'care_product', 'California', 'can', 2.00, 4.00, 16400, 45, 10],
    ];

    $stmt = $pdo->prepare("INSERT INTO products (name_en, name_km, category, brand, unit, cost_price_usd, price_usd, price_khr, stock_qty, min_stock_alert) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($products as $p) {
        $stmt->execute($p);
    }
}

// 7. Seed Sample Oil Change Logs (One due soon, one overdue, one recent)
$logCnt = (int)$pdo->query("SELECT COUNT(*) FROM oil_change_logs")->fetchColumn();
if ($logCnt === 0) {
    $sampleLogs = [
        // 1. Ford Ranger - Changed 2.5 months ago, due in 2 weeks (DUE SOON)
        [
            1, 1, 1, '2BQ-8899', '012 889 900', 'Sokha Meng',
            'Mobil 1 ESP 5W-30 (4L) + Denso Filter',
            75000, 85000,
            date('Y-m-d', strtotime('-80 days')),
            date('Y-m-d', strtotime('+10 days')),
            'Customer requested sticker on windshield'
        ],
        // 2. Toyota Prius - Changed 4 months ago (OVERDUE)
        [
            2, 3, 2, '2AB-1234', '093 456 789', 'Borey Chea',
            'Castrol EDGE 5W-30 (4L) + Toyota Filter',
            112000, 122000,
            date('Y-m-d', strtotime('-120 days')),
            date('Y-m-d', strtotime('-30 days')),
            'Customer prefers 5W-30 high fuel economy'
        ],
        // 3. Honda Scoopy - Changed 2 weeks ago (GOOD)
        [
            4, 2, 1, '1AC-5566', '012 889 900', 'Sokha Meng',
            'Motul 10W-40 Motorbike (1L)',
            15000, 18000,
            date('Y-m-d', strtotime('-14 days')),
            date('Y-m-d', strtotime('+76 days')),
            'Regular oil replacement'
        ]
    ];

    $lStmt = $pdo->prepare("INSERT INTO oil_change_logs (order_id, vehicle_id, customer_id, plate_number, customer_phone, customer_name, oil_product_name, current_mileage, next_due_mileage, oil_change_date, next_due_date, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($sampleLogs as $sl) {
        $lStmt->execute($sl);
    }
}

if (php_sapi_name() === 'cli') {
    echo "Oil Change & Car Care module migrated and seeded successfully!\n";
}
