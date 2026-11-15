<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AuthorizationRevision;
use App\Core\Database;
use App\Core\EntitlementService;
use App\Core\OAuthService;
use PDO;

final class EntitlementController
{
    public function index(): void
    {
        (new OAuthController())->corsForApi();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $token = preg_match('/\ABearer\s+([A-Za-z0-9_-]+)\z/i', trim($authorization), $match) ? $match[1] : '';
        $pdo = Database::connection();
        $identity = $token !== '' ? OAuthService::authenticateAccessToken($pdo, $token) : null;
        if (!$identity) {
            header('WWW-Authenticate: Bearer error="invalid_token"');
            $this->respond(401, ['ok' => false, 'error' => ['code' => 'invalid_token', 'message' => 'A valid access token is required.']]);
            return;
        }
        if (!in_array('entitlements', $identity['scopes'], true)) {
            header('WWW-Authenticate: Bearer error="insufficient_scope", scope="entitlements"');
            $this->respond(403, ['ok' => false, 'error' => ['code' => 'insufficient_scope', 'message' => 'The access token does not include the entitlements scope.']]);
            return;
        }

        $userId = (int)$identity['user_id'];
        $revision = AuthorizationRevision::current($pdo, $userId);
        $entitlements = EntitlementService::forUser($pdo, $userId);
        $this->respond(200, [
            'ok' => true,
            'data' => [
                'entitlements' => $entitlements,
                'authorization_revision' => $revision,
            ],
            'meta' => [
                'timestamp' => gmdate('c'),
            ],
        ]);
    }

    private function respond(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
