<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateSmartQueue(bool $force = false): bool {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // 0. Ensure schema_migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id {$autoInc},
        migration VARCHAR(255) NOT NULL UNIQUE,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['migrate_smart_queue']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_smart_queue' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Smart Queue Engine Migration...");

    // 1. Add base_duration_minutes to services table
    if ($isSqlite) {
        $cols = $pdo->query("PRAGMA table_info(services)")->fetchAll(PDO::FETCH_COLUMN, 1);
    } else {
        $cols = $pdo->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!in_array('base_duration_minutes', $cols, true)) {
        $pdo->exec("ALTER TABLE services ADD COLUMN base_duration_minutes INT NOT NULL DEFAULT 15 AFTER category_id");
        migrateLog("Added 'base_duration_minutes' column to 'services' table.");

        // Seed realistic base durations based on service names
        $pdo->exec("UPDATE services SET base_duration_minutes = 8 WHERE name_en LIKE '%Basic%' OR name_en LIKE '%Express%' OR name_en LIKE '%Standard Wash%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 12 WHERE name_en LIKE '%Foam Wash%' OR name_en LIKE '%Exterior%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 18 WHERE name_en LIKE '%Interior%' OR name_en LIKE '%Vacuum%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 20 WHERE name_en LIKE '%Engine%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 25 WHERE name_en LIKE '%Oil%' OR name_en LIKE '%Synthetic%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 45 WHERE name_en LIKE '%Polish%' OR name_en LIKE '%Wax%'");
        $pdo->exec("UPDATE services SET base_duration_minutes = 60 WHERE name_en LIKE '%Detailing%' OR name_en LIKE '%Ceramic%'");
        migrateLog("Seeded default base durations for services.");
    }

    // 2. Add columns to orders table
    if ($isSqlite) {
        $ordCols = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_COLUMN, 1);
    } else {
        $ordCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!in_array('estimated_duration_minutes', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN estimated_duration_minutes INT DEFAULT NULL AFTER vehicle_type");
    }
    if (!in_array('estimated_finish_time', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN estimated_finish_time DATETIME DEFAULT NULL AFTER estimated_duration_minutes");
    }
    if (!in_array('actual_duration_minutes', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN actual_duration_minutes INT DEFAULT NULL AFTER estimated_finish_time");
    }
    if (!in_array('delay_reason', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delay_reason VARCHAR(255) DEFAULT NULL AFTER actual_duration_minutes");
    }
    if (!in_array('delay_logged_at', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delay_logged_at DATETIME DEFAULT NULL AFTER delay_reason");
    }
    if (!in_array('delay_logged_by', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delay_logged_by INT DEFAULT NULL AFTER delay_logged_at");
    }
    if (!in_array('suggested_bay_id', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN suggested_bay_id INT DEFAULT NULL AFTER bay_id");
    }
    if (!in_array('bay_override_reason', $ordCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN bay_override_reason VARCHAR(255) DEFAULT NULL AFTER suggested_bay_id");
    }
    migrateLog("Orders table columns for smart queue ensured.");

    // 3. Add columns to order_tasks table
    if ($isSqlite) {
        $taskCols = $pdo->query("PRAGMA table_info(order_tasks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    } else {
        $taskCols = $pdo->query("SHOW COLUMNS FROM order_tasks")->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!in_array('base_duration_minutes', $taskCols, true)) {
        $pdo->exec("ALTER TABLE order_tasks ADD COLUMN base_duration_minutes INT NOT NULL DEFAULT 15 AFTER sequence");
    }
    if (!in_array('estimated_duration_minutes', $taskCols, true)) {
        $pdo->exec("ALTER TABLE order_tasks ADD COLUMN estimated_duration_minutes INT NOT NULL DEFAULT 15 AFTER base_duration_minutes");
    }
    if (!in_array('actual_duration_minutes', $taskCols, true)) {
        $pdo->exec("ALTER TABLE order_tasks ADD COLUMN actual_duration_minutes INT DEFAULT NULL AFTER estimated_duration_minutes");
    }
    if (!in_array('delay_reason', $taskCols, true)) {
        $pdo->exec("ALTER TABLE order_tasks ADD COLUMN delay_reason VARCHAR(255) DEFAULT NULL AFTER actual_duration_minutes");
    }
    migrateLog("Order_tasks table columns for smart queue ensured.");

    // 4. Create service_duration_reviews table (Learning engine)
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_duration_reviews (
        id {$autoInc},
        service_id INT DEFAULT NULL,
        service_name VARCHAR(150) NOT NULL,
        vehicle_type VARCHAR(50) NOT NULL,
        order_id INT NOT NULL,
        task_id INT DEFAULT NULL,
        estimated_minutes INT NOT NULL,
        actual_minutes INT NOT NULL,
        over_percentage DECIMAL(6,2) NOT NULL,
        washer_answered_longer TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending', 'adjusted', 'dismissed'
        reviewed_by INT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        resolution_notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'service_duration_reviews' ensured.");

    // 5. Seed default settings for Smart Queue Engine
    $defaultSettings = [
        'multiplier_sedan' => '1.0',
        'multiplier_small_car' => '1.0',
        'multiplier_suv_pickup' => '1.25',
        'multiplier_van_truck' => '1.4',
        'multiplier_motorbike' => '0.6',
        'estimate_buffer_percent' => '10',
        'delay_reasons_list' => json_encode([
            'Heavy soil - extra rinse',
            'Customer requested extra interior vacuum',
            'Stuck oil filter bolt',
            'Technical complication',
            'Extra drying & tire dressing'
        ], JSON_UNESCAPED_UNICODE)
    ];

    $checkSettingStmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $insertSettingStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");

    foreach ($defaultSettings as $sKey => $sVal) {
        $checkSettingStmt->execute([$sKey]);
        if ((int)$checkSettingStmt->fetchColumn() === 0) {
            $insertSettingStmt->execute([$sKey, $sVal]);
        }
    }
    migrateLog("Default Smart Queue settings seeded.");

    // 6. Record completed migration
    $recStmt = $pdo->prepare("INSERT INTO schema_migrations (migration, executed_at) VALUES (?, NOW())");
    $recStmt->execute(['migrate_smart_queue']);
    migrateLog("Migration 'migrate_smart_queue' recorded in schema_migrations.");

    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Smart Queue Engine Migration via CLI...\n";
    $success = migrateSmartQueue(true);
    echo $success ? "Migration completed successfully!\n" : "Migration encountered an issue.\n";
}
