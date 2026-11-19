<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'shop_logo'");
$stmt->execute(['public/uploads/logo_1793680351_4c71ab78.png']);
echo "Updated shop_logo successfully. Rows affected: " . $stmt->rowCount() . PHP_EOL;
