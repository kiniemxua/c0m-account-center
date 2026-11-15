# OAuth 2.0 and OpenID Connect

## Implemented protocol surface

The current provider implements the Authorization Code flow with PKCE (S256) for public and confidential clients.

| Endpoint | Method | Purpose |
|---|---|---|
| `/.well-known/openid-configuration` | GET | OpenID Provider discovery |
| `/oauth/authorize` | GET, POST | Authorization request and user consent |
| `/oauth/token` | POST | Authorization-code exchange and refresh |
| `/oauth/userinfo` | GET, POST | Scoped user claims |
| `/oauth/revoke` | POST | RFC 7009 token revocation |
| `/oauth/jwks` | GET | RSA public signing key set |

The authorization endpoint requires `response_type=code`, `state`, an exact registered `redirect_uri`, `code_challenge_method=S256`, and a valid 43-character S256 challenge. Authorization codes are random, stored only as SHA-256 hashes, expire after five minutes and can be consumed once. Failed PKCE verification does not consume the code.

Access tokens are opaque random values stored only as hashes. Access tokens expire after 15 minutes. Refresh tokens expire after 30 days and rotate on every successful use. Reuse of an already rotated refresh token revokes the associated token family, including its access tokens. Revocation is scoped to the authenticated client.

ID tokens are signed with RS256 using an RSA key of at least 2048 bits. The signing key is generated on first use and stored at `storage/oauth-signing-private.pem` with owner-only permissions. The private key must remain outside the public document root. Back it up securely; losing it invalidates the ability to verify tokens signed by that key. Key rotation and historical JWKS retention are not yet implemented.

## Scopes

- `openid`: OIDC subject and ID token
- `profile`: display name and preferred username/picture when present
- `email`: email address and verification state
- `groups`: active member group keys
- `permissions`: effective role-derived permission keys
- `entitlements`: active subscription features and quotas through the versioned member API

UserInfo also returns `authorization_revision`. Connected apps should compare this revision with their cached authorization snapshot and refresh when it changes. A connected app must still enforce its own server-side access checks; hiding an application in the dashboard is not an authorization boundary.

## Provisioning a client

Apply new migrations on an existing installation:

```sh
php scripts/migrate.php
```

The migration runner requires CLI access and the existing `config/database.php`. It obtains a database advisory lock and replays the repeatable migration files in lexical order.

Create an OAuth client for an active application:

```sh
php scripts/create-oauth-client.php <application-id> public https://app.example.com/auth/callback
php scripts/create-oauth-client.php <application-id> confidential https://app.example.com/auth/callback
```

The command prints the client ID and, for confidential clients, a high-entropy client secret exactly once. Store the secret in the connected application's secret manager. Only its SHA-256 hash is stored in Account Center. Redirect URIs must be HTTPS, exact-match values without userinfo or fragments. One OAuth client is currently allowed per registry application.

Super Admins can also create clients, rotate confidential secrets and enable/disable clients in the browser at `/admin/oauth-clients`. The CLI remains available for automation and recovery; one client per application is enforced.

## Versioned member API

The following endpoints accept OAuth bearer tokens and enforce their scopes on every request:

| Endpoint | Scope | Data |
|---|---|---|
| `GET /api/v1/me` | `openid` | Stable member ID and authorization revision; optional `profile` and `email` claims. |
| `GET /api/v1/me/groups` | `groups` | Active member group keys and names. |
| `GET /api/v1/me/memberships` | `groups` | Group assignments and group lifecycle state. |
| `GET /api/v1/me/permissions` | `permissions` | Effective roles and permission keys. |
| `GET /api/v1/me/entitlements` | `entitlements` | Active feature flags, quotas and validity metadata. |

Every API response is non-cacheable and includes an authorization revision for downstream cache invalidation. See [API.md](API.md) for the response envelope and integration rules.

## Authorization request

Generate a cryptographically random PKCE verifier (43–128 allowed characters) and its S256 challenge. Generate independent random `state` and `nonce` values. Redirect the member's browser to:

```
https://accounts.example.com/oauth/authorize
  ?response_type=code
  &client_id=CLIENT_ID
  &redirect_uri=https%3A%2F%2Fapp.example.com%2Fauth%2Fcallback
  &scope=openid%20profile%20email
  &state=RANDOM_STATE
  &nonce=RANDOM_NONCE
  &code_challenge=S256_CHALLENGE
  &code_challenge_method=S256
```

The member signs in at Account Center if needed, reviews the consent screen, and approves or denies the request. The callback receives the code and the original state. The application must compare the returned state with the state it created before proceeding.

## Token exchange

POST form-encoded data to `/oauth/token`:

```
grant_type=authorization_code
client_id=CLIENT_ID
code=AUTHORIZATION_CODE
redirect_uri=https://app.example.com/auth/callback
code_verifier=ORIGINAL_PKCE_VERIFIER
```

Public clients authenticate using their client ID and PKCE. Confidential clients must use HTTP Basic client authentication. Never place a confidential client secret in browser code.

The response includes `access_token`, `refresh_token`, `expires_in`, `token_type`, and `id_token` when `openid` was requested. Verify ID-token signatures against the published JWKS and validate issuer, audience, expiry, nonce and subject before creating a local application session.

Use the bearer access token at `/oauth/userinfo`. Refresh tokens must be stored securely and replaced atomically after each successful refresh. If a refresh request has an ambiguous network outcome, do not blindly retry the old token: replay detection intentionally revokes its token family.

## Browser CORS

Token, revocation and UserInfo endpoints accept browser CORS requests only when the request Origin matches the origin of an active client's registered HTTPS redirect URI. Preflight requests support Authorization and Content-Type. Discovery and JWKS are public read-only resources.

CORS is not authentication. Every endpoint still validates the bearer token or client credentials independently.

## Operational and security limitations

Before production use, complete and review:
- per-client audit history UI
- signing-key rotation with overlap and historical JWKS publication
- token and authorization endpoint rate limits, anomaly monitoring and abuse controls
- a formal OIDC conformance review and independent security review
- provider-specific checkout, renewal, failure, refund and cancellation adapters
- signing-key rotation with overlap and historical JWKS publication
- independent OIDC conformance/security review and product SDKs
- production HTTPS, secure storage permissions, backup/restore and key recovery procedures

Do not use this implementation as a general-purpose identity provider until those remaining controls have been completed and reviewed.
