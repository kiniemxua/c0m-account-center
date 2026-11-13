<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__, 2));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix)));
    if (is_file($file)) {
        require $file;
    }
});

if (!App\Core\RequestSecurity::isHttps(BASE_PATH)) {
    http_response_code(400);
    header('Cache-Control: no-store');
    exit('HTTPS is required. Configure direct HTTPS or trusted proxy addresses.');
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
