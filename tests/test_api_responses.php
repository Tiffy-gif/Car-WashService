<?php
// Test endpoint responses for different device key states
$baseUrl = 'http://localhost/Car-WashService/api/pos/index.php';

function testRequest($desc, $headers, $payload = ['action' => 'sync_catalog']) {
    global $baseUrl;
    $ch = curl_init($baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $httpHeaders = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($headers as $k => $v) {
        $httpHeaders[] = "$k: $v";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "=== $desc ===\nHTTP Status: $status\nResponse: $response\n\n";
}

testRequest('1. No X-Device-Key header', []);
testRequest('2. Invalid X-Device-Key header', ['X-Device-Key' => 'non_existent_key_12345']);
testRequest('3. Inactive/Deleted Device Key', ['X-Device-Key' => 'pos_sec_test_dummy']);
