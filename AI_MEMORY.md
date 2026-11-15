# AI memory

## Project status

This workspace contains a partially mature Account Center codebase. The core identity, session, RBAC, localization and installer safety features are present and are the primary implementation area.

## Verified repository observations

- The repo root contains app/, config/, database/, docs/, lang/, public/, scripts/ and tests/.
- The feature branch is named feature/phase3-bilingual-localization, as described in the project brief.
- The web surface is server-rendered PHP. An admin-facing application registry supports Super Admin create/update/disable workflows and the dashboard lists active public/member entries.
- OAuth 2.0 Authorization Code + PKCE (S256), OpenID discovery, RS256 ID tokens/JWKS, scoped UserInfo, refresh rotation/replay detection, RFC 7009 revocation and registered-origin CORS are implemented on the feature branch. OAuth client provisioning is CLI-only at present.
- The `applications` table already exists in migration 001. `apps.manage` and `groups.manage` are Super Admin permissions. Migration 005 adds member groups, user memberships and application-specific group grants; the dashboard checks restricted access server-side.
- Migration 004 adds `user_authorization_revisions`; legacy users are backfilled to revision 1. Role-set, group membership/grant, status and email activation changes increment revisions transactionally. New users without a row resolve to revision 1 until their first change.
- The application enforces HTTPS checks unless a trusted proxy is explicitly configured.
- Locale handling is centralized in app/Core/Localization.php with Vietnamese as the system default.
- The installer has atomic config and lock writes and will refuse to overwrite an existing configuration.

## Operational constraints

- PHP 8.3.9 and MariaDB 11.4.5 are available locally. PHP tests use `DB_HOST=127.0.0.1`, `DB_PORT=3307`, and the `c0m_test` schema. The same port also has a distinct `localhost` listener, so database reset/inspection commands must explicitly use `-h 127.0.0.1` to match PDO.
- The installer test requires an empty `c0m_test` schema and now fails safely on non-empty schemas; it does not drop tables itself.
- App Registry and group access/revision behavior passed HTTP tests. After migration 005 was added, all seven test groups passed again and PHP lint passed for 62 source files. Test cleanup left no `http-registry-*`, `http-group-*` or group audit fixtures in `c0m_test`. This does not constitute a production readiness review.
- OAuth/OIDC has an initial end-to-end implementation, but needs independent protocol/security review, signing-key rotation and rate limiting. Subscription plans, entitlements, payment processing, signed webhook delivery and transactional outbox remain unfinished.
- New repeatable migrations 006–008 add OAuth client, authorization-code, consent, access/refresh token, auth_time and token-family storage. Run `php scripts/migrate.php` on existing installations before using OAuth.
- New CLI provisioning command: `php scripts/create-oauth-client.php <application-id> <public|confidential> <https-redirect-uri> [additional-https-redirect-uri...]`. Confidential secrets are displayed once; store them securely.
- The GitHub Actions run for commit `25b5ac5d5e663fcf706f195d40158aebba72c64b` passed all four PHP 8.2/8.3/8.4 + MariaDB 11.4/MySQL 8.4 jobs, including end-to-end OAuth/OIDC HTTP tests. Later CORS changes are still awaiting their own CI result; do not generalize the earlier green run to later commits.

## Recommended next steps

1. Finish browser-based OAuth client management, secret rotation, signing-key rotation and OAuth rate limiting; verify the latest CORS changes on CI.
2. Add versioned member/profile/group/permission API endpoints beyond OIDC UserInfo.
3. Implement subscription plans, subscription lifecycle and entitlement evaluation with authorization revisions.
4. Add payment provider abstraction, signed/idempotent payment webhooks, transactional outbox and retryable event delivery.
