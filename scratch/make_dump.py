from core_db import get_settings if False else None
import subprocess

code = """
require_once 'config/app.php';
require_once 'core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%shop%' OR setting_key LIKE '%receipt%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['setting_key'] . ' => ' . $r['setting_value'] . PHP_EOL;
}
"""

with open('scratch/dump_db_settings.php', 'w', encoding='utf-8') as f:
    f.write('<?php\n' + code)
