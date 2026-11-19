<?php
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();
$baseUrl = 'http://localhost/Car-WashService/api/pos/index.php';
$deviceKey = 'my_test_device_key_unique9';

function apiCall($action, $payload = [], $token = null) {
    global $baseUrl, $deviceKey;
    $url = "{$baseUrl}?action={$action}";
    $headers = [
        'Content-Type: application/json',
        "X-Device-Key: {$deviceKey}"
    ];
    if ($token) {
        $headers[] = "X-Staff-Token: {$token}";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$httpCode, json_decode($response, true)];
}

echo "=== 1. LOGIN AS CASHIER VISAL ===\n";
list($status, $loginRes) = apiCall('staff_login', ['username' => 'visal', 'pin' => '123456']);
echo "Login status: {$status}, token: " . substr($loginRes['session_token'] ?? 'none', 0, 8) . "\n";
if (empty($loginRes['success'])) {
    die("Cashier login failed!\n");
}
$token = $loginRes['session_token'];

echo "=== 2. PREPARE DATABASE (Close shifts & clear today's close record) ===\n";
$pdo->query("UPDATE cashier_shifts SET status = 'ended', ended_at = NOW() WHERE status = 'open'");
$pdo->query("DELETE FROM pos_daily_closes WHERE close_date = CURDATE()");
echo "Active shifts ended and today close record cleared.\n";

echo "=== 3. VERIFY CASHIER CREDENTIALS FOR CLOSE DAY ===\n";
list($vStatus, $vRes) = apiCall('verify_manager_pin', [
    'manager_username' => 'visal',
    'manager_pin' => '123456',
    'purpose' => 'close_day'
], $token);
echo "verify_manager_pin purpose='close_day': status={$vStatus}\n";
print_r($vRes);
if (empty($vRes['success']) || ($vRes['manager']['role'] ?? '') !== 'cashier') {
    die("FAILED: Cashier credentials not verified for close day!\n");
}

echo "=== 4. EXECUTE CLOSE DAY (Z-REPORT) BY CASHIER VISAL ===\n";
list($closeStatus, $closeRes) = apiCall('close_day_zreport', [
    'manager_username' => 'visal',
    'manager_pin' => '123456',
    'counted_cash_usd' => 55.00,
    'counted_cash_khr' => 0,
    'notes' => 'Day closed by cashier Visal test'
], $token);
echo "close_day_zreport: status={$closeStatus}\n";
print_r($closeRes);
if (empty($closeRes['success']) || ($closeRes['closed_by_name'] ?? '') !== 'visal' || ($closeRes['role'] ?? '') !== 'cashier') {
    die("FAILED: close_day_zreport failed for cashier!\n");
}

echo "=== 5. CHECK POS_DAILY_CLOSES TABLE ===\n";
$stmt = $pdo->query("SELECT id, close_date, closed_by_name, role, z_report_number, status FROM pos_daily_closes WHERE close_date = CURDATE() ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($row);
if (!$row || $row['closed_by_name'] !== 'visal' || $row['role'] !== 'cashier' || $row['status'] !== 'closed') {
    die("FAILED: pos_daily_closes verification failed!\n");
}

echo "=== 6. CHECK AUDIT_LOGS TABLE ===\n";
$stmtAudit = $pdo->query("SELECT id, log_uid, action, username, role, details, created_at FROM audit_logs WHERE action = 'DAY_CLOSED' ORDER BY id DESC LIMIT 1");
$auditRow = $stmtAudit->fetch(PDO::FETCH_ASSOC);
print_r($auditRow);
if (!$auditRow || $auditRow['username'] !== 'visal' || $auditRow['role'] !== 'cashier' || strpos($auditRow['details'], 'visal') === false) {
    die("FAILED: audit_logs verification failed!\n");
}

echo "\n>>> SUCCESS: ALL CASHIER CLOSE DAY BACKEND AND AUDIT TESTS PASSED! <<<\n";
