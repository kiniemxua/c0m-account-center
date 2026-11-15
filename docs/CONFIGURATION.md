# Configuration reference

## Language defaults and preferences

Account Center supports Vietnamese (vi) and English (en) without external services. The existing audit log is available at `/admin/audit` to accounts with the `audit.view` permission.

- The system fallback is vi.
- New profile rows default to vi through database/migrations/003_account_preferences.sql.
- Existing valid user_profiles.language values are not overwritten by the migration.
- Authenticated requests use the language saved in the account profile.
- Guest choices are stored in the PHP session and the c0m_lang cookie (one year, HttpOnly, SameSite=Lax, Secure on HTTPS).
- Email templates use the recipient's saved profile language. Unknown or unsupported values fall back to Vietnamese.

Do not add a separate language setting to config/app.php; supported locales are code/catalog configuration. Keep all user-facing UI keys in both lang/vi.php and lang/en.php. The localization test checks catalog parity and statically referenced keys in views, controllers and installer.

## Adding another language

See LOCALIZATION.md. A new locale requires a complete translation catalog, registration in Localization::SUPPORTED, a switcher option, translated system-email templates and tests. Do not translate user-generated content, usernames, email addresses, URLs, tokens or other technical values.

## Database migration

Run the normal ordered migration process. Do not delete or recreate the database to change the default language. Migration 003 changes the column default only; it does not update existing account preference values.
