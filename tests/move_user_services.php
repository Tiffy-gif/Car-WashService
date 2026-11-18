<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);

// Update user's attempted test services to 'test' category
$p->prepare("UPDATE services SET category = 'test', category_id = 639 WHERE id IN (35, 36)")->execute();

$services = $p->query("SELECT id, name_en, category, category_id, is_active FROM services WHERE category_id = 639 OR category = 'test'")->fetchAll(PDO::FETCH_ASSOC);
print_r($services);
