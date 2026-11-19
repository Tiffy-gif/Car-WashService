<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%shop%' OR setting_key LIKE '%receipt%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['setting_key'] . ' = ' . $r['setting_value'] . PHP_EOL;
}
