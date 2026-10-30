<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateCrmMembershipReminders(bool $force = false): bool {
    $pdo = Database::getInstance();
    $driver = Database::getDriver();
    $isSqlite = ($driver === 'sqlite');
    $autoInc = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $charsetEngine = $isSqlite ? '' : 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    // 0. Check if already executed
    $check = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE migration = ?");
    $check->execute(['migrate_crm_membership_reminders']);
    if (!$force && (int)$check->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_crm_membership_reminders' already executed. Skipping.");
        return true;
    }

    migrateLog("Starting CRM, Memberships, Reminders & Upsell Rules Migration...");

    // 1. membership_tiers
    $pdo->exec("CREATE TABLE IF NOT EXISTS membership_tiers (
        id {$autoInc},
        tier_code VARCHAR(50) NOT NULL UNIQUE,
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        monthly_fee_usd DECIMAL(10,2) DEFAULT 0.00,
        included_services JSON DEFAULT NULL,
        discount_percent INT DEFAULT 0,
        priority_level VARCHAR(10) DEFAULT 'P0',
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 2. membership_usages
    $pdo->exec("CREATE TABLE IF NOT EXISTS membership_usages (
        id {$autoInc},
        customer_id INT NOT NULL,
        vehicle_id INT DEFAULT NULL,
        plate_number VARCHAR(50) DEFAULT NULL,
        tier_code VARCHAR(50) NOT NULL,
        service_id INT NOT NULL,
        order_id INT DEFAULT NULL,
        usage_month VARCHAR(7) NOT NULL,
        visit_date DATE NOT NULL,
        is_free TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 3. loyalty_reward_rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS loyalty_reward_rules (
        id {$autoInc},
        rule_uid VARCHAR(64) NOT NULL UNIQUE,
        name_en VARCHAR(100) NOT NULL,
        name_km VARCHAR(100) NOT NULL,
        trigger_type VARCHAR(50) NOT NULL,
        trigger_threshold INT NOT NULL,
        trigger_period VARCHAR(20) DEFAULT 'month',
        reward_type VARCHAR(50) NOT NULL,
        reward_item_id INT DEFAULT NULL,
        reward_item_name VARCHAR(100) NOT NULL,
        reward_value_usd DECIMAL(10,2) DEFAULT 0.00,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 4. crm_message_queue (Offline-resilient Message Events)
    $pdo->exec("CREATE TABLE IF NOT EXISTS crm_message_queue (
        id {$autoInc},
        event_uid VARCHAR(64) NOT NULL UNIQUE,
        customer_id INT NOT NULL,
        vehicle_id INT DEFAULT NULL,
        plate_number VARCHAR(50) NOT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        reminder_type VARCHAR(50) NOT NULL,
        title VARCHAR(255) NOT NULL,
        message_body TEXT NOT NULL,
        metadata_json TEXT DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'queued',
        is_synced TINYINT(1) DEFAULT 0,
        synced_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME DEFAULT NULL
    ) {$charsetEngine}");

    // 5. upsell_rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS upsell_rules (
        id {$autoInc},
        rule_uid VARCHAR(64) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        trigger_service_id INT DEFAULT NULL,
        trigger_category VARCHAR(50) DEFAULT NULL,
        trigger_vehicle_type VARCHAR(50) DEFAULT NULL,
        trigger_min_mileage INT DEFAULT NULL,
        offer_service_id INT DEFAULT NULL,
        offer_product_id INT DEFAULT NULL,
        offer_price_usd DECIMAL(10,2) DEFAULT NULL,
        offer_headline_en VARCHAR(255) NOT NULL,
        offer_headline_km VARCHAR(255) DEFAULT NULL,
        offer_description_en TEXT DEFAULT NULL,
        offer_description_km TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 6. upsell_logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS upsell_logs (
        id {$autoInc},
        log_uid VARCHAR(64) NOT NULL UNIQUE,
        rule_id INT NOT NULL,
        rule_name VARCHAR(150) NOT NULL,
        cashier_id INT NOT NULL,
        cashier_name VARCHAR(100) NOT NULL,
        order_id INT DEFAULT NULL,
        customer_id INT DEFAULT NULL,
        vehicle_id INT DEFAULT NULL,
        plate_number VARCHAR(50) DEFAULT NULL,
        status VARCHAR(20) NOT NULL,
        reason_declined VARCHAR(255) DEFAULT NULL,
        offered_at DATETIME NOT NULL,
        action_at DATETIME DEFAULT NULL,
        is_synced TINYINT(1) DEFAULT 0,
        synced_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");

    // 7. Alter customers table for tier linking & message opt-in
    if (!$isSqlite) {
        $custCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('membership_tier_id', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN membership_tier_id INT DEFAULT NULL");
        }
        if (!in_array('membership_tier_code', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN membership_tier_code VARCHAR(50) DEFAULT 'standard'");
        }
        if (!in_array('membership_started_at', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN membership_started_at DATE DEFAULT NULL");
        }
        if (!in_array('membership_expires_at', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN membership_expires_at DATE DEFAULT NULL");
        }
        if (!in_array('message_opt_in', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN message_opt_in TINYINT(1) DEFAULT 1");
        }
        if (!in_array('message_opt_in_updated_at', $custCols, true)) {
            $pdo->exec("ALTER TABLE customers ADD COLUMN message_opt_in_updated_at DATETIME DEFAULT NULL");
        }

        // Alter vehicles table for direct tier linking if needed
        $vCols = $pdo->query("SHOW COLUMNS FROM vehicles")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('membership_tier_id', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN membership_tier_id INT DEFAULT NULL");
        }
        if (!in_array('membership_tier_code', $vCols, true)) {
            $pdo->exec("ALTER TABLE vehicles ADD COLUMN membership_tier_code VARCHAR(50) DEFAULT NULL");
        }
    }

    // 8. Seed Default Membership Tiers
    $defaultTiers = [
        [
            'standard', 'Standard Member', 'សមាជិកស្តង់ដារ', 0.00,
            json_encode([]), 0, 'P3'
        ],
        [
            'silver', 'Silver Member', 'សមាជិកប្រាក់ (Silver)', 19.00,
            json_encode([
                ['service_id' => 1, 'service_code' => 'basic_wash', 'service_name' => 'Exterior Wash', 'limit_type' => 'unlimited']
            ]), 5, 'P1'
        ],
        [
            'gold', 'Gold VIP Member', 'សមាជិកមាស (Gold VIP)', 39.00,
            json_encode([
                ['service_id' => 1, 'service_code' => 'basic_wash', 'service_name' => 'Exterior Wash', 'limit_type' => 'unlimited'],
                ['service_id' => 3, 'service_code' => 'full_wash', 'service_name' => 'Full Wash (Exterior + Interior)', 'limit_type' => 'monthly', 'limit_qty' => 2]
            ]), 10, 'P0'
        ],
        [
            'platinum', 'Platinum Fleet Member', 'សមាជិកផ្លាទីន (Platinum)', 69.00,
            json_encode([
                ['service_id' => 1, 'service_code' => 'basic_wash', 'service_name' => 'Exterior Wash', 'limit_type' => 'unlimited'],
                ['service_id' => 3, 'service_code' => 'full_wash', 'service_name' => 'Full Wash (Exterior + Interior)', 'limit_type' => 'unlimited'],
                ['service_id' => 5, 'service_code' => 'wax_polish', 'service_name' => 'Hydrophobic Wax & Polish', 'limit_type' => 'monthly', 'limit_qty' => 1]
            ]), 15, 'P0'
        ]
    ];

    $tierStmt = $pdo->prepare("
        INSERT INTO membership_tiers (tier_code, name_en, name_km, monthly_fee_usd, included_services, discount_percent, priority_level)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name_en = VALUES(name_en),
            name_km = VALUES(name_km),
            monthly_fee_usd = VALUES(monthly_fee_usd),
            included_services = VALUES(included_services),
            discount_percent = VALUES(discount_percent),
            priority_level = VALUES(priority_level)
    ");
    foreach ($defaultTiers as $t) {
        $tierStmt->execute($t);
    }

    // 9. Seed Default Loyalty Reward Rules (Cloud synced)
    $rewardRules = [
        [
            'RULE-VISIT-5-AIR-FRESHENER',
            '5th visit this month: free air freshener',
            'ការមកដល់លើកទី 5 ក្នុងខែនេះ៖ ទទួលបានទឹកក្រអូបរថយន្តដោយឥតគិតថ្លៃ',
            'monthly_visit_count',
            5,
            'month',
            'free_product',
            10, // California Scents Car Air Freshener Can
            'California Scents Car Air Freshener Can',
            4.00,
            1
        ]
    ];

    $rrStmt = $pdo->prepare("
        INSERT INTO loyalty_reward_rules (rule_uid, name_en, name_km, trigger_type, trigger_threshold, trigger_period, reward_type, reward_item_id, reward_item_name, reward_value_usd, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            name_en = VALUES(name_en),
            name_km = VALUES(name_km),
            trigger_type = VALUES(trigger_type),
            trigger_threshold = VALUES(trigger_threshold),
            reward_type = VALUES(reward_type),
            reward_item_id = VALUES(reward_item_id),
            reward_item_name = VALUES(reward_item_name),
            reward_value_usd = VALUES(reward_value_usd),
            is_active = VALUES(is_active)
    ");
    foreach ($rewardRules as $rr) {
        $rrStmt->execute($rr);
    }

    // 10. Seed Default Upsell Rules (Cloud synced)
    $upsellSeed = [
        [
            'UPSELL-SUV-PREMIUM-WASH',
            'Basic Wash on an SUV: offer Premium Wash for $5',
            1, // Trigger on Service 1 (Exterior Wash)
            'wash',
            'suv_pickup',
            null,
            3, // Offer Service 3 (Full Wash Exterior + Interior)
            null,
            5.00,
            'Special SUV Upgrade: Add Interior Detailing for only $5.00!',
            'ការដំឡើងពិសេសសម្រាប់ SUV៖ បន្ថែមការសម្អាតខាងក្នុងត្រឹមតែ $5.00!',
            'Upgrade this SUV to Full Wash (Exterior + Interior) at the exclusive promo rate of $5.00 (Standard: $8.50).',
            'ដំឡើងការលាងរថយន្ត SUV នេះទៅកញ្ចប់ពេញលេញ (ក្រៅ + ក្នុង) ក្នុងតម្លៃពិសេស $5.00។',
            1
        ],
        [
            'UPSELL-HIGH-MILEAGE-SYNTHETIC',
            'Conventional oil on a car over 75,000 km: recommend synthetic',
            null,
            'engine_oil',
            null,
            75000,
            null,
            2, // Offer Product 2 (Mobil 1 ESP 5W-30 Advanced Synthetic 4L)
            42.00,
            'High Mileage Care (>75,000 km): Upgrade to Full Synthetic Oil!',
            'ការថែទាំចម្ងាយឆ្ងាយ (>75,000 គ.ម)៖ ដំឡើងទៅប្រើប្រេងសំយោគពេញលេញ!',
            'Engines over 75,000 km benefit from advanced synthetic protection against wear, sludge, and heat breakdown.',
            'ម៉ាស៊ីនដែលបើកបរលើសពី 75,000 គីឡូម៉ែត្រ ត្រូវការប្រេងសំយោគពេញលេញដើម្បីការពារការសឹករិចរិល។',
            1
        ]
    ];

    $upStmt = $pdo->prepare("
        INSERT INTO upsell_rules (
            rule_uid, name, trigger_service_id, trigger_category,
            trigger_vehicle_type, trigger_min_mileage, offer_service_id,
            offer_product_id, offer_price_usd, offer_headline_en,
            offer_headline_km, offer_description_en, offer_description_km, is_active
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?
        )
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            trigger_service_id = VALUES(trigger_service_id),
            trigger_category = VALUES(trigger_category),
            trigger_vehicle_type = VALUES(trigger_vehicle_type),
            trigger_min_mileage = VALUES(trigger_min_mileage),
            offer_service_id = VALUES(offer_service_id),
            offer_product_id = VALUES(offer_product_id),
            offer_price_usd = VALUES(offer_price_usd),
            offer_headline_en = VALUES(offer_headline_en),
            offer_headline_km = VALUES(offer_headline_km),
            offer_description_en = VALUES(offer_description_en),
            offer_description_km = VALUES(offer_description_km),
            is_active = VALUES(is_active)
    ");
    foreach ($upsellSeed as $u) {
        $upStmt->execute($u);
    }

    // 11. Register CRM & Upsell Permissions
    $perms = [
        ['memberships.view', 'View Memberships & Tiers', 'មើលកម្រិតសមាជិក', 'crm'],
        ['memberships.manage', 'Manage Memberships & Limits', 'គ្រប់គ្រងកម្រិតសមាជិក', 'crm'],
        ['crm.reminders', 'View & Manage Due Reminders', 'មើលនិងគ្រប់គ្រងសាររំលឹក', 'crm'],
        ['crm.opt_in', 'Manage Customer Message Opt-In', 'គ្រប់គ្រងការយល់ព្រមទទួលសារ', 'crm'],
        ['upsells.view', 'View Upsell Reports & Conversion', 'មើលរបាយការណ៍ Upsell', 'reports'],
        ['upsells.manage', 'Configure Upsell Rules', 'កំណត់លក្ខខណ្ឌ Upsell', 'settings'],
    ];

    $insPerm = $pdo->prepare("
        INSERT INTO permissions (code, label_en, label_km, module)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label_en = VALUES(label_en), label_km = VALUES(label_km), module = VALUES(module)
    ");
    foreach ($perms as $p) {
        $insPerm->execute($p);
    }

    // Assign to Owner & Manager
    $ownerRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'owner'")->fetchColumn();
    $mgrRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'manager'")->fetchColumn();
    $cashierRoleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'cashier'")->fetchColumn();

    $assignRole = $pdo->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
    foreach ($perms as $p) {
        $pId = (int)$pdo->query("SELECT id FROM permissions WHERE code = '{$p[0]}'")->fetchColumn();
        if ($pId > 0) {
            if ($ownerRoleId > 0) $assignRole->execute([$ownerRoleId, $pId]);
            if ($mgrRoleId > 0) $assignRole->execute([$mgrRoleId, $pId]);
            // Cashier can view memberships & opt-in
            if ($cashierRoleId > 0 && in_array($p[0], ['memberships.view', 'crm.opt_in'], true)) {
                $assignRole->execute([$cashierRoleId, $pId]);
            }
        }
    }

    // 12. Record migration in schema_migrations
    $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?) ON DUPLICATE KEY UPDATE executed_at = executed_at");
    $stmt->execute(['migrate_crm_membership_reminders']);

    migrateLog("CRM, Memberships, Reminders & Upsell Rules Migration completed successfully!");
    return true;
}

if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'migrate_crm_membership_reminders.php') {
    migrateCrmMembershipReminders(true);
    echo "CRM, Memberships, Reminders & Upsell Rules migration executed successfully via CLI.\n";
}
