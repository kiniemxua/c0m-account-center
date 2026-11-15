# API and integration contract

## Current state

The web surface remains server-rendered PHP for browser account and administration pages. External applications now have an OAuth 2.0 / OpenID Connect provider using Authorization Code + PKCE (S256), plus scoped OIDC UserInfo. Subscription, entitlement, payment and signed webhook APIs are not implemented yet.

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

OAuth clients are currently provisioned through the CLI script `scripts/create-oauth-client.php`. One OAuth client is allowed per application registry entry. Confidential client secrets are displayed once and only their SHA-256 hashes are stored. See [OAUTH.md](OAUTH.md).

## Current browser account routes

- `/login`, `/register`, `/logout`
- `/verify-email`, `/resend-verification`
- `/forgot-password`, `/reset-password`
- `/dashboard`, `/account`
- `/sessions`, `/sessions/revoke`, `/sessions/revoke-others`
- `/login-history`
- `/admin/users`, `/admin/settings`, `/admin/audit`
- `/admin/applications`, `/admin/applications/save`
- `/admin/groups`
- `/language`

Browser mutations require session-bound CSRF tokens. The API bearer-token endpoints do not use browser cookies as identity.

## Future API modules

The next API namespaces should remain explicit and versioned:
- `/api/v1/me`
- `/api/v1/memberships`
- `/api/v1/entitlements`
- `/api/v1/applications`
- `/api/v1/webhooks`

Use a normalized response envelope:

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

Errors should use stable machine-readable codes and must not expose SQL, secrets, token material or stack traces.

## Integration principles

- Account Center is the source of truth for identity, groups, roles, permissions and future entitlements.
- An API key identifies an application only; it must never identify a member.
- Keep user identity distinct from application identity.
- Validate token issuer, audience, signature, expiry, nonce and state at the connected application.
- Treat external products as consumers, not authorities.
- Use authorization revisions to invalidate stale downstream caches.
- Verify payment webhook signatures and idempotency before changing entitlements.
- Write payment state and outbox events in one database transaction.
- Never treat dashboard visibility or client-side UI state as authorization.

## References

- [README.md](../README.md)
- [ARCHITECTURE.md](ARCHITECTURE.md)
- [OAUTH.md](OAUTH.md)
- [INSTALLATION.md](INSTALLATION.md)
- [SECURITY.md](SECURITY.md)
- [LOCALIZATION.md](LOCALIZATION.md)
