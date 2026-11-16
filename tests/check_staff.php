<?php
require_once __DIR__ . '/../core/Database.php';
$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT id, username, role, status FROM users");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
