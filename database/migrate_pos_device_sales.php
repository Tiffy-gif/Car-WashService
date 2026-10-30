<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migratePosDeviceSales(bool $force = false): bool {
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

    // Check if migration has already been executed
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['pos_device_sales_and_closings']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'pos_device_sales_and_closings' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running POS Device Sales & Closings migration...");

    // 1. Ensure indexes on orders: (pos_device_id, paid_at) and (pos_device_id, created_at)
    if (!$isSqlite) {
        $indexes = $pdo->query("SHOW INDEX FROM orders")->fetchAll(PDO::FETCH_ASSOC);
        $existingIndexNames = array_unique(array_column($indexes, 'Key_name'));

        if (!in_array('idx_orders_device_paid', $existingIndexNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_orders_device_paid (pos_device_id, paid_at)");
            migrateLog("Added index 'idx_orders_device_paid' on orders(pos_device_id, paid_at).");
        }

        if (!in_array('idx_orders_device_created', $existingIndexNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_orders_device_created (pos_device_id, created_at)");
            migrateLog("Added index 'idx_orders_device_created' on orders(pos_device_id, created_at).");
        }
    } else {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_device_paid ON orders(pos_device_id, paid_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_device_created ON orders(pos_device_id, created_at)");
        migrateLog("Ensured SQLite indexes on orders.");
    }

    // 2. Ensure pos_closings table exists
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_closings (
        id {$autoInc},
        pos_device_id INT NULL,
        device_name VARCHAR(100) NULL,
        cashier_id INT NOT NULL,
        cashier_name VARCHAR(100) NULL,
        closed_at DATETIME NOT NULL,
        close_date DATE NOT NULL,
        opening_cash_usd DECIMAL(10,2) DEFAULT 0.00,
        expected_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_cash_khr INT NOT NULL DEFAULT 0,
        difference_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        cash_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        aba_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        khqr_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        expenses_usd DECIMAL(10,2) DEFAULT 0.00,
        discount_total_usd DECIMAL(10,2) DEFAULT 0.00,
        paid_orders_count INT DEFAULT 0,
        unpaid_orders_count INT DEFAULT 0,
        cancelled_orders_count INT DEFAULT 0,
        notes TEXT NULL,
        status VARCHAR(20) DEFAULT 'closed',
        reopened_by_user_id INT NULL,
        reopened_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    if (!$isSqlite) {
        $closingIndexes = $pdo->query("SHOW INDEX FROM pos_closings")->fetchAll(PDO::FETCH_ASSOC);
        $closingIdxNames = array_unique(array_column($closingIndexes, 'Key_name'));
        if (!in_array('idx_closings_device_date', $closingIdxNames, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD INDEX idx_closings_device_date (pos_device_id, close_date)");
        }
        if (!in_array('idx_closings_date', $closingIdxNames, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD INDEX idx_closings_date (close_date)");
        }
    }
    migrateLog("Ensured 'pos_closings' table exists.");

    // 3. Check for any duplicated/shared API keys in pos_devices and re-assign unique keys
    $devs = $pdo->query("SELECT id, device_name, api_key FROM pos_devices ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $seenKeys = [];
    foreach ($devs as $d) {
        $key = $d['api_key'];
        if (isset($seenKeys[$key])) {
            $newKey = 'pos_sec_' . bin2hex(random_bytes(12));
            $pdo->prepare("UPDATE pos_devices SET api_key = ? WHERE id = ?")->execute([$newKey, $d['id']]);
            migrateLog("Replaced duplicate API key on device #{$d['id']} ({$d['device_name']}) with unique key {$newKey}.");
        } else {
            $seenKeys[$key] = $d['id'];
        }
    }

    // 4. Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['pos_device_sales_and_closings']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['pos_device_sales_and_closings']);
    }

    migrateLog("pos_device_sales_and_closings migration completed successfully.");
    return true;
}

if (php_sapi_name() === 'cli') {
    migratePosDeviceSales();
}
