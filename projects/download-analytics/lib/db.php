<?php

function demo_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/../config.php';
    if (!is_file($path)) {
        http_response_code(500);
        echo 'Missing config.php';
        exit;
    }

    $config = require $path;
    return $config;
}

function demo_pdo(): PDO
{
    $db = demo_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function demo_client_ip(): string
{
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_REAL_IP'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $ip) {
        if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

function demo_require_admin(): void
{
    $admin = demo_config()['admin'];
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    if (!hash_equals($admin['user'], $user) || !hash_equals($admin['pass'], $pass)) {
        header('WWW-Authenticate: Basic realm="Demo Analytics"');
        http_response_code(401);
        echo 'Authorization required';
        exit;
    }
}


