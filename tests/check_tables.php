<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);
$tables = $p->query("SHOW TABLES LIKE '%cat%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
$allTables = $p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($allTables);
