<?php
declare(strict_types=1);

class ErrorHandler {
    private static string $logPath = '';

    public static function init(): void {
        self::$logPath = dirname(__DIR__) . '/storage/logs/app_error.log';
        $logDir = dirname(self::$logPath);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Hide raw PHP errors from public display
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', self::$logPath);
        error_reporting(E_ALL);

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function log(string $level, string $message, array $context = []): void {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? 'CLI';
        $userId = 'guest';

        if (class_exists('Auth', false)) {
            try {
                if (Auth::check()) {
                    $userId = 'user_id:' . Auth::getUserId() . '(' . Auth::getRole() . ')';
                }
            } catch (Throwable $t) {}
        }

        $contextStr = '';
        if (!empty($context)) {
            // Mask sensitive fields
            $safeContext = self::maskSensitive($context);
            $contextStr = ' | Context: ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $logLine = "[{$timestamp}] [{$level}] [{$ip}] [{$userId}] [{$method} {$uri}] {$message}{$contextStr}" . PHP_EOL;
        @file_put_contents(self::$logPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $level = match ($severity) {
            E_ERROR, E_USER_ERROR => 'ERROR',
            E_WARNING, E_USER_WARNING => 'WARNING',
            E_NOTICE, E_USER_NOTICE => 'NOTICE',
            default => 'INFO'
        };

        self::log($level, "{$message} in {$file}:{$line}");

        if ($severity === E_ERROR || $severity === E_USER_ERROR) {
            self::renderFriendlyError("A system processing error occurred.");
            exit;
        }

        return true;
    }

    public static function handleException(Throwable $e): void {
        $message = sprintf(
            "Uncaught Exception %s: \"%s\" in %s:%d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        self::log('CRITICAL', $message);
        self::renderFriendlyError();
        exit;
    }

    public static function handleShutdown(): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::log('FATAL', "Fatal error: {$error['message']} in {$error['file']}:{$error['line']}");
            self::renderFriendlyError();
        }
    }

    private static function isApiRequest(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (str_contains($uri, '/api/')) {
            return true;
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        return false;
    }

    public static function renderFriendlyError(string $customMessage = ''): void {
        if (ob_get_level()) {
            @ob_clean();
        }

        if (self::isApiRequest()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'SERVER_ERROR',
                'message' => 'An unexpected error occurred while processing your request. The issue has been safely logged for the administrator.'
            ]);
            return;
        }

        http_response_code(500);
        $appUrl = defined('APP_URL') ? APP_URL : '/Car-WashService';
        $appName = defined('APP_NAME') ? APP_NAME : 'Car Wash Service Pro';
        $appNameKm = defined('APP_NAME_KM') ? APP_NAME_KM : 'សេវាកម្មលាងរថយន្ត & ម៉ូតូ';

        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notice - <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($appUrl) ?>/public/css/style.css">
    <style>
        body {
            background-color: var(--bg-body, #f8fafc);
            color: var(--text-main, #0f172a);
            font-family: var(--font-sans, system-ui, sans-serif);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .error-card {
            background: var(--bg-surface, #ffffff);
            border: 1px solid var(--border, #e2e8f0);
            border-radius: var(--radius-lg, 16px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08);
            max-width: 480px;
            width: 100%;
            padding: 36px 30px;
            text-align: center;
        }
        .error-icon {
            width: 68px;
            height: 68px;
            background: #fffbeb;
            color: #d97706;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
            border: 1px solid #fef3c7;
        }
        .error-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-main, #0f172a);
            margin-bottom: 8px;
        }
        .error-subtitle {
            font-size: 14px;
            font-weight: 600;
            color: #d97706;
            margin-bottom: 16px;
        }
        .error-desc {
            font-size: 14px;
            color: var(--text-muted, #64748b);
            line-height: 1.6;
            margin-bottom: 28px;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h1 class="error-title">Something Went Wrong</h1>
        <div class="error-subtitle">មានបញ្ហាមិនរំពឹងទុកមួយបានកើតឡើង</div>
        <p class="error-desc">
            We encountered an unexpected issue while processing your request. 
            The technical details have been safely recorded in our private system log for the administrator.
        </p>
        <div class="error-actions">
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left mr-1"></i> Go Back
            </a>
            <a href="<?= htmlspecialchars($appUrl) ?>/index.php" class="btn btn-primary">
                <i class="fa-solid fa-house mr-1"></i> Return to Home
            </a>
        </div>
    </div>
</body>
</html>
        <?php
    }

    private static function maskSensitive(array $data): array {
        $keysToMask = ['password', 'password_hash', 'current_password', 'new_password', 'token', 'csrf_token', 'device_key'];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = self::maskSensitive($v);
            } elseif (in_array(strtolower((string)$k), $keysToMask, true)) {
                $data[$k] = '********';
            }
        }
        return $data;
    }
}
