<?php
declare(strict_types=1);

namespace App\\Controllers;

use App\\Core\\AuthorizationRevision;
use App\\Core\\Database;
use App\\Core\\OAuthController;
use App\\Core\\OAuthService;
use PDO;

final class AccountApiController
{
    public function me(): void
    {
        $identity = $this->authenticate(['openid']);
        if ($identity === null) return;

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT u.id, u.email, u.display_name, u.email_verified_at, p.username, p.avatar_path, p.language, p.timezone
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id AND u.status = \'active\' LIMIT 1'
        );
        $stmt->execute(['id' => (int)$identity['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $this->respond(401, ['ok' => false, 'error' => ['code' => 'invalid_token', 'message' => 'The account is no longer available.']]);
            return;
        }

        $data = ['id' => (string)$user['id']];
        if (in_array('profile', $identity['scopes'], true)) {
            $data['display_name'] = (string)($user['display_name'] ?? '');
            $data['username'] = $user['username'] !== null ? (string)$user['username'] : null;
            $data['avatar'] = $user['avatar_path'] !== null ? (string)$user['avatar_path'] : null;
            $data['language'] = (string)($user['language'] ?? 'vi');
            $data['timezone'] = (string)($user['timezone'] ?? 'UTC');
        }
        if (in_array('email', $identity['scopes'], true)) {
            $data['email'] = (string)$user['email'];
            $data['email_verified'] = $user['email_verified_at'] !== null;
        }
        $data['authorization_revision'] = AuthorizationRevision::current($pdo, (int)$user['id']);
        $this->respond(200, ['ok' => true, 'data' => $data, 'meta' => $this->meta()]);
    }

    public function groups(): void
    {
        $identity = $this->authenticate(['groups']);
        if ($identity === null) return;
        $stmt = Database::connection()->prepare(
            "SELECT g.group_key, g.name
             FROM user_groups ug
             INNER JOIN member_groups g ON g.id = ug.group_id
             WHERE ug.user_id = :user AND g.status = 'active'
             ORDER BY g.group_key"
        );
        $stmt->execute(['user' => (int)$identity['user_id']]);
        $this->respond(200, [
            'ok' => true,
            'data' => ['groups' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'authorization_revision' => AuthorizationRevision::current(Database::connection(), (int)$identity['user_id'])],
            'meta' => $this->meta(),
        ]);
    }

    public function permissions(): void
    {
        $identity = $this->authenticate(['permissions']);
        if ($identity === null) return;
        $pdo = Database::connection();
        $roles = $pdo->prepare(
            'SELECT DISTINCT r.role_key
             FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user ORDER BY r.role_key'
        );
        $roles->execute(['user' => (int)$identity['user_id']]);
        $permissions = $pdo->prepare(
            'SELECT DISTINCT p.permission_key
             FROM user_roles ur
             INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user ORDER BY p.permission_key'
        );
        $permissions->execute(['user' => (int)$identity['user_id']]);
        $this->respond(200, [
            'ok' => true,
            'data' => [
                'roles' => $roles->fetchAll(PDO::FETCH_COLUMN),
                'permissions' => $permissions->fetchAll(PDO::FETCH_COLUMN),
                'authorization_revision' => AuthorizationRevision::current($pdo, (int)$identity['user_id']),
            ],
            'meta' => $this->meta(),
        ]);
    }

    public function memberships(): void
    {
        $identity = $this->authenticate(['groups']);
        if ($identity === null) return;
        $stmt = Database::connection()->prepare(
            'SELECT g.id AS group_id, g.group_key, g.name, g.status, ug.assigned_at
             FROM user_groups ug
             INNER JOIN member_groups g ON g.id = ug.group_id
             WHERE ug.user_id = :user
             ORDER BY g.group_key'
        );
        $stmt->execute(['user' => (int)$identity['user_id']]);
        $this->respond(200, [
            'ok' => true,
            'data' => [
                'memberships' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'authorization_revision' => AuthorizationRevision::current(Database::connection(), (int)$identity['user_id']),
            ],
            'meta' => $this->meta(),
        ]);
    }

    private function authenticate(array $requiredScopes): ?array
    {
        (new OAuthController())->corsForApi();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/\\ABearer\\s+([A-Za-z0-9_-]+)\\z/i', trim($header), $match) ? $match[1] : '';
        $identity = $token !== '' ? OAuthService::authenticateAccessToken(Database::connection(), $token) : null;
        if (!$identity) {
            header('WWW-Authenticate: Bearer error="invalid_token"');
            $this->respond(401, ['ok' => false, 'error' => ['code' => 'invalid_token', 'message' => 'A valid access token is required.']]);
            return null;
        }
        $missing = array_values(array_diff($requiredScopes, $identity['scopes']));
        if ($missing !== []) {
            header('WWW-Authenticate: Bearer error="insufficient_scope", scope="' . implode(' ', $requiredScopes) . '"');
            $this->respond(403, ['ok' => false, 'error' => ['code' => 'insufficient_scope', 'message' => 'The access token does not include the required scope.']]);
            return null;
        }
        return $identity;
    }

    private function meta(): array
    {
        return ['request_id' => bin2hex(random_bytes(16)), 'timestamp' => gmdate('c')];
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
