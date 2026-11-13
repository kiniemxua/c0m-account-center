# Changelog

## Unreleased

- Add a retry-safe cPanel installer with atomic configuration/lock writes and partial-install recovery.
- Make the initial schema migration safe to re-run after non-transactional MySQL DDL.
- Add strict PHP session settings and database-backed session lifecycle with 30-minute idle and 12-hour absolute expiry.
- Persist only SHA-256 hashes of PHP session identifiers; validate account status and revoke sessions on logout.
- Separate failed-login throttles by account identifier and source IP without trusting forwarded IP headers.
- Add CSRF unit tests and MariaDB integration coverage for migrations and session lifecycle.
- Expand GitHub Actions to run PHP syntax checks, unit tests and MariaDB integration tests.

> This release remains under active development and is not production-ready.
