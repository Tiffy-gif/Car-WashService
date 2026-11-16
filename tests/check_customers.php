<?php
require_once __DIR__ . '/../core/Database.php';
$pdo = Database::getInstance();
$stmt = $pdo->query("DESCRIBE customers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt2 = $pdo->query("SELECT * FROM customers LIMIT 3");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
$stmt3 = $pdo->query("SELECT * FROM vehicles LIMIT 3");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
