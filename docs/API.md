# API and integration contract

## Current state

The web surface remains server-rendered PHP for browser account and administration pages. External applications now have an OAuth 2.0 / OpenID Connect provider using Authorization Code + PKCE (S256), plus scoped OIDC UserInfo. Subscription plans, member entitlements, signed payment webhook ingestion, normalized payment transaction lifecycle and transactional outbox delivery are implemented. Provider-specific checkout/recurring-billing adapters, partial refunds and reconciliation remain deployment work.

See [OAUTH.md](OAUTH.md) for the complete authorization flow, provisioning commands, scopes and operational limitations.

## OAuth / OIDC endpoints

| Endpoint | Methods | Authentication |
|---|---|---|
| `/.well-known/openid-configuration` | GET | Public |
| `/oauth/jwks` | GET | Public |
| `/oauth/authorize` | GET, POST | Account Center session + CSRF for consent |
| `/oauth/token` | POST | Confidential client Basic auth or public client ID + PKCE |
| `/oauth/userinfo` | GET, POST | Bearer access token |
| `/oauth/revoke` | POST | Client authentication |

The authorization endpoint requires:
- `response_type=code`
- `state` with a non-empty random value
- exact-match registered HTTPS `redirect_uri`
- `code_challenge_method=S256` and a valid challenge
- requested scopes contained in the client's allowed scope list

Authorization codes are stored as hashes, expire after five minutes and are single-use. Access tokens are opaque, stored as hashes and expire after 15 minutes. Refresh tokens expire after 30 days and rotate on use. Reuse of a rotated refresh token revokes the token family and its access tokens. Revocation follows RFC 7009 response semantics for unknown tokens.

OIDC ID tokens use RS256 with an RSA key of at least 2048 bits. The public key is published at `/oauth/jwks`. The issuer is the configured HTTPS `base_url`.

## UserInfo scopes

| Scope | Claims/data |
|---|---|
| `openid` | `sub` |
| `profile` | `name`, `preferred_username`, `picture` when present |
| `email` | `email`, `email_verified` |
| `groups` | active member group keys |
| `permissions` | effective role-derived permission keys |

UserInfo also returns `authorization_revision`. Downstream applications should retain the revision alongside cached authorization data and refresh when it changes. A revision is a cache invalidation hint, not a replacement for server-side authorization checks.

CORS is limited to origins derived from active clients' registered HTTPS redirect URIs. Token, revoke and UserInfo endpoints support preflight requests for Authorization and Content-Type. Discovery and JWKS are public read-only resources.

## Application registry

`GET /admin/applications` and `POST /admin/applications/save` require `apps.manage`, currently granted to Super Admin. Writes validate HTTPS launch URLs, use prepared statements and commit the registry mutation with its audit event in one transaction. Duplicate application keys return a conflict. Disabling an application preserves its registry record.

The authenticated dashboard lists active `public` and `members` entries, and lists `restricted` entries only when the user belongs to an active group explicitly granted to that application. These grants control Account Center directory visibility only; external applications must authenticate users and authorize requests independently.

Super Admins can provision, rotate and disable OAuth clients in the browser at `/admin/oauth-clients`; the CLI script `scripts/create-oauth-client.php` remains available for automation and recovery. One OAuth client is allowed per application registry entry. Confidential client secrets are displayed once and only their SHA-256 hashes are stored. See [OAUTH.md](OAUTH.md).

## Current browser account routes

- `/login`, `/register`, `/logout`
- `/verify-email`, `/resend-verification`
- `/forgot-password`, `/reset-password`
- `/dashboard`, `/account`
- `/sessions`, `/sessions/revoke`, `/sessions/revoke-others`
- `/login-history`
- `/admin/users`, `/admin/settings`, `/admin/audit`
- `/admin/applications`, `/admin/applications/save`
- `/admin/groups`, `/admin/plans`, `/admin/plans/save`
- `/language`

Browser mutations require session-bound CSRF tokens. The API bearer-token endpoints do not use browser cookies as identity.

## Versioned member API

All member endpoints below require an OAuth bearer access token. Tokens are checked against the active user, client, expiry and revocation state on every request. Responses use `Cache-Control: no-store`.

| Endpoint | Required scope | Purpose |
|---|---|---|
| `GET /api/v1/me` | `openid` | Stable member ID and authorization revision; `profile` adds display name, username, avatar, language and timezone; `email` adds email and verification state. |
| `GET /api/v1/me/groups` | `groups` | Active group keys and names. |
| `GET /api/v1/me/memberships` | `groups` | Group assignments, including disabled-group state and assignment time. |
| `GET /api/v1/me/permissions` | `permissions` | Effective role keys and permission keys. |
| `GET /api/v1/me/entitlements` | `entitlements` | Active subscription features, quotas and their validity/revision metadata. |

Every versioned member endpoint returns a consistent response envelope:

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-11-15T00:00:00Z"
  }
}
```

Errors use stable machine-readable codes (`invalid_token`, `insufficient_scope`) and do not expose SQL, secrets, token material or stack traces. `meta.request_id` is a random per-response identifier; `meta.timestamp` is UTC. Authorization revisions must be retained with cached member authorization data and re-fetched whenever the revision changes.

## Integration principles

- Account Center is the source of truth for identity, groups, roles, permissions and subscription entitlements.
- An API key identifies an application only; it must never identify a member.
- Keep user identity distinct from application identity.
- Validate token issuer, audience, signature, expiry, nonce and state at the connected application.
- Treat external products as consumers, not authorities.
- Use authorization revisions to invalidate stale downstream caches.
- Verify payment webhook signatures and idempotency before changing entitlements.
- Write payment state and outbox events in one database transaction.
- Never treat dashboard visibility or client-side UI state as authorization.

## Payment webhook contract

The payment webhook endpoint is **POST /api/v1/webhooks/payments**. Configure a provider-specific secret under **providers.<provider>.webhook_secret** in the private **config/payments.php** file (copy **config/payments.example.php** first). The request must include:

- **X-C0M-Provider**: a configured provider key containing lowercase letters, digits, underscores or hyphens.
- **X-C0M-Signature**: **t=<unix-seconds>,v1=<hex-hmac-sha256>**. The HMAC input is **<timestamp>.<raw-request-body>** and the signature timestamp must be within five minutes of receipt.
- A JSON envelope with a unique **id**, **type**, provider event creation time in **created_at** (ISO-8601) or **created** (Unix seconds), and an object-valued **data**.

Payment lifecycle event types are **payment.succeeded**, **payment.failed** and **payment.refunded**. Their **data** must contain:

- **user_id**: an existing active Account Center member.
- **payment_id**: the stable provider payment/charge ID, reused for every event about that payment.
- **amount_minor** and **currency**: the original transaction amount in minor units and its three-letter ISO currency code. A refund event currently represents a full refund and must report the same original amount/currency.
- **subscription_id** (optional): when supplied, it must identify a subscription owned by the same member.

Account Center stores every distinct signed delivery in **payment_webhook_events** for audit, but maintains one current **payment_transactions** row per provider/payment ID. The payment state transitions are monotonic: **pending → succeeded/failed**, **failed → succeeded** and **succeeded → refunded**. A refund without a prior successful payment is rejected. Older or equal-timestamp lifecycle events, repeated states and backwards transitions are acknowledged as ignored; they do not create outbound events. The latest observed provider timestamp is still retained for ignored newer events, preventing an older later delivery from being applied. A repeated provider event ID is acknowledged as a duplicate.

Every accepted state transition and its outbox event commit in one database transaction. If two distinct events race to create the same provider payment ID, the unique database constraint makes the losing request retryable (HTTP 409) rather than silently dropping it. Payment events require the provider's original event creation timestamp; do not substitute the webhook delivery time.

This is the normalized webhook contract, not a hosted checkout integration. Provider-specific checkout, recurring billing, partial refunds and reconciliation still require a concrete payment-provider adapter and live-provider testing. A **payment.refunded** event records the payment state; subscription access changes must be communicated separately using the corresponding subscription lifecycle event.

## References

- [README.md](../README.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [OAUTH.md](OAUTH.md)
- [INSTALLATION.md](INSTALLATION.md)
- [SECURITY.md](SECURITY.md)
- [LOCALIZATION.md](LOCALIZATION.md)


## PHP SDK for connected products

A lightweight PHP 8.2+ client is available under `sdk/php`. It calls the scoped member endpoints using a server-side OAuth access token and provides `authorizationSnapshot()`, `hasPermission()`, `hasGroup()`, `hasFeature()` and `quota()`.

The snapshot helper compares the authorization revision from all five API reads and retries when authorization changes during the fetch. Products should cache snapshots only with their revision, refresh when `GET /api/v1/me` reports a newer revision, and fail closed if Account Center cannot be reached. See [the PHP SDK guide](../sdk/php/README.md).
