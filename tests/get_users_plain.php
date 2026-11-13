<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SELECT id, username, full_name, role FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
