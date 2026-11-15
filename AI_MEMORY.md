# AI memory

## Project status

This workspace contains a partially mature Account Center codebase. The core identity, session, RBAC, localization and installer safety features are present and are the primary implementation area.

## Verified repository observations

- The repo root contains app/, config/, database/, docs/, lang/, public/, scripts/ and tests/.
- The feature branch is named feature/phase3-bilingual-localization, as described in the project brief.
- The web surface is server-rendered PHP. An admin-facing application registry now supports Super Admin create/update/disable workflows and the dashboard lists active public/member entries; there is still no public API or OAuth/OIDC provider.
- The `applications` table already exists in migration 001. `apps.manage` and `groups.manage` are Super Admin permissions. Migration 005 adds member groups, user memberships and application-specific group grants; the dashboard checks restricted access server-side.
- Migration 004 adds `user_authorization_revisions`; legacy users are backfilled to revision 1. Role-set, group membership/grant, status and email activation changes increment revisions transactionally. New users without a row resolve to revision 1 until their first change.
- The application enforces HTTPS checks unless a trusted proxy is explicitly configured.
- Locale handling is centralized in app/Core/Localization.php with Vietnamese as the system default.
- The installer has atomic config and lock writes and will refuse to overwrite an existing configuration.

## Operational constraints

- PHP 8.3.9 and MariaDB 11.4.5 are available locally. PHP tests use `DB_HOST=127.0.0.1`, `DB_PORT=3307`, and the `c0m_test` schema. The same port also has a distinct `localhost` listener, so database reset/inspection commands must explicitly use `-h 127.0.0.1` to match PDO.
- The installer test requires an empty `c0m_test` schema and now fails safely on non-empty schemas; it does not drop tables itself.
- App Registry and group access/revision behavior passed HTTP tests. After migration 005 was added, all seven test groups passed again and PHP lint passed for 62 source files. Test cleanup left no `http-registry-*`, `http-group-*` or group audit fixtures in `c0m_test`. This does not constitute a production readiness review.
- OAuth/OIDC, public APIs, group permissions beyond application-directory grants, entitlements, subscriptions, payment processing and signed webhook delivery remain unfinished.

## Recommended next steps

1. Implement OAuth2 Authorization Code + PKCE, client registration, consent, token rotation/revocation and OIDC signing/key rotation.
2. Expose member profiles, active groups, permissions, account status and authorization revision through a scoped API.
3. Add subscription/entitlement lifecycle, then signed payment webhook and transactional event delivery modules.
