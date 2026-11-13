# Security status

## Implemented in the current foundation

- PDO prepared statements and native prepares.
- Password hashing with PHP `password_hash()` and verification with `password_verify()`.
- Session ID regeneration after successful authentication.
- HttpOnly and SameSite=Lax session cookies; Secure is enabled when PHP detects HTTPS.
- CSRF token validation on sign-in and sign-out forms.
- Basic database-backed failed-login throttling and login attempt history.
- Basic security response headers and output escaping in current views.

## Not implemented / not audited

- Email verification and password reset.
- Registration abuse controls and account lockout policy.
- Persistent session inventory, device management and remote revocation.
- Roles, permissions and admin authorization.
- TOTP, recovery codes, passkeys and WebAuthn.
- OAuth2/OIDC, PKCE, refresh-token rotation and consent management.
- QR login.
- Wallet ledger, payment processing and webhook verification.
- Secrets-at-rest encryption, key rotation and operational audit review.
- Comprehensive automated unit, integration and penetration tests.

Do not use this build for real member accounts or financial activity until the outstanding controls are implemented and independently tested. Enforce HTTPS at the hosting layer.
