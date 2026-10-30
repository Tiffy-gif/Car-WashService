<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateTechnicalWarehouse(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_technical_warehouse']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_technical_warehouse' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Technical & Warehouse Flow Migration...");

    // 1. Create parts_requests Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS parts_requests (
        id {$autoInc},
        request_uid VARCHAR(50) NOT NULL UNIQUE,
        order_id INT NOT NULL,
        task_id INT DEFAULT NULL,
        product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        qty_requested INT NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'pending', -- 'pending', 'approved', 'rejected', 'received'
        requested_by_id INT NOT NULL,
        requested_by_name VARCHAR(100) NOT NULL,
        requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        reviewed_by_id INT DEFAULT NULL,
        reviewed_by_name VARCHAR(100) DEFAULT NULL,
        review_reason TEXT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'parts_requests' ensured.");

    // 2. Create inspection_checklists Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS inspection_checklists (
        id {$autoInc},
        order_id INT NOT NULL,
        task_id INT DEFAULT NULL,
        item_key VARCHAR(50) NOT NULL,
        item_name VARCHAR(100) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending', -- 'pending', 'pass', 'fail'
        measurement_value VARCHAR(50) DEFAULT NULL,
        photo_url VARCHAR(255) DEFAULT NULL,
        is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT DEFAULT NULL,
        checked_by_id INT DEFAULT NULL,
        checked_by_name VARCHAR(100) DEFAULT NULL,
        checked_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'inspection_checklists' ensured.");

    // 3. Create stock_discrepancies Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_discrepancies (
        id {$autoInc},
        discrepancy_uid VARCHAR(50) NOT NULL UNIQUE,
        product_id INT NOT NULL,
        product_name VARCHAR(150) NOT NULL,
        system_qty INT NOT NULL,
        counted_qty INT NOT NULL,
        difference_qty INT NOT NULL,
        reason TEXT NOT NULL,
        logged_by_id INT NOT NULL,
        logged_by_name VARCHAR(100) NOT NULL,
        logged_by_role VARCHAR(50) NOT NULL,
        logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        device_name VARCHAR(100) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1
    ) {$charsetEngine}");
    migrateLog("Table 'stock_discrepancies' ensured.");

    // 4. Ensure columns on order_tasks
    if (!$isSqlite) {
        $otCols = $pdo->query("SHOW COLUMNS FROM order_tasks")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('lane', $otCols, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN lane VARCHAR(20) NOT NULL DEFAULT 'lane_1' AFTER sequence");
        }
        if (!in_array('reserved_oil_liters', $otCols, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN reserved_oil_liters DECIMAL(6,2) DEFAULT NULL AFTER took_longer_than_expected");
        }
        if (!in_array('actual_oil_used', $otCols, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN actual_oil_used DECIMAL(6,2) DEFAULT NULL AFTER reserved_oil_liters");
        }
        if (!in_array('parts_status', $otCols, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN parts_status VARCHAR(30) NOT NULL DEFAULT 'none' AFTER actual_oil_used");
        }
    } else {
        $tableInfo = $pdo->query("PRAGMA table_info(order_tasks)")->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($tableInfo, 'name');
        if (!in_array('lane', $existing, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN lane VARCHAR(20) NOT NULL DEFAULT 'lane_1'");
        }
        if (!in_array('reserved_oil_liters', $existing, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN reserved_oil_liters DECIMAL(6,2) DEFAULT NULL");
        }
        if (!in_array('actual_oil_used', $existing, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN actual_oil_used DECIMAL(6,2) DEFAULT NULL");
        }
        if (!in_array('parts_status', $existing, true)) {
            $pdo->exec("ALTER TABLE order_tasks ADD COLUMN parts_status VARCHAR(30) NOT NULL DEFAULT 'none'");
        }
    }
    migrateLog("Columns on 'order_tasks' ensured.");

    // 5. Ensure columns on orders
    if (!$isSqlite) {
        $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('job_order_uid', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN job_order_uid VARCHAR(50) DEFAULT NULL AFTER order_uid");
        }
        if (!in_array('has_pending_parts_request', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN has_pending_parts_request TINYINT(1) DEFAULT 0 AFTER is_mixed");
        }
        if (!in_array('actual_oil_used', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN actual_oil_used DECIMAL(6,2) DEFAULT NULL AFTER next_oil_date");
        }
    } else {
        $tableInfo = $pdo->query("PRAGMA table_info(orders)")->fetchAll(PDO::FETCH_ASSOC);
        $existing = array_column($tableInfo, 'name');
        if (!in_array('job_order_uid', $existing, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN job_order_uid VARCHAR(50) DEFAULT NULL");
        }
        if (!in_array('has_pending_parts_request', $existing, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN has_pending_parts_request TINYINT(1) DEFAULT 0");
        }
        if (!in_array('actual_oil_used', $existing, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN actual_oil_used DECIMAL(6,2) DEFAULT NULL");
        }
    }
    migrateLog("Columns on 'orders' ensured.");

    // 6. Ensure Permissions
    $newPerms = [
        ['module' => 'mechanic_jobs', 'code' => 'mechanic_jobs.update',   'label_en' => 'Update Job Status & Tasks',      'label_km' => 'ធ្វើបច្ចុប្បន្នភាពស្ថានភាពការងារ', 'sort_order' => 2],
        ['module' => 'mechanic_jobs', 'code' => 'mechanic_jobs.notes',    'label_en' => 'Save Mechanic Notes',           'label_km' => 'កត់ត្រាកំណត់សម្គាល់ជាង',            'sort_order' => 3],
        ['module' => 'mechanic_jobs', 'code' => 'parts_requests.create',  'label_en' => 'Create Parts Requests',         'label_km' => 'ស្នើសុំគ្រឿងបន្លាស់',                'sort_order' => 4],
        ['module' => 'mechanic_jobs', 'code' => 'inspection.manage',      'label_en' => 'Manage Inspection Checklist',   'label_km' => 'គ្រប់គ្រងបញ្ជីត្រួតពិនិត្យយានយន្ត',  'sort_order' => 5],
        ['module' => 'oil_care',      'code' => 'parts_requests.approve', 'label_en' => 'Approve/Reject Parts Requests', 'label_km' => 'អនុម័ត/បដិសេធសំណើគ្រឿងបន្លាស់',   'sort_order' => 10],
        ['module' => 'oil_care',      'code' => 'discrepancy.manage',     'label_en' => 'Log Stock Discrepancy',         'label_km' => 'កត់ត្រាគម្លាតស្តុកជាក់ស្តែង',         'sort_order' => 11],
    ];

    $insPerm = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO permissions (module, code, label_en, label_km, sort_order) VALUES (?, ?, ?, ?, ?)"
        : "INSERT IGNORE INTO permissions (module, code, label_en, label_km, sort_order) VALUES (?, ?, ?, ?, ?)"
    );

    foreach ($newPerms as $np) {
        $insPerm->execute([$np['module'], $np['code'], $np['label_en'], $np['label_km'], $np['sort_order']]);
    }

    $allPerms = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rolesByCode = array_flip($pdo->query("SELECT id, code FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR));

    $insRp = $pdo->prepare($isSqlite
        ? "INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
        : "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
    );

    // Assign permissions to Technical & Mechanic
    foreach (['technical', 'mechanic'] as $rCode) {
        if (!empty($rolesByCode[$rCode])) {
            $rId = $rolesByCode[$rCode];
            $techPermCodes = [
                'mechanic_jobs.view', 'mechanic_jobs.update', 'mechanic_jobs.notes',
                'parts_requests.create', 'inspection.manage', 'vehicle_history.list'
            ];
            foreach ($techPermCodes as $c) {
                if (isset($allPerms[$c])) {
                    $insRp->execute([$rId, $allPerms[$c]]);
                }
            }
        }
    }

    // Assign permissions to Stock Control
    if (!empty($rolesByCode['stock_control'])) {
        $scId = $rolesByCode['stock_control'];
        $scPermCodes = [
            'oil_care.list', 'oil_care.add', 'oil_care.edit', 'oil_care.adjust_stock',
            'parts_requests.approve', 'discrepancy.manage', 'categories.list', 'extras.list'
        ];
        foreach ($scPermCodes as $c) {
            if (isset($allPerms[$c])) {
                $insRp->execute([$scId, $allPerms[$c]]);
            }
        }
    }

    // Manager role permissions
    if (!empty($rolesByCode['manager'])) {
        $mgrId = $rolesByCode['manager'];
        $mgrPermCodes = ['parts_requests.approve', 'discrepancy.manage', 'inspection.manage'];
        foreach ($mgrPermCodes as $c) {
            if (isset($allPerms[$c])) {
                $insRp->execute([$mgrId, $allPerms[$c]]);
            }
        }
    }

    // Owner role gets all permissions
    if (!empty($rolesByCode['owner'])) {
        $ownerId = $rolesByCode['owner'];
        foreach ($allPerms as $pCode => $pId) {
            $insRp->execute([$ownerId, $pId]);
        }
    }

    // Record migration in schema_migrations
    $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_technical_warehouse']);
    migrateLog("Migration 'migrate_technical_warehouse' completed successfully.");

    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running migrateTechnicalWarehouse...\n";
    $ok = migrateTechnicalWarehouse(true);
    echo $ok ? "Migration successful!\n" : "Migration failed!\n";
}
