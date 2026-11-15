<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class GroupManager
{
    public static function create(PDO $pdo, string $key, string $name, string $description): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO member_groups (group_key, name, description) VALUES (:group_key, :name, :description)'
        );
        $stmt->execute([
            'group_key' => $key,
            'name' => $name,
            'description' => $description === '' ? null : $description,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function replaceUserGroups(PDO $pdo, int $userId, array $groupIds, int $actorId): array
    {
        return self::mutate($pdo, function () use ($pdo, $userId, $groupIds, $actorId): array {
            $user = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $user->execute(['id' => $userId]);
            if ($user->fetchColumn() === false) {
                throw new RuntimeException('user_not_found');
            }
            $desired = self::normalizeIds($groupIds);
            self::assertActiveGroups($pdo, $desired);
            $stmt = $pdo->prepare('SELECT group_id FROM user_groups WHERE user_id = :id');
            $stmt->execute(['id' => $userId]);
            $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $oldSorted = $current;
            $newSorted = $desired;
            sort($oldSorted, SORT_NUMERIC);
            sort($newSorted, SORT_NUMERIC);
            if ($oldSorted === $newSorted) {
                return $desired;
            }
            $pdo->prepare('DELETE FROM user_groups WHERE user_id = :id')->execute(['id' => $userId]);
            $insert = $pdo->prepare('INSERT INTO user_groups (user_id, group_id, assigned_by) VALUES (:user, :group, :actor)');
            foreach ($desired as $groupId) {
                $insert->execute(['user' => $userId, 'group' => $groupId, 'actor' => $actorId]);
            }
            AuthorizationRevision::bump($pdo, $userId);
            return $desired;
        });
    }

    public static function replaceApplicationGroups(PDO $pdo, int $applicationId, array $groupIds): array
    {
        return self::mutate($pdo, function () use ($pdo, $applicationId, $groupIds): array {
            $application = $pdo->prepare('SELECT id FROM applications WHERE id = :id FOR UPDATE');
            $application->execute(['id' => $applicationId]);
            if ($application->fetchColumn() === false) {
                throw new RuntimeException('application_not_found');
            }
            $desired = self::normalizeIds($groupIds);
            self::assertActiveGroups($pdo, $desired);
            $stmt = $pdo->prepare('SELECT group_id FROM application_groups WHERE application_id = :id');
            $stmt->execute(['id' => $applicationId]);
            $current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $oldSorted = $current;
            $newSorted = $desired;
            sort($oldSorted, SORT_NUMERIC);
            sort($newSorted, SORT_NUMERIC);
            if ($oldSorted === $newSorted) {
                return $desired;
            }
            $affectedGroups = array_values(array_unique(array_merge($current, $desired)));
            $userIds = self::userIdsForGroups($pdo, $affectedGroups);
            $pdo->prepare('DELETE FROM application_groups WHERE application_id = :id')->execute(['id' => $applicationId]);
            $insert = $pdo->prepare('INSERT INTO application_groups (application_id, group_id) VALUES (:app, :group)');
            foreach ($desired as $groupId) {
                $insert->execute(['app' => $applicationId, 'group' => $groupId]);
            }
            foreach ($userIds as $userId) {
                AuthorizationRevision::bump($pdo, $userId);
            }
            return $desired;
        });
    }

    public static function applicationGroupIds(PDO $pdo, int $applicationId): array
    {
        $stmt = $pdo->prepare('SELECT group_id FROM application_groups WHERE application_id = :id ORDER BY group_id');
        $stmt->execute(['id' => $applicationId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function mutate(PDO $pdo, callable $callback): array
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function normalizeIds(array $groupIds): array
    {
        $ids = [];
        foreach ($groupIds as $groupId) {
            if (!is_int($groupId) && !(is_string($groupId) && ctype_digit($groupId))) {
                throw new RuntimeException('invalid_group_id');
            }
            $id = (int)$groupId;
            if ($id < 1) {
                throw new RuntimeException('invalid_group_id');
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    private static function assertActiveGroups(PDO $pdo, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM member_groups WHERE status = 'active' AND id IN ({$placeholders})");
        $stmt->execute($groupIds);
        if ((int)$stmt->fetchColumn() !== count($groupIds)) {
            throw new RuntimeException('inactive_or_missing_group');
        }
    }

    private static function userIdsForGroups(PDO $pdo, array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT user_id FROM user_groups WHERE group_id IN ({$placeholders}) FOR UPDATE");
        $stmt->execute($groupIds);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}