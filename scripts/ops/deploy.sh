#!/bin/bash
set -Eeuo pipefail
umask 077
[[ $EUID == 0 && $# == 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || { echo 'A commit SHA and root access are required.' >&2; exit 1; }
sha=$1
exec 9>/run/lock/cloud-storage-deploy.lock
flock -w 1200 9
repo=https://github.com/2500018036-ship-it/theaz-cloud.git
root=/mnt/data/www/cloud-storage
stack=/mnt/data/docker-compose/cloud-storage
release="$root/releases/$sha"
python3 - "$sha" <<'PY'
import json,sys,urllib.request
base='https://api.github.com/repos/2500018036-ship-it/theaz-cloud'
def get(path):
 req=urllib.request.Request(base+path,headers={'User-Agent':'cloud-storage-deploy','Accept':'application/vnd.github+json'})
 with urllib.request.urlopen(req,timeout=30) as r: return json.load(r)
sha=sys.argv[1]
head=get('/git/ref/heads/production')['object']['sha']
if head != sha: raise SystemExit('Refusing stale or untrusted branch commit.')
checks=get('/commits/'+sha+'/check-runs')['check_runs']
if not any(c['name']=='verify' and c['conclusion']=='success' and c['app']['slug']=='github-actions' for c in checks):
 raise SystemExit('Successful GitHub Actions verification is required.')
print('Trusted production branch and successful CI verified.')
PY
mkdir -p "$root/releases" "$stack"
if [[ ! -d "$root/repository.git" ]]; then git init --bare "$root/repository.git" >/dev/null; fi
git --git-dir="$root/repository.git" -c core.hooksPath=/dev/null fetch --no-tags "$repo" production >/dev/null
[[ $(git --git-dir="$root/repository.git" rev-parse FETCH_HEAD) == "$sha" ]] || { echo 'Production changed; retry the newer commit.' >&2; exit 1; }
if [[ ! -d "$release" ]]; then
    mkdir "$release"
    git --git-dir="$root/repository.git" archive "$sha" | tar -x -C "$release"
fi
candidate=$(mktemp -d "$stack/candidate.XXXXXXXX")
printf 'RELEASE_SHA=%s\nRELEASE_PATH=%s\n' "$sha" "$release" > "$candidate/release.env"
cp "$release/docker/compose.production.yaml" "$candidate/compose.yaml"
build=(docker compose --project-name cloud-storage --env-file "$stack/production.env" --env-file "$candidate/release.env" -f "$candidate/compose.yaml")
"${build[@]}" config --quiet
if ! docker image inspect "cloud-storage-app:$sha" "cloud-storage-web:$sha" >/dev/null 2>&1; then
    "${build[@]}" build app web
fi
previous=$(mktemp -d "$stack/previous.XXXXXXXX")
if [[ -f "$stack/release.env" ]]; then
    cp -a "$stack/release.env" "$stack/compose.yaml" "$previous/"
    # Initial database creation on the persistent disk can outlast a failed first deploy.
    docker compose --env-file "$stack/production.env" --env-file "$stack/release.env" -f "$stack/compose.yaml" up -d db --wait --wait-timeout 600
    CLOUD_DEPLOY_LOCK_HELD=1 /usr/local/libexec/cloud-storage-backup
fi
rollback_on_error() {
    code=$?
    trap - ERR
    previous_path=$(sed -n 's/^RELEASE_PATH=//p' "$previous/release.env" 2>/dev/null || true)
    if [[ -f "$previous/release.env" && -f "$previous_path/DEPLOYED" ]]; then
        cp -a "$previous/release.env" "$previous/compose.yaml" "$stack/"
        docker compose --env-file "$stack/production.env" --env-file "$stack/release.env" -f "$stack/compose.yaml" up -d --wait --wait-timeout 180
        echo 'Application images rolled back. Database was preserved; inspect the pre-deploy backup.' >&2
    else
        docker compose --env-file "$stack/production.env" --env-file "$stack/release.env" -f "$stack/compose.yaml" stop web app
        echo 'Incomplete initial release stopped; persistent database and files were preserved.' >&2
    fi
    exit "$code"
}
trap rollback_on_error ERR
cp "$candidate/compose.yaml" "$candidate/release.env" "$stack/"
cd "$stack"
compose=(docker compose --env-file production.env --env-file release.env)
"${compose[@]}" up -d db --wait --wait-timeout 600
"${compose[@]}" stop web app
"${compose[@]}" run --rm --no-deps app php spark ops:migrate
"${compose[@]}" up -d app web --wait --wait-timeout 180
curl -fsS --retry 5 --retry-delay 3 http://127.0.0.1:18080/ready >/dev/null
touch "$release/DEPLOYED"
ln -sfn "$release" "$root/current"
printf '%s %s\n' "$(date -u +%FT%TZ)" "$sha" >> "$stack/deployments.log"
echo "Deployment healthy: $sha"
