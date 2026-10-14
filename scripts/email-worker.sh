#!/bin/sh
set -eu
cd /var/www/html
while :; do
    touch /tmp/email-worker-heartbeat
    # Only delivery counts are logged; SMTP diagnostics and recipients stay private.
    php spark email:send-queued --limit 5 --max-attempts 5 || true
    touch /tmp/email-worker-heartbeat
    sleep 10
done
