# Phase 09: Security, Role Management, Dynamic System Settings, and Storage Monitoring

## Role Model

Supported roles:

- `super_admin`
- `admin`
- `user`

`super_admin` has every admin permission. Standard `admin` accounts keep day-to-day operational permissions, but cannot manage other admins, change account roles, or update system settings unless a specific backend permission is granted.

The bootstrap command now creates a `super_admin` account. The backend prevents self-demotion, self-deactivation, and removal of the last active super admin.

## Admin Management

Routes:

- `GET /admin/management`
- `POST /admin/management/invitations`
- `POST /admin/management/invitations/{id}/resend`
- `POST /admin/management/invitations/{id}/revoke`
- `GET /admin-invitations/activate/{token}`
- `POST /admin-invitations/activate/{token}`

Only `super_admin` users can create and revoke admin invitations. Admin invitations are email-bound, one-use, expirable, and store only the hashed invitation code. The raw activation token is shown once after creation.
Because the raw token is not stored, resending an invitation regenerates a fresh activation link, revokes the previous invitation, and shows the new link once.

Accepted admin invitations create an `admin` account, apply the invitation quota, mark the invite consumed, and store `accepted_user_id`/`accepted_at` metadata.

## Dynamic Settings

`system_settings` now stores:

- `value_type`
- `category`
- `metadata_json`

Settings are grouped by general, registration, storage, security, notifications, and maintenance categories. Runtime code now reads dynamic values for login attempt limits, password minimum length, default user quota, max upload size, version retention, storage warning threshold, preview limit, and maintenance mode.

Admin Settings are separated from User Settings. Admin settings require backend authorization and ordinary users cannot reach them by URL.

## Invitation Quotas

User invitations may define `storage_quota_bytes`. Registration applies this quota from the accepted invite; otherwise it falls back to `default_user_quota_bytes`.

Admin UI validates assignable invitation quota against `max_admin_assignable_quota_bytes`.
The same policy is applied when admins update a user's storage quota from the Users page, User Detail, or Admin Dashboard quick quota controls.

## Storage Monitoring

Admin Storage remains monitoring-only. It distinguishes:

- filesystem disk total/free/used bytes from PHP disk APIs
- app-managed bytes from drive metadata and retained versions
- allocated quota from `users.storage_quota_bytes`
- actual per-user usage from `users.storage_used_bytes`

The dashboard does not scan all physical files on each request. Storage usage continues to be updated by drive operations.

## Activity Logging

Admin and security-sensitive operations record activity entries for:

- permission denial
- admin invitation create/revoke/accept
- user role/status/quota changes
- system settings and maintenance updates

Logs avoid passwords, raw tokens, and file contents.

## Verification

```powershell
php spark migrate
php spark routes
php -d extension=sqlite3 vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature
```
