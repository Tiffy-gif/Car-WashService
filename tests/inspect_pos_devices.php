<?php
require_once __DIR__ . '/../core/Database.php';
$pdo = Database::getInstance();
$stmt = $pdo->query("DESCRIBE pos_devices");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT * FROM pos_devices");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
