# Changelog

## Unreleased

- Harden CSRF handling so array/object request values are rejected without type errors and malformed stored session tokens are regenerated; add unit regression coverage.
- Harden the Super Admin concurrency test so partially created users/roles are still removed if fixture setup or worker startup fails before the race begins.
- Make the local runner remove its test-generated database/app/mail configuration, installer lock and development mail log on every exit path after verifying those paths were absent at startup.
- Make the local test runner reject a non-empty `c0m_test` database before running; the installer suite creates persistent schema objects, so reruns now require an explicitly fresh disposable database instead of failing midway with misleading fixture errors.
- Harden HTTP and installer test setup/finally paths so early database, port or server failures still remove temporary configuration, close processes and clean created fixtures; restore the trusted-proxy fixture before the installer existing-account recovery scenario.
- Add a guarded local test-suite runner that checks PHP extensions and a disposable `c0m_test` database before executing the same lint and integration checks used by CI.
- Extend the untranslated-text regression scan to shared view partials and avoid resending an already-synchronized authenticated locale cookie on every page request.
- Extend localization regression tests with a static HTML text-node scan across all public/account/admin views and the installer to prevent untranslated copy from being added outside the catalogs.
- Fix the catalog duplicate-key audit so it scans every declaration, including multiple entries on one line; remove repeated entries and verify 223 unique keys in each catalog with matching key sets.
- Correct the HTTP admin-locale regression expectation after the fixture switches its saved account language back to Vietnamese.
- Add HTTP integration coverage for authenticated English-to-Vietnamese switching, account preference persistence, and guest-cookie synchronization.

- Expand integration checks for account language precedence over stale guest session/cookie values and invalid stored locale fallback.

- Extend localization regression coverage to audit event keys and verify audit labels plus metadata/IP output escaping.

- Add registration with pending accounts, email verification and hashed single-use tokens.
- Add password recovery with expiring single-use reset tokens and session revocation.
- Add external SMTP provider abstraction and a development-only mail log provider.
- Reject SMTP AUTH over plaintext and require username/password to be configured together.
- Add server-side session inventory, revoke-one and revoke-other-device flows.
- Add database-backed Super Admin, Admin and Member roles, permission checks and admin controls.
- Serialize privileged role/status changes, audit them in the same transaction and protect the final active super administrator.
- Add account profile preferences for display name, username, language and timezone.
- Add password change with current-password verification, independent throttling, session/token revocation and security email.
- Add migration 003 for Vietnamese as the default language of newly created profiles and safely backfill missing legacy profile rows without overwriting existing preferences.
- Add Vietnamese-first and English UI localization for public, account, security, admin, audit and installer screens.
- Persist guest language choices in an HttpOnly cookie/session and authenticated choices in account profile preferences; keep account preference authoritative.
- Localize validation/system errors and verification, reset and password-change security emails.
- Add catalog parity, unique-key and cross-locale placeholder checks, view-key coverage, locale fallback, JavaScript message and email-template checks.
- Ensure authenticated accounts without a valid saved locale fall back to Vietnamese instead of inheriting stale guest preferences.
- Harden language-switch return targets against protocol-relative, encoded, malformed, raw-control-byte and percent-encoded-control-byte values; reject raw controls before URL parsing and cap return targets at 2,048 bytes.
- Extend real HTTP coverage to administrator locale switching on protected admin routes, in addition to guest and member persistence; assert UTF-8 recipient names and recipient-profile language selection in localized email templates.
- Add regression coverage proving invalid locale values in session/cookies and Installer query parameters cannot select arbitrary catalog files.
- Fix doubled namespace separators in the missing-profile locale integration test so it is valid PHP, and extend HTTP redirect tests for raw and encoded control bytes.
- Import the global `RuntimeException` explicitly in the authentication controller to avoid a namespaced class lookup failure on the missing-default-role error path.
- Expand MariaDB integration tests for token expiry/replay, login session fixation, identifier throttling, profile preferences and password change.
- Add SMTP protocol tests and update cPanel/mail/security documentation.

> This release remains under active development and is not production-ready.
