<?php
declare(strict_types=1);

function is_authenticated(): bool
{
    return !empty($_SESSION['auth_ok']);
}

function require_auth(): void
{
    if (!is_authenticated()) {
        redirect('/login.php');
    }
}

function attempt_login(string $password): bool
{
    $expected = (string)config('APP_PASSWORD', '');
    if ($expected !== '' && hash_equals($expected, $password)) {
        $_SESSION['auth_ok'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
