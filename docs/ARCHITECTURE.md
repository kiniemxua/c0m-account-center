# Architecture Overview

## 1. Purpose

c0m-account-center is the identity and access control hub for the c0m.me ecosystem. It provides the trusted account layer that other applications can rely on for authentication, authorization, profile state, application registration and security audit events.

The product intentionally keeps the authoritative state inside Account Center rather than allowing each connected application to invent a separate permission model.

## 2. Major layers

### Core runtime

- app/Core/bootstrap.php initializes the application, HTTPS checks, autoloading, session boot and locale initialization.
- app/Core/Database.php coordinates the single PDO connection and centralized UTC time configuration.
- app/Core/SessionManager.php maintains strict session state, revocation and idle/absolute expiry.
- app/Core/Authorization.php enforces authenticated access and permission checks.
- app/Core/Localization.php resolves the active locale from the authenticated user profile or guest cookie/session and loads the UTF-8 translation catalog.
- app/Core/RequestSecurity.php enforces HTTPS trust boundaries and ignores untrusted forwarded headers.

### Domain and policy layer

- app/Core/SuperAdminGuard.php protects the final active super administrator from being disabled or demoted in concurrent operations.
- app/Core/UserRoleManager.php manages role replacement and role assignment semantics.
- app/Core/AppConfig.php resolves the application base URL from config/app.php.
- app/Core/EmailTemplates.php centralizes localized system email content.\n- app/Core/OAuthService.php manages authorization codes, access/refresh tokens and replay detection.\n- app/Core/SubscriptionService.php and EntitlementService.php synchronize subscription lifecycle and member features/quotas.\n- app/Core/SafeWebhookHttpClient.php validates and DNS-pins outbound webhook destinations before HTTPS delivery.
- app/Core/MailService.php and mail provider implementations provide SMTP/logging abstraction.

### Controllers and routing

- public/index.php defines the HTTP routes for account, admin and installer flows.
- app/Controllers/AuthController.php handles registration, verification, password reset, login/logout and profile flows.
- app/Controllers/AdminController.php handles user management, settings and audit log review.
- app/Controllers/ApplicationRegistryController.php manages registered ecosystem applications under the `apps.manage` permission.
- app/Controllers/LanguageController.php persists the selected locale for guest or authenticated users.\n- app/Controllers/OAuthController.php implements OAuth2/OIDC, token lifecycle, discovery, JWKS and scoped UserInfo.\n- app/Controllers/AccountApiController.php and EntitlementController.php expose versioned, bearer-authenticated member profile, group, membership, permission and entitlement APIs.\n- app/Controllers/PaymentWebhookController.php verifies provider signatures and applies subscription/payment events transactionally.

### Views and UI

- app/Views contains the account, admin and authentication pages.
- app/Views/admin-applications.php renders the Super Admin registry; app/Views/dashboard.php lists active public/member applications.
- app/Views/partials/language-switcher.php provides the locale selector and UI-safe form-state preservation.
- public/assets/css/app.css contains the shared styling for the dashboard and auth pages.

### Data model

The following patterns are central to the product:

- users: account identity and status
- user_authorization_revisions: per-user monotonically increasing authorization version for downstream cache invalidation
- member_groups, user_groups and application_groups: group membership and application-specific restricted directory grants
- user_profiles: user language/timezone/profile preferences
- roles: system roles such as member, admin and super_admin
- user_roles: many-to-many role assignments
- auth_tokens: one-time tokens for email verification and password reset
- sessions: server-side authenticated session records
- audit_logs: security and admin actions
- applications: ecosystem application catalog and launch metadata
- app_settings: mutable configuration such as registration enablement\n- oauth_clients, oauth_authorization_codes, oauth_access_tokens and oauth_refresh_tokens: application credentials and OAuth lifecycle\n- subscription_plans, subscriptions and user_entitlements: plans, current member subscriptions and derived access features/quotas\n- payment_webhook_events and payment_transactions: verified, idempotently processed provider events and financial records\n- event_outbox, app_webhook_endpoints and webhook_deliveries: transactional event publication and retryable outbound delivery

## 3. Security model

- All writes are served through PHP and PDO prepared statements.
- Secret material is never committed to the repository.
- CSRF is validated for state-changing requests.
- HTTPS is required unless a trusted proxy is explicitly configured.
- Session IDs are stored only as server-side hashes.
- Sensitive account actions revoke active sessions or tokens after change.
- Super administrator protections are enforced at the database transaction layer.
- Registry writes require CSRF and `apps.manage`, validate HTTPS launch URLs and audit in the same DB transaction. A `restricted` catalog flag is not an authorization grant.
- Role replacement, account-status changes and email activation increment `user_authorization_revisions` in the same transaction as the mutation. New users resolve to revision 1 until their first authorization change.
- Group membership changes, application-group grant changes and entitlement synchronization increment the affected users' authorization revisions in the same transaction. The dashboard enforces directory visibility; downstream apps must use scoped bearer APIs and still enforce their own server-side checks.\n- OAuth tokens are opaque, stored as hashes and checked for expiry, revocation, active user and active client on every API request.\n- Payment webhook signatures and provider event IDs are verified before processing; payment state and outbox records are written in the same transaction.\n- Outbound webhooks reject private/reserved DNS destinations, pin validated public IPs, verify TLS, disable redirects/proxies and cap response bodies.

## 4. Localization model

The application supports Vietnamese and English through UTF-8 PHP arrays in lang/vi.php and lang/en.php.

Resolution order:

1. Authenticated user profile language if valid
2. Guest session locale
3. Guest cookie locale
4. Vietnamese fallback

The language switcher posts to /language and updates both the browser cookie and the server-side preference when the user is signed in.

## 5. Operational notes

- Configuration files live outside the public document root.
- The installer writes config/database.php, config/app.php and storage/install.lock atomically.
- The installer and app use a repeatable migration model so partial failures can be retried safely.
- The project is under active development and is not treated as production-ready until a live deployment review and full operational test cycle are complete.

## 6. Current product scope

The codebase already covers the core identity foundation and admin security controls described in the project README, including:

- registration and verification
- login and logout with throttling
- reset/change password flows
- role-based access control
- session management and revocation
- admin audit logs
- initial application registry CRUD and member-facing directory
- localization and installer safety hardening

OAuth2/OIDC, scoped member APIs, subscriptions, entitlements, payment webhook ingestion and transactional outbound webhook delivery are implemented. Provider-specific checkout/renewal adapters, signing-key rotation, broad rate limiting, independent protocol review and product SDK integration remain milestones.
