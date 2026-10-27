<?php
/** The single MySQL connection shared by the platform and every app. */
declare(strict_types=1);

return [
    'host'    => env('DB_HOST', '127.0.0.1'),
    'port'    => env('DB_PORT', 3306),
    'name'    => env('DB_NAME', 'raix'),
    'user'    => env('DB_USER', 'root'),
    'pass'    => env('DB_PASS', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',

    // Storage engine and row format for every generated table.
    'engine' => 'InnoDB',
];
