# Changelog

## Unreleased

- Add registration with pending accounts, email verification and hashed single-use tokens.
- Add password recovery with expiring single-use reset tokens and session revocation.
- Add external SMTP provider abstraction and a development-only mail log provider.
- Reject SMTP AUTH over plaintext and require username/password to be configured together.
- Add server-side session inventory, revoke-one and revoke-other-device flows.
- Add database-backed Super Admin, Admin and Member roles, permission checks and admin controls.
- Serialize privileged role/status changes, audit them in the same transaction and protect the final active super administrator.
- Add account profile preferences for display name, username, language and timezone.
- Add password change with current-password verification, independent throttling, session/token revocation and security email.
- Add migration 003 for Vietnamese as the default language of newly created profiles.
- Add Vietnamese-first and English UI localization for public, account, security, admin, audit and installer screens.
- Persist guest language choices in an HttpOnly cookie/session and authenticated choices in account profile preferences; keep account preference authoritative.
- Localize validation/system errors and verification, reset and password-change security emails.
- Add catalog parity and unique-key checks, view-key coverage, locale fallback, JavaScript message and email-template checks.
- Ensure authenticated accounts without a valid saved locale fall back to Vietnamese instead of inheriting stale guest preferences.
- Harden language-switch return targets against protocol-relative, encoded and malformed redirect values; add HTTP regression coverage for CSRF, cookie flags and member preference persistence.
- Extend real HTTP coverage to administrator locale switching on protected admin routes, in addition to guest and member persistence; assert UTF-8 recipient names in localized email templates.
- Import the global `RuntimeException` explicitly in the authentication controller to avoid a namespaced class lookup failure on the missing-default-role error path.
- Expand MariaDB integration tests for token expiry/replay, login session fixation, identifier throttling, profile preferences and password change.
- Add SMTP protocol tests and update cPanel/mail/security documentation.

> This release remains under active development and is not production-ready.
