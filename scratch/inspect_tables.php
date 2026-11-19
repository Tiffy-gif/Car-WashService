<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
echo "--- ORDERS TABLE ---\n";
$cols = $pdo->query('DESCRIBE orders')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- CASHIER_SHIFTS TABLE ---\n";
$cols2 = $pdo->query('DESCRIBE cashier_shifts')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols2 as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- POS_DAILY_CLOSES TABLE ---\n";
$cols3 = $pdo->query('DESCRIBE pos_daily_closes')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols3 as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- EXPENSES TABLE ---\n";
$cols4 = $pdo->query('DESCRIBE expenses')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols4 as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}

echo "\n--- DRAWER_EVENTS TABLE ---\n";
$cols5 = $pdo->query('DESCRIBE drawer_events')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols5 as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
