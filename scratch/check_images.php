<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SELECT id, name_en, name_km, image_path, is_active FROM services');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
