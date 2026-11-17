<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;
use Throwable;

final class UserRoleManager
{
    private const ALLOWED_ROLES = ['super_admin', 'admin', 'member'];

    private static function validateReplacementRequest(int $userId, array $roleKeys): void
    {
        if ($userId < 1 || $roleKeys === []) {
            throw new RuntimeException('At least one role is required.');
        }
    }
    private static function validateRoleKey($roleKey): void
    {
        if (!is_string($roleKey) || !in_array($roleKey, self::ALLOWED_ROLES, true)) {
            throw new RuntimeException('Invalid role assignment.');
        }
    }
    public static function replace(PDO $pdo, int $userId, array $roleKeys): array
    {
        self::validateReplacementRequest($userId, $roleKeys);

        $normalized = [];
        foreach ($roleKeys as $roleKey) {
            self::validateRoleKey($roleKey);
            $normalized[] = $roleKey;
        }
        $normalized = array_values(array_unique($normalized));

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $user = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $user->execute(['id' => $userId]);
            if ($user->fetchColumn() === false) {
                throw new RuntimeException('User does not exist.');
            }

            $currentStmt = $pdo->prepare(
                'SELECT r.role_key FROM user_roles ur
                 INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id'
            );
            $currentStmt->execute(['id' => $userId]);
            $current = $currentStmt->fetchAll(PDO::FETCH_COLUMN);
            $current = array_values(array_unique(array_map('strval', $current)));
            $desired = $normalized;
            sort($current, SORT_STRING);
            sort($desired, SORT_STRING);
            if ($current === $desired) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return $normalized;
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $userId]);
            $assign = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE role_key = :role_key'
            );
            foreach ($normalized as $roleKey) {
                $assign->execute(['user_id' => $userId, 'role_key' => $roleKey]);
                if ($assign->rowCount() !== 1) {
                    throw new RuntimeException('Role does not exist.');
                }
            }
            AuthorizationRevision::bump($pdo, $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $normalized;
        } catch (Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
