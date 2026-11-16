<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\PaymentWebhookVerifier;
use App\Core\SubscriptionService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
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
        $createdAt = is_array($event) ? ($event['created_at'] ?? $event['created'] ?? null) : null;
        if (is_array($data) && (is_string($createdAt) || is_int($createdAt))) {
            $data['_event_created_at'] = $createdAt;
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
                $amount = filter_var($data['amount_minor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $currency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : '';
                $paymentId = is_string($data['payment_id'] ?? null) ? trim($data['payment_id']) : '';
                $subscriptionId = null;
                if (array_key_exists('subscription_id', $data) && $data['subscription_id'] !== null && $data['subscription_id'] !== '') {
                    $parsedSubscriptionId = filter_var($data['subscription_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($parsedSubscriptionId === false) {
                        throw new RuntimeException('invalid_payment_subscription');
                    }
                    $subscriptionId = (int)$parsedSubscriptionId;
                }
                $eventCreatedAt = self::eventTimestamp($data['_event_created_at'] ?? null);

                if ($userId === false || $amount === false || $paymentId === '' || strlen($paymentId) > 190
                    || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || $eventCreatedAt === null) {
                    throw new RuntimeException('invalid_payment_event');
                }

                $user = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1");
                $user->execute(['id' => $userId]);
                if ($user->fetchColumn() === false) {
                    throw new RuntimeException('payment_user_unavailable');
                }

                if ($subscriptionId !== null) {
                    $subscriptionOwner = $pdo->prepare(
                        'SELECT id FROM subscriptions WHERE id = :id AND user_id = :user LIMIT 1 FOR UPDATE'
                    );
                    $subscriptionOwner->execute(['id' => $subscriptionId, 'user' => $userId]);
                    if ($subscriptionOwner->fetchColumn() === false) {
                        throw new RuntimeException('payment_subscription_owner_mismatch');
                    }
                }

                $paymentStatus = match ($eventType) {
                    'payment.succeeded' => 'succeeded',
                    'payment.failed' => 'failed',
                    default => 'refunded',
                };
                $findPayment = $pdo->prepare(
                    'SELECT id, user_id, subscription_id, amount_minor, currency, status, provider_event_created_at
                     FROM payment_transactions
                     WHERE provider = :provider AND provider_payment_id = :payment
                     LIMIT 1 FOR UPDATE'
                );
                $findPayment->execute(['provider' => $provider, 'payment' => $paymentId]);
                $existingPayment = $findPayment->fetch(PDO::FETCH_ASSOC);

                $effectiveSubscriptionId = $subscriptionId;
                if ($existingPayment) {
                    if ((int)$existingPayment['user_id'] !== (int)$userId
                        || (int)$existingPayment['amount_minor'] !== (int)$amount
                        || strtoupper((string)$existingPayment['currency']) !== $currency
                        || ($subscriptionId !== null && $existingPayment['subscription_id'] !== null
                            && (int)$existingPayment['subscription_id'] !== $subscriptionId)) {
                        throw new RuntimeException('payment_identity_mismatch');
                    }
                    if ($effectiveSubscriptionId === null && $existingPayment['subscription_id'] !== null) {
                        $effectiveSubscriptionId = (int)$existingPayment['subscription_id'];
                    }

                    $previousEventAt = $existingPayment['provider_event_created_at'];
                    $previousStatus = (string)$existingPayment['status'];
                    $allowedTransitions = [
                        'pending' => ['succeeded', 'failed'],
                        'failed' => ['succeeded'],
                        'succeeded' => ['refunded'],
                        'refunded' => [],
                    ];
                    $isNewer = is_string($previousEventAt) && $eventCreatedAt > $previousEventAt;
                    $canTransition = $isNewer
                        && in_array($paymentStatus, $allowedTransitions[$previousStatus] ?? [], true);

                    if (!$canTransition) {
                        $status = 'ignored';
                    } else {
                        $updatePayment = $pdo->prepare(
                            'UPDATE payment_transactions
                             SET provider_event_id = :event, subscription_id = COALESCE(:subscription, subscription_id),
                                 status = :status, payload_hash = :hash, processed_at = UTC_TIMESTAMP(),
                                 provider_event_created_at = :event_created_at
                             WHERE id = :id'
                        );
                        $updatePayment->execute([
                            'event' => $eventId,
                            'subscription' => $effectiveSubscriptionId,
                            'status' => $paymentStatus,
                            'hash' => hash('sha256', $rawBody),
                            'event_created_at' => $eventCreatedAt,
                            'id' => (int)$existingPayment['id'],
                        ]);
                    }
                } else {
                    if ($paymentStatus === 'refunded') {
                        throw new RuntimeException('payment_refund_without_original');
                    }
                    $insertPayment = $pdo->prepare(
                        'INSERT INTO payment_transactions
                            (provider, provider_event_id, provider_payment_id, user_id, subscription_id,
                             amount_minor, currency, status, payload_hash, processed_at, provider_event_created_at)
                         VALUES (:provider, :event, :payment, :user, :subscription, :amount, :currency,
                                 :status, :hash, UTC_TIMESTAMP(), :event_created_at)'
                    );
                    $insertPayment->execute([
                        'provider' => $provider,
                        'event' => $eventId,
                        'payment' => $paymentId,
                        'user' => (int)$userId,
                        'subscription' => $effectiveSubscriptionId,
                        'amount' => (int)$amount,
                        'currency' => $currency,
                        'status' => $paymentStatus,
                        'hash' => hash('sha256', $rawBody),
                        'event_created_at' => $eventCreatedAt,
                    ]);
                }

                $outboxData += [
                    'user_id' => (int)$userId,
                    'payment_id' => $paymentId,
                    'subscription_id' => $effectiveSubscriptionId,
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
            $this->respond(200, ['ok' => true, 'duplicate' => false, 'status' => $status]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                try {
                    $duplicate = $pdo->prepare(
                        'SELECT 1 FROM payment_webhook_events WHERE provider = :provider AND provider_event_id = :event LIMIT 1'
                    );
                    $duplicate->execute(['provider' => $provider, 'event' => $eventId]);
                    if ($duplicate->fetchColumn() !== false) {
                        $this->respond(200, ['ok' => true, 'duplicate' => true]);
                        return;
                    }
                } catch (Throwable) {
                    // Fall through to a retryable conflict response.
                }
                $this->respond(409, ['ok' => false, 'error' => 'payment_conflict_retry']);
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

    private static function eventTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('invalid_payment_event_timestamp');
        }
        if (is_string($value) && (
            strlen($value) > 80
            || trim($value) !== $value
            || preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\\z/D', $value) !== 1
        )) {
            throw new RuntimeException('invalid_payment_event_timestamp');
        }

        try {
            $date = is_int($value)
                ? new DateTimeImmutable('@' . $value)
                : new DateTimeImmutable($value, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new RuntimeException('invalid_payment_event_timestamp');
            }
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            throw new RuntimeException('invalid_payment_event_timestamp');
        }
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
