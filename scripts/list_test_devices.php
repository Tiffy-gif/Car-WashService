<?php
declare(strict_types=1);

// Refuse to run unless called via CLI
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script can only be run from the command line (CLI).\n");
    exit(1);
}

require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

// Parse CLI options
$blockIds = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--block=')) {
        $val = substr($arg, 8);
        $ids = array_filter(array_map('intval', explode(',', $val)), fn(int $id): bool => $id > 0);
        $blockIds = array_merge($blockIds, $ids);
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/list_test_devices.php [--block=ID,ID]\n";
        echo "  Lists POS devices whose name, UID, or API key look like test data.\n";
        echo "  --block=ID,ID  Sets the status of specified device IDs to 'blocked'.\n";
        exit(0);
    }
}
$blockIds = array_values(array_unique($blockIds));

// If --block=ID,ID was specified, update them
if (!empty($blockIds)) {
    $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
    $stmt = $pdo->prepare("UPDATE pos_devices SET status = 'blocked' WHERE id IN ({$placeholders})");
    $stmt->execute($blockIds);
    $affected = $stmt->rowCount();
    echo "Updated {$affected} device(s) to status 'blocked' (IDs: " . implode(', ', $blockIds) . ").\n\n";
}

// Query pos_devices rows whose name, uid, or key look like test data
// IMPORTANT: Never select or print the api_key column (secrets protection)
$query = "
    SELECT id, device_name, device_uid, status
    FROM pos_devices
    WHERE LOWER(device_name) LIKE '%test%'
       OR LOWER(device_name) LIKE '%demo%'
       OR LOWER(device_name) LIKE '%sample%'
       OR LOWER(device_name) LIKE '%dummy%'
       OR LOWER(device_name) LIKE '%mock%'
       OR LOWER(device_uid) LIKE '%test%'
       OR LOWER(device_uid) LIKE '%demo%'
       OR LOWER(device_uid) LIKE '%sample%'
       OR LOWER(device_uid) LIKE '%dummy%'
       OR LOWER(device_uid) LIKE '%mock%'
       OR LOWER(api_key) LIKE '%test%'
       OR LOWER(api_key) LIKE '%demo%'
       OR LOWER(api_key) LIKE '%sample%'
       OR LOWER(api_key) LIKE '%dummy%'
       OR LOWER(api_key) LIKE '%mock%'
    ORDER BY id ASC
";

$stmt = $pdo->query($query);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($devices)) {
    echo "No test devices found in pos_devices.\n";
    exit(0);
}

// Print formatted table: id, name, uid, status only (never the key)
$format = "| %-4s | %-32s | %-22s | %-10s |\n";
$sep    = "+------+----------------------------------+------------------------+------------+\n";

echo "Test POS Devices Audit:\n";
echo $sep;
printf($format, 'ID', 'Name', 'UID', 'Status');
echo $sep;

foreach ($devices as $d) {
    printf(
        $format,
        (string)$d['id'],
        mb_strimwidth((string)$d['device_name'], 0, 32, '...'),
        mb_strimwidth((string)$d['device_uid'], 0, 22, '...'),
        (string)$d['status']
    );
}

echo $sep;
echo "Total test devices: " . count($devices) . "\n";
echo "To block any test devices, run: php scripts/list_test_devices.php --block=ID,ID\n";
