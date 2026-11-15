<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (str_starts_with($class, $prefix)) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

session_name('c0m_test_' . bin2hex(random_bytes(3)));
session_start();
$token = App\Core\Csrf::token();
check(strlen($token) === 64, 'CSRF token must contain 32 random bytes encoded as hex.');
check(App\Core\Csrf::verify($token), 'The issued CSRF token should validate.');
check(!App\Core\Csrf::verify(str_repeat('0', 64)), 'A different CSRF token must be rejected.');
check(!App\Core\Csrf::verify(null), 'A missing CSRF token must be rejected.');
check(!App\Core\Csrf::verify([]), 'An array CSRF token must be rejected without a type error.');
check(!App\Core\Csrf::verify(new stdClass()), 'An object CSRF token must be rejected without a type error.');
$_SESSION['_csrf'] = ['malformed'];
$recoveredToken = App\Core\Csrf::token();
check(is_string($recoveredToken) && strlen($recoveredToken) === 64, 'A malformed stored CSRF value must be replaced with a valid token.');
check(App\Core\Csrf::verify($recoveredToken), 'The regenerated CSRF token must validate.');
session_destroy();

// Forwarded headers from arbitrary clients must never enable Secure-cookie trust.
$_SERVER['HTTPS'] = 'off';
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
check(!App\Core\SessionManager::requestIsSecure(), 'Untrusted forwarded HTTPS headers must be ignored.');
$_SERVER['HTTPS'] = 'on';
check(App\Core\SessionManager::requestIsSecure(), 'Direct HTTPS must be recognized.');

// A configured proxy may assert HTTPS only when its address matches REMOTE_ADDR.
$configPath = BASE_PATH . '/config/app.php';
$configExisted = is_file($configPath);
$oldConfig = $configExisted ? file_get_contents($configPath) : null;
try {
    file_put_contents($configPath, "<?php return ['trusted_proxies' => ['127.0.0.1']];");
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    check(App\Core\SessionManager::requestIsSecure(), 'A configured trusted proxy may assert HTTPS.');

    session_name('c0m_proxy_cookie_test');
    App\Core\SessionManager::start();
    $proxyCookie = session_get_cookie_params();
    check(($proxyCookie['secure'] ?? false) === true, 'A trusted HTTPS proxy must set the actual session cookie Secure flag.');
    session_destroy();

    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    check(!App\Core\SessionManager::requestIsSecure(), 'A non-trusted client must not assert HTTPS through a forwarded header.');
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    check(!App\Core\SessionManager::requestIsSecure(), 'Ambiguous forwarded protocol chains must be rejected.');
} finally {
    if ($configExisted && $oldConfig !== false) {
        file_put_contents($configPath, $oldConfig);
    } else {
        @unlink($configPath);
    }
}

// Router dispatch must instantiate controller handlers whose action methods are
// non-static, instead of failing with a TypeError on every route.
final class RouterFixtureController
{
    public function ping(): void { echo 'pong'; }
}
App\Core\Localization::set('vi');
$router = new App\Core\Router();
$router->get('/router-fixture', [RouterFixtureController::class, 'ping']);
$router->get('/router-missing-method', [RouterFixtureController::class, 'nonexistent']);
ob_start();
$router->dispatch('GET', '/router-fixture');
$dispatched = ob_get_clean();
check($dispatched === 'pong', 'The router must instantiate non-static controller handlers and dispatch them.');
http_response_code(200);
ob_start();
$router->dispatch('GET', '/no-such-route');
$notFound = ob_get_clean();
check(http_response_code() === 404 && trim($notFound) !== '', 'Unknown routes must render the localized 404 page.');
http_response_code(200);

echo "Unit tests passed.\n";
