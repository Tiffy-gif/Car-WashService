<?php
require 'config/app.php';
require 'core/Database.php';

$pdo = Database::getInstance();
$users = $pdo->query("SELECT id, username, local_pin_hash, password_hash FROM users WHERE role='cashier'")->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as $u) {
    echo "User: " . $u['username'] . "\n";
    foreach (['123456', '111111', '000000', '123123', 'admin'] as $p) {
        if (password_verify($p, $u['local_pin_hash'] ?? '')) {
            echo "  PIN: $p matches local_pin_hash\n";
        }
        if (password_verify($p, $u['password_hash'] ?? '')) {
            echo "  PWD: $p matches password_hash\n";
        }
    }
}
