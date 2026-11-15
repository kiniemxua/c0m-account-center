<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class AuthorizationRevision
{
    public static function current(PDO $pdo, int $userId): int
    {
        if ($userId < 1) {
            return 1;
        }
        $stmt = $pdo->prepare('SELECT revision FROM user_authorization_revisions WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $revision = $stmt->fetchColumn();
        return $revision === false ? 1 : (int)$revision;
    }

    public static function bump(PDO $pdo, int $userId): void
    {
        if ($userId < 1) {
            throw new RuntimeException('Invalid user for authorization revision.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO user_authorization_revisions (user_id, revision)
             VALUES (:user_id, 2)
             ON DUPLICATE KEY UPDATE revision = revision + 1, updated_at = NOW()'
        );
        $stmt->execute(['user_id' => $userId]);
    }
}