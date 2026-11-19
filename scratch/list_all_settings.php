<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key ASC');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['setting_key'] . ' => ' . mb_substr($row['setting_value'], 0, 50) . PHP_EOL;
}
