<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCashierSessionsCompatibility(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_cashier_sessions_compatibility']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_cashier_sessions_compatibility' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Ensuring cashier_shifts columns and indices for cashier sessions report...");

    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM cashier_shifts")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('needs_review', $cols, true)) {
            $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
            migrateLog("Added 'needs_review' to cashier_shifts.");
        }
        if (!in_array('review_reason', $cols, true)) {
            $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN review_reason TEXT NULL AFTER notes");
            migrateLog("Added 'review_reason' to cashier_shifts.");
        }

        // Add indices for fast filtering by needs_review and ended_at
        try {
            $indices = $pdo->query("SHOW INDEX FROM cashier_shifts")->fetchAll(PDO::FETCH_ASSOC);
            $indexNames = array_column($indices, 'Key_name');
            if (!in_array('idx_cs_needs_review', $indexNames, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD INDEX idx_cs_needs_review (needs_review)");
                migrateLog("Added index 'idx_cs_needs_review' to cashier_shifts.");
            }
            if (!in_array('idx_cs_ended', $indexNames, true)) {
                $pdo->exec("ALTER TABLE cashier_shifts ADD INDEX idx_cs_ended (ended_at)");
                migrateLog("Added index 'idx_cs_ended' to cashier_shifts.");
            }
        } catch (Throwable $e) {
            migrateLog("Notice on index creation: " . $e->getMessage());
        }
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_cashier_sessions_compatibility']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_cashier_sessions_compatibility']);
    }

    migrateLog("Migration 'migrate_cashier_sessions_compatibility' completed successfully.");
    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running standalone migration for cashier sessions...\n";
    $ok = migrateCashierSessionsCompatibility(true);
    exit($ok ? 0 : 1);
}
