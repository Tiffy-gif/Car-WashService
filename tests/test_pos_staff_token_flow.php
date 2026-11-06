<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI execution only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();

// 1. Resolve active POS device
$devStmt = $pdo->prepare("SELECT id, device_name, device_uid, api_key, api_key_hash FROM pos_devices WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$devStmt->execute();
$device = $devStmt->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    echo "FAIL: No active POS device found in pos_devices.\n";
    exit(1);
}

$deviceId = (int)$device['id'];
$apiKey = getenv('POS_DEVICE_KEY') ?: ($_ENV['POS_DEVICE_KEY'] ?? '');
if (!$apiKey) {
    $apiKey = 'test_token_flow_key_' . bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE pos_devices SET api_key_hash = ? WHERE id = ?")->execute([hash('sha256', $apiKey), $deviceId]);
}

$apiUrl = getenv('POS_URL') ?: ($_ENV['POS_URL'] ?? (rtrim(API_BASE_URL, '/') . '/api/pos/index.php'));

// Resolve active cashier user
$cashierStmt = $pdo->query("SELECT username FROM users WHERE role = 'cashier' AND status = 'active' LIMIT 1");
$cashierUsername = $cashierStmt->fetchColumn() ?: 'visal';

echo "======================================================================\n";
echo "POS Staff Session Token & Authentication Flow Verification Test Suite\n";
echo "Endpoint  : {$apiUrl}\n";
echo "Device ID : {$deviceId} ({$device['device_name']})\n";
echo "Cashier   : {$cashierUsername}\n";
echo "======================================================================\n\n";

function executeRequest(string $url, array $payload, array $extraHeaders = [], string $cookieJar = '', ?string &$responseHeaders = null): array {
    $ch = curl_init();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    foreach ($extraHeaders as $k => $v) {
        $headers[] = "{$k}: {$v}";
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if ($cookieJar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    $raw = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $headerStr = is_string($raw) ? substr($raw, 0, $headerSize) : '';
    $bodyStr = is_string($raw) ? substr($raw, $headerSize) : '';
    $responseHeaders = $headerStr;

    $json = is_string($bodyStr) ? json_decode($bodyStr, true) : null;

    return [
        'status' => $httpCode,
        'headers' => $headerStr,
        'body' => $bodyStr,
        'json' => $json,
        'error' => $curlError
    ];
}

function executeFormPost(string $url, array $fields, string $cookieJar): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'body' => $body];
}

function executeOptions(string $url, string $origin, string $requestHeaders = ''): array {
    $ch = curl_init();
    $headers = [
        "Origin: {$origin}",
        "Access-Control-Request-Method: POST",
    ];
    if ($requestHeaders !== '') {
        $headers[] = "Access-Control-Request-Headers: {$requestHeaders}";
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $headerStr = is_string($raw) ? substr($raw, 0, $headerSize) : '';

    return [
        'status' => $httpCode,
        'headers' => $headerStr,
        'error' => $curlError
    ];
}

$allPassed = true;
$testSessionsToClean = [];

try {
    // =========================================================================
    // TEST 1: Staff Login via PIN Flow
    // =========================================================================
    echo "--- TEST 1: Staff Login via PIN Flow ---\n";
    $loginRes = executeRequest($apiUrl, [
        'action' => 'staff_login',
        'username' => $cashierUsername,
        'pin' => '123456'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($loginRes['status'] === 200 && !empty($loginRes['json']['session_token']) && ($loginRes['json']['success'] ?? false) === true) {
        $staffToken = $loginRes['json']['session_token'];
        $testSessionsToClean[] = hash('sha256', $staffToken);
        echo "[PASS] Staff login successful: Token issued (" . substr($staffToken, 0, 10) . "...)\n";
        echo "       User: {$loginRes['json']['user']['username']}, Role: {$loginRes['json']['user']['role']}\n";
    } else {
        echo "[FAIL] Staff login failed: HTTP {$loginRes['status']} - " . json_encode($loginRes['json']) . "\n";
        $allPassed = false;
        $staffToken = '';
    }

    // =========================================================================
    // TEST 2: POS Page Loading Behavior (Logged-Out Clean State)
    // =========================================================================
    echo "\n--- TEST 2: POS Page Loading Behavior (Logged-Out Clean State) ---\n";
    // 2a. Catalog sync succeeds with device key only (no token)
    $catRes = executeRequest($apiUrl, [
        'action' => 'sync_catalog'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($catRes['status'] === 200 && ($catRes['json']['success'] ?? false) === true) {
        echo "[PASS] 2a. Page-load catalog sync succeeds with device key only (HTTP 200)\n";
    } else {
        echo "[FAIL] 2a. Page-load catalog sync failed: HTTP {$catRes['status']}\n";
        $allPassed = false;
    }

    // 2b. Day lock status check succeeds with device key only
    $lockRes = executeRequest($apiUrl, [
        'action' => 'get_day_lock_status'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($lockRes['status'] === 200 && ($lockRes['json']['success'] ?? false) === true) {
        echo "[PASS] 2b. Day lock status succeeds with device key only (HTTP 200)\n";
    } else {
        echo "[FAIL] 2b. Day lock status failed: HTTP {$lockRes['status']}\n";
        $allPassed = false;
    }

    // =========================================================================
    // TEST 3: Relevant POS Actions with Valid Staff Session Token
    // =========================================================================
    echo "\n--- TEST 3: Relevant POS Actions with Valid Staff Session Token ---\n";
    
    // 3a. Customer sync with staff token
    $custRes = executeRequest($apiUrl, [
        'action' => 'sync_customers'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($custRes['status'] === 200 && ($custRes['json']['success'] ?? false) === true) {
        echo "[PASS] 3a. sync_customers succeeded with X-Staff-Token (HTTP 200)\n";
    } else {
        echo "[FAIL] 3a. sync_customers failed: HTTP {$custRes['status']} - " . json_encode($custRes['json']) . "\n";
        $allPassed = false;
    }

    // 3b. Job Queue & Bays summary
    $baysRes = executeRequest($apiUrl, [
        'action' => 'get_bays_and_queue'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($baysRes['status'] === 200 && ($baysRes['json']['success'] ?? false) === true) {
        echo "[PASS] 3b. get_bays_and_queue succeeded with X-Staff-Token (HTTP 200)\n";
    } else {
        echo "[FAIL] 3b. get_bays_and_queue failed: HTTP {$baysRes['status']} - " . json_encode($baysRes['json']) . "\n";
        $allPassed = false;
    }

    // 3c. Today's orders list
    $ordersRes = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($ordersRes['status'] === 200 && ($ordersRes['json']['success'] ?? false) === true) {
        echo "[PASS] 3c. get_today_orders succeeded with X-Staff-Token (HTTP 200)\n";
    } else {
        echo "[FAIL] 3c. get_today_orders failed: HTTP {$ordersRes['status']} - " . json_encode($ordersRes['json']) . "\n";
        $allPassed = false;
    }

    // 3d. Get wait times (Cashier POS action)
    $waitRes = executeRequest($apiUrl, [
        'action' => 'get_wait_times',
        'service_ids' => [1],
        'vehicle_type' => 'small_car'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($waitRes['status'] === 200 && ($waitRes['json']['success'] ?? false) === true) {
        echo "[PASS] 3d. get_wait_times succeeded with X-Staff-Token (HTTP 200)\n";
    } else {
        echo "[FAIL] 3d. get_wait_times failed: HTTP {$waitRes['status']} - " . json_encode($waitRes['json']) . "\n";
        $allPassed = false;
    }

    // 3e. Create order with staff token (simulate POS sale)
    $testIdempotency = 'test_token_flow_order_' . bin2hex(random_bytes(8));
    $orderRes = executeRequest($apiUrl, [
        'action' => 'create_order',
        'customer_name' => 'Token Test Guest',
        'plate_number' => '2AB-9999',
        'vehicle_type' => 'small_car',
        'payment_method' => 'cash',
        'items' => [
            [
                'item_type' => 'service',
                'service_id' => 1,
                'name' => 'Regular Wash',
                'price_usd' => 5.00,
                'qty' => 1
            ]
        ]
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken,
        'X-Idempotency-Key' => $testIdempotency
    ]);

    if ($orderRes['status'] === 200 && (($orderRes['json']['success'] ?? false) === true || ($orderRes['json']['is_duplicate'] ?? false) === true)) {
        $ordNum = $orderRes['json']['order']['order_number'] ?? ($orderRes['json']['order_number'] ?? 'N/A');
        echo "[PASS] 3e. create_order succeeded with X-Staff-Token (HTTP 200, Order: {$ordNum})\n";
    } else {
        echo "[FAIL] 3e. create_order failed: HTTP {$orderRes['status']} - " . json_encode($orderRes['json']) . "\n";
        $allPassed = false;
    }

    // 3f. Create order with UPFRONT PAYMENT and DISCOUNT (verifying creator payment & discount permissions)
    $testPaidIdempotency = 'test_token_flow_paid_' . bin2hex(random_bytes(8));
    $paidOrderRes = executeRequest($apiUrl, [
        'action' => 'create_order',
        'customer_name' => 'Paid Token Test Guest',
        'plate_number' => '2AB-8888',
        'vehicle_type' => 'small_car',
        'is_paid' => 1,
        'payment_method' => 'cash',
        'amount_tendered_usd' => 10.00,
        'discount_usd' => 1.00,
        'items' => [
            [
                'item_type' => 'service',
                'service_id' => 1,
                'name' => 'Regular Wash',
                'price_usd' => 5.00,
                'qty' => 1
            ]
        ]
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken,
        'X-Idempotency-Key' => $testPaidIdempotency
    ]);

    if ($paidOrderRes['status'] === 200 && (($paidOrderRes['json']['success'] ?? false) === true || ($paidOrderRes['json']['is_duplicate'] ?? false) === true)) {
        $ordPaidNum = $paidOrderRes['json']['order']['order_number'] ?? ($paidOrderRes['json']['order_number'] ?? 'N/A');
        echo "[PASS] 3f. create_order with upfront payment and discount succeeded (HTTP 200, Order: {$ordPaidNum})\n";
    } else {
        echo "[FAIL] 3f. create_order with upfront payment failed: HTTP {$paidOrderRes['status']} - " . json_encode($paidOrderRes['json']) . "\n";
        $allPassed = false;
    }

    // =========================================================================
    // TEST 4: Page Refresh Simulation (Token Persistence in Dual Storage)
    // =========================================================================
    echo "\n--- TEST 4: Page Refresh Simulation (Token Persistence) ---\n";
    // Simulating page refresh:
    // In browser, localStorage.getItem('cw_pos_session_token') yields $staffToken
    // Next request arrives with the retained token
    $refreshRes = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken // Retained from storage
    ]);

    if ($refreshRes['status'] === 200 && ($refreshRes['json']['success'] ?? false) === true) {
        echo "[PASS] Request with retained staff token after page refresh succeeds (HTTP 200)\n";
    } else {
        echo "[FAIL] Request with retained token failed after refresh: HTTP {$refreshRes['status']}\n";
        $allPassed = false;
    }

    // 4b. Web session bridging test (if staff is logged into Back Office via cookie)
    $cookieFile = sys_get_temp_dir() . '/test_pos_cookie_' . bin2hex(random_bytes(6)) . '.txt';
    // Authenticate web session via login form POST
    $webLoginRes = executeFormPost(rtrim(API_BASE_URL, '/') . '/index.php?action=login', [
        'username' => $cashierUsername,
        'pin' => '123456'
    ], $cookieFile);

    // Call get_web_session on POS endpoint with cookie
    $bridgeRes = executeRequest($apiUrl, [
        'action' => 'get_web_session'
    ], [
        'X-Device-Key' => $apiKey
    ], $cookieFile);

    if ($bridgeRes['status'] === 200 && !empty($bridgeRes['json']['session_token'])) {
        $bridgedToken = $bridgeRes['json']['session_token'];
        $testSessionsToClean[] = hash('sha256', $bridgedToken);
        echo "[PASS] 4b. Back Office web session successfully bridged to POS staff token (HTTP 200)\n";
    } else {
        echo "[FAIL] 4b. Web session bridge test failed: HTTP {$bridgeRes['status']} - " . json_encode($bridgeRes['json']) . "\n";
        $allPassed = false;
    }
    @unlink($cookieFile);

    // =========================================================================
    // TEST 5: Logout and Login Again (Token Revocation & Rotation)
    // =========================================================================
    echo "\n--- TEST 5: Logout and Login Again (Token Revocation & Rotation) ---\n";
    // 5a. Logout terminates active token
    $logoutRes = executeRequest($apiUrl, [
        'action' => 'staff_logout'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($logoutRes['status'] === 200 && ($logoutRes['json']['success'] ?? false) === true) {
        echo "[PASS] 5a. staff_logout executed cleanly (HTTP 200)\n";
    } else {
        echo "[FAIL] 5a. staff_logout failed: HTTP {$logoutRes['status']}\n";
        $allPassed = false;
    }

    // 5b. Subsequent request with the revoked token must be rejected with 401
    $revokedRes = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $staffToken
    ]);

    if ($revokedRes['status'] === 401 && ($revokedRes['json']['error'] ?? '') === 'SESSION_INVALID') {
        echo "[PASS] 5b. Revoked token rejected with HTTP 401 SESSION_INVALID\n";
    } else {
        echo "[FAIL] 5b. Revoked token was NOT rejected: HTTP {$revokedRes['status']}\n";
        $allPassed = false;
    }

    // 5c. Login again to verify token rotation
    $reloginRes = executeRequest($apiUrl, [
        'action' => 'staff_login',
        'username' => $cashierUsername,
        'pin' => '123456'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($reloginRes['status'] === 200 && !empty($reloginRes['json']['session_token'])) {
        $newToken = $reloginRes['json']['session_token'];
        $testSessionsToClean[] = hash('sha256', $newToken);
        if ($newToken !== $staffToken) {
            echo "[PASS] 5c. Re-login successful: Fresh token issued distinct from revoked token\n";
        } else {
            echo "[WARN] 5c. Re-login token matched previous token\n";
        }
    } else {
        echo "[FAIL] 5c. Re-login failed: HTTP {$reloginRes['status']}\n";
        $allPassed = false;
    }

    // =========================================================================
    // TEST 6: Missing or Invalid Token Rejection
    // =========================================================================
    echo "\n--- TEST 6: Missing or Invalid Token Rejection ---\n";
    
    // 6a. Missing token on protected endpoint => 401 with exact expected message
    $missingRes = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey
        // NO X-Staff-Token
    ]);

    if ($missingRes['status'] === 401 && ($missingRes['json']['message'] ?? '') === 'Staff session token required. Pass header X-Staff-Token.') {
        echo "[PASS] 6a. Missing token returns 401: 'Staff session token required. Pass header X-Staff-Token.'\n";
    } else {
        echo "[FAIL] 6a. Expected 401 'Staff session token required', got HTTP {$missingRes['status']} - " . json_encode($missingRes['json']) . "\n";
        $allPassed = false;
    }

    // 6b. Invalid / fake token => 401 Invalid staff session token
    $fakeRes = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => 'bogus_token_1234567890abcdef'
    ]);

    if ($fakeRes['status'] === 401 && ($fakeRes['json']['error'] ?? '') === 'SESSION_INVALID') {
        echo "[PASS] 6b. Invalid token rejected with HTTP 401 SESSION_INVALID\n";
    } else {
        echo "[FAIL] 6b. Invalid token was not rejected: HTTP {$fakeRes['status']}\n";
        $allPassed = false;
    }

    // =========================================================================
    // TEST 7: Offline LAN POS Requests (CORS & Headers)
    // =========================================================================
    echo "\n--- TEST 7: Offline LAN POS Requests (CORS & Headers) ---\n";
    
    // 7a. LAN preflight OPTIONS request with X-Staff-Token in requested headers
    $lanPreflight = executeOptions($apiUrl, 'http://192.168.1.126', 'Content-Type, X-Device-Key, X-Staff-Token');
    
    $hasAllowOrigin = str_contains($lanPreflight['headers'], 'Access-Control-Allow-Origin: http://192.168.1.126');
    $hasAllowStaffToken = str_contains($lanPreflight['headers'], 'X-Staff-Token');
    $hasAllowCreds = str_contains($lanPreflight['headers'], 'Access-Control-Allow-Credentials: true');

    if ($lanPreflight['status'] === 204 && $hasAllowOrigin && $hasAllowStaffToken && $hasAllowCreds) {
        echo "[PASS] 7a. LAN CORS preflight OPTIONS returned 204 with X-Staff-Token in Allow-Headers\n";
    } else {
        echo "[FAIL] 7a. LAN CORS preflight failed: HTTP {$lanPreflight['status']} - Headers: {$lanPreflight['headers']}\n";
        $allPassed = false;
    }

    // 7b. LAN request with valid token and device key
    $lanRequest = executeRequest($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'Origin' => 'http://192.168.1.126',
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $newToken
    ]);

    if ($lanRequest['status'] === 200 && ($lanRequest['json']['success'] ?? false) === true) {
        echo "[PASS] 7b. LAN API request with X-Staff-Token succeeded (HTTP 200)\n";
    } else {
        echo "[FAIL] 7b. LAN API request failed: HTTP {$lanRequest['status']}\n";
        $allPassed = false;
    }

} finally {
    // Clean up temporary test sessions
    if (!empty($testSessionsToClean)) {
        $inClause = implode(',', array_fill(0, count($testSessionsToClean), '?'));
        $pdo->prepare("DELETE FROM pos_sessions WHERE token_hash IN ({$inClause})")->execute($testSessionsToClean);
    }
}

echo "\n======================================================================\n";
if ($allPassed) {
    echo "RESULT: ALL 7 TEST SUITES PASSED! POS AUTHENTICATION FLOW VERIFIED!\n";
} else {
    echo "RESULT: ONE OR MORE TESTS FAILED.\n";
    exit(1);
}
echo "======================================================================\n";
