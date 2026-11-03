<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI access only.\n";
    exit(1);
}

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';

echo "====================================================\n";
echo "   Car-WashService Go-Live Pre-Flight Verification   \n";
echo "====================================================\n\n";

$allPassed = true;

function printResult(string $title, bool $ok, string $details = ''): void {
    global $allPassed;
    if (!$ok) {
        $allPassed = false;
        echo sprintf("[ FAIL ] %-42s %s\n", $title, ($details !== '' ? "({$details})" : ''));
    } else {
        echo sprintf("[  OK  ] %-42s %s\n", $title, ($details !== '' ? "({$details})" : ''));
    }
}

try {
    $pdo = Database::getInstance();
} catch (Throwable $t) {
    echo "Fatal Error: Cannot connect to database: " . $t->getMessage() . "\n";
    exit(1);
}

// 1. demo_mode is 0
$demoMode = function_exists('get_setting') ? get_setting('demo_mode', '1') : '1';
if ($demoMode === null || $demoMode === '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'demo_mode' LIMIT 1");
    $stmt->execute();
    $demoMode = (string)$stmt->fetchColumn();
}
$isDemoOff = ($demoMode === '0');
printResult("demo_mode is 0", $isDemoOff, "Current: " . ($demoMode ?: 'unset'));

// 2. no sample accounts active
$sampleStmt = $pdo->query("SELECT id, username, role, status FROM users WHERE is_sample_account = 1 AND status = 'active'");
$activeSamples = $sampleStmt ? $sampleStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$noActiveSamples = empty($activeSamples);
$sampleDetail = $noActiveSamples ? '0 active sample accounts' : count($activeSamples) . ' active: ' . implode(', ', array_column($activeSamples, 'username'));
printResult("No sample accounts active", $noActiveSamples, $sampleDetail);

// 3. no pos_devices with key or name containing 'test' (only counting active devices)
$knownTestKeyHashes = [
    hash('sha256', 'pos_sec_test_key_8899aabb'),
];
$devStmt = $pdo->query("SELECT id, device_name, device_uid, api_key, api_key_hash, status FROM pos_devices");
$allDevices = $devStmt ? $devStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$badDevices = [];
foreach ($allDevices as $dr) {
    if (($dr['status'] ?? '') !== 'active') {
        continue;
    }
    $hasTestName = (stripos($dr['device_name'], 'test') !== false || stripos($dr['device_uid'], 'test') !== false);
    $hasTestKey = (!empty($dr['api_key']) && stripos($dr['api_key'], 'test') !== false);
    $hasKnownTestHash = (!empty($dr['api_key_hash']) && in_array($dr['api_key_hash'], $knownTestKeyHashes, true));
    if ($hasTestName || $hasTestKey || $hasKnownTestHash) {
        $badDevices[] = "#{$dr['id']} '{$dr['device_name']}'";
    }
}
$noBadDevices = empty($badDevices);
$deviceDetail = $noBadDevices ? '0 active test devices' : count($badDevices) . ' active found: ' . implode(', ', $badDevices);
printResult("No pos_devices with test key or name", $noBadDevices, $deviceDetail);

// 3b. tv_display_code does not equal default 'tv-office-88'
$tvStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'tv_display_code' LIMIT 1");
$tvStmt->execute();
$tvCode = (string)$tvStmt->fetchColumn();
$tvCodeSafe = ($tvCode !== '' && $tvCode !== 'tv-office-88');
printResult("tv_display_code is not 'tv-office-88'", $tvCodeSafe, $tvCodeSafe ? "Current: {$tvCode}" : "Equals default 'tv-office-88'");

// 3c. Users owner/admin/cashier/washer1/washer2/mechanic1 do not verify against seed passwords
$seedAccounts = [
    'owner'     => ['admin123', '123456'],
    'admin'     => ['111111', 'admin123'],
    'cashier'   => ['cashier123', '123456'],
    'washer1'   => ['washer123', '123456'],
    'washer2'   => ['washer123', '123456'],
    'mechanic1' => ['mechanic123', '123456'],
];
$unsecuredAccounts = [];
$uStmt = $pdo->prepare("SELECT username, password_hash FROM users WHERE username = ?");
foreach ($seedAccounts as $uname => $passwords) {
    $uStmt->execute([$uname]);
    $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($uRow && !empty($uRow['password_hash'])) {
        foreach ($passwords as $pwd) {
            if (password_verify($pwd, $uRow['password_hash'])) {
                $unsecuredAccounts[] = $uname;
                break;
            }
        }
    }
}
$noSeedPasswords = empty($unsecuredAccounts);
printResult("No staff using known seed passwords", $noSeedPasswords, $noSeedPasswords ? "All seed passwords changed" : "Known seed password verified for: " . implode(', ', $unsecuredAccounts));

// 3d. Folders Pos-Carwash/ or scratch/ do not exist in web root
$rootPath = dirname(__DIR__);
$forbiddenFolders = ['Pos-Carwash', 'scratch'];
$existingForbidden = [];
foreach ($forbiddenFolders as $folder) {
    if (is_dir($rootPath . '/' . $folder)) {
        $existingForbidden[] = $folder . '/';
    }
}
$noForbiddenFolders = empty($existingForbidden);
printResult("No Pos-Carwash/ or scratch/ in web root", $noForbiddenFolders, $noForbiddenFolders ? "Clean web root" : "Found: " . implode(', ', $existingForbidden));

// 3e. No dev helper scripts or snapshots present
$devArtifacts = [
    dirname(__DIR__) . '/scripts/dev_reset_logins.php',
    dirname(__DIR__) . '/scripts/dev_data_tool.php',
    dirname(__DIR__) . '/storage/snapshots',
];
$foundDevArtifacts = [];
foreach ($devArtifacts as $art) {
    if (file_exists($art)) {
        $foundDevArtifacts[] = (is_dir($art) ? basename($art) . '/' : basename($art));
    }
}
$noDevArtifacts = empty($foundDevArtifacts);
printResult("No dev helper scripts or snapshots present", $noDevArtifacts, $noDevArtifacts ? "Clean: dev helper scripts and snapshots absent" : "Found: " . implode(', ', $foundDevArtifacts));

// 4. API_BASE_URL uses https
$apiUrl = defined('API_BASE_URL') ? API_BASE_URL : '';
$isHttps = (stripos($apiUrl, 'https://') === 0);
printResult("API_BASE_URL uses https", $isHttps, $apiUrl ?: 'undefined');

// 5. default MySQL root has a password (try connecting with an empty password)
$rootPasswordCheckOk = false;
$rootDetail = '';
try {
    $dbCfg = file_exists(dirname(__DIR__) . '/config/database.php') ? (require dirname(__DIR__) . '/config/database.php') : [];
    $dbHost = $dbCfg['host'] ?? '127.0.0.1';
    $dbPort = $dbCfg['port'] ?? 3306;
    $testPdo = @new PDO("mysql:host={$dbHost};port={$dbPort};charset=utf8mb4", 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    // If it connected successfully with an empty password, root has NO password!
    $rootPasswordCheckOk = false;
    $rootDetail = "Security vulnerability: connected to MySQL root with EMPTY password";
} catch (Throwable $e) {
    // If connection was refused/denied, root has a password or cannot be reached with empty password
    $msg = $e->getMessage();
    if (stripos($msg, 'Access denied') !== false || stripos($msg, 'password') !== false) {
        $rootPasswordCheckOk = true;
        $rootDetail = "Access denied with empty password (password is set)";
    } else {
        $rootPasswordCheckOk = true;
        $rootDetail = "Root empty login refused: " . substr($msg, 0, 40);
    }
}
printResult("Default MySQL root has a password", $rootPasswordCheckOk, $rootDetail);

// 6. storage/ is not reachable by HTTP (try fetching via http://localhost and expect 403/404)
$storageBlocked = false;
$storageDetail = '';
$probeUrls = [
    'http://localhost/Car-WashService/storage/',
    'http://127.0.0.1/Car-WashService/storage/'
];

foreach ($probeUrls as $probeUrl) {
    $ch = curl_init($probeUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (in_array($httpCode, [403, 404], true)) {
        $storageBlocked = true;
        $storageDetail = "HTTP Code {$httpCode} on {$probeUrl}";
        break;
    } else {
        $storageDetail = "HTTP Code {$httpCode} on {$probeUrl}";
    }
}
printResult("storage/ not reachable by HTTP", $storageBlocked, $storageDetail);

// 7. .htaccess rewrite rules active
$rewriteActive = false;
$rewriteDetail = '';
// In .htaccess line 36: RewriteRule ^(config|core|database|storage|tests|docs|bin|scripts)/ - [F,L,NC]
// Probing http://localhost/Car-WashService/database/ or config/ must return 403 Forbidden
$rewriteProbeUrl = 'http://localhost/Car-WashService/config/';
$ch = curl_init($rewriteProbeUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 403) {
    $rewriteActive = true;
    $rewriteDetail = "RewriteEngine blocking internal folders (HTTP 403)";
} else {
    $rewriteDetail = "Expected 403 for /config/, got {$httpCode}";
}
printResult(".htaccess rewrite rules active", $rewriteActive, $rewriteDetail);

// 8. CSP header present
$cspPresent = false;
$cspDetail = '';
$ch = curl_init('http://localhost/Car-WashService/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$rawHeaders = curl_exec($ch);
curl_close($ch);

if ($rawHeaders && stripos((string)$rawHeaders, 'Content-Security-Policy:') !== false) {
    $cspPresent = true;
    $cspDetail = "Content-Security-Policy header verified";
} else {
    $cspDetail = "Content-Security-Policy header missing from response";
}
printResult("CSP header present", $cspPresent, $cspDetail);

echo "\n----------------------------------------------------\n";
if ($allPassed) {
    echo "STATUS: ALL CHECKS PASSED - READY FOR PRODUCTION GO-LIVE!\n";
    exit(0);
} else {
    echo "STATUS: ONE OR MORE GO-LIVE CHECKS FAILED.\n";
    echo "Resolve the issues above before deploying to live production.\n";
    exit(1);
}
