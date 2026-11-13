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
session_destroy();

echo "Unit tests passed.\n";
