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

} finally {
    // Cleanup any created test sessions
    if (!empty($testSessionHashes)) {
        $inClause = implode(',', array_fill(0, count($testSessionHashes), '?'));
        $delStmt = $pdo->prepare("DELETE FROM pos_sessions WHERE token_hash IN ({$inClause})");
        $delStmt->execute($testSessionHashes);
    }
}

echo "\n========================================================\n";
if ($allPassed) {
    echo "ALL 5 POS AUTHENTICATION & PERMISSION TESTS PASSED!\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "SOME POS AUTHENTICATION TESTS FAILED!\n";
    echo "========================================================\n";
    exit(1);
}
