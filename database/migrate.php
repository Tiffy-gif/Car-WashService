<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/migrate_categories.php';
require_once __DIR__ . '/migrate_loyalty.php';
require_once __DIR__ . '/migrate_roles.php';
require_once __DIR__ . '/migrate_pos_device_sales.php';
require_once __DIR__ . '/migrate_bays_queue.php';
require_once __DIR__ . '/migrate_foundation.php';
require_once __DIR__ . '/migrate_cashier_controls.php';
require_once __DIR__ . '/migrate_bank_stock_controls.php';
require_once __DIR__ . '/migrate_crm_membership_reminders.php';
require_once __DIR__ . '/migrate_pos_sessions.php';
require_once __DIR__ . '/migrate_receipt_printed.php';
require_once __DIR__ . '/migrate_pos_devices.php';

/**
 * Run and verify all database schema migrations.
 * Safe to call from CLI or Settings.
 * Never outputs text to web pages.
 */
function runMigrations(bool $force = false): array {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    try {
        // 0. Ensure schema_migrations table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id {$autoInc},
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) {$charsetEngine}");

        // 1. orders & customers base columns
        if (!$isSqlite) {
            $orderCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('cancel_reason', $orderCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER notes");
                migrateLog("Added 'cancel_reason' to orders table.");
            }
            if (!in_array('cancelled_at', $orderCols, true)) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL AFTER paid_at");
                migrateLog("Added 'cancelled_at' to orders table.");
            }

            $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('status', $custCols, true)) {
                $pdo->exec("ALTER TABLE customers ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER membership_level");
                $pdo->exec("UPDATE customers SET status = 'active' WHERE status IS NULL OR status = ''");
                migrateLog("Added 'status' to customers table.");
            }
        }

        // Record base_migrations
        if ($isSqlite) {
            $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['base_migrations']);
        } else {
            $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['base_migrations']);
        }

        // 2. Modular migrations
        migrateCategories($force);
        migrateLoyalty($force);
        migrateRoles($force);
        migratePosDeviceSales($force);
        migrateBaysQueue($force);
        migrateFoundation($force);
        migrateCashierControls($force);
        migrate_bank_stock_controls($force);
        migrateCrmMembershipReminders($force);
        migratePosSessions($force);
        migrateReceiptPrinted($force);
        migrate_pos_devices();

        // Record init_database as well
        if ($isSqlite) {
            $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['init_database']);
        } else {
            $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['init_database']);
        }

        // Clear any previous migration error from settings
        try {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = 'migration_last_error'")->execute();
        } catch (Throwable $t) {}

        migrateLog("All migrations applied successfully.");
        return [
            'success' => true,
            'message' => 'All database schema migrations ran successfully and are up to date.'
        ];
    } catch (Throwable $e) {
        $errDetail = $e->getMessage();
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        @file_put_contents($logDir . '/migration_error.log', "[" . date('Y-m-d H:i:s') . "] Migration error: {$errDetail}" . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND | LOCK_EX);
        migrateLog("Migration failed: {$errDetail}", 'ERROR');

        // Store last error in settings for Owner notification
        try {
            $insErr = $pdo->prepare($isSqlite
                ? "INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('migration_last_error', ?)"
                : "INSERT INTO settings (setting_key, setting_value) VALUES ('migration_last_error', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $insErr->execute([$errDetail]);
        } catch (Throwable $t) {}

        return [
            'success' => false,
            'error'   => $errDetail
        ];
    }
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $res = runMigrations();
    if (!$res['success']) {
        exit(1);
    }
    exit(0);
}
