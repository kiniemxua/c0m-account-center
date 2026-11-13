<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class SuperAdminGuard
{
    /**
     * Serialize every operation that can remove an active Super Admin.
     * All callers must acquire this lock set before locking actor/target rows.
     */
    public static function lockActive(PDO $pdo): array
    {
        $ids = $pdo->query(
            "SELECT u.id FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.role_key = 'super_admin' AND u.status = 'active'
             ORDER BY u.id FOR UPDATE"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    public static function assertCanRemove(
        array $activeSuperAdminIds,
        int $targetId,
        bool $targetIsSuperAdmin,
        bool $targetIsActive,
        bool $willRemainSuperAdmin
    ): void {
        if ($targetIsSuperAdmin && $targetIsActive && !$willRemainSuperAdmin
            && in_array($targetId, $activeSuperAdminIds, true)
            && count($activeSuperAdminIds) <= 1) {
            throw new RuntimeException('last_super_admin');
        }
    }
}
