<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppConfig;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\EmailTemplates;
use App\Core\MailService;
use App\Core\Localization;
use App\Core\SessionManager;
use PDO;
use Throwable;
use RuntimeException;

final class AuthController
{
    public function showLogin(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        $error = null;
        require BASE_PATH . '/app/Views/login.php';
    }

    public function showRegister(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        $registrationEnabled = $this->registrationEnabled();
        $error = null;
        require __DIR__ . '/../Views/register.php';
    }

    private function verifyRegistrationCsrf(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
    }

    private function registrationInput(): array
    {
        return [
            strtolower(trim((string)($_POST['email'] ?? ''))),
            trim((string)($_POST['display_name'] ?? '')),
            (string)($_POST['password'] ?? ''),
        ];
    }

    private function validRegistrationCredentials(string $email, string $password): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL)
            && strlen($email) <= 190
            && $this->strongPassword($password);
    }

    private function validRegistrationDisplayName(string $display): bool
    {
        return $display !== '' && mb_strlen($display) <= 100;
    }

    public function register(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        $this->verifyRegistrationCsrf();

        [$email, $display, $password] = $this->registrationInput();
        $registrationEnabled = $this->registrationEnabled();

        if (!$registrationEnabled) {
            http_response_code(403);
            $error = Localization::translate("Registration is currently closed.");
            require BASE_PATH . '/app/Views/register.php';
            return;
        }
        if (!$this->validRegistrationCredentials($email, $password)) {
            $error = Localization::translate("Use a valid email address and a password of at least 12 characters containing upper and lower case letters and a number.");
            require BASE_PATH . '/app/Views/register.php';
            return;
        }
        if (!$this->validRegistrationDisplayName($display)) {
            $error = Localization::translate("Enter a display name of up to 100 characters.");
            require BASE_PATH . '/app/Views/register.php';
            return;
        }

        $pdo = Database::connection();
        if ($this->recordAuthAttempt($pdo, 'register', $email)) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = Localization::translate("Too many requests. Please wait before trying again.");
            require BASE_PATH . '/app/Views/register.php';
            return;
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetchColumn() !== false) {
            $message = Localization::translate("If registration can be completed for this address, a verification email will be sent shortly.");
            require BASE_PATH . '/app/Views/register-result.php';
            return;
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                "INSERT INTO users (email, password_hash, display_name, status)
                 VALUES (:email, :password_hash, :display_name, 'pending')"
            );
            $insert->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'display_name' => $display,
            ]);
            $userId = (int)$pdo->lastInsertId();
            $profile = $pdo->prepare('INSERT INTO user_profiles (user_id, language) VALUES (:user_id, :language)');
            $profile->execute(['user_id' => $userId, 'language' => Localization::current()]);
            $memberRole = $pdo->prepare(
                "INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, id FROM roles WHERE role_key = 'member'"
            );
            $memberRole->execute(['user_id' => $userId]);
            if ($memberRole->rowCount() !== 1) {
                throw new RuntimeException('Default member role is unavailable.');
            }
            $token = $this->issueToken($pdo, $userId, 'email_verification', 86400);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // A duplicate race is deliberately indistinguishable from an existing address.
            if ((string)$e->getCode() !== '23000') {
                error_log('Registration failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            }
            $message = Localization::translate("If registration can be completed for this address, a verification email will be sent shortly.");
            require BASE_PATH . '/app/Views/register-result.php';
            return;
        }

        try {
            $url = AppConfig::baseUrl() . '/verify-email?token=' . rawurlencode($token);
            $mail = EmailTemplates::verification(Localization::current(), $display, $url);
            MailService::send($email, $mail['subject'], $mail['text']);
        } catch (Throwable $e) {
            error_log('Verification email could not be delivered (' . get_class($e) . ', code ' . $e->getCode() . ').');
        }

        $message = Localization::translate("If registration can be completed for this address, a verification email will be sent shortly.");
        require BASE_PATH . '/app/Views/register-result.php';
    }

    public function verifyEmailForm(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $token = trim((string)($_GET['token'] ?? ''));
        $valid = false;
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $stmt = Database::connection()->prepare(
                "SELECT id FROM auth_tokens
                 WHERE token_hash = :token_hash AND token_type = 'email_verification'
                   AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
            );
            $stmt->execute(['token_hash' => hash('sha256', $token)]);
            $valid = $stmt->fetchColumn() !== false;
        }
        require BASE_PATH . '/app/Views/verify-email.php';
    }

    public function verifyEmail(): void
    {
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $token = trim((string)($_POST['token'] ?? ''));
        $verified = false;
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, user_id FROM auth_tokens
                     WHERE token_hash = :token_hash AND token_type = 'email_verification'
                       AND used_at IS NULL AND expires_at > NOW()
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute(['token_hash' => hash('sha256', $token)]);
                $row = $stmt->fetch();
                if ($row) {
                    $consume = $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                    $consume->execute(['id' => (int)$row['id']]);
                    $activate = $pdo->prepare(
                        "UPDATE users SET status = 'active', email_verified_at = NOW()
                         WHERE id = :user_id AND status = 'pending' AND email_verified_at IS NULL"
                    );
                    $activate->execute(['user_id' => (int)$row['user_id']]);
                    $verified = $activate->rowCount() === 1;
                    if ($verified) {
                        \App\Core\AuthorizationRevision::bump($pdo, (int)$row['user_id']);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Email verification failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            }
        }
        require BASE_PATH . '/app/Views/verify-result.php';
    }

    public function resendVerification(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pdo = Database::connection();
        $limited = $this->recordAuthAttempt($pdo, 'resend_verification', $email);
        if (!$limited && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT u.id, u.display_name, p.language FROM users u INNER JOIN user_profiles p ON p.user_id = u.id WHERE u.email = :email AND u.status = 'pending' LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            if ($user) {
                try {
                    $token = $this->issueToken($pdo, (int)$user['id'], 'email_verification', 86400);
                    $url = AppConfig::baseUrl() . '/verify-email?token=' . rawurlencode($token);
                    $mail = EmailTemplates::verification((string)($user['language'] ?? 'vi'), (string)($user['display_name'] ?? ''), $url);
                    MailService::send($email, $mail['subject'], $mail['text']);
                } catch (Throwable $e) {
                    error_log('Verification resend failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
                }
            }
        }
        $message = Localization::translate("If this address has a pending account, a new verification email will be sent shortly.");
        require dirname(__DIR__, 2) . '/app/Views/register-result.php';
    }

    public function showForgotPassword(): void
    {
        $error = null;
        require dirname(__DIR__) . '/Views/forgot-password.php';
    }

    public function forgotPassword(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pdo = Database::connection();
        $limited = $this->recordAuthAttempt($pdo, 'password_reset', $email);
        if (!$limited && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("SELECT u.id, u.display_name, p.language FROM users u INNER JOIN user_profiles p ON p.user_id = u.id WHERE u.email = :email AND u.status = 'active' LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();
            if ($user) {
                try {
                    $token = $this->issueToken($pdo, (int)$user['id'], 'password_reset', 3600);
                    $url = AppConfig::baseUrl() . '/reset-password?token=' . rawurlencode($token);
                    $mail = EmailTemplates::passwordReset((string)($user['language'] ?? 'vi'), (string)($user['display_name'] ?? ''), $url);
                    MailService::send($email, $mail['subject'], $mail['text']);
                } catch (Throwable $e) {
                    error_log('Password reset email could not be delivered (' . get_class($e) . ', code ' . $e->getCode() . ').');
                }
            }
        }
        $message = Localization::translate("If an active account matches that address, password reset instructions will be sent shortly.");
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        require BASE_PATH . '/app/Views/password-reset-result.php';
    }

    public function showResetPassword(): void
    {
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $token = trim((string)($_GET['token'] ?? ''));
        $valid = false;
        if (preg_match('/^[a-f0-9]{64}$/', $token)) {
            $stmt = Database::connection()->prepare(
                "SELECT id FROM auth_tokens
                 WHERE token_hash = :token_hash AND token_type = 'password_reset'
                   AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
            );
            $stmt->execute(['token_hash' => hash('sha256', $token)]);
            $valid = $stmt->fetchColumn() !== false;
        }
        require dirname(__DIR__, 2) . '/app/Views/reset-password.php';
    }

    public function resetPassword(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $token = trim((string)($_POST['token'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $reset = false;
        $resetUserId = null;
        if ($this->strongPassword($password) && preg_match('/^[a-f0-9]{64}$/', $token)) {
            $pdo = Database::connection();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, user_id FROM auth_tokens
                     WHERE token_hash = :token_hash AND token_type = 'password_reset'
                       AND used_at IS NULL AND expires_at > NOW()
                     LIMIT 1 FOR UPDATE"
                );
                $stmt->execute(['token_hash' => hash('sha256', $token)]);
                $row = $stmt->fetch();
                if ($row) {
                    $updateUser = $pdo->prepare(
                        "UPDATE users SET password_hash = :password_hash, updated_at = NOW()
                         WHERE id = :user_id AND status = 'active'"
                    );
                    $updateUser->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'user_id' => (int)$row['user_id'],
                    ]);
                    if ($updateUser->rowCount() === 1) {
                        $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL')
                            ->execute(['user_id' => (int)$row['user_id']]);
                        $pdo->prepare('UPDATE sessions SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL')
                            ->execute(['user_id' => (int)$row['user_id']]);
                        $reset = true;
                        $resetUserId = (int)$row['user_id'];
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Password reset failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            }
        }
        if ($reset && $resetUserId !== null && (int)($_SESSION['user_id'] ?? 0) === $resetUserId) {
            SessionManager::invalidate();
        }
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        require BASE_PATH . '/app/Views/password-reset-result.php';
    }

    public function login(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        if (!$validEmail || $password === '') {
            $error = Localization::translate("The email or password is incorrect.");
            require BASE_PATH . '/app/Views/login.php';
            return;
        }

        $pdo = Database::connection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packedIp = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;

        $identityLimit = $pdo->prepare(
            'SELECT COUNT(*) FROM login_history
             WHERE email_attempted = :email AND success = 0
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $identityLimit->execute(['email' => $email]);
        $ipBlocked = false;
        if ($packedIp !== null) {
            $ipLimit = $pdo->prepare(
                'SELECT COUNT(*) FROM login_history
                 WHERE ip_address = :ip AND success = 0
                   AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
            );
            $ipLimit->bindValue(':ip', $packedIp, PDO::PARAM_LOB);
            $ipLimit->execute();
            $ipBlocked = (int)$ipLimit->fetchColumn() >= 30;
        }
        if ((int)$identityLimit->fetchColumn() >= 10 || $ipBlocked) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = Localization::translate("Too many sign-in attempts. Please wait and try again.");
            require BASE_PATH . '/app/Views/login.php';
            return;
        }

        $stmt = $pdo->prepare('SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        $success = $user && $user['status'] === 'active' && password_verify($password, $user['password_hash']);

        $log = $pdo->prepare(
            'INSERT INTO login_history (user_id,email_attempted,ip_address,user_agent,success)
             VALUES (:uid,:email,:ip,:ua,:success)'
        );
        $knownUserId = $user ? (int)$user['id'] : null;
        $log->bindValue(':uid', $knownUserId, $knownUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $log->bindValue(':email', $email);
        $log->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $log->bindValue(':ua', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500));
        $log->bindValue(':success', $success ? 1 : 0, PDO::PARAM_INT);
        $log->execute();

        if (!$success) {
            usleep(250000);
            $error = Localization::translate("The email or password is incorrect.");
            require BASE_PATH . '/app/Views/login.php';
            return;
        }

        SessionManager::establish((int)$user['id']);
        // Preserve a validated OAuth authorization request across sign-in.
        // The request itself is stored server-side by OAuthController; never
        // accept an arbitrary return URL from the login form.
        header('Location: ' . (is_array($_SESSION['oauth_pending'] ?? null) ? '/oauth/authorize' : '/dashboard'), true, 303);
    }

    public function logout(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        SessionManager::revokeCurrent();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
        header('Location: /', true, 303);
    }

    private function registrationEnabled(): bool
    {
        try {
            $stmt = Database::connection()->prepare(
                "SELECT setting_value FROM app_settings WHERE setting_key = 'registration_enabled' LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetchColumn() === '1';
        } catch (Throwable $e) {
            return false;
        }
    }

    private function strongPassword(string $password): bool
    {
        return strlen($password) >= 12
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    private function recordAuthAttempt(PDO $pdo, string $action, string $email): bool
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $packedIp = $ip !== false ? inet_pton($ip) : null;
        $identifierHash = $email !== '' ? hash('sha256', $email) : null;
        $insert = $pdo->prepare(
            'INSERT INTO auth_attempts (action, ip_address, identifier_hash) VALUES (:action, :ip, :identifier)'
        );
        $insert->bindValue(':action', $action);
        $insert->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $insert->bindValue(':identifier', $identifierHash, $identifierHash === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insert->execute();

        $ipCount = 0;
        if ($packedIp !== null) {
            $byIp = $pdo->prepare(
                'SELECT COUNT(*) FROM auth_attempts
                 WHERE action = :action AND ip_address = :ip
                   AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
            );
            $byIp->bindValue(':action', $action);
            $byIp->bindValue(':ip', $packedIp, PDO::PARAM_LOB);
            $byIp->execute();
            $ipCount = (int)$byIp->fetchColumn();
        }
        $byIdentifier = $pdo->prepare(
            'SELECT COUNT(*) FROM auth_attempts
             WHERE action = :action AND identifier_hash = :identifier
               AND created_at >= (NOW() - INTERVAL 15 MINUTE)'
        );
        $byIdentifier->execute(['action' => $action, 'identifier' => $identifierHash]);
        return $ipCount > 20 || (int)$byIdentifier->fetchColumn() > 5;
    }

    private function issueToken(PDO $pdo, int $userId, string $type, int $ttl): string
    {
        $pdo->prepare(
            'UPDATE auth_tokens SET used_at = NOW()
             WHERE user_id = :user_id AND token_type = :type AND used_at IS NULL'
        )->execute(['user_id' => $userId, 'type' => $type]);

        $token = bin2hex(random_bytes(32));
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $packedIp = $ip !== false ? inet_pton($ip) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at, request_ip)
             VALUES (:user_id, :type, :token_hash, DATE_ADD(NOW(), INTERVAL :ttl SECOND), :ip)'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':token_hash', hash('sha256', $token));
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->execute();
        return $token;
    }
}
