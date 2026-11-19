<?php
require_once __DIR__ . '/../core/Database.php';
$db = Database::getInstance();

$stmt = $db->query("SELECT id, module, code, label_en, area FROM permissions WHERE module = 'daily_close'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$cashierPerms = $db->query("SELECT p.code FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = 2")->fetchAll(PDO::FETCH_COLUMN);
echo "Cashier has daily_close.close: " . (in_array('daily_close.close', $cashierPerms) ? "YES" : "NO") . "\n";
echo "Cashier has daily_close.view: " . (in_array('daily_close.view', $cashierPerms) ? "YES" : "NO") . "\n";
