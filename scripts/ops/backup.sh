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
    backup_status=$?
    trap - EXIT
    # Recovery must run even when the backup itself failed, and its failure must
    # turn a successful archive operation into a failed systemd job.
    set +e
    recovery_status=0
    restore_services=()
    for service in app web mail_worker; do
        if [[ " $running " == *" $service "* ]]; then restore_services+=("$service"); fi
    done
    if (( ${#restore_services[@]} )); then
        "${compose[@]}" start --wait --wait-timeout 180 "${restore_services[@]}" || recovery_status=1
    fi
    if [[ " $running " == *' web '* ]]; then
        curl --fail --silent --show-error --max-time 10 --retry 5 --retry-all-errors \
            --retry-delay 3 --retry-max-time 75 http://127.0.0.1:18080/ready >/dev/null || recovery_status=1
    fi
    if (( recovery_status )); then
        echo 'ERROR: Cloud Storage recovery failed after backup; inspect container health and /ready.' >&2
        if (( backup_status == 0 )); then backup_status=1; fi
    else
        echo 'Cloud Storage recovery verified for services that were running before backup.'
    fi
    exit "$backup_status"
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
