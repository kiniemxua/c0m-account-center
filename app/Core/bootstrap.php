<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!App\Core\RequestSecurity::isHttps(BASE_PATH)) {
    http_response_code(400);
    header('Cache-Control: no-store');
    $locale = (string)($_COOKIE['c0m_lang'] ?? 'vi');
    if (!in_array($locale, ['vi', 'en'], true)) $locale = 'vi';
    $messages = require BASE_PATH . '/lang/' . $locale . '.php';
    exit((string)($messages['HTTPS is required. Configure direct HTTPS or trusted proxy addresses.'] ?? ($locale === 'vi' ? 'Yêu cầu HTTPS.' : 'HTTPS is required.')));
}

$configFile = BASE_PATH . '/config/database.php';
if (is_file($configFile)) {
    $db = require $configFile;
    App\Core\Database::configure($db);
}

App\Core\SessionManager::start();
App\Core\Localization::initialize();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
