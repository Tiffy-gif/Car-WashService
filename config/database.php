<?php
declare(strict_types=1);

return [
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'dbname'   => 'carwash_db',
    'user'     => 'root',
    'pass'     => '',
    'charset'  => 'utf8mb4',
    // Fallback SQLite file if MySQL connection fails
    'sqlite_path' => dirname(__DIR__) . '/database/carwash.sqlite',
];
