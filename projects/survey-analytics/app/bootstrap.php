<?php
declare(strict_types=1);

session_start();

define('APP_ROOT', dirname(__DIR__));

require_once APP_ROOT . '/app/config.php';
require_once APP_ROOT . '/app/database.php';
require_once APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/forms.php';
require_once APP_ROOT . '/app/metrics.php';
require_once APP_ROOT . '/app/email_parser.php';
require_once APP_ROOT . '/app/importer.php';

date_default_timezone_set(config('APP_TIMEZONE', 'Asia/Yekaterinburg'));

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $configured = config('APP_BASE_PATH', null);
    if ($configured !== null && trim($configured) !== '') {
        $path = trim($configured, '/');
        return $path === '' ? '' : '/' . $path;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (($position = strpos($script, '/public/')) !== false) {
        return rtrim(substr($script, 0, $position), '/');
    }

    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $directory === '/' ? '' : $directory;
}

function url_for(string $path): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function truncate_text(string $value, int $length = 140): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($value, 0, $length, '...', 'UTF-8');
    }
    return strlen($value) > $length ? substr($value, 0, $length - 3) . '...' : $value;
}

function redirect(string $path): void
{
    header('Location: ' . url_for($path));
    exit;
}
