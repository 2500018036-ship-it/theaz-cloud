# Phase 04: Drive Core

This phase implements the main Theaz Cloud workspace for authenticated users.

## Capabilities

- My Drive with folder and subfolder navigation.
- Multi-file upload into private storage outside the public webroot.
- Browser preview for supported file types and file download through
  authenticated controller responses.
- Rename, move, copy, trash, restore, permanent delete, star, and unstar.
- Per-item actions from the three-dot menu.
- Grid and list views.
- Sorting, breadcrumb navigation, name search, and pagination.
- Recent files, starred items, and trash views.
- Storage usage and quota checks before writes.
- Scheduled cleanup command for expired private temp files.

## UI/UX

- My Drive lists only direct children of the current folder.
- Folder clicks navigate into that folder; file clicks preview supported types or
  download unsupported types.
- Create folder, upload, rename, move, copy, details, trash, restore, and
  permanent delete use Bootstrap modals/offcanvas from the existing template.
- Server-side validation errors for modal operations reopen the relevant modal
  with the error shown inside it.
- Item actions are grouped in the template dropdown pattern to keep grid and list
  views compact.
- Empty state uses the shared template component and provides upload/create
  actions in My Drive.

## Storage

Original files are stored under `cloudDrive.storagePath`, defaulting to
`writable/cloud-drive`. Public URLs never point directly at stored files.
Physical filenames are random and do not use the original user-provided name.

## Database

Tables:

- `drive_items`: file/folder metadata, tree parent, physical path, trash state.
- `drive_favorites`: per-user starred items.
- `drive_shares`: user shares and optional public-link metadata.

Run migrations:

```bash
php spark migrate
```

## Routes

- `GET /drive`
- `GET /drive/recent`
- `GET /drive/starred`
- `GET /drive/trash`
- `GET /drive/shared-with-me`
- `GET /drive/shared-by-me`
- `POST /drive/folders`
- `POST /drive/upload`
- `GET /drive/items/{id}/preview`
- `GET /drive/items/{id}/download`
- `POST /drive/items/{id}/rename`
- `POST /drive/items/{id}/move`
- `POST /drive/items/{id}/copy`
- `POST /drive/items/{id}/delete`
- `POST /drive/items/{id}/restore`
- `POST /drive/items/{id}/destroy`
- `POST /drive/items/{id}/star`
- `POST /drive/items/{id}/unstar`
- `POST /drive/items/{id}/shares`
- `POST /drive/shares/{id}/permission`
- `POST /drive/shares/{id}/revoke`

## Scheduled Cleanup

Run from cron or Task Scheduler:

```bash
php spark drive:cleanup-temp --max-age=86400
```

## Verification

```bash
php spark routes
php spark filter:check GET drive
php spark filter:check POST drive/upload
php spark filter:check POST drive/items/1/delete
php spark migrate
```

Use the project PHPUnit runner, not the old global XAMPP PHPUnit binary:

```bash
composer install
vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage tests/feature/DriveCoreTest.php
```

If the PHP `sqlite3` extension is not enabled, point the `tests` database group
to an isolated MySQL database before running PHPUnit. Example for PowerShell:

```powershell
php -r "`$pdo = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', ''); `$pdo->exec('CREATE DATABASE IF NOT EXISTS storage_cloud_test CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');"
Set-Item -Path Env:database.tests.hostname -Value localhost
Set-Item -Path Env:database.tests.database -Value storage_cloud_test
Set-Item -Path Env:database.tests.username -Value root
Set-Item -Path Env:database.tests.password -Value ''
Set-Item -Path Env:database.tests.DBDriver -Value MySQLi
Set-Item -Path Env:database.tests.DBPrefix -Value ''
vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature\DriveCoreTest.php
```
