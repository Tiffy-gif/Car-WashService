<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migratePerformanceReports(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_performance_reports']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_performance_reports' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Performance Dashboard, Timecards & Reports Migration...");

    // 1. Create staff_timecards table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_timecards (
        id {$autoInc},
        timecard_uid VARCHAR(64) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        clock_in DATETIME NOT NULL,
        clock_out DATETIME DEFAULT NULL,
        total_hours DECIMAL(6,2) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        notes TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'staff_timecards' verified.");

    // Add indexes if not present
    if (!$isSqlite) {
        try {
            $pdo->exec("ALTER TABLE staff_timecards ADD INDEX idx_st_user_id (user_id)");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE staff_timecards ADD INDEX idx_st_clock_in (clock_in)");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE staff_timecards ADD INDEX idx_st_status (status)");
        } catch (Exception $e) {}
    }

    // 2. Create timecard_corrections table
    $pdo->exec("CREATE TABLE IF NOT EXISTS timecard_corrections (
        id {$autoInc},
        timecard_id INT NOT NULL,
        user_id INT NOT NULL,
        original_clock_in DATETIME NOT NULL,
        original_clock_out DATETIME DEFAULT NULL,
        corrected_clock_in DATETIME NOT NULL,
        corrected_clock_out DATETIME NOT NULL,
        reason TEXT NOT NULL,
        corrected_by INT NOT NULL,
        corrected_by_name VARCHAR(100) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'timecard_corrections' verified.");

    if (!$isSqlite) {
        try {
            $pdo->exec("ALTER TABLE timecard_corrections ADD INDEX idx_tc_timecard_id (timecard_id)");
        } catch (Exception $e) {}
        try {
            $pdo->exec("ALTER TABLE timecard_corrections ADD INDEX idx_tc_user_id (user_id)");
        } catch (Exception $e) {}
    }

    // 3. Register permissions for performance & timecards
    $newPermissions = [
        ['timecards', 'timecards.manage', 'Manage Staff Timecards & Corrections', 'គ្រប់គ្រងវត្តមានបុគ្គលិក និងកែសម្រួលម៉ោង', 75],
        ['reports', 'reports.performance', 'View Performance & KPI Analytics', 'មើលរបាយការណ៍ និងសូចនាករផលិតភាព', 76],
    ];

    $permCheck = $pdo->prepare("SELECT id FROM permissions WHERE code = ?");
    $permInsert = $pdo->prepare("INSERT INTO permissions (module, code, label_en, label_km, sort_order) VALUES (?, ?, ?, ?, ?)");

    foreach ($newPermissions as $p) {
        $permCheck->execute([$p[1]]);
        if (!$permCheck->fetchColumn()) {
            $permInsert->execute([$p[0], $p[1], $p[2], $p[3], $p[4]]);
        }
    }

    // Assign to owner and manager roles
    $roleStmt = $pdo->query("SELECT id, code FROM roles WHERE code IN ('owner', 'manager')");
    $targetRoles = $roleStmt->fetchAll(PDO::FETCH_ASSOC);

    $linkCheck = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission_id = ?");
    $linkInsert = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($targetRoles as $r) {
        foreach ($newPermissions as $p) {
            $pId = $pdo->query("SELECT id FROM permissions WHERE code = " . $pdo->quote($p[1]))->fetchColumn();
            if ($pId) {
                $linkCheck->execute([$r['id'], $pId]);
                if ((int)$linkCheck->fetchColumn() === 0) {
                    $linkInsert->execute([$r['id'], $pId]);
                }
            }
        }
    }
    migrateLog("Permissions for timecards & performance analytics registered.");

    // 4. Default settings
    $defaultSettings = [
        'cars_per_labor_hour_target' => '2.0',
        'shop_daily_open_hours' => '10.0',
        'leaderboard_enabled' => '1',
        'leaderboard_metrics' => json_encode(['cars_per_hour', 'jobs_completed', 'efficiency_factor'], JSON_UNESCAPED_UNICODE),
        'leaderboard_visible_roles' => json_encode(['owner', 'manager'], JSON_UNESCAPED_UNICODE)
    ];

    $checkSettingStmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $insertSettingStmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");

    foreach ($defaultSettings as $sKey => $sVal) {
        $checkSettingStmt->execute([$sKey]);
        if ((int)$checkSettingStmt->fetchColumn() === 0) {
            $insertSettingStmt->execute([$sKey, $sVal]);
        }
    }
    migrateLog("Default Performance & Leaderboard settings seeded.");

    // 5. Record completed migration
    if ($isSqlite) {
        $recStmt = $pdo->prepare("INSERT OR REPLACE INTO schema_migrations (migration, executed_at) VALUES (?, CURRENT_TIMESTAMP)");
    } else {
        $recStmt = $pdo->prepare("INSERT INTO schema_migrations (migration, executed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE executed_at = NOW()");
    }
    $recStmt->execute(['migrate_performance_reports']);
    migrateLog("Migration 'migrate_performance_reports' recorded in schema_migrations.");

    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Performance Dashboard & Reports Migration via CLI...\n";
    $success = migratePerformanceReports(true);
    echo $success ? "Migration completed successfully!\n" : "Migration encountered an issue.\n";
}
