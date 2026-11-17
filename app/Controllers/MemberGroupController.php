<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\GroupManager;
use PDOException;
use Throwable;

final class MemberGroupController
{
    public function index(): void
    {
        Authorization::requirePermission('groups.manage');
        $pdo = Database::connection();
        $groups = $pdo->query(
            'SELECT g.id, g.group_key, g.name, g.description, g.status,
                    GROUP_CONCAT(ug.user_id ORDER BY ug.user_id) AS member_ids
             FROM member_groups g LEFT JOIN user_groups ug ON ug.group_id = g.id
             GROUP BY g.id ORDER BY g.name, g.id'
        )->fetchAll();
        foreach ($groups as &$group) {
            $group['member_ids'] = $group['member_ids'] ? array_map('intval', explode(',', $group['member_ids'])) : [];
        }
        unset($group);
        $users = $pdo->query("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY email LIMIT 500")->fetchAll();
        $error = null;
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        require BASE_PATH . '/app/Views/admin-groups.php';
    }

    public function save(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $actorId = Authorization::requirePermission('groups.manage');
        $action = $_POST['action'] ?? '';
        $pdo = Database::connection();

        try {
            if ($action === 'create') {
                $key = is_string($_POST['group_key'] ?? null) ? trim($_POST['group_key']) : '';
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                if (!preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/D', $key)
                    || $name === '' || mb_strlen($name) > 120 || mb_strlen($description) > 2000) {
                    http_response_code(422);
                    $this->renderWithError(\App\Core\t('Check the group key, name and description.'));
                    return;
                }
                $pdo->beginTransaction();
                $groupId = GroupManager::create($pdo, $key, $name, $description);
                Authorization::audit($actorId, 'group.created', 'group', (string)$groupId, ['group_key' => $key]);
                $pdo->commit();
            } elseif ($action === 'update') {
                $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
                if ($groupId === false || $name === '' || mb_strlen($name) > 120 || mb_strlen($description) > 2000
                    || !in_array($status, ['active', 'disabled'], true)) {
                    http_response_code(422);
                    $this->renderWithError(\App\Core\t('Check the group key, name and description.'));
                    return;
                }
                $pdo->beginTransaction();
                $group = $pdo->prepare('SELECT status FROM member_groups WHERE id = :id FOR UPDATE');
                $group->execute(['id' => $groupId]);
                $currentStatus = $group->fetchColumn();
                if ($currentStatus === false) {
                    throw new \DomainException('group_not_found');
                }
                $memberStmt = $pdo->prepare('SELECT user_id FROM user_groups WHERE group_id = :id FOR UPDATE');
                $memberStmt->execute(['id' => $groupId]);
                $memberIds = array_map('intval', $memberStmt->fetchAll(\PDO::FETCH_COLUMN));
                $pdo->prepare('UPDATE member_groups SET name = :name, description = :description, status = :status, updated_at = NOW() WHERE id = :id')
                    ->execute(['name' => $name, 'description' => $description === '' ? null : $description, 'status' => $status, 'id' => $groupId]);
                if ($currentStatus !== $status) {
                    foreach ($memberIds as $memberId) {
                        \App\Core\AuthorizationRevision::bump($pdo, $memberId);
                    }
                }
                Authorization::audit($actorId, 'group.updated', 'group', (string)$groupId, ['status' => $status]);
                $pdo->commit();
            } elseif ($action === 'members') {
                $groupId = filter_var($_POST['group_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $members = $_POST['user_ids'] ?? [];
                if ($groupId === false || !is_array($members)) {
                    http_response_code(422);
                    $this->renderWithError(\App\Core\t('Check the group and member selection.'));
                    return;
                }
                $selected = [];
                foreach ($members as $memberId) {
                    if (!is_string($memberId) || !ctype_digit($memberId) || (int)$memberId < 1) {
                        http_response_code(422);
                        $this->renderWithError(\App\Core\t('Check the group and member selection.'));
                        return;
                    }
                    $selected[] = (int)$memberId;
                }
                $selected = array_values(array_unique($selected));
                $pdo->beginTransaction();
                $group = $pdo->prepare("SELECT id FROM member_groups WHERE id = :id AND status = 'active' FOR UPDATE");
                $group->execute(['id' => $groupId]);
                if ($group->fetchColumn() === false) {
                    throw new \DomainException('group_not_found');
                }
                $currentStmt = $pdo->prepare('SELECT user_id FROM user_groups WHERE group_id = :id');
                $currentStmt->execute(['id' => $groupId]);
                $affected = array_values(array_unique(array_merge(array_map('intval', $currentStmt->fetchAll(\PDO::FETCH_COLUMN)), $selected)));
                foreach ($affected as $memberId) {
                    $memberGroups = $pdo->prepare('SELECT group_id FROM user_groups WHERE user_id = :id');
                    $memberGroups->execute(['id' => $memberId]);
                    $groupIds = array_map('intval', $memberGroups->fetchAll(\PDO::FETCH_COLUMN));
                    if (in_array($memberId, $selected, true)) {
                        $groupIds[] = (int)$groupId;
                    } else {
                        $groupIds = array_values(array_filter($groupIds, static fn(int $id): bool => $id !== (int)$groupId));
                    }
                    GroupManager::replaceUserGroups($pdo, $memberId, $groupIds, $actorId);
                }
                Authorization::audit($actorId, 'group.members.changed', 'group', (string)$groupId, ['member_count' => count($selected)]);
                $pdo->commit();
            } else {
                http_response_code(400);
                exit(\App\Core\t('Invalid group action.'));
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                http_response_code(409);
                $this->renderWithError(\App\Core\t('Group key already exists.'));
                return;
            }
            error_log('Group update failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->renderWithError(\App\Core\t('Group update failed.'));
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException && $e->getMessage() === 'group_not_found') {
                http_response_code(404);
                $this->renderWithError(\App\Core\t('Group not found.'));
                return;
            }
            error_log('Group update failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(422);
            $this->renderWithError(\App\Core\t('Group update failed.'));
            return;
        }

        header('Location: /admin/groups', true, 303);
    }

    private function renderWithError(string $error): void
    {
        $pdo = Database::connection();
        $groups = $pdo->query(
            'SELECT g.id, g.group_key, g.name, g.description, g.status,
                    GROUP_CONCAT(ug.user_id ORDER BY ug.user_id) AS member_ids
             FROM member_groups g LEFT JOIN user_groups ug ON ug.group_id = g.id
             GROUP BY g.id ORDER BY g.name, g.id'
        )->fetchAll();
        foreach ($groups as &$group) {
            $group['member_ids'] = $group['member_ids'] ? array_map('intval', explode(',', $group['member_ids'])) : [];
        }
        unset($group);
        $users = $pdo->query("SELECT id, email, display_name FROM users WHERE status = 'active' ORDER BY email LIMIT 500")->fetchAll();
        defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
        require BASE_PATH . '/app/Views/admin-groups.php';
    }
}