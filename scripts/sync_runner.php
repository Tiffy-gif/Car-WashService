<?php
declare(strict_types=1);

/**
 * Car Wash Back Office - Dedicated CLI Background Cloud Sync Runner
 * 
 * Periodically executed by Windows Task Scheduler (e.g. every 3 minutes).
 * Synchronizes local append-only operational ledgers with the Cloud Master,
 * pulls latest master catalog, staff users, and system rules.
 * Uses a non-blocking filesystem lock to prevent overlapping runs.
 */

// 1. Enforce CLI-only execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "ERROR: scripts/sync_runner.php must be run from the command line interface (CLI).\n";
    exit(1);
}

// 2. Load core environment and dependencies
require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/CloudSyncManager.php';

// 3. Acquire exclusive lock (non-blocking)
$lockFile = CloudSyncManager::getLockFile();
$lockDir = dirname($lockFile);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}

$lockFp = @fopen($lockFile, 'c+');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Sync runner already active or lock held. Execution skipped.\n";
    if ($lockFp) {
        fclose($lockFp);
    }
    exit(0);
}

// Record runner PID and timestamp in lock file
@ftruncate($lockFp, 0);
@fwrite($lockFp, (string)getmypid() . "\n" . date('Y-m-d H:i:s'));
fflush($lockFp);

try {
    $pdo = Database::getInstance();
    $startTime = microtime(true);

    // 4. Run sendToCloud (PUSH) then receiveFromCloud (PULL)
    $pushRes = CloudSyncManager::sendToCloud($pdo);
    $pullRes = CloudSyncManager::receiveFromCloud($pdo);

    $durationMs = (int)round((microtime(true) - $startTime) * 1000);

    $pushStatus = $pushRes['success'] ? 'ok' : ($pushRes['error'] ?? 'failed');
    $pushCount = (int)($pushRes['sent_count'] ?? 0);

    $pullStatus = $pullRes['success'] ? 'ok' : ($pullRes['error'] ?? 'failed');
    $pullCount = (int)(($pullRes['received_count'] ?? 0) + ($pullRes['applied_count'] ?? 0));
    $conflicts = (int)($pullRes['conflicts'] ?? 0);

    // One-line summary output
    printf(
        "[%s] Push: %d sent (%s), Pull: %d received (%s, conflicts: %d), Duration: %d ms\n",
        date('Y-m-d H:i:s'),
        $pushCount,
        $pushStatus,
        $pullCount,
        $pullStatus,
        $conflicts,
        $durationMs
    );
} catch (Throwable $e) {
    printf("[%s] Sync exception: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
    exit(1);
} finally {
    // 5. Release lock safely
    if ($lockFp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}
