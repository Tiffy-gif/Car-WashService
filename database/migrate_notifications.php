<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once __DIR__ . '/migrate_helpers.php';

function migrateNotifications(bool $force = false): bool {
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
    $checkStmt->execute(['migrate_notifications']);
    if (!$force && (int)$checkStmt->fetchColumn() > 0) {
        migrateLog("Migration 'migrate_notifications' has already been executed. Skipping.");
        return true;
    }

    migrateLog("Starting Notification System Migration...");

    // 1. Create system_notifications Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_notifications (
        id {$autoInc},
        notification_uid VARCHAR(50) NOT NULL UNIQUE,
        target_role VARCHAR(50) DEFAULT NULL,
        target_user_id INT DEFAULT NULL,
        category VARCHAR(50) NOT NULL, -- 'bank_transfer', 'job_queue', 'bay_alert', 'parts_request', 'cash_variance', 'system'
        title_en VARCHAR(150) NOT NULL,
        title_km VARCHAR(150) DEFAULT NULL,
        message_en TEXT NOT NULL,
        message_km TEXT DEFAULT NULL,
        action_url VARCHAR(255) DEFAULT NULL,
        sound_type VARCHAR(20) NOT NULL DEFAULT 'chime', -- 'chime', 'alert', 'none'
        reference_id VARCHAR(50) DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME DEFAULT NULL,
        read_by_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'system_notifications' ensured.");

    // 2. Create user_notification_settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_settings (
        user_id INT PRIMARY KEY,
        sound_muted TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) {$charsetEngine}");
    migrateLog("Table 'user_notification_settings' ensured.");

    // 3. Ensure index on notifications
    if (!$isSqlite) {
        try {
            $pdo->exec("CREATE INDEX idx_notif_target ON system_notifications (target_role, is_read, created_at)");
        } catch (Throwable $t) {}
    }

    // 4. Ensure TV Display settings exist
    $ensureSetting = function(string $key, string $val) use ($pdo) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $chk->execute([$key]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)")
                ->execute([$key, $val]);
        }
    };
    $ensureSetting('tv_display_enabled', '1');
    $ensureSetting('tv_display_code', 'tv-office-88');
    $ensureSetting('tv_display_language', 'both');

    // Record migration in schema_migrations
    $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)")->execute(['migrate_notifications']);
    migrateLog("Migration 'migrate_notifications' completed successfully.");

    return true;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running migrateNotifications...\n";
    $ok = migrateNotifications(true);
    echo $ok ? "Migration successful!\n" : "Migration failed!\n";
}
