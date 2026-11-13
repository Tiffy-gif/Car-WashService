<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT id, username, full_name, role, pin FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
