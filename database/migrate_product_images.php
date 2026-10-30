<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_product_images(): void {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();

    migrateLog("Running product and service image columns migration...");

    // 1. Products table
    if ($driver === 'mysql') {
        $pCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('image_path', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER min_stock_alert");
            migrateLog("Added image_path to products.");
        }
        if (!in_array('thumb_path', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN thumb_path VARCHAR(255) NULL AFTER image_path");
            migrateLog("Added thumb_path to products.");
        }
        if (!in_array('image_updated_at', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_updated_at DATETIME NULL AFTER thumb_path");
            migrateLog("Added image_updated_at to products.");
        }
    } else {
        try { $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE products ADD COLUMN thumb_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE products ADD COLUMN image_updated_at DATETIME NULL"); } catch (Exception $e) {}
    }

    // 2. Services table
    if ($driver === 'mysql') {
        $sCols = $pdo->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('image_path', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN image_path VARCHAR(255) NULL AFTER category");
            migrateLog("Added image_path to services.");
        }
        if (!in_array('thumb_path', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN thumb_path VARCHAR(255) NULL AFTER image_path");
            migrateLog("Added thumb_path to services.");
        }
        if (!in_array('image_updated_at', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN image_updated_at DATETIME NULL AFTER thumb_path");
            migrateLog("Added image_updated_at to services.");
        }
    } else {
        try { $pdo->exec("ALTER TABLE services ADD COLUMN image_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE services ADD COLUMN thumb_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE services ADD COLUMN image_updated_at DATETIME NULL"); } catch (Exception $e) {}
    }

    // 3. Inventory table
    if ($driver === 'mysql') {
        $iCols = $pdo->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('image_path', $iCols, true)) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN image_path VARCHAR(255) NULL AFTER unit_cost_usd");
            migrateLog("Added image_path to inventory.");
        }
        if (!in_array('thumb_path', $iCols, true)) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN thumb_path VARCHAR(255) NULL AFTER image_path");
            migrateLog("Added thumb_path to inventory.");
        }
        if (!in_array('image_updated_at', $iCols, true)) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN image_updated_at DATETIME NULL AFTER thumb_path");
            migrateLog("Added image_updated_at to inventory.");
        }
    } else {
        try { $pdo->exec("ALTER TABLE inventory ADD COLUMN image_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE inventory ADD COLUMN thumb_path VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE inventory ADD COLUMN image_updated_at DATETIME NULL"); } catch (Exception $e) {}
    }

    migrateLog("Image columns migration completed successfully.");
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_product_images();
}
