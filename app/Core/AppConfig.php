<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AppConfig
{
    public static function baseUrl(): string
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        $path = BASE_PATH . '/config/app.php';
        if (!is_file($path)) {
            throw new RuntimeException('Application URL is not configured.');
        }
        $config = require $path;
        $url = rtrim((string)($config['base_url'] ?? ''), '/');
        if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('A valid HTTPS application base URL is required.');
        }
        return $url;
    }
}
