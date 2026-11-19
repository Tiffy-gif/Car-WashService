<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$stmt = $pdo->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5");
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($orders as $o) {
    echo "ID: {$o['id']} | Num: {$o['order_number']} | Plate: {$o['plate_number']} | Subtotal: {$o['subtotal_usd']} | Disc: {$o['discount_usd']} | Total: {$o['total_usd']}\n";
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $itemStmt->execute([$o['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $it) {
        echo "   Item: {$it['service_name']} | Qty: {$it['qty']} | Price: {$it['price_usd']}\n";
    }
}
