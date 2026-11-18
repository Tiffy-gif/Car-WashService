<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);
$cats = $p->query("SELECT id, name_en, name_km, code, type, is_active FROM categories WHERE type = 'service'")->fetchAll(PDO::FETCH_ASSOC);
print_r($cats);

$services = $p->query("SELECT id, name_en, category, category_id, is_active FROM services WHERE category = 'test' OR category_id = 639")->fetchAll(PDO::FETCH_ASSOC);
echo "\nServices in Test:\n";
print_r($services);
