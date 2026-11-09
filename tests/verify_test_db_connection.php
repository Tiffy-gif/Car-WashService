<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__) . '/core/Database.php';

try {
    $pdo = Database::getInstance();
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $customerCount = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    $deviceCount = (int)$pdo->query("SELECT COUNT(*) FROM pos_devices")->fetchColumn();

    $isTestDb = ($dbName === 'carwash_db_test');

    echo json_encode([
        'status' => 'ok',
        'active_database' => $dbName,
        'is_test_isolated' => $isTestDb,
        'order_count' => $orderCount,
        'customer_count' => $customerCount,
        'device_count' => $deviceCount,
        'server_port' => $_SERVER['SERVER_PORT'] ?? 'unknown',
        'driver' => Database::getDriver(),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
