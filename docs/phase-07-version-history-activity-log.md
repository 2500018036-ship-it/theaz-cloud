# Phase 07: Version History and Activity Log

## Version History

- Version history is implemented for file content updates only.
- Metadata-only actions such as rename, move, star, and share do not create file versions.
- A new version is created by opening a file action menu and choosing `Versions`, then uploading a replacement file.
- Replacement files must use the same extension as the current file.
- Previous file content is retained in private storage and referenced by `drive_file_versions`.
- Restore swaps the selected historical content back into the active `drive_items` row and archives the pre-restore content as another version.
- Version files are never stored as database BLOBs and remain outside the public webroot.
- Retained version bytes count against the file owner's storage quota.
- Retention is controlled in `Config\CloudDrive`:
  - `maxVersionsPerFile`
  - `versionRetentionDays`
- Retention runs during version upload/restore and can also be run manually:

```powershell
php spark drive:versions:prune
```

## Activity Log

Activity is stored in `activity_logs` with:

- actor
- optional subject user
- action
- target type/id/owner/name
- status
- IP address and user agent
- sanitized metadata
- timestamp

Sensitive metadata keys such as password, token, cookie, authorization, secret, and csrf are redacted before storage.

Logged events include:

- login and logout
- upload and download
- folder create
- rename, move, copy, trash, restore, permanent delete
- share grant, permission update, revoke
- public link create, revoke, and public download
- bulk download
- version upload, restore, and prune
- admin invitation create and revoke
- failed drive operations and relevant security failures

## UI

- User activity page: `GET /drive/activity`
- Admin audit page: `GET /admin/audit`
- File version page: `GET /drive/items/{id}/versions`
- Upload new version: `POST /drive/items/{id}/versions`
- Restore version: `POST /drive/items/{id}/versions/{versionId}/restore`

Activity pages use pagination and filters. Version restore/upload forms are handled by the existing drive asynchronous navigation layer.

## Permissions

- Viewer can view version history metadata for files they can access.
- Editor and owner can upload and restore versions.
- Owner sees activity for owned targets.
- Users see activity where they are actor, subject, or target owner.
- Admin sees the full audit log.

## Verification

```powershell
php spark migrate
vendor\bin\phpunit --configuration phpunit.xml.dist --no-coverage tests\feature
```
