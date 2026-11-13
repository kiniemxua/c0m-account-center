# Installation (cPanel)

## Requirements

- PHP 8.2+ with pdo_mysql, mbstring, openssl and fileinfo.
- MariaDB 10.6+ or MySQL 8+.
- HTTPS enabled for the domain.
- A database and database user created in cPanel.

## Deploy

1. Upload the repository so that app/, config/, database/, storage/ and public/ are siblings.
2. Set the domain document root to the repository's public/ directory. Do not expose the repository root.
3. Ensure config/ and storage/ are writable by PHP only during installation. Set restrictive permissions (directories 0750, config 0640 where supported).
4. Open https://your-domain.example/installer/.
5. Enter the cPanel database credentials and create the initial administrator. The installer stores a password hash, not the administrator password.
6. After installation, verify /installer/ reports that installation is locked. Keep storage/install.lock and remove public access to the installer directory using cPanel File Manager or deny rules.
7. Confirm login works over HTTPS, then remove write permission from config/.

The installer writes config/database.php atomically and creates storage/install.lock only after the database schema and initial administrator are present. If a process stops after the config is written but before the lock is created, the next installer request verifies the configured database and safely completes the lock. If failure occurs before config creation, the initial schema migration is repeatable and can be retried; MySQL DDL itself is not transactional.

## Manual configuration

If you prefer manual setup, copy config/database.example.php to config/database.php, set the database credentials, then import migrations in numeric order using phpMyAdmin. Provision the first active administrator through a secure database/CLI process. Never put credentials in the public document root or source control.

## Session policy

Authentication sessions use an HttpOnly, SameSite=Lax cookie, strict PHP session mode, a 30-minute idle timeout and a 12-hour absolute expiry. The server-side sessions record is checked against the current user status on authenticated requests. HTTPS is required in production so the session cookie receives the Secure attribute.

## Tests

Run php tests/unit.php for unit checks. For database integration checks, set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD, then run php tests/integration.php. GitHub Actions runs both against MariaDB.

The account center is still under development. Registration, verification mail, password recovery, session/device UI, full RBAC, App Registry and SSO are not yet complete.
