# Phase 03: Admin Invitation Management

This phase connects invitation code management to the administrator interface.

## Routes

- `GET /admin/invitations`
- `POST /admin/invitations`
- `POST /admin/invitations/{id}/revoke`

## Capabilities

- Admins can create invitation codes from the web UI.
- Plaintext codes are displayed once after creation and only the SHA-256 hash is stored.
- Admins can revoke active invitations.
- Expired active invitations are marked as expired before dashboard and list rendering.
- The admin dashboard now shows user, invitation, and storage usage summaries.

## Verification

```bash
php spark routes
php spark filter:check get admin/invitations
php phpunit-10.phar --configuration phpunit.xml.dist --no-coverage tests/feature/AuthFlowTest.php tests/feature/AdminInvitationFlowTest.php
```
