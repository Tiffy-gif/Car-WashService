<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migratePosSessions(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_pos_sessions']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_pos_sessions' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running POS Sessions migration...");

    // 1. pos_sessions table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            device_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            ip VARCHAR(50) DEFAULT NULL
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sessions_user ON pos_sessions(user_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sessions_device ON pos_sessions(device_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pos_sessions_expires ON pos_sessions(expires_at)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pos_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            user_id INT NOT NULL,
            device_id INT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            ip VARCHAR(50) DEFAULT NULL,
            INDEX idx_pos_sessions_user (user_id),
            INDEX idx_pos_sessions_device (device_id),
            INDEX idx_pos_sessions_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_pos_sessions']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_pos_sessions']);
    }

    migrateLog("Table pos_sessions verified successfully.");
    return true;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migratePosSessions();
}
