#!/bin/bash
set -euo pipefail
[[ "${SSH_ORIGINAL_COMMAND:-}" =~ ^deploy\ ([a-f0-9]{40})$ ]] || { echo 'Only production deployments are allowed.' >&2; exit 1; }
sha=${BASH_REMATCH[1]}
exec ssh -T -p 2222 -i /var/lib/cloud-deploy/debian-key -o HostKeyAlias=192.168.20.195 -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/var/lib/cloud-deploy/known_hosts cloud-deploy@127.0.0.1 "deploy $sha"
