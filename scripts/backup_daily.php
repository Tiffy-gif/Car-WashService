<?php
declare(strict_types=1);

/**
 * Daily Automated Database Backup Job (Runs at 2:00 AM via Windows Task Scheduler)
 * 
 * - Generates dated SQL dump: storage/backups/CarWash_AutoBackup_YYYY-MM-DD.sql
 * - Retains last 14 days of backups, automatically pruning older files
 * - Writes activity log to storage/logs/backup.log
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Backup.php';

try {
    $pdo = Database::getInstance();
    $res = Backup::runDailyBackup($pdo, null, 14);

    echo "SUCCESS: Backup saved to {$res['filename']} ({$res['size_kb']} KB)\n";
    if (!empty($res['pruned_files'])) {
        echo "Pruned " . count($res['pruned_files']) . " old backups (>14 days): " . implode(', ', $res['pruned_files']) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $err = "[ERROR] Daily backup failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    @file_put_contents(dirname(__DIR__) . '/storage/logs/backup.log', "[" . date('Y-m-d H:i:s') . "] " . $err, FILE_APPEND);
    fwrite(STDERR, $err);
    exit(1);
}
