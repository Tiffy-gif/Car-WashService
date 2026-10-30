<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateFoundation(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_foundation']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_foundation' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Foundation migration (10 Roles, PIN Login, Audit Logs, Unique IDs)...");

    // 1. Audit Logs Table (Immutable log history with unique ID)
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            log_uid VARCHAR(64) NOT NULL UNIQUE,
            user_id INT DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            role VARCHAR(50) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) DEFAULT NULL,
            entity_id VARCHAR(100) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            device_name VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            log_uid VARCHAR(64) NOT NULL UNIQUE,
            user_id INT DEFAULT NULL,
            username VARCHAR(100) DEFAULT NULL,
            role VARCHAR(50) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(50) DEFAULT NULL,
            entity_id VARCHAR(100) DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(50) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            device_name VARCHAR(100) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_user (user_id),
            INDEX idx_audit_action (action),
            INDEX idx_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'audit_logs' verified.");

    // 2. Payments Table (Every payment gets a unique non-repeating ID)
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            payment_uid VARCHAR(64) NOT NULL UNIQUE,
            order_id INT NOT NULL,
            order_number VARCHAR(50) NOT NULL,
            amount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount_khr INT NOT NULL DEFAULT 0,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
            payment_ref VARCHAR(100) DEFAULT NULL,
            cashier_id INT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            payment_uid VARCHAR(64) NOT NULL UNIQUE,
            order_id INT NOT NULL,
            order_number VARCHAR(50) NOT NULL,
            amount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount_khr INT NOT NULL DEFAULT 0,
            payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
            payment_ref VARCHAR(100) DEFAULT NULL,
            cashier_id INT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pay_order (order_id),
            INDEX idx_pay_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'payments' verified.");

    // 3. Stock Movements Table (Every inventory change gets a unique non-repeating ID)
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            movement_uid VARCHAR(64) NOT NULL UNIQUE,
            product_id INT NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            previous_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reference_id VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            performed_by INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movement_uid VARCHAR(64) NOT NULL UNIQUE,
            product_id INT NOT NULL,
            movement_type VARCHAR(30) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            previous_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            new_qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reference_id VARCHAR(100) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            performed_by INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_prod (product_id),
            INDEX idx_stock_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'stock_movements' verified.");

    // 4. Update Columns in existing tables
    if (!$isSqlite) {
        // Users table columns for PIN login & lock
        $uCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('failed_login_attempts', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN failed_login_attempts INT NOT NULL DEFAULT 0");
        }
        if (!in_array('locked_until', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME DEFAULT NULL");
        }
        if (!in_array('must_change_pin', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_pin TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('is_sample_account', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN is_sample_account TINYINT(1) NOT NULL DEFAULT 0");
        }

        // Orders table columns
        $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('order_uid', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN order_uid VARCHAR(64) NULL AFTER order_number");
        }
        if (!in_array('is_active', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }

        // Expenses table columns
        $eCols = $pdo->query("SHOW COLUMNS FROM expenses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active', $eCols, true)) {
            $pdo->exec("ALTER TABLE expenses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }

        // Order Washers and Mechanics active flags
        $owCols = $pdo->query("SHOW COLUMNS FROM order_washers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active', $owCols, true)) {
            $pdo->exec("ALTER TABLE order_washers ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
        $omCols = $pdo->query("SHOW COLUMNS FROM order_mechanics")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_active', $omCols, true)) {
            $pdo->exec("ALTER TABLE order_mechanics ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
        }
    }

    // 5. Ensure Permissions for Audit and System Administration
    $newPermissions = [
        ['audit', 'audit.view', 'View Audit Trail Logs', 'មើលកំណត់ត្រាសវនកម្ម', 'admin', 70],
        ['bays', 'bays.manage', 'Manage Wash Bays & Queue', 'គ្រប់គ្រងកន្លែងលាង & ជួររង់ចាំ', 'sales', 71],
    ];

    $insPermStmt = $pdo->prepare($isSqlite ?
        "INSERT OR IGNORE INTO permissions (module, code, label_en, label_km, area, sort_order) VALUES (?, ?, ?, ?, ?, ?)" :
        "INSERT INTO permissions (module, code, label_en, label_km, area, sort_order) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE label_en = VALUES(label_en), label_km = VALUES(label_km)"
    );
    foreach ($newPermissions as $np) {
        $insPermStmt->execute($np);
    }

    // 6. Seed Built-In Ten Roles
    // Built-in roles:
    // 1. System Admin
    // 2. Owner
    // 3. Manager
    // 4. CRM Coordinator
    // 5. Cashier
    // 6. Stock Control (Warehouse)
    // 7. Technical
    // 8. Washer
    // 9. Wash Supervisor
    // 10. Customer (TV only, no login)
    $tenRoles = [
        [
            'code' => 'system_admin',
            'name_en' => 'System Admin',
            'name_km' => 'អ្នកគ្រប់គ្រងប្រព័ន្ធ',
            'description' => 'System administration, settings, user accounts, POS devices, backups and audit logs. CANNOT view money or financial reports.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'owner',
            'name_en' => 'Shop Owner',
            'name_km' => 'ម្ចាស់ហាង',
            'description' => 'Full administrative and business access: revenue analytics, financial reports, daily closes, expenses, staff, and system settings.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'manager',
            'name_en' => 'Manager',
            'name_km' => 'អ្នកចាត់ការទូទៅ',
            'description' => 'Operations manager: manages queues, orders, bay assignments, staff duty, catalog, and inventory.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 1,
            'can_assign_mechanic' => 1,
        ],
        [
            'code' => 'crm',
            'name_en' => 'CRM Coordinator',
            'name_km' => 'អ្នកសម្របសម្រួល CRM',
            'description' => 'Customer relationship coordinator: customer directory, vehicles, loyalty points, and maintenance notifications.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'cashier',
            'name_en' => 'Cashier',
            'name_km' => 'អ្នកគិតលុយ',
            'description' => 'Front-desk point of sale, order checkouts, payment receipts, order queue, and daily close registers.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'stock_control',
            'name_en' => 'Stock Control (Warehouse)',
            'name_km' => 'អ្នកគ្រប់គ្រងស្តុក (ឃ្លាំង)',
            'description' => 'Warehouse stock manager: tracking motor oils, spare parts, car care inventory, and stock adjustments.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'technical',
            'name_en' => 'Technical',
            'name_km' => 'ជាងបច្ចេកទេស',
            'description' => 'Technical service specialist: vehicle maintenance queue, diagnostics, repair jobs, and repair history.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 1,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 1,
        ],
        [
            'code' => 'washer',
            'name_en' => 'Washer',
            'name_km' => 'ជាងលាងរថយន្ត',
            'description' => 'Vehicle cleaning specialist: wash bay queue, active washing jobs, and commission earnings.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 1,
            'can_assign_washer' => 1,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'wash_supervisor',
            'name_en' => 'Wash Supervisor',
            'name_km' => 'ប្រធានត្រួតពិនិត្យការលាង',
            'description' => 'Supervises wash bays and FIFO queue, assigns washers to bays, and inspects quality before delivery.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 1,
            'can_assign_mechanic' => 1,
        ],
        [
            'code' => 'customer',
            'name_en' => 'Customer (Queue TV Only)',
            'name_km' => 'អតិថិជន (អេក្រង់ TV)',
            'description' => 'Customer Queue TV display screen (tv.php). Has no back-office login privileges.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
    ];

    $insRoleStmt = $pdo->prepare($isSqlite ?
        "INSERT OR REPLACE INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)" :
        "INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name_en = VALUES(name_en),
            name_km = VALUES(name_km),
            description = VALUES(description),
            is_system = VALUES(is_system),
            is_active = VALUES(is_active),
            is_commission_eligible = VALUES(is_commission_eligible),
            can_assign_washer = VALUES(can_assign_washer),
            can_assign_mechanic = VALUES(can_assign_mechanic)"
    );

    foreach ($tenRoles as $r) {
        $insRoleStmt->execute([
            $r['code'], $r['name_en'], $r['name_km'], $r['description'],
            $r['is_system'], $r['is_active'], $r['is_commission_eligible'],
            $r['can_assign_washer'], $r['can_assign_mechanic']
        ]);
    }
    migrateLog("Ten built-in roles verified.");

    // Fetch all permissions & roles map
    $allPerms = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rolesMap = $pdo->query("SELECT code, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);

    $insRp = $pdo->prepare($isSqlite ?
        "INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)" :
        "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
    );

    // 7. Assign Permissions to Roles:
    // 7a. System Admin: settings, staff, roles, devices, backups, audit trail. NO money reports!
    $sysAdminId = $rolesMap['system_admin'] ?? null;
    if ($sysAdminId) {
        $sysAdminPerms = [
            'settings.view', 'settings.edit',
            'staff.list', 'staff.add', 'staff.edit', 'staff.delete', 'staff.reset_password',
            'roles.list', 'roles.add', 'roles.edit', 'roles.delete',
            'pos_devices.list', 'pos_devices.add', 'pos_devices.edit', 'pos_devices.block', 'pos_devices.regen_key',
            'reports.backup', 'audit.view'
        ];
        // Clean existing permissions for System Admin to guarantee no money access
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$sysAdminId]);
        foreach ($sysAdminPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$sysAdminId, $allPerms[$code]]);
            }
        }
    }

    // 7b. Owner: ALL permissions
    $ownerId = $rolesMap['owner'] ?? null;
    if ($ownerId) {
        foreach ($allPerms as $code => $pid) {
            $insRp->execute([$ownerId, $pid]);
        }
    }

    // 7c. Manager: operations management
    $managerId = $rolesMap['manager'] ?? null;
    if ($managerId) {
        $managerPerms = [
            'orders.list', 'orders.change_status', 'orders.change_crew', 'orders.cancel',
            'bays.view', 'bays.manage',
            'pos.access', 'pos.create_order', 'pos.discount', 'pos.pay_order', 'pos.pay_later', 'pos.cancel_order', 'pos.receipt',
            'customers.list', 'customers.add', 'customers.edit',
            'services.list', 'services.add', 'services.edit',
            'categories.list',
            'oil_care.list', 'oil_care.add', 'oil_care.edit',
            'extras.list', 'extras.add', 'extras.edit',
            'staff.list', 'vehicle_history.list',
            'washer_jobs.view', 'mechanic_jobs.view', 'daily_close.view'
        ];
        foreach ($managerPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$managerId, $allPerms[$code]]);
            }
        }
    }

    // 7d. CRM Coordinator: customers, vehicles, loyalty, service reminders
    $crmId = $rolesMap['crm'] ?? null;
    if ($crmId) {
        $crmPerms = [
            'customers.list', 'customers.add', 'customers.edit', 'customers.delete',
            'vehicle_history.list',
            'oil_care.list'
        ];
        foreach ($crmPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$crmId, $allPerms[$code]]);
            }
        }
    }

    // 7e. Cashier: POS, orders, customers, daily closes
    $cashierId = $rolesMap['cashier'] ?? null;
    if ($cashierId) {
        $cashierPerms = [
            'pos.access', 'pos.create_order', 'pos.discount', 'pos.pay_order', 'pos.pay_later', 'pos.receipt',
            'orders.list', 'orders.change_status', 'orders.change_crew',
            'customers.list', 'customers.add', 'customers.edit',
            'vehicle_history.list', 'oil_care.list', 'extras.list',
            'daily_close.view', 'daily_close.close'
        ];
        foreach ($cashierPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$cashierId, $allPerms[$code]]);
            }
        }
    }

    // 7f. Stock Control: warehouse inventory, oil products, extra goods
    $stockId = $rolesMap['stock_control'] ?? null;
    if ($stockId) {
        $stockPerms = [
            'oil_care.list', 'oil_care.add', 'oil_care.edit', 'oil_care.adjust_stock',
            'extras.list', 'extras.add', 'extras.edit', 'extras.adjust_stock',
            'categories.list'
        ];
        foreach ($stockPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$stockId, $allPerms[$code]]);
            }
        }
    }

    // 7g. Technical: mechanic screen, vehicle history, diagnostics
    $techId = $rolesMap['technical'] ?? null;
    if ($techId) {
        $techPerms = [
            'mechanic_jobs.view', 'mechanic_jobs.update', 'mechanic_jobs.notes',
            'vehicle_history.list'
        ];
        foreach ($techPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$techId, $allPerms[$code]]);
            }
        }
    }
    // Also support legacy mechanic role if present
    $mechId = $rolesMap['mechanic'] ?? null;
    if ($mechId) {
        foreach (['mechanic_jobs.view', 'mechanic_jobs.update', 'mechanic_jobs.notes', 'vehicle_history.list'] as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$mechId, $allPerms[$code]]);
            }
        }
    }

    // 7h. Washer: wash bay screen & updates
    $washerId = $rolesMap['washer'] ?? null;
    if ($washerId) {
        $washerPerms = ['washer_jobs.view', 'washer_jobs.update'];
        foreach ($washerPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$washerId, $allPerms[$code]]);
            }
        }
    }

    // 7i. Wash Supervisor: bays & queue, crew assignment, job monitoring
    $supId = $rolesMap['wash_supervisor'] ?? null;
    if ($supId) {
        $supPerms = [
            'bays.view', 'bays.manage',
            'orders.list', 'orders.change_status', 'orders.change_crew',
            'washer_jobs.view', 'mechanic_jobs.view'
        ];
        foreach ($supPerms as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$supId, $allPerms[$code]]);
            }
        }
    }

    // 7j. Customer: NO PERMISSIONS, TV ONLY!
    $custId = $rolesMap['customer'] ?? null;
    if ($custId) {
        $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$custId]);
    }

    migrateLog("Role permissions linked.");

    // 8. Seed / Update Built-in Sample Accounts with 6-digit PINs (ONLY in development)
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $sampleStaff = [
            ['username' => 'admin',           'pin' => '111111', 'name' => 'System Admin',           'role' => 'system_admin',   'role_id' => $sysAdminId],
            ['username' => 'owner',           'pin' => '123456', 'name' => 'Bona Sok (Owner)',        'role' => 'owner',          'role_id' => $ownerId],
            ['username' => 'manager',         'pin' => '123456', 'name' => 'Vireak Chea (Manager)',   'role' => 'manager',        'role_id' => $managerId],
            ['username' => 'crm',             'pin' => '123456', 'name' => 'Sophea Nuon (CRM)',       'role' => 'crm',            'role_id' => $crmId],
            ['username' => 'cashier',         'pin' => '123456', 'name' => 'Dara Chan (Cashier)',     'role' => 'cashier',        'role_id' => $cashierId],
            ['username' => 'stock_control',   'pin' => '123456', 'name' => 'Serey Vong (Warehouse)',  'role' => 'stock_control',  'role_id' => $stockId],
            ['username' => 'technical',       'pin' => '123456', 'name' => 'Kosal Pich (Technical)',  'role' => 'technical',      'role_id' => $techId],
            ['username' => 'washer',          'pin' => '123456', 'name' => 'Rathana Heng (Washer)',   'role' => 'washer',         'role_id' => $washerId],
            ['username' => 'wash_supervisor', 'pin' => '123456', 'name' => 'Piseth Bun (Supervisor)', 'role' => 'wash_supervisor','role_id' => $supId],
            ['username' => 'customer',        'pin' => '123456', 'name' => 'Customer Display TV',     'role' => 'customer',       'role_id' => $custId],
        ];

        $selUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $insUser = $pdo->prepare("
            INSERT INTO users (username, password_hash, full_name, phone, role, role_id, commission_type, commission_rate, status, is_sample_account)
            VALUES (?, ?, ?, '012 345 678', ?, ?, 'percentage', 20.00, 'active', 1)
        ");
        $updUser = $pdo->prepare("
            UPDATE users SET
                password_hash = ?, full_name = ?, role = ?, role_id = ?, status = 'active',
                is_sample_account = 1, failed_login_attempts = 0, locked_until = NULL, must_change_pin = 0
            WHERE username = ?
        ");

        foreach ($sampleStaff as $stf) {
            $selUser->execute([$stf['username']]);
            $existing = $selUser->fetch();
            $pinHash = password_hash($stf['pin'], PASSWORD_DEFAULT);
            if ($existing) {
                $updUser->execute([$pinHash, $stf['name'], $stf['role'], $stf['role_id'], $stf['username']]);
            } else {
                $insUser->execute([$stf['username'], $pinHash, $stf['name'], $stf['role'], $stf['role_id']]);
            }
        }

        // Also ensure existing washer1 and mechanic1 work with PIN 123456
        $legacySample = [
            ['username' => 'washer1',   'pin' => '123456', 'role' => 'washer',    'role_id' => $washerId],
            ['username' => 'washer2',   'pin' => '123456', 'role' => 'washer',    'role_id' => $washerId],
            ['username' => 'mechanic1', 'pin' => '123456', 'role' => 'technical', 'role_id' => $techId],
        ];
        foreach ($legacySample as $ls) {
            $selUser->execute([$ls['username']]);
            if ($selUser->fetch()) {
                $pdo->prepare("
                    UPDATE users SET
                        password_hash = ?, role = ?, role_id = ?, status = 'active',
                        is_sample_account = 1, failed_login_attempts = 0, locked_until = NULL
                    WHERE username = ?
                ")->execute([password_hash($ls['pin'], PASSWORD_DEFAULT), $ls['role'], $ls['role_id'], $ls['username']]);
            }
        }
    }

    // Backfill role_id on any other users
    foreach ($rolesMap as $code => $rid) {
        $pdo->prepare("UPDATE users SET role_id = ? WHERE role = ? AND (role_id IS NULL OR role_id != ?)")->execute([$rid, $code, $rid]);
    }

    // 9. Backfill unique order_uid on orders
    $ordersWithoutUid = $pdo->query("SELECT id, order_number FROM orders WHERE order_uid IS NULL OR order_uid = ''")->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($ordersWithoutUid)) {
        $updOrderUid = $pdo->prepare("UPDATE orders SET order_uid = ? WHERE id = ?");
        foreach ($ordersWithoutUid as $ord) {
            $datePart = date('Ymd');
            $randomPart = strtoupper(bin2hex(random_bytes(4)));
            $uid = "ORD-{$datePart}-{$randomPart}";
            $updOrderUid->execute([$uid, $ord['id']]);
        }
        migrateLog("Backfilled unique order_uid for " . count($ordersWithoutUid) . " historical orders.");
    }

    // Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_foundation']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_foundation']);
    }

    migrateLog("Foundation migration executed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $force = in_array('--force', $argv ?? [], true);
    migrateFoundation($force);
}
