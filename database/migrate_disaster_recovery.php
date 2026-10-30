<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

function run_migrate_disaster_recovery(bool $verbose = false): array {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');

    $log = function(string $msg) use ($verbose) {
        if ($verbose) {
            echo $msg . "\n";
        }
    };

    $log("Running Disaster Recovery & Paper Mode migration...");

    // 1. Add recovery fields to orders table
    $columnsToAdd = [
        'is_recovered'      => "TINYINT(1) DEFAULT 0",
        'paper_slip_number' => "VARCHAR(50) NULL",
        'recovered_by_id'   => "INT NULL",
        'recovered_by_name' => "VARCHAR(100) NULL",
        'recovered_at'      => "DATETIME NULL",
        'recovery_notes'    => "TEXT NULL"
    ];

    foreach ($columnsToAdd as $col => $definition) {
        if ($isSqlite) {
            $cols = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
            $hasCol = false;
            foreach ($cols as $c) {
                if ($c['name'] === $col) {
                    $hasCol = true;
                    break;
                }
            }
            if (!$hasCol) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$definition}");
            }
        } else {
            $hasCol = (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE '{$col}'")->fetch();
            if (!$hasCol) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN {$col} {$definition}");
            }
        }
    }

    // 2. Ensure permissions exist
    $perms = [
        ['code' => 'recovery.view',   'label_en' => 'View Disaster Recovery & Paper Forms', 'label_km' => 'មើលទម្រង់ក្រដាសគ្រោះអាសន្ន', 'module' => 'sales'],
        ['code' => 'recovery.create', 'label_en' => 'Bulk Enter Recovered Paper Tickets',    'label_km' => 'បញ្ចូលទិន្នន័យក្រដាសស្តារប្រព័ន្ធ', 'module' => 'sales'],
        ['code' => 'backup.manage',   'label_en' => 'Manage & Download Database Backups',    'label_km' => 'គ្រប់គ្រង និងទាញយកទិន្នន័យបម្រុង', 'module' => 'admin'],
    ];

    foreach ($perms as $p) {
        $check = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
        $check->execute([$p['code']]);
        if (!$check->fetchColumn()) {
            $pdo->prepare("INSERT INTO permissions (code, label_en, label_km, module) VALUES (?, ?, ?, ?)")
                ->execute([$p['code'], $p['label_en'], $p['label_km'], $p['module']]);
        }
    }

    // 3. Grant permissions to roles
    $roleCodes = ['owner', 'system_admin', 'manager', 'cashier'];
    foreach ($roleCodes as $rCode) {
        $rId = (int)$pdo->query("SELECT id FROM roles WHERE code = '{$rCode}'")->fetchColumn();
        if ($rId > 0) {
            $permList = ($rCode === 'cashier') ? ['recovery.view', 'recovery.create'] : ['recovery.view', 'recovery.create', 'backup.manage'];
            foreach ($permList as $pCode) {
                $pId = (int)$pdo->query("SELECT id FROM permissions WHERE code = '{$pCode}'")->fetchColumn();
                if ($pId > 0) {
                    $has = (bool)$pdo->query("SELECT 1 FROM role_permissions WHERE role_id = {$rId} AND permission_id = {$pId}")->fetchColumn();
                    if (!$has) {
                        $pdo->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES ({$rId}, {$pId})");
                    }
                }
            }
        }
    }

    // 4. Record migration
    $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration, executed_at) VALUES ('migrate_disaster_recovery', NOW()) ON DUPLICATE KEY UPDATE executed_at = NOW()");
    $stmt->execute();

    $log("Disaster Recovery migration completed successfully!");
    return ['success' => true];
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    run_migrate_disaster_recovery(true);
}
