# c0m Account Center PHP SDK

A small dependency-free PHP 8.2+ client for products connected to c0m Account Center.

## Install

Copy the files under `sdk/php/src` into your product or add this package to your Composer path repository. The package requires PHP cURL and JSON extensions.

## Authentication

Your product must complete the OAuth 2.0 Authorization Code + PKCE flow with Account Center and keep access/refresh tokens on its server. Never place a confidential secret, access token or refresh token in browser JavaScript or HTML.

The SDK does not implement OAuth login or refresh-token rotation. It sends the access token to Account Center and fails closed when the API rejects it.

## Usage

```php
use C0m\AccountCenter\AccountCenterClient;
use C0m\AccountCenter\AccountCenterException;

$client = new AccountCenterClient(
    $_ENV['ACCOUNT_CENTER_URL'],
    $_SESSION['account_center_access_token']
);

try {
    $snapshot = $client->authorizationSnapshot();

    if (!$client->hasPermission($snapshot, 'forum.thread.create')) {
        http_response_code(403);
        exit('Forbidden');
    }

    if (!$client->hasFeature($snapshot, 'ai_member')) {
        http_response_code(403);
        exit('Upgrade required');
    }

    $subdomainLimit = $client->quota($snapshot, 'subdomains');
} catch (AccountCenterException $exception) {
    // Do not silently grant access when Account Center is unavailable.
    error_log('Account Center API error: ' . $exception->errorCode());
    http_response_code(503);
    exit('Account authorization is temporarily unavailable.');
}
```

## Required OAuth scopes

| API data | Scope |
|---|---|
| Member ID and authorization revision | `openid` |
| Display name, username, avatar, language and timezone | `profile` |
| Email and verification state | `email` |
| Groups and group memberships | `groups` |
| Effective roles and permission keys | `permissions` |
| Subscription features and quotas | `entitlements` |

A full authorization snapshot requires `openid groups permissions entitlements` plus `profile`/`email` when those profile fields are needed.

## Authorization revisions and caching

`authorizationSnapshot()` reads profile, groups, memberships, permissions and entitlements. It compares the `authorization_revision` returned by every endpoint and retries up to three times if a member's authorization changes during the read. It never returns a mixed snapshot.

Products may cache the snapshot with its revision. Before relying on a cached snapshot, call `me()` and compare its current `authorization_revision`; fetch a new snapshot whenever the revision differs. Keep a short cache lifetime as a fallback. Do not treat client-side UI visibility as an authorization check.

## API methods

- `me()`
- `groups()`
- `permissions()`
- `memberships()`
- `entitlements()`
- `authorizationSnapshot()`
- `hasPermission($snapshot, $permission)`
- `hasGroup($snapshot, $groupKey)`
- `hasFeature($snapshot, $featureKey)`
- `quota($snapshot, $quotaKey)`

The client enforces HTTPS, certificate/hostname verification, bounded timeouts, no redirects and a 1 MiB response cap. Network/API failures throw `AccountCenterException`; connected products must fail closed.
