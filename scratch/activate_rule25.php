<?php
$pdo = new PDO('mysql:host=localhost;dbname=carwash_db', 'root', '');
$pdo->exec("UPDATE upsell_rules SET is_active = 1 WHERE id = 25");
echo "Updated Rule 25 to active!\n";
