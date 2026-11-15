<?php
declare(strict_types=1);

namespace C0m\AccountCenter;

use Throwable;

final class AccountCenterClient
{
    private string $baseUrl;

    /** @var callable|null */
    private $transport;

    public function __construct(
        string $baseUrl,
        private readonly string $accessToken,
        private readonly int $timeoutSeconds = 5,
        ?callable $transport = null
    ) {
        $parts = parse_url($baseUrl);
        if (strlen($baseUrl) > 500 || filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AccountCenterException('Account Center base URL must be a valid HTTPS URL.', 'invalid_base_url');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{16,4096}\z/D', $accessToken) !== 1) {
            throw new AccountCenterException('A valid opaque access token is required.', 'invalid_access_token');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new AccountCenterException('Timeout must be between 1 and 30 seconds.', 'invalid_timeout');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
    }

    public function me(): array
    {
        return $this->get('/api/v1/me');
    }

    public function groups(): array
    {
        return $this->get('/api/v1/me/groups');
    }

    public function permissions(): array
    {
        return $this->get('/api/v1/me/permissions');
    }

    public function memberships(): array
    {
        return $this->get('/api/v1/me/memberships');
    }

    public function entitlements(): array
    {
        return $this->get('/api/v1/me/entitlements');
    }

    /**
     * Fetch a coherent authorization snapshot. If permissions change while
     * the five endpoints are being read, retry rather than returning a mixed
     * snapshot with different authorization revisions.
     */
    public function authorizationSnapshot(int $maxAttempts = 3): array
    {
        if ($maxAttempts < 1 || $maxAttempts > 5) {
            throw new AccountCenterException('Snapshot attempts must be between 1 and 5.', 'invalid_attempt_count');
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $me = $this->me();
            $groups = $this->groups();
            $permissions = $this->permissions();
            $memberships = $this->memberships();
            $entitlements = $this->entitlements();

            $revisions = [
                $me['authorization_revision'] ?? null,
                $groups['authorization_revision'] ?? null,
                $permissions['authorization_revision'] ?? null,
                $memberships['authorization_revision'] ?? null,
                $entitlements['authorization_revision'] ?? null,
            ];
            $normalized = array_map(
                static fn(mixed $revision): ?int => is_int($revision) || (is_string($revision) && ctype_digit($revision))
                    ? (int)$revision : null,
                $revisions
            );
            if ($normalized[0] !== null && count(array_unique($normalized, SORT_REGULAR)) === 1) {
                return [
                    'authorization_revision' => $normalized[0],
                    'member' => $me,
                    'groups' => is_array($groups['groups'] ?? null) ? $groups['groups'] : [],
                    'memberships' => is_array($memberships['memberships'] ?? null) ? $memberships['memberships'] : [],
                    'roles' => is_array($permissions['roles'] ?? null) ? $permissions['roles'] : [],
                    'permissions' => is_array($permissions['permissions'] ?? null) ? $permissions['permissions'] : [],
                    'entitlements' => is_array($entitlements['entitlements'] ?? null) ? $entitlements['entitlements'] : [],
                ];
            }
        }

        throw new AccountCenterException(
            'Authorization changed while the snapshot was being fetched. Retry the request.',
            'authorization_changed_during_fetch',
            409
        );
    }

    public function hasPermission(array $snapshot, string $permission): bool
    {
        return $permission !== '' && in_array($permission, $snapshot['permissions'] ?? [], true);
    }

    public function hasGroup(array $snapshot, string $groupKey): bool
    {
        foreach (($snapshot['groups'] ?? []) as $group) {
            if (is_array($group) && ($group['group_key'] ?? null) === $groupKey) return true;
        }
        return false;
    }

    public function hasFeature(array $snapshot, string $featureKey): bool
    {
        $value = $snapshot['entitlements']['feature.' . $featureKey]['value'] ?? false;
        return $value === true;
    }

    public function quota(array $snapshot, string $quotaKey): ?int
    {
        $value = $snapshot['entitlements']['quota.' . $quotaKey]['value'] ?? null;
        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function get(string $path): array
    {
        $result = $this->transport !== null
            ? ($this->transport)($path, $this->accessToken)
            : $this->curlGet($path);

        if (!is_array($result) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
            throw new AccountCenterException('The Account Center transport returned an invalid response.', 'invalid_transport_response');
        }

        try {
            $payload = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new AccountCenterException('Account Center returned an invalid JSON response.', 'invalid_json_response', $result['status']);
        }

        if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'api_request_failed';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'The Account Center request was rejected.';
            throw new AccountCenterException($message, $code, $result['status']);
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new AccountCenterException('Account Center returned an unexpected HTTP status.', 'unexpected_http_status', $result['status']);
        }
        return $payload['data'];
    }

    private function curlGet(string $path): array
    {
        if (!extension_loaded('curl')) {
            throw new AccountCenterException('The PHP cURL extension is required.', 'curl_extension_required');
        }
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new AccountCenterException('Could not initialize the Account Center request.', 'transport_initialization_failed');
        }

        try {
            $body = '';
            $configured = curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->accessToken,
                ],
                CURLOPT_CONNECTTIMEOUT => min(5, $this->timeoutSeconds),
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 1048576) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured) {
                throw new AccountCenterException('Could not configure the Account Center request.', 'transport_configuration_failed');
            }
            if (curl_exec($handle) === false) {
                throw new AccountCenterException('Could not reach Account Center.', 'transport_error');
            }
            return ['status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }
}
