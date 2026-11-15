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
        $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $remote = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($remote === false || $forwardedProto !== 'https') {
            return false;
        }

        $configPath = rtrim($basePath, DIRECTORY_SEPARATOR) . '/config/app.php';
        if (!is_file($configPath)) {
            return false;
        }

        try {
            $config = require $configPath;
        } catch (Throwable) {
            return false;
        }
        $trusted = is_array($config) ? ($config['trusted_proxies'] ?? []) : [];
        if (!is_array($trusted)) {
            return false;
        }

        $remotePacked = inet_pton($remote);
        foreach ($trusted as $proxy) {
            if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            $proxyPacked = inet_pton($proxy);
            if ($proxyPacked !== false && $remotePacked !== false && hash_equals($proxyPacked, $remotePacked)) {
                return true;
            }
        }
        return false;
    }
}
