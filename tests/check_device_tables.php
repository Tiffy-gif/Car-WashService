<?php
require_once __DIR__ . '/../core/Database.php';
$pdo = Database::getInstance();
$stmt = $pdo->query("SHOW TABLES LIKE '%pos%'");
echo "Tables matching 'pos':\n";
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt2 = $pdo->query("SHOW TABLES LIKE '%device%'");
echo "\nTables matching 'device':\n";
print_r($stmt2->fetchAll(PDO::FETCH_COLUMN));

$stmt3 = $pdo->query("SHOW TABLES LIKE '%setting%'");
echo "\nTables matching 'setting':\n";
print_r($stmt3->fetchAll(PDO::FETCH_COLUMN));
