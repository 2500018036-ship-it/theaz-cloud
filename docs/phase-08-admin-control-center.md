# Phase 08: Admin Control Center

## Dashboard

Admin dashboard is backed by real database and filesystem-safe metrics:

- user counts by status and role
- file, folder, trash, and retained-version counts
- assigned quota usage from `users.storage_used_bytes`
- private storage disk capacity from PHP disk APIs
- recent activity and recent failure count
- quick user storage quota upgrade controls
- database, storage, application, and PHP health information

No simulated metrics are shown as real values.

## User Management

Routes:

- `GET /admin/users`
- `GET /admin/users/{id}`
- `POST /admin/users/{id}/status`
- `POST /admin/users/{id}/role`
- `POST /admin/users/{id}/quota`

Features:

- paginated user list
- search by username/email
- role and status filters
- user detail page
- status update: `active`, `inactive`, `suspended`
- role update: `super_admin`, `admin`, `user` by super admins only
- quota management
- quick quota upgrades are also available from the admin dashboard
- protection against deactivating or demoting the current/last active admin and last active super admin
- activity logging for admin changes

## Invitations

Routes:

- `GET /admin/invitations`
- `POST /admin/invitations`
- `POST /admin/invitations/{id}/update`
- `POST /admin/invitations/{id}/revoke`

Features:

- paginated invitation list
- search and status filter
- create invitation code
- update label, max usage, and expiration
- revoke active invitation
- plaintext invitation codes are only shown once after creation
- code hashes remain the stored persistent form

## Storage

Route:

- `GET /admin/storage`

Features:

- quota usage from database counters
- private storage path writability
- disk free/total capacity from PHP disk APIs
- user storage usage list with pagination
- file and retained-version counts

The storage page does not scan every file on disk on each request.

## Audit

Route:

- `GET /admin/audit`

The audit view reuses the Phase 07 activity log with pagination and filters. Admin operations are recorded with actor, status, target, and sanitized metadata.

## Asynchronous Admin UI

`public/assets/js/admin-ui.js` progressively enhances admin links and forms:

- navigation
- search/filter forms
- pagination
- role/status/quota changes
- invitation create/update/revoke

Server-side authorization remains mandatory; async behavior is only a UX layer.

## Verification

```powershell
php spark routes
vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature
```
