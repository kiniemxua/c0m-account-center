<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Authorization
{
    public static function hasPermission(int $userId, string $permission): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id AND p.permission_key = :permission
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'permission' => $permission]);
        return $stmt->fetchColumn() !== false;
    }

    public static function isSuperAdmin(int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.role_key = 'super_admin' LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    public static function requirePermission(string $permission): int
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            http_response_code(401);
            exit(\App\Core\t('Authentication required.'));
        }
        if (!self::hasPermission($userId, $permission)) {
            http_response_code(403);
            exit(\App\Core\t('You do not have permission to access this resource.'));
        }
        return $userId;
    }

    public static function audit(int $actorId, string $action, string $subjectType, string $subjectId, array $metadata = []): void
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $packedIp = $ip !== false ? inet_pton($ip) : null;
        $stmt = Database::connection()->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, subject_type, subject_id, metadata, ip_address)
             VALUES (:actor, :action, :subject_type, :subject_id, :metadata, :ip)'
        );
        $stmt->bindValue(':actor', $actorId, PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':subject_type', $subjectType);
        $stmt->bindValue(':subject_id', $subjectId);
        $stmt->bindValue(':metadata', json_encode($metadata, JSON_THROW_ON_ERROR));
        $stmt->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->execute();
    }
}
