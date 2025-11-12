<?php

return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DATABASE_URL'),
            'database' => ':memory:', // Use in-memory database for simplicity
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DATABASE_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'matching_db'),
            'username' => env('DB_USERNAME', 'matching_user'),
            'password' => env('DB_PASSWORD', 'matching_password'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    'migrations' => 'migrations',
];