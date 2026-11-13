# c0m.me Account Center

Central account and application hub for the c0m.me ecosystem.

## Stack

- PHP 8.2+
- MariaDB / MySQL with PDO native prepares
- Vanilla JavaScript, HTML and CSS
- cPanel shared hosting; no Node.js runtime required

## Current implementation status

The repository contains an early authentication foundation:

- cPanel installer with atomic config/lock writes and interrupted-install recovery.
- Email/password registration with pending accounts and single-use email verification tokens.
- Password reset with hashed, expiring, single-use tokens and session revocation.
- SMTP provider interface with external SMTP delivery and a development log provider.
- Login throttling by account identifier and source IP.
- Hardened PHP sessions with server-side records, idle/absolute expiry and session revocation.
- Session/device listing, individual revocation and sign-out-other-devices.
- Database-backed roles and permissions (Super Admin, Admin, Member) and protected admin routes.
- Admin controls for registration settings, member status and role assignment.
- Unit and MariaDB integration test scripts.

**Not production-ready.** Tests and security review are still in progress. Profile editing, password change, login/security notification emails, expanded account-security workflows and broader HTTP-level abuse tests remain outstanding. App Registry and OAuth/OIDC SSO are separate milestones and are not complete.

## cPanel installation

1. Upload the repository so app/, config/, database/, storage/ and public/ are siblings.
2. Point the domain document root to public/; do not expose the repository root.
3. Enable PHP 8.2+ with pdo_mysql, mbstring and openssl.
4. Create a MariaDB/MySQL database and user in cPanel.
5. Visit /installer/ over HTTPS and create the first administrator.
6. Keep storage/install.lock, deny public access to /installer/ after setup and make config files read-only.

See docs/INSTALLATION.md, docs/MAIL.md and docs/SECURITY.md.

## Tests

- `php tests/unit.php`
- `php tests/smtp.php`
- Set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD, then run `php tests/integration.php`.

GitHub Actions is configured to run PHP syntax validation, unit tests, the SMTP protocol fixture and MariaDB integration tests. A configured workflow is not evidence of a passing run; check the Actions result for the exact commit.

## Security status

See [docs/SECURITY.md](docs/SECURITY.md). Do not accept real member accounts or financial activity until the outstanding security work and deployment review are complete.
