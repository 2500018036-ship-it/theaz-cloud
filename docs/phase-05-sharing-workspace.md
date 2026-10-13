# Phase 05: Sharing and Workspace

This phase adds authenticated sharing for files and folders.

## Permission Model

- Owner: the `drive_items.user_id` account. Owners can manage item metadata,
  trash/restore/destroy, and grant or revoke access.
- Viewer: can open shared folders and preview/download shared files.
- Editor: inherits viewer access and can create folders/upload files inside a
  shared folder, rename shared content, and move shared content to trash.

Folder shares are inherited by active descendants. Every endpoint resolves access
server-side from `drive_shares`; the frontend never supplies an owner ID.

## UI

- Sidebar adds `Shared with me` and `Shared by me`.
- Owner item menus show Share. Viewer menus only show view/download/details
  actions. Editor menus show allowed edit actions, but not owner-only controls.
- Share uses the existing modal pattern with recipient lookup, permission update,
  and revoke forms.
- Drive navigation and share actions are handled by the existing AJAX frame so
  the template updates without a full page reload.

## Public Links

Public file links remain token based and controller backed. Tokens are random,
can expire, and never expose the private physical path. Folder public links are
not exposed in the UI.

## Verification

```powershell
Set-Item -Path Env:database.tests.hostname -Value localhost
Set-Item -Path Env:database.tests.database -Value storage_cloud_test
Set-Item -Path Env:database.tests.username -Value root
Set-Item -Path Env:database.tests.password -Value ''
Set-Item -Path Env:database.tests.DBDriver -Value MySQLi
Set-Item -Path Env:database.tests.DBPrefix -Value ''
vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature\DriveCoreTest.php tests\feature\DriveSharingTest.php
```

Covered cases include viewer/editor/owner behavior, inherited folder access,
revoked access, trashed shared files, expired public links, and cross-user
access rejection.
