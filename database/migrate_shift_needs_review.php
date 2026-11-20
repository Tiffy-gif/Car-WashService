<?php
declare(strict_types=1);

/**
 * Migration: Add needs_review column to cashier_shifts and pos_daily_closes.
 * SAFETY RULE: Only runs on test database ending with '_test'.
 */

$dbName = getenv('CARWASH_DB_NAME') ?: 'carwash_db_test';
echo "Running migration on database: {$dbName}\n";

if (!str_ends_with($dbName, '_test')) {
    die("ABORT: Migration safety check failed. Database name '{$dbName}' does not end with '_test'.\n");
}

$pdo = new PDO("mysql:host=localhost;dbname={$dbName};charset=utf8mb4", 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

// 1. Add needs_review to cashier_shifts if missing
$chk1 = $pdo->query("SHOW COLUMNS FROM cashier_shifts LIKE 'needs_review'")->fetch();
if (!$chk1) {
    $pdo->exec("ALTER TABLE cashier_shifts ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    echo "Added 'needs_review' column to cashier_shifts.\n";
} else {
    echo "'needs_review' column already exists in cashier_shifts.\n";
}

// 2. Add needs_review to pos_daily_closes if missing
$chk2 = $pdo->query("SHOW COLUMNS FROM pos_daily_closes LIKE 'needs_review'")->fetch();
if (!$chk2) {
    $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    echo "Added 'needs_review' column to pos_daily_closes.\n";
} else {
    echo "'needs_review' column already exists in pos_daily_closes.\n";
}

echo "Migration finished successfully on {$dbName}.\n";
