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
- Transactional admin status/role changes and audit events.
- Member profile preferences (display name, username, language and timezone).
- Password change with current-password verification, throttling, session revocation and email notification.
- Application and installer requests fail closed unless direct HTTPS or an explicitly configured trusted proxy proves HTTPS; forwarded headers from untrusted clients are ignored.

**Not production-ready.** The current automated HTTP suite exercises the real PHP router and key access-control/CSRF paths, but does not yet cover every end-to-end account flow. Concurrent last-Super-Admin controller tests, filesystem-permission installer injection, full SMTP TLS/auth failure fixtures, independent security review and broader UI localization remain outstanding. App Registry and OAuth/OIDC SSO are separate milestones and are not complete.

## cPanel installation

1. Upload the repository so app/, config/, database/, storage/ and public/ are siblings.
2. Point the domain document root to public/; do not expose the repository root.
3. Enable PHP 8.2+ with pdo_mysql, mbstring, openssl and fileinfo.
4. Create a MariaDB/MySQL database and user in cPanel.
5. Visit /installer/ over HTTPS and create the first administrator.
6. Keep storage/install.lock, deny public access to /installer/ after setup and make config files read-only.

See docs/INSTALLATION.md, docs/MAIL.md and docs/SECURITY.md.

## Tests

Run:

- `php tests/unit.php`
- `php tests/smtp.php`
- `php tests/installer.php` (requires a disposable MariaDB database and DB_* environment variables)
- `php tests/integration.php` (requires a disposable MariaDB database and DB_* environment variables)
- `php tests/rbac-concurrency.php` (requires a disposable MariaDB database and PHP proc_open)
- `php tests/http.php` (requires the same DB_* environment variables and PHP proc_open)

The GitHub Actions workflow tests PHP 8.2, 8.3 and 8.4 against MariaDB 11.4. It runs repository-wide PHP syntax checks, unit tests, SMTP protocol tests, installer recovery tests, database integration tests and real HTTP router tests. A configured workflow is not evidence of a passing run; check the Actions result for the exact commit.

## Localization (Vietnamese / English)

The UI uses the built-in `App\\Core\\Localization` service and the UTF-8 catalogs `lang/vi.php` and `lang/en.php`. Vietnamese (`vi`) is the default. The language switcher posts to `/language`; guest choices are kept in a one-year HttpOnly, SameSite=Lax cookie and authenticated choices are stored in `user_profiles.language`. Authenticated account preference takes precedence. Add new message keys to both catalogs and add localized system-email copy to `app/Core/EmailTemplates.php`; do not translate user-provided names, email addresses, URLs or tokens. Run `php tests/localization.php` when changing catalogs or email templates.

## Security status

See [docs/SECURITY.md](docs/SECURITY.md). Do not accept real member accounts or financial activity until outstanding security work and deployment review are complete.
