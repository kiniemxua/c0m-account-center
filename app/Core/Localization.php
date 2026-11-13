<?php
declare(strict_types=1);
namespace App\Core;

use PDO;

final class Localization
{
    private const SUPPORTED = ['vi', 'en'];
    private static string $locale = 'vi';
    private static array $messages = [];

    public static function initialize(): void
    {
        $locale = 'vi';
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId > 0) {
            try {
                $stmt = Database::connection()->prepare('SELECT language FROM user_profiles WHERE user_id = :id LIMIT 1');
                $stmt->execute(['id' => $userId]);
                $stored = $stmt->fetchColumn();
                if (is_string($stored) && in_array($stored, self::SUPPORTED, true)) {
                    $locale = $stored;
                    $_SESSION['locale'] = $stored;
                    self::writeGuestCookie($stored);
                } else {
                    $locale = self::valid($_SESSION['locale'] ?? null) ?? self::valid($_COOKIE['c0m_lang'] ?? null) ?? 'vi';
                }
            } catch (\Throwable $e) {
                error_log('Locale preference lookup failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
                $locale = self::valid($_SESSION['locale'] ?? null) ?? self::valid($_COOKIE['c0m_lang'] ?? null) ?? 'vi';
            }
        } else {
            $locale = self::valid($_SESSION['locale'] ?? null) ?? self::valid($_COOKIE['c0m_lang'] ?? null) ?? 'vi';
        }
        self::set($locale);
    }

    public static function current(): string { return self::$locale; }

    public static function set(string $locale): void
    {
        self::$locale = self::valid($locale) ?? 'vi';
        $_SESSION['locale'] = self::$locale;
        self::$messages = [];
    }

    public static function persistGuest(string $locale): void
    {
        self::set($locale);
        self::writeGuestCookie(self::$locale);
    }

    public static function writeGuestCookie(string $locale): void
    {
        $secure = SessionManager::requestIsSecure();
        setcookie('c0m_lang', self::valid($locale) ?? 'vi', [
            'expires' => time() + 31536000, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function translate(string $key, array $replace = []): string
    {
        $messages = self::messages();
        $value = $messages[$key] ?? null;
        if (!is_string($value)) {
            $fallback = self::load('vi');
            $value = $fallback[$key] ?? null;
        }
        if (!is_string($value)) {
            // Never expose missing source keys or technical identifiers.
            $value = self::$locale === 'vi' ? 'Thông báo hệ thống' : 'System message';
        }
        foreach ($replace as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string)$replacement, $value);
        }
        return $value;
    }

    public static function javascriptMessages(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (is_string($key)) $result[$key] = self::translate($key);
        }
        return $result;
    }

    public static function all(string $locale): array { return self::load(self::valid($locale) ?? 'vi'); }

    private static function messages(): array
    {
        if (!self::$messages) self::$messages = self::load(self::$locale);
        return self::$messages;
    }

    private static function load(string $locale): array
    {
        $file = BASE_PATH . '/lang/' . $locale . '.php';
        if (!is_file($file)) return [];
        $messages = require $file;
        return is_array($messages) ? $messages : [];
    }

    private static function valid(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}

function t(string $key, array $replace = []): string { return Localization::translate($key, $replace); }
