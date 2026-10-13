# Theaz Cloud operations

## Layout and access

Public URL: `https://cloud.azrilachdi.web.id`. Azure Nginx terminates TLS and routes through WireGuard to Debian Nginx Proxy Manager, then to `cloud-storage-web:80` on the existing `web-proxy` network.

| Purpose | Debian path |
|---|---|
| Bare source repository and immutable releases | `/mnt/data/www/cloud-storage` |
| Current source | `/mnt/data/www/cloud-storage/current` |
| Compose, private environment, release pointer | `/mnt/data/docker-compose/cloud-storage` |
| User files and versions | `/mnt/data/storage/cloud-storage` |
| MariaDB data | `/mnt/data/docker-compose/cloud-storage/data/mysql` |
| Sessions, cache, logs, avatars | Docker volume `cloud-storage_writable` |
| Consistent backups | `/mnt/data/backups/cloud-storage` |

Only web port `127.0.0.1:18080` is published for local diagnostics. MariaDB and PHP-FPM have no host ports. The existing proxy manager, `website-test`, `test.local`, and the Azure Nextcloud vhost remain separate services.

Run operational commands as root using `sudo`. Never run `docker compose down -v`, `migrate:refresh`, `migrate:rollback`, or volume pruning against production. Do not print `production.env`, run unfiltered `docker inspect`, or print rendered Compose configuration because they contain secrets. Use `docker compose config --quiet` for validation.

## Status, login and settings

```bash
cd /mnt/data/docker-compose/cloud-storage
sudo docker compose --env-file production.env --env-file release.env ps
curl --fail https://cloud.azrilachdi.web.id/ready
sudo docker compose --env-file production.env --env-file release.env exec -T app php spark migrate:status
```

`/health` is liveness. `/ready` checks database connectivity, the migrated users table and writable persistent directories. Neither endpoint returns secrets or infrastructure details.

Initial MariaDB setup on the persistent disk took about five minutes in the audited server. Deployment allows up to ten minutes for database health, including retries after an interrupted first initialization. It waits for MariaDB before backup, and the strict migration command refuses to proceed with an incomplete schema. An unsuccessful first release stops the application's web/PHP services while preserving data; automatic rollback applies only to a previously successful release.

Initial super administrator: username `azril`, email `muhammad.azril.achdi@gmail.com`. Its initial password is generated privately. Set your own password in a terminal using the hidden-input script; no password goes in command arguments or chat:

```bash
sudo /usr/local/libexec/cloud-storage-admin-password
```

Sign in, create a one-use user invitation in the admin dashboard, and register a separate personal storage account. Administrator accounts manage the system and do not have personal drives. Users cannot select their own role or quota. Invite tokens are hashed, expire, and have atomic use limits. Quota reservations are atomic in MariaDB. File bytes remain outside the webroot; downloads and sharing enforce access checks in the backend.

The development rollout sets `EMAIL_VERIFICATION_DEFERRED=true`; invite registration activates users without SMTP. The database verification setting is preserved. To enable verification later, configure/test SMTP, enable outbound connectivity for the app's internal Docker network, start the mail queue worker, back up `production.env`, then set `EMAIL_VERIFICATION_DEFERRED=false` and recreate the app. Configure email environment variables explicitly in the production Compose file. Do not enable verification before successful email delivery.

The active framework comes from Composer (`codeigniter4/framework` 4.7.5 or later, locked by `composer.lock`). The legacy repository `system/` tree is retained as source history, excluded from Docker images and unused by PHP or PHPUnit.

## CI/CD and trusted production

`.github/workflows/production.yml` runs on GitHub-hosted Ubuntu runners. It builds images, checks PHP syntax and Composer dependencies, runs PHPUnit on SQLite and MariaDB, migrates a new database, starts the production image and performs HTTP registration/login/folder/upload/download/isolation/quota tests. Tests on pull requests have no production secrets or server access.

The deployment job runs only for this repository's `production` branch after `verify` succeeds. The GitHub `production` environment permits only that branch and stores the encrypted deployment private key and pinned Azure host keys. Deployment uses existing Azure SSH, then a persistent reverse SSH tunnel from Debian whose Azure listener binds only `127.0.0.1:2222`. Direct Azure-to-Debian SSH timed out; this outbound tunnel avoids changing the existing network firewall or opening Debian SSH to the internet. HTTPS traffic still uses WireGuard.

Both deployment accounts have locked passwords, forced commands and disabled forwarding/PTY. The Debian account has sudo access only to a root-owned deployment dispatcher, not Docker or a general shell. The dispatcher accepts one 40-character SHA, verifies it is the current production head with a successful GitHub Actions `verify` check, fetches only the configured repository, builds commit-tagged images, backs up existing data/configuration, runs migrations, and requires healthy containers. Rejected commands cannot execute arbitrary shell text. Host keys are pinned; do not disable host-key checks.

The deployment job passes its temporary repository-scoped `GITHUB_TOKEN` over encrypted SSH standard input. Only `contents: read` and `checks: read` are granted; the server uses it for branch/check verification, then removes it before builds. It is never written to disk or passed in command arguments. This avoids the shared public-IP GitHub API rate limit without adding a permanent API secret. Public HTTPS verification retries for up to five minutes per page so a backup waiting on the deployment lock can finish before the job checks readiness. See [GitHub's token security documentation](https://docs.github.com/en/actions/concepts/security/github_token).

Promote a tested commit by merging a reviewed change into `production`. Branch protection requires the `verify` check and prevents force pushes/deletion. Keep schema migrations additive so the prior application image remains compatible. Never deploy pull-request code or grant runner jobs access to the Docker socket on the server.

Deployment history: `/mnt/data/docker-compose/cloud-storage/deployments.log`. Images and source releases are retained for rollback. Existing image tags for a SHA are reused rather than overwritten. Root-owned dispatchers are installed manually from reviewed scripts under `scripts/ops`; ordinary application deployments do not replace them.

## Backups and recovery

The systemd timer runs daily around **02:30 Asia/Jakarta**. Backup stops only this application's web/app services briefly, leaves MariaDB running, makes a transactional SQL dump, archives user file bytes/versions and avatars, copies private Compose/environment configuration, records file hashes, and resumes the services even on failure. A deployment lock prevents a scheduled backup from racing migrations. Failed backup directories have no `COMPLETE` marker.

```bash
sudo systemctl list-timers cloud-storage-backup.timer
sudo systemctl start cloud-storage-backup.service
sudo journalctl -u cloud-storage-backup.service --since today
sudo /usr/local/libexec/cloud-storage-backup
```

Backup files are root-only and are not deleted automatically. They are on the same data disk: arrange an encrypted copy to a separate machine/disk for disaster recovery, monitor capacity, and set a reviewed retention policy. SQL includes password hashes; environment backups include secrets. Never attach them to issues or upload them unencrypted to a public service.

Verify a completed backup without touching production:

```bash
sudo /usr/local/libexec/cloud-storage-restore-check /mnt/data/backups/cloud-storage/<completed-backup>
```

This checks archive hashes, waits up to ten minutes for the isolated database to initialize on the persistent disk, restores SQL into a new MariaDB container with no network, restores file archives into a new directory, and verifies every stored file's SHA-256. SQL is imported only after database readiness succeeds. The test container is stopped and evidence retained. It never imports into production or overwrites production files. Remove only explicitly reviewed restore-test artifacts after inspection.

Application rollback:

```bash
sudo /usr/local/libexec/cloud-storage-rollback <previous-successful-40-character-SHA>
```

This creates a fresh backup, restores the prior Compose/image release, waits for health, and preserves current database/file contents. A failed deploy attempts the same application rollback automatically. Database rollback is a separate recovery operation: select a consistent SQL+files+writable+configuration backup, verify it in isolation, agree on downtime and loss of changes since that backup, then restore all components together into new paths before switching production. Never reverse database migrations blindly.

## HTTPS and upload limits

Azure's cloud vhost and NPM's cloud proxy accept 136 MB request bodies; PHP accepts a 128 MB file / 136 MB POST; the default application maximum is 100 MB per file. Raise all three layers together if policy changes. TLS redirects are enforced by Azure. Session cookies are Secure, HttpOnly, SameSite=Lax. Only the audited NPM address is trusted by the app. NPM preserves HTTPS headers only from the Azure WireGuard source; Azure overwrites forwarding headers from public clients.

NPM's cloud proxy configuration and SQLite database, plus Azure's cloud vhost, are backed up before edits. Restore just that cloud host through NPM/API if necessary; avoid restoring the entire NPM database over changes to other hosts. Always run `nginx -t` before reload and confirm `test.local` and the separate Nextcloud domain after proxy edits.

## Deep HTTP verification

```bash
cd /mnt/data/docker-compose/cloud-storage
sudo python3 /mnt/data/www/cloud-storage/current/scripts/smoke.py \
  --url https://cloud.azrilachdi.web.id --compose-dir "$PWD" \
  --env production.env --env release.env
```

The test creates dedicated random `smoke_<id>_*` accounts and invitations, uses private tmpfs credentials, verifies downloads by SHA-256, checks quota and access controls, then suspends the generated accounts and removes their credential file. Small test file/folder records are retained for audit; production user data is unchanged. On interrupted tests, identify those exact test accounts through admin and suspend them; never delete unrelated accounts.

Email delivery and high-concurrency load are outside this deferred-email rollout. Review PHP/MariaDB logs and RAM/disk usage regularly. File-session storage assumes one app instance; use shared session/cache storage before scaling replicas. Rotate dedicated deployment keys by replacing GitHub environment secrets and corresponding restricted public keys together; keep private values out of logs.
