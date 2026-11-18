<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db', 'root', '');
foreach ($pdo->query("SELECT id, rule_uid, name, is_active FROM upsell_rules") as $r) {
    echo "ID {$r['id']}: {$r['rule_uid']} ({$r['name']}) -> is_active = {$r['is_active']}\n";
}
