<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrate_pos_devices(): void {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $autoInc = ($driver === 'sqlite') ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    migrateLog("Starting POS API & Device Integration Migration...");

    // 1. Create pos_devices Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_devices (
        id {$autoInc},
        device_name VARCHAR(100) NOT NULL,
        device_uid VARCHAR(100) NOT NULL UNIQUE,
        api_key VARCHAR(128) NOT NULL UNIQUE,
        location VARCHAR(100) DEFAULT 'Main Counter',
        status VARCHAR(20) DEFAULT 'active', -- active, blocked
        last_seen_at DATETIME DEFAULT NULL,
        last_ip VARCHAR(50) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    migrateLog("Table pos_devices verified.");

    // 2. Add columns to orders table:
    if ($driver === 'mysql') {
        $cols = $pdo->query("DESCRIBE orders")->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('source', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN source VARCHAR(30) DEFAULT 'web'");
            migrateLog("Added column 'source' to orders.");
        }
        if (!in_array('pos_device_id', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_id INT DEFAULT NULL");
            migrateLog("Added column 'pos_device_id' to orders.");
        }
        if (!in_array('pos_device_name', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_name VARCHAR(100) DEFAULT NULL");
            migrateLog("Added column 'pos_device_name' to orders.");
        }
        if (!in_array('idempotency_key', $cols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(100) DEFAULT NULL");
            $pdo->exec("ALTER TABLE orders ADD UNIQUE KEY uniq_idempotency_key (idempotency_key)");
            migrateLog("Added column 'idempotency_key' to orders.");
        }
    } else {
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN source VARCHAR(30) DEFAULT 'web'"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_id INT DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
    }

    // 3. Seed Default / Sample Approved POS Device (ONLY in development)
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $devStmt = $pdo->prepare("SELECT COUNT(*) FROM pos_devices WHERE device_uid = 'POS-TERMINAL-01'");
        $devStmt->execute();
        if ((int)$devStmt->fetchColumn() === 0) {
            $sampleKey = 'pos_sec_test_key_8899aabb';
            $insDev = $pdo->prepare("
                INSERT INTO pos_devices (device_name, device_uid, api_key, location, status)
                VALUES ('Counter Terminal 1 (Tablet)', 'POS-TERMINAL-01', ?, 'Lane 1 / Counter', 'active')
            ");
            $insDev->execute([$sampleKey]);
            migrateLog("Seeded default POS device: POS-TERMINAL-01 with API Key: {$sampleKey}");
        }
    }

    migrateLog("POS API migration completed successfully!");
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    migrate_pos_devices();
}
