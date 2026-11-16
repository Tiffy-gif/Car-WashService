<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$pdo->prepare("DELETE FROM pos_devices WHERE device_uid = 'POS-MAIN-01'")->execute();
echo json_encode(['deleted' => true]);
