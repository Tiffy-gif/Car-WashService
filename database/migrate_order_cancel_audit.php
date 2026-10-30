<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateOrderCancelAudit(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_order_cancel_audit']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_order_cancel_audit' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting order cancellation audit columns migration...");

    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cancelled_by_user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_by_user_id INT NULL AFTER cancel_reason");
            migrateLog("Added 'cancelled_by_user_id' to orders table.");
        }
        if (!in_array('cancel_approved_by_user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_approved_by_user_id INT NULL AFTER cancelled_by_user_id");
            migrateLog("Added 'cancel_approved_by_user_id' to orders table.");
        }
        if (!in_array('cancelled_at', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL AFTER cancel_approved_by_user_id");
            migrateLog("Added 'cancelled_at' to orders table.");
        }
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        if (!in_array('cancelled_by_user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_by_user_id INTEGER NULL");
            migrateLog("Added 'cancelled_by_user_id' to orders table (SQLite).");
        }
        if (!in_array('cancel_approved_by_user_id', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_approved_by_user_id INTEGER NULL");
            migrateLog("Added 'cancel_approved_by_user_id' to orders table (SQLite).");
        }
        if (!in_array('cancelled_at', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL");
            migrateLog("Added 'cancelled_at' to orders table (SQLite).");
        }
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_order_cancel_audit']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_order_cancel_audit']);
    }

    migrateLog("Order cancellation audit migration completed.");
    return true;
}
