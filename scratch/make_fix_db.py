require_once = None
import subprocess

php_code = """<?php
require_once 'config/app.php';
require_once 'core/Database.php';

$pdo = Database::getInstance();

$keys = [
    'shop_address' => 'St. 271, Sangkat Boeung Tumpun, Khan Mean Chey, Phnom Penh',
    'shop_phone' => '012 334 556 / 098 776 543'
];

foreach ($keys as $k => $defVal) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$k]);
    $val = $stmt->fetchColumn();
    if ($val === false) {
        $ins = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
        $ins->execute([$k, $defVal]);
        echo "Inserted $k: $defVal\\n";
    } elseif (trim((string)$val) === '') {
        $upd = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $upd->execute([$defVal, $k]);
        echo "Updated empty $k to: $defVal\\n";
    } else {
        echo "Existing $k: $val\\n";
    }
}
"""

with open('scratch/fix_shop_info_db.php', 'w', encoding='utf-8') as f:
    f.write(php_code)
