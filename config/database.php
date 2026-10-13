<?php

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', storage_path('app/private/database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
