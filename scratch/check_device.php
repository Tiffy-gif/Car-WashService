<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SELECT id, device_name, device_uid, api_key, status FROM pos_devices');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
