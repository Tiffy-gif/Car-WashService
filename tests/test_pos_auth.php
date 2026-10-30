<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This test script must be executed via CLI only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

$pdo = Database::getInstance();

// 1. Resolve active POS device
$devStmt = $pdo->prepare("SELECT id, device_name, device_uid, api_key FROM pos_devices WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$devStmt->execute();
$device = $devStmt->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    echo "FAIL: No active POS device found in pos_devices table.\n";
    exit(1);
}

$deviceId = (int)$device['id'];
$apiKey = getenv('POS_DEVICE_KEY') ?: ($_ENV['POS_DEVICE_KEY'] ?? '');
if (!$apiKey) {
    // When keys are stored hashed, generate a temporary session test key for this device
    $apiKey = 'test_auth_key_' . bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE pos_devices SET api_key_hash = ? WHERE id = ?")->execute([hash('sha256', $apiKey), $deviceId]);
}
$apiUrl = getenv('POS_URL') ?: ($_ENV['POS_URL'] ?? (rtrim(API_BASE_URL, '/') . '/api/pos/index.php'));

echo "========================================================\n";
echo "POS API Authentication & Authorization Test Suite (CLI)\n";
echo "Target Endpoint : {$apiUrl}\n";
echo "Device ID       : {$deviceId} ({$device['device_name']})\n";
echo "========================================================\n\n";

/**
 * Execute HTTP POST request against POS API
 */
function callPosApi(string $url, array $payload, array $extraHeaders = []): array {
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $json = is_string($body) ? json_decode($body, true) : null;

    return [
        'status' => $httpCode,
        'body' => $body,
        'json' => $json,
        'error' => $curlError
    ];
}

$allPassed = true;

// Helper to record temporary sessions for cleanup
$testSessionHashes = [];

function registerTestSession(PDO $pdo, int $userId, int $deviceId, string $expiresAt, string $lastSeenAt = 'now'): string {
    global $testSessionHashes;
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $testSessionHashes[] = $tokenHash;

    $nowStr = date('Y-m-d H:i:s');
    $lastSeenStr = ($lastSeenAt === 'now') ? $nowStr : $lastSeenAt;

    $stmt = $pdo->prepare("INSERT INTO pos_sessions (token_hash, user_id, device_id, created_at, last_seen_at, expires_at, ip) VALUES (?, ?, ?, ?, ?, ?, '127.0.0.1')");
    $stmt->execute([$tokenHash, $userId, $deviceId, $nowStr, $lastSeenStr, $expiresAt]);

    return $rawToken;
}

try {
    // ----------------------------------------------------
    // CASE 1: No token => 401
    // ----------------------------------------------------
    $res1 = callPosApi($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($res1['status'] === 401 && ($res1['json']['error'] ?? '') === 'SESSION_INVALID') {
        echo "[PASS] Case 1: No token => 401 (SESSION_INVALID)\n";
    } else {
        echo "[FAIL] Case 1: Expected HTTP 401 SESSION_INVALID, got HTTP {$res1['status']} - " . json_encode($res1['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 2: Fake staff_id with no token => 401
    // ----------------------------------------------------
    $res2 = callPosApi($apiUrl, [
        'action' => 'get_today_orders',
        'staff_id' => 1,
        'user_id' => 1,
        'cashier_id' => 1,
        'staff_role' => 'owner'
    ], [
        'X-Device-Key' => $apiKey
    ]);

    if ($res2['status'] === 401 && ($res2['json']['error'] ?? '') === 'SESSION_INVALID') {
        echo "[PASS] Case 2: Fake staff_id with no token => 401 (SESSION_INVALID)\n";
    } else {
        echo "[FAIL] Case 2: Expected HTTP 401 SESSION_INVALID, got HTTP {$res2['status']} - " . json_encode($res2['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 3: Cashier token calling a manager-only action => 403
    // ----------------------------------------------------
    // Find active cashier user
    $cStmt = $pdo->prepare("SELECT id FROM users WHERE role = 'cashier' AND status = 'active' LIMIT 1");
    $cStmt->execute();
    $cashierUserId = (int)$cStmt->fetchColumn();
    if (!$cashierUserId) {
        $cashierUserId = 2; // fallback
    }

    $validExpires = date('Y-m-d H:i:s', time() + 3600);
    $cashierToken = registerTestSession($pdo, $cashierUserId, $deviceId, $validExpires);

    // Manager-only action: get_daily_report (requires reports.view, which cashier lacks)
    $res3 = callPosApi($apiUrl, [
        'action' => 'get_daily_report'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $cashierToken
    ]);

    if ($res3['status'] === 403 && ($res3['json']['error'] ?? '') === 'PERMISSION_DENIED') {
        echo "[PASS] Case 3: Cashier token calling manager-only action => 403 (PERMISSION_DENIED)\n";
    } else {
        echo "[FAIL] Case 3: Expected HTTP 403 PERMISSION_DENIED, got HTTP {$res3['status']} - " . json_encode($res3['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 4: Expired session => 401
    // ----------------------------------------------------
    $pastExpires = date('Y-m-d H:i:s', time() - 3600); // 1 hour in the past
    $expiredToken = registerTestSession($pdo, $cashierUserId, $deviceId, $pastExpires);

    $res4 = callPosApi($apiUrl, [
        'action' => 'get_today_orders'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $expiredToken
    ]);

    if ($res4['status'] === 401 && ($res4['json']['error'] ?? '') === 'SESSION_INVALID') {
        echo "[PASS] Case 4: Expired session => 401 (SESSION_INVALID)\n";
    } else {
        echo "[FAIL] Case 4: Expected HTTP 401 SESSION_INVALID, got HTTP {$res4['status']} - " . json_encode($res4['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 5: Valid manager token succeeds => 200
    // ----------------------------------------------------
    // Find active manager or owner user
    $mStmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('manager', 'owner') AND status = 'active' ORDER BY role ASC LIMIT 1");
    $mStmt->execute();
    $managerUserId = (int)$mStmt->fetchColumn();
    if (!$managerUserId) {
        $managerUserId = 1; // fallback
    }

    $managerToken = registerTestSession($pdo, $managerUserId, $deviceId, $validExpires);

    $res5 = callPosApi($apiUrl, [
        'action' => 'get_daily_report'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $managerToken
    ]);

    if ($res5['status'] === 200 && ($res5['json']['success'] ?? false) === true) {
        echo "[PASS] Case 5: Valid manager token succeeds => 200 (success: true)\n";
    } else {
        echo "[FAIL] Case 5: Expected HTTP 200 with success: true, got HTTP {$res5['status']} - " . json_encode($res5['json']) . "\n";
        $allPassed = false;
    }

    // ========================================================
    // REFUNDS & VOIDS / OVERRIDE AUTHORIZATION TESTS (ITEM 6)
    // ========================================================
    $testOrderIds = [];
    $testUserIds = [];

    // Create temporary dedicated test manager & test cashier users
    $testPin = '123456';
    $testMgrUsername = 'test_mgr_' . bin2hex(random_bytes(4));
    $testCashierUsername = 'test_csh_' . bin2hex(random_bytes(4));

    $pdo->prepare("
        INSERT INTO users (username, full_name, role, password_hash, status, created_at)
        VALUES (?, 'Test Manager Auth', 'manager', ?, 'active', NOW())
    ")->execute([$testMgrUsername, password_hash($testPin, PASSWORD_DEFAULT)]);
    $testMgrId = (int)$pdo->lastInsertId();
    $testUserIds[] = $testMgrId;

    $pdo->prepare("
        INSERT INTO users (username, full_name, role, password_hash, status, created_at)
        VALUES (?, 'Test Cashier Auth', 'cashier', ?, 'active', NOW())
    ")->execute([$testCashierUsername, password_hash('123456', PASSWORD_DEFAULT)]);
    $testCashierId = (int)$pdo->lastInsertId();
    $testUserIds[] = $testCashierId;

    $dedicatedCashierToken = registerTestSession($pdo, $testCashierId, $deviceId, $validExpires);
    $dedicatedMgrToken = registerTestSession($pdo, $testMgrId, $deviceId, $validExpires);

    $insOrd = $pdo->prepare("
        INSERT INTO orders (
            order_number, customer_name, plate_number, vehicle_type, total_usd, status, payment_status, cashier_id, created_at
        ) VALUES (?, 'Test Customer', '2A-9999', 'sedan', 25.00, ?, ?, ?, NOW())
    ");

    // ----------------------------------------------------
    // CASE 6: Cashier cancel without PIN => 403
    // ----------------------------------------------------
    $ordNum1 = 'TEST-ORD-' . bin2hex(random_bytes(4));
    $insOrd->execute([$ordNum1, 'pending', 'unpaid', $testCashierId]);
    $ordId1 = (int)$pdo->lastInsertId();
    $testOrderIds[] = $ordId1;

    $res6 = callPosApi($apiUrl, [
        'action' => 'cancel_order',
        'order_id' => $ordId1,
        'order_number' => $ordNum1,
        'reason' => 'Customer requested cancellation'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedCashierToken
    ]);

    if ($res6['status'] === 403) {
        echo "[PASS] Case 6: Cashier cancel without PIN => 403\n";
    } else {
        echo "[FAIL] Case 6: Expected HTTP 403, got HTTP {$res6['status']} - " . json_encode($res6['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 7: Cashier cancel with wrong PIN => 403
    // ----------------------------------------------------
    $res7 = callPosApi($apiUrl, [
        'action' => 'cancel_order',
        'order_id' => $ordId1,
        'order_number' => $ordNum1,
        'reason' => 'Customer requested cancellation',
        'manager_username' => $testMgrUsername,
        'manager_pin' => '000000'
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedCashierToken
    ]);

    if ($res7['status'] === 403) {
        echo "[PASS] Case 7: Cashier cancel with wrong PIN => 403\n";
    } else {
        echo "[FAIL] Case 7: Expected HTTP 403, got HTTP {$res7['status']} - " . json_encode($res7['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 8: Valid manager PIN => success with both IDs stored
    // ----------------------------------------------------
    $res8 = callPosApi($apiUrl, [
        'action' => 'cancel_order',
        'order_id' => $ordId1,
        'order_number' => $ordNum1,
        'reason' => 'Customer requested cancellation',
        'manager_username' => $testMgrUsername,
        'manager_pin' => $testPin
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedCashierToken
    ]);

    $chkStmt = $pdo->prepare("SELECT status, cancelled_by_user_id, cancel_approved_by_user_id FROM orders WHERE id = ?");
    $chkStmt->execute([$ordId1]);
    $row8 = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($res8['status'] === 200 && ($res8['json']['success'] ?? false) === true
        && ($row8['status'] ?? '') === 'cancelled'
        && (int)($row8['cancelled_by_user_id'] ?? 0) === $testCashierId
        && (int)($row8['cancel_approved_by_user_id'] ?? 0) === $testMgrId) {
        echo "[PASS] Case 8: Valid manager PIN => success with both IDs stored\n";
    } else {
        echo "[FAIL] Case 8: Expected HTTP 200 with both IDs stored, got HTTP {$res8['status']} row: " . json_encode($row8) . " - " . json_encode($res8['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 9: Cashier cancel of a PAID order with valid manager PIN => success
    // ----------------------------------------------------
    $ordNumPaid = 'TEST-ORD-PAID-' . bin2hex(random_bytes(4));
    $insOrd->execute([$ordNumPaid, 'paid', 'paid', $testCashierId]);
    $ordIdPaid = (int)$pdo->lastInsertId();
    $testOrderIds[] = $ordIdPaid;

    $res9 = callPosApi($apiUrl, [
        'action' => 'cancel_order',
        'order_id' => $ordIdPaid,
        'order_number' => $ordNumPaid,
        'reason' => 'Customer requested refund authorized by manager',
        'manager_username' => $testMgrUsername,
        'manager_pin' => $testPin
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedCashierToken
    ]);

    $chkStmt->execute([$ordIdPaid]);
    $row9 = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($res9['status'] === 200 && ($res9['json']['success'] ?? false) === true
        && ($row9['status'] ?? '') === 'cancelled'
        && (int)($row9['cancelled_by_user_id'] ?? 0) === $testCashierId
        && (int)($row9['cancel_approved_by_user_id'] ?? 0) === $testMgrId) {
        echo "[PASS] Case 9: Cashier cancel of a PAID order with valid manager PIN => success\n";
    } else {
        echo "[FAIL] Case 9: Expected HTTP 200 success for cancelling paid order, got HTTP {$res9['status']} row: " . json_encode($row9) . " - " . json_encode($res9['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 10: Approver equal to requester => rejected
    // ----------------------------------------------------
    $ordNumSelf = 'TEST-ORD-SELF-' . bin2hex(random_bytes(4));
    $insOrd->execute([$ordNumSelf, 'pending', 'unpaid', $testMgrId]);
    $ordIdSelf = (int)$pdo->lastInsertId();
    $testOrderIds[] = $ordIdSelf;

    $res10 = callPosApi($apiUrl, [
        'action' => 'cancel_order',
        'order_id' => $ordIdSelf,
        'order_number' => $ordNumSelf,
        'reason' => 'Self cancellation test attempt',
        'manager_username' => $testMgrUsername,
        'manager_pin' => $testPin
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedMgrToken
    ]);

    if ($res10['status'] === 403 && (
        ($res10['json']['error'] ?? '') === 'SELF_APPROVAL_NOT_ALLOWED' ||
        stripos($res10['json']['message'] ?? '', 'same user') !== false ||
        stripos($res10['json']['message'] ?? '', 'requester') !== false
    )) {
        echo "[PASS] Case 10: Approver equal to requester => rejected\n";
    } else {
        echo "[FAIL] Case 10: Expected HTTP 403 SELF_APPROVAL_NOT_ALLOWED, got HTTP {$res10['status']} - " . json_encode($res10['json']) . "\n";
        $allPassed = false;
    }

    // ----------------------------------------------------
    // CASE 11: create_order with override flag and no PIN => rejected
    // ----------------------------------------------------
    $res11 = callPosApi($apiUrl, [
        'action' => 'create_order',
        'order_number' => 'TEST-ORD-OVR-' . bin2hex(random_bytes(4)),
        'plate_number' => '2A-1111',
        'vehicle_type' => 'sedan',
        'manager_override' => true,
        'items' => [
            [
                'type' => 'service',
                'id' => 1,
                'service_id' => 1,
                'name' => 'Regular Wash',
                'price_usd' => 5.00,
                'qty' => 1
            ]
        ]
    ], [
        'X-Device-Key' => $apiKey,
        'X-Staff-Token' => $dedicatedCashierToken
    ]);

    if ($res11['status'] === 403 && (
        ($res11['json']['error'] ?? '') === 'MANAGER_PIN_REQUIRED' ||
        stripos($res11['json']['message'] ?? '', 'manager') !== false
    )) {
        echo "[PASS] Case 11: create_order with override flag and no PIN => rejected\n";
    } else {
        echo "[FAIL] Case 11: Expected HTTP 403 rejection for override without PIN, got HTTP {$res11['status']} - " . json_encode($res11['json']) . "\n";
        $allPassed = false;
    }

} finally {
    // Cleanup any created test orders
    if (!empty($testOrderIds)) {
        $inOrdClause = implode(',', array_fill(0, count($testOrderIds), '?'));
        $pdo->prepare("DELETE FROM orders WHERE id IN ({$inOrdClause})")->execute($testOrderIds);
    }

    // Cleanup any created test users
    if (!empty($testUserIds)) {
        $inUserClause = implode(',', array_fill(0, count($testUserIds), '?'));
        $pdo->prepare("DELETE FROM users WHERE id IN ({$inUserClause})")->execute($testUserIds);
    }

    // Cleanup any created test sessions
    if (!empty($testSessionHashes)) {
        $inClause = implode(',', array_fill(0, count($testSessionHashes), '?'));
        $delStmt = $pdo->prepare("DELETE FROM pos_sessions WHERE token_hash IN ({$inClause})");
        $delStmt->execute($testSessionHashes);
    }
}

echo "\n========================================================\n";
if ($allPassed) {
    echo "ALL 11 POS AUTHENTICATION, PERMISSION & VOID TESTS PASSED!\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "SOME POS AUTHENTICATION TESTS FAILED!\n";
    echo "========================================================\n";
    exit(1);
}
