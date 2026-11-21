<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateDrawerEventsCompatibility(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_drawer_events_compatibility']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_drawer_events_compatibility' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Ensuring drawer_events columns and indices for cash movements...");

    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM drawer_events")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shift_id', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN shift_id INT DEFAULT NULL AFTER pos_device_id");
            migrateLog("Added 'shift_id' to drawer_events.");
        }
        if (!in_array('amount_usd', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN amount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cashier_name");
            migrateLog("Added 'amount_usd' to drawer_events.");
        }
        if (!in_array('amount_khr', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN amount_khr BIGINT NOT NULL DEFAULT 0 AFTER amount_usd");
            migrateLog("Added 'amount_khr' to drawer_events.");
        }

        // Add indices for fast filtering by event_type and pos_device_id
        try {
            $indices = $pdo->query("SHOW INDEX FROM drawer_events")->fetchAll(PDO::FETCH_ASSOC);
            $indexNames = array_column($indices, 'Key_name');
            if (!in_array('idx_drw_type', $indexNames, true)) {
                $pdo->exec("ALTER TABLE drawer_events ADD INDEX idx_drw_type (event_type)");
                migrateLog("Added index 'idx_drw_type' to drawer_events.");
            }
            if (!in_array('idx_drw_device', $indexNames, true)) {
                $pdo->exec("ALTER TABLE drawer_events ADD INDEX idx_drw_device (pos_device_id)");
                migrateLog("Added index 'idx_drw_device' to drawer_events.");
            }
        } catch (Throwable $e) {
            migrateLog("Notice on index creation: " . $e->getMessage());
        }
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(drawer_events)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        if (!in_array('shift_id', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN shift_id INTEGER DEFAULT NULL");
            migrateLog("Added 'shift_id' to drawer_events (SQLite).");
        }
        if (!in_array('amount_usd', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN amount_usd REAL NOT NULL DEFAULT 0.00");
            migrateLog("Added 'amount_usd' to drawer_events (SQLite).");
        }
        if (!in_array('amount_khr', $cols, true)) {
            $pdo->exec("ALTER TABLE drawer_events ADD COLUMN amount_khr INTEGER NOT NULL DEFAULT 0");
            migrateLog("Added 'amount_khr' to drawer_events (SQLite).");
        }
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_drawer_events_compatibility']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = CURRENT_TIMESTAMP")->execute(['migrate_drawer_events_compatibility']);
    }

    migrateLog("Migration 'migrate_drawer_events_compatibility' completed.");
    return true;
}
