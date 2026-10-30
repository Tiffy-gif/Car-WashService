<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateLoyalty(bool $force = false): bool {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    // 0. Ensure schema_migrations table exists
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Check if migration has already been executed
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['migrate_loyalty']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_loyalty' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Loyalty & Membership migration...");

    // 1. Create loyalty_transactions table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_id INT NOT NULL,
            order_id INT DEFAULT NULL,
            type VARCHAR(20) NOT NULL, -- earn, redeem, adjust, reversal
            points INT NOT NULL,
            balance_after INT NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lt_customer ON loyalty_transactions(customer_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_lt_order ON loyalty_transactions(order_id)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            order_id INT DEFAULT NULL,
            type VARCHAR(20) NOT NULL, -- earn, redeem, adjust, reversal
            points INT NOT NULL,
            balance_after INT NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lt_customer (customer_id),
            INDEX idx_lt_order (order_id),
            INDEX idx_lt_type (type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'loyalty_transactions' ensured.");

    // 2. Add lifetime_points to customers table
    if ($isSqlite) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN lifetime_points INT DEFAULT 0");
        } catch (Throwable $e) {}
    } else {
        $cCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('lifetime_points', $cCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN lifetime_points INT DEFAULT 0 AFTER loyalty_points");
            migrateLog("Added 'lifetime_points' column to customers table.");
        }
    }

    // Initialize lifetime_points = loyalty_points where lifetime_points is 0
    $pdo->exec("UPDATE customers SET lifetime_points = loyalty_points WHERE (lifetime_points IS NULL OR lifetime_points = 0) AND loyalty_points > 0");

    // 3. Add points columns to orders table
    if ($isSqlite) {
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN points_earned INT DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN points_redeemed INT DEFAULT 0"); } catch (Throwable $e) {}
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN points_discount_usd DECIMAL(10,2) DEFAULT 0.00"); } catch (Throwable $e) {}
    } else {
        $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('points_earned', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN points_earned INT DEFAULT 0 AFTER discount_usd");
        }
        if (!in_array('points_redeemed', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN points_redeemed INT DEFAULT 0 AFTER points_earned");
        }
        if (!in_array('points_discount_usd', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN points_discount_usd DECIMAL(10,2) DEFAULT 0.00 AFTER points_redeemed");
            migrateLog("Added points columns (points_earned, points_redeemed, points_discount_usd) to orders table.");
        }
    }

    // 4. Backfill one opening balance row in loyalty_transactions for each customer with points
    $custWithPts = $pdo->query("
        SELECT id, loyalty_points 
        FROM customers 
        WHERE loyalty_points > 0 
          AND id NOT IN (SELECT DISTINCT customer_id FROM loyalty_transactions)
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($custWithPts)) {
        $insBackfill = $pdo->prepare("
            INSERT INTO loyalty_transactions (customer_id, order_id, type, points, balance_after, note, created_by, created_at)
            VALUES (?, NULL, 'adjust', ?, ?, 'Opening balance from system setup', NULL, CURRENT_TIMESTAMP)
        ");
        $backfilledCount = 0;
        foreach ($custWithPts as $cp) {
            $pts = (int)$cp['loyalty_points'];
            $insBackfill->execute([(int)$cp['id'], $pts, $pts]);
            $backfilledCount++;
        }
        migrateLog("Backfilled {$backfilledCount} opening balance transaction(s) for existing customers.");
    }

    // 5. Seed default Loyalty settings
    $defaultSettings = [
        'loyalty_earn_rate' => '1',
        'loyalty_redeem_rate' => '10',
        'loyalty_tier_gold_min' => '200',
        'loyalty_tier_platinum_min' => '500',
        'loyalty_multiplier_standard' => '1.0',
        'loyalty_multiplier_gold' => '1.5',
        'loyalty_multiplier_platinum' => '2.0',
        'loyalty_wax_discount_gold' => '10',
        'loyalty_wax_discount_platinum' => '15'
    ];

    if ($isSqlite) {
        $setStmt = $pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    } else {
        $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
    }
    foreach ($defaultSettings as $k => $v) {
        $setStmt->execute([$k, $v]);
    }

    // 6. Recalculate customer tier membership_level from lifetime_points
    $goldMin = (int)($defaultSettings['loyalty_tier_gold_min']);
    $platMin = (int)($defaultSettings['loyalty_tier_platinum_min']);
    $pdo->prepare("UPDATE customers SET membership_level = 'Platinum' WHERE lifetime_points >= ?")->execute([$platMin]);
    $pdo->prepare("UPDATE customers SET membership_level = 'Gold' WHERE lifetime_points >= ? AND lifetime_points < ?")->execute([$goldMin, $platMin]);
    $pdo->prepare("UPDATE customers SET membership_level = 'Standard' WHERE lifetime_points < ? OR lifetime_points IS NULL")->execute([$goldMin]);

    // 7. Record in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_loyalty']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_loyalty']);
    }

    migrateLog("Loyalty & Membership migration completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'migrate_loyalty.php') {
    migrateLoyalty(isset($argv[1]) && $argv[1] === '--force');
}
