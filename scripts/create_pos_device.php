<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Error: This script can only be run from the command line (CLI).\n");
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();

// Parse CLI options: --name="...", --location="...", --uid="..."
$deviceName = 'Counter Terminal ' . date('ymd-His');
$location = 'Main Counter';
$deviceUid = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--name=')) {
        $deviceName = trim(substr($arg, 7), " '\"");
    } elseif (str_starts_with($arg, '--location=')) {
        $location = trim(substr($arg, 11), " '\"");
    } elseif (str_starts_with($arg, '--uid=')) {
        $deviceUid = strtoupper(trim(substr($arg, 6), " '\""));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "Usage: php scripts/create_pos_device.php [--name=\"Name\"] [--location=\"Location\"] [--uid=\"UID\"]\n";
        echo "  Creates a new POS device record with a cryptographically secure random 32-byte key.\n";
        echo "  Prints the secret device key once.\n";
        exit(0);
    }
}

if (!$deviceUid) {
    $deviceUid = 'POS-' . strtoupper(bin2hex(random_bytes(4)));
}

// Ensure UID is unique
$chk = $pdo->prepare("SELECT COUNT(*) FROM pos_devices WHERE device_uid = ?");
$chk->execute([$deviceUid]);
if ((int)$chk->fetchColumn() > 0) {
    $deviceUid = 'POS-' . strtoupper(bin2hex(random_bytes(6)));
}

// Generate random 32-byte key (64 hex characters)
$rawKey = bin2hex(random_bytes(32));

// Check if api_key_hash column exists (for forward-compatibility with Item 2)
$cols = $pdo->query("SHOW COLUMNS FROM pos_devices")->fetchAll(PDO::FETCH_COLUMN);
$hasHashCol = in_array('api_key_hash', $cols, true);

if ($hasHashCol) {
    $keyHash = hash('sha256', $rawKey);
    $stmt = $pdo->prepare("
        INSERT INTO pos_devices (device_name, device_uid, api_key, api_key_hash, location, status)
        VALUES (?, ?, '', ?, ?, 'active')
    ");
    $stmt->execute([$deviceName, $deviceUid, $keyHash, $location]);
} else {
    $stmt = $pdo->prepare("
        INSERT INTO pos_devices (device_name, device_uid, api_key, location, status)
        VALUES (?, ?, ?, ?, 'active')
    ");
    $stmt->execute([$deviceName, $deviceUid, $rawKey, $location]);
}

$deviceId = (int)$pdo->lastInsertId();

echo "========================================================\n";
echo "SUCCESS: Created new POS Device\n";
echo "========================================================\n";
echo "Device ID   : {$deviceId}\n";
echo "Device Name : {$deviceName}\n";
echo "Device UID  : {$deviceUid}\n";
echo "Location    : {$location}\n";
echo "Status      : active\n";
echo "Device Key  : {$rawKey}\n";
echo "========================================================\n";
echo "IMPORTANT: Copy and store this Device Key now!\n";
echo "For security, this key cannot be retrieved again.\n";
echo "========================================================\n";
