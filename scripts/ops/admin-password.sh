#!/bin/bash
set -euo pipefail
cd /mnt/data/docker-compose/cloud-storage
read -rp 'Super administrator email: ' email
read -rsp 'New password (12+ characters): ' password
printf '\n'
read -rsp 'Confirm password: ' confirmation
printf '\n'
[[ "$password" == "$confirmation" ]] || { echo 'Passwords differ.' >&2; exit 1; }
printf '%s\n' "$password" | docker compose --env-file production.env --env-file release.env exec -T app php spark ops:admin-password --email="$email"
unset password confirmation
