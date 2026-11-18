<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_DEVICE_UID'] = 'POS-CAASIER';
$_SERVER['HTTP_X_DEVICE_KEY'] = 'my_test_device_key_unique9';
$_GET['action'] = 'sync_catalog';
ob_start();
include 'api/pos/index.php';
$output = ob_get_clean();
$json = json_decode($output, true);
if (isset($json['data']['services'])) {
    foreach ($json['data']['services'] as $s) {
        echo "ID: " . $s['id'] . " | Name: " . $s['name_en'] . " | Img: " . ($s['image_url'] ?? 'NULL') . "\n";
    }
} else {
    echo "Output: " . substr($output, 0, 500);
}
