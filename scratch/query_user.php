<?php
require_once __DIR__ . '/../app/core/Database.php';
$db = Database::getInstance()->getConnection();
$stmt = $db->query('SELECT id, username, role, status FROM users WHERE username = "dona"');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
