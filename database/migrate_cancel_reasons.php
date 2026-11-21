<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCancelReasons(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_cancel_reasons']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_cancel_reasons' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting cancel reasons column migration...");

    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cancel_reason_code', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason_code VARCHAR(50) NULL DEFAULT NULL AFTER cancel_reason");
            migrateLog("Added 'cancel_reason_code' column to orders table.");
        }
        // Add index if not already present
        try {
            $indexes = $pdo->query("SHOW INDEX FROM orders WHERE Key_name = 'idx_cancel_reason_code'")->fetchAll();
            if (empty($indexes)) {
                $pdo->exec("ALTER TABLE orders ADD INDEX idx_cancel_reason_code (cancel_reason_code)");
                migrateLog("Added index 'idx_cancel_reason_code' to orders.");
            }
        } catch (Throwable $e) {
            migrateLog("Notice on index creation: " . $e->getMessage());
        }
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        if (!in_array('cancel_reason_code', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason_code TEXT NULL DEFAULT NULL");
            migrateLog("Added 'cancel_reason_code' column to orders table (SQLite).");
        }
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_cancel_reasons']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = CURRENT_TIMESTAMP")->execute(['migrate_cancel_reasons']);
    }

    migrateLog("Migration 'migrate_cancel_reasons' completed successfully.");
    return true;
}
