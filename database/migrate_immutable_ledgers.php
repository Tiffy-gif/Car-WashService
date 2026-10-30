<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateImmutableLedgers(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_immutable_ledgers']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_immutable_ledgers' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Creating immutable ledger triggers on stock_movements and audit_logs...");

    $tables = ['stock_movements', 'audit_logs'];

    foreach ($tables as $table) {
        try {
            if ($isSqlite) {
                // SQLite triggers
                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_delete");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_delete
                    BEFORE DELETE ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        SELECT RAISE(ABORT, 'Ledger rows are immutable');
                    END;
                ");

                $tInfo = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
                $cols = array_column($tInfo, 'name');
                $immutableCols = array_values(array_filter($cols, fn($c) => !in_array($c, ['is_synced', 'synced_at'], true)));

                $whenParts = [];
                foreach ($immutableCols as $c) {
                    $whenParts[] = "OLD.`{$c}` IS NOT NEW.`{$c}`";
                }
                $whenClause = implode("\n       OR ", $whenParts);

                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_update");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_update
                    BEFORE UPDATE ON `{$table}`
                    FOR EACH ROW
                    WHEN (
                        {$whenClause}
                    )
                    BEGIN
                        SELECT RAISE(ABORT, 'Ledger rows are immutable');
                    END;
                ");
                migrateLog("  - Immutable triggers created for '{$table}' (SQLite)");
            } else {
                // MySQL / MariaDB triggers
                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_delete");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_delete
                    BEFORE DELETE ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable';
                    END
                ");

                $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                $immutableCols = array_values(array_filter($cols, fn($c) => !in_array($c, ['is_synced', 'synced_at'], true)));

                $conditions = [];
                foreach ($immutableCols as $c) {
                    $conditions[] = "NOT (OLD.`{$c}` <=> NEW.`{$c}`)";
                }
                $ifCond = implode("\n           OR ", $conditions);

                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_update");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_update
                    BEFORE UPDATE ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        IF {$ifCond}
                        THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable';
                        END IF;
                    END
                ");
                migrateLog("  - Immutable triggers created for '{$table}' (MySQL)");
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'command denied') !== false || stripos($msg, 'TRIGGER') !== false || stripos($msg, 'access denied') !== false) {
                migrateLog("WARNING: Database user lacks TRIGGER privilege. Trigger creation on '{$table}' skipped: {$msg}", 'WARNING');
            } else {
                throw $e;
            }
        }
    }

    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_immutable_ledgers']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_immutable_ledgers']);
    }

    migrateLog("Immutable ledger triggers migration completed.");
    return true;
}
