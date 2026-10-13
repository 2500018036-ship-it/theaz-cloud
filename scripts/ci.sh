#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true || "${CI_ISOLATED:-}" == true ]] || { echo 'Run only in an isolated CI environment.' >&2; exit 1; }
task_dir=$(mktemp -d)
chmod 700 "$task_dir"
export RELEASE_PATH="$PWD" RELEASE_SHA="${GITHUB_SHA:-preflight}"
export STORAGE_PATH="$task_dir/storage" DATABASE_PATH="$task_dir/database"
export MARIADB_DATABASE=cloud_storage MARIADB_USER=cloud_storage
export MARIADB_PASSWORD MARIADB_ROOT_PASSWORD
MARIADB_PASSWORD=$(openssl rand -hex 32)
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 32)
export EMAIL_VERIFICATION_DEFERRED=true
docker network inspect web-proxy >/dev/null 2>&1 || docker network create web-proxy >/dev/null
compose=(docker compose -p "${CI_PROJECT:-cloud-storage-ci}" -f docker/compose.production.yaml -f docker/compose.ci.yaml)
cleanup() { "${compose[@]}" down -v --remove-orphans >/dev/null; }
trap cleanup EXIT
"${compose[@]}" config --quiet
"${compose[@]}" build app web tests
docker run --rm --entrypoint sh "cloud-storage-test:$RELEASE_SHA" -c 'find app tests -type f -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && composer validate --strict --no-check-publish && composer audit && php vendor/bin/phpunit --no-coverage'
"${compose[@]}" up -d db --wait --wait-timeout 180
"${compose[@]}" run --rm tests php vendor/bin/phpunit --no-coverage
"${compose[@]}" run --rm --no-deps app php spark migrate --all
"${compose[@]}" up -d app web --wait --wait-timeout 180
python3 scripts/smoke.py --url http://127.0.0.1:18080 --compose-dir "$PWD" --file docker/compose.production.yaml --overlay docker/compose.ci.yaml --project "${CI_PROJECT:-cloud-storage-ci}"
echo 'All CI checks passed.'
