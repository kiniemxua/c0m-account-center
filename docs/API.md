# API and integration notes

## 1. Current state

The repository includes an admin-facing application registry and member dashboard directory, but does not yet expose a public REST API for external applications. OAuth/OIDC, payment webhooks and entitlement APIs are also not implemented. The current surface remains server-rendered HTML with controller-side authorization.

The current architecture keeps access control and business logic in PHP controllers and core services rather than a framework-based API layer.

## 2. HTTP patterns in use

The web application uses standard server-rendered HTML routes rather than a JSON API contract. Route patterns currently include:

- /login
- /register
- /forgot-password
- /reset-password
- /verify-email
- /dashboard
- /account
- /sessions
- /admin/users
- /admin/settings
- /admin/audit
- /admin/applications (Super Admin registry)
- /admin/applications/save (CSRF-protected create/update)
- /language

These flows validate CSRF, enforce permissions, and render localized templates.

## 3. Application registry

`GET /admin/applications` and `POST /admin/applications/save` require the `apps.manage` permission, currently granted to Super Admin. Writes validate HTTPS launch URLs, use prepared statements, and commit the registry mutation with its audit event in one transaction. Duplicate application keys return a conflict. Disabling an application preserves its registry record for future integrations.

The authenticated dashboard lists active `public` and `members` entries, and lists `restricted` entries only when the user belongs to an active group explicitly granted to that application. These grants control Account Center directory visibility only; external applications still require OAuth/API authorization and must not trust the directory decision alone.

Each user also has a `user_authorization_revisions.revision` value. It is backfilled at 1 and advances for role-set changes, group membership/grant changes, account-status changes and email activation. No external API or token currently exposes this value; future member and entitlement responses should return it so clients can detect stale authorization caches.

## 4. Security expectations for future API work

Any future API layer should follow the same base assumptions as the current codebase:

- verify CSRF for browser-based forms and secure API tokens for machine client requests
- enforce permission and scope checks server-side
- distinguish user identity from application identity
- reject untrusted forwarded headers for HTTPS detection
- use per-client rate limiting and audit logging
- maintain explicit token revocation and rotation semantics

## 5. Recommended contract shape for future API modules

When the project adds API endpoints, the recommended structure is to keep them behind an explicit version namespace, such as:

- /api/v1/auth
- /api/v1/users
- /api/v1/memberships
- /api/v1/permissions
- /api/v1/applications
- /api/v1/oauth
- /api/v1/entitlements
- /api/v1/webhooks
- /api/v1/admin

Each response should use a normalized envelope:

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "request_id": "uuid",
    "timestamp": "2026-11-15T00:00:00Z"
  }
}
```

Errors should be explicit and normalized:

```json
{
  "ok": false,
  "error": {
    "code": "validation_error",
    "message": "The request payload is invalid.",
    "details": []
  }
}
```

## 6. Integration recommendations

- Keep Account Center as the source of truth for identity, roles and entitlements.
- Treat external products as consumers, not authorities.
- Use a revision or version marker for entitlement and permission changes so downstream apps can refresh cache safely.
- Log all privileged actions and webhook processing results.
- Prevent duplicate payment or token processing via idempotency keys and transactional checks.

## 7. Existing doc references

See:

- [README.md](README.md)
- [docs/INSTALLATION.md](INSTALLATION.md)
- [docs/SECURITY.md](SECURITY.md)
- [docs/LOCALIZATION.md](LOCALIZATION.md)
