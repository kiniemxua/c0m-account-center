<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\SessionManager;

final class SecurityController
{
    public function sessions(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, ip_address, user_agent, last_activity, expires_at
             FROM sessions
             WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > NOW()
             ORDER BY last_activity DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $currentHash = hash('sha256', session_id());
        $sessions = [];
        foreach ($stmt->fetchAll() as $row) {
            $row['is_current'] = hash_equals($currentHash, (string)$row['id']);
            $row['ip_display'] = $row['ip_address'] !== null ? (inet_ntop($row['ip_address']) ?: 'Unknown') : 'Unknown';
            $row['user_agent'] = substr((string)$row['user_agent'], 0, 250);
            $sessions[] = $row;
        }
        require BASE_PATH . '/app/Views/sessions.php';
    }

    public function revoke(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $sessionHash = strtolower(trim((string)($_POST['session_id'] ?? '')));
        if ($userId < 1 || !preg_match('/^[a-f0-9]{64}$/', $sessionHash)) {
            http_response_code(400);
            exit(\App\Core\t('Invalid session reference.'));
        }
        $stmt = Database::connection()->prepare(
            'UPDATE sessions SET revoked_at = NOW()
             WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $sessionHash, 'user_id' => $userId]);
        if (hash_equals(hash('sha256', session_id()), $sessionHash)) {
            SessionManager::invalidate();
            $this->destroyCurrentSession();
            header('Location: /login', true, 303);
            return;
        }
        header('Location: /sessions', true, 303);
    }

    public function revokeOthers(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }
        $stmt = Database::connection()->prepare(
            'UPDATE sessions SET revoked_at = NOW()
             WHERE user_id = :user_id AND id <> :current_id AND revoked_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId, 'current_id' => hash('sha256', session_id())]);
        header('Location: /sessions', true, 303);
    }

    public function loginHistory(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }
        $stmt = Database::connection()->prepare(
            'SELECT ip_address, user_agent, success, created_at
             FROM login_history WHERE user_id = :user_id
             ORDER BY created_at DESC LIMIT 50'
        );
        $stmt->execute(['user_id' => $userId]);
        $history = $stmt->fetchAll();
        foreach ($history as &$row) {
            $row['ip_display'] = $row['ip_address'] !== null ? (inet_ntop($row['ip_address']) ?: 'Unknown') : 'Unknown';
            $row['user_agent'] = substr((string)$row['user_agent'], 0, 250);
        }
        unset($row);
        require BASE_PATH . '/app/Views/login-history.php';
    }

    private function destroyCurrentSession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }
}
