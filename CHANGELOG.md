# Changelog

## Unreleased

- Add registration with pending accounts, email verification and hashed single-use tokens.
- Add password recovery with expiring single-use reset tokens and session revocation.
- Add external SMTP provider abstraction and a development-only mail log provider.
- Reject SMTP AUTH over plaintext and require username/password to be configured together.
- Add server-side session inventory, revoke-one and revoke-other-device flows.
- Add database-backed Super Admin, Admin and Member roles, permission checks and admin controls.
- Add registration setting management and admin audit events.
- Expand MariaDB integration tests for token expiry/replay, login session fixation and identifier throttling.
- Add SMTP protocol tests and update cPanel/mail/security documentation.
- Retain cPanel installer recovery, hardened sessions, independent login throttles and PHP/MariaDB CI.

> This release remains under active development and is not production-ready.
