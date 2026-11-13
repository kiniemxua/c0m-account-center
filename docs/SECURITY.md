# Security status

## Implemented in the current foundation

- PDO native prepared statements and PHP password_hash()/password_verify().
- CSRF token generation and constant-time validation on state-changing routes.
- Session ID regeneration after successful authentication.
- Strict PHP session mode, cookie-only sessions, HttpOnly and SameSite=Lax authenticated cookies; the installer uses SameSite=Strict.
- The Secure cookie attribute is enabled for direct HTTPS. Behind a reverse proxy, it is enabled only when REMOTE_ADDR matches an explicit IP in config/app.php trusted_proxies and X-Forwarded-Proto is exactly https. Forwarded headers from other clients are ignored.
- Server-side session records store SHA-256 hashes of PHP session identifiers, not raw session IDs.
- 30-minute idle timeout, 12-hour absolute expiry, account-status validation and server-side session revocation.
- Session/device inventory, individual session revocation and sign-out-other-devices. Suspended accounts and idle/absolute expiry invalidate the server-side session.
- Independent failed-login throttles by account identifier and source IP. Only REMOTE_ADDR is used for client IP; forwarded client-IP headers are ignored.
- Registration enable/disable is enforced server-side from the administrator setting.
- Registration, pending-account email verification, hashed one-time verification tokens and generic duplicate-registration responses.
- Password recovery with hashed, expiring, single-use reset tokens and revocation of all account sessions.
- Member password change with current-password verification under a row lock, independent attempt throttling, session/token revocation and post-change email notification.
- Member profile update with validation, unique usernames, language/timezone preferences and audit logging.
- Database-backed Super Admin, Admin and Member RBAC. Admin controllers enforce permissions server-side; member administration preserves and edits multiple selected roles rather than silently collapsing them to one.
- Privileged status/role changes use transactions and row locks to protect the final active Super Admin; audit events commit with the change.
- External SMTP provider and development-log provider. SMTP AUTH is rejected unless TLS or implicit SSL is configured; TLS peer and hostname verification are enabled by the stream context.
- Installer writes config and install lock atomically, refuses to overwrite existing configuration, can recover when config was written but the lock was not, and permits retries after partial repeatable migrations.
- Installer requires password verification before promoting an already-existing account to Super Admin.
- Generic installer failure responses; application and installer logs record only exception class/code, not raw DB/SMTP credentials, server responses or exception messages.
- Generic login/reset responses, output escaping in current views and baseline security response headers.

## Automated test coverage

- CSRF token acceptance/rejection and reverse-proxy HTTPS trust boundaries.
- Repeat execution of core/auth/preferences schema migrations.
- Persistent session creation, expiry metadata and revocation.
- Registration, duplicate-address response, verification-token hashing, activation and replay rejection.
- Expired verification and password-reset token rejection.
- Password reset hash update and existing-session revocation.
- Login session-ID rotation, server-side session creation, failed-login history, account-identifier/IP throttling, idle expiry and suspended-account invalidation.
- Profile preference persistence and password-change notification dispatch.
- RBAC permission grants/denials and audit insertion.
- SMTP transaction fixture, plaintext-auth rejection and credential-pair validation.
- Installer HTTP failure on database connection, partial migration failure caused by restricted DB privileges, safe retry, and existing-account password-verification behavior.
- HTTP router requests for login rendering, security headers, CSRF rejection, unauthenticated admin access denial and unknown routes.
- Concurrent transaction test that races two Super Admin removals and verifies that one active Super Admin remains.
- GitHub Actions matrix for PHP 8.2, 8.3 and 8.4 with MariaDB 11.4, plus MySQL 8.4 on PHP 8.4.

## Remaining work / not independently audited

- Full HTTP-level concurrent controller requests proving that the browser/session/router layer preserves the same race-condition guarantee; the DB transaction guard itself is exercised by concurrent PHP workers.
- HTTP-level full registration, email verification, login/logout, password-reset and session-expiration journeys; current HTTP tests are router/security smoke integration, not browser E2E.
- SMTP fixture coverage for STARTTLS handshake, certificate/hostname mismatch, AUTH rejection, timeout and server-unavailable scenarios. Current fixture validates a local SMTP message transaction without production credentials.
- Installer failure injection for filesystem write/rename permission failures and recovery after an interrupted config/lock write on real cPanel filesystems.
- Full operational audit-log review, migration version tracking and a tested backup/restore procedure.
- Independent penetration test and production deployment review.
- App Registry, OAuth 2.0/OIDC, PKCE, consent and token rotation.

Do not treat this development build as production-ready. Enforce HTTPS, keep config and storage outside the public document root, restrict permissions, configure backups and complete the remaining tests before accepting real member accounts.
