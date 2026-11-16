<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$devices = $pdo->query("SELECT id, device_name, device_uid, status, LEFT(api_key_hash, 10) as hash_preview FROM pos_devices")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($devices, JSON_PRETTY_PRINT);
