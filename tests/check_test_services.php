<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);
$prods = $p->query("SELECT id, name_en, category_id FROM products WHERE category_id IN (637, 638)")->fetchAll(PDO::FETCH_ASSOC);
print_r($prods);
