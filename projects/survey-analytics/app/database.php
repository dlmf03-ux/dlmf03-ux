<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = config('DB_HOST', 'localhost');
    $port = config('DB_PORT', '3306');
    $name = config('DB_NAME', 'survey_analytics');
    $charset = config('DB_CHARSET', 'utf8mb4');
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    $pdo = new PDO($dsn, config('DB_USER', ''), config('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
