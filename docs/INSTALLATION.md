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
4. If HTTPS terminates at a reverse proxy (for example Cloudflare), create config/app.php first and list only the immediate proxy IPs under trusted_proxies; this is required so the installer session cookie also receives Secure. Then open https://your-domain.example/installer/ over HTTPS.
5. Enter the cPanel database credentials and create the initial administrator.
6. Verify the installer reports that installation is locked. Keep storage/install.lock and deny public access to /installer/ using cPanel File Manager or access rules.
7. Confirm login works over HTTPS, then remove write permission from config/.

The installer writes config/database.php and storage/install.lock atomically. It applies repeatable migrations in lexical order. If a migration fails, no completed config/lock is written; correct the database permissions and retry. MySQL DDL implicitly commits, so some tables may already exist after failure. The shipped migrations are designed to be re-run safely. Do not manually drop tables as a first recovery step.

If config/database.php exists but the process stopped before creating install.lock, the next installer request verifies the saved DB connection/schema and completes the lock. If an existing account is selected as the initial administrator, the entered administrator password must match that account's existing password; the installer will not silently promote an arbitrary existing account. If an install lock exists but the database config is missing, restore the config from a secure backup rather than rerunning setup against the existing database.

If DB connection fails, check the DB host, database name, user grants and cPanel database-user assignment. If migration fails, inspect the PHP/server error log (installer logs exception class/code, not submitted credentials), grant the required schema permissions, then retry. A config/lock write failure may require correcting ownership/permissions for config/ and storage/. The automated CI suite covers DB connection and partial-migration failures; actual cPanel filesystem permission behavior still requires deployment-specific verification.

## Language configuration

Vietnamese (`vi`) is the default language and English (`en`) is also supported. Migration 003 changes the default for new profile rows without overwriting existing preferences. Guests can change language with the shared selector; their choice is stored in the one-year `c0m_lang` HttpOnly, SameSite=Lax cookie and PHP session. Authenticated users use `user_profiles.language` as the authoritative preference; it is mirrored to the guest cookie so the selected language remains available after logout. Admin users with the `audit.view` permission can review the localized audit event list at `/admin/audit`. Verification, password-reset and password-change security emails use the recipient's saved language. The installer has its own language selector before the application database is available. To add a language, add a complete UTF-8 catalog under `lang/`, register the locale in `app/Core/Localization.php`, translate every existing key, add localized system-email subjects and bodies in `app/Core/EmailTemplates.php`, update the switcher and extend `tests/localization.php`.

## Mail configuration

Copy config/mail.example.php to config/mail.php. Keep the file outside public/ and never commit real credentials. For development only, set mode to development_log; this writes one-time verification/reset links to storage/mail.log, so protect and delete that file after testing.

For production, configure an external SMTP provider using mode smtp, host, port, encryption (tls or ssl), username, password, from_email and from_name. SMTP authentication is refused when encryption is none. TLS peer and hostname verification are enabled. CraneMail and other external SMTP providers can be used; no local mail server is required. See docs/MAIL.md.

## HTTPS and reverse proxies

Direct HTTPS requests automatically receive Secure session cookies. If TLS terminates at Cloudflare or another reverse proxy and the origin connection is HTTP, explicitly configure the immediate proxy IP addresses in config/app.php:

```php
<?php
declare(strict_types=1);

return [
    'base_url' => 'https://accounts.example.com',
    'trusted_proxies' => [
        '127.0.0.1',
        // Add only the exact, stable IP addresses of proxies that connect directly to this origin.
    ],
];
```

The application and installer trust X-Forwarded-Proto only when REMOTE_ADDR exactly matches one of these configured IP addresses and the header value is exactly https. Never list arbitrary client IP ranges. If direct HTTPS cannot be detected and the proxy IP is not configured, requests fail with HTTP 400 rather than issuing insecure session cookies; configure the web server to provide direct HTTPS to PHP or add the correct immediate proxy addresses.

## Manual configuration

If you prefer manual setup, copy config/database.example.php to config/database.php, set database credentials, then import migrations in numeric order using phpMyAdmin. Provision the first active administrator securely and assign the super_admin role. Never put credentials in the public document root or source control.

## Session policy

Authentication sessions use strict PHP session mode, cookie-only sessions, HttpOnly and SameSite=Lax cookies. The installer uses an HttpOnly, SameSite=Strict session cookie. The Secure attribute is enabled for direct HTTPS or explicitly trusted HTTPS proxies. Server-side session records enforce a 30-minute idle timeout and a 12-hour absolute expiry. Account status and server-side revocation are checked on authenticated requests.

## Tests

Run `php tests/unit.php`, `php tests/smtp.php`, `php tests/installer.php`, `php tests/integration.php`, `php tests/rbac-concurrency.php` and `php tests/http.php`. Database-backed tests require a disposable MariaDB/MySQL database and DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD. HTTP and installer tests require PHP proc_open and bind only to localhost. The installer test creates a temporary restricted DB user and removes it after execution.

This project remains under development and is not production-ready. App Registry, OAuth 2.0/OIDC SSO, PKCE, passkeys and QR login are not implemented.
