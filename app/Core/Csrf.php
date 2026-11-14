<?php
declare(strict_types=1);
namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $stored = $_SESSION['_csrf'] ?? null;
        if (!is_string($stored) || !preg_match('/\A[a-f0-9]{64}\z/D', $stored)) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(mixed $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($token)
            && is_string($expected)
            && preg_match('/\A[a-f0-9]{64}\z/D', $expected) === 1
            && hash_equals($expected, $token);
    }
}
