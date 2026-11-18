<?php
require_once 'core/Database.php';
$pdo = Database::getInstance();
$rows = $pdo->query("SELECT id, name_en, category, category_id, is_active FROM services ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
