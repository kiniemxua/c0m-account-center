<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BASE_PATH', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) require $file;
    }
});

$configPath = BASE_PATH . '/config/database.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Database configuration is missing.\n");
    exit(1);
}

$config = require $configPath;
$pdo = null;
$lockAcquired = false;

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)($config['port'] ?? 3306), $config['name']),
        (string)$config['user'],
        (string)($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    $lockAcquired = (int)$pdo->query("SELECT GET_LOCK('c0m-account-center-subscription-finalizer', 1)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        fwrite(STDOUT, "Another subscription finalizer is already running.\n");
        exit(0);
    }

    $pdo->beginTransaction();
    $count = App\Core\SubscriptionService::finalizeScheduledCancellations($pdo, 100);
    $pdo->commit();

    fwrite(STDOUT, 'Finalized scheduled cancellations: ' . $count . PHP_EOL);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Subscription finalizer failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
    fwrite(STDERR, "Subscription finalization failed. Check the server error log.\n");
    exit(1);
} finally {
    if ($lockAcquired && $pdo instanceof PDO) {
        try {
            $pdo->query("SELECT RELEASE_LOCK('c0m-account-center-subscription-finalizer')");
        } catch (Throwable) {
            // The connection close also releases the advisory lock.
        }
    }
}
