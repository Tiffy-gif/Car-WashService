<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $r['setting_key'] . ' => ' . $r['setting_value'] . PHP_EOL;
}
