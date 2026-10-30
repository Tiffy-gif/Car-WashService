<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

// 1. Create settings_history table
$isMysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');

if ($isMysql) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        changed_by_id INT NULL,
        changed_by_name VARCHAR(100) NOT NULL,
        changed_at DATETIME NOT NULL,
        INDEX idx_key (setting_key),
        INDEX idx_time (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT NOT NULL,
        old_value TEXT,
        new_value TEXT,
        changed_by_id INTEGER,
        changed_by_name TEXT NOT NULL,
        changed_at DATETIME NOT NULL
    )");
}

// 2. Ensure default settings exist
$defaultSettings = [
    'shop_name_en'        => 'AquaGleam Car Wash & Detailing',
    'shop_name_km'        => 'អាខ្វាហ្គ្លីម សេវាកម្មលាងរថយន្ត និងម៉ូតូទំនើប',
    'shop_phone'          => '012 334 556 / 098 776 543',
    'shop_address'        => 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh',
    'shop_logo'           => '',
    'receipt_footer'      => 'Thank you for choosing AquaGleam! / សូមអរគុណ!',
    'receipt_show_logo'   => '1',
    'receipt_language'    => 'both',
    'exchange_rate'       => '4100',
    'aba_account_name'    => 'CAR WASH SERVICE CO., LTD',
    'aba_account_num'     => '001 234 567',
    'khqr_image'          => '',
    'default_oil_mileage' => '10000',
    'default_oil_months'  => '3',
    'telegram_bot_token'  => '',
    'telegram_chat_id'    => '',
    'default_language'    => 'en',
    'demo_mode'           => '1',
    'app_timezone'        => 'Asia/Phnom_Penh',
    'default_pw_dismissed'=> '0',
];

if ($isMysql) {
    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = setting_value");
} else {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
}

foreach ($defaultSettings as $key => $val) {
    $stmt->execute([$key, $val]);
}

if (php_sapi_name() === 'cli') {
    echo "Settings table and history initialized successfully.\n";
}
