# Localization

## Supported locales

- `vi`: Vietnamese, system default.
- `en`: English.

The application has no framework or external translation dependency. UTF-8 PHP arrays in `lang/vi.php` and `lang/en.php` are loaded by `App\\Core\\Localization`.

## Locale resolution

1. Authenticated users: a valid `user_profiles.language` is authoritative; if the profile or value is missing/invalid, or the profile lookup fails, use `vi` rather than a stale guest cookie.
2. Guests: the session locale, then the `c0m_lang` cookie.
3. Fallback: `vi`.

The language form posts to `/language`. It uses the existing CSRF protection. The guest cookie is HttpOnly, SameSite=Lax, one year, and Secure on HTTPS. The switcher preserves ordinary non-password form fields across the language refresh; passwords, CSRF values, and one-time tokens are never copied into browser storage.

## Adding a locale

1. Add the locale code to `Localization::SUPPORTED` and keep `vi` as the system fallback.
2. Create `lang/<locale>.php` as a UTF-8 PHP array.
3. Include every key present in both current catalogs. Keep the keys identical across catalogs.
4. Add localized system email subjects and bodies to `app/Core/EmailTemplates.php`.
5. Add the locale to the language switcher.
6. Add coverage to `tests/localization.php` and run the full database-backed integration suite.

Use `\\App\\Core\\t('English source phrase', ['name' => $value])` in PHP views for user-facing static copy. Keep user-generated content, email addresses, usernames, URLs and tokens untouched. Use `EmailTemplates` for system email content; never translate token values or URLs.

## Tests

Run `php tests/localization.php` for catalog parity, UTF-8, fallback, placeholders and email copy. Run `php tests/http.php` with a disposable MariaDB/MySQL database to exercise guest/member/admin language switching and the authentication HTTP flows. The integration suite applies migrations against MariaDB/MySQL, checks the `vi` default, backfills missing legacy profile rows without overwriting existing preferences, re-runs the migration to verify idempotency, and checks that the authenticated account preference overrides a guest/session choice. The HTTP suite exercises guest, member and administrator language switching, cookie security attributes, CSRF rejection, redirect validation, and preference persistence through login/logout, and recipient-profile locale selection for password-reset email. Redirect tests include raw and percent-encoded control bytes; the controller rejects raw control bytes before URL parsing and caps return targets at 2,048 bytes. The admin audit-log screen localizes known event labels but leaves metadata, IDs and other recorded data unchanged.
