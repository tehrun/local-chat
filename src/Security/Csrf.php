<?php

declare(strict_types=1);

namespace LocalChat\Security;

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? null;

        return is_string($sessionToken)
            && is_string($token)
            && $sessionToken !== ''
            && hash_equals($sessionToken, $token);
    }

    public static function require(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!self::verify(is_string($token) ? $token : null)) {
            jsonResponse(['error' => 'Invalid CSRF token.'], 419);
        }
    }
}
