# Installation (cPanel)

## Requirements

- PHP 8.2+ with pdo_mysql, mbstring, openssl and fileinfo.
- MariaDB 10.6+ or MySQL 8+.
- HTTPS enabled for the domain.
- A database and database user created in cPanel.

## Deploy

1. Upload the repository so app/, config/, database/, storage/ and public/ are siblings.
2. Set the cPanel domain document root to public/. Do not expose the repository root.
3. Ensure config/ and storage/ are outside the public document root. Make them writable by PHP only during setup; use restrictive permissions (directories 0750, config 0640 where supported).
4. Open https://your-domain.example/installer/ over HTTPS.
5. Enter the cPanel database credentials and create the initial administrator.
6. Verify the installer reports that installation is locked. Keep storage/install.lock and deny public access to /installer/ using cPanel File Manager or access rules.
7. Confirm login works over HTTPS, then remove write permission from config/.

The installer writes config/database.php atomically and creates storage/install.lock only after schema and administrator creation. If the config was written but lock creation was interrupted, the next request verifies the configured database and completes the lock. If failure occurs before config creation, repeatable migrations can be retried. MySQL DDL is not transactional; see database/README.md before manual recovery.

## Mail configuration

Copy config/mail.example.php to config/mail.php. Keep the file outside public/ and never commit real credentials. For development only, set mode to development_log; this writes one-time verification/reset links to storage/mail.log, so protect and delete that file after testing.

For production, configure an external SMTP provider using mode smtp, host, port, encryption (tls or ssl), username, password, from_email and from_name. SMTP authentication is refused when encryption is none. CraneMail and other external SMTP providers can be used; no local mail server is required. See docs/MAIL.md.

## Manual configuration

If you prefer manual setup, copy config/database.example.php to config/database.php, set database credentials, then import migrations in numeric order using phpMyAdmin. Provision the first active administrator securely and assign the super_admin role. Never put credentials in the public document root or source control.

## Session policy

Authentication sessions use strict PHP session mode, cookie-only sessions, HttpOnly and SameSite=Lax cookies. The Secure attribute is enabled when PHP detects HTTPS. Server-side session records enforce a 30-minute idle timeout and a 12-hour absolute expiry. Account status and server-side revocation are checked on authenticated requests.

## Tests

Run `php tests/unit.php` and `php tests/smtp.php`. For database integration checks, set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD, then run `php tests/integration.php`. GitHub Actions is configured to run these against MariaDB.

This project remains under development and is not production-ready. App Registry, OAuth 2.0/OIDC SSO, PKCE, passkeys and QR login are not implemented.
