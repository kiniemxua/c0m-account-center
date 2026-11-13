<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('c0m_session');
    session_set_cookie_params([
        'httponly' => true, 'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax', 'path' => '/',
    ]);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

$configFile = BASE_PATH . '/config/database.php';
if (is_file($configFile)) {
    $db = require $configFile;
    App\Core\Database::configure($db);
}
