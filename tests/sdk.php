<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/sdk/php/src/AccountCenterException.php';
require_once dirname(__DIR__) . '/sdk/php/src/AccountCenterClient.php';

use C0m\AccountCenter\AccountCenterClient;
use C0m\AccountCenter\AccountCenterException;

function sdkCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$token = str_repeat('a', 64);
$revisionSequence = [1, 2, 2, 2, 2, 2, 2, 2, 2, 2];
$calls = [];
$transport = static function (string $path, string $accessToken) use (&$calls, $revisionSequence, $token): array {
    $calls[] = [$path, $accessToken];
    $revision = $revisionSequence[count($calls) - 1] ?? 2;
    $data = match ($path) {
        '/api/v1/me' => [
            'id' => '42',
            'display_name' => 'SDK test member',
            'authorization_revision' => $revision,
        ],
        '/api/v1/me/groups' => [
            'groups' => [['group_key' => 'pro', 'name' => 'Pro']],
            'authorization_revision' => $revision,
        ],
        '/api/v1/me/permissions' => [
            'roles' => ['member'],
            'permissions' => ['forum.thread.create'],
            'authorization_revision' => $revision,
        ],
        '/api/v1/me/memberships' => [
            'memberships' => [['group_key' => 'pro', 'status' => 'active']],
            'authorization_revision' => $revision,
        ],
        '/api/v1/me/entitlements' => [
            'entitlements' => [
                'feature.ai_member' => ['value' => true],
                'quota.subdomains' => ['value' => 15],
            ],
            'authorization_revision' => $revision,
        ],
        default => throw new RuntimeException('Unexpected SDK path: ' . $path),
    };
    return ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR)];
};

$client = new AccountCenterClient('https://accounts.example.test', $token, 5, $transport);
$snapshot = $client->authorizationSnapshot();
sdkCheck($snapshot['authorization_revision'] === 2, 'The SDK must return a coherent authorization revision.');
sdkCheck(count($calls) === 10, 'The SDK must retry a mixed-revision snapshot exactly once.');
sdkCheck($client->hasPermission($snapshot, 'forum.thread.create'), 'The SDK must evaluate effective permissions.');
sdkCheck($client->hasGroup($snapshot, 'pro'), 'The SDK must evaluate active groups.');
sdkCheck($client->hasFeature($snapshot, 'ai_member'), 'The SDK must evaluate boolean feature entitlements.');
sdkCheck($client->quota($snapshot, 'subdomains') === 15, 'The SDK must return integer quotas.');
sdkCheck($calls[0][1] === $token, 'The SDK must pass the server-side bearer token to the transport.');

$invalidBaseUrlRejected = false;
try {
    new AccountCenterClient('http://accounts.example.test', $token, 5, $transport);
} catch (AccountCenterException $exception) {
    $invalidBaseUrlRejected = $exception->errorCode() === 'invalid_base_url';
}
sdkCheck($invalidBaseUrlRejected, 'The SDK must reject non-HTTPS Account Center URLs.');

$invalidTokenRejected = false;
try {
    new AccountCenterClient('https://accounts.example.test', 'short', 5, $transport);
} catch (AccountCenterException $exception) {
    $invalidTokenRejected = $exception->errorCode() === 'invalid_access_token';
}
sdkCheck($invalidTokenRejected, 'The SDK must reject malformed access tokens.');

$forbiddenClient = new AccountCenterClient(
    'https://accounts.example.test',
    $token,
    5,
    static fn(string $path, string $accessToken): array => [
        'status' => 403,
        'body' => json_encode([
            'ok' => false,
            'error' => ['code' => 'insufficient_scope', 'message' => 'Required scope missing.'],
        ], JSON_THROW_ON_ERROR),
    ]
);
$scopeErrorCaught = false;
try {
    $forbiddenClient->groups();
} catch (AccountCenterException $exception) {
    $scopeErrorCaught = $exception->httpStatus() === 403 && $exception->errorCode() === 'insufficient_scope';
}
sdkCheck($scopeErrorCaught, 'The SDK must preserve Account Center HTTP status and machine error codes.');

$mismatchRevision = 0;
$mismatchClient = new AccountCenterClient(
    'https://accounts.example.test',
    $token,
    5,
    static function (string $path, string $accessToken) use (&$mismatchRevision): array {
        $mismatchRevision = ($mismatchRevision ?? 0) + 1;
        $data = ['authorization_revision' => $mismatchRevision];
        if ($path === '/api/v1/me/groups') $data['groups'] = [];
        if ($path === '/api/v1/me/permissions') $data += ['roles' => [], 'permissions' => []];
        if ($path === '/api/v1/me/memberships') $data['memberships'] = [];
        if ($path === '/api/v1/me/entitlements') $data['entitlements'] = [];
        if ($path === '/api/v1/me') $data['id'] = '42';
        return ['status' => 200, 'body' => json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR)];
    }
);
$mismatchRejected = false;
try {
    $mismatchClient->authorizationSnapshot(1);
} catch (AccountCenterException $exception) {
    $mismatchRejected = $exception->httpStatus() === 409
        && $exception->errorCode() === 'authorization_changed_during_fetch';
}
sdkCheck($mismatchRejected, 'The SDK must fail closed when authorization changes during a snapshot.');

echo "Account Center PHP SDK tests passed.\n";
