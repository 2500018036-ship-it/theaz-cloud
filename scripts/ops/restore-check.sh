#!/bin/bash
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 ]] || { echo 'Root and a completed backup directory are required.' >&2; exit 1; }
backup=$(realpath "$1")
[[ "$backup" == /mnt/data/backups/cloud-storage/* && -f "$backup/COMPLETE" ]] || { echo 'Invalid backup path.' >&2; exit 1; }
sha256sum -c "$backup/SHA256SUMS" >/dev/null
scratch=$(mktemp -d /mnt/data/backups/cloud-storage/restore-check.XXXXXXXX)
container=cloud-storage-restore-check-$(date -u +%s)
password=$(openssl rand -hex 32)
printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_DATABASE=restore_check\n' "$password" > "$scratch/restore.env"
unset password
docker run -d --name "$container" --network none --env-file "$scratch/restore.env" -v "$scratch/database:/var/lib/mysql" mariadb:11.4 >/dev/null
trap 'docker stop "$container" >/dev/null' EXIT
for attempt in {1..60}; do
    if docker exec "$container" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
    sleep 2
done
docker exec -i "$container" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root restore_check' < "$backup/database.sql"
docker exec "$container" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root --batch --skip-column-names restore_check -e "SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS items FROM drive_items;"'
mkdir "$scratch/files" "$scratch/writable"
python3 - "$backup" "$scratch" <<'PY'
import hashlib,json,pathlib,sys,tarfile
backup,scratch=map(pathlib.Path,sys.argv[1:])
for archive,destination in [('files.tar.gz','files'),('writable.tar.gz','writable')]:
 with tarfile.open(backup/archive) as source: source.extractall(scratch/destination,filter='data')
manifest=json.loads((backup/'storage-sha256.json').read_text())
for relative,expected in manifest.items():
 target=(scratch/'files'/relative).resolve()
 if not target.is_relative_to((scratch/'files').resolve()): raise SystemExit('Unsafe archive path.')
 if hashlib.file_digest(target.open('rb'),'sha256').hexdigest()!=expected: raise SystemExit('Restored file integrity failed.')
print('PASS: isolated database restore and all stored-file SHA-256 hashes; production untouched.')
PY
echo "Restore evidence retained securely: $scratch (container will be stopped)."
