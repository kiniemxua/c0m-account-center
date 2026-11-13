# Installation (cPanel)

1. Upload the repository contents to your account.
2. Set the domain document root to the repository's `public/` directory.
3. Use PHP 8.2 or newer with PDO and PDO MySQL enabled.
4. Create an empty MariaDB/MySQL database and database user in cPanel.
5. Copy `config/database.example.php` to `config/database.php` and enter credentials.
6. Import `database/migrations/001_core.sql` using phpMyAdmin.
7. Create the first active administrator through a secure CLI/database provisioning step; public self-registration is not enabled in this foundation release.
8. Ensure `config/`, `database/`, `app/` and `installer/` are outside the public document root.

Do not expose the installer or database files. Configure HTTPS before accepting real credentials.

This foundation is not production-ready. Password reset, email verification, registration, lockout/rate limiting, persistent session tracking, admin role enforcement and automated tests are still pending.
