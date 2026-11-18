<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);
$services = $p->query("SELECT id, name_en, category, category_id, is_active FROM services ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
print_r($services);
