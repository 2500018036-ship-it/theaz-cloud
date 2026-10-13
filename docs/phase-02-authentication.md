# Phase 02: Authentication, Invitations, and RBAC

This phase adds invite-only registration, login/logout, role-based admin access,
and CLI-controlled bootstrap flows. It does not implement file manager features.

## Migrations

```bash
php spark migrate
```

Tables:

- `users`
- `invitation_codes`

## Bootstrap Super Admin

Set secrets outside source control, then run:

```bash
AUTH_BOOTSTRAP_ADMIN_EMAIL=admin@example.test \
AUTH_BOOTSTRAP_ADMIN_USERNAME=admin \
AUTH_BOOTSTRAP_ADMIN_PASSWORD='change-this-long-password' \
php spark auth:bootstrap-admin
```

If the email already exists, the command exits without silently changing role or
password. If the existing account is not `super_admin`, it fails.

## Create Invitation Code

```bash
php spark invitation:create --label "First user" --max-uses 1 --expires-at "2026-12-31 23:59:59"
```

The plaintext code is printed once. Only the SHA-256 hash is stored.

## Routes

- `GET /login`
- `POST /login`
- `GET /register`
- `POST /register`
- `POST /logout`
- `GET /dashboard`
- `GET /admin/dashboard`

## Security Notes

- Registration always creates `role = user`.
- Login reads role from the database, never from form input.
- Session ID is regenerated after successful login.
- `POST` requests use CodeIgniter CSRF protection.
- Login attempts are throttled by IP address and identifier.
- Admin routes are protected by the `admin` filter.
- Invitation consumption is atomic and guarded by `use_count < max_uses`.

## Implemented Files

Migrations:

- `app/Database/Migrations/2026-10-11-000001_CreateUsersTable.php`
- `app/Database/Migrations/2026-10-11-000002_CreateInvitationCodesTable.php`

Models and services:

- `app/Models/UserModel.php`
- `app/Models/InvitationCodeModel.php`
- `app/Libraries/AuthService.php`
- `app/Libraries/InvitationService.php`

Controllers, filters, and commands:

- `app/Controllers/AuthController.php`
- `app/Controllers/DashboardController.php`
- `app/Controllers/Admin/DashboardController.php`
- `app/Filters/AuthFilter.php`
- `app/Filters/AdminFilter.php`
- `app/Filters/GuestFilter.php`
- `app/Commands/BootstrapAdmin.php`
- `app/Commands/CreateInvitation.php`

Views:

- `app/Views/layouts/auth.php`
- `app/Views/auth/login.php`
- `app/Views/auth/register.php`
- `app/Views/dashboard/user.php`
- `app/Views/admin/dashboard.php`
- `app/Views/errors/html/error_403.php`

## Verification

Commands run during implementation:

```bash
composer validate --no-check-publish
php spark routes
php spark filter:check get admin/dashboard
php spark filter:check get dashboard
php spark filter:check post login
php spark migrate
php spark auth:bootstrap-admin
php spark invitation:create --label phase02-smoke --created-by-email admin.phase02@example.test --max-uses 1
php phpunit-10.phar --configuration phpunit.xml.dist --no-coverage tests/feature/AuthFlowTest.php
```

Automated test result:

- `AuthFlowTest`: 14 tests, 46 assertions, all passing.

Covered scenarios:

- Valid and invalid login.
- Email or username login.
- Inactive account rejection.
- Session regeneration and logout cleanup.
- Login rate limiting.
- Invalid and expired invitation codes.
- One-time invitation consumption.
- Duplicate username and email rejection.
- Password confirmation mismatch.
- Registration without SMTP.
- Registration cannot choose admin role.
- Admin dashboard access for `admin` and `super_admin` roles only.
- Guest redirect and user access denial for admin routes.
