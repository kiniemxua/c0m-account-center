# Security status

## Implemented in the current foundation

- PDO native prepared statements and PHP password_hash()/password_verify().
- CSRF token generation and constant-time validation.
- Session ID regeneration after successful authentication.
- Strict PHP session mode, cookie-only sessions, HttpOnly and SameSite=Lax cookies; Secure is enabled when PHP detects HTTPS.
- Server-side session records store SHA-256 hashes of PHP session identifiers, not raw session IDs.
- 30-minute idle timeout, 12-hour absolute expiry, account-status validation and server-side session revocation.
- Session/device inventory, individual session revocation and sign-out-other-devices.
- Independent failed-login throttles by account identifier and source IP. Only REMOTE_ADDR is trusted; forwarded client-IP headers are ignored.
- Registration, pending-account email verification, hashed one-time verification tokens and generic duplicate-registration responses.
- Password recovery with hashed, expiring, single-use reset tokens and revocation of all account sessions.
- Member password change with current-password verification under a row lock, independent attempt throttling, session/token revocation and post-change email notification.
- Member profile update with validation, unique usernames, language/timezone preferences and audit logging.
- Database-backed Super Admin, Admin and Member RBAC. Admin controllers enforce permissions server-side.
- Privileged status/role changes use transactions and row locks to protect the final active Super Admin; audit events commit with the change.
- External SMTP provider and development-log provider. SMTP AUTH is rejected unless TLS or implicit SSL is configured.
- Atomic installer config/lock writes, repeatable initial schema setup and interrupted-install recovery.
- Generic login/reset responses, output escaping in current views and baseline security response headers.

## Automated test coverage

- CSRF token acceptance/rejection.
- Repeat execution of core/auth/preferences schema migrations.
- Persistent session creation, expiry metadata and revocation.
- Registration, duplicate-address response, verification-token hashing, activation and replay rejection.
- Expired verification and password-reset token rejection.
- Password reset hash update and existing-session revocation.
- Login session-ID rotation, server-side session creation, failed-login history and account-identifier throttling.
- Profile preference persistence and password-change notification dispatch.
- RBAC permission grants/denials and audit insertion.
- SMTP transaction fixture, plaintext-auth rejection and credential-pair validation.
- PHP syntax validation and MariaDB-backed integration workflow configuration.

## Remaining work / not independently audited

- HTTP-level tests for CSRF rejection, IP-based throttling, account-status changes and installer failure injection.
- Login notification email for new devices and additional account-security events.
- Full operational audit-log review and concurrent-administrator integration tests.
- Migration version tracking and a tested backup/restore procedure.
- Independent penetration test and production deployment review.
- App Registry, OAuth 2.0/OIDC, PKCE, consent and token rotation.

Do not treat this development build as production-ready. Enforce HTTPS, keep config and storage outside the public document root, restrict permissions, configure backups and complete the remaining tests before accepting real member accounts.
