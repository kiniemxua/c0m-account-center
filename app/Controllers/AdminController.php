<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use PDO;
use Throwable;

final class AdminController
{
    public function users(): void
    {
        Authorization::requirePermission('users.view');
        $stmt = Database::connection()->query(
            'SELECT u.id, u.email, u.display_name, u.status, u.created_at,
                    GROUP_CONCAT(r.role_key ORDER BY r.role_key SEPARATOR ", ") AS roles
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             GROUP BY u.id ORDER BY u.created_at DESC LIMIT 200'
        );
        $users = $stmt->fetchAll();
        $canManage = Authorization::hasPermission((int)$_SESSION['user_id'], 'users.manage');
        $canAssignRoles = Authorization::isSuperAdmin((int)$_SESSION['user_id']);
        require BASE_PATH . '/app/Views/admin-users.php';
    }

    public function updateStatus(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
        $actorId = Authorization::requirePermission('users.manage');
        $targetId = (int)($_POST['user_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($targetId < 1 || !in_array($status, ['active', 'suspended', 'banned', 'pending'], true)) {
            http_response_code(400);
            exit('Invalid user status.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Lock the active super-admin set first so concurrent status changes
            // cannot disable the final active super administrator.
            $activeSuperAdmins = $pdo->query(
                "SELECT u.id FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.role_key = 'super_admin' AND u.status = 'active'
                 ORDER BY u.id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (!Authorization::hasPermission($actorId, 'users.manage')) {
                throw new \\RuntimeException('actor_permission_revoked');
            }
            $target = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $targetId]);
            $targetRow = $target->fetch();
            if (!$targetRow) {
                throw new \RuntimeException('target_not_found');
            }

            $targetSuperAdmin = Authorization::isSuperAdmin($targetId);
            if ($targetSuperAdmin && !Authorization::isSuperAdmin($actorId)) {
                throw new \RuntimeException('super_admin_forbidden');
            }
            if ($targetSuperAdmin && $targetRow['status'] === 'active' && $status !== 'active' && count($activeSuperAdmins) <= 1) {
                throw new \RuntimeException('last_super_admin');
            }

            $stmt = $pdo->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
            $stmt->execute(['status' => $status, 'id' => $targetId]);
            if ($status !== 'active') {
                $pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
                    ->execute(['id' => $targetId]);
            }
            Authorization::audit($actorId, 'user.status.changed', 'user', (string)$targetId, ['status' => $status]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $reason = $e->getMessage();
            if ($reason === 'actor_permission_revoked') {
                http_response_code(403);
                exit('Your permission to manage users is no longer active.');
            }
            if ($reason === 'target_not_found') {
                http_response_code(404);
                exit('User not found.');
            }
            if ($reason === 'super_admin_forbidden') {
                http_response_code(403);
                exit('Only a super administrator may manage a super administrator.');
            }
            if ($reason === 'last_super_admin') {
                http_response_code(409);
                exit('The last active super administrator cannot be disabled.');
            }
            error_log('User status update failed: ' . $e->getMessage());
            http_response_code(500);
            exit('User status update failed.');
        }

        header('Location: /admin/users', true, 303);
    }

    public function assignRole(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
        $actorId = (int)($_SESSION['user_id'] ?? 0);
        if ($actorId < 1 || !Authorization::isSuperAdmin($actorId)) {
            http_response_code(403);
            exit('Only a super administrator may assign roles.');
        }
        $targetId = (int)($_POST['user_id'] ?? 0);
        $roleKey = (string)($_POST['role'] ?? '');
        if ($targetId < 1 || !in_array($roleKey, ['super_admin', 'admin', 'member'], true)) {
            http_response_code(400);
            exit('Invalid role assignment.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Use the same lock order as status changes to serialize operations
            // which could otherwise remove the final active super administrator.
            $activeSuperAdmins = $pdo->query(
                "SELECT u.id FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.role_key = 'super_admin' AND u.status = 'active'
                 ORDER BY u.id FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (!Authorization::isSuperAdmin($actorId)) {
                throw new \RuntimeException('actor_no_longer_super_admin');
            }
            $target = $pdo->prepare('SELECT id, status FROM users WHERE id = :id FOR UPDATE');
            $target->execute(['id' => $targetId]);
            $targetRow = $target->fetch();
            if (!$targetRow) {
                throw new \RuntimeException('target_not_found');
            }

            $currentSuper = Authorization::isSuperAdmin($targetId);
            if ($currentSuper && $targetRow['status'] === 'active' && $roleKey !== 'super_admin' && count($activeSuperAdmins) <= 1) {
                throw new \RuntimeException('last_super_admin');
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :id')->execute(['id' => $targetId]);
            $assign = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE role_key = :role_key'
            );
            $assign->execute(['user_id' => $targetId, 'role_key' => $roleKey]);
            if ($assign->rowCount() !== 1) {
                throw new \RuntimeException('Role does not exist.');
            }
            Authorization::audit($actorId, 'user.role.changed', 'user', (string)$targetId, ['role' => $roleKey]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $reason = $e->getMessage();
            if ($reason === 'actor_no_longer_super_admin') {
                http_response_code(403);
                exit('Only a super administrator may assign roles.');
            }
            if ($reason === 'target_not_found') {
                http_response_code(404);
                exit('User not found.');
            }
            if ($reason === 'last_super_admin') {
                http_response_code(409);
                exit('The last active super administrator cannot be demoted.');
            }
            error_log('Role assignment failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Role assignment failed.');
        }

        header('Location: /admin/users', true, 303);
    }

    public function settings(): void
    {
        $actorId = Authorization::requirePermission('settings.manage');
        $pdo = Database::connection();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                http_response_code(419);
                exit('Invalid CSRF token');
            }
            $enabled = ($_POST['registration_enabled'] ?? '0') === '1' ? '1' : '0';
            $stmt = $pdo->prepare(
                "INSERT INTO app_settings (setting_key, setting_value)
                 VALUES ('registration_enabled', :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
            );
            $stmt->execute(['value' => $enabled]);
            Authorization::audit($actorId, 'settings.registration.changed', 'setting', 'registration_enabled', ['enabled' => $enabled === '1']);
            header('Location: /admin/settings', true, 303);
            return;
        }
        $enabled = $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key = 'registration_enabled'"
        )->fetchColumn() === '1';
        require BASE_PATH . '/app/Views/admin-settings.php';
    }
}
