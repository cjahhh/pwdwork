#!/bin/sh
set -eu
umask 027
cd /var/www/backend

case "${PORT:-10000}" in
    ''|*[!0-9]*) echo 'PORT must be numeric.' >&2; exit 1 ;;
esac
if [ "${PORT:-10000}" -lt 1024 ] || [ "${PORT:-10000}" -gt 65535 ]; then
    echo 'PORT must be between 1024 and 65535.' >&2; exit 1
fi
export PORT="${PORT:-10000}"

# Cache only at runtime, after Render injects secrets. Never echo configuration.
rm -f bootstrap/cache/config.php
php /opt/pwdwork/check-environment.php
# Root readability does not prove the FPM worker can open Render Secret Files.
# runuser initializes www-data's supplementary groups, as FPM does for workers.
runuser -u www-data -- php /opt/pwdwork/check-environment.php --ca-readable
php artisan config:cache --no-ansi --quiet
php artisan view:cache --no-ansi --quiet
chown -R www-data:www-data storage bootstrap/cache
chmod -R u=rwX,g=rX,o= storage bootstrap/cache

# Render terminates TLS upstream. Other Docker environments must provide their
# own TLS proxy before enabling this mode; do not publish FPM port 9000.
if [ "${RENDER:-false}" = 'true' ]; then
    sed -e 's/__PWDWORK_HTTPS__/on/g' \
        -e 's/__PWDWORK_SERVER_PORT__/443/g' \
        -e 's/__PWDWORK_CLIENT_IP__/$render_client_ip/g' \
        /opt/pwdwork/fastcgi-app.conf.template > /etc/nginx/fastcgi-app.conf
else
    sed -e 's/__PWDWORK_HTTPS__/$https/g' \
        -e 's/__PWDWORK_SERVER_PORT__/$server_port/g' \
        -e 's/__PWDWORK_CLIENT_IP__/$remote_addr/g' \
        /opt/pwdwork/fastcgi-app.conf.template > /etc/nginx/fastcgi-app.conf
fi
# Restrict substitution so Nginx's own variables survive unchanged.
# Prepare Nginx temporary directories on every container startup.
mkdir -p /tmp/nginx/client \
         /tmp/nginx/fastcgi \
         /tmp/nginx/proxy \
         /tmp/nginx/uwsgi \
         /tmp/nginx/scgi

# Allow the Nginx worker to access its temporary directories.
chown -R www-data:www-data /tmp/nginx
chmod -R 750 /tmp/nginx

# Generate and validate Nginx configuration.
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
nginx -t
php-fpm -t
exec "$@"
