<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Database;
use App\Core\Authorization;

final class HomeController
{
    public function index(): void { require BASE_PATH . '/app/Views/home.php'; }
    public function dashboard(): void
    {
        if (empty($_SESSION['user_id'])) { header('Location: /login', true, 303); return; }
        $stmt = Database::connection()->prepare('SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) { $_SESSION = []; header('Location: /login', true, 303); return; }
        $canViewAdmin = Authorization::hasPermission((int)$user['id'], 'users.view');
        $canViewAudit = Authorization::hasPermission((int)$user['id'], 'audit.view');
        require BASE_PATH . '/app/Views/dashboard.php';
    }
}
