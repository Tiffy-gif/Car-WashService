<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateRevokeCashierCancel(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_revoke_cashier_cancel']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_revoke_cashier_cancel' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Revoking pos.cancel_order and orders.cancel from cashier role...");

    // 1. Delete cashier permissions for cancel_order
    $delStmt = $pdo->prepare("
        DELETE FROM role_permissions
        WHERE role_id IN (SELECT id FROM roles WHERE code = 'cashier')
          AND permission_id IN (SELECT id FROM permissions WHERE code IN ('pos.cancel_order', 'orders.cancel'))
    ");
    $delStmt->execute();
    migrateLog("Revoked cancellation permissions from cashier role.");

    // 2. Ensure manager role retains both cancellation permissions
    $mgrRoleId = $pdo->query("SELECT id FROM roles WHERE code = 'manager' LIMIT 1")->fetchColumn();
    if ($mgrRoleId) {
        $pStmt = $pdo->query("SELECT id FROM permissions WHERE code IN ('pos.cancel_order', 'orders.cancel')");
        $pIds = $pStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pIds as $pId) {
            if ($isSqlite) {
                $pdo->prepare("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$mgrRoleId, $pId]);
            } else {
                $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$mgrRoleId, $pId]);
            }
        }
        migrateLog("Confirmed manager role possesses cancellation permissions.");
    }

    // 3. Record migration
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_revoke_cashier_cancel']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_revoke_cashier_cancel']);
    }

    migrateLog("Revoke cashier cancel permissions migration completed.");
    return true;
}
