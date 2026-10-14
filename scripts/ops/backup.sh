#!/bin/bash
set -Eeuo pipefail
umask 077
[[ $EUID == 0 ]] || { echo 'Root access required.' >&2; exit 1; }
if [[ "${CLOUD_DEPLOY_LOCK_HELD:-}" != 1 ]]; then
    exec 9>/run/lock/cloud-storage-deploy.lock
    flock -w 300 9
fi
exec 8>/run/lock/cloud-storage-backup.lock
flock -w 300 8
stack=/mnt/data/docker-compose/cloud-storage
backup=/mnt/data/backups/cloud-storage/$(date -u +%Y%m%dT%H%M%S)-$RANDOM
mkdir -p "$backup"
cd "$stack"
compose=(docker compose --env-file production.env --env-file release.env)
"${compose[@]}" up -d db --wait --wait-timeout 600 >/dev/null
running=$(${compose[@]} ps --status running --services | tr '\n' ' ')
resume() {
    if [[ " $running " == *' app '* ]]; then "${compose[@]}" start app >/dev/null; fi
    if [[ " $running " == *' web '* ]]; then "${compose[@]}" start web >/dev/null; fi
    if [[ " $running " == *' mail_worker '* ]]; then "${compose[@]}" start mail_worker >/dev/null; fi
}
trap resume EXIT
"${compose[@]}" stop web app >/dev/null
if [[ " $running " == *' mail_worker '* ]]; then "${compose[@]}" stop mail_worker >/dev/null; fi
"${compose[@]}" exec -T db sh -c 'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump --single-transaction --routines --triggers --events -u "$MARIADB_USER" "$MARIADB_DATABASE"' > "$backup/database.sql"
tar -czf "$backup/files.tar.gz" -C /mnt/data/storage/cloud-storage .
python3 - "$backup/storage-sha256.json" <<'PY'
import hashlib,json,pathlib,sys
root=pathlib.Path('/mnt/data/storage/cloud-storage')
manifest={str(p.relative_to(root)):hashlib.file_digest(p.open('rb'),'sha256').hexdigest() for p in root.rglob('*') if p.is_file()}
pathlib.Path(sys.argv[1]).write_text(json.dumps(manifest))
PY
docker run --rm --network none -v cloud-storage_writable:/source:ro -v "$backup:/backup" --entrypoint sh cloud-storage-app:$(sed -n 's/^RELEASE_SHA=//p' release.env) -c 'cd /source; set -- uploads; if [ -d profile-avatars ]; then set -- "$@" profile-avatars; fi; tar -czf /backup/writable.tar.gz "$@"'
cp -a production.env release.env compose.yaml "$backup/"
if [ -f .env ]; then cp -a .env "$backup/"; fi
checksum_files=("$backup/database.sql" "$backup/files.tar.gz" "$backup/writable.tar.gz" "$backup/storage-sha256.json" "$backup/production.env" "$backup/release.env" "$backup/compose.yaml")
if [[ -f "$backup/.env" ]]; then checksum_files+=("$backup/.env"); fi
sha256sum "${checksum_files[@]}" > "$backup/SHA256SUMS"
touch "$backup/COMPLETE"
echo "Consistent database/files backup: $backup"
