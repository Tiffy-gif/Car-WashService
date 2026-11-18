<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db;charset=utf8mb4', 'root', '');
$pdo->exec("UPDATE pos_devices SET api_key = 'my_test_device_key_unique9', status = 'active' WHERE id = 9");
echo "Updated id 9 successfully\n";
