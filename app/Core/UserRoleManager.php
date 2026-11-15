<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class UserRoleManager
{
    private const ALLOWED_ROLES = ['super_admin', 'admin', 'member'];

    public static function replace(PDO $pdo, int $userId, array $roleKeys): array
    {
        if ($userId < 1 || $roleKeys === []) {
            throw new RuntimeException('At least one role is required.');
        }

        $normalized = [];
        foreach ($roleKeys as $roleKey) {
            if (!is_string($roleKey) || !in_array($roleKey, self::ALLOWED_ROLES, true)) {
                throw new RuntimeException('Invalid role assignment.');
            }
            $normalized[] = $roleKey;
        }
        $normalized = array_values(array_unique($normalized));

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

        return $normalized;
    }
}
