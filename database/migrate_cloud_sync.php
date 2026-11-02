<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCloudSync(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_cloud_sync']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_cloud_sync' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Cloud Sync Migration...");

    // 1. Table sync_queue
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_queue (
        id {$autoInc},
        record_type VARCHAR(50) NOT NULL,
        record_uid VARCHAR(100) NOT NULL,
        payload_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        attempts INT DEFAULT 0,
        last_attempt_at DATETIME DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        synced_at DATETIME DEFAULT NULL
    ) {$charsetEngine}");
    migrateLog("Table 'sync_queue' verified.");

    if (!$isSqlite) {
        try { $pdo->exec("ALTER TABLE sync_queue ADD INDEX idx_sq_status (status)"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE sync_queue ADD INDEX idx_sq_record (record_type, record_uid)"); } catch (Exception $e) {}
    }

    // 2. Table sync_conflicts
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_conflicts (
        id {$autoInc},
        conflict_uid VARCHAR(100) NOT NULL UNIQUE,
        record_type VARCHAR(50) NOT NULL,
        record_uid VARCHAR(100) NOT NULL,
        local_data JSON NOT NULL,
        cloud_data JSON NOT NULL,
        local_updated_at DATETIME NOT NULL,
        cloud_updated_at DATETIME NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        resolution_notes TEXT DEFAULT NULL,
        resolved_by INT DEFAULT NULL,
        resolved_by_name VARCHAR(100) DEFAULT NULL,
        resolved_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'sync_conflicts' verified.");

    if (!$isSqlite) {
        try { $pdo->exec("ALTER TABLE sync_conflicts ADD INDEX idx_sc_status (status)"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE sync_conflicts ADD INDEX idx_sc_record (record_type, record_uid)"); } catch (Exception $e) {}
    }

    // 3. Table sync_logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_logs (
        id {$autoInc},
        sync_type VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL,
        records_sent INT DEFAULT 0,
        records_received INT DEFAULT 0,
        conflicts_count INT DEFAULT 0,
        duration_ms INT DEFAULT 0,
        error_message TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'sync_logs' verified.");

    // 3b. Vehicle Technical Specs Reference Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_specs (
        id {$autoInc},
        cloud_id INT DEFAULT NULL,
        make VARCHAR(50) NOT NULL,
        model VARCHAR(50) NOT NULL,
        engine_type VARCHAR(50) DEFAULT '',
        oil_viscosity VARCHAR(30) NOT NULL,
        oil_type VARCHAR(50) DEFAULT NULL,
        oil_capacity_liters DECIMAL(4,2) NOT NULL,
        oil_filter_code VARCHAR(50) NOT NULL,
        other_fluids TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        version INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'vehicle_specs' verified.");

    // 3c. Reminder Rules Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS reminder_rules (
        id {$autoInc},
        cloud_id INT DEFAULT NULL,
        rule_type VARCHAR(50) NOT NULL,
        trigger_mileage_before INT DEFAULT 0,
        trigger_days_before INT DEFAULT 0,
        trigger_days_inactive INT DEFAULT 0,
        message_template_en TEXT DEFAULT NULL,
        message_template_km TEXT DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        version INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'reminder_rules' verified.");

    // 4. Add is_synced columns to local tables
    $tableColumns = [
        'orders' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'stock_movements' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'pos_closings' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'staff_timecards' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'loyalty_transactions' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'audit_logs' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
        'order_tasks' => ['is_synced' => 'TINYINT(1) DEFAULT 0', 'synced_at' => 'DATETIME DEFAULT NULL'],
    ];

    foreach ($tableColumns as $table => $cols) {
        if ($isSqlite) {
            $existing = $pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_COLUMN, 1);
        } else {
            $existing = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        }

        foreach ($cols as $colName => $colDef) {
            if (!in_array($colName, $existing, true)) {
                try {
                    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$colName}` {$colDef}");
                } catch (Exception $e) {}
            }
        }
    }
    migrateLog("Added 'is_synced' flags to core operational tables.");

    // 5. Cloud Sync Permissions
    $newPermissions = [
        ['sync', 'sync.view', 'View Cloud Sync Monitor & Logs', 'មើលស្ថានភាព Cloud Sync និងកំណត់ត្រា', 80],
        ['sync', 'sync.manage', 'Trigger Force Sync & Resolve Conflicts', 'បញ្ជា Cloud Sync ភ្លាមៗ និងដោះស្រាយទំនាស់ទិន្នន័យ', 81],
    ];

    $permCheck = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
    $permInsert = $pdo->prepare("INSERT INTO permissions (module, code, label_en, label_km, sort_order) VALUES (?, ?, ?, ?, ?)");

    foreach ($newPermissions as $p) {
        $permCheck->execute([$p[1]]);
        if (!$permCheck->fetchColumn()) {
            $permInsert->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
        }
    }

    // Link to owner & manager roles
    $roleStmt = $pdo->query("SELECT id, code FROM roles WHERE code IN ('owner', 'manager')");
    $targetRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

    $linkCheck = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $linkInsert = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($targetRoles as $r) {
        foreach ($newPermissions as $p) {
            $pId = $pdo->query("SELECT id FROM permissions WHERE code = " . $pdo->quote($p[1]))->fetchColumn();
            if ($pId) {
                $linkCheck->execute([$r['id'], $pId]);
                if ((int)$linkCheck->fetchColumn() === 0) {
                    $linkInsert->execute([$r['id'], $pId]);
                }
            }
        }
    }
    migrateLog("Cloud Sync permissions registered.");

    // 6. Default settings
    $defaultSettings = [
        'cloud_sync_enabled' => '1',
        'cloud_api_url' => '',
        'cloud_shop_id' => '',
        'cloud_api_token' => '',
        'cloud_api_secret' => '',
        'cloud_sync_interval_mins' => '3',
        'cloud_offline_mode' => '0',
        'cloud_allow_insecure_tls' => '0',
        'catalog_price_version' => (string)time()
    ];

    $checkSettingStmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $insertSettingStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");

    foreach ($defaultSettings as $sKey => $sVal) {
        $checkSettingStmt->execute([$sKey]);
        if ((int)$checkSettingStmt->fetchColumn() === 0) {
            $insertSettingStmt->execute([$sKey, $sVal]);
        }
    }
    migrateLog("Default Cloud Sync settings seeded.");

    // 7. Record completed migration
    if ($isSqlite) {
        $recStmt = $pdo->prepare("INSERT OR REPLACE INTO schema_migrations (migration, executed_at) VALUES (?, CURRENT_TIMESTAMP)");
    } else {
        $recStmt = $pdo->prepare("INSERT INTO schema_migrations (migration, executed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE executed_at = NOW()");
    }
    $recStmt->execute(['migrate_cloud_sync']);
    migrateLog("Migration 'migrate_cloud_sync' recorded in schema_migrations.");

    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Cloud Sync Migration via CLI...\n";
    $success = migrateCloudSync(true);
    echo $success ? "Migration completed successfully!\n" : "Migration encountered an issue.\n";
}
