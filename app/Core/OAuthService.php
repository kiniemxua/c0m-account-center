<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class OAuthService
{
    public const ACCESS_TOKEN_TTL = 900;
    public const REFRESH_TOKEN_TTL = 2592000;
    public const AUTH_CODE_TTL = 300;

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function validVerifier(string $verifier): bool
    {
        return preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier) === 1;
    }

    public static function validRedirectUri(array $registeredUris, string $candidate): bool
    {
        if ($candidate === '' || strlen($candidate) > 2048 || preg_match('/[\x00-\x20\x7f]/', $candidate)) {
            return false;
        }
        // OAuth redirect URI matching is exact. Never normalize host, path,
        // query or trailing slash before comparing registered values.
        foreach ($registeredUris as $uri) {
            if (is_string($uri) && hash_equals($uri, $candidate)) {
                return true;
            }
        }
        return false;
    }

    public static function verifyClientSecret(array $client, string $secret): bool
    {
        if (($client['client_type'] ?? '') !== 'confidential' || !is_string($client['client_secret_hash'] ?? null)) {
            return false;
        }
        return $secret !== '' && hash_equals($client['client_secret_hash'], hash('sha256', $secret));
    }

    public static function createAuthorizationCode(
        PDO $pdo,
        int $clientId,
        int $userId,
        string $redirectUri,
        array $scopes,
        string $challenge,
        ?string $nonce = null
    ): string {
        if ($clientId < 1 || $userId < 1 || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $challenge) !== 1) {
            throw new \InvalidArgumentException('invalid_authorization_request');
        }
        $scopes = self::normalizeScopes($scopes);
        $code = self::base64UrlEncode(random_bytes(32));
        $stmt = $pdo->prepare(
            'INSERT INTO oauth_authorization_codes
                (client_id, user_id, code_hash, redirect_uri, scopes, code_challenge, nonce, expires_at)
             VALUES (:client, :user, :hash, :redirect, :scopes, :challenge, :nonce, :expires)'
        );
        $stmt->execute([
            'client' => $clientId,
            'user' => $userId,
            'hash' => hash('sha256', $code),
            'redirect' => $redirectUri,
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'challenge' => $challenge,
            'nonce' => $nonce,
            'expires' => gmdate('Y-m-d H:i:s', time() + self::AUTH_CODE_TTL),
        ]);
        return $code;
    }

    /**
     * Redeem a one-time code. The client must already have been authenticated
     * by the token endpoint. A conditional consume prevents concurrent replay.
     */
    public static function exchangeAuthorizationCode(
        PDO $pdo,
        array $client,
        string $code,
        string $redirectUri,
        string $verifier
    ): ?array {
        if ($code === '' || strlen($code) > 128 || !self::validVerifier($verifier)) {
            return null;
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT c.*, u.status AS user_status, oc.client_id AS public_client_id, oc.status AS client_status
                 FROM oauth_authorization_codes c
                 INNER JOIN users u ON u.id = c.user_id
                 INNER JOIN oauth_clients oc ON oc.id = c.client_id
                 WHERE c.code_hash = :hash AND c.client_id = :client_id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => hash('sha256', $code), 'client_id' => (int)$client['id']]);
            $row = $stmt->fetch();
            if (!$row || $row['consumed_at'] !== null || strtotime((string)$row['expires_at'] . ' UTC') <= time()
                || $row['user_status'] !== 'active' || $row['client_status'] !== 'active'
                || !hash_equals((string)$row['redirect_uri'], $redirectUri)) {
                $pdo->rollBack();
                return null;
            }
            $expected = self::base64UrlEncode(hash('sha256', $verifier, true));
            if (!hash_equals((string)$row['code_challenge'], $expected)) {
                $pdo->rollBack();
                return null;
            }
            $consume = $pdo->prepare(
                'UPDATE oauth_authorization_codes SET consumed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()'
            );
            $consume->execute(['id' => (int)$row['id']]);
            if ($consume->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $scopes = self::decodeScopes($row['scopes']);
            $tokens = self::issueTokenPair($pdo, (int)$client['id'], (int)$row['user_id'], $scopes);
            $pdo->commit();
            return $tokens + [
                'user_id' => (int)$row['user_id'],
                'scopes' => $scopes,
                'nonce' => $row['nonce'] === null ? null : (string)$row['nonce'],
                'authorization_code_id' => (int)$row['id'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function refreshAccessToken(PDO $pdo, array $client, string $refreshToken): ?array
    {
        if ($refreshToken === '' || strlen($refreshToken) > 128) return null;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT rt.*, u.status AS user_status, oc.status AS client_status
                 FROM oauth_refresh_tokens rt
                 INNER JOIN users u ON u.id = rt.user_id
                 INNER JOIN oauth_clients oc ON oc.id = rt.client_id
                 WHERE rt.token_hash = :hash AND rt.client_id = :client_id
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => hash('sha256', $refreshToken), 'client_id' => (int)$client['id']]);
            $row = $stmt->fetch();
            if (!$row) {
                $pdo->rollBack();
                return null;
            }
            if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
                // Reuse of a rotated token is evidence of token theft. Revoke
                // every remaining refresh token in its family before returning.
                $pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = :family AND revoked_at IS NULL')
                    ->execute(['family' => $row['family_id']]);
                $pdo->prepare('UPDATE oauth_access_tokens SET revoked_at = UTC_TIMESTAMP() WHERE client_id = :client AND user_id = :user AND revoked_at IS NULL')
                    ->execute(['client' => (int)$client['id'], 'user' => (int)$row['user_id']]);
                $pdo->commit();
                return null;
            }
            if (strtotime((string)$row['expires_at'] . ' UTC') <= time()
                || $row['user_status'] !== 'active' || $row['client_status'] !== 'active') {
                $pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = :id')
                    ->execute(['id' => (int)$row['id']]);
                $pdo->commit();
                return null;
            }
            $scopes = self::decodeScopes($row['scopes']);
            $pair = self::issueTokenPair($pdo, (int)$client['id'], (int)$row['user_id'], $scopes, (string)$row['family_id']);
            $update = $pdo->prepare(
                'UPDATE oauth_refresh_tokens SET used_at = UTC_TIMESTAMP(), replaced_by_id = :replacement
                 WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL'
            );
            $update->execute(['replacement' => $pair['refresh_token_id'], 'id' => (int)$row['id']]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }
            $pdo->commit();
            return $pair + ['user_id' => (int)$row['user_id'], 'scopes' => $scopes];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function authenticateAccessToken(PDO $pdo, string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) return null;
        $stmt = $pdo->prepare(
            'SELECT at.id AS token_id, at.user_id, at.client_id, at.scopes, at.expires_at,
                    u.email, u.display_name, u.status AS user_status, u.email_verified_at,
                    oc.client_id AS oauth_client_id, oc.status AS client_status
             FROM oauth_access_tokens at
             INNER JOIN users u ON u.id = at.user_id
             INNER JOIN oauth_clients oc ON oc.id = at.client_id
             WHERE at.token_hash = :hash AND at.revoked_at IS NULL
               AND at.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        if (!$row || $row['user_status'] !== 'active' || $row['client_status'] !== 'active') return null;
        $row['scopes'] = self::decodeScopes($row['scopes']);
        return $row;
    }

    public static function revokeRefreshFamily(PDO $pdo, string $refreshToken, int $clientId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT id, family_id FROM oauth_refresh_tokens
             WHERE token_hash = :hash AND client_id = :client LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $refreshToken), 'client' => $clientId]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE oauth_refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE family_id = :family AND revoked_at IS NULL')
                ->execute(['family' => $row['family_id']]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function issueTokenPair(PDO $pdo, int $clientId, int $userId, array $scopes, ?string $familyId = null): array
    {
        $access = self::base64UrlEncode(random_bytes(32));
        $refresh = self::base64UrlEncode(random_bytes(48));
        $familyId ??= sprintf('%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $accessExpires = time() + self::ACCESS_TOKEN_TTL;
        $refreshExpires = time() + self::REFRESH_TOKEN_TTL;

        $accessStmt = $pdo->prepare(
            'INSERT INTO oauth_access_tokens (client_id, user_id, token_hash, scopes, expires_at)
             VALUES (:client, :user, :hash, :scopes, :expires)'
        );
        $accessStmt->execute([
            'client' => $clientId, 'user' => $userId, 'hash' => hash('sha256', $access),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'expires' => gmdate('Y-m-d H:i:s', $accessExpires),
        ]);
        $accessId = (int)$pdo->lastInsertId();

        $refreshStmt = $pdo->prepare(
            'INSERT INTO oauth_refresh_tokens (family_id, client_id, user_id, token_hash, scopes, expires_at)
             VALUES (:family, :client, :user, :hash, :scopes, :expires)'
        );
        $refreshStmt->execute([
            'family' => $familyId, 'client' => $clientId, 'user' => $userId,
            'hash' => hash('sha256', $refresh),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'expires' => gmdate('Y-m-d H:i:s', $refreshExpires),
        ]);
        return [
            'token_type' => 'Bearer',
            'access_token' => $access,
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refresh,
            'refresh_token_id' => (int)$pdo->lastInsertId(),
            'access_token_id' => $accessId,
        ];
    }

    private static function normalizeScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter($scopes, static fn($scope): bool =>
            is_string($scope) && preg_match('/\A[a-z][a-z0-9:_-]{0,63}\z/D', $scope) === 1
        )));
        sort($scopes, SORT_STRING);
        return $scopes;
    }

    private static function decodeScopes(mixed $json): array
    {
        $scopes = is_string($json) ? json_decode($json, true) : null;
        return is_array($scopes) ? self::normalizeScopes($scopes) : [];
    }
}
