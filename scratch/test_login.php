<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_DEVICE_UID'] = 'POS-CAASIER';
$_SERVER['HTTP_X_DEVICE_KEY'] = 'my_test_device_key_unique9';
$_GET['action'] = 'staff_login';
$input = json_encode(['username' => 'visal', 'pin' => '123456']);
// Mock php://input
file_put_contents('scratch/mock_input.json', $input);
// Let's test staff_login
$ch = curl_init('http://localhost/Car-WashService/api/pos/index.php?action=staff_login');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-Device-UID: POS-CAASIER',
    'X-Device-Key: my_test_device_key_unique9'
]);
$res = curl_exec($ch);
curl_close($ch);
echo $res;
