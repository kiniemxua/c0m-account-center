<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class SubscriptionService
{
    public static function applyProviderSubscription(PDO $pdo, string $provider, string $eventType, array $data): array
    {
        $userId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $planKey = is_string($data['plan_key'] ?? null) ? $data['plan_key'] : '';
        $providerSubscriptionId = is_string($data['provider_subscription_id'] ?? null)
            ? trim($data['provider_subscription_id']) : '';
        if ($userId === false || $planKey === '' || strlen($planKey) > 80
            || $providerSubscriptionId === '' || strlen($providerSubscriptionId) > 190) {
            throw new RuntimeException('invalid_subscription_event');
        }
        $user = $pdo->prepare("SELECT id FROM users WHERE id = :id AND status = 'active' LIMIT 1");
        $user->execute(['id' => $userId]);
        if ($user->fetchColumn() === false) throw new RuntimeException('subscription_user_unavailable');

        $plan = $pdo->prepare("SELECT id FROM subscription_plans WHERE plan_key = :key AND status = 'active' LIMIT 1");
        $plan->execute(['key' => $planKey]);
        $planId = $plan->fetchColumn();
        if ($planId === false) throw new RuntimeException('subscription_plan_unavailable');

        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        if ($eventType === 'subscription.canceled') $status = 'canceled';
        if ($status === '') $status = 'active';
        if (!in_array($status, ['pending', 'trialing', 'active', 'past_due', 'canceled', 'expired'], true)) {
            throw new RuntimeException('invalid_subscription_status');
        }

        $periodStart = self::dateValue($data['current_period_start'] ?? null);
        $periodEnd = self::dateValue($data['current_period_end'] ?? null);
        if ($periodStart !== null && $periodEnd !== null && $periodEnd <= $periodStart) {
            throw new RuntimeException('invalid_subscription_period');
        }
        $customerId = is_string($data['provider_customer_id'] ?? null) ? substr($data['provider_customer_id'], 0, 190) : null;
        $cancelAtPeriodEnd = !empty($data['cancel_at_period_end']) ? 1 : 0;
        if ($cancelAtPeriodEnd === 1 && $periodEnd === null) {
            throw new RuntimeException('cancellation_period_end_required');
        }

        $find = $pdo->prepare(
            'SELECT id, user_id, provider_event_created_at FROM subscriptions
             WHERE provider = :provider AND provider_subscription_id = :subscription
             LIMIT 1 FOR UPDATE'
        );
        $find->execute(['provider' => $provider, 'subscription' => $providerSubscriptionId]);
        $existing = $find->fetch();
        if ($existing && (int)$existing['user_id'] !== (int)$userId) {
            throw new RuntimeException('subscription_owner_mismatch');
        }
        $eventCreatedAt = self::dateValue($data['_event_created_at'] ?? null);
        if ($existing && $existing['provider_event_created_at'] !== null
            && ($eventCreatedAt === null || $eventCreatedAt < $existing['provider_event_created_at'])) {
            return [
                'subscription_id' => (int)$existing['id'],
                'user_id' => (int)$userId,
                'status' => 'stale',
                'plan_key' => $planKey,
                'authorization_revision' => null,
                'stale_event' => true,
            ];
        }
        if ($existing) {
            $subscriptionId = (int)$existing['id'];
            $update = $pdo->prepare(
                'UPDATE subscriptions SET plan_id = :plan, status = :status,
                    provider_customer_id = :customer, current_period_start = :period_start,
                    current_period_end = :period_end, cancel_at_period_end = :cancel_at_period_end,
                    canceled_at = :canceled_at, provider_event_created_at = COALESCE(:event_created_at, provider_event_created_at), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                'plan' => (int)$planId,
                'status' => $status,
                'customer' => $customerId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'canceled_at' => $status === 'canceled' ? gmdate('Y-m-d H:i:s') : null,
                'event_created_at' => $eventCreatedAt,
                'id' => $subscriptionId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO subscriptions
                    (user_id, plan_id, status, provider, provider_customer_id, provider_subscription_id,
                     current_period_start, current_period_end, cancel_at_period_end, canceled_at, provider_event_created_at)
                 VALUES (:user, :plan, :status, :provider, :customer, :subscription,
                         :period_start, :period_end, :cancel_at_period_end, :canceled_at, :event_created_at)'
            );
            $insert->execute([
                'user' => (int)$userId,
                'plan' => (int)$planId,
                'status' => $status,
                'provider' => $provider,
                'customer' => $customerId,
                'subscription' => $providerSubscriptionId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'canceled_at' => $status === 'canceled' ? gmdate('Y-m-d H:i:s') : null,
                'event_created_at' => $eventCreatedAt,
            ]);
            $subscriptionId = (int)$pdo->lastInsertId();
        }

        if (in_array($status, ['active', 'trialing'], true)) {
            $pdo->prepare(
                "UPDATE subscriptions SET status = 'canceled', canceled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE user_id = :user AND id <> :current AND status IN ('active','trialing')"
            )->execute(['user' => (int)$userId, 'current' => $subscriptionId]);
        }

        $revision = EntitlementService::synchronize($pdo, $subscriptionId);
        return [
            'subscription_id' => $subscriptionId,
            'user_id' => (int)$userId,
            'status' => $status,
            'plan_key' => $planKey,
            'authorization_revision' => $revision,
        ];
    }

    
    /**
     * Cancel a user's subscription safely. Scheduled cancellation keeps access
     * through the paid period; immediate cancellation synchronizes entitlements.
     * Caller may wrap this operation in a transaction.
     */
    public static function cancel(PDO $pdo, int $userId, int $subscriptionId, bool $atPeriodEnd = true): array
    {
        if ($userId < 1 || $subscriptionId < 1) throw new RuntimeException('invalid_subscription_owner');
        $stmt = $pdo->prepare(
            'SELECT id, user_id, status, current_period_end, cancel_at_period_end
             FROM subscriptions WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $subscriptionId]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['user_id'] !== $userId) throw new RuntimeException('subscription_not_found');
        if (!in_array($row['status'], ['pending','trialing','active','past_due'], true)) {
            throw new RuntimeException('subscription_not_cancellable');
        }
        if ($atPeriodEnd && (!in_array($row['status'], ['trialing','active','past_due'], true)
            || $row['current_period_end'] === null
            || strtotime((string)$row['current_period_end'] . ' UTC') <= time())) {
            throw new RuntimeException('subscription_period_end_unavailable');
        }

        if ($atPeriodEnd) {
            $pdo->prepare(
                'UPDATE subscriptions SET cancel_at_period_end = 1, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            )->execute(['id' => $subscriptionId]);
            return ['subscription_id'=>$subscriptionId, 'status'=>(string)$row['status'],
                'cancel_at_period_end'=>true, 'access_until'=>(string)$row['current_period_end']];
        }

        $pdo->prepare(
            "UPDATE subscriptions SET status = 'canceled', cancel_at_period_end = 0,
             canceled_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id"
        )->execute(['id' => $subscriptionId]);
        $revision = EntitlementService::synchronize($pdo, $subscriptionId);
        return ['subscription_id'=>$subscriptionId, 'status'=>'canceled',
            'cancel_at_period_end'=>false, 'authorization_revision'=>$revision];
    }

    /**
     * Finalize scheduled cancellations whose paid period has ended.
     * Returns the number of subscriptions transitioned.
     */
    public static function finalizeScheduledCancellations(PDO $pdo, int $limit = 100): int
    {
        $limit = max(1, min(500, $limit));
        $stmt = $pdo->query(
            "SELECT id FROM subscriptions WHERE cancel_at_period_end = 1
             AND status IN ('active','trialing','past_due')
             AND current_period_end IS NOT NULL AND current_period_end <= UTC_TIMESTAMP()
             ORDER BY current_period_end, id LIMIT {$limit} FOR UPDATE"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $update = $pdo->prepare(
            "UPDATE subscriptions SET status = 'canceled', cancel_at_period_end = 0,
             canceled_at = COALESCE(canceled_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
             WHERE id = :id"
        );
        foreach ($ids as $id) {
            $update->execute(['id'=>$id]);
            EntitlementService::synchronize($pdo, $id);
        }
        return count($ids);
    }

    private static function dateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('invalid_subscription_date');
        try {
            $date = is_int($value)
                ? (new \DateTimeImmutable('@' . $value))->setTimezone(new \DateTimeZone('UTC'))
                : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            throw new RuntimeException('invalid_subscription_date');
        }
    }
}
