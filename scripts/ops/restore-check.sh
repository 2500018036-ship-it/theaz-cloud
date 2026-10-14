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
python3 - "$backup/production.env" "$scratch/restore.env" <<'PY'
import pathlib,re,secrets,sys
source=pathlib.Path(sys.argv[1]).read_text()
match=re.search(r'^MARIADB_USER=(.*)$',source,re.M)
user=match.group(1).strip().strip('\"\'') if match else 'cloud_storage'
if not re.fullmatch(r'[A-Za-z0-9_]+',user): raise SystemExit('Unsupported application database account name.')
# Recreate the dump's DEFINER account with a fresh isolated password, never production credentials.
with pathlib.Path(sys.argv[2]).open('a') as target:
 target.write('MARIADB_USER='+user+'\nMARIADB_PASSWORD='+secrets.token_hex(32)+'\n')
PY
docker run -d --name "$container" --network none --env-file "$scratch/restore.env" -v "$scratch/database:/var/lib/mysql" mariadb:11.4 >/dev/null
trap 'docker stop "$container" >/dev/null' EXIT
ready=0
for attempt in {1..300}; do
    if docker exec "$container" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then ready=1; break; fi
    sleep 2
done
[[ "$ready" == 1 ]] || { echo 'Isolated restore database did not become ready within ten minutes.' >&2; exit 1; }
docker exec -i "$container" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root restore_check' < "$backup/database.sql"
docker exec "$container" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root --batch --skip-column-names restore_check -e "SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS items FROM drive_items;"'
if grep -q users_protect_owner_update "$backup/database.sql"; then
    if docker exec "$container" sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -u root restore_check -e "START TRANSACTION; UPDATE users SET status = '\''inactive'\'' WHERE role = '\''super_admin'\''; ROLLBACK;"' > "$scratch/owner-protection.log" 2>&1; then
        echo 'Restored primary-owner protection failed.' >&2; exit 1
    fi
    grep -q 'Primary administrator' "$scratch/owner-protection.log"
    echo 'PASS: restored database rejects deactivation of the permanent owner.'
fi
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
