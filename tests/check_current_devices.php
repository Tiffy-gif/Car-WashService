<?php
require_once __DIR__ . '/../core/Database.php';
$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT id, device_name, device_uid, status FROM pos_devices");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
