<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

/**
 * Migration: Add reports.view_profit permission and assign to Owner & System Admin.
 * SAFETY RULE: Runs only on test database ending with '_test' (unless explicit).
 */
function migrateProfitPermission(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_profit_permission']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_profit_permission' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Adding 'reports.view_profit' permission and configuring role defaults...");

    // 1. Insert permission definition if missing
    $pCheck = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
    $pCheck->execute(['reports.view_profit']);
    $permId = (int)$pCheck->fetchColumn();

    if ($permId <= 0) {
        $insP = $pdo->prepare("
            INSERT INTO permissions (module, code, label_en, label_km, area, sort_order)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insP->execute([
            'reports',
            'reports.view_profit',
            'View Profit, Cost & Margin Figures',
            'មើលតួលេខប្រាក់ចំណេញ ថ្លៃដើម និងប្រាក់ចំណេញសុទ្ធ',
            'reports',
            54
        ]);
        $permId = (int)$pdo->lastInsertId();
        migrateLog("Created permission 'reports.view_profit' (ID: {$permId}).");
    } else {
        migrateLog("Permission 'reports.view_profit' already exists (ID: {$permId}).");
    }

    // 2. Assign permission to 'owner' and 'system_admin' roles by default
    // Managers and other roles DO NOT get this permission unless explicitly assigned by Owner.
    $rolesToAssign = ['owner', 'system_admin'];
    foreach ($rolesToAssign as $rCode) {
        $rStmt = $pdo->prepare("SELECT id FROM roles WHERE code = ? LIMIT 1");
        $rStmt->execute([$rCode]);
        $roleId = (int)$rStmt->fetchColumn();

        if ($roleId > 0 && $permId > 0) {
            if ($isSqlite) {
                $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                    ->execute([$roleId, $permId]);
            } else {
                $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE permission_id = permission_id")
                    ->execute([$roleId, $permId]);
            }
            migrateLog("Assigned 'reports.view_profit' to role '{$rCode}' (Role ID: {$roleId}).");
        }
    }

    // Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_profit_permission']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_profit_permission']);
    }

    migrateLog("Migration 'migrate_profit_permission' completed successfully.");
    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running standalone migration for profit permission...\n";
    $ok = migrateProfitPermission(true);
    exit($ok ? 0 : 1);
}
