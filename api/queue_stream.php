<?php
declare(strict_types=1);

/**
 * Server-Sent Events (SSE) Stream for Queue TV Display
 * Emits queue updates only when data changes, with keep-alive comments.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/Helpers.php';
require_once __DIR__ . '/../core/BayManager.php';

$appTimezone = get_setting('app_timezone', 'Asia/Phnom_Penh');
if (!empty($appTimezone)) {
    @date_default_timezone_set($appTimezone);
}

$pdo = Database::getInstance();
BayManager::ensureTables($pdo);

// 1. Authorization check matching tv.php
$tvEnabled = get_setting('tv_display_enabled', '1');
$tvSecret = get_setting('tv_display_code', 'tv-office-88');
$providedCode = (string)($_GET['code'] ?? $_GET['token'] ?? '');
$isAuthorized = ($tvEnabled === '1' && $tvSecret !== '' && hash_equals($tvSecret, $providedCode));

// Disable compression and buffer holding
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

if (!$isAuthorized) {
    http_response_code(403);
    echo "event: error\n";
    echo "data: " . json_encode(['success' => false, 'error' => 'NOT_AVAILABLE', 'message' => 'Not available']) . "\n\n";
    @flush();
    exit;
}

// 2. Stream duration configuration
$maxSeconds = (int)get_setting('tv_stream_max_seconds', '25');
if ($maxSeconds < 5 || $maxSeconds > 120) {
    $maxSeconds = 25;
}

// 3. Execution limits and connection management
@set_time_limit($maxSeconds + 10);
ignore_user_abort(false);

// Release session lock before long-running loop so concurrent requests are never blocked
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

while (ob_get_level() > 0) {
    @ob_end_flush();
}
ob_implicit_flush(true);

$startTime = time();
$lastKeepAlive = $startTime;
$lastPayloadHash = isset($_GET['last_hash']) && $_GET['last_hash'] !== '' ? (string)$_GET['last_hash'] : null;

// Initial connection comment
echo ": connected at " . date('c') . "\n\n";
@flush();

// 4. Stream Loop: checks every 1 second, sends event on change, keep-alive every 10s
while ((time() - $startTime) < $maxSeconds) {
    if (connection_aborted()) {
        break;
    }

    $data = BayManager::getTvDisplayData($pdo);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $currentHash = md5($json);

    if ($currentHash !== $lastPayloadHash) {
        $lastPayloadHash = $currentHash;
        echo "id: " . $currentHash . "\n";
        echo "event: queue_update\n";
        echo "data: " . $json . "\n\n";
        @flush();
    }

    // Keep-alive comment every 10 seconds
    if ((time() - $lastKeepAlive) >= 10) {
        echo ": keep-alive " . time() . "\n\n";
        @flush();
        $lastKeepAlive = time();
    }

    sleep(1);
}

// End of cycle: closing connection allows EventSource to reconnect cleanly
echo ": cycle_complete\n\n";
@flush();
exit;
