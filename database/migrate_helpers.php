<?php
declare(strict_types=1);

if (!function_exists('migrateLog')) {
    /**
     * Central Migration Logger.
     * Writes to storage/logs/migration.log.
     * Echoes to stdout ONLY when running in CLI mode.
     * Never outputs text to web pages.
     */
    function migrateLog(string $message, string $level = 'INFO'): void {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logLine = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        @file_put_contents($logDir . '/migration.log', $logLine, FILE_APPEND | LOCK_EX);

        // Echo only when explicitly run from the command line interface
        if (php_sapi_name() === 'cli') {
            echo $message . PHP_EOL;
        }
    }
}
