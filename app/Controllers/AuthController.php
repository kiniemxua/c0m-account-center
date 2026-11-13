<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Database;

final class AuthController
{
    public function showLogin(): void
    {
        $error = null;
        require BASE_PATH . '/app/Views/login.php';
    }

    public function login(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) { http_response_code(419); exit('Invalid CSRF token'); }
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        if (!$validEmail || $password === '') {
            $error = 'The email or password is incorrect.';
            require BASE_PATH . '/app/Views/login.php';
            return;
        }

        $pdo = Database::connection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $packedIp = filter_var($ip, FILTER_VALIDATE_IP) ? inet_pton($ip) : null;

        // Conservative per-identity and per-IP throttle. A dedicated distributed limiter
        // can replace this database-backed guard as deployment scale grows.
        $limit = $pdo->prepare(
            'SELECT COUNT(*) FROM login_history
             WHERE email_attempted = :email AND created_at >= (NOW() - INTERVAL 15 MINUTE)
             AND (:ip1 IS NULL OR ip_address = :ip2)'
        );
        $limit->bindValue(':email', $email);
        $limit->bindValue(':ip1', $packedIp, $packedIp === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $limit->bindValue(':ip2', $packedIp, $packedIp === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $limit->execute();
        if ((int)$limit->fetchColumn() >= 10) {
            http_response_code(429);
            header('Retry-After: 900');
            $error = 'Too many sign-in attempts. Please wait and try again.';
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
        $log->bindValue(':uid', $success ? (int)$user['id'] : null, $success ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
        $log->bindValue(':email', $email);
        $log->bindValue(':ip', $packedIp, $packedIp === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $log->bindValue(':ua', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500));
        $log->bindValue(':success', $success ? 1 : 0, \PDO::PARAM_INT);
        $log->execute();

        if (!$success) {
            usleep(250000);
            $error = 'The email or password is incorrect.';
            require BASE_PATH . '/app/Views/login.php';
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        header('Location: /dashboard', true, 303);
    }

    public function logout(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) { http_response_code(419); exit('Invalid CSRF token'); }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
        header('Location: /', true, 303);
    }
}
