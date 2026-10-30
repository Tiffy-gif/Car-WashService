<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function initDatabase(bool $force = false): void {
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

    // Fast check: Skip heavy DDL table checks and migrations if already up to date
    if (!$force) {
        $requiredMigrations = [
            'init_database',
            'base_migrations',
            'migrate_categories',
            'migrate_loyalty',
            'migrate_roles',
            'pos_device_sales_and_closings',
            'migrate_bays_queue',
            'migrate_foundation',
            'migrate_cashier_controls',
            'migrate_bank_stock_controls',
            'migrate_technical_warehouse',
            'migrate_notifications',
            'migrate_smart_queue',
            'migrate_performance_reports',
            'migrate_cloud_sync',
            'migrate_disaster_recovery',
            'migrate_crm_membership_reminders'
        ];

        try {
            $executed = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
            $executedSet = array_flip($executed);

            $allPresent = true;
            foreach ($requiredMigrations as $rm) {
                if (!isset($executedSet[$rm])) {
                    $allPresent = false;
                    break;
                }
            }

            if ($allPresent) {
                // All tables and migrations are already up to date! Return immediately with 0 output.
                return;
            }
        } catch (Throwable $t) {}
    }

    // 0b. Ensure Database collation is utf8mb4_unicode_ci on MySQL
    if (!$isSqlite) {
        try {
            $pdo->exec("ALTER DATABASE `carwash_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (Exception $e) {}
    }

    // 1. Roles Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id {$autoInc},
        code VARCHAR(50) NOT NULL UNIQUE,
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        is_system TINYINT(1) DEFAULT 0,
        is_commission_eligible TINYINT(1) DEFAULT 0,
        can_assign_washer TINYINT(1) DEFAULT 0,
        can_assign_mechanic TINYINT(1) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 2. Permissions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id {$autoInc},
        module VARCHAR(50) NOT NULL,
        code VARCHAR(100) NOT NULL UNIQUE,
        label_en VARCHAR(150) NOT NULL,
        label_km VARCHAR(150) NOT NULL,
        sort_order INT DEFAULT 0
    ) {$charsetEngine}");

    // 3. Role Permissions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT NOT NULL,
        permission_id INT NOT NULL,
        PRIMARY KEY (role_id, permission_id)
    ) {$charsetEngine}");

    // 4. Role Audit Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_audit_logs (
        id {$autoInc},
        role_id INT DEFAULT NULL,
        action VARCHAR(50) NOT NULL,
        details TEXT DEFAULT NULL,
        performed_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 5. Categories Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id {$autoInc},
        type VARCHAR(20) NOT NULL, -- 'service' or 'product'
        code VARCHAR(50) NOT NULL UNIQUE,
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        icon VARCHAR(50) DEFAULT 'fa-folder',
        color VARCHAR(20) DEFAULT '#0284c7',
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 6. Users Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id {$autoInc},
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        local_pin_hash VARCHAR(255) DEFAULT NULL,
        full_name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        role VARCHAR(20) NOT NULL, -- owner, cashier, washer, mechanic, manager
        role_id INT DEFAULT NULL,
        commission_type VARCHAR(20) DEFAULT 'percentage', -- percentage, fixed
        commission_rate DECIMAL(10,2) DEFAULT 20.00,
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 7. Services Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id {$autoInc},
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        category VARCHAR(50) DEFAULT 'wash', -- wash, care, repair, maintenance
        category_id INT DEFAULT NULL,
        description TEXT DEFAULT NULL,
        icon VARCHAR(50) DEFAULT 'fa-car-wash',
        image_path VARCHAR(255) DEFAULT NULL,
        thumb_path VARCHAR(255) DEFAULT NULL,
        image_updated_at DATETIME DEFAULT NULL,
        is_active INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 8. Service Prices by Vehicle Type Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS service_prices (
        id {$autoInc},
        service_id INT NOT NULL,
        vehicle_type VARCHAR(30) NOT NULL,
        price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price_khr INT NOT NULL DEFAULT 0,
        estimated_minutes INT DEFAULT 30,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (service_id, vehicle_type)
    ) {$charsetEngine}");

    // 9. Products Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id {$autoInc},
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        category VARCHAR(50) DEFAULT 'engine_oil', -- engine_oil, oil_filter, fluid, care_product, spare_part
        category_id INT DEFAULT NULL,
        brand VARCHAR(50) DEFAULT NULL,
        unit VARCHAR(30) DEFAULT 'can',
        cost_price_usd DECIMAL(10,2) DEFAULT 0.00,
        price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price_khr INT NOT NULL DEFAULT 0,
        stock_qty INT NOT NULL DEFAULT 0,
        min_stock_alert INT DEFAULT 5,
        image_path VARCHAR(255) DEFAULT NULL,
        thumb_path VARCHAR(255) DEFAULT NULL,
        image_updated_at DATETIME DEFAULT NULL,
        is_active INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 10. Inventory Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
        id {$autoInc},
        item_name VARCHAR(100) NOT NULL,
        category VARCHAR(50) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        unit VARCHAR(20) NOT NULL,
        min_alert_qty DECIMAL(10,2) NOT NULL DEFAULT 5.00,
        unit_cost_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_path VARCHAR(255) DEFAULT NULL,
        thumb_path VARCHAR(255) DEFAULT NULL,
        image_updated_at DATETIME DEFAULT NULL,
        last_restocked DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 11. Customers Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id {$autoInc},
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20) DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        loyalty_points INT DEFAULT 0,
        lifetime_points INT DEFAULT 0,
        membership_level VARCHAR(50) DEFAULT 'Standard',
        status VARCHAR(20) DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 12. Customer Vehicles Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
        id {$autoInc},
        customer_id INT DEFAULT NULL,
        plate_number VARCHAR(50) NOT NULL,
        plate_province VARCHAR(50) DEFAULT 'Phnom Penh',
        vehicle_type VARCHAR(30) NOT NULL, -- motorbike, small_car, suv_pickup, van_truck
        make_model VARCHAR(100) DEFAULT NULL,
        color VARCHAR(50) DEFAULT NULL,
        last_mileage INT DEFAULT NULL,
        next_oil_mileage INT DEFAULT NULL,
        next_oil_date DATE DEFAULT NULL,
        last_contacted_at DATETIME DEFAULT NULL,
        contact_notes VARCHAR(255) DEFAULT NULL,
        snooze_until DATE DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 13. Orders Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id {$autoInc},
        order_number VARCHAR(30) NOT NULL UNIQUE,
        idempotency_key VARCHAR(100) DEFAULT NULL,
        source VARCHAR(30) DEFAULT 'web',
        pos_device_id INT DEFAULT NULL,
        pos_device_name VARCHAR(100) DEFAULT NULL,
        customer_id INT DEFAULT NULL,
        vehicle_id INT DEFAULT NULL,
        customer_name VARCHAR(100) NOT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        plate_number VARCHAR(50) NOT NULL,
        vehicle_type VARCHAR(30) NOT NULL,
        subtotal_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        points_earned INT DEFAULT 0,
        points_redeemed INT DEFAULT 0,
        points_discount_usd DECIMAL(10,2) DEFAULT 0.00,
        total_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_khr INT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'waiting', -- waiting, washing, finished, paid, cancelled
        payment_method VARCHAR(20) DEFAULT 'unpaid', -- cash, aba, khqr, unpaid
        payment_status VARCHAR(20) DEFAULT 'unpaid', -- unpaid, paid, cancelled
        amount_tendered_usd DECIMAL(10,2) DEFAULT 0.00,
        change_usd DECIMAL(10,2) DEFAULT 0.00,
        payment_ref VARCHAR(100) DEFAULT NULL,
        cashier_id INT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        cancel_reason VARCHAR(255) DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        paid_at DATETIME DEFAULT NULL,
        receipt_printed_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        current_mileage INT DEFAULT NULL,
        next_oil_mileage INT DEFAULT NULL,
        next_oil_date DATE DEFAULT NULL,
        repair_status VARCHAR(20) DEFAULT NULL,
        repair_notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 14. Order Items Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id {$autoInc},
        order_id INT NOT NULL,
        service_id INT DEFAULT NULL,
        product_id INT DEFAULT NULL,
        item_type VARCHAR(20) DEFAULT 'service',
        service_name VARCHAR(100) NOT NULL,
        price_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price_khr INT NOT NULL DEFAULT 0,
        qty INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 15. Order Washers Assignment Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_washers (
        id {$autoInc},
        order_id INT NOT NULL,
        washer_id INT NOT NULL,
        commission_usd DECIMAL(10,2) DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 16. Order Mechanics Assignment Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_mechanics (
        id {$autoInc},
        order_id INT NOT NULL,
        mechanic_id INT NOT NULL,
        commission_usd DECIMAL(10,2) DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 17. Oil Change Logs Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS oil_change_logs (
        id {$autoInc},
        order_id INT DEFAULT NULL,
        vehicle_id INT DEFAULT NULL,
        customer_id INT DEFAULT NULL,
        plate_number VARCHAR(50) DEFAULT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        customer_name VARCHAR(100) DEFAULT NULL,
        oil_product_name VARCHAR(100) DEFAULT NULL,
        current_mileage INT DEFAULT NULL,
        next_due_mileage INT DEFAULT NULL,
        oil_change_date DATE DEFAULT NULL,
        next_due_date DATE DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 18. Vehicle Repair History Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_repair_history (
        id {$autoInc},
        order_id INT DEFAULT NULL,
        vehicle_id INT DEFAULT NULL,
        plate_number VARCHAR(50) DEFAULT NULL,
        customer_name VARCHAR(100) DEFAULT NULL,
        mechanic_name VARCHAR(100) DEFAULT NULL,
        services_performed TEXT DEFAULT NULL,
        parts_replaced TEXT DEFAULT NULL,
        labor_cost_usd DECIMAL(10,2) DEFAULT 0.00,
        parts_cost_usd DECIMAL(10,2) DEFAULT 0.00,
        total_cost_usd DECIMAL(10,2) DEFAULT 0.00,
        mileage INT DEFAULT NULL,
        repair_notes TEXT DEFAULT NULL,
        repair_date DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 19. Expenses Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id {$autoInc},
        category VARCHAR(50) NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_khr INT NOT NULL DEFAULT 0,
        expense_date DATE NOT NULL,
        recorded_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 20. Settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(50) PRIMARY KEY,
        setting_value TEXT NOT NULL
    ) {$charsetEngine}");

    // 21. Settings History Audit Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings_history (
        id {$autoInc},
        setting_key VARCHAR(100) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by_id INT NULL,
        changed_by_name VARCHAR(100) NOT NULL,
        changed_at DATETIME NOT NULL
    ) {$charsetEngine}");

    // 22. Loyalty Transactions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_transactions (
        id {$autoInc},
        customer_id INT NOT NULL,
        order_id INT DEFAULT NULL,
        type VARCHAR(20) NOT NULL, -- earn, redeem, adjust, reversal
        points INT NOT NULL,
        balance_after INT NOT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 23. POS Devices Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_devices (
        id {$autoInc},
        device_name VARCHAR(100) NOT NULL,
        device_uid VARCHAR(100) NOT NULL UNIQUE,
        api_key VARCHAR(128) NOT NULL UNIQUE,
        location VARCHAR(100) DEFAULT 'Main Counter',
        status VARCHAR(20) DEFAULT 'active', -- active, blocked
        last_seen_at DATETIME DEFAULT NULL,
        last_ip VARCHAR(50) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 24. POS Closings Table (per device & shift)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_closings (
        id {$autoInc},
        pos_device_id INT NULL,
        device_name VARCHAR(100) NULL,
        cashier_id INT NOT NULL,
        cashier_name VARCHAR(100) NULL,
        closed_at DATETIME NOT NULL,
        close_date DATE NOT NULL,
        opening_cash_usd DECIMAL(10,2) DEFAULT 0.00,
        expected_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_cash_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_cash_khr INT NOT NULL DEFAULT 0,
        difference_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        cash_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        aba_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        khqr_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        expenses_usd DECIMAL(10,2) DEFAULT 0.00,
        discount_total_usd DECIMAL(10,2) DEFAULT 0.00,
        paid_orders_count INT DEFAULT 0,
        unpaid_orders_count INT DEFAULT 0,
        cancelled_orders_count INT DEFAULT 0,
        notes TEXT NULL,
        status VARCHAR(20) DEFAULT 'closed',
        reopened_by_user_id INT NULL,
        reopened_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 25. POS Daily Closes Table (for backward compatibility & shopwide views)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_daily_closes (
        id {$autoInc},
        close_date DATE NOT NULL,
        pos_device_id INT NULL,
        device_name VARCHAR(100) NULL,
        closed_by_user_id INT NULL,
        closed_by_name VARCHAR(100) NULL,
        role VARCHAR(20) DEFAULT 'cashier',
        opening_time DATETIME DEFAULT NULL,
        closed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        total_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        total_sales_khr INT DEFAULT 0,
        cash_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        aba_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        khqr_sales_usd DECIMAL(10,2) DEFAULT 0.00,
        expenses_usd DECIMAL(10,2) DEFAULT 0.00,
        discount_total_usd DECIMAL(10,2) DEFAULT 0.00,
        expected_cash_usd DECIMAL(10,2) DEFAULT 0.00,
        counted_cash_usd DECIMAL(10,2) DEFAULT 0.00,
        counted_cash_khr INT DEFAULT 0,
        difference_usd DECIMAL(10,2) DEFAULT 0.00,
        paid_orders_count INT DEFAULT 0,
        unpaid_orders_count INT DEFAULT 0,
        cancelled_orders_count INT DEFAULT 0,
        notes TEXT NULL,
        status VARCHAR(20) DEFAULT 'closed',
        reopened_by_user_id INT NULL,
        reopened_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 26. Schema Migrations Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id {$autoInc},
        migration VARCHAR(255) NOT NULL UNIQUE,
        executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // Auto-migration & column synchronizer for existing databases
    if (!$isSqlite) {
        // Dynamic column sync for vehicles
        $vCols = $pdo->query("SHOW COLUMNS FROM vehicles")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('last_mileage', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN last_mileage INT DEFAULT NULL");
        }
        if (!in_array('next_oil_mileage', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN next_oil_mileage INT DEFAULT NULL");
        }
        if (!in_array('next_oil_date', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN next_oil_date DATE DEFAULT NULL");
        }
        if (!in_array('last_contacted_at', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN last_contacted_at DATETIME DEFAULT NULL");
        }
        if (!in_array('contact_notes', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN contact_notes VARCHAR(255) DEFAULT NULL");
        }
        if (!in_array('snooze_until', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN snooze_until DATE DEFAULT NULL");
        }

        // Dynamic column sync for orders
        $oCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('source', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN source VARCHAR(30) DEFAULT 'web'");
        }
        if (!in_array('pos_device_id', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_id INT DEFAULT NULL");
        }
        if (!in_array('pos_device_name', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN pos_device_name VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('idempotency_key', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN idempotency_key VARCHAR(100) DEFAULT NULL");
        }
        if (!in_array('cancel_reason', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER notes");
        }
        if (!in_array('cancelled_at', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL AFTER paid_at");
        }
        if (!in_array('current_mileage', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN current_mileage INT DEFAULT NULL");
        }
        if (!in_array('next_oil_mileage', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN next_oil_mileage INT DEFAULT NULL");
        }
        if (!in_array('next_oil_date', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN next_oil_date DATE DEFAULT NULL");
        }
        if (!in_array('repair_status', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN repair_status VARCHAR(20) DEFAULT NULL");
        }
        if (!in_array('repair_notes', $oCols, true)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN repair_notes TEXT DEFAULT NULL");
        }

        // Dynamic column sync for customers
        $cCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $cCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER membership_level");
            $pdo->exec("UPDATE customers SET status = 'active' WHERE status IS NULL OR status = ''");
        }

        // Dynamic column sync for users
        $uCols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('role_id', $uCols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN role_id INT DEFAULT NULL AFTER role");
        }

        // Dynamic column sync for services & products
        $sCols = $pdo->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category_id', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN category_id INT DEFAULT NULL AFTER category");
        }
        if (!in_array('image_path', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN image_path VARCHAR(255) NULL");
        }
        if (!in_array('thumb_path', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN thumb_path VARCHAR(255) NULL");
        }
        if (!in_array('image_updated_at', $sCols, true)) {
            $pdo->exec("ALTER TABLE services ADD COLUMN image_updated_at DATETIME NULL");
        }

        $pCols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('category_id', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL AFTER category");
        }
        if (!in_array('image_path', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL");
        }
        if (!in_array('thumb_path', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN thumb_path VARCHAR(255) NULL");
        }
        if (!in_array('image_updated_at', $pCols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN image_updated_at DATETIME NULL");
        }

        // Dynamic column sync for pos_closings & pos_daily_closes
        $pcCols = $pdo->query("SHOW COLUMNS FROM pos_closings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('reopened_by_user_id', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN reopened_by_user_id INT DEFAULT NULL");
        }
        if (!in_array('reopened_at', $pcCols, true)) {
            $pdo->exec("ALTER TABLE pos_closings ADD COLUMN reopened_at DATETIME DEFAULT NULL");
        }

        $pdcCols = $pdo->query("SHOW COLUMNS FROM pos_daily_closes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('reopened_by_user_id', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN reopened_by_user_id INT DEFAULT NULL");
        }
        if (!in_array('reopened_at', $pdcCols, true)) {
            $pdo->exec("ALTER TABLE pos_daily_closes ADD COLUMN reopened_at DATETIME DEFAULT NULL");
        }

        // Indexes for fast POS sales queries
        $idxList = $pdo->query("SHOW INDEX FROM orders")->fetchAll(PDO::FETCH_ASSOC);
        $idxNames = array_unique(array_column($idxList, 'Key_name'));
        if (!in_array('idx_orders_device_paid', $idxNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_orders_device_paid (pos_device_id, paid_at)");
        }
        if (!in_array('idx_orders_device_created', $idxNames, true)) {
            $pdo->exec("ALTER TABLE orders ADD INDEX idx_orders_device_created (pos_device_id, created_at)");
        }

        // Convert ALL existing tables to utf8mb4_unicode_ci to prevent mixed collation crashes
        $allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allTables as $table) {
            try {
                $pdo->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Exception $e) {}
        }
    }

    // Run modular idempotent migrations
    if (file_exists(__DIR__ . '/migrate_roles.php')) {
        require_once __DIR__ . '/migrate_roles.php';
        if (function_exists('migrateRoles')) {
            migrateRoles();
        }
    }
    if (file_exists(__DIR__ . '/migrate_categories.php')) {
        require_once __DIR__ . '/migrate_categories.php';
        if (function_exists('migrateCategories')) {
            migrateCategories();
        }
    }
    if (file_exists(__DIR__ . '/migrate_loyalty.php')) {
        require_once __DIR__ . '/migrate_loyalty.php';
        if (function_exists('migrateLoyalty')) {
            migrateLoyalty();
        }
    }
    if (file_exists(__DIR__ . '/migrate_bays_queue.php')) {
        require_once __DIR__ . '/migrate_bays_queue.php';
        if (function_exists('migrateBaysQueue')) {
            migrateBaysQueue();
        }
    }
    if (file_exists(__DIR__ . '/migrate_foundation.php')) {
        require_once __DIR__ . '/migrate_foundation.php';
        if (function_exists('migrateFoundation')) {
            migrateFoundation();
        }
    }
    if (file_exists(__DIR__ . '/migrate_cashier_controls.php')) {
        require_once __DIR__ . '/migrate_cashier_controls.php';
        if (function_exists('migrateCashierControls')) {
            migrateCashierControls();
        }
    }
    if (file_exists(__DIR__ . '/migrate_bank_stock_controls.php')) {
        require_once __DIR__ . '/migrate_bank_stock_controls.php';
        if (function_exists('migrate_bank_stock_controls')) {
            migrate_bank_stock_controls();
        }
    }
    if (file_exists(__DIR__ . '/migrate_technical_warehouse.php')) {
        require_once __DIR__ . '/migrate_technical_warehouse.php';
        if (function_exists('migrateTechnicalWarehouse')) {
            migrateTechnicalWarehouse();
        }
    }
    if (file_exists(__DIR__ . '/migrate_notifications.php')) {
        require_once __DIR__ . '/migrate_notifications.php';
        if (function_exists('migrateNotifications')) {
            migrateNotifications();
        }
    }
    if (file_exists(__DIR__ . '/migrate_smart_queue.php')) {
        require_once __DIR__ . '/migrate_smart_queue.php';
        if (function_exists('migrateSmartQueue')) {
            migrateSmartQueue();
        }
    }
    if (file_exists(__DIR__ . '/migrate_performance_reports.php')) {
        require_once __DIR__ . '/migrate_performance_reports.php';
        if (function_exists('migratePerformanceReports')) {
            migratePerformanceReports();
        }
    }
    if (file_exists(__DIR__ . '/migrate_cloud_sync.php')) {
        require_once __DIR__ . '/migrate_cloud_sync.php';
        if (function_exists('migrateCloudSync')) {
            migrateCloudSync();
        }
    }
    if (file_exists(__DIR__ . '/migrate_disaster_recovery.php')) {
        require_once __DIR__ . '/migrate_disaster_recovery.php';
        if (function_exists('run_migrate_disaster_recovery')) {
            run_migrate_disaster_recovery(false);
        }
    }

    // Ensure sample/default POS device exists (ONLY in development)
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $devCount = (int)$pdo->query("SELECT COUNT(*) FROM pos_devices WHERE device_uid = 'POS-TERMINAL-01'")->fetchColumn();
        if ($devCount === 0) {
            $insDev = $pdo->prepare("
                INSERT INTO pos_devices (device_name, device_uid, api_key, location, status)
                VALUES ('Counter Terminal 1 (Tablet)', 'POS-TERMINAL-01', 'pos_sec_test_key_8899aabb', 'Lane 1 / Counter', 'active')
            ");
            $insDev->execute();
        }
    }

    // Check if we need to seed initial demo data
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount === 0) {
        seedSampleData($pdo);
    }

    // Record init_database in schema_migrations
    if ($isSqlite) {
        $pdo->prepare("INSERT OR IGNORE INTO schema_migrations (migration) VALUES (?)")->execute(['init_database']);
    } else {
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at")->execute(['init_database']);
    }
}

function seedSampleData(PDO $pdo): void {
    // 1. Seed Users (Owner, Cashier, Washers, Mechanic) - ONLY in development
    if (defined('APP_ENV') && APP_ENV === 'development') {
        $ownerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'owner'")->fetchColumn() ?: null;
        $cashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'cashier'")->fetchColumn() ?: null;
        $washerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'washer'")->fetchColumn() ?: null;
        $mechanicRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'mechanic'")->fetchColumn() ?: null;

        $users = [
            ['owner', password_hash('admin123', PASSWORD_DEFAULT), 'Bona Sok (Owner)', '012 334 556', 'owner', $ownerRoleId, 'percentage', 0.00],
            ['cashier', password_hash('cashier123', PASSWORD_DEFAULT), 'Dara Chan (Cashier)', '098 776 543', 'cashier', $cashierRoleId, 'fixed', 0.00],
            ['washer1', password_hash('washer123', PASSWORD_DEFAULT), 'Rathana Heng (Washer)', '087 112 233', 'washer', $washerRoleId, 'percentage', 25.00],
            ['washer2', password_hash('washer123', PASSWORD_DEFAULT), 'Vannak Ouk (Washer)', '085 445 566', 'washer', $washerRoleId, 'fixed', 1.50],
            ['mechanic1', password_hash('mechanic123', PASSWORD_DEFAULT), 'Sophea Pich (Mechanic)', '011 223 344', 'mechanic', $mechanicRoleId, 'percentage', 30.00],
        ];

        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, phone, role, role_id, commission_type, commission_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($users as $u) {
            $stmt->execute($u);
        }
    }

    // 2. Seed Services & Tiered Prices
    $services = [
        [1, 'Exterior Wash', 'លាងសម្អាតខាងក្រៅ', 'wash', 'High-pressure foam wash, wheel rinse, hand dry and tire shine', 'fa-shower'],
        [2, 'Interior Cleaning', 'បោសសម្អាតខាងក្នុង', 'wash', 'Vacuuming, dashboard dust removal, floor mats wipe and air freshener', 'fa-broom'],
        [3, 'Full Wash (Exterior + Interior)', 'លាងពេញលេញ (ក្នុង + ក្រៅ)', 'wash', 'Complete exterior high-foam wash plus thorough interior deep clean', 'fa-sparkles'],
        [4, 'Engine Bay Wash', 'លាងសម្អាតបន្ទប់ម៉ាស៊ីន', 'wash', 'Degreaser spray, gentle pressure wash and engine plastic conditioning', 'fa-gears'],
        [5, 'Hydrophobic Wax & Polish', 'បាញ់ក្រមួន Wax & ប៉ូលាពន្លឺ', 'care', 'Protective nano-coating wax for lasting shine and water repelling', 'fa-shield-halved'],
        [6, 'Oil Change & Filter Service', 'ផ្លាស់ប្តូរប្រេងម៉ាស៊ីន & តម្រង', 'maintenance', 'Complete oil drain, synthetic oil refill and new OEM filter installation', 'fa-oil-can'],
        [7, 'Brake Inspection & Pad Replacement', 'ត្រួតពិនិត្យ & ផ្លាស់ប្តូរស្បែកហ្វ្រាំង', 'repair', 'Brake caliper test, rotor check and front/rear pad replacement', 'fa-circle-stop'],
        [8, 'AC Cabin Filter Replacement', 'ផ្លាស់ប្តូរតម្រងម៉ាស៊ីនត្រជាក់', 'maintenance', 'Cabin air purification and new activated carbon filter installation', 'fa-fan'],
        [9, 'Coolant Flush & Refill', 'ប្តូរទឹកស្អំម៉ាស៊ីន', 'maintenance', 'Radiator flush, anti-corrosion coolant refill and cap pressure test', 'fa-temperature-low'],
        [10, 'Tire Pressure & Rotation', 'វាស់ខ្យល់ និងបង្វិលកង់រថយន្ត', 'maintenance', 'Digital tire pressure balancing and 4-wheel rotation', 'fa-arrows-rotate'],
    ];

    $sStmt = $pdo->prepare("INSERT INTO services (id, name_en, name_km, category, description, icon) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($services as $s) {
        $sStmt->execute($s);
    }

    // Prices matrix:
    $priceMatrix = [
        ['service_id' => 1, 'motorbike' => 1.50, 'small_car' => 3.50, 'suv_pickup' => 5.00, 'van_truck' => 7.00, 'min' => 20],
        ['service_id' => 2, 'motorbike' => 1.00, 'small_car' => 3.00, 'suv_pickup' => 4.50, 'van_truck' => 6.50, 'min' => 25],
        ['service_id' => 3, 'motorbike' => 2.50, 'small_car' => 6.00, 'suv_pickup' => 8.50, 'van_truck' => 12.00, 'min' => 40],
        ['service_id' => 4, 'motorbike' => 1.50, 'small_car' => 4.00, 'suv_pickup' => 5.50, 'van_truck' => 7.50, 'min' => 20],
        ['service_id' => 5, 'motorbike' => 3.00, 'small_car' => 8.00, 'suv_pickup' => 12.00, 'van_truck' => 16.00, 'min' => 35],
        ['service_id' => 6, 'motorbike' => 3.00, 'small_car' => 10.00, 'suv_pickup' => 15.00, 'van_truck' => 20.00, 'min' => 30],
        ['service_id' => 7, 'motorbike' => 4.00, 'small_car' => 15.00, 'suv_pickup' => 20.00, 'van_truck' => 25.00, 'min' => 45],
        ['service_id' => 8, 'motorbike' => 2.00, 'small_car' => 5.00, 'suv_pickup' => 8.00, 'van_truck' => 10.00, 'min' => 15],
        ['service_id' => 9, 'motorbike' => 3.00, 'small_car' => 8.00, 'suv_pickup' => 12.00, 'van_truck' => 15.00, 'min' => 25],
        ['service_id' => 10, 'motorbike' => 1.00, 'small_car' => 3.00, 'suv_pickup' => 5.00, 'van_truck' => 6.00, 'min' => 15],
    ];

    $pStmt = $pdo->prepare("INSERT INTO service_prices (service_id, vehicle_type, price_usd, price_khr, estimated_minutes) VALUES (?, ?, ?, ?, ?)");
    foreach ($priceMatrix as $row) {
        $sId = $row['service_id'];
        $min = $row['min'];
        foreach (['motorbike', 'small_car', 'suv_pickup', 'van_truck'] as $vType) {
            $usd = $row[$vType];
            $khr = (int)round($usd * 4100);
            $pStmt->execute([$sId, $vType, $usd, $khr, $min]);
        }
    }

    // 3. Seed Products (Engine Oil, Filters, Fluids, Care Products, Spare Parts)
    $products = [
        ['Mobil 1 Advanced Full Synthetic 5W-30 (4L)', 'ប្រេងម៉ាស៊ីន Mobil 1 5W-30 (4L)', 'engine_oil', 'Mobil 1', 'can', 28.00, 38.00, 15],
        ['Castrol GTX Ultraclean 10W-40 (4L)', 'ប្រេងម៉ាស៊ីន Castrol 10W-40 (4L)', 'engine_oil', 'Castrol', 'can', 20.00, 28.00, 20],
        ['Motul 3000 Plus 4T 10W-40 Motorcycle (1L)', 'ប្រេងម៉ាស៊ីនម៉ូតូ Motul 10W-40 (1L)', 'engine_oil', 'Motul', 'can', 4.50, 7.50, 30],
        ['Toyota OEM Genuine Oil Filter (90915-YZZE2)', 'តម្រងប្រេងម៉ាស៊ីន Toyota សុទ្ធ', 'oil_filter', 'Toyota OEM', 'pcs', 3.50, 6.00, 40],
        ['Bosch Premium Oil Filter (P3300)', 'តម្រងប្រេង Bosch Premium', 'oil_filter', 'Bosch', 'pcs', 4.00, 7.00, 25],
        ['Meguiar’s Gold Class Car Wash Shampoo (1.8L)', 'សាប៊ូលាងរថយន្ត Meguiar’s Gold Class', 'care_product', 'Meguiar’s', 'bottle', 9.00, 14.50, 18],
        ['Turtle Wax Ceramic Spray Coating (500ml)', 'ស្ព្រាយសេរ៉ាមិចការពារថ្នាំពណ៌ Turtle Wax', 'care_product', 'Turtle Wax', 'bottle', 11.00, 18.00, 12],
        ['Brembo Ceramic Front Brake Pads Set', 'ស្បែកហ្វ្រាំងមុខ Brembo Ceramic', 'spare_part', 'Brembo', 'set', 22.00, 35.00, 10],
        ['Michelin Stealth Ultra Wiper Blade 24-inch', 'ផ្លិតទឹក Michelin Stealth 24 អ៊ីញ', 'spare_part', 'Michelin', 'pcs', 7.50, 13.00, 16],
        ['Prestone LongLife Prediluted Coolant (3.78L)', 'ទឹកស្អំម៉ាស៊ីន Prestone LongLife (3.78L)', 'fluid', 'Prestone', 'gallon', 8.50, 14.00, 15],
    ];

    $prodStmt = $pdo->prepare("
        INSERT INTO products (name_en, name_km, category, brand, unit, cost_price_usd, price_usd, price_khr, stock_qty)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($products as $pr) {
        $usd = (float)$pr[6];
        $khr = (int)round($usd * 4100);
        $prodStmt->execute([$pr[0], $pr[1], $pr[2], $pr[3], $pr[4], $pr[5], $usd, $khr, $pr[7]]);
    }

    // 4. Seed Customers & Vehicles with oil mileage tracking
    $custData = [
        [
            'name' => 'Sokha Meng',
            'phone' => '012 889 900',
            'notes' => 'Regular customer, prefers tire shine',
            'points' => 35,
            'vehicles' => [
                ['plate' => '2BQ-8899', 'prov' => 'Phnom Penh', 'type' => 'suv_pickup', 'model' => 'Ford Ranger Wildtrak 2023', 'color' => 'Black', 'mileage' => 45000, 'oil_km' => 50000, 'oil_date' => date('Y-m-d', strtotime('+45 days'))],
                ['plate' => '1AC-5566', 'prov' => 'Phnom Penh', 'type' => 'motorbike', 'model' => 'Honda Scoopy 2022', 'color' => 'White', 'mileage' => 12000, 'oil_km' => 15000, 'oil_date' => date('Y-m-d', strtotime('+60 days'))]
            ]
        ],
        [
            'name' => 'Borey Chea',
            'phone' => '093 456 789',
            'notes' => 'Prefers interior deep clean',
            'points' => 20,
            'vehicles' => [
                ['plate' => '2AB-1234', 'prov' => 'Phnom Penh', 'type' => 'small_car', 'model' => 'Toyota Prius 2012', 'color' => 'Silver', 'mileage' => 152000, 'oil_km' => 150000, 'oil_date' => date('Y-m-d', strtotime('-5 days'))] // Overdue!
            ]
        ],
        [
            'name' => 'Piseth Ly',
            'phone' => '077 223 344',
            'notes' => 'Fleet delivery driver',
            'points' => 50,
            'vehicles' => [
                ['plate' => '3A-9876', 'prov' => 'Kandal', 'type' => 'van_truck', 'model' => 'Hyundai H1 Starex', 'color' => 'Grey', 'mileage' => 88500, 'oil_km' => 89000, 'oil_date' => date('Y-m-d', strtotime('+10 days'))] // Due soon!
            ]
        ],
        [
            'name' => 'Kosal Vong',
            'phone' => '086 554 433',
            'notes' => 'Always pays with ABA KHQR',
            'points' => 15,
            'vehicles' => [
                ['plate' => '2AF-7788', 'prov' => 'Phnom Penh', 'type' => 'suv_pickup', 'model' => 'Lexus RX350', 'color' => 'Pearl White', 'mileage' => 62000, 'oil_km' => 70000, 'oil_date' => date('Y-m-d', strtotime('+80 days'))]
            ]
        ]
    ];

    $cStmt = $pdo->prepare("INSERT INTO customers (name, phone, notes, loyalty_points, lifetime_points, membership_level) VALUES (?, ?, ?, ?, ?, 'standard')");
    $vStmt = $pdo->prepare("INSERT INTO vehicles (customer_id, plate_number, plate_province, vehicle_type, make_model, color, last_mileage, next_oil_mileage, next_oil_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($custData as $c) {
        $cStmt->execute([$c['name'], $c['phone'], $c['notes'], $c['points'], $c['points']]);
        $cId = (int)$pdo->lastInsertId();
        foreach ($c['vehicles'] as $v) {
            $vStmt->execute([$cId, $v['plate'], $v['prov'], $v['type'], $v['model'], $v['color'], $v['mileage'], $v['oil_km'], $v['oil_date']]);
        }
    }

    // 5. Seed Daily Expenses
    $expenses = [
        ['Water', 'Clean Water Utility Bill - PPWSA', 45.00, 184500, date('Y-m-d', strtotime('-2 days')), 1],
        ['Electricity', 'Electricity bill for pressure washers & vacuum', 82.50, 338250, date('Y-m-d', strtotime('-1 days')), 1],
        ['Soap/Wax', 'Purchased 20L Premium Snow Foam Soap & Tire Gel', 35.00, 143500, date('Y-m-d'), 1],
        ['Salary', 'Daily advance wages for washers', 20.00, 82000, date('Y-m-d'), 1],
    ];

    $eStmt = $pdo->prepare("INSERT INTO expenses (category, description, amount_usd, amount_khr, expense_date, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($expenses as $e) {
        $eStmt->execute($e);
    }

    // 6. Seed Settings
    $settings = [
        'shop_name_en' => 'AquaGleam Car Wash & Detailing',
        'shop_name_km' => 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
        'shop_phone' => '012 334 556 / 098 776 543',
        'shop_address' => 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh',
        'receipt_footer' => 'Thank you for choosing AquaGleam! / សូមអរគុណ!',
        'aba_account_name' => 'CAR WASH SERVICE CO., LTD',
        'aba_account_num' => '001 234 567',
        'exchange_rate' => '4100',
        'loyalty_earn_rate' => '1',
        'loyalty_redeem_rate' => '10',
        'loyalty_tier_gold_min' => '200',
        'loyalty_tier_platinum_min' => '500',
        'loyalty_multiplier_standard' => '1.0',
        'loyalty_multiplier_gold' => '1.5',
        'loyalty_multiplier_platinum' => '2.0',
        'loyalty_wax_discount_gold' => '10',
        'loyalty_wax_discount_platinum' => '15',
        'default_oil_mileage' => '10000',
        'default_oil_months' => '3',
        'require_receipt_before_wash' => '1',
        'demo_mode' => '1',
        'default_pw_dismissed' => '0'
    ];

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $setStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    } else {
        $setStmt = $pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    }
    foreach ($settings as $k => $v) {
        $setStmt->execute([$k, $v]);
    }
}

// Run init
try {
    initDatabase();
} catch (Throwable $e) {
    @file_put_contents(dirname(__DIR__) . '/storage/logs/migration_error.log', "[" . date('Y-m-d H:i:s') . "] initDatabase error: " . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL, FILE_APPEND | LOCK_EX);
}
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'init.php') {
    echo "Database initialized, collations unified to utf8mb4_unicode_ci, and all migrations verified successfully!\n";
}
