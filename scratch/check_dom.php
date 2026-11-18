<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db;charset=utf8mb4', 'root', '');
$stmt = $pdo->query("SELECT u.username, u.role, r.name as role_name, p.slug FROM users u JOIN roles r ON u.role_id = r.id JOIN role_permissions rp ON r.id = rp.role_id JOIN permissions p ON rp.permission_id = p.id WHERE u.username = 'dom'");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
