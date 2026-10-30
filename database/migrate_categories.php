<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCategories(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_categories']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_categories' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Running Categories migration...");

    // 1. Create categories table
    if ($isSqlite) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id {$autoInc},
            type VARCHAR(20) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            name_km VARCHAR(100) NOT NULL,
            icon VARCHAR(50) DEFAULT 'fa-tag',
            color VARCHAR(30) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(type, code)
        )");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
            id {$autoInc},
            type VARCHAR(20) NOT NULL,
            code VARCHAR(50) NOT NULL,
            name_en VARCHAR(100) NOT NULL,
            name_km VARCHAR(100) NOT NULL,
            icon VARCHAR(50) DEFAULT 'fa-tag',
            color VARCHAR(30) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active INT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_type_code (type, code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    migrateLog("Table 'categories' ensured.");

    // Ensure updated_at column exists in categories table
    if ($isSqlite) {
        try {
            $pdo->exec("ALTER TABLE categories ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP");
        } catch (Exception $e) {}
    } else {
        $catCols = $pdo->query("SHOW COLUMNS FROM categories")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('updated_at', $catCols, true)) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER is_active");
            $pdo->exec("UPDATE categories SET updated_at = COALESCE(created_at, CURRENT_TIMESTAMP)");
            migrateLog("Added updated_at to categories.");
        }
    }

    // 2. Add nullable category_id column to services, products, expenses
    $tablesToAlter = [
        'services' => 'category',
        'products' => 'category',
        'expenses' => 'category',
    ];

    foreach ($tablesToAlter as $tbl => $afterCol) {
        if ($isSqlite) {
            try {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN category_id INT DEFAULT NULL");
                migrateLog("Added category_id to {$tbl}.");
            } catch (Exception $e) {
                // Already exists in SQLite
            }
        } else {
            $cols = $pdo->query("SHOW COLUMNS FROM {$tbl}")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('category_id', $cols, true)) {
                $afterClause = in_array($afterCol, $cols, true) ? "AFTER {$afterCol}" : "";
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN category_id INT DEFAULT NULL {$afterClause}");
                migrateLog("Added category_id to {$tbl}.");
            }
        }
    }

    // 3. Seed initial default categories
    $initialCategories = [
        // Services
        [
            'type' => 'service',
            'code' => 'wash',
            'name_en' => 'Car Wash & Detailing',
            'name_km' => 'លាងសម្អាត & ថែទាំរថយន្ត',
            'icon' => 'fa-shower',
            'color' => '#0284c7',
            'sort_order' => 1
        ],
        [
            'type' => 'service',
            'code' => 'repair',
            'name_en' => 'Basic Maintenance & Repair',
            'name_km' => 'ជួសជុល & ថែទាំទូទៅ',
            'icon' => 'fa-wrench',
            'color' => '#ea580c',
            'sort_order' => 2
        ],

        // Products
        [
            'type' => 'product',
            'code' => 'engine_oil',
            'name_en' => 'Engine Oils',
            'name_km' => 'ប្រេងម៉ាស៊ីន',
            'icon' => 'fa-oil-can',
            'color' => '#ea580c',
            'sort_order' => 1
        ],
        [
            'type' => 'product',
            'code' => 'oil_filter',
            'name_en' => 'Oil Filters',
            'name_km' => 'តម្រងប្រេង',
            'icon' => 'fa-filter',
            'color' => '#d97706',
            'sort_order' => 2
        ],
        [
            'type' => 'product',
            'code' => 'fluid',
            'name_en' => 'Fluids & Coolants',
            'name_km' => 'ទឹកស្អំ & ទឹកកញ្ចក់',
            'icon' => 'fa-bottle-droplet',
            'color' => '#0284c7',
            'sort_order' => 3
        ],
        [
            'type' => 'product',
            'code' => 'care_product',
            'name_en' => 'Car Care Products',
            'name_km' => 'ទំនិញថែទាំរថយន្ត',
            'icon' => 'fa-spray-can-sparkles',
            'color' => '#9333ea',
            'sort_order' => 4
        ],
        [
            'type' => 'product',
            'code' => 'spare_part',
            'name_en' => 'Spare Parts & Hardware',
            'name_km' => 'គ្រឿងបន្លាស់ & ផ្នែករឹង',
            'icon' => 'fa-gears',
            'color' => '#475569',
            'sort_order' => 5
        ],

        // Expenses
        [
            'type' => 'expense',
            'code' => 'Water',
            'name_en' => 'Water Utility',
            'name_km' => 'ទឹកស្អាត',
            'icon' => 'fa-water',
            'color' => '#0284c7',
            'sort_order' => 1
        ],
        [
            'type' => 'expense',
            'code' => 'Electricity',
            'name_en' => 'Electricity',
            'name_km' => 'អគ្គិសនី (EDC)',
            'icon' => 'fa-bolt',
            'color' => '#eab308',
            'sort_order' => 2
        ],
        [
            'type' => 'expense',
            'code' => 'Soap/Wax',
            'name_en' => 'Soap & Chemical Stock',
            'name_km' => 'សាប៊ូ & សារធាតុគីមី',
            'icon' => 'fa-soap',
            'color' => '#06b6d4',
            'sort_order' => 3
        ],
        [
            'type' => 'expense',
            'code' => 'Salary',
            'name_en' => 'Wages & Staff Advance',
            'name_km' => 'ប្រាក់ខែ & បុរេប្រទាន',
            'icon' => 'fa-money-bill-wave',
            'color' => '#16a34a',
            'sort_order' => 4
        ],
        [
            'type' => 'expense',
            'code' => 'Maintenance',
            'name_en' => 'Machine Repairs & Maintenance',
            'name_km' => 'ជួសជុលម៉ាស៊ីន & បរិក្ខារ',
            'icon' => 'fa-screwdriver-wrench',
            'color' => '#ea580c',
            'sort_order' => 5
        ],
        [
            'type' => 'expense',
            'code' => 'Other',
            'name_en' => 'Miscellaneous / Other',
            'name_km' => 'ចំណាយផ្សេងៗ',
            'icon' => 'fa-receipt',
            'color' => '#64748b',
            'sort_order' => 6
        ]
    ];

    if ($isSqlite) {
        $insCat = $pdo->prepare("
            INSERT OR IGNORE INTO categories (type, code, name_en, name_km, icon, color, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");
    } else {
        $insCat = $pdo->prepare("
            INSERT INTO categories (type, code, name_en, name_km, icon, color, sort_order, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                name_en = VALUES(name_en),
                name_km = VALUES(name_km),
                icon = VALUES(icon),
                color = VALUES(color),
                sort_order = VALUES(sort_order)
        ");
    }

    foreach ($initialCategories as $c) {
        $insCat->execute([
            $c['type'],
            $c['code'],
            $c['name_en'],
            $c['name_km'],
            $c['icon'],
            $c['color'],
            $c['sort_order']
        ]);
    }
    migrateLog("Default categories seeded/updated.");

    // 4. Backfill category_id for existing services, products, expenses
    $catRows = $pdo->query("SELECT id, type, code FROM categories")->fetchAll();
    $catMap = [];
    foreach ($catRows as $cr) {
        $catMap[$cr['type']][$cr['code']] = (int)$cr['id'];
    }

    // Backfill Services
    if (!empty($catMap['service'])) {
        $svcStmt = $pdo->prepare("UPDATE services SET category_id = ? WHERE (category = ? OR (category IS NULL AND ? = 'wash')) AND (category_id IS NULL OR category_id = 0)");
        foreach ($catMap['service'] as $code => $id) {
            $svcStmt->execute([$id, $code, $code]);
        }
        if (isset($catMap['service']['wash'])) {
            $pdo->prepare("UPDATE services SET category_id = ?, category = 'wash' WHERE category_id IS NULL OR category_id = 0")->execute([$catMap['service']['wash']]);
        }
        migrateLog("Backfilled category_id for services.");
    }

    // Backfill Products
    if (!empty($catMap['product'])) {
        $prodStmt = $pdo->prepare("UPDATE products SET category_id = ? WHERE category = ? AND (category_id IS NULL OR category_id = 0)");
        foreach ($catMap['product'] as $code => $id) {
            $prodStmt->execute([$id, $code]);
        }
        if (isset($catMap['product']['engine_oil'])) {
            $pdo->prepare("UPDATE products SET category_id = ?, category = 'engine_oil' WHERE category_id IS NULL OR category_id = 0")->execute([$catMap['product']['engine_oil']]);
        }
        migrateLog("Backfilled category_id for products.");
    }

    // Backfill Expenses
    if (!empty($catMap['expense'])) {
        $expStmt = $pdo->prepare("UPDATE expenses SET category_id = ? WHERE category = ? AND (category_id IS NULL OR category_id = 0)");
        foreach ($catMap['expense'] as $code => $id) {
            $expStmt->execute([$id, $code]);
        }
        foreach ($catMap['expense'] as $code => $id) {
            $pdo->prepare("UPDATE expenses SET category_id = ? WHERE LOWER(category) = LOWER(?) AND (category_id IS NULL OR category_id = 0)")->execute([$id, $code]);
        }
        if (isset($catMap['expense']['Other'])) {
            $pdo->prepare("UPDATE expenses SET category_id = ? WHERE category_id IS NULL OR category_id = 0")->execute([$catMap['expense']['Other']]);
        }
        migrateLog("Backfilled category_id for expenses.");
    }

    // 5. Record execution in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_categories']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['migrate_categories']);
    }

    migrateLog("Categories migration completed successfully!");
    return true;
}

// Run if called directly from CLI
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'migrate_categories.php') {
    $force = in_array('--force', $argv ?? [], true);
    migrateCategories($force);
}
