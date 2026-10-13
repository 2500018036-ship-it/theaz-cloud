# PHASE 11: User Settings, Preview, Notifications, Admin Settings

Implemented on 2026-10-11.

## Implemented

- User settings page for profile, avatar, email change request, password change, drive defaults, and notification preferences.
- Private avatar storage under `WRITEPATH/profile-avatars`, served only through authenticated backend route.
- Notification center with unread state, pagination, filters, header dropdown, mark-read, and mark-all-read actions.
- Internal email queue for verification, sharing events, and announcements. SMTP delivery worker is intentionally not implemented yet.
- System settings table and admin page for app name, timezone, admin contact, preview text limit, and maintenance mode.
- Maintenance mode enforcement in `AuthFilter` for non-admin authenticated routes.
- Admin announcements with draft, publish, archive, target all/specific user, notification channel, and email queue channel.
- Sharing notifications for grant, permission update, and revoked access.
- In-app file preview modal for images, PDF, text/JSON, audio, and video, backed by permission-checked preview metadata/text endpoints.
- Drive default view/sort preferences are applied when URL query parameters are not present.

## Database

New migration:

- `2026-10-11-000008_CreateSettingsNotificationsAndAnnouncements.php`

New tables:

- `user_profiles`
- `notifications`
- `system_settings`
- `announcements`
- `email_queue`

## Security Notes

- Notification and preview endpoints always use authenticated backend access checks.
- Text preview is capped by `max_preview_text_bytes` and does not execute file content as HTML.
- Avatar files are stored outside the webroot and validated by MIME type and image signature.
- Email verification stores a SHA-256 token hash in the profile table. Activity logs store hashes only, not raw tokens.
- Maintenance mode does not grant admin privileges; admin routes remain protected by the admin filter.

## Verification

Commands run:

```bash
php spark migrate
php spark routes
php -d extension=sqlite3 vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature
```

Result:

```text
OK (43 tests, 216 assertions)
```

Note: the local CLI PHP has `pdo_sqlite` enabled but not the native `sqlite3` extension by default. The test run used `php -d extension=sqlite3` because CodeIgniter's configured test driver requires native `sqlite3`.

## Not Implemented Yet

- Actual SMTP sending worker for `email_queue`.
- Active session listing and remote session revocation.
- External OAuth or two-factor authentication.
- Rich office document rendering beyond browser-supported preview types.
