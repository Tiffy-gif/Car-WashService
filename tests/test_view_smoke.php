<?php
declare(strict_types=1);

/**
 * Automated View HTTP Smoke Test (CLI)
 * 
 * Logs in over HTTP using demo login (index.php?action=demo_login&role=owner on localhost with demo_mode set),
 * iterates over every ?view=... page in router map (index.php),
 * verifies no HTTP 500, "Something Went Wrong", "Uncaught", "Fatal error", or "Warning:".
 * Prints PASS/FAIL and exits non-zero on failure.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';

$pdo = Database::getInstance();

// 1. Ensure demo_mode is enabled for login, and restore original state on shutdown
$origStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'demo_mode'");
$origStmt->execute();
$originalDemoMode = $origStmt->fetchColumn();

if ($originalDemoMode === false || $originalDemoMode === null) {
    $originalDemoMode = '0';
}

$restoreDemoMode = function() use ($pdo, $originalDemoMode) {
    try {
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'demo_mode'");
        $stmt->execute([$originalDemoMode]);
    } catch (Throwable $t) {
        // Ignore
    }
};
register_shutdown_function($restoreDemoMode);

// Temporarily set demo_mode = '1'
$pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('demo_mode', '1') ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();

// 2. Discover views from router map in index.php
$indexContent = file_get_contents(__DIR__ . '/../index.php');
$views = [];
if (preg_match('/\$viewMap\s*=\s*\[(.*?)\];/s', $indexContent, $mapMatch)) {
    if (preg_match_all('/[\'"]([a-zA-Z0-9_]+)[\'"]\s*=>/', $mapMatch[1], $keyMatches)) {
        $views = $keyMatches[1];
    }
}

if (empty($views)) {
    $views = [
        'login', 'change_pin', 'dashboard', 'services', 'customers',
        'pos', 'shift', 'close_day', 'orders', 'bays_queue', 'bays',
        'commissions', 'receipt', 'manual_receipt', 'washer_jobs',
        'mechanic_jobs', 'vehicle_history', 'expenses', 'reports',
        'extras', 'staff', 'oil_care', 'stock_ledger', 'pos_devices',
        'categories', 'roles', 'settings', 'sync_monitor', 'audit_logs',
        'paper_forms', 'recovery_bulk_entry'
    ];
}

// 3. Determine base URL (default http://127.0.0.1/Car-WashService)
$baseUrl = getenv('APP_HTTP_URL') ?: 'http://127.0.0.1/Car-WashService';
$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smoke_cookie_' . uniqid() . '.txt';

register_shutdown_function(function() use ($cookieFile) {
    if (file_exists($cookieFile)) {
        @unlink($cookieFile);
    }
});

echo "====================================================================\n";
echo "           VIEW HTTP SMOKE TEST (OWNER ROLE)                        \n";
echo "====================================================================\n";
echo "Base URL: {$baseUrl}\n";
echo "Discovered " . count($views) . " view routes to test.\n\n";

function fetchUrl(string $url, string $cookieFile, array $extraOpts = []): array {
    $ch = curl_init($url);
    $defaultOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'ViewSmokeTest/1.0',
    ];
    curl_setopt_array($ch, $extraOpts + $defaultOpts);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $body === false ? '' : (string)$body,
        'url'  => $effectiveUrl,
        'error'=> $error,
    ];
}

// 4. Perform demo login
echo "[*] Logging in via: {$baseUrl}/index.php?action=demo_login&role=owner ...\n";
$loginRes = fetchUrl($baseUrl . '/index.php?action=demo_login&role=owner', $cookieFile);

if ($loginRes['code'] >= 400 || !empty($loginRes['error'])) {
    echo "[-] Login failed! HTTP {$loginRes['code']}, Error: {$loginRes['error']}\n";
    $restoreDemoMode();
    exit(1);
}

echo "[+] Successfully logged in as owner.\n\n";

// 5. Test each view
$passCount = 0;
$failCount = 0;
$failedViews = [];

// Error patterns: HTTP 500, "Something Went Wrong", "Uncaught", "Fatal error", or "Warning:"
$errorRegex = '/(Something Went Wrong|Fatal error|Uncaught|Warning:|<b>Warning<\/b>:|<b>Fatal error<\/b>:)/';

foreach ($views as $view) {
    // If testing receipt, order id might be required for full page render, or test=1
    // For view=receipt, test with test=1 to verify full layout rendering if needed
    $query = ($view === 'receipt') ? '?view=receipt&test=1' : '?view=' . urlencode($view);
    $testUrl = $baseUrl . '/index.php' . $query;
    $res = fetchUrl($testUrl, $cookieFile);

    $is500 = ($res['code'] === 500);
    $hasErrorText = false;
    $matchedError = '';

    if (preg_match($errorRegex, $res['body'], $matches)) {
        $hasErrorText = true;
        $matchedError = $matches[1];
    }

    if ($is500 || $hasErrorText) {
        $failCount++;
        $failedViews[] = $view;
        $reason = $is500 ? "HTTP 500" : "Found '{$matchedError}'";
        echo "  [FAIL] ?view={$view} -> {$reason} (HTTP {$res['code']})\n";
    } else {
        $passCount++;
        echo "  [PASS] ?view={$view} (HTTP {$res['code']})\n";
    }
}

// Restore original demo_mode
$restoreDemoMode();

echo "\n--------------------------------------------------------------------\n";
echo "Summary: {$passCount} passed, {$failCount} failed out of " . count($views) . " views.\n";
echo "--------------------------------------------------------------------\n";

if ($failCount > 0) {
    echo "Failed views: " . implode(', ', $failedViews) . "\n";
    exit(1);
}

echo "[OK] All views passed smoke test!\n";
exit(0);
