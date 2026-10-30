<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_bank_stock_controls(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_bank_stock_controls']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_bank_stock_controls' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Bank Transfer Approval & Stock Control Migration...");

    // 1. Add columns to orders table
    $orderCols = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('bank_slip_image', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN bank_slip_image VARCHAR(255) DEFAULT NULL AFTER payment_ref");
        migrateLog("  - Added bank_slip_image to orders");
    }

    if (!in_array('bank_slip_uploaded_at', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN bank_slip_uploaded_at DATETIME DEFAULT NULL AFTER bank_slip_image");
        migrateLog("  - Added bank_slip_uploaded_at to orders");
    }

    if (!in_array('payment_verified_by_id', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_verified_by_id INT DEFAULT NULL AFTER bank_slip_uploaded_at");
        migrateLog("  - Added payment_verified_by_id to orders");
    }

    if (!in_array('payment_verified_by_name', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_verified_by_name VARCHAR(100) DEFAULT NULL AFTER payment_verified_by_id");
        migrateLog("  - Added payment_verified_by_name to orders");
    }

    if (!in_array('payment_verified_at', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_verified_at DATETIME DEFAULT NULL AFTER payment_verified_by_name");
        migrateLog("  - Added payment_verified_at to orders");
    }

    if (!in_array('payment_rejection_reason', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_rejection_reason VARCHAR(255) DEFAULT NULL AFTER payment_verified_at");
        migrateLog("  - Added payment_rejection_reason to orders");
    }

    if (!in_array('paper_receipt_fallback', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN paper_receipt_fallback TINYINT(1) DEFAULT 0 AFTER payment_rejection_reason");
        migrateLog("  - Added paper_receipt_fallback to orders");
    }

    if (!in_array('paper_receipt_authorized_by', $orderCols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN paper_receipt_authorized_by VARCHAR(100) DEFAULT NULL AFTER paper_receipt_fallback");
        migrateLog("  - Added paper_receipt_authorized_by to orders");
    }

    // 2. Add columns to products table
    $productCols = $pdo->query("DESCRIBE products")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('reserved_qty', $productCols, true)) {
        $pdo->exec("ALTER TABLE products ADD COLUMN reserved_qty INT(11) DEFAULT 0 AFTER stock_qty");
        migrateLog("  - Added reserved_qty to products");
    }

    // 3. Add columns to stock_movements table
    $stockCols = $pdo->query("DESCRIBE stock_movements")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('product_name', $stockCols, true)) {
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN product_name VARCHAR(150) DEFAULT NULL AFTER product_id");
        migrateLog("  - Added product_name to stock_movements");
    }

    if (!in_array('performer_name', $stockCols, true)) {
        $pdo->exec("ALTER TABLE stock_movements ADD COLUMN performer_name VARCHAR(100) DEFAULT NULL AFTER performed_by");
        migrateLog("  - Added performer_name to stock_movements");
    }

    // Backfill product_name in stock_movements if empty
    $pdo->exec("
        UPDATE stock_movements sm
        JOIN products p ON sm.product_id = p.id
        SET sm.product_name = p.name_en
        WHERE sm.product_name IS NULL OR sm.product_name = ''
    ");

    // 4. Ensure uploads/bank_slips directory exists
    $slipDir = dirname(__DIR__) . '/public/uploads/bank_slips';
    if (!is_dir($slipDir)) {
        @mkdir($slipDir, 0755, true);
    }

    // Add .htaccess to disallow executing php scripts in uploads/bank_slips
    $htaccessFile = $slipDir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        @file_put_contents($htaccessFile, "<FilesMatch \"\\.(php|phtml|php5|php7|phar)$\">\n    Deny from all\n</FilesMatch>\n");
    }

    // 5. Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_bank_stock_controls']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_bank_stock_controls']);
    }

    migrateLog("Bank Transfer Approval & Stock Control Migration completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_bank_stock_controls();
}
