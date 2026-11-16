<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$apiKey = 'pos_sec_' . bin2hex(random_bytes(16));
$hash = hash('sha256', $apiKey);

// Delete if exists
$pdo->prepare("DELETE FROM pos_devices WHERE device_uid = 'POS-MAIN-01'")->execute();

$stmt = $pdo->prepare("INSERT INTO pos_devices (device_name, device_uid, api_key_hash, location, status) VALUES ('Main Counter Terminal', 'POS-MAIN-01', ?, 'Counter 1', 'active')");
$stmt->execute([$hash]);

echo json_encode(['apiKey' => $apiKey]);
