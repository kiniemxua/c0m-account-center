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
        $email = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string)($_POST['password'] ?? '');
        if (!$email || $password === '') { $error = 'Please enter a valid email and password.'; require BASE_PATH . '/app/Views/login.php'; return; }
        $stmt = Database::connection()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
            usleep(250000);
            $error = 'The email or password is incorrect.';
            require BASE_PATH . '/app/Views/login.php'; return;
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
