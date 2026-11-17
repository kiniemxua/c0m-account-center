<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use PDO;
use PDOException;
use Throwable;

final class ApplicationRegistryController
{
    public function index(): void
    {
        Authorization::requirePermission('apps.manage');
        $form = $this->emptyForm();
        $editValue = $_GET['edit'] ?? null;
        if ($editValue !== null) {
            $editId = filter_var($editValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($editId === false) {
                http_response_code(400);
                exit(\App\Core\t('Invalid application reference.'));
            }
            $stmt = Database::connection()->prepare('SELECT * FROM applications WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $editId]);
            $application = $stmt->fetch();
            if (!$application) {
                http_response_code(404);
                exit(\App\Core\t('Application not found.'));
            }
            $form = $this->formFromRow($application);
            $form['group_ids'] = \App\Core\GroupManager::applicationGroupIds(Database::connection(), (int)$editId);
        }
        $this->render($form, null);
    }

    public function save(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $actorId = Authorization::requirePermission('apps.manage');
        $form = $this->formFromPost($_POST);
        $error = $this->validationError($form);
        if ($error !== null) {
            http_response_code(422);
            $this->render($form, $error);
            return;
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if ($form['id'] === 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO applications
                        (app_key, name, description, url, icon_path, category, version, status, featured, access_level)
                     VALUES
                        (:app_key, :name, :description, :url, :icon_path, :category, :version, :status, :featured, :access_level)'
                );
                $stmt->execute($this->databaseValues($form));
                $applicationId = (int)$pdo->lastInsertId();
                $action = 'application.created';
            } else {
                $existing = $pdo->prepare('SELECT app_key FROM applications WHERE id = :id FOR UPDATE');
                $existing->execute(['id' => $form['id']]);
                if ($existing->fetchColumn() === false) {
                    throw new \DomainException('application_not_found');
                }
                $stmt = $pdo->prepare(
                    'UPDATE applications
                     SET app_key = :app_key, name = :name, description = :description, url = :url,
                         icon_path = :icon_path, category = :category, version = :version,
                         status = :status, featured = :featured, access_level = :access_level,
                         updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute($this->databaseValues($form) + ['id' => $form['id']]);
                $applicationId = $form['id'];
                $action = 'application.updated';
            }
            \App\Core\GroupManager::replaceApplicationGroups(
                $pdo,
                $applicationId,
                $form['access_level'] === 'restricted' ? $form['group_ids'] : []
            );
            Authorization::audit($actorId, $action, 'application', (string)$applicationId, [
                'app_key' => $form['app_key'],
                'status' => $form['status'],
                'access_level' => $form['access_level'],
                'group_ids' => $form['access_level'] === 'restricted' ? $form['group_ids'] : [],
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \DomainException && $e->getMessage() === 'application_not_found') {
                http_response_code(404);
                $this->render($form, \App\Core\t('Application not found.'));
                return;
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                http_response_code(409);
                $this->render($form, \App\Core\t('Application key already exists.'));
                return;
            }
            if ($e instanceof \RuntimeException && in_array($e->getMessage(), ['invalid_group_id', 'inactive_or_missing_group'], true)) {
                http_response_code(422);
                $this->render($form, \App\Core\t('Select active member groups for this application.'));
                return;
            }
            error_log('Application registry save failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render($form, \App\Core\t('Application save failed.'));
            return;
        }

        header('Location: /admin/applications', true, 303);
    }

    private function render(array $form, ?string $error): void
    {
        $apps = Database::connection()->query(
            'SELECT id, app_key, name, description, url, category, version, status, featured, access_level, updated_at
             FROM applications ORDER BY updated_at DESC, id DESC LIMIT 200'
        )->fetchAll();
        $groups = Database::connection()->query("SELECT id, group_key, name FROM member_groups WHERE status = 'active' ORDER BY name, id")->fetchAll();
        require __DIR__ . '/../Views/admin-applications.php';
    }

    private function emptyForm(): array
    {
        return [
            'id' => 0,
            'app_key' => '',
            'name' => '',
            'description' => '',
            'url' => '',
            'icon_path' => '',
            'category' => 'Other',
            'version' => '',
            'status' => 'disabled',
            'featured' => '0',
            'access_level' => 'members',
            'group_ids' => [],
        ];
    }

    private function formFromPost(array $source): array
    {
        $form = $this->emptyForm();
        $id = $source['id'] ?? '';
        if (is_string($id) && $id !== '' && ctype_digit($id)) {
            $form['id'] = (int)$id;
        } elseif (is_int($id) && $id > 0) {
            $form['id'] = $id;
        } elseif ($id !== '' && $id !== null) {
            $form['id'] = -1;
        }
        foreach (['app_key', 'name', 'description', 'url', 'icon_path', 'category', 'version', 'status', 'access_level'] as $field) {
            $value = $source[$field] ?? '';
            $form[$field] = is_string($value) ? trim($value) : '';
        }
        $form['featured'] = ($source['featured'] ?? '') === '1' ? '1' : '0';
        $groupIds = $source['group_ids'] ?? [];
        if (is_array($groupIds)) {
            foreach ($groupIds as $groupId) {
                if (is_string($groupId) && ctype_digit($groupId) && (int)$groupId > 0) {
                    $form['group_ids'][] = (int)$groupId;
                } else {
                    $form['group_ids'][] = -1;
                }
            }
        } elseif ($groupIds !== '') {
            $form['group_ids'][] = -1;
        }
        $form['group_ids'] = array_values(array_unique($form['group_ids']));
        return $form;
    }

    private function formFromRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'app_key' => (string)$row['app_key'],
            'name' => (string)$row['name'],
            'description' => (string)($row['description'] ?? ''),
            'url' => (string)$row['url'],
            'icon_path' => (string)($row['icon_path'] ?? ''),
            'category' => (string)$row['category'],
            'version' => (string)($row['version'] ?? ''),
            'status' => (string)$row['status'],
            'featured' => (int)$row['featured'] === 1 ? '1' : '0',
            'access_level' => (string)$row['access_level'],
            'group_ids' => [],
        ];
    }

    private function validationError(array $form): ?string
    {
        if ($form['access_level'] === 'restricted' && $form['group_ids'] === []) {
            return \App\Core\t('Select active member groups for this application.');
        }
        if ($form['id'] < 0
            || !preg_match('/\A[a-z0-9][a-z0-9._-]{0,79}\z/D', $form['app_key'])
            || $form['name'] === '' || mb_strlen($form['name']) > 120
            || mb_strlen($form['description']) > 10000
            || mb_strlen($form['url']) > 500
            || !$this->isHttpsUrl($form['url'])
            || mb_strlen($form['icon_path']) > 255
            || mb_strlen($form['category']) > 80
            || mb_strlen($form['version']) > 40
            || !$this->validIconPath($form['icon_path'])
            || !in_array($form['status'], ['active', 'maintenance', 'disabled'], true)
            || !in_array($form['access_level'], ['public', 'members', 'restricted'], true)
            || in_array(-1, $form['group_ids'], true)) {
            return \App\Core\t('Check the application details and use a valid HTTPS URL.');
        }
        return null;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return strlen($url) <= 500
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }

    private function validIconPath(string $iconPath): bool
    {
        if ($iconPath === '') {
            return true;
        }
        if (str_starts_with($iconPath, '/') && !str_starts_with($iconPath, '//')) {
            return !str_contains($iconPath, '\\') && !preg_match('/[\x00-\x20\x7f]/', $iconPath);
        }
        return $this->isHttpsUrl($iconPath);
    }

    private function databaseValues(array $form): array
    {
        return [
            'app_key' => $form['app_key'],
            'name' => $form['name'],
            'description' => $form['description'] === '' ? null : $form['description'],
            'url' => $form['url'],
            'icon_path' => $form['icon_path'] === '' ? null : $form['icon_path'],
            'category' => $form['category'] === '' ? 'Other' : $form['category'],
            'version' => $form['version'] === '' ? null : $form['version'],
            'status' => $form['status'],
            'featured' => $form['featured'] === '1' ? 1 : 0,
            'access_level' => $form['access_level'],
        ];
    }
}