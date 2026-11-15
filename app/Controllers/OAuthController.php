<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppConfig;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\OAuthService;
use App\Core\OidcSigner;
use PDO;
use Throwable;

final class OAuthController
{
    public function authorize(): void
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            $this->consent();
            return;
        }
        $pending = $_SESSION['oauth_pending'] ?? null;
        if (!is_array($pending)) {
            $pending = $this->validateAuthorizationRequest($_GET);
            if ($pending === null) return;
            $_SESSION['oauth_pending'] = $pending;
        }
        if ((int)($_SESSION['user_id'] ?? 0) < 1) {
            header('Location: /login', true, 303);
            return;
        }

        $client = $this->clientById((string)($pending['client_id'] ?? ''));
        if (!$client || !$this->pendingStillValid($pending, $client)) {
            unset($_SESSION['oauth_pending']);
            http_response_code(400);
            echo \App\Core\t('The authorization request is invalid or the application is unavailable.');
            return;
        }

        $userId = (int)$_SESSION['user_id'];
        $consent = Database::connection()->prepare(
            'SELECT scopes FROM oauth_consents
             WHERE client_id = :client AND user_id = :user AND revoked_at IS NULL LIMIT 1'
        );
        $consent->execute(['client' => (int)$client['id'], 'user' => $userId]);
        $savedScopes = json_decode((string)$consent->fetchColumn(), true);
        if (is_array($savedScopes) && array_diff($pending['scopes'], $savedScopes) === []) {
            $this->completeAuthorization($pending, $client, $userId);
            return;
        }

        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        $title = htmlspecialchars((string)$client['application_name'], ENT_QUOTES, 'UTF-8');
        $scopeLabels = [
            'openid' => \App\Core\t('Sign you in'),
            'profile' => \App\Core\t('View your basic profile'),
            'email' => \App\Core\t('View your email address'),
            'groups' => \App\Core\t('View your active member groups'),
            'permissions' => \App\Core\t('View your granted permissions'),
        ];
        echo '<!doctype html><html lang="' . htmlspecialchars(\App\Core\Localization::current(), ENT_QUOTES, 'UTF-8') . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . \App\Core\t('Authorize application') . '</title><link rel="stylesheet" href="/assets/css/app.css"><main class="auth-wrap"><section class="auth-card"><span class="eyebrow">c0m.me</span><h1>' . \App\Core\t('Authorize application') . '</h1><p>' . \App\Core\t('The application wants permission to access your account:') . ' <strong>' . $title . '</strong></p><ul>';
        foreach ($pending['scopes'] as $scope) {
            echo '<li>' . htmlspecialchars($scopeLabels[$scope] ?? $scope, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul><form method="post" action="/oauth/authorize"><input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '"><button class="button full" name="decision" value="approve" type="submit">' . \App\Core\t('Allow access') . '</button><button class="button full secondary" name="decision" value="deny" type="submit">' . \App\Core\t('Deny') . '</button></form></section></main></html>';
    }

    public function token(): void
    {
        $this->corsHeaders();
        $this->jsonHeaders();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            $this->jsonError(405, 'invalid_request', 'POST is required.');
            return;
        }
        $client = $this->authenticateClient();
        if (!$client) {
            header('WWW-Authenticate: Basic realm="c0m-account-center"');
            $this->jsonError(401, 'invalid_client', 'Client authentication failed.');
            return;
        }

        $grant = is_string($_POST['grant_type'] ?? null) ? $_POST['grant_type'] : '';
        try {
            if ($grant === 'authorization_code') {
                $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
                $redirect = is_string($_POST['redirect_uri'] ?? null) ? $_POST['redirect_uri'] : '';
                $verifier = is_string($_POST['code_verifier'] ?? null) ? $_POST['code_verifier'] : '';
                $tokens = OAuthService::exchangeAuthorizationCode(Database::connection(), $client, $code, $redirect, $verifier);
                if (!$tokens) {
                    $this->jsonError(400, 'invalid_grant', 'The authorization code is invalid, expired, used or does not match PKCE.');
                    return;
                }
            } elseif ($grant === 'refresh_token') {
                $refresh = is_string($_POST['refresh_token'] ?? null) ? $_POST['refresh_token'] : '';
                $tokens = OAuthService::refreshAccessToken(Database::connection(), $client, $refresh);
                if (!$tokens) {
                    $this->jsonError(400, 'invalid_grant', 'The refresh token is invalid, expired, revoked or already used.');
                    return;
                }
            } else {
                $this->jsonError(400, 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.');
                return;
            }

            if (in_array('openid', $tokens['scopes'], true)) {
                $tokens['id_token'] = $this->idToken($client, $tokens['user_id'], $tokens['scopes'], $tokens['nonce'] ?? null, (int)($tokens['auth_time'] ?? time()));
            }
            unset($tokens['user_id'], $tokens['scopes'], $tokens['nonce'], $tokens['auth_time'], $tokens['refresh_token_id'], $tokens['access_token_id'], $tokens['authorization_code_id']);
            echo json_encode($tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            error_log('OAuth token request failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            $this->jsonError(500, 'server_error', 'The token request could not be completed.');
        }
    }

    public function revoke(): void
    {
        $this->corsHeaders();
        $this->jsonHeaders();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            header('Allow: POST');
            $this->jsonError(405, 'invalid_request', 'POST is required.');
            return;
        }
        $client = $this->authenticateClient();
        if (!$client) {
            header('WWW-Authenticate: Basic realm="c0m-account-center"');
            $this->jsonError(401, 'invalid_client', 'Client authentication failed.');
            return;
        }
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $hint = is_string($_POST['token_type_hint'] ?? null) ? $_POST['token_type_hint'] : '';
        if ($token !== '') {
            try {
                if ($hint === 'refresh_token') {
                    OAuthService::revokeRefreshFamily(Database::connection(), $token, (int)$client['id']);
                } elseif ($hint === 'access_token') {
                    OAuthService::revokeAccessToken(Database::connection(), $token, (int)$client['id']);
                } elseif (!OAuthService::revokeRefreshFamily(Database::connection(), $token, (int)$client['id'])) {
                    OAuthService::revokeAccessToken(Database::connection(), $token, (int)$client['id']);
                }
            } catch (Throwable $e) {
                error_log('OAuth token revocation failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
                $this->jsonError(500, 'server_error', 'Token revocation could not be completed.');
                return;
            }
        }
        // RFC 7009 requires a successful response for unknown/already-revoked tokens.
        http_response_code(200);
        echo '{}';
    }

    public function userinfo(): void
    {
        $this->corsHeaders();
        $this->jsonHeaders();
        $token = $this->bearerToken();
        $row = $token !== '' ? OAuthService::authenticateAccessToken(Database::connection(), $token) : null;
        if (!$row || !in_array('openid', $row['scopes'], true)) {
            header('WWW-Authenticate: Bearer error="invalid_token"');
            $this->jsonError(401, 'invalid_token', 'A valid OpenID access token is required.');
            return;
        }
        $scopes = $row['scopes'];
        $data = ['sub' => (string)$row['user_id']];
        if (in_array('profile', $scopes, true)) {
            $profile = Database::connection()->prepare('SELECT username, avatar_path FROM user_profiles WHERE user_id = :id LIMIT 1');
            $profile->execute(['id' => (int)$row['user_id']]);
            $details = $profile->fetch() ?: [];
            $data['name'] = (string)($row['display_name'] ?? '');
            if (!empty($details['username'])) $data['preferred_username'] = (string)$details['username'];
            if (!empty($details['avatar_path'])) $data['picture'] = (string)$details['avatar_path'];
        }
        if (in_array('email', $scopes, true)) {
            $data['email'] = (string)$row['email'];
            $data['email_verified'] = $row['email_verified_at'] !== null;
        }
        if (in_array('groups', $scopes, true)) {
            $groups = Database::connection()->prepare(
                "SELECT g.group_key FROM user_groups ug INNER JOIN member_groups g ON g.id = ug.group_id
                 WHERE ug.user_id = :id AND g.status = 'active' ORDER BY g.group_key"
            );
            $groups->execute(['id' => (int)$row['user_id']]);
            $data['groups'] = $groups->fetchAll(PDO::FETCH_COLUMN);
        }
        if (in_array('permissions', $scopes, true)) {
            $permissions = Database::connection()->prepare(
                'SELECT DISTINCT p.permission_key FROM user_roles ur
                 INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE ur.user_id = :id ORDER BY p.permission_key'
            );
            $permissions->execute(['id' => (int)$row['user_id']]);
            $data['permissions'] = $permissions->fetchAll(PDO::FETCH_COLUMN);
        }
        $revision = Database::connection()->prepare('SELECT revision FROM user_authorization_revisions WHERE user_id = :id');
        $revision->execute(['id' => (int)$row['user_id']]);
        $data['authorization_revision'] = (int)($revision->fetchColumn() ?: 1);
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function discovery(): void
    {
        $issuer = AppConfig::baseUrl();
        $this->jsonHeaders();
        header('Access-Control-Allow-Origin: *');
        echo json_encode([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/oauth/userinfo',
            'jwks_uri' => $issuer . '/oauth/jwks',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile', 'email', 'groups', 'permissions'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'preferred_username', 'picture', 'email', 'email_verified'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function jwks(): void
    {
        $this->jsonHeaders();
        header('Access-Control-Allow-Origin: *');
        try {
            echo json_encode(OidcSigner::jwks(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            error_log('OIDC JWKS request failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            $this->jsonError(503, 'temporarily_unavailable', 'Signing keys are unavailable.');
        }
    }

    private function consent(): void
    {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            exit(\App\Core\t('Invalid CSRF token'));
        }
        $pending = $_SESSION['oauth_pending'] ?? null;
        if (!is_array($pending) || (int)($_SESSION['user_id'] ?? 0) < 1) {
            unset($_SESSION['oauth_pending']);
            http_response_code(400);
            echo \App\Core\t('The authorization request has expired. Start again from the application.');
            return;
        }
        $client = $this->clientById((string)($pending['client_id'] ?? ''));
        if (!$client || !$this->pendingStillValid($pending, $client)) {
            unset($_SESSION['oauth_pending']);
            http_response_code(400);
            echo \App\Core\t('The authorization request is invalid or the application is unavailable.');
            return;
        }
        $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
        $userId = (int)$_SESSION['user_id'];
        if ($decision === 'deny') {
            unset($_SESSION['oauth_pending']);
            $this->redirectWith($pending['redirect_uri'], ['error' => 'access_denied', 'state' => $pending['state']]);
            return;
        }
        if ($decision !== 'approve') {
            http_response_code(400);
            echo \App\Core\t('Invalid authorization decision.');
            return;
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO oauth_consents (client_id, user_id, scopes, granted_at, revoked_at)
             VALUES (:client, :user, :scopes, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE scopes = VALUES(scopes), granted_at = UTC_TIMESTAMP(), revoked_at = NULL'
        );
        $stmt->execute([
            'client' => (int)$client['id'],
            'user' => $userId,
            'scopes' => json_encode($pending['scopes'], JSON_THROW_ON_ERROR),
        ]);
        $this->completeAuthorization($pending, $client, $userId);
    }

    private function completeAuthorization(array $pending, array $client, int $userId): void
    {
        try {
            $code = OAuthService::createAuthorizationCode(
                Database::connection(),
                (int)$client['id'],
                $userId,
                $pending['redirect_uri'],
                $pending['scopes'],
                $pending['code_challenge'],
                $pending['nonce'],
                (int)($_SESSION['authenticated_at'] ?? time())
            );
            unset($_SESSION['oauth_pending']);
            $params = ['code' => $code, 'state' => $pending['state']];
            $this->redirectWith($pending['redirect_uri'], $params);
        } catch (Throwable $e) {
            error_log('OAuth authorization code creation failed (' . get_class($e) . ', code ' . $e->getCode() . ').');
            http_response_code(500);
            echo \App\Core\t('Authorization could not be completed.');
        }
    }

    private function validateAuthorizationRequest(array $input): ?array
    {
        $clientId = is_string($input['client_id'] ?? null) ? $input['client_id'] : '';
        $redirect = is_string($input['redirect_uri'] ?? null) ? $input['redirect_uri'] : '';
        $responseType = is_string($input['response_type'] ?? null) ? $input['response_type'] : '';
        $challenge = is_string($input['code_challenge'] ?? null) ? $input['code_challenge'] : '';
        $method = is_string($input['code_challenge_method'] ?? null) ? $input['code_challenge_method'] : '';
        $state = is_string($input['state'] ?? null) ? $input['state'] : '';
        $nonce = is_string($input['nonce'] ?? null) ? $input['nonce'] : null;
        $requestedScopes = is_string($input['scope'] ?? null) ? preg_split('/\s+/', trim($input['scope']), -1, PREG_SPLIT_NO_EMPTY) : [];
        $client = $this->clientById($clientId);
        if (!$client || $responseType !== 'code' || $method !== 'S256'
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $challenge) !== 1
            || $state === '' || strlen($state) > 512 || preg_match('/[\x00-\x20\x7f]/', $state)
            || ($nonce !== null && (strlen($nonce) > 255 || preg_match('/[\x00-\x1f\x7f]/', $nonce)))) {
            http_response_code(400);
            echo \App\Core\t('The authorization request is invalid.');
            return null;
        }
        $redirects = json_decode((string)$client['redirect_uris'], true);
        $allowed = json_decode((string)$client['allowed_scopes'], true);
        if (!is_array($redirects) || !OAuthService::validRedirectUri($redirects, $redirect)
            || !is_array($allowed) || $requestedScopes === [] || array_diff($requestedScopes, $allowed) !== []) {
            http_response_code(400);
            echo \App\Core\t('The authorization request is invalid.');
            return null;
        }
        $requestedScopes = array_values(array_unique($requestedScopes));
        sort($requestedScopes, SORT_STRING);
        return [
            'client_id' => $clientId,
            'redirect_uri' => $redirect,
            'scopes' => $requestedScopes,
            'code_challenge' => $challenge,
            'state' => $state,
            'nonce' => $nonce,
        ];
    }

    private function pendingStillValid(array $pending, array $client): bool
    {
        $redirects = json_decode((string)$client['redirect_uris'], true);
        $allowed = json_decode((string)$client['allowed_scopes'], true);
        return $client['status'] === 'active'
            && $client['application_status'] === 'active'
            && is_array($redirects) && OAuthService::validRedirectUri($redirects, (string)($pending['redirect_uri'] ?? ''))
            && is_array($allowed) && array_diff((array)($pending['scopes'] ?? []), $allowed) === [];
    }

    private function clientById(string $clientId): ?array
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $clientId)) return null;
        $stmt = Database::connection()->prepare(
            'SELECT oc.*, a.name AS application_name, a.status AS application_status
             FROM oauth_clients oc INNER JOIN applications a ON a.id = oc.application_id
             WHERE oc.client_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch();
        return is_array($client) ? $client : null;
    }

    private function authenticateClient(): ?array
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $clientId = '';
        $secret = '';
        if (str_starts_with($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if (!is_string($decoded) || !str_contains($decoded, ':')) return null;
            [$clientId, $secret] = explode(':', $decoded, 2);
        } else {
            $clientId = is_string($_POST['client_id'] ?? null) ? $_POST['client_id'] : '';
        }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $clientId) || $this->clientAuthenticationRateLimited($clientId)) {
            return null;
        }
        $client = $this->clientById($clientId);
        if (!$client || $client['status'] !== 'active' || $client['application_status'] !== 'active') {
            $this->recordClientAuthenticationFailure($clientId);
            return null;
        }
        if ($client['client_type'] === 'confidential') {
            if (!OAuthService::verifyClientSecret($client, $secret)) {
                $this->recordClientAuthenticationFailure($clientId);
                return null;
            }
            return $client;
        }
        if ($secret !== '') {
            $this->recordClientAuthenticationFailure($clientId);
            return null;
        }
        return $client;
    }

    private function clientAuthenticationRateLimited(string $clientId): bool
    {
        $pdo = Database::connection();
        $identifier = hash('sha256', $clientId);
        $byClient = $pdo->prepare(
            "SELECT COUNT(*) FROM auth_attempts
             WHERE action = 'oauth_client_auth' AND identifier_hash = :identifier
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)"
        );
        $byClient->execute(['identifier' => $identifier]);
        if ((int)$byClient->fetchColumn() >= 10) return true;

        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($ip === false) return false;
        $packedIp = inet_pton($ip);
        $byIp = $pdo->prepare(
            "SELECT COUNT(*) FROM auth_attempts
             WHERE action = 'oauth_client_auth' AND ip_address = :ip
               AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)"
        );
        $byIp->bindValue(':ip', $packedIp, PDO::PARAM_LOB);
        $byIp->execute();
        return (int)$byIp->fetchColumn() >= 30;
    }

    private function recordClientAuthenticationFailure(string $clientId): void
    {
        $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        $packedIp = $ip !== false ? inet_pton($ip) : null;
        $stmt = Database::connection()->prepare(
            "INSERT INTO auth_attempts (action, ip_address, identifier_hash)
             VALUES ('oauth_client_auth', :ip, :identifier)"
        );
        $stmt->bindValue(':ip', $packedIp, $packedIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':identifier', hash('sha256', $clientId));
        $stmt->execute();
    }

    private function idToken(array $client, int $userId, array $scopes, ?string $nonce, int $authTime): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT email, display_name, email_verified_at, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || $user['status'] !== 'active') throw new \RuntimeException('oidc_user_unavailable');
        $now = time();
        $claims = [
            'iss' => AppConfig::baseUrl(),
            'sub' => (string)$userId,
            'aud' => (string)$client['client_id'],
            'iat' => $now,
            'exp' => $now + 300,
            'auth_time' => $authTime > 0 ? $authTime : $now,
        ];
        if ($nonce !== null && $nonce !== '') $claims['nonce'] = $nonce;
        if (in_array('profile', $scopes, true)) $claims['name'] = (string)($user['display_name'] ?? '');
        if (in_array('email', $scopes, true)) {
            $claims['email'] = (string)$user['email'];
            $claims['email_verified'] = $user['email_verified_at'] !== null;
        }
        return OidcSigner::sign($claims);
    }

    private function bearerToken(): string
    {
        $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/\ABearer\s+([A-Za-z0-9_-]+)\z/i', trim($header), $match) ? $match[1] : '';
    }

    private function redirectWith(string $uri, array $params): void
    {
        $separator = str_contains($uri, '?') ? '&' : '?';
        header('Location: ' . $uri . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986), true, 303);
    }

    public function preflight(): void
    {
        $origin = is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? trim($_SERVER['HTTP_ORIGIN']) : '';
        $method = strtoupper((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? ''));
        $requestedHeaders = strtolower(trim((string)($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')));
        if ($origin === '' || !$this->originIsRegistered($origin)
            || !in_array($method, ['GET', 'POST'], true)) {
            http_response_code(403);
            return;
        }
        if ($requestedHeaders !== '') {
            $requested = array_values(array_filter(array_map('trim', explode(',', $requestedHeaders))));
            if (array_diff($requested, ['authorization', 'content-type']) !== []) {
                http_response_code(403);
                return;
            }
        }
        $this->corsHeaders();
        http_response_code(204);
        header('Access-Control-Max-Age: 600');
    }

    private function corsHeaders(): void
    {
        $origin = is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? trim($_SERVER['HTTP_ORIGIN']) : '';
        if ($origin === '' || !$this->originIsRegistered($origin)) return;
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin', false);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Max-Age: 600');
    }

    private function originIsRegistered(string $origin): bool
    {
        $originParts = parse_url($origin);
        if (!is_array($originParts) || strtolower((string)($originParts['scheme'] ?? '')) !== 'https'
            || empty($originParts['host']) || isset($originParts['user']) || isset($originParts['pass'])
            || isset($originParts['query']) || isset($originParts['fragment'])
            || (isset($originParts['path']) && $originParts['path'] !== '')) {
            return false;
        }
        $normalize = static function (array $parts): string {
            $scheme = strtolower((string)($parts['scheme'] ?? ''));
            $host = strtolower((string)($parts['host'] ?? ''));
            $port = isset($parts['port']) ? (int)$parts['port'] : 443;
            return $scheme . '://' . $host . ($port === 443 ? '' : ':' . $port);
        };
        $candidate = $normalize($originParts);
        $stmt = Database::connection()->query(
            "SELECT oc.redirect_uris FROM oauth_clients oc
             INNER JOIN applications a ON a.id = oc.application_id
             WHERE oc.status = 'active' AND a.status = 'active'"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $redirectJson) {
            $redirects = json_decode((string)$redirectJson, true);
            if (!is_array($redirects)) continue;
            foreach ($redirects as $redirect) {
                if (!is_string($redirect)) continue;
                $parts = parse_url($redirect);
                if (is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
                    && !empty($parts['host']) && $normalize($parts) === $candidate) {
                    return true;
                }
            }
        }
        return false;
    }

    private function jsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    private function jsonError(int $status, string $code, string $message): void
    {
        http_response_code($status);
        echo json_encode(['error' => $code, 'error_description' => $message], JSON_UNESCAPED_SLASHES);
    }
}
