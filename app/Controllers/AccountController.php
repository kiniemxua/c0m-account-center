<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\MailService;
use PDO;
use Throwable;

final class AccountController
{
    public function show(): void
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }
        $stmt = Database::connection()->prepare(
            'SELECT u.email, u.display_name, p.username, p.language, p.timezone
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $account = $stmt->fetch();
        if (!$account) {
            http_response_code(404);
            exit('Account not found.');
        }
        $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
        $error = null;
        require BASE_PATH . '/app/Views/account.php';
    }

    public function updateProfile(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }

        $displayName = trim((string)($_POST['display_name'] ?? ''));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $language = (string)($_POST['language'] ?? 'vi');
        $timezone = (string)($_POST['timezone'] ?? 'Asia/Ho_Chi_Minh');

        if ($displayName === '' || mb_strlen($displayName) > 100
            || ($username !== '' && !preg_match('/^[a-z0-9_]{3,30}$/', $username))
            || !in_array($language, ['vi', 'en'], true)
            || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            http_response_code(422);
            $error = 'Please check your display name, username, language and timezone.';
            $this->renderWithInput($userId, $error);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $updateUser = $pdo->prepare('UPDATE users SET display_name = :name, updated_at = NOW() WHERE id = :id');
            $updateUser->execute(['name' => $displayName, 'id' => $userId]);
            $updateProfile = $pdo->prepare(
                'UPDATE user_profiles SET username = :username, language = :language, timezone = :timezone WHERE user_id = :id'
            );
            $updateProfile->execute([
                'username' => $username !== '' ? $username : null,
                'language' => $language,
                'timezone' => $timezone,
                'id' => $userId,
            ]);
            Authorization::audit($userId, 'account.profile.updated', 'user', (string)$userId, [
                'language' => $language,
                'timezone' => $timezone,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string)$e->getCode() === '23000') {
                http_response_code(422);
                $error = 'That username is already in use.';
                $this->renderWithInput($userId, $error);
                return;
            }
            error_log('Profile update failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Profile update failed.');
        }
        header('Location: /account?saved=1', true, 303);
    }

    public function changePassword(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit('Invalid CSRF token');
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            header('Location: /login', true, 303);
            return;
        }
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!$this->strongPassword($new) || !hash_equals($new, $confirm)) {
            http_response_code(422);
            $error = 'The new password must be at least 12 characters with upper/lowercase letters and a number, and both entries must match.';
            $this->renderWithInput($userId, $error);
            return;
        }

        $pdo = Database::connection();
        $lookup = $pdo->prepare('SELECT email, password_hash FROM users WHERE id = :id AND status = \'active\' LIMIT 1');
        $lookup->execute(['id' => $userId]);
        $user = $lookup->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) {
            http_response_code(422);
            $error = 'The current password is incorrect.';
            $this->renderWithInput($userId, $error);
            return;
        }
        if (password_verify($new, $user['password_hash'])) {
            http_response_code(422);
            $error = 'Choose a password different from your current password.';
            $this->renderWithInput($userId, $error);
            return;
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id AND status = \'active\'');
            $update->execute(['hash' => password_hash($new, PASSWORD_DEFAULT), 'id' => $userId]);
            if ($update->rowCount() !== 1) {
                throw new \RuntimeException('Account status changed during password update.');
            }
            $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = :id AND used_at IS NULL')
                ->execute(['id' => $userId]);
            $pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = :id AND revoked_at IS NULL')
                ->execute(['id' => $userId]);
            Authorization::audit($userId, 'account.password.changed', 'user', (string)$userId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password change failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Password change failed.');
        }

        try {
            MailService::send(
                $user['email'],
                'Your c0m.me password was changed',
                "The password for your c0m.me account was changed. All active sessions were signed out. If you did not make this change, contact support immediately."
            );
        } catch (Throwable $e) {
            error_log('Password-change notification could not be delivered: ' . $e->getMessage());
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        header('Location: /login?password_changed=1', true, 303);
    }

    private function renderWithInput(int $userId, string $error): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.email, u.display_name, p.username, p.language, p.timezone
             FROM users u INNER JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $account = $stmt->fetch();
        $saved = false;
        require BASE_PATH . '/app/Views/account.php';
    }

    private function strongPassword(string $password): bool
    {
        return strlen($password) >= 12
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }
}
