# Security status

## Implemented and regression-tested in this increment

- PDO native prepared statements and password hashing with PHP password_hash() / password_verify().
- CSRF token generation and constant-time validation.
- Session ID regeneration after successful authentication.
- Strict PHP session mode, cookie-only sessions, HttpOnly and SameSite=Lax cookies; Secure is enabled when PHP sees HTTPS.
- Server-side session records keyed by a SHA-256 hash of the PHP session identifier (the raw session identifier is not stored in the database).
- 30-minute idle timeout, 12-hour absolute expiry, user-status verification and session revocation on logout.
- Independent failed-login throttles: 10 failures per account identifier and 30 failures per source IP in a rolling 15-minute window. Only REMOTE_ADDR is trusted; forwarded client-IP headers are ignored.
- Atomic installer config/lock writes, retry-safe initial schema creation, and recovery when config writing succeeded but lock creation did not.
- Generic login failure messages, output escaping in existing views and baseline security response headers.

## Current test coverage

- CSRF token acceptance/rejection.
- Repeat execution of the initial MariaDB schema migration.
- Persistent session creation and expiry metadata.
- Session acceptance on a subsequent request.
- Idle session expiry and server-side revocation.
- PHP syntax validation and MariaDB-backed CI.

## Not yet implemented or independently audited

- Member registration, email verification tokens and delivery.
- Password recovery and reset-token lifecycle.
- Session inventory/device management UI and revoke-other-device flows.
- RBAC enforcement, administrator routes and administrative audit workflow.
- Login/security notification email.
- Full installer failure-injection tests across cPanel filesystem/database permission combinations.
- Automated HTTP-level brute-force, CSRF, fixation and account-status tests.
- App Registry, OAuth 2.0 / OIDC, PKCE, consent and token rotation.
- Independent penetration test, production deployment review and operational backup/restore exercise.

Do not treat this development build as production-ready. Enforce HTTPS, keep config and storage outside the public document root, restrict file permissions, and configure backups before real member accounts are accepted.
