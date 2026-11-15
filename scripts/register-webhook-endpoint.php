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
    fwrite(STDERR, "Database configuration is missing. Run the installer first.\n");
    exit(1);
}
$config = require $configPath;
$applicationId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$endpoint = (string)($argv[2] ?? '');
$eventText = (string)($argv[3] ?? '');
$parts = parse_url($endpoint);
$events = array_values(array_unique(array_filter(array_map('trim', explode(',', $eventText)))));
$allowedEvents = [
    'subscription.created', 'subscription.updated', 'subscription.canceled',
    'entitlement.changed', 'payment.succeeded', 'payment.failed', 'payment.refunded',
];
if ($applicationId === false || filter_var($endpoint, FILTER_VALIDATE_URL) === false
    || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
    || $events === [] || array_diff($events, $allowedEvents) !== []) {
    fwrite(STDERR, "Usage: php scripts/register-webhook-endpoint.php <application-id> <https-endpoint> <comma-separated-event-types>\n");
    exit(2);
}
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)($config['port'] ?? 3306), $config['name']),
        (string)$config['user'],
        (string)($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    $app = $pdo->prepare("SELECT id, name FROM applications WHERE id = :id AND status = 'active' LIMIT 1");
    $app->execute(['id' => $applicationId]);
    $application = $app->fetch(PDO::FETCH_ASSOC);
    if (!$application) throw new RuntimeException('Application is not active.');
    $secret = bin2hex(random_bytes(32));
    $insert = $pdo->prepare(
        'INSERT INTO app_webhook_endpoints (application_id, endpoint_url, secret_ciphertext, event_types, status)
         VALUES (:application, :endpoint, :secret, :events, \'active\')'
    );
    $insert->execute([
        'application' => (int)$applicationId,
        'endpoint' => $endpoint,
        'secret' => App\Core\Crypto::encrypt($secret),
        'events' => json_encode($events, JSON_THROW_ON_ERROR),
    ]);
    fwrite(STDOUT, json_encode([
        'application' => $application['name'],
        'endpoint_id' => (int)$pdo->lastInsertId(),
        'endpoint_url' => $endpoint,
        'secret' => $secret,
        'events' => $events,
        'warning' => 'Store this signing secret securely. It is shown only once.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $e) {
    error_log('Webhook endpoint registration failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
    fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . PHP_EOL : "Webhook endpoint registration failed. Check the server error log.\n");
    exit(1);
}
