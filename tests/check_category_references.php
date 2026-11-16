<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);

$tables = ['services', 'products', 'order_items', 'inventory'];
foreach ($tables as $t) {
    try {
        $count = $p->query("SELECT COUNT(*) FROM $t WHERE category_id IN (637, 638)")->fetchColumn();
        echo "$t: $count references\n";
    } catch(Exception $e) {
        echo "$t: " . $e->getMessage() . "\n";
    }
}
