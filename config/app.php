<?php
declare(strict_types=1);

// Application environment: 'production' or 'development'
if (!defined('APP_ENV')) {
    $env = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? null);
    if (!$env && (file_exists(dirname(__DIR__) . '/storage/development.flag') || file_exists(dirname(__DIR__) . '/.env.dev'))) {
        $env = 'development';
    }
    define('APP_ENV', $env ?: 'production');
}

// Application settings
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Car Wash Service Pro');
}
if (!defined('APP_NAME_KM')) {
    define('APP_NAME_KM', 'សេវាកម្មលាងរថយន្ត & ម៉ូតូ');
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
if (!defined('APP_URL')) {
    define('APP_URL', '/Car-WashService');
}

// Base URL for web and external POS API connections
// Change this in ONE place when moving to cloud (e.g. 'https://api.mycarwash.com' or 'http://192.168.1.100/Car-WashService')
if (!defined('API_BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('API_BASE_URL', $protocol . '://' . $host . APP_URL);
}

// Demo 1-Click Login Security Switch:
// Strictly disabled by default (false). Neutralizes 1-click passwordless logins and demo credentials.
if (!defined('ALLOW_DEMO_LOGIN')) {
    define('ALLOW_DEMO_LOGIN', false);
}

// Optional list of extra client IPs allowed to use demo one-click login for testing (e.g. ['192.168.1.50'])
if (!defined('DEMO_ALLOWED_IPS')) {
    define('DEMO_ALLOWED_IPS', []);
}

// Active Cashier POS UI: 'new' (./pos/index.html) or 'legacy' (views/cashier/pos.php)
if (!defined('POS_UI')) {
    define('POS_UI', 'new');
}

// Currency & Exchange Rate: 1 USD = 4,100 KHR
if (!defined('DEFAULT_EXCHANGE_RATE')) {
    define('DEFAULT_EXCHANGE_RATE', 4100);
}

// Storage & Upload Configuration (cloud-ready: change base directory / URL here in ONE place)
if (!defined('STORAGE_UPLOAD_DIR')) {
    define('STORAGE_UPLOAD_DIR', dirname(__DIR__) . '/public/uploads');
}
if (!defined('STORAGE_UPLOAD_URL')) {
    define('STORAGE_UPLOAD_URL', API_BASE_URL . '/public/uploads');
}
if (!defined('MAX_IMAGE_UPLOAD_BYTES')) {
    define('MAX_IMAGE_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB max per image
}

// Global Exception & Error Handler (never show technical errors to users)
require_once dirname(__DIR__) . '/core/ErrorHandler.php';
ErrorHandler::init();

// Session cookie security defaults
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}

