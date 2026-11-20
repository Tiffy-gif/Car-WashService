<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Migration: Add status column to oil_change_logs table.
 * Supports marking cancelled oil changes and rolling back vehicle mileage/dates.
 */
function migrate_oil_change_status(?PDO $pdo = null): bool {
    if (!$pdo) {
        $pdo = Database::getInstance();
    }
    $driver = Database::getDriver();

    migrateLog("Starting Oil Change Status Column Migration...");

    if ($driver === 'mysql') {
        // 1. Check if status column already exists
        $colCheck = $pdo->query("SHOW COLUMNS FROM oil_change_logs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        if (!$colCheck) {
            $pdo->exec("
                ALTER TABLE oil_change_logs 
                ADD COLUMN status ENUM('completed', 'cancelled') NOT NULL DEFAULT 'completed' AFTER notes
            ");
            migrateLog("Added column 'status' ENUM('completed', 'cancelled') DEFAULT 'completed' to oil_change_logs.");
        } else {
            migrateLog("Column 'status' already exists in oil_change_logs.");
        }

        // 2. Add composite index on (vehicle_id, status)
        $idxCheck = $pdo->query("SHOW INDEX FROM oil_change_logs WHERE Key_name = 'idx_oil_logs_vehicle_status'")->fetch(PDO::FETCH_ASSOC);
        if (!$idxCheck) {
            $pdo->exec("ALTER TABLE oil_change_logs ADD INDEX idx_oil_logs_vehicle_status (vehicle_id, status)");
            migrateLog("Added index 'idx_oil_logs_vehicle_status' on oil_change_logs(vehicle_id, status).");
        } else {
            migrateLog("Index 'idx_oil_logs_vehicle_status' already exists.");
        }
    } else {
        // SQLite fallback
        $cols = $pdo->query("PRAGMA table_info(oil_change_logs)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');
        if (!in_array('status', $colNames, true)) {
            $pdo->exec("ALTER TABLE oil_change_logs ADD COLUMN status TEXT DEFAULT 'completed'");
            migrateLog("Added column 'status' to oil_change_logs (SQLite).");
        }
    }

    migrateLog("Oil Change Status Column Migration completed successfully.");
    return true;
}

// Auto-run if executed directly from CLI
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // When run directly, check if a custom test DB was requested
    $useTest = in_array('--test', $argv, true) || in_array('--test-db', $argv, true);
    if ($useTest) {
        $testPdo = new PDO('mysql:host=127.0.0.1;dbname=carwash_db_test;charset=utf8mb4', 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        migrate_oil_change_status($testPdo);
    } else {
        migrate_oil_change_status();
    }
}
