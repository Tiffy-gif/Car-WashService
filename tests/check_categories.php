<?php
$c = require __DIR__ . '/../config/database.php';
$p = new PDO("mysql:host={$c['host']};dbname={$c['dbname']}", $c['user'], $c['pass']);
print_r($p->query("SELECT * FROM categories")->fetchAll(PDO::FETCH_ASSOC));
