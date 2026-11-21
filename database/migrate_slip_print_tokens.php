<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Migration: Create slip_print_tokens table for secure one-time slip printing.
 * Tokens are stored scrambled as SHA-256 hashes to prevent plaintext credential leaks.
 */
function migrateSlipPrintTokens(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_slip_print_tokens']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_slip_print_tokens' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Creating slip_print_tokens table for secure one-time print tickets...");

    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS slip_print_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL UNIQUE,
            slip_type TEXT NOT NULL,
            slip_ref TEXT NOT NULL,
            user_id INTEGER NOT NULL,
            expires_at TEXT NOT NULL,
            used_at TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_spt_hash ON slip_print_tokens(token_hash)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_spt_lookup ON slip_print_tokens(token_hash, expires_at, used_at)");
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_slip_print_tokens']);
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS slip_print_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            slip_type VARCHAR(20) NOT NULL,
            slip_ref VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_spt_hash (token_hash),
            INDEX idx_spt_lookup (token_hash, expires_at, used_at)
        ) {$charsetEngine}");

        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")
            ->execute(['migrate_slip_print_tokens']);
    }

    migrateLog("Migration 'migrate_slip_print_tokens' completed successfully.");
    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running standalone migration for slip print tokens...\n";
    $ok = migrateSlipPrintTokens(true);
    exit($ok ? 0 : 1);
}
