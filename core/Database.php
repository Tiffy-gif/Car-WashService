<?php
declare(strict_types=1);

if (!class_exists('Database')) {
class Database {
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';
    private static bool $allowMainDbCli = false;

    /**
     * Explicitly grant or revoke permission for CLI execution against the main database.
     */
    public static function allowMainDbCli(bool $allow = true): void {
        self::$allowMainDbCli = $allow;
    }

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $config = require dirname(__DIR__) . '/config/database.php';

            // CLI Safety Guard: Refuse to connect in command-line mode unless target DB ends with '_test',
            // or an explicit setting allows the main database for production CLI jobs.
            if (php_sapi_name() === 'cli') {
                $targetDb = (string)($config['dbname'] ?? '');
                if (!str_ends_with($targetDb, '_test')) {
                    $cliArgs = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
                    $hasCliFlag = in_array('--allow-main-db', $cliArgs, true)
                        || in_array('--allow-main-db-cli', $cliArgs, true)
                        || in_array('ALLOW_MAIN_DB_CLI=true', $cliArgs, true);

                    $isAllowed = self::$allowMainDbCli
                        || (defined('ALLOW_MAIN_DB_CLI') && ALLOW_MAIN_DB_CLI === true)
                        || $hasCliFlag
                        || (!empty($_ENV['ALLOW_MAIN_DB_CLI']) && in_array(strtolower((string)$_ENV['ALLOW_MAIN_DB_CLI']), ['1', 'true', 'yes'], true))
                        || (!empty($_SERVER['ALLOW_MAIN_DB_CLI']) && in_array(strtolower((string)$_SERVER['ALLOW_MAIN_DB_CLI']), ['1', 'true', 'yes'], true))
                        || (getenv('ALLOW_MAIN_DB_CLI') !== false && in_array(strtolower((string)getenv('ALLOW_MAIN_DB_CLI')), ['1', 'true', 'yes'], true));

                    if (!$isAllowed) {
                        throw new RuntimeException(
                            "CRITICAL SAFETY HALT: Command-line script attempted to connect to main database '{$targetDb}'.\n" .
                            "CLI scripts are strictly restricted to databases ending with '_test' (e.g. 'carwash_db_test').\n" .
                            "To run this script on the main database, pass the environment variable or flag:\n" .
                            "  ALLOW_MAIN_DB_CLI=true php <script.php>  (or add --allow-main-db)\n"
                        );
                    }
                }
            }
            
            try {
                // Try MySQL first
                $portStr = !empty($config['port']) ? ";port={$config['port']}" : '';
                $charset = $config['charset'] ?? 'utf8mb4';
                $dsn = "mysql:host={$config['host']}{$portStr};dbname={$config['dbname']};charset={$charset}";
                
                self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                self::$driver = 'mysql';
                self::$instance->exec("SET time_zone = '+07:00'");
            } catch (PDOException $e) {
                // If database does not exist, try to create it on MySQL
                try {
                    $dsnNoDb = "mysql:host={$config['host']}{$portStr};charset={$charset}";
                    $tempPdo = new PDO($dsnNoDb, $config['user'], $config['pass']);
                    $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    
                    self::$instance = new PDO($dsn, $config['user'], $config['pass'], [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    self::$driver = 'mysql';
                } catch (PDOException $innerEx) {
                    // Fallback to SQLite
                    $sqlitePath = $config['sqlite_path'];
                    $sqliteDir = dirname($sqlitePath);
                    if (!is_dir($sqliteDir)) {
                        mkdir($sqliteDir, 0777, true);
                    }
                    self::$instance = new PDO("sqlite:{$sqlitePath}", null, null, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]);
                    self::$driver = 'sqlite';
                    self::$instance->exec('PRAGMA foreign_keys = ON;');
                }
            }
        }
        return self::$instance;
    }

    public static function getDriver(): string {
        return self::$driver;
    }

    public static function setInstance(?PDO $instance): void {
        self::$instance = $instance;
    }

    private function __construct() {}
    private function __clone() {}
}
}
