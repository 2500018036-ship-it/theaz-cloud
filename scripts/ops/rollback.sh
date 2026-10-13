#!/bin/bash
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || { echo 'A previously deployed SHA and root are required.' >&2; exit 1; }
exec 9>/run/lock/cloud-storage-deploy.lock
flock -w 300 9
release=/mnt/data/www/cloud-storage/releases/$1
stack=/mnt/data/docker-compose/cloud-storage
[[ -f "$release/DEPLOYED" ]] || { echo 'Commit has not been successfully deployed.' >&2; exit 1; }
docker image inspect "cloud-storage-app:$1" "cloud-storage-web:$1" >/dev/null
CLOUD_DEPLOY_LOCK_HELD=1 /usr/local/libexec/cloud-storage-backup
cp -a "$stack/compose.yaml" "$stack/compose.yaml.before-rollback-$(date -u +%Y%m%dT%H%M%S)"
cp -a "$stack/release.env" "$stack/release.env.before-rollback-$(date -u +%Y%m%dT%H%M%S)"
cp "$release/docker/compose.production.yaml" "$stack/compose.yaml"
printf 'RELEASE_SHA=%s\nRELEASE_PATH=%s\n' "$1" "$release" > "$stack/release.env"
cd "$stack"
docker compose --env-file production.env --env-file release.env up -d --wait --wait-timeout 180
curl -fsS http://127.0.0.1:18080/ready >/dev/null
ln -sfn "$release" /mnt/data/www/cloud-storage/current
echo "Application rollback healthy: $1. Database remains unchanged."
