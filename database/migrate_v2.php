<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();
$driver = Database::getDriver();
$autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

// 1. Inventory Table
$pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
    id {$autoInc},
    item_name_en VARCHAR(100) NOT NULL,
    item_name_km VARCHAR(100) NOT NULL,
    category VARCHAR(50) DEFAULT 'soap',
    unit VARCHAR(30) DEFAULT 'liter',
    quantity DECIMAL(10,2) DEFAULT 0.00,
    min_alert_qty DECIMAL(10,2) DEFAULT 5.00,
    unit_cost_usd DECIMAL(10,2) DEFAULT 0.00,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// 2. Appointments Table
$pdo->exec("CREATE TABLE IF NOT EXISTS appointments (
    id {$autoInc},
    customer_name VARCHAR(100) NOT NULL,
    customer_phone VARCHAR(20) NOT NULL,
    plate_number VARCHAR(50) NOT NULL,
    vehicle_type VARCHAR(30) NOT NULL,
    service_id INT DEFAULT NULL,
    appointment_date DATE NOT NULL,
    appointment_time VARCHAR(10) NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled',
    notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Seed Inventory if empty
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
if ($cnt === 0) {
    $items = [
        ['Snow Foam Car Shampoo (20L)', 'សាប៊ូលាងឡានបង្កើតពពុះព្រិល (20L)', 'soap', 'gallon', 8.50, 2.00, 25.00],
        ['Hydrophobic Quick Wax (5L)', 'ក្រមួនបាញ់ពន្លឺការពារទឹក Wax (5L)', 'wax', 'bottle', 4.00, 2.00, 35.00],
        ['Tire Shine Silicone Gel', 'ជែលបាញ់កង់ខ្មៅភ្លឺរលោង', 'chemical', 'bottle', 12.00, 3.00, 8.50],
        ['Engine Degreaser Spray', 'ទឹកថ្នាំបាញ់សម្អាតប្រេងម៉ាស៊ីន', 'chemical', 'can', 6.00, 3.00, 6.00],
        ['Microfiber Drying Towels (Large)', 'កន្សែងជូតសម្ងួត Microfiber ធំ', 'accessory', 'pcs', 24.00, 5.00, 2.50]
    ];
    $stmt = $pdo->prepare("INSERT INTO inventory (item_name_en, item_name_km, category, unit, quantity, min_alert_qty, unit_cost_usd) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $it) {
        $stmt->execute($it);
    }
}

// Seed Appointments if empty
$appCnt = (int)$pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
if ($appCnt === 0) {
    $apps = [
        ['Chenda Keo', '011 889 776', '2BA-1122', 'small_car', 3, date('Y-m-d'), '17:00', 'scheduled', 'Wants full wash before trip to Siem Reap'],
        ['Vibol Hem', '078 443 221', '2BC-5544', 'suv_pickup', 5, date('Y-m-d', strtotime('+1 day')), '09:30', 'scheduled', 'Full wax and polish detailing']
    ];
    $aStmt = $pdo->prepare("INSERT INTO appointments (customer_name, customer_phone, plate_number, vehicle_type, service_id, appointment_date, appointment_time, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($apps as $ap) {
        $aStmt->execute($ap);
    }
}

if (php_sapi_name() === 'cli') {
    echo "Inventory and Appointments tables migrated and seeded successfully!\n";
}
