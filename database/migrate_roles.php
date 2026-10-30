<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateRoles(bool $force = false): bool {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';

    // 0. Ensure schema_migrations table exists
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    // Check if migration has already been executed
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $checkStmt->execute(['migrate_roles']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_roles' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Roles & Permissions migration...");

    // 1. Create `roles` table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id {$autoInc},
            code VARCHAR(50) NOT NULL UNIQUE,
            name_en VARCHAR(100) NOT NULL,
            name_km VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            is_system INT NOT NULL DEFAULT 0,
            is_active INT NOT NULL DEFAULT 1,
            is_commission_eligible INT NOT NULL DEFAULT 0,
            can_assign_washer INT NOT NULL DEFAULT 0,
            can_assign_mechanic INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
            id {$autoInc},
            code VARCHAR(50) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            name_km VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_commission_eligible TINYINT(1) NOT NULL DEFAULT 0,
            can_assign_washer TINYINT(1) NOT NULL DEFAULT 0,
            can_assign_mechanic TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_role_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'roles' ensured.");

    // 2. Create `permissions` table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
            id {$autoInc},
            module VARCHAR(50) NOT NULL,
            code VARCHAR(100) NOT NULL UNIQUE,
            label_en VARCHAR(100) NOT NULL,
            label_km VARCHAR(100) NOT NULL,
            area VARCHAR(50) NOT NULL DEFAULT 'sales',
            sort_order INT NOT NULL DEFAULT 0
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
            id {$autoInc},
            module VARCHAR(50) NOT NULL,
            code VARCHAR(100) NOT NULL,
            label_en VARCHAR(100) NOT NULL,
            label_km VARCHAR(100) NOT NULL,
            area VARCHAR(50) NOT NULL DEFAULT 'sales',
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY unique_perm_code (code),
            INDEX idx_module (module),
            INDEX idx_area (area)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'permissions' ensured.");

    // 3. Create `role_permissions` pivot table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id)
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            INDEX idx_perm (permission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'role_permissions' ensured.");

    // 4. Create `role_audit_logs` table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_audit_logs (
            id {$autoInc},
            role_id INT DEFAULT NULL,
            role_code VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_audit_logs (
            id {$autoInc},
            role_id INT DEFAULT NULL,
            role_code VARCHAR(50) NOT NULL,
            action VARCHAR(50) NOT NULL,
            details TEXT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_role (role_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'role_audit_logs' ensured.");

    // 5. Add nullable role_id column to users table
    if ($isSqlite) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL");
            migrateLog("Added 'role_id' column to users.");
        } catch (Exception $e) {}
    } else {
        $userCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role_id', $userCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL AFTER role");
            migrateLog("Added 'role_id' column to users.");
        }
    }

    // 6. Seed Built-In Roles
    $builtInRoles = [
        [
            'code' => 'owner',
            'name_en' => 'Shop Owner',
            'name_km' => 'ម្ចាស់ហាង',
            'description' => 'Full administrative access to all back office operations, settings, staff, and financial reports.',
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
            'description' => 'Front-desk point of sale, customer checkouts, receiving payments, order queue, and daily close.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 0,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'washer',
            'name_en' => 'Car Washer',
            'name_km' => 'ជាងលាងរថយន្ត',
            'description' => 'Washing bay screen, tracking vehicle cleaning tasks, and earning washing commissions.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 1,
            'can_assign_washer' => 1,
            'can_assign_mechanic' => 0,
        ],
        [
            'code' => 'mechanic',
            'name_en' => 'Mechanic & Technician',
            'name_km' => 'ជាងជួសជុល & បច្ចេកទេស',
            'description' => 'Service bay screen, inspection, vehicle maintenance history, and repair commissions.',
            'is_system' => 1,
            'is_active' => 1,
            'is_commission_eligible' => 1,
            'can_assign_washer' => 0,
            'can_assign_mechanic' => 1,
        ],
    ];

    $insRoleStmt = $pdo->prepare("
        INSERT INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            name_en = VALUES(name_en),
            name_km = VALUES(name_km),
            description = VALUES(description),
            is_system = VALUES(is_system),
            is_commission_eligible = VALUES(is_commission_eligible),
            can_assign_washer = VALUES(can_assign_washer),
            can_assign_mechanic = VALUES(can_assign_mechanic)
    ");

    foreach ($builtInRoles as $r) {
        if ($isSqlite) {
            $pdo->prepare("
                INSERT OR REPLACE INTO roles (code, name_en, name_km, description, is_system, is_active, is_commission_eligible, can_assign_washer, can_assign_mechanic)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$r['code'], $r['name_en'], $r['name_km'], $r['description'], $r['is_system'], $r['is_active'], $r['is_commission_eligible'], $r['can_assign_washer'], $r['can_assign_mechanic']]);
        } else {
            $insRoleStmt->execute([$r['code'], $r['name_en'], $r['name_km'], $r['description'], $r['is_system'], $r['is_active'], $r['is_commission_eligible'], $r['can_assign_washer'], $r['can_assign_mechanic']]);
        }
    }
    migrateLog("Built-in roles seeded.");

    // Backfill role_id on users table
    $roleMap = $pdo->query("SELECT code, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($roleMap as $code => $id) {
        $pdo->prepare("UPDATE users SET role_id = ? WHERE role = ? AND (role_id IS NULL OR role_id != ?)")->execute([$id, $code, $id]);
    }
    migrateLog("Backfilled users.role_id.");

    // 7. Seed Permissions
    // Format: [module, code, label_en, label_km, area, sort_order]
    $permissionsToSeed = [
        // --- Sales & Operations ---
        ['pos', 'pos.access', 'Access POS Register', 'ចូលប្រើផ្ទាំងលក់', 'sales', 1],
        ['pos', 'pos.create_order', 'Create Order', 'បង្កើតការកុម្ម៉ង់', 'sales', 2],
        ['pos', 'pos.discount', 'Apply Discount', 'បញ្ចុះតម្លៃ', 'sales', 3],
        ['pos', 'pos.pay_order', 'Pay Order', 'ទទួលការទូទាត់', 'sales', 4],
        ['pos', 'pos.pay_later', 'Pay Later', 'ជំពាក់ (ទូទាត់ពេលក្រោយ)', 'sales', 5],
        ['pos', 'pos.cancel_order', 'Cancel Order', 'បោះបង់ការកុម្ម៉ង់', 'sales', 6],
        ['pos', 'pos.receipt', 'Reprint Receipt', 'បោះពុម្ពវិក្កយបត្រឡើងវិញ', 'sales', 7],

        ['orders', 'orders.list', 'Access / List Orders', 'មើលបញ្ជីការកុម្ម៉ង់', 'sales', 8],
        ['orders', 'orders.change_status', 'Change Status', 'ប្តូរស្ថានភាពការងារ', 'sales', 9],
        ['orders', 'orders.change_crew', 'Change Crew', 'ប្តូរជាង ឬបុគ្គលិកទទួលបន្ទុក', 'sales', 10],
        ['orders', 'orders.cancel', 'Cancel Order', 'បោះបង់វិក្កយបត្រ', 'sales', 11],

        ['daily_close', 'daily_close.view', 'View Daily Close Summary', 'មើលសង្ខេបបិទបញ្ជី', 'sales', 12],
        ['daily_close', 'daily_close.close', 'Close Register Day', 'បិទបញ្ជីប្រចាំថ្ងៃ', 'sales', 13],

        ['washer_jobs', 'washer_jobs.view', 'View Washer Screen', 'ផ្ទាំងការងារលាងរថយន្ត', 'sales', 14],

        ['mechanic_jobs', 'mechanic_jobs.view', 'View Mechanic Screen', 'ផ្ទាំងការងារជួសជុល', 'sales', 15],

        ['vehicle_history', 'vehicle_history.list', 'View Vehicle Repair History', 'ប្រវត្តិជួសជុលរថយន្ត', 'sales', 16],

        // --- Catalog & Inventory ---
        ['services', 'services.list', 'Access / List Services', 'មើលបញ្ជីសេវាកម្ម', 'catalog', 17],
        ['services', 'services.add', 'Add Service', 'បន្ថែមសេវាកម្ម', 'catalog', 18],
        ['services', 'services.edit', 'Edit Service', 'កែប្រែសេវាកម្ម', 'catalog', 19],
        ['services', 'services.delete', 'Delete Service', 'លុបសេវាកម្ម', 'catalog', 20],
        ['services', 'services.status', 'Activate / Deactivate Service', 'បើក-បិទដំណើរការ', 'catalog', 21],

        ['categories', 'categories.list', 'Access / List Categories', 'មើលបញ្ជីប្រភេទ', 'catalog', 22],
        ['categories', 'categories.add', 'Add Category', 'បន្ថែមប្រភេទថ្មី', 'catalog', 23],
        ['categories', 'categories.edit', 'Edit Category', 'កែប្រែប្រភេទ', 'catalog', 24],
        ['categories', 'categories.delete', 'Delete Category', 'លុបប្រភេទ', 'catalog', 25],

        ['oil_care', 'oil_care.list', 'Access / List Oil & Care', 'មើលបញ្ជីប្រេង & ថែទាំ', 'catalog', 26],
        ['oil_care', 'oil_care.add', 'Add Product', 'បន្ថែមទំនិញ', 'catalog', 27],
        ['oil_care', 'oil_care.edit', 'Edit Product', 'កែប្រែទំនិញ', 'catalog', 28],
        ['oil_care', 'oil_care.delete', 'Delete Product', 'លុបទំនិញ', 'catalog', 29],
        ['oil_care', 'oil_care.adjust_stock', 'Adjust Stock', 'កែតម្រូវស្តុក', 'catalog', 30],

        ['extras', 'extras.list', 'Access / List Extras', 'មើលបញ្ជីទំនិញបន្ថែម', 'catalog', 31],
        ['extras', 'extras.add', 'Add Item', 'បន្ថែមទំនិញ', 'catalog', 32],
        ['extras', 'extras.edit', 'Edit Item', 'កែប្រែទំនិញ', 'catalog', 33],
        ['extras', 'extras.delete', 'Delete Item', 'លុបទំនិញ', 'catalog', 34],
        ['extras', 'extras.adjust_stock', 'Adjust Stock', 'កែតម្រូវស្តុក', 'catalog', 35],
        ['extras', 'extras.loyalty', 'Loyalty Program Management', 'គ្រប់គ្រងកម្មវិធីភក្តីភាព', 'catalog', 36],

        // --- Customers & People ---
        ['customers', 'customers.list', 'Access / List Customers', 'មើលបញ្ជីអតិថិជន', 'people', 37],
        ['customers', 'customers.add', 'Add Customer', 'បន្ថែមអតិថិជន', 'people', 38],
        ['customers', 'customers.edit', 'Edit Customer', 'កែប្រែអតិថិជន', 'people', 39],
        ['customers', 'customers.delete', 'Delete Customer', 'លុបអតិថិជន', 'people', 40],

        ['staff', 'staff.list', 'Access / List Staff', 'មើលបញ្ជីបុគ្គលិក', 'people', 41],
        ['staff', 'staff.add', 'Add Staff', 'បន្ថែមបុគ្គលិក', 'people', 42],
        ['staff', 'staff.edit', 'Edit Staff', 'កែប្រែព័ត៌មានបុគ្គលិក', 'people', 43],
        ['staff', 'staff.delete', 'Delete Staff', 'លុបបុគ្គលិក', 'people', 44],
        ['staff', 'staff.reset_password', 'Reset Password', 'ប្តូរពាក្យសម្ងាត់', 'people', 45],

        ['roles', 'roles.list', 'Access / List Roles', 'មើលបញ្ជីតួនាទី', 'people', 46],
        ['roles', 'roles.add', 'Add Role', 'បង្កើតតួនាទីថ្មី', 'people', 47],
        ['roles', 'roles.edit', 'Edit Role', 'កែប្រែតួនាទី', 'people', 48],
        ['roles', 'roles.delete', 'Delete Role', 'លុបតួនាទី', 'people', 49],

        ['commissions', 'commissions.list', 'Access / List Commissions', 'មើលកម្រៃជើងសារ', 'people', 50],
        ['commissions', 'commissions.pay', 'Mark Paid', 'ទូទាត់កម្រៃជើងសារ', 'people', 51],

        // --- Reports & Finances ---
        ['dashboard', 'dashboard.view', 'View Dashboard', 'មើលផ្ទាំងគ្រប់គ្រង', 'reports', 52],

        ['reports', 'reports.view', 'View Reports', 'មើលរបាយការណ៍', 'reports', 53],
        ['reports', 'reports.export', 'Export to Excel', 'ទាញយកជា Excel', 'reports', 54],
        ['reports', 'reports.backup', 'Full System Backup', 'ទាញយកទិន្នន័យបម្រុង', 'reports', 55],

        ['expenses', 'expenses.list', 'Access / List Expenses', 'មើលបញ្ជីចំណាយ', 'reports', 56],
        ['expenses', 'expenses.add', 'Add Expense', 'បន្ថែមចំណាយ', 'reports', 57],
        ['expenses', 'expenses.edit', 'Edit Expense', 'កែប្រែចំណាយ', 'reports', 58],
        ['expenses', 'expenses.delete', 'Delete Expense', 'លុបចំណាយ', 'reports', 59],

        // --- Administration & Settings ---
        ['settings', 'settings.view', 'View Settings', 'មើលការកំណត់', 'settings', 60],
        ['settings', 'settings.edit', 'Edit Settings', 'កែប្រែការកំណត់', 'settings', 61],

        ['pos_devices', 'pos_devices.list', 'Access / List POS Devices', 'មើលបញ្ជីឧបករណ៍ POS', 'settings', 62],
        ['pos_devices', 'pos_devices.add', 'Register Device', 'ចុះឈ្មោះឧបករណ៍', 'settings', 63],
        ['pos_devices', 'pos_devices.edit', 'Edit Device', 'កែប្រែឧបករណ៍', 'settings', 64],
        ['pos_devices', 'pos_devices.block', 'Block / Unblock Device', 'បិទ ឬបើកឧបករណ៍', 'settings', 65],
        ['pos_devices', 'pos_devices.regen_key', 'Regenerate API Key', 'បង្កើតសោរសម្ងាត់ឡើងវិញ', 'settings', 66],
    ];

    $insPermStmt = $pdo->prepare("
        INSERT INTO permissions (module, code, label_en, label_km, area, sort_order)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            module = VALUES(module),
            label_en = VALUES(label_en),
            label_km = VALUES(label_km),
            area = VALUES(area),
            sort_order = VALUES(sort_order)
    ");

    foreach ($permissionsToSeed as $p) {
        if ($isSqlite) {
            $pdo->prepare("
                INSERT OR REPLACE INTO permissions (module, code, label_en, label_km, area, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute($p);
        } else {
            $insPermStmt->execute($p);
        }
    }
    migrateLog("Permissions seeded (" . count($permissionsToSeed) . " permissions).");

    // 8. Assign Permissions to Built-In Roles
    // Get all permissions mapped by code => id
    $allPerms = $pdo->query("SELECT code, id FROM permissions")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rolesMap = $pdo->query("SELECT code, id FROM roles")->fetchAll(PDO::FETCH_KEY_PAIR);

    $ownerId = $rolesMap['owner'] ?? null;
    $cashierId = $rolesMap['cashier'] ?? null;
    $washerId = $rolesMap['washer'] ?? null;
    $mechanicId = $rolesMap['mechanic'] ?? null;

    $insRp = $pdo->prepare($isSqlite ? "INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)" : "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

    // 8a. Owner gets ALL permissions
    if ($ownerId) {
        foreach ($allPerms as $code => $pid) {
            $insRp->execute([$ownerId, $pid]);
        }
        migrateLog("Owner role assigned all " . count($allPerms) . " permissions.");
    }

    // 8b. Cashier gets POS, Orders, Customers, Extras, Oil Care list, Vehicle History, Daily Close
    if ($cashierId) {
        $cashierPermCodes = [
            'pos.access', 'pos.create_order', 'pos.discount', 'pos.pay_order', 'pos.pay_later', 'pos.cancel_order', 'pos.receipt',
            'orders.list', 'orders.change_status', 'orders.change_crew', 'orders.cancel',
            'customers.list', 'customers.add', 'customers.edit',
            'vehicle_history.list',
            'oil_care.list',
            'extras.list',
            'daily_close.view', 'daily_close.close',
        ];
        foreach ($cashierPermCodes as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$cashierId, $allPerms[$code]]);
            }
        }
        migrateLog("Cashier role assigned " . count($cashierPermCodes) . " permissions.");
    }

    // 8c. Washer gets washer_jobs.view, washer_jobs.update
    if ($washerId) {
        $washerPermCodes = ['washer_jobs.view', 'washer_jobs.update'];
        foreach ($washerPermCodes as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$washerId, $allPerms[$code]]);
            }
        }
        migrateLog("Washer role assigned " . count($washerPermCodes) . " permissions.");
    }

    // 8d. Mechanic gets mechanic_jobs.view, mechanic_jobs.update, mechanic_jobs.notes, vehicle_history.list
    if ($mechanicId) {
        $mechanicPermCodes = ['mechanic_jobs.view', 'mechanic_jobs.update', 'mechanic_jobs.notes', 'vehicle_history.list'];
        foreach ($mechanicPermCodes as $code) {
            if (isset($allPerms[$code])) {
                $insRp->execute([$mechanicId, $allPerms[$code]]);
            }
        }
        migrateLog("Mechanic role assigned " . count($mechanicPermCodes) . " permissions.");
    }

    // 9. Record migration in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_roles']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_roles']);
    }

    migrateLog("Roles & Permissions migration completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $force = in_array('--force', $argv ?? [], true);
    migrateRoles($force);
}
