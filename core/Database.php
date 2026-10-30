<?php
declare(strict_types=1);

if (!class_exists('Database')) {
class Database {
    private static ?PDO $instance = null;
    private static string $driver = 'mysql';

    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $config = require dirname(__DIR__) . '/config/database.php';
            
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

    private function __construct() {}
    private function __clone() {}
}
}
