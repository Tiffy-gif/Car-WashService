<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCashierControls(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_cashier_controls']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_cashier_controls' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Cashier & Money Controls migration...");

    // 1. cashier_shifts table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_uid VARCHAR(64) NOT NULL UNIQUE,
            pos_device_id INT DEFAULT NULL,
            pos_device_name VARCHAR(100) DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME DEFAULT NULL,
            opening_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            opening_cash_khr INT NOT NULL DEFAULT 0,
            expected_cash_usd DECIMAL(10,2) DEFAULT NULL,
            expected_cash_khr INT DEFAULT NULL,
            counted_cash_usd DECIMAL(10,2) DEFAULT NULL,
            counted_cash_khr INT DEFAULT NULL,
            difference_usd DECIMAL(10,2) DEFAULT NULL,
            shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            handover_to_cashier_id INT DEFAULT NULL,
            handover_to_cashier_name VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shifts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shift_uid VARCHAR(64) NOT NULL UNIQUE,
            pos_device_id INT DEFAULT NULL,
            pos_device_name VARCHAR(100) DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME DEFAULT NULL,
            opening_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            opening_cash_khr INT NOT NULL DEFAULT 0,
            expected_cash_usd DECIMAL(10,2) DEFAULT NULL,
            expected_cash_khr INT DEFAULT NULL,
            counted_cash_usd DECIMAL(10,2) DEFAULT NULL,
            counted_cash_khr INT DEFAULT NULL,
            difference_usd DECIMAL(10,2) DEFAULT NULL,
            shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            handover_to_cashier_id INT DEFAULT NULL,
            handover_to_cashier_name VARCHAR(100) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_shift_cashier (cashier_id),
            INDEX idx_shift_status (status),
            INDEX idx_shift_started (started_at)
        ) {$charsetEngine}");
    }
    migrateLog("Table 'cashier_shifts' ensured.");

    // 2. spot_audits table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS spot_audits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            audit_uid VARCHAR(64) NOT NULL UNIQUE,
            shift_id INT DEFAULT NULL,
            pos_device_id INT DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            manager_id INT NOT NULL,
            manager_name VARCHAR(100) NOT NULL,
            expected_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            counted_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            counted_cash_khr INT NOT NULL DEFAULT 0,
            difference_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS spot_audits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            audit_uid VARCHAR(64) NOT NULL UNIQUE,
            shift_id INT DEFAULT NULL,
            pos_device_id INT DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            manager_id INT NOT NULL,
            manager_name VARCHAR(100) NOT NULL,
            expected_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            counted_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            counted_cash_khr INT NOT NULL DEFAULT 0,
            difference_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spot_cashier (cashier_id),
            INDEX idx_spot_manager (manager_id),
            INDEX idx_spot_created (created_at)
        ) {$charsetEngine}");
    }
    migrateLog("Table 'spot_audits' ensured.");

    // 3. drawer_events table (no-sale drawer open, overrides)
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS drawer_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_uid VARCHAR(64) NOT NULL UNIQUE,
            event_type VARCHAR(30) NOT NULL,
            pos_device_id INT DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            manager_id INT DEFAULT NULL,
            manager_name VARCHAR(100) DEFAULT NULL,
            reason VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS drawer_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_uid VARCHAR(64) NOT NULL UNIQUE,
            event_type VARCHAR(30) NOT NULL,
            pos_device_id INT DEFAULT NULL,
            cashier_id INT NOT NULL,
            cashier_name VARCHAR(100) NOT NULL,
            manager_id INT DEFAULT NULL,
            manager_name VARCHAR(100) DEFAULT NULL,
            reason VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_drw_cashier (cashier_id),
            INDEX idx_drw_created (created_at)
        ) {$charsetEngine}");
    }
    migrateLog("Table 'drawer_events' ensured.");

    // 4. Update columns on existing tables
    if (!$isSqlite) {
        // Orders: refund tracking
        $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('refund_status', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refund_status VARCHAR(20) DEFAULT NULL AFTER payment_status");
        }
        if (!in_array('refund_amount_usd', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refund_amount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER refund_status");
        }
        if (!in_array('refund_reason', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refund_reason VARCHAR(255) DEFAULT NULL AFTER refund_amount_usd");
        }
        if (!in_array('refunded_by_manager_id', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refunded_by_manager_id INT DEFAULT NULL AFTER refund_reason");
        }
        if (!in_array('refunded_by_manager_name', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refunded_by_manager_name VARCHAR(100) DEFAULT NULL AFTER refunded_by_manager_id");
        }
        if (!in_array('refunded_at', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN refunded_at DATETIME DEFAULT NULL AFTER refunded_by_manager_name");
        }

        // pos_closings: shortage & z-report
        $pcCols = $pdo->query("SHOW COLUMNS FROM pos_closings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shortage_owed_usd', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER difference_usd");
        }
        if (!in_array('z_report_number', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN z_report_number VARCHAR(64) DEFAULT NULL AFTER status");
        }
        if (!in_array('manager_id', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN manager_id INT DEFAULT NULL");
        }
        if (!in_array('manager_name', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN manager_name VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('reopen_reason', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN reopen_reason VARCHAR(255) DEFAULT NULL");
        }

        // pos_daily_closes: shortage & z-report
        $pdcCols = $pdo->query("SHOW COLUMNS FROM pos_daily_closes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shortage_owed_usd', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN shortage_owed_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER difference_usd");
        }
        if (!in_array('z_report_number', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN z_report_number VARCHAR(64) DEFAULT NULL AFTER status");
        }
        if (!in_array('manager_id', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN manager_id INT DEFAULT NULL");
        }
        if (!in_array('manager_name', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN manager_name VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('reopen_reason', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN reopen_reason VARCHAR(255) DEFAULT NULL");
        }
    }

    // Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_cashier_controls']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_cashier_controls']);
    }

    migrateLog("Cashier & Money Controls migration completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrateCashierControls();
}
