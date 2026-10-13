#!/bin/bash
set -euo pipefail
[[ "${SSH_ORIGINAL_COMMAND:-}" =~ ^deploy\ ([a-f0-9]{40})$ ]] || { echo 'Only production deployments are allowed.' >&2; exit 1; }
exec sudo -n /usr/local/libexec/cloud-storage-deploy "${BASH_REMATCH[1]}"
