<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/BayManager.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateBaysQueue(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_bays_queue']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_bays_queue' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Bays & Queue Migration...");

    // 1. Ensure tables, columns, and initial bays
    BayManager::ensureTables($pdo);
    migrateLog("Bays, bay_history, and orders columns ensured.");

    // 1b. Seed default TV display settings if not present
    $defaultSettings = [
        'tv_display_enabled'  => '1',
        'tv_display_code'     => 'tv-office-88',
        'tv_display_language' => 'both',
    ];
    $insSetting = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)"
        : "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value"
    );
    foreach ($defaultSettings as $k => $v) {
        $insSetting->execute([$k, $v]);
    }
    migrateLog("TV display settings ensured.");

    // 2. Ensure Manager role exists in roles table
    $mgrStmt = $pdo->prepare("SELECT id FROM roles WHERE code = 'manager'");
    $mgrStmt->execute();
    $mgrId = $mgrStmt->fetchColumn();

    if (!$mgrId) {
        $insRole = $pdo->prepare("
            INSERT INTO roles (code, name_en, name_km, description, is_system, is_commission_eligible, can_assign_washer, can_assign_mechanic, is_active)
            VALUES ('manager', 'Store Manager', 'អ្នកគ្រប់គ្រងទូទៅ', 'Manages day-to-day operations, bays and queues', 0, 0, 1, 1, 1)
        ");
        $insRole->execute();
        $mgrId = (int)$pdo->lastInsertId();
        migrateLog("Created 'manager' role (ID: {$mgrId}).");
    }

    // 3. Seed Bays & Queue Permissions
    $permsToSeed = [
        ['bays', 'bays.view', 'View Bays & Queue Board', 'មើលផ្ទាំងកន្លែងលាង & ជួររង់ចាំ', 'sales', 131],
        ['bays', 'bays.assign', 'Assign Car to Bay', 'ដាក់រថយន្តចូលកន្លែងលាង', 'sales', 132],
        ['bays', 'bays.finish', 'Finish Car in Bay', 'បញ្ចប់ការលាង/ជួសជុលរថយន្ត', 'sales', 133],
        ['bays', 'bays.arrive', 'Mark Customer Arrived', 'កត់ត្រាអតិថិជនមកដល់', 'sales', 134],
        ['bays', 'bays.move_queue', 'Move Car Up in Queue', 'រំកិលរថយន្តឡើងមុខក្នុងជួរ (សិទ្ធិពិសេស)', 'sales', 135],
        ['bays', 'bays.manage', 'Manage Bays in Settings', 'គ្រប់គ្រងកន្លែងលាងក្នុង Settings', 'settings', 136],
    ];

    $insPerm = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO permissions (module, code, label_en, label_km, area, sort_order) VALUES (?, ?, ?, ?, ?, ?)"
        : "INSERT INTO permissions (module, code, label_en, label_km, area, sort_order) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label_en = VALUES(label_en), label_km = VALUES(label_km)"
    );

    foreach ($permsToSeed as $p) {
        $insPerm->execute($p);
    }
    migrateLog("Bays & Queue permissions registered.");

    // 4. Map permission IDs
    $allPerms = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);

    $roleRows = $pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    // roleRows is id => code. Invert to code => id:
    $rolesByCode = array_flip($roleRows);

    $insRp = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
        : "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
    );

    // 4a. Owner gets ALL permissions
    if (!empty($rolesByCode['owner'])) {
        $ownerId = $rolesByCode['owner'];
        foreach ($allPerms as $permCode => $permId) {
            $insRp->execute([$ownerId, $permId]);
        }
    }

    // 4b. Manager gets bays.view, bays.assign, bays.finish, bays.arrive, bays.move_queue
    if (!empty($rolesByCode['manager'])) {
        $managerId = $rolesByCode['manager'];
        $mgrPerms = [
            'pos.access', 'pos.create_order', 'pos.discount', 'pos.pay_order', 'pos.receipt',
            'orders.list', 'orders.change_status', 'orders.change_crew',
            'bays.view', 'bays.assign', 'bays.finish', 'bays.arrive', 'bays.move_queue',
            'washer_jobs.view', 'mechanic_jobs.view', 'vehicle_history.list',
            'customers.list', 'reports.view'
        ];
        foreach ($mgrPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$managerId, $allPerms[$code]]);
            }
        }
    }

    // 4c. Cashier gets bays.view, bays.assign, bays.finish, bays.arrive
    if (!empty($rolesByCode['cashier'])) {
        $cashierId = $rolesByCode['cashier'];
        $cashierPerms = ['bays.view', 'bays.assign', 'bays.finish', 'bays.arrive'];
        foreach ($cashierPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$cashierId, $allPerms[$code]]);
            }
        }
    }

    // 4d. Washer gets bays.view, bays.assign, bays.finish
    if (!empty($rolesByCode['washer'])) {
        $washerId = $rolesByCode['washer'];
        $washerPerms = ['bays.view', 'bays.assign', 'bays.finish'];
        foreach ($washerPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$washerId, $allPerms[$code]]);
            }
        }
    }

    // 4e. Mechanic gets bays.view, bays.assign, bays.finish
    if (!empty($rolesByCode['mechanic'])) {
        $mechanicId = $rolesByCode['mechanic'];
        $mechPerms = ['bays.view', 'bays.assign', 'bays.finish'];
        foreach ($mechPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$mechanicId, $allPerms[$code]]);
            }
        }
    }

    // 5. Record migration
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_bays_queue']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_bays_queue']);
    }

    migrateLog("Bays & Queue migration finished successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    migrateBaysQueue();
}
