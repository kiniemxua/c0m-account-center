<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class RequestSecurity
{
    /**
     * Detect HTTPS from the direct connection or an explicitly trusted
     * immediate reverse proxy. Never trust forwarded headers from clients.
     */
    public static function isHttps(string $basePath): bool
    {
        $isHttps = self::isDirectHttps();
        if (!$isHttps) {
            $isHttps = self::isTrustedForwardedHttps($basePath);
        }
        return $isHttps;
    }

    private static function isDirectHttps(): bool
    {
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        return $https !== '' && $https !== 'off' && $https !== '0';
    }

    private static function isTrustedForwardedHttps(string $basePath): bool
    {
        $remote = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $isTrusted = false;
        if ($remote !== false && $forwardedProto === 'https') {
            $trusted = self::loadTrustedProxies($basePath);
            $isTrusted = self::matchesTrustedProxy($remote, $trusted);
        }
        return $isTrusted;
    }

    private static function loadTrustedProxies(string $basePath): array
    {
        $configPath = rtrim($basePath, DIRECTORY_SEPARATOR) . '/config/app.php';
        $config = null;
        if (is_file($configPath)) {
            try {
                $config = require $configPath;
            } catch (Throwable) {
                $config = null;
            }
        }
        $trusted = is_array($config) ? ($config['trusted_proxies'] ?? []) : [];
        return is_array($trusted) ? $trusted : [];
    }

    private static function matchesTrustedProxy(string $remote, array $trusted): bool
    {
        $remotePacked = inet_pton($remote);
        $matches = false;
        foreach ($trusted as $proxy) {
            if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $proxyPacked = inet_pton($proxy);
            if ($proxyPacked !== false && $remotePacked !== false && hash_equals($proxyPacked, $remotePacked)) {
                $matches = true;
                break;
            }
        }
        return $matches;
    }
}
