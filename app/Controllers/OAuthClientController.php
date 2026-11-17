<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Database;
use PDO;
use PDOException;
use Throwable;

final class OAuthClientController
{
    private const SCOPES = ['openid', 'profile', 'email', 'groups', 'permissions', 'entitlements'];

    public function index(): void
    {
        Authorization::requirePermission('apps.manage');
        $flash = $_SESSION['oauth_client_once'] ?? null;
        unset($_SESSION['oauth_client_once']);
        $this->render(null, is_array($flash) ? $flash : null);
    }

    private function verifyCreateCsrf(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
    }

    private function hasInvalidClientDetails($applicationId, string $type, array $redirects, $rawScopes): bool
    {
        return $applicationId === false || !in_array($type, ['public', 'confidential'], true)
            || $redirects === [] || !is_array($rawScopes)
            || array_filter($rawScopes, static fn($scope): bool => !is_string($scope)) !== [];
    }

    private function supportedScopes(array $rawScopes): array
    {
        return array_values(array_unique(array_filter($rawScopes, static fn($scope): bool =>
            is_string($scope) && in_array($scope, self::SCOPES, true)
        )));
    }

    public function create(): void
    {
        $this->verifyCreateCsrf();
        $actorId = Authorization::requirePermission('apps.manage');
        $applicationId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $type = is_string($_POST['client_type'] ?? null) ? $_POST['client_type'] : '';
        $redirectText = is_string($_POST['redirect_uris'] ?? null) ? trim($_POST['redirect_uris']) : '';
        $redirects = preg_split('/\R+/', $redirectText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rawScopes = $_POST['scopes'] ?? [];
        if ($this->hasInvalidClientDetails($applicationId, $type, $redirects, $rawScopes)) {
            http_response_code(422);
            $this->render(\App\Core\t('Check the OAuth client details.'), null);
            return;
        }
        $scopes = $this->supportedScopes($rawScopes);
        if ($scopes === []) {
            http_response_code(422);
            $this->render(\App\Core\t('Select one or more supported OAuth scopes.'), null);
            return;
        }
        foreach ($redirects as $uri) {
            if (!$this->validRedirectUri($uri)) {
                http_response_code(422);
                $this->render(\App\Core\t('Every redirect URI must be a valid HTTPS URL without userinfo or fragments.'), null);
                return;
            }
        }
        $redirects = array_values(array_unique($redirects));
        sort($scopes, SORT_STRING);

        $pdo = Database::connection();
        $application = $pdo->prepare("SELECT id, name FROM applications WHERE id = :id AND status = 'active' LIMIT 1");
        $application->execute(['id' => $applicationId]);
        $app = $application->fetch();
        if (!$app) {
            http_response_code(422);
            $this->render(\App\Core\t('Choose an active registered application.'), null);
            return;
        }

        $clientId = bin2hex(random_bytes(32));
        $secret = $type === 'confidential' ? bin2hex(random_bytes(48)) : null;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO oauth_clients
                    (application_id, client_id, client_secret_hash, client_type, redirect_uris, allowed_scopes, status)
                 VALUES (:application, :client, :secret_hash, :type, :redirects, :scopes, \'active\')'
            );
            $stmt->execute([
                'application' => (int)$applicationId,
                'client' => $clientId,
                'secret_hash' => $secret === null ? null : hash('sha256', $secret),
                'type' => $type,
                'redirects' => json_encode($redirects, JSON_THROW_ON_ERROR),
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            ]);
            $id = (int)$pdo->lastInsertId();
            Authorization::audit($actorId, 'oauth_client.created', 'oauth_client', (string)$id, [
                'application_id' => (int)$applicationId,
                'client_type' => $type,
                'redirect_uri_count' => count($redirects),
                'scopes' => $scopes,
            ]);
            $pdo->commit();
            $_SESSION['oauth_client_once'] = [
                'client_id' => $clientId,
                'client_secret' => $secret,
                'application_name' => (string)$app['name'],
            ];
            header('Location: /admin/oauth-clients', true, 303);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                http_response_code(409);
                $this->render(\App\Core\t('This application already has an OAuth client.'), null);
                return;
            }
            error_log('OAuth client creation failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render(\App\Core\t('OAuth client operation failed.'), null);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('OAuth client creation failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render(\App\Core\t('OAuth client operation failed.'), null);
        }
    }

    public function rotateSecret(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $actorId = Authorization::requirePermission('apps.manage');
        $id = filter_var($_POST['client_record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            http_response_code(422);
            $this->render(\App\Core\t('Invalid OAuth client reference.'), null);
            return;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare('SELECT id, client_id, client_type FROM oauth_clients WHERE id = :id FOR UPDATE');
            $find->execute(['id' => $id]);
            $client = $find->fetch();
            if (!$client || $client['client_type'] !== 'confidential') {
                throw new \DomainException('client_not_rotatable');
            }
            $secret = bin2hex(random_bytes(48));
            $pdo->prepare('UPDATE oauth_clients SET client_secret_hash = :hash, updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['hash' => hash('sha256', $secret), 'id' => (int)$id]);
            $this->revokeClientTokens($pdo, (int)$id);
            Authorization::audit($actorId, 'oauth_client.secret_rotated', 'oauth_client', (string)$id);
            $pdo->commit();
            $_SESSION['oauth_client_once'] = [
                'client_id' => (string)$client['client_id'],
                'client_secret' => $secret,
                'application_name' => '',
            ];
            header('Location: /admin/oauth-clients', true, 303);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) {
                http_response_code(404);
                $this->render(\App\Core\t('Confidential OAuth client not found.'), null);
                return;
            }
            error_log('OAuth client secret rotation failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render(\App\Core\t('OAuth client operation failed.'), null);
        }
    }

    public function setStatus(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $actorId = Authorization::requirePermission('apps.manage');
        $id = filter_var($_POST['client_record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($id === false || !in_array($status, ['active', 'disabled'], true)) {
            http_response_code(422);
            $this->render(\App\Core\t('Invalid OAuth client reference.'), null);
            return;
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT oc.id, oc.application_id, a.status AS application_status
                 FROM oauth_clients oc INNER JOIN applications a ON a.id = oc.application_id
                 WHERE oc.id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $client = $stmt->fetch();
            if (!$client || ($status === 'active' && $client['application_status'] !== 'active')) {
                throw new \DomainException('client_not_available');
            }
            $pdo->prepare('UPDATE oauth_clients SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id')
                ->execute(['status' => $status, 'id' => (int)$id]);
            if ($status === 'disabled') $this->revokeClientTokens($pdo, (int)$id);
            Authorization::audit($actorId, 'oauth_client.status_changed', 'oauth_client', (string)$id, ['status' => $status]);
            $pdo->commit();
            header('Location: /admin/oauth-clients', true, 303);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \DomainException) {
                http_response_code(422);
                $this->render(\App\Core\t('The OAuth client or its application is unavailable.'), null);
                return;
            }
            error_log('OAuth client status update failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            $this->render(\App\Core\t('OAuth client operation failed.'), null);
        }
    }

    private function revokeClientTokens(PDO $pdo, int $clientId): void
    {
        $pdo->prepare('UPDATE oauth_access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE client_id = :id AND revoked_at IS NULL')
            ->execute(['id' => $clientId]);
        $pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE client_id = :id AND revoked_at IS NULL')
            ->execute(['id' => $clientId]);
        $pdo->prepare('UPDATE oauth_authorization_codes SET consumed_at = UTC_TIMESTAMP() WHERE client_id = :id AND consumed_at IS NULL')
            ->execute(['id' => $clientId]);
    }

    private function validRedirectUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2048 || preg_match('/[\x00-\x20\x7f]/', $uri)) return false;
        $parts = parse_url($uri);
        return filter_var($uri, FILTER_VALIDATE_URL) !== false
            && is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private function render(?string $error, ?array $flash): void
    {
        $pdo = Database::connection();
        $clients = $pdo->query(
            'SELECT oc.id, oc.client_id, oc.client_type, oc.redirect_uris, oc.allowed_scopes,
                    oc.status, oc.created_at, a.name AS application_name, a.status AS application_status
             FROM oauth_clients oc INNER JOIN applications a ON a.id = oc.application_id
             ORDER BY oc.created_at DESC, oc.id DESC LIMIT 200'
        )->fetchAll();
        $applications = $pdo->query(
            "SELECT a.id, a.name FROM applications a
             LEFT JOIN oauth_clients oc ON oc.application_id = a.id
             WHERE a.status = 'active' AND oc.id IS NULL
             ORDER BY a.name, a.id LIMIT 200"
        )->fetchAll();
        $scopes = self::SCOPES;
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
        require BASE_PATH . '/app/Views/admin-oauth-clients.php';
    }
}
