<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\PaymentWebhookVerifier;
use App\Core\SubscriptionService;
use PDO;
use PDOException;
use Throwable;

final class PaymentWebhookController
{
    public function receive(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            $this->respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
            return;
        }
        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 65536) {
            $this->respond(413, ['ok' => false, 'error' => 'invalid_payload']);
            return;
        }
        $provider = is_string($_SERVER['HTTP_X_C0M_PROVIDER'] ?? null)
            ? strtolower(trim($_SERVER['HTTP_X_C0M_PROVIDER'])) : '';
        if (!preg_match('/\A[a-z0-9_-]{1,40}\z/D', $provider)) {
            $this->respond(400, ['ok' => false, 'error' => 'invalid_provider']);
            return;
        }
        $configPath = BASE_PATH . '/config/payments.php';
        if (!is_file($configPath)) {
            $this->respond(503, ['ok' => false, 'error' => 'payment_webhooks_unavailable']);
            return;
        }
        $config = require $configPath;
        $secret = is_array($config) ? ($config['providers'][$provider]['webhook_secret'] ?? null) : null;
        if (!is_string($secret) || !PaymentWebhookVerifier::verify(
            $secret,
            $rawBody,
            (string)($_SERVER['HTTP_X_C0M_SIGNATURE'] ?? '')
        )) {
            $this->respond(401, ['ok' => false, 'error' => 'invalid_signature']);
            return;
        }

        try {
            $event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->respond(400, ['ok' => false, 'error' => 'invalid_json']);
            return;
        }
        $eventId = is_array($event) && is_string($event['id'] ?? null) ? trim($event['id']) : '';
        $eventType = is_array($event) && is_string($event['type'] ?? null) ? trim($event['type']) : '';
        $data = is_array($event) && is_array($event['data'] ?? null) ? $event['data'] : null;
        if (is_array($data) && (is_string($event['created_at'] ?? null) || is_int($event['created'] ?? null))) {
            $data['_event_created_at'] = $event['created_at'] ?? $event['created'];
        }
        if ($eventId === '' || strlen($eventId) > 190 || $eventType === '' || strlen($eventType) > 120
            || $data === null || preg_match('/[\x00-\x1f\x7f]/', $eventId . $eventType)) {
            $this->respond(400, ['ok' => false, 'error' => 'invalid_event']);
            return;
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $insertEvent = $pdo->prepare(
                'INSERT INTO payment_webhook_events
                    (provider, provider_event_id, event_type, payload_hash, signature_verified, status)
                 VALUES (:provider, :event, :type, :hash, 1, \'received\')'
            );
            $insertEvent->execute([
                'provider' => $provider,
                'event' => $eventId,
                'type' => $eventType,
                'hash' => hash('sha256', $rawBody),
            ]);

            $outboxData = ['provider' => $provider, 'provider_event_id' => $eventId];
            $aggregateType = 'payment_event';
            $aggregateId = $eventId;
            $status = 'processed';

            if (in_array($eventType, ['subscription.activated', 'subscription.updated', 'subscription.canceled'], true)) {
                $subscription = SubscriptionService::applyProviderSubscription($pdo, $provider, $eventType, $data);
                if (!empty($subscription['stale_event'])) {
                    $status = 'ignored';
                }
                $outboxData += $subscription;
                $aggregateType = 'user';
                $aggregateId = (string)$subscription['user_id'];
                $outboxData['authorization_revision'] = $subscription['authorization_revision'];
            } elseif (in_array($eventType, ['payment.succeeded', 'payment.failed', 'payment.refunded'], true)) {
                $userId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $amount = filter_var($data['amount_minor'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '';
                if ($userId === false || $amount === false || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
                    throw new \RuntimeException('invalid_payment_event');
                }
                $user = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1");
                $user->execute(['id' => $userId]);
                if ($user->fetchColumn() === false) throw new \RuntimeException('payment_user_unavailable');
                $subscriptionId = filter_var($data['subscription_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $paymentStatus = match ($eventType) {
                    'payment.succeeded' => 'succeeded',
                    'payment.failed' => 'failed',
                    default => 'refunded',
                };
                $payment = $pdo->prepare(
                    'INSERT INTO payment_transactions
                        (provider, provider_event_id, provider_payment_id, user_id, subscription_id,
                         amount_minor, currency, status, payload_hash, processed_at)
                     VALUES (:provider, :event, :payment, :user, :subscription, :amount, :currency, :status, :hash, UTC_TIMESTAMP())'
                );
                $payment->execute([
                    'provider' => $provider,
                    'event' => $eventId,
                    'payment' => is_string($data['payment_id'] ?? null) ? substr($data['payment_id'], 0, 190) : null,
                    'user' => (int)$userId,
                    'subscription' => $subscriptionId === false ? null : $subscriptionId,
                    'amount' => (int)$amount,
                    'currency' => $currency,
                    'status' => $paymentStatus,
                    'hash' => hash('sha256', $rawBody),
                ]);
                $outboxData += [
                    'user_id' => (int)$userId,
                    'payment_id' => is_string($data['payment_id'] ?? null) ? substr($data['payment_id'], 0, 190) : null,
                    'amount_minor' => (int)$amount,
                    'currency' => $currency,
                    'status' => $paymentStatus,
                ];
                $aggregateType = 'user';
                $aggregateId = (string)$userId;
            } else {
                $status = 'ignored';
            }

            if ($status === 'processed') {
                $eventKey = hash('sha256', $provider . ':' . $eventId);
                $outbox = $pdo->prepare(
                    'INSERT IGNORE INTO event_outbox
                        (event_key, event_type, aggregate_type, aggregate_id, payload, available_at)
                     VALUES (:key, :type, :aggregate_type, :aggregate_id, :payload, UTC_TIMESTAMP())'
                );
                $outbox->execute([
                    'key' => $eventKey,
                    'type' => $eventType,
                    'aggregate_type' => $aggregateType,
                    'aggregate_id' => $aggregateId,
                    'payload' => json_encode($outboxData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
            }
            $pdo->prepare(
                'UPDATE payment_webhook_events SET status = :status, processed_at = UTC_TIMESTAMP()
                 WHERE provider = :provider AND provider_event_id = :event'
            )->execute(['status' => $status, 'provider' => $provider, 'event' => $eventId]);
            $pdo->commit();
            $this->respond(200, ['ok' => true, 'duplicate' => false]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $this->respond(200, ['ok' => true, 'duplicate' => true]);
                return;
            }
            error_log('Payment webhook processing failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            $this->respond(500, ['ok' => false, 'error' => 'processing_failed']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment webhook processing failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            $this->respond(400, ['ok' => false, 'error' => 'invalid_event_data']);
        }
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
