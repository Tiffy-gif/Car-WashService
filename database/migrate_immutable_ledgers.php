<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Returns specifications for the four mandatory immutable ledger triggers.
 */
function getRequiredImmutableTriggers(): array {
    return [
        'trg_stock_movements_before_delete' => [
            'table' => 'stock_movements',
            'event' => 'DELETE',
            'timing' => 'BEFORE',
        ],
        'trg_stock_movements_before_update' => [
            'table' => 'stock_movements',
            'event' => 'UPDATE',
            'timing' => 'BEFORE',
        ],
        'trg_audit_logs_before_delete' => [
            'table' => 'audit_logs',
            'event' => 'DELETE',
            'timing' => 'BEFORE',
        ],
        'trg_audit_logs_before_update' => [
            'table' => 'audit_logs',
            'event' => 'UPDATE',
            'timing' => 'BEFORE',
        ],
    ];
}

/**
 * Physically inspects the database catalog to verify that all four mandatory triggers exist
 * and that their actual definitions and enforcement rules are valid.
 * Does NOT rely on schema_migrations or flags.
 */
function verifyImmutableTriggers(PDO $pdo): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isSqlite = ($driver === 'sqlite');
    $required = getRequiredImmutableTriggers();

    $missingOrInvalid = [];
    $errors = [];
    $details = [];

    // 1. Verify target tables exist first
    foreach (['stock_movements', 'audit_logs'] as $tbl) {
        $tblExists = false;
        try {
            if ($isSqlite) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
                $stmt->execute([$tbl]);
                $tblExists = ((int)$stmt->fetchColumn() > 0);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $stmt->execute([$tbl]);
                $tblExists = ((int)$stmt->fetchColumn() > 0);
            }
        } catch (Throwable $t) {
            $tblExists = false;
        }

        if (!$tblExists) {
            return [
                'valid' => false,
                'missing_or_invalid' => array_keys($required),
                'errors' => ["Target table '{$tbl}' does not exist in database."],
                'details' => []
            ];
        }
    }

    // 2. Physical catalog inspection
    if ($isSqlite) {
        $stmt = $pdo->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type = 'trigger'");
        $existing = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['name']] = $row;
        }

        foreach ($required as $trgName => $spec) {
            if (!isset($existing[$trgName])) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' physically missing from sqlite_master.";
                $details[$trgName] = ['exists' => false, 'valid' => false, 'reason' => 'Missing from sqlite_master'];
                continue;
            }

            $sql = (string)($existing[$trgName]['sql'] ?? '');
            $tbl = (string)($existing[$trgName]['tbl_name'] ?? '');

            if (strcasecmp($tbl, $spec['table']) !== 0) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' attaches to '{$tbl}', expected '{$spec['table']}'.";
                $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Target table mismatch'];
                continue;
            }

            // Check enforcement rule
            if (!preg_match('/RAISE\s*\(\s*ABORT/i', $sql)) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' definition lacks RAISE(ABORT) enforcement rule.";
                $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Missing RAISE(ABORT) rule'];
                continue;
            }

            if ($spec['event'] === 'UPDATE') {
                if (!preg_match('/WHEN\s*\(/i', $sql) || !preg_match('/IS NOT/i', $sql)) {
                    $missingOrInvalid[] = $trgName;
                    $errors[] = "Trigger '{$trgName}' lacks valid column comparison WHEN condition.";
                    $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Missing WHEN column comparison'];
                    continue;
                }
                // Synchronization metadata exemption check
                if (preg_match('/OLD\.`?is_synced`?\s+IS NOT/i', $sql) || preg_match('/OLD\.`?synced_at`?\s+IS NOT/i', $sql)) {
                    $missingOrInvalid[] = $trgName;
                    $errors[] = "Trigger '{$trgName}' improperly blocks synchronization metadata (is_synced/synced_at).";
                    $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Improperly blocks synchronization metadata'];
                    continue;
                }
            }

            $details[$trgName] = ['exists' => true, 'valid' => true, 'reason' => 'Catalog definition verified'];
        }
    } else {
        // MySQL / MariaDB
        $stmt = $pdo->query("
            SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
            FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()
        ");
        $existing = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[$row['TRIGGER_NAME']] = $row;
        }

        foreach ($required as $trgName => $spec) {
            if (!isset($existing[$trgName])) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' physically missing from information_schema.TRIGGERS.";
                $details[$trgName] = ['exists' => false, 'valid' => false, 'reason' => 'Missing from information_schema.TRIGGERS'];
                continue;
            }

            $row = $existing[$trgName];
            if (strcasecmp($row['EVENT_OBJECT_TABLE'], $spec['table']) !== 0 ||
                strcasecmp($row['EVENT_MANIPULATION'], $spec['event']) !== 0 ||
                strcasecmp($row['ACTION_TIMING'], $spec['timing']) !== 0) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' event/timing/table mismatch ({$row['ACTION_TIMING']} {$row['EVENT_MANIPULATION']} ON {$row['EVENT_OBJECT_TABLE']}).";
                $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Event/timing/table mismatch'];
                continue;
            }

            $action = (string)($row['ACTION_STATEMENT'] ?? '');
            if (!preg_match("/SIGNAL\s+SQLSTATE\s+'45000'/i", $action)) {
                $missingOrInvalid[] = $trgName;
                $errors[] = "Trigger '{$trgName}' lacks SIGNAL SQLSTATE '45000' enforcement rule.";
                $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Missing SIGNAL SQLSTATE 45000'];
                continue;
            }

            if ($spec['event'] === 'UPDATE') {
                if (!preg_match('/(OLD\.|<=>|<>)/i', $action)) {
                    $missingOrInvalid[] = $trgName;
                    $errors[] = "Trigger '{$trgName}' lacks column comparison logic.";
                    $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Missing column comparison logic'];
                    continue;
                }
                // Synchronization metadata exemption check
                if (preg_match('/OLD\.`?is_synced`?\s*<=>/i', $action) || preg_match('/OLD\.`?synced_at`?\s*<=>/i', $action)) {
                    $missingOrInvalid[] = $trgName;
                    $errors[] = "Trigger '{$trgName}' improperly checks synchronization metadata (is_synced/synced_at).";
                    $details[$trgName] = ['exists' => true, 'valid' => false, 'reason' => 'Improperly checks synchronization metadata'];
                    continue;
                }
            }

            $details[$trgName] = ['exists' => true, 'valid' => true, 'reason' => 'Catalog definition verified'];
        }
    }

    $valid = empty($missingOrInvalid);
    return [
        'valid' => $valid,
        'missing_or_invalid' => $missingOrInvalid,
        'errors' => $errors,
        'details' => $details
    ];
}

/**
 * Creates or updates immutable triggers for stock_movements and audit_logs.
 * Dynamically inspects the actual table schema to protect all columns
 * while explicitly preserving the approved synchronization metadata update exceptions.
 * Returns true if creation succeeded without errors; false otherwise.
 */
function createImmutableTriggers(PDO $pdo): bool {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isSqlite = ($driver === 'sqlite');
    $tables = ['stock_movements', 'audit_logs'];

    foreach ($tables as $table) {
        try {
            if ($isSqlite) {
                $tCheck = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
                $tCheck->execute([$table]);
                if ((int)$tCheck->fetchColumn() === 0) {
                    migrateLog("Target table '{$table}' not found in SQLite schema; trigger creation aborted.", 'ERROR');
                    return false;
                }

                // Inspect actual table schema
                $tInfo = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
                $cols = array_column($tInfo, 'name');
                $immutableCols = array_values(array_filter($cols, fn($c) => !in_array($c, ['is_synced', 'synced_at'], true)));

                if (empty($immutableCols)) {
                    migrateLog("Table '{$table}' has no columns for immutable trigger protection.", 'ERROR');
                    return false;
                }

                // DELETE trigger (idempotent)
                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_delete");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_delete
                    BEFORE DELETE ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        SELECT RAISE(ABORT, 'Ledger rows are immutable. DELETE is strictly prohibited.');
                    END;
                ");

                // UPDATE trigger (idempotent, exempting sync metadata)
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
                        SELECT RAISE(ABORT, 'Ledger rows are immutable. UPDATE of movement data is strictly prohibited.');
                    END;
                ");

                migrateLog("  - Immutable triggers installed for '{$table}' (SQLite)");
            } else {
                // MySQL / MariaDB
                $tCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
                $tCheck->execute([$table]);
                if ((int)$tCheck->fetchColumn() === 0) {
                    migrateLog("Target table '{$table}' not found in MySQL schema; trigger creation aborted.", 'ERROR');
                    return false;
                }

                // Inspect actual table schema
                $cols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                $immutableCols = array_values(array_filter($cols, fn($c) => !in_array($c, ['is_synced', 'synced_at'], true)));

                if (empty($immutableCols)) {
                    migrateLog("Table '{$table}' has no columns for immutable trigger protection.", 'ERROR');
                    return false;
                }

                // DELETE trigger (idempotent)
                $pdo->exec("DROP TRIGGER IF EXISTS trg_{$table}_before_delete");
                $pdo->exec("
                    CREATE TRIGGER trg_{$table}_before_delete
                    BEFORE DELETE ON `{$table}`
                    FOR EACH ROW
                    BEGIN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable. DELETE is strictly prohibited.';
                    END
                ");

                // UPDATE trigger (idempotent, exempting sync metadata)
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
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger rows are immutable. UPDATE of movement data is strictly prohibited.';
                        END IF;
                    END
                ");

                migrateLog("  - Immutable triggers installed for '{$table}' (MySQL)");
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            migrateLog("CRITICAL: Failed to create trigger on '{$table}': {$msg}", 'ERROR');
            return false;
        }
    }

    return true;
}

/**
 * Migration orchestrator for immutable ledgers.
 * Guarantees physical catalog verification. A schema_migrations row alone CANNOT bypass verification.
 * Never records migration success when trigger creation or verification fails.
 */
function migrateImmutableLedgers(bool $force = false, ?PDO $pdo = null): bool {
    $pdo = $pdo ?? Database::getInstance();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // 0. Ensure schema_migrations table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id {$autoInc},
        migration VARCHAR(255) NOT NULL UNIQUE,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 1. Perform physical catalog verification
    $verification = verifyImmutableTriggers($pdo);

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['migrate_immutable_ledgers']);
    $hasSchemaRecord = ((int)$checkStmt->fetchColumn() > 0);

    // Bypass check: ONLY skip if NOT forced, schema_migrations has the record, AND physical triggers are verified
    if (!$force && $hasSchemaRecord && $verification['valid']) {
        migrateLog("Immutable ledger triggers verified and active in catalog. Skipping.");
        return true;
    }

    if (!$verification['valid'] && $hasSchemaRecord) {
        migrateLog("WARNING: 'migrate_immutable_ledgers' present in schema_migrations but physical triggers are missing/invalid. Re-creating...", 'WARNING');
    }

    migrateLog("Creating immutable ledger triggers on stock_movements and audit_logs...");

    // 2. Attempt trigger creation
    $creationSuccess = createImmutableTriggers($pdo);

    if (!$creationSuccess) {
        // Trigger creation failed (e.g. missing TRIGGER privileges)
        // NEVER record migration success in schema_migrations!
        try {
            $pdo->prepare("DELETE FROM schema_migrations WHERE migration = ?")->execute(['migrate_immutable_ledgers']);
        } catch (Throwable $e) {}

        // Persist unprotected status in settings if available
        try {
            $setStmt = $isSqlite
                ? $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '0')")
                : $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $setStmt->execute();
        } catch (Throwable $e) {}

        migrateLog("CRITICAL: Immutable ledger triggers creation failed. Database is in an UNPROTECTED state.", 'ERROR');
        return false;
    }

    // 3. Post-creation physical catalog verification
    $postVerification = verifyImmutableTriggers($pdo);
    if (!$postVerification['valid']) {
        try {
            $pdo->prepare("DELETE FROM schema_migrations WHERE migration = ?")->execute(['migrate_immutable_ledgers']);
        } catch (Throwable $e) {}

        try {
            $setStmt = $isSqlite
                ? $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '0')")
                : $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $setStmt->execute();
        } catch (Throwable $e) {}

        $errStr = implode('; ', $postVerification['errors']);
        migrateLog("CRITICAL: Triggers created but catalog verification failed: {$errStr}", 'ERROR');
        return false;
    }

    // 4. Record migration success ONLY after verified physical catalog check
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_immutable_ledgers']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_immutable_ledgers']);
    }

    // Update settings table with verified state
    try {
        $setStmt = $isSqlite
            ? $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '1')")
            : $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('immutable_triggers_verified', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
        $setStmt->execute();
        $pdo->prepare("DELETE FROM settings WHERE setting_key = 'immutable_triggers_error'")->execute();
    } catch (Throwable $e) {}

    migrateLog("Immutable ledger triggers verified and catalog registered successfully.");
    return true;
}

/**
 * Asserts that the immutable ledger triggers are verified and physically active in the catalog.
 * Any protected application ledger operation must call this check.
 * If verification fails, throws a RuntimeException to refuse normal operations that could compromise ledger integrity.
 */
function assertImmutableLedgerIntegrity(?PDO $pdo = null): void {
    $pdo = $pdo ?? Database::getInstance();
    $res = verifyImmutableTriggers($pdo);
    if (!$res['valid']) {
        $err = !empty($res['errors']) ? implode('; ', $res['errors']) : 'Missing or invalid immutable ledger triggers';
        throw new RuntimeException("Ledger integrity violation: Database is in an UNPROTECTED state ({$err}). Protected operations refused.");
    }
}

