#!/bin/bash
set -eu
# tini reaps orphaned children. If either daemon exits, stop the other and fail.
php-fpm --nodaemonize &
fpm_pid=$!
nginx -g 'daemon off;' &
nginx_pid=$!
stop_services() {
    kill -QUIT "$nginx_pid" "$fpm_pid" 2>/dev/null || true
    wait "$nginx_pid" "$fpm_pid" 2>/dev/null || true
}
trap 'stop_services; exit 0' TERM INT
set +e
wait -n "$fpm_pid" "$nginx_pid"
service_status=$?
set -e
stop_services
if [ "$service_status" -eq 0 ]; then service_status=1; fi
exit "$service_status"
