# c0m.me Account Center

Central account and application hub for the c0m.me ecosystem.

## Stack

- PHP 8.2+
- MariaDB / MySQL with PDO native prepares
- Vanilla JavaScript, HTML and CSS
- Designed for cPanel shared hosting; no Node.js runtime required

## Development status

Phase 1 authentication foundation is being implemented. The current increment includes a retry-safe cPanel installer, hardened PHP sessions, database-backed session expiry/revocation, independent failed-login throttles, CSRF tests and MariaDB integration tests.

**Not production-ready.** Registration, email verification, password recovery, device management UI and full RBAC are still outstanding. App Hub and SSO are separate future milestones and are not represented as complete.

## Install

1. Upload the repository with app/, config/, database/, storage/ and public/ at the same level.
2. Point the cPanel domain document root to public/.
3. Enable PHP 8.2+ and pdo_mysql.
4. Create a MariaDB/MySQL database and user in cPanel.
5. Visit /installer/ over HTTPS and follow the setup wizard.
6. Keep storage/install.lock, remove public installer access after setup, and make config/database.php read-only.

See docs/INSTALLATION.md and docs/SECURITY.md.

## Tests

- php tests/unit.php
- Set DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD, then run php tests/integration.php.

GitHub Actions runs PHP syntax validation, unit checks and MariaDB integration tests.
