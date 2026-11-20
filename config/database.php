<?php
declare(strict_types=1);

/**
 * Database Configuration
 * Resolves CARWASH_DB_NAME across constant, $_ENV, $_SERVER, and getenv().
 * Properly handles empty strings and environment variable access.
 */
$resolvedDbName = null;
if (defined('CARWASH_DB_NAME') && is_string(CARWASH_DB_NAME) && trim(CARWASH_DB_NAME) !== '') {
    $resolvedDbName = trim(CARWASH_DB_NAME);
} elseif (!empty($_ENV['CARWASH_DB_NAME']) && is_string($_ENV['CARWASH_DB_NAME']) && trim($_ENV['CARWASH_DB_NAME']) !== '') {
    $resolvedDbName = trim($_ENV['CARWASH_DB_NAME']);
} elseif (!empty($_SERVER['CARWASH_DB_NAME']) && is_string($_SERVER['CARWASH_DB_NAME']) && trim($_SERVER['CARWASH_DB_NAME']) !== '') {
    $resolvedDbName = trim($_SERVER['CARWASH_DB_NAME']);
} else {
    $envVal = getenv('CARWASH_DB_NAME');
    if ($envVal !== false && is_string($envVal) && trim($envVal) !== '') {
        $resolvedDbName = trim($envVal);
    }
}

return [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => $resolvedDbName ?: 'carwash_db',
    'user'     => 'root',
    'pass'     => '',
    'charset'  => 'utf8mb4',
    // Fallback SQLite file if MySQL connection fails
    'sqlite_path' => dirname(__DIR__) . '/database/carwash.sqlite',
];
