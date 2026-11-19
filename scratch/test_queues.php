<?php
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/BayManager.php';

$pdo = Database::getInstance();
$queues = BayManager::getQueues($pdo);

echo "Wash queue count: " . count($queues['wash_queue']) . "\n";
foreach ($queues['wash_queue'] as $q) {
    echo "  Wash: " . $q['plate_number'] . " (ID: " . $q['order_id'] . ")\n";
}

echo "Tech queue count: " . count($queues['technical_queue']) . "\n";
foreach ($queues['technical_queue'] as $q) {
    echo "  Tech: " . $q['plate_number'] . " (ID: " . $q['order_id'] . ")\n";
}
