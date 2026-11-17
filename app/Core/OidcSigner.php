<?php
declare(strict_types=1);

namespace App\Core;

use OpenSSLAsymmetricKey;
use RuntimeException;

final class OidcSigner
{
    private static ?OpenSSLAsymmetricKey $privateKey = null;
    private static ?array $details = null;
    private static ?string $keyId = null;

    private static function load(): void
    {
        if (self::$privateKey instanceof OpenSSLAsymmetricKey) return;
        if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__, 2));
        $directory = BASE_PATH . '/storage';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('OIDC signing storage is unavailable.');
        }
        $path = $directory . '/oauth-signing-private.pem';
        if (!is_file($path)) {
            $key = openssl_pkey_new([
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'private_key_bits' => 2048,
            ]);
            if (!$key || !openssl_pkey_export($key, $pem)) {
                throw new RuntimeException('Could not generate OIDC signing key.');
            }
            $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
            if (file_put_contents($temporary, $pem, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Could not securely persist OIDC signing key.');
            }
        }
        if (!chmod($path, 0600)) {
            throw new RuntimeException('OIDC signing key permissions could not be restricted.');
        }
        $pem = file_get_contents($path);
        if (!is_string($pem) || $pem === '') throw new RuntimeException('OIDC signing key is unreadable.');
        $key = openssl_pkey_get_private($pem);
        if (!$key) throw new RuntimeException('OIDC signing key is invalid.');
        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || (int)($details['bits'] ?? 0) < 2048 || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('OIDC requires an RSA signing key of at least 2048 bits.');
        }
        self::$privateKey = $key;
        self::$details = $details;
        self::$keyId = substr(hash('sha256', $details['key']), 0, 24);
    }

    public static function keyId(): string
    {
        self::load();
        return (string)self::$keyId;
    }

    public static function sign(array $claims): string
    {
        self::load();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::$keyId];
        $encodedHeader = OAuthService::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = OAuthService::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $input = $encodedHeader . '.' . $encodedClaims;
        if (!openssl_sign($input, $signature, self::$privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign OIDC token.');
        }
        return $input . '.' . OAuthService::base64UrlEncode($signature);
    }

    public static function jwks(): array
    {
        self::load();
        $keys = [self::jwkFromDetails(self::$details, (string)self::$keyId)];
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $previousDirectory = BASE_PATH . '/storage/oauth-signing-previous';
        if (is_dir($previousDirectory)) {
            $minimumCreatedAt = time() - 86400;
            foreach (glob($previousDirectory . '/*.pem') ?: [] as $path) {
                if (is_link($path)) continue;
                $createdAt = @filemtime($path);
                if (!is_int($createdAt) || $createdAt < $minimumCreatedAt || $createdAt > time() + 60) {
                    continue;
                }
                $publicPem = @file_get_contents($path);
                if (!is_string($publicPem) || $publicPem === '') continue;
                $publicKey = openssl_pkey_get_public($publicPem);
                if (!$publicKey) continue;
                $details = openssl_pkey_get_details($publicKey);
                if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
                    || (int)($details['bits'] ?? 0) < 2048
                    || !isset($details['key'], $details['rsa']['n'], $details['rsa']['e'])) {
                    continue;
                }
                $kid = substr(hash('sha256', $details['key']), 0, 24);
                if ($kid === self::$keyId || basename($path, '.pem') !== $kid) continue;
                $keys[$kid] = self::jwkFromDetails($details, $kid);
            }
        }
        return ['keys' => array_values($keys)];
    }

    private static function jwkFromDetails(array $details, string $kid): array
    {
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => OAuthService::base64UrlEncode($details['rsa']['n']),
            'e' => OAuthService::base64UrlEncode($details['rsa']['e']),
        ];
    }
}
