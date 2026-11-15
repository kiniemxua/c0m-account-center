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
        $canManageApplications = Authorization::hasPermission((int)$user['id'], 'apps.manage');
        $canManageGroups = Authorization::hasPermission((int)$user['id'], 'groups.manage');
                $applicationsQuery = Database::connection()->prepare(
                        "SELECT a.app_key, a.name, a.description, a.url, a.category, a.version, a.featured, a.access_level
                         FROM applications a
                         WHERE a.status = 'active'
                             AND (a.access_level IN ('public', 'members') OR (
                                        a.access_level = 'restricted' AND EXISTS (
                                                SELECT 1 FROM application_groups ag
                                                INNER JOIN user_groups ug ON ug.group_id = ag.group_id
                                                INNER JOIN member_groups g ON g.id = ug.group_id AND g.status = 'active'
                                                WHERE ag.application_id = a.id AND ug.user_id = :user_id
                                        )
                             ))
                         ORDER BY a.featured DESC, a.name ASC LIMIT 100"
                );
                $applicationsQuery->execute(['user_id' => (int)$user['id']]);
                $applications = $applicationsQuery->fetchAll();
        require BASE_PATH . '/app/Views/dashboard.php';
    }
}
