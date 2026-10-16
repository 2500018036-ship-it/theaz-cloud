#!/bin/bash
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true || "${CI_ISOLATED:-}" == true ]] || { echo 'Run only in an isolated CI environment.' >&2; exit 1; }
ci_project=${CI_PROJECT:-cloud-storage-ci}
[[ "$ci_project" == cloud-storage-ci* || "$ci_project" == cloud-storage-preflight* ]] || { echo 'Refusing a non-test Compose project.' >&2; exit 1; }
task_dir=$(mktemp -d)
chmod 700 "$task_dir"
export RELEASE_PATH="$PWD" RELEASE_SHA="${GITHUB_SHA:-preflight}"
export STORAGE_PATH="$task_dir/storage" DATABASE_PATH="$task_dir/database"
export MARIADB_DATABASE=cloud_storage MARIADB_USER=cloud_storage
export MARIADB_PASSWORD MARIADB_ROOT_PASSWORD
MARIADB_PASSWORD=$(openssl rand -hex 32)
MARIADB_ROOT_PASSWORD=$(openssl rand -hex 32)
export EMAIL_VERIFICATION_DEFERRED=false
export CI_BASE_URL="http://127.0.0.1:${LOCAL_HTTP_PORT:-18080}/"
export APP_SECRETS_ENV_FILE="$task_dir/.env"
touch "$APP_SECRETS_ENV_FILE"
if [[ "${CI_ISOLATED:-}" == true ]]; then
    mkdir -p build
    python3 - "$task_dir/runtime.env" <<'PY'
import os,json,pathlib,sys
keys=['RELEASE_PATH','RELEASE_SHA','STORAGE_PATH','DATABASE_PATH','MARIADB_DATABASE','MARIADB_USER','MARIADB_PASSWORD','MARIADB_ROOT_PASSWORD','EMAIL_VERIFICATION_DEFERRED','CI_BASE_URL','APP_SECRETS_ENV_FILE','LOCAL_HTTP_PORT']
path=pathlib.Path(sys.argv[1]);path.write_text('\n'.join(k+'='+json.dumps(os.environ[k]) for k in keys if k in os.environ)+'\n');path.chmod(0o600)
pathlib.Path('build/ci-runtime-path').write_text(str(path))
PY
fi
docker network inspect web-proxy >/dev/null 2>&1 || docker network create web-proxy >/dev/null
compose=(docker compose -p "$ci_project" -f docker/compose.production.yaml -f docker/compose.ci.yaml)
cleanup() {
    code=$?
    if [[ "$code" != 0 ]]; then
        "${compose[@]}" logs --tail 40 app web || true
    fi
    if [[ "${CI_KEEP_RUNNING:-}" != true || "$code" != 0 || "${CI_ISOLATED:-}" != true ]]; then
        "${compose[@]}" down -v --remove-orphans >/dev/null
    fi
    exit "$code"
}
trap cleanup EXIT
"${compose[@]}" config --quiet
python3 -m unittest discover -s tests/ops -p 'test_*.py'
if command -v node >/dev/null; then
    for script in public/assets/js/*.js; do node --check "$script"; done
elif [[ "${GITHUB_ACTIONS:-}" == true ]]; then
    echo 'Node.js is required for JavaScript checks.' >&2; exit 1
else
    echo 'JavaScript syntax is checked on the development host and in GitHub Actions.'
fi
"${compose[@]}" build app web tests
docker run --rm --entrypoint sh "cloud-storage-test:$RELEASE_SHA" -c 'find app tests -type f -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && composer validate --strict --no-check-publish && composer audit && php vendor/bin/phpunit --no-coverage'
"${compose[@]}" up -d db --wait --wait-timeout "${CI_DB_WAIT_TIMEOUT:-180}"
"${compose[@]}" exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb -u root -e "CREATE DATABASE cloud_storage_tests; GRANT ALL ON cloud_storage_tests.* TO '\''cloud_storage'\''@'\''%'\'';"'
"${compose[@]}" run --rm tests php vendor/bin/phpunit --no-coverage
"${compose[@]}" run --rm --no-deps app php spark ops:migrate
"${compose[@]}" up -d app web --wait --wait-timeout 180
python3 scripts/smoke.py --url "$CI_BASE_URL" --compose-dir "$PWD" --file docker/compose.production.yaml --overlay docker/compose.ci.yaml --project "$ci_project"
echo 'All CI checks passed.'
