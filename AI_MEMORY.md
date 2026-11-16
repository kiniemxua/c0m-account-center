# AI memory — verified project handoff

## Project goal and scope

c0m.me Account Center is a lightweight central identity and authorization hub for the c0m ecosystem. Keep the scope frozen: finish and stabilize the implemented account, application, membership, API and entitlement flows. Do not expand it into a general-purpose Google Account replacement or add unrelated features.

## Repository and runtime

- Repository: `kiniemxua/c0m-account-center`
- Stable branch: `main`
- Stack: server-rendered PHP 8.2+, PDO with MariaDB 10.6+/MySQL 8+, vanilla JavaScript and custom CSS.
- Deployment target: cPanel/shared hosting; the domain document root must point to `public/`. Keep `config/` and `storage/` outside the public document root.
- No Node.js runtime or frontend framework is required.
- Never commit live DB, SMTP, OAuth or signing-key secrets.

## Implemented scope on main

- Installer with atomic config/lock writes, repeatable migrations and recovery after partial setup.
- Email/password registration, pending email verification, login throttling, password reset/change and security email notifications.
- Hardened server-side sessions, idle/absolute expiry, device/session listing and revocation.
- Vietnamese/English localization and member profile preferences.
- Database-backed Super Admin/Admin/Member roles, permission-checked admin routes, member management, audit events and protection against removing the final active Super Admin.
- Application registry, member groups, memberships and application-specific restricted-directory grants.
- Per-user authorization revisions for role, status, email activation, group and entitlement changes.
- OAuth 2.0 Authorization Code + PKCE, OIDC discovery, RS256 ID tokens/JWKS, consent, scoped UserInfo, opaque access/refresh tokens, refresh rotation/replay detection, token-family revocation and RFC 7009 revocation.
- Browser-based OAuth client provisioning, confidential-secret rotation and client lifecycle controls.
- Versioned member APIs for profile, groups, memberships, permissions and entitlements, plus a lightweight PHP SDK.
- Subscription plan management, subscription/entitlement synchronization, signed/idempotent payment webhook event processing, transactional outbox and retryable outbound application webhooks.
- Payment transaction lifecycle on PR #21: one current transaction row per provider/payment ID, retained webhook delivery audit records, provider event timestamp ordering, safe failed-to-succeeded retries, full-refund transitions, and transactional outbox publication. This work is not yet part of main.

## Migrations and operational commands

- Repeatable SQL migrations are in `database/migrations/`; PR #21 adds migration 013 (001–013 on that branch).
- Existing installations must run `php scripts/migrate.php` before using schema additions.
- OAuth client provisioning: `php scripts/create-oauth-client.php`.
- Signing-key rotation: `php scripts/rotate-oidc-signing-key.php`; validate the operational backup/recovery procedure before relying on rotation in production.
- Outbox dispatch: `php scripts/dispatch-outbox.php`; configure a controlled scheduler after deployment.
- See `docs/INSTALLATION.md`, `docs/API.md`, `docs/OAUTH.md`, `docs/MAIL.md` and `docs/SECURITY.md` for current instructions and limitations.

## Verified CI baseline

- Main commit `669a3808` (including PR #20) has a successful GitHub Actions run `43806927572`.
- All four matrix jobs passed: PHP 8.2 + MariaDB 11.4, PHP 8.3 + MariaDB 11.4, PHP 8.4 + MariaDB 11.4, and PHP 8.4 + MySQL 8.4.
- PR #21 head `c4df3f68` also has a successful full CI run `43808554546`; all four matrix jobs passed, including syntax, unit, SDK, localization/email, SMTP, installer recovery, database integration, RBAC concurrency and HTTP router tests. PR #21 remains open and has not been merged into main.
- CI verifies the automated suite only. It does not prove cPanel filesystem behavior, live SMTP deliverability, real payment-provider integration, backup/restore, browser E2E or production security.

## Remaining production gates (not feature expansion)

1. Run clean-install and upgrade/retry flows on a real staging cPanel account with the intended PHP and database versions.
2. Verify SMTP with the actual provider, including TLS/implicit SSL, deliverability and SPF/DKIM/DMARC.
3. Exercise browser journeys: register, verify, login/logout, reset password, session expiry/revocation, admin member/role changes and OAuth consent/token lifecycle.
4. Validate scheduler/outbox delivery, webhook retries and the actual payment provider's checkout/renewal/failure/refund/cancellation lifecycle. The normalized payment webhook lifecycle is implemented, but provider-specific checkout/recurring-billing adapters, partial refunds and reconciliation remain incomplete.
5. Test database/filesystem backup and restore, signing-key backup/recovery and operational audit review.
6. Complete independent OAuth/OIDC protocol and security review, plus broader abuse monitoring/rate limiting.
7. Confirm every connected product validates tokens and enforces scopes, groups, permissions and entitlements server-side.

Do not label the project production-ready based on CI alone. Do not accept real member accounts or financial activity until the applicable gates are completed.

## Working rules

- Keep changes small and tied to a verified defect or a production-readiness gap in existing scope.
- Prefer read-only inspection before modifying data or deployment configuration.
- Preserve existing work; do not reset user data or deploy to production without explicit instruction.
- Routine commits/pushes and PRs on feature branches are authorized; merging to main and production deployment still require explicit authorization.
- Do not revive parked work or add new product scope just to increase commit count.
