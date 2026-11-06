<?php
declare(strict_types=1);

class Language {
    private static array $strings = [];
    private static string $currentLang = 'en';

    public static function init(): void {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/Auth.php';
            Auth::startSession();
        }

        // Check GET switch
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'km'], true)) {
            $_SESSION['lang'] = $_GET['lang'];
            setcookie('carwash_lang', $_GET['lang'], time() + (86400 * 30), '/');
        }

        $defaultLang = function_exists('get_setting') ? (get_setting('default_language', 'en') ?: 'en') : 'en';
        self::$currentLang = $_SESSION['lang'] ?? $_COOKIE['carwash_lang'] ?? $defaultLang;

        $langFile = dirname(__DIR__) . '/lang/' . self::$currentLang . '.json';
        if (file_exists($langFile)) {
            self::$strings = json_decode((string)file_get_contents($langFile), true) ?? [];
        }
    }

    public static function get(string $key, ?string $default = null): string {
        return self::$strings[$key] ?? $default ?? $key;
    }

    public static function current(): string {
        return self::$currentLang;
    }
}

// Global translation helper
function __(string $key, ?string $default = null): string {
    return Language::get($key, $default);
}
