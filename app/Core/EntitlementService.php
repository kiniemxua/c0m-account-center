<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class EntitlementService
{
    public static function synchronize(PDO $pdo, int $subscriptionId): int
    {
        $stmt = $pdo->prepare(
            'SELECT s.id, s.user_id, s.status, s.current_period_start, s.current_period_end, s.cancel_at_period_end,
                    p.features, p.quotas
             FROM subscriptions s
             INNER JOIN subscription_plans p ON p.id = s.plan_id
             WHERE s.id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $subscriptionId]);
        $subscription = $stmt->fetch();
        if (!$subscription) throw new RuntimeException('subscription_not_found');

        $userId = (int)$subscription['user_id'];
        $periodStillValid = $subscription['current_period_end'] === null
            || strtotime((string)$subscription['current_period_end'] . ' UTC') > time();
        $isCurrent = $periodStillValid && (
            in_array($subscription['status'], ['active', 'trialing'], true)
            || ($subscription['status'] === 'canceled' && (int)$subscription['cancel_at_period_end'] === 1)
        );
        $desired = [];
        if ($isCurrent) {
            $features = json_decode((string)$subscription['features'], true);
            $quotas = json_decode((string)$subscription['quotas'], true);
            foreach (['feature' => $features, 'quota' => $quotas] as $prefix => $values) {
                if (!is_array($values)) continue;
                foreach ($values as $key => $value) {
                    if (!is_string($key) || preg_match('/\A[a-z0-9][a-z0-9._:-]{0,100}\z/D', $key) !== 1) continue;
                    $desired[$prefix . '.' . $key] = $value;
                }
            }
        }

        $currentStmt = $pdo->prepare(
            "SELECT entitlement_key, entitlement_value, source_subscription_id, valid_from, valid_until
             FROM user_entitlements WHERE user_id = :user AND status = 'active' FOR UPDATE"
        );
        $currentStmt->execute(['user' => $userId]);
        $currentRows = $currentStmt->fetchAll();
        $current = [];
        foreach ($currentRows as $row) $current[(string)$row['entitlement_key']] = $row;

        $validFrom = is_string($subscription['current_period_start']) && $subscription['current_period_start'] !== ''
            ? (string)$subscription['current_period_start'] : gmdate('Y-m-d H:i:s');
        $validUntil = $subscription['current_period_end'] === null ? null : (string)$subscription['current_period_end'];
        $changed = false;
        foreach ($current as $key => $row) {
            if (!array_key_exists($key, $desired)
                || (int)$row['source_subscription_id'] !== $subscriptionId
                || json_decode((string)$row['entitlement_value'], true) !== $desired[$key]
                || (string)$row['valid_from'] !== $validFrom
                || ($row['valid_until'] === null ? null : (string)$row['valid_until']) !== $validUntil) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            foreach ($desired as $key => $value) {
                if (!isset($current[$key])) {
                    $changed = true;
                    break;
                }
            }
        }

        $revision = AuthorizationRevision::current($pdo, $userId);
        if ($changed) {
            AuthorizationRevision::bump($pdo, $userId);
            $revision = AuthorizationRevision::current($pdo, $userId);
            $pdo->prepare(
                "UPDATE user_entitlements SET status = 'revoked', revision = :revision, updated_at = UTC_TIMESTAMP()
                 WHERE user_id = :user AND status = 'active'"
            )->execute(['revision' => $revision, 'user' => $userId]);

            $upsert = $pdo->prepare(
                'INSERT INTO user_entitlements
                    (user_id, entitlement_key, entitlement_value, source_subscription_id, valid_from, valid_until, status, revision)
                 VALUES (:user, :key, :value, :subscription, :valid_from, :valid_until, \'active\', :revision)
                 ON DUPLICATE KEY UPDATE
                    entitlement_value = VALUES(entitlement_value),
                    source_subscription_id = VALUES(source_subscription_id),
                    valid_from = VALUES(valid_from),
                    valid_until = VALUES(valid_until),
                    status = \'active\',
                    revision = VALUES(revision),
                    updated_at = UTC_TIMESTAMP()'
            );
            foreach ($desired as $key => $value) {
                $upsert->execute([
                    'user' => $userId,
                    'key' => $key,
                    'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'subscription' => $subscriptionId,
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'revision' => $revision,
                ]);
            }
        }
        return $revision;
    }

    public static function forUser(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT entitlement_key, entitlement_value, valid_until, revision
             FROM user_entitlements
             WHERE user_id = :user AND status = 'active'
               AND valid_from <= UTC_TIMESTAMP()
               AND (valid_until IS NULL OR valid_until > UTC_TIMESTAMP())
             ORDER BY entitlement_key"
        );
        $stmt->execute(['user' => $userId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string)$row['entitlement_key']] = [
                'value' => json_decode((string)$row['entitlement_value'], true),
                'valid_until' => $row['valid_until'],
                'revision' => (int)$row['revision'],
            ];
        }
        return $result;
    }
}
