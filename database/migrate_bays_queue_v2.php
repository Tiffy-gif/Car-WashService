<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/BayManager.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateBaysQueueV2(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_bays_queue_v2']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_bays_queue_v2' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Bays & Queue Extension Migration (V2)...");

    // 1. Ensure columns and tables via BayManager
    BayManager::ensureTables($pdo);
    migrateLog("Bays skill_tag, last_freed_at, order_tasks, and priority columns ensured.");

    // 2. Ensure Wash Supervisor role permissions
    $allPerms = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rolesByCode = array_flip($pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR));

    $insRp = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
        : "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
    );

    // Wash Supervisor role permissions
    if (!empty($rolesByCode['wash_supervisor'])) {
        $supId = $rolesByCode['wash_supervisor'];
        $supPerms = [
            'bays.view', 'bays.assign', 'bays.finish', 'bays.arrive', 'bays.move_queue',
            'orders.list', 'washer_jobs.view', 'mechanic_jobs.view', 'vehicle_history.list'
        ];
        foreach ($supPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$supId, $allPerms[$code]]);
            }
        }
    }

    // Owner role gets all permissions
    if (!empty($rolesByCode['owner'])) {
        $ownerId = $rolesByCode['owner'];
        foreach ($allPerms as $code => $pid) {
            $insRp->execute([$ownerId, $pid]);
        }
    }

    // Manager role permissions
    if (!empty($rolesByCode['manager'])) {
        $mgrId = $rolesByCode['manager'];
        $mgrPerms = [
            'bays.view', 'bays.assign', 'bays.finish', 'bays.arrive', 'bays.move_queue',
            'orders.list', 'washer_jobs.view', 'mechanic_jobs.view', 'vehicle_history.list'
        ];
        foreach ($mgrPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$mgrId, $allPerms[$code]]);
            }
        }
    }

    // Record migration
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_bays_queue_v2']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_bays_queue_v2']);
    }

    migrateLog("Bays & Queue Extension (V2) completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    migrateBaysQueueV2();
}
