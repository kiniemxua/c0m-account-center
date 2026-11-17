<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Crypto
{
    private static function key(): string
    {
        $path = dirname(__DIR__, 2) . '/config/database.php';
        if (!is_file($path)) throw new RuntimeException('Application encryption key is unavailable.');
        $config = require $path;
        $appKey = is_array($config) ? ($config['app_key'] ?? null) : null;
        if (!is_string($appKey) || !preg_match('/\A[a-f0-9]{64}\z/D', $appKey)) {
            throw new RuntimeException('Application encryption key is invalid.');
        }
        return hash('sha256', $appKey, true);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') throw new RuntimeException('Cannot encrypt an empty secret.');
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Secret encryption failed.');
        }
        return base64_encode("1" . $nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if (!is_string($payload) || strlen($payload) < 29 || $payload[0] !== '1') {
            throw new RuntimeException('Encrypted secret format is invalid.');
        }
        $nonce = substr($payload, 1, 12);
        $tag = substr($payload, 13, 16);
        $ciphertext = substr($payload, 29);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($plaintext)) throw new RuntimeException('Encrypted secret authentication failed.');
        return $plaintext;
    }
}
