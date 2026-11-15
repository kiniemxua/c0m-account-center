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

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], (int)($config['port'] ?? 3306), $config['name']),
        (string)$config['user'],
        (string)($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    if ((int)$pdo->query("SELECT GET_LOCK('c0m-account-center-outbox', 1)")->fetchColumn() !== 1) {
        fwrite(STDOUT, "Another outbox worker is already running.\n");
        exit(0);
    }

    $processed = 0;
    try {
        $events = $pdo->query(
            'SELECT id, event_key, event_type, aggregate_type, aggregate_id, payload, created_at
             FROM event_outbox
             WHERE delivered_at IS NULL AND available_at <= UTC_TIMESTAMP() AND attempts < 20
             ORDER BY id LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($events as $event) {
            $endpoints = $pdo->query(
                "SELECT e.id, e.endpoint_url, e.secret_ciphertext, e.event_types
                 FROM app_webhook_endpoints e
                 INNER JOIN applications a ON a.id = e.application_id
                 WHERE e.status = 'active' AND a.status = 'active'
                 ORDER BY e.id"
            )->fetchAll(PDO::FETCH_ASSOC);

            foreach ($endpoints as $endpoint) {
                $eventTypes = json_decode((string)$endpoint['event_types'], true);
                if (!is_array($eventTypes) || !in_array($event['event_type'], $eventTypes, true)) continue;
                $create = $pdo->prepare(
                    "INSERT IGNORE INTO webhook_deliveries (outbox_id, endpoint_id, status, attempts, next_attempt_at)
                     VALUES (:outbox, :endpoint, 'pending', 0, UTC_TIMESTAMP())"
                );
                $create->execute(['outbox' => (int)$event['id'], 'endpoint' => (int)$endpoint['id']]);
            }

            $deliveries = $pdo->prepare(
                "SELECT d.id AS delivery_id, d.attempts, e.endpoint_url, e.secret_ciphertext
                 FROM webhook_deliveries d
                 INNER JOIN app_webhook_endpoints e ON e.id = d.endpoint_id
                 WHERE d.outbox_id = :outbox AND d.status = 'pending'
                   AND d.next_attempt_at <= UTC_TIMESTAMP() AND e.status = 'active'
                 ORDER BY d.id LIMIT 50"
            );
            $deliveries->execute(['outbox' => (int)$event['id']]);
            foreach ($deliveries->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
                $attempt = (int)$delivery['attempts'] + 1;
                $responseStatus = null;
                $errorCode = null;
                try {
                    $secret = App\Core\Crypto::decrypt((string)$delivery['secret_ciphertext']);
                    $payload = json_encode([
                        'id' => (string)$event['event_key'],
                        'type' => (string)$event['event_type'],
                        'created_at' => gmdate('c', strtotime((string)$event['created_at'] . ' UTC')),
                        'data' => json_decode((string)$event['payload'], true),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    $timestamp = (string)time();
                    $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
                    $headers = [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'Connection: close',
                        'X-C0M-Event: ' . $event['event_type'],
                        'X-C0M-Delivery: ' . $event['event_key'],
                        'X-C0M-Signature: t=' . $timestamp . ',v1=' . $signature,
                    ];
                    $responseStatus = App\Core\SafeWebhookHttpClient::post(
                        (string)$delivery['endpoint_url'],
                        $headers,
                        $payload
                    );
                    if ($responseStatus < 200 || $responseStatus >= 300) {
                        $errorCode = 'http_status_' . $responseStatus;
                    }
                } catch (Throwable $e) {
                    $errorCode = $e instanceof RuntimeException ? $e->getMessage() : 'delivery_error';
                }

                if ($errorCode === null && $responseStatus !== null && $responseStatus >= 200 && $responseStatus < 300) {
                    $pdo->prepare(
                        "UPDATE webhook_deliveries
                         SET status = 'delivered', attempts = :attempts, response_status = :status,
                             delivered_at = UTC_TIMESTAMP(), last_error = NULL
                         WHERE id = :id"
                    )->execute(['attempts' => $attempt, 'status' => $responseStatus, 'id' => (int)$delivery['delivery_id']]);
                } else {
                    $terminal = $attempt >= 12;
                    $delay = min(3600, 30 * (2 ** min(7, $attempt - 1)));
                    $nextAttemptAt = gmdate('Y-m-d H:i:s', time() + $delay);
                    $pdo->prepare(
                        'UPDATE webhook_deliveries
                         SET status = :status, attempts = :attempts, response_status = :response,
                             next_attempt_at = :next_attempt_at, last_error = :error
                         WHERE id = :id'
                    )->execute([
                        'status' => $terminal ? 'failed' : 'pending',
                        'attempts' => $attempt,
                        'response' => $responseStatus,
                        'next_attempt_at' => $nextAttemptAt,
                        'error' => substr((string)$errorCode, 0, 255),
                        'id' => (int)$delivery['delivery_id'],
                    ]);
                }
                $processed++;
            }

            $pending = $pdo->prepare("SELECT COUNT(*) FROM webhook_deliveries WHERE outbox_id = :id AND status = 'pending'");
            $pending->execute(['id' => (int)$event['id']]);
            if ((int)$pending->fetchColumn() === 0) {
                $pdo->prepare('UPDATE event_outbox SET delivered_at = UTC_TIMESTAMP(), attempts = attempts + 1 WHERE id = :id AND delivered_at IS NULL')
                    ->execute(['id' => (int)$event['id']]);
            } else {
                $pdo->prepare('UPDATE event_outbox SET attempts = attempts + 1 WHERE id = :id')
                    ->execute(['id' => (int)$event['id']]);
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('c0m-account-center-outbox')");
    }
    fwrite(STDOUT, 'Processed webhook deliveries: ' . $processed . PHP_EOL);
} catch (Throwable $e) {
    error_log('Outbox dispatcher failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
    fwrite(STDERR, "Outbox dispatch failed. Check the server error log.\n");
    exit(1);
}
