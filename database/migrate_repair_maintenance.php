<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_repair_maintenance(): void {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    migrateLog("Starting Basic Maintenance & Minor Repair migration...");

// 1. Add category column to services table if not exists
if ($driver === 'mysql') {
    $sCols = $pdo->query("DESCRIBE services")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category', $sCols, true)) {
        $pdo->exec("ALTER TABLE services ADD COLUMN category VARCHAR(30) DEFAULT 'wash' AFTER icon");
        migrateLog("Added category column to services.");
    }
} else {
    try {
        $pdo->exec("ALTER TABLE services ADD COLUMN category VARCHAR(30) DEFAULT 'wash'");
    } catch (Exception $e) {}
}

// Ensure all existing services have category = 'wash' if null
$pdo->exec("UPDATE services SET category = 'wash' WHERE category IS NULL OR category = ''");

// 2. Add repair columns to orders table if not exists
if ($driver === 'mysql') {
    $oCols = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('repair_status', $oCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN repair_status VARCHAR(20) DEFAULT NULL, ADD COLUMN repair_notes TEXT DEFAULT NULL");
        migrateLog("Added repair_status & repair_notes to orders.");
    }
} else {
    try {
        $pdo->exec("ALTER TABLE orders ADD COLUMN repair_status VARCHAR(20) DEFAULT NULL");
        $pdo->exec("ALTER TABLE orders ADD COLUMN repair_notes TEXT DEFAULT NULL");
    } catch (Exception $e) {}
}

// 3. Create order_mechanics Table
$pdo->exec("CREATE TABLE IF NOT EXISTS order_mechanics (
    id {$autoInc},
    order_id INT NOT NULL,
    mechanic_id INT NOT NULL,
    commission_usd DECIMAL(10,2) DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
migrateLog("Table order_mechanics verified.");

// 4. Create vehicle_repair_history Table
$pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_repair_history (
    id {$autoInc},
    order_id INT NOT NULL,
    vehicle_id INT DEFAULT NULL,
    plate_number VARCHAR(50) NOT NULL,
    customer_name VARCHAR(100) DEFAULT NULL,
    mechanic_id INT DEFAULT NULL,
    mechanic_name VARCHAR(100) DEFAULT NULL,
    services_performed TEXT NOT NULL,
    parts_replaced TEXT DEFAULT NULL,
    labor_cost_usd DECIMAL(10,2) DEFAULT 0.00,
    parts_cost_usd DECIMAL(10,2) DEFAULT 0.00,
    total_cost_usd DECIMAL(10,2) DEFAULT 0.00,
    mileage INT DEFAULT NULL,
    repair_notes TEXT DEFAULT NULL,
    repair_date DATETIME DEFAULT CURRENT_TIMESTAMP
)");
migrateLog("Table vehicle_repair_history verified.");

// 5. Seed Sample Mechanic if not exists
$mechStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'mechanic1'");
$mechStmt->execute();
if ((int)$mechStmt->fetchColumn() === 0) {
    $insMech = $pdo->prepare("
        INSERT INTO users (username, password_hash, full_name, phone, role, commission_type, commission_rate, status)
        VALUES ('mechanic1', ?, 'Kosal Sem (Mechanic)', '089 556 677', 'mechanic', 'percentage', 20.00, 'active')
    ");
    $insMech->execute([password_hash('mechanic123', PASSWORD_DEFAULT)]);
    migrateLog("Seeded sample mechanic: mechanic1 (mechanic123)");
}

// 6. Seed Sample Repair Services if not exists
$repairsCount = (int)$pdo->query("SELECT COUNT(*) FROM services WHERE category = 'repair'")->fetchColumn();
if ($repairsCount === 0) {
    $repairServices = [
        // id, name_en, name_km, desc, icon, prices [motorbike, small_car, suv_pickup, van_truck]
        [
            'name_en' => 'Tire Patch & Puncture Repair',
            'name_km' => 'ប៉ះកង់រថយន្ត & ម៉ូតូ',
            'desc' => 'Tubeless patch plug, bead seal check, valve inspection and tire re-inflation',
            'icon' => 'fa-circle-dot',
            'prices' => ['motorbike' => 1.50, 'small_car' => 3.00, 'suv_pickup' => 4.00, 'van_truck' => 5.00],
            'min' => 20
        ],
        [
            'name_en' => 'Tire Change & Wheel Balance',
            'name_km' => 'ប្តូរសំបកកង់ & ថ្លឹងកង់',
            'desc' => 'Dismount, mount new tire, dynamic wheel balancing, and torque lug nuts to factory spec',
            'icon' => 'fa-arrows-rotate',
            'prices' => ['motorbike' => 2.00, 'small_car' => 5.00, 'suv_pickup' => 8.00, 'van_truck' => 10.00],
            'min' => 30
        ],
        [
            'name_en' => 'Brake Pad Replacement (Labor)',
            'name_km' => 'ប្តូរស្បែកហ្វ្រាំង (ថ្លៃឈ្នួល)',
            'desc' => 'Remove worn pads, caliper cleaning & guide pin lubrication, install new brake pads',
            'icon' => 'fa-compact-disc',
            'prices' => ['motorbike' => 3.00, 'small_car' => 8.00, 'suv_pickup' => 12.00, 'van_truck' => 15.00],
            'min' => 40
        ],
        [
            'name_en' => 'Brake Inspection & Adjustment',
            'name_km' => 'ពិនិត្យ & សារ៉េហ្វ្រាំង',
            'desc' => 'Comprehensive 4-wheel brake inspection, fluid moisture test, handbrake tension adjustment',
            'icon' => 'fa-magnifying-glass-chart',
            'prices' => ['motorbike' => 1.50, 'small_car' => 3.50, 'suv_pickup' => 5.00, 'van_truck' => 6.00],
            'min' => 25
        ],
        [
            'name_en' => 'Battery Replacement & Electrical Test',
            'name_km' => 'ប្តូរអាគុយ & តេស្តភ្លើង',
            'desc' => 'Battery terminal cleaning, alternator charge test, starter voltage drop test, and battery install',
            'icon' => 'fa-car-battery',
            'prices' => ['motorbike' => 1.00, 'small_car' => 3.00, 'suv_pickup' => 4.00, 'van_truck' => 5.00],
            'min' => 15
        ],
        [
            'name_en' => 'Headlight / Tail Bulb Change',
            'name_km' => 'ប្តូរអំពូលភ្លើងមុខ / ក្រោយ',
            'desc' => 'Socket inspection, install halogen/LED bulb, beam alignment check',
            'icon' => 'fa-lightbulb',
            'prices' => ['motorbike' => 1.00, 'small_car' => 2.50, 'suv_pickup' => 3.50, 'van_truck' => 4.00],
            'min' => 15
        ],
        [
            'name_en' => 'Wiper Blade Change (Labor)',
            'name_km' => 'ប្តូរផ្លិតទឹក (ថ្លៃឈ្នួល)',
            'desc' => 'Windshield clean, remove old blades, install aero beam wipers, washer spray check',
            'icon' => 'fa-wind',
            'prices' => ['motorbike' => 0.00, 'small_car' => 1.50, 'suv_pickup' => 2.00, 'van_truck' => 2.50],
            'min' => 10
        ],
    ];

    $insServ = $pdo->prepare("INSERT INTO services (name_en, name_km, description, icon, category, is_active) VALUES (?, ?, ?, ?, 'repair', 1)");
    $insPrice = $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, ?, ?, ?, ?)");

    foreach ($repairServices as $rs) {
        $insServ->execute([$rs['name_en'], $rs['name_km'], $rs['desc'], $rs['icon']]);
        $sId = (int)$pdo->lastInsertId();

        foreach ($rs['prices'] as $vt => $pUsd) {
            $pKhr = (int)round($pUsd * DEFAULT_EXCHANGE_RATE);
            $insPrice->execute([$sId, $vt, $pUsd, $pKhr, $rs['min']]);
        }
    }
    migrateLog("Seeded " . count($repairServices) . " repair services with tiered vehicle prices.");
}

// 7. Seed Sample Spare Parts into products if not exists
$sparePartsCount = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE category = 'spare_part'")->fetchColumn();
if ($sparePartsCount === 0) {
    $spareParts = [
        // name_en, name_km, category, brand, unit, cost, sell, stock, alert
        ['Bendix Ultra Premium Front Brake Pads (Camry/Prius)', 'ស្បែកហ្វ្រាំងមុខ Bendix Ultra Premium (Camry/Prius)', 'spare_part', 'Bendix', 'set (4pcs)', 14.00, 24.00, 15, 4],
        ['Ceramic Heavy-Duty Brake Pads (Hilux/Ranger/SUV)', 'ស្បែកហ្វ្រាំងសេរ៉ាមិចធុនធ្ងន់ (Hilux/Ranger/SUV)', 'spare_part', 'Ceramic Pro', 'set (4pcs)', 18.00, 32.00, 10, 3],
        ['Bosch Clear Advantage Wiper Blades 24"+18" (Pair)', 'ផ្លិតទឹក Bosch Clear Advantage 24"+18" (មួយគូ)', 'spare_part', 'Bosch', 'pair', 7.00, 14.00, 20, 5],
        ['GS Maintenance-Free Car Battery 12V 70Ah', 'អាគុយស្ងួត GS 12V 70Ah ធានា ១ឆ្នាំ', 'spare_part', 'GS Battery', 'unit', 52.00, 75.00, 8, 2],
        ['Osram Night Breaker H4 Halogen Bulb 12V 60/55W', 'អំពូលភ្លើង Osram Night Breaker H4 12V', 'spare_part', 'Osram', 'box', 3.00, 6.50, 30, 8],
        ['Michelin Heavy-Duty Tubeless Tire Patch Kit', 'ឈុតប៉ះកង់ Michelin Tubeless ស្វិតធន់ខ្ពស់', 'spare_part', 'Michelin', 'kit', 1.50, 4.00, 50, 15],
        ['Motorcycle Heavy-Duty Inner Tube 17"', 'ពោះវៀនកង់ម៉ូតូធន់ខ្ពស់ 17"', 'spare_part', 'Camel', 'unit', 1.80, 4.00, 25, 8],
    ];

    $insProd = $pdo->prepare("
        INSERT INTO products (name_en, name_km, category, brand, unit, cost_price_usd, price_usd, price_khr, stock_qty, min_stock_alert)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($spareParts as $sp) {
        $pKhr = (int)round($sp[6] * DEFAULT_EXCHANGE_RATE);
        $insProd->execute([$sp[0], $sp[1], $sp[2], $sp[3], $sp[4], $sp[5], $sp[6], $pKhr, $sp[7], $sp[8]]);
    }
    migrateLog("Seeded " . count($spareParts) . " spare parts into inventory.");
}

// 8. Seed Sample Vehicle Repair History if empty
$histCount = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_repair_history")->fetchColumn();
if ($histCount === 0) {
    $sampleHistory = [
        [
            'order_id' => 101,
            'vehicle_id' => 1,
            'plate_number' => '2BQ-8899',
            'customer_name' => 'Sokha Meng',
            'mechanic_name' => 'Kosal Sem (Mechanic)',
            'services_performed' => 'Brake Pad Replacement, Brake Inspection',
            'parts_replaced' => 'Bendix Ultra Premium Front Brake Pads (x1 set)',
            'labor_cost_usd' => 12.00,
            'parts_cost_usd' => 24.00,
            'total_cost_usd' => 36.00,
            'mileage' => 74200,
            'repair_notes' => 'Front right brake pad was worn down to 2mm metal squeal. Rotors resurfaced, brake fluid topped up.',
            'repair_date' => date('Y-m-d H:i:s', strtotime('-14 days'))
        ],
        [
            'order_id' => 102,
            'vehicle_id' => 2,
            'plate_number' => '2AB-1234',
            'customer_name' => 'Borey Chea',
            'mechanic_name' => 'Kosal Sem (Mechanic)',
            'services_performed' => 'Tire Patch & Puncture Repair, Tire Balance',
            'parts_replaced' => 'Michelin Heavy-Duty Tubeless Tire Patch Kit (x1)',
            'labor_cost_usd' => 8.00,
            'parts_cost_usd' => 4.00,
            'total_cost_usd' => 12.00,
            'mileage' => 111500,
            'repair_notes' => 'Found 2-inch iron screw in rear left tire outer groove. Patched and sealed from inside, balanced wheel to 0g.',
            'repair_date' => date('Y-m-d H:i:s', strtotime('-28 days'))
        ],
        [
            'order_id' => 103,
            'vehicle_id' => 1,
            'plate_number' => '2BQ-8899',
            'customer_name' => 'Sokha Meng',
            'mechanic_name' => 'Kosal Sem (Mechanic)',
            'services_performed' => 'Wiper Blade Change, Bulb Change',
            'parts_replaced' => 'Bosch Clear Advantage 24"+18" (x1 pair), Osram H4 Bulb (x1)',
            'labor_cost_usd' => 4.50,
            'parts_cost_usd' => 20.50,
            'total_cost_usd' => 25.00,
            'mileage' => 71000,
            'repair_notes' => 'Driver side headlight bulb was burnt out. Wiper rubber split; replaced both front aero wipers.',
            'repair_date' => date('Y-m-d H:i:s', strtotime('-60 days'))
        ]
    ];

    $insHist = $pdo->prepare("
        INSERT INTO vehicle_repair_history (
            order_id, vehicle_id, plate_number, customer_name, mechanic_name,
            services_performed, parts_replaced, labor_cost_usd, parts_cost_usd, total_cost_usd,
            mileage, repair_notes, repair_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($sampleHistory as $sh) {
        $insHist->execute([
            $sh['order_id'], $sh['vehicle_id'], $sh['plate_number'], $sh['customer_name'], $sh['mechanic_name'],
            $sh['services_performed'], $sh['parts_replaced'], $sh['labor_cost_usd'], $sh['parts_cost_usd'],
            $sh['total_cost_usd'], $sh['mileage'], $sh['repair_notes'], $sh['repair_date']
        ]);
    }
    migrateLog("Seeded " . count($sampleHistory) . " vehicle repair history records.");
}

    migrateLog("Migration completed successfully!");
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_repair_maintenance();
}
