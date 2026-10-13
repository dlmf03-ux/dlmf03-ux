<?php
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'name' => getenv('DB_NAME') ?: 'demo_analytics',
        'user' => getenv('DB_USER') ?: 'demo_user',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'admin' => [
        'user' => getenv('ADMIN_USER') ?: 'admin',
        'pass' => getenv('ADMIN_PASS') ?: '',
    ],
];


