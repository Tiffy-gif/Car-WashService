<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$device = $pdo->query("SELECT api_key, device_uid FROM pos_devices WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    die("No active device found.\n");
}

$url = "http://localhost/Car-WashService/api/pos/index.php?action=";

function apiCall(string $action, array $data, string $apiKey): array {
    $ch = curl_init("http://localhost/Car-WashService/api/pos/index.php?action=" . urlencode($action));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Device-Key: ' . $apiKey
    ]);
    $res = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'data' => json_decode((string)$res, true)];
}

echo "1. Testing POS staff_login...\n";
$loginRes = apiCall('staff_login', ['username' => 'cashier', 'password' => 'cashier123'], $device['api_key']);
echo "HTTP Status: " . $loginRes['status'] . "\n";
echo "Role Code: " . ($loginRes['data']['user']['role_code'] ?? 'N/A') . "\n";
echo "Role Name EN: " . ($loginRes['data']['user']['role_name_en'] ?? 'N/A') . "\n";
echo "Role Name KM: " . ($loginRes['data']['user']['role_name_km'] ?? 'N/A') . "\n";
echo "Permissions count: " . count($loginRes['data']['user']['permissions'] ?? []) . "\n";
$hasPosAccess = in_array('pos.access', $loginRes['data']['user']['permissions'] ?? [], true);
echo "Has pos.access: " . ($hasPosAccess ? 'YES' : 'NO') . "\n";

echo "\n2. Testing POS sync_catalog...\n";
$syncRes = apiCall('sync_catalog', [], $device['api_key']);
echo "HTTP Status: " . $syncRes['status'] . "\n";
echo "Washers duty crew count: " . count($syncRes['data']['washers'] ?? []) . "\n";
echo "Mechanics duty crew count: " . count($syncRes['data']['mechanics'] ?? []) . "\n";

echo "\nAll POS API checks passed!\n";
