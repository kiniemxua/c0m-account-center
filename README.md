# c0m.me Account Center

Central account and application hub for the c0m.me ecosystem.

## Stack

- PHP 8.2+
- MariaDB / MySQL with PDO native prepares
- Vanilla JavaScript, HTML and CSS
- cPanel shared hosting; no Node.js runtime required

## Current implementation status

The repository contains an account/authentication foundation:

- cPanel installer with atomic config/lock writes, repeatable migrations and recovery after partial schema application.
- Existing administrator accounts can only be promoted by the installer after password verification.
- Email/password registration with pending accounts and single-use email verification tokens.
- Password reset with hashed, expiring, single-use tokens and session revocation.
- External SMTP provider interface and a development log provider.
- Login throttling by account identifier and source IP.
- Hardened PHP sessions with server-side records, idle/absolute expiry and revocation.
- Session/device listing, individual revocation and sign-out-other-devices.
- Database-backed Super Admin, Admin and Member roles with protected admin routes and multi-role member assignments.
- Monotonic per-user authorization revisions incremented when role assignments, account status or email-verification state changes.
- Super Admin member-group creation and membership assignment with transactional revision updates.
- Transactional admin status/role changes and audit events.
- Super Admin application registry for creating, editing, featuring and disabling catalog entries, with transactional audit events.
- Super Admin member-group creation, membership assignment and active/disabled lifecycle with revision updates.
- Super Admin subscription-plan management with validated feature/quota JSON, immutable plan keys, archive safeguards and transactional entitlement synchronization.
- Normalized payment transaction lifecycle with one current row per provider payment, retained webhook event history, provider timestamp ordering, failed-to-succeeded retries, full-refund transitions and transactional outbox publication.
- Application-specific group grants for `restricted` registry entries; the dashboard checks active group membership server-side.
- Versioned member APIs for profile, active groups, group memberships, effective roles/permissions and subscription entitlements, all protected by scoped OAuth bearer tokens and authorization revisions.
- Lightweight PHP SDK for connected products, including consistent authorization snapshots, revision-aware cache refresh and fail-closed permission/feature/quota checks.
- OAuth 2.0 Authorization Code + PKCE (S256) for public and confidential clients, exact redirect matching, one-time authorization codes and consent.
- OpenID Connect discovery, RS256 ID tokens, JWKS, scoped UserInfo, nonce/state validation and authorization revision output.
- Opaque access/refresh tokens, refresh-token rotation and replay detection, token-family revocation and RFC 7009 revocation endpoint.
- Registered-origin CORS for browser token, revocation and UserInfo calls; public discovery/JWKS endpoints.
- Super Admin browser-based OAuth client provisioning, one-time confidential secret display, secret rotation and client lifecycle controls; CLI migration runner for existing installations.
- Member profile preferences (display name, username, language and timezone).
- Password change with current-password verification, throttling, session revocation and email notification.
- Application and installer requests fail closed unless direct HTTPS or an explicitly configured trusted proxy proves HTTPS; forwarded headers from untrusted clients are ignored.

**Not production-ready.** OAuth2/OIDC authorization-code SSO, scoped UserInfo and token lifecycle are now implemented and covered by HTTP integration tests, but the provider still needs an independent protocol/security review, signing-key rotation and broader rate limiting. Subscription plans, entitlements, signed payment webhook ingestion, normalized payment transaction lifecycle and transactional outbox delivery are implemented; provider-specific checkout/recurring-billing adapters, partial refunds and reconciliation remain incomplete. Outbound webhook SSRF protections are implemented but still require live delivery verification. External applications must validate tokens and enforce authorization server-side; dashboard visibility is never an authorization boundary. Do not accept production accounts or financial activity until the remaining modules, security review and deployment gates are complete.

## cPanel installation

1. Upload the repository so app/, config/, database/, storage/ and public/ are siblings.
2. Point the domain document root to public/; do not expose the repository root.
3. Enable PHP 8.2+ with pdo_mysql, mbstring, openssl, fileinfo and curl.
4. Create a MariaDB/MySQL database and user in cPanel.
5. Visit /installer/ over HTTPS and create the first administrator.
6. Keep storage/install.lock, deny public access to /installer/ after setup and make config files read-only.

See docs/INSTALLATION.md, docs/LOCALIZATION.md, docs/MAIL.md, docs/SECURITY.md, docs/ARCHITECTURE.md and docs/API.md.

## Documentation map

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): system overview, runtime layers and security architecture.
- [docs/API.md](docs/API.md): current HTTP patterns and integration API contract.
- [docs/OAUTH.md](docs/OAUTH.md): OAuth2/OIDC endpoints, PKCE flow, client provisioning and operational limitations.
- [docs/INSTALLATION.md](docs/INSTALLATION.md): deployment and reverse-proxy setup guidance.
- [docs/LOCALIZATION.md](docs/LOCALIZATION.md): locale selection, catalog maintenance and validation.
- [docs/MAIL.md](docs/MAIL.md): SMTP and development mail configuration.
- [docs/SECURITY.md](docs/SECURITY.md): risk review and security controls.
- [AI_MEMORY.md](AI_MEMORY.md): current project state and operational constraints.

## Tests

Run:

- `php tests/unit.php`
- `php tests/localization.php`
- `php tests/smtp.php`
- `php tests/installer.php` (requires an empty disposable `c0m_test` MariaDB database and DB_* environment variables; the test refuses non-empty schemas)
- `php tests/integration.php` (requires a disposable MariaDB database and DB_* environment variables)
- `php tests/rbac-concurrency.php` (requires a disposable MariaDB database and PHP proc_open)
- `php tests/http.php` (requires the same DB_* environment variables and PHP proc_open)

The GitHub Actions workflow runs the full test suite on PHP 8.2 + MariaDB 11.4, PHP 8.3 + MariaDB 11.4, PHP 8.4 + MariaDB 11.4, and PHP 8.4 + MySQL 8.4. It runs repository-wide PHP syntax checks, unit tests, localization/catalog and email-template tests, SMTP protocol tests, installer recovery tests, database integration tests and real HTTP router tests. A configured workflow is not evidence of a passing run; check the Actions result for the exact commit.

## Localization (Vietnamese / English)

The UI and installer use the built-in `App\\Core\\Localization` service and UTF-8 catalogs `lang/vi.php` and `lang/en.php`. Vietnamese (`vi`) is the default. The language switcher posts to `/language`; guest choices are kept in a one-year HttpOnly, SameSite=Lax cookie and session, while authenticated choices are saved to `user_profiles.language` and mirrored to the cookie for logout continuity. Account preference takes precedence. Verification, password-reset and password-change security emails use the recipient's saved language. Keep catalog keys unique and add new message keys to both catalogs and localized email copy to `app/Core/EmailTemplates.php`; never translate user-provided names, email addresses, URLs or tokens. See `docs/LOCALIZATION.md` and `docs/CONFIGURATION.md`, and run `php tests/localization.php` after changes.

## Security status

See [docs/SECURITY.md](docs/SECURITY.md). Do not accept real member accounts or financial activity until outstanding security work and deployment review are complete.
