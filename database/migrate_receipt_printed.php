<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateReceiptPrinted(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_receipt_printed']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_receipt_printed' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting 'No receipt, no wash' migration...");

    // 1. Add receipt_printed_at column to orders table
    if (!$isSqlite) {
        $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('receipt_printed_at', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL AFTER paid_at");
            migrateLog("Added 'receipt_printed_at' to orders table.");
        }
    } else {
        $tInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($tInfo, 'name');
        if (!in_array('receipt_printed_at', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN receipt_printed_at DATETIME DEFAULT NULL");
            migrateLog("Added 'receipt_printed_at' to orders table (SQLite).");
        }
    }

    // 2. Ensure setting require_receipt_before_wash default '1'
    $sStmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'require_receipt_before_wash'");
    $sStmt->execute();
    if ((int)$sStmt->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('require_receipt_before_wash', '1')")->execute();
        migrateLog("Seeded 'require_receipt_before_wash' = '1'.");
    }

    // 3. Record migration execution
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_receipt_printed']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_receipt_printed']);
    }

    migrateLog("Migration 'migrate_receipt_printed' completed successfully.");
    return true;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'migrate_receipt_printed.php') {
    echo "Running migrate_receipt_printed...\n";
    $ok = migrateReceiptPrinted(true);
    echo $ok ? "Success!\n" : "Failed!\n";
}
