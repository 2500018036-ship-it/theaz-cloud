# Production Audit Update - 2026-10-12

## Applied fixes

- Administrator accounts are now treated as system-management accounts, not personal storage accounts.
- `/drive` authenticated routes reject `admin` and `super_admin` roles in the backend.
- Admin quota controls now apply only to `user` accounts.
- Admin Management creates administrator accounts directly with email, username, initial password, and explicit permissions.
- The legacy admin invitation activation flow is disabled in backend code.
- File icons are centralized in `App\Libraries\FileIcon` and cover PDF, Office, image, video, audio, archive, text/code, folders, and unknown files.
- User preferences now include `locale` with English as default and Bahasa Indonesia as an alternative.
- Admin My Account Settings now hides personal drive preferences because admins do not use personal storage.
- Sidebar and header labels use CodeIgniter language files for the first localization pass.
- A visible language dropdown is available in the app topbar and on login/register pages.
- Audit log entries can be deleted by accounts with `activity_logs.manage`; each deletion writes a new audit event.
- SMTP configuration is prepared for Gmail App Password through environment variables only. No App Password is stored in source.
- `.gitignore` now excludes local environment and secret files.

## Database changes

- `2026-10-12-000001_AddLocaleToUserProfiles` adds `user_profiles.locale` with default `en`.

## Environment configuration

Use environment variables or local `.env` only:

- `email.fromEmail` / `email_fromEmail`
- `email.fromName` / `email_fromName`
- `email.protocol` / `email_protocol`
- `email.SMTPHost` / `email_SMTPHost`
- `email.SMTPPort` / `email_SMTPPort`
- `email.SMTPUser` / `email_SMTPUser`
- `email.SMTPPass` / `email_SMTPPass`
- `email.SMTPCrypto` / `email_SMTPCrypto`
- `email.SMTPTimeout` / `email_SMTPTimeout`

Gmail defaults are prepared for `muhammad.azril.achdi@gmail.com`, but the real Google App Password must be added only to local secrets.

## Verification

- PHP syntax checks passed for changed PHP files.
- PHPUnit passed with SQLite test database: `60 tests, 279 assertions`.
- Local dev server started on `http://127.0.0.1:8081`; `/health`, `/login`, `/register`, and the sidebar logo asset returned HTTP 200.
- Login and registration pages render the language switcher and submit to `/language`.
- MariaDB migration on the local XAMPP database could not be run because the local MySQL/MariaDB service refused the connection.
- SMTP sending was not executed because the Google App Password was not available in this session.

## Remaining work

- Run `php spark migrate --all` after MariaDB is started.
- Run `php spark email:test --to your-address@example.com` after setting the App Password in local secrets.
- Continue replacing hard-coded UI strings with language keys across all remaining views.
- Perform browser checks for login, registration, admin dashboard, settings, My Drive, file icons, and logo rendering after the database service is active.
