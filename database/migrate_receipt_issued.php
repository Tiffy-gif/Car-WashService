<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Migration: Decouple Electronic Receipt Issuance from Physical Printing
 * Adds orders.receipt_issued_at and performs safe historical backfill for verified printed records.
 */
function migrateReceiptIssued(?PDO $pdo = null, bool $force = false): bool {
    $pdo = $pdo ?? Database::getInstance();
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

    // Check migration table record
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['migrate_receipt_issued']);
    $alreadyLogged = ((int)$checkStmt->fetchColumn() > 0);

    // 1. PHYSICAL SCHEMA INSPECTION (Do not trust migration record alone!)
    $columnPhysicallyExists = false;
    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $columnPhysicallyExists = in_array('receipt_issued_at', $cols, true);
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        $columnPhysicallyExists = in_array('receipt_issued_at', $cols, true);
    }

    if ($alreadyLogged && $columnPhysicallyExists && !$force) {
        migrateLog("Migration 'migrate_receipt_issued' has already been physically executed. Skipping.");
        return true;
    }

    migrateLog("Starting 'Decouple electronic receipt issuance' migration...");

    // 2. Add receipt_issued_at column ONLY if it does not physically exist
    if (!$columnPhysicallyExists) {
        if (!$isSqlite) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_issued_at DATETIME DEFAULT NULL AFTER receipt_printed_at");
            migrateLog("Added 'receipt_issued_at' to orders table (MySQL/MariaDB).");
        } else {
            $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_issued_at DATETIME DEFAULT NULL");
            migrateLog("Added 'receipt_issued_at' to orders table (SQLite).");
        }
    } else {
        migrateLog("Column 'receipt_issued_at' physically exists on orders table. Skipping DDL.");
    }

    // 3. SAFE HISTORICAL BACKFILL (Verified Evidence ONLY)
    // Only backfill where existing source records provide verified evidence that a receipt
    // was rendered and physically printed (receipt_printed_at IS NOT NULL) for a paid order.
    // Ambiguous records (receipt_printed_at IS NULL) are intentionally left NULL (quarantined).
    $backfillStmt = $pdo->exec("
        UPDATE orders 
        SET receipt_issued_at = receipt_printed_at 
        WHERE payment_status = 'paid' 
          AND receipt_printed_at IS NOT NULL 
          AND receipt_issued_at IS NULL
    ");
    migrateLog("Backfilled verified historical receipt issuance for {$backfillStmt} orders.");

    // 4. Record migration execution in schema_migrations idempotently
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_receipt_issued']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_receipt_issued']);
    }

    migrateLog("Migration 'migrate_receipt_issued' completed successfully.");
    return true;
}

/**
 * Engine-Specific Rollback Helper for migrate_receipt_issued
 */
function rollbackReceiptIssued(?PDO $pdo = null, bool $force = false): bool {
    $pdo = $pdo ?? Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');

    migrateLog("Starting rollback of 'migrate_receipt_issued'...");

    // 1. Check physical column existence
    $columnPhysicallyExists = false;
    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        $columnPhysicallyExists = in_array('receipt_issued_at', $cols, true);
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        $columnPhysicallyExists = in_array('receipt_issued_at', $cols, true);
    }

    if ($columnPhysicallyExists) {
        if (!$isSqlite) {
            $pdo->exec("ALTER TABLE orders DROP COLUMN receipt_issued_at");
            migrateLog("Dropped column 'receipt_issued_at' from orders table (MySQL/MariaDB).");
        } else {
            // Check SQLite version for native DROP COLUMN support (>= 3.35.0)
            $sqliteVer = (string)$pdo->query("SELECT sqlite_version()")->fetchColumn();
            if (version_compare($sqliteVer, '3.35.0', '>=')) {
                $pdo->exec("ALTER TABLE orders DROP COLUMN receipt_issued_at");
                migrateLog("Dropped column 'receipt_issued_at' from orders table (SQLite {$sqliteVer}).");
            } else {
                // Neutralization fallback for legacy SQLite < 3.35.0
                $pdo->exec("UPDATE orders SET receipt_issued_at = NULL");
                migrateLog("Neutralized 'receipt_issued_at' (set to NULL) for legacy SQLite {$sqliteVer}.");
            }
        }
    }

    // 2. Remove migration tracking record
    $pdo->prepare("DELETE FROM schema_migrations WHERE migration = ?")->execute(['migrate_receipt_issued']);
    migrateLog("Removed 'migrate_receipt_issued' from schema_migrations.");

    return true;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'migrate_receipt_issued.php') {
    echo "Running migrate_receipt_issued...\n";
    $ok = migrateReceiptIssued(null, true);
    echo $ok ? "Success!\n" : "Failed!\n";
}
