# syntax=docker/dockerfile:1
FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY index.html vite.config.js ./
COPY src ./src
COPY public ./public
# Public build configuration only. All browser API traffic stays on this origin.
ENV VITE_API_URL=/api
RUN npm run build

FROM php:8.4.27-fpm-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gettext-base nginx tini unzip fonts-dejavu-core \
        libfreetype6 libjpeg62-turbo libpng16-16 libzip4 libicu72 \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 gd intl pdo_mysql zip \
    && apt-get purge -y --auto-remove \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /var/www/backend

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY backend/composer.json backend/composer.lock ./
# No .env, application key, database connection, scripts or migrations at build time.
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader
COPY backend/app ./app
COPY backend/bootstrap ./bootstrap
COPY backend/config ./config
COPY backend/database/migrations ./database/migrations
COPY backend/resources ./resources
COPY backend/routes ./routes
COPY backend/artisan ./artisan
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database/factories database/seeders \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts --no-plugins \
    && composer check-platform-reqs --no-dev \
    && php -r 'require "app/Support/RuntimeRequirements.php"; if (!App\Support\RuntimeRequirements::patchedPhp(PHP_VERSION)) { fwrite(STDERR, "PHP security baseline not met.\n"); exit(1); }' \
    && php artisan package:discover --no-ansi

FROM php-base AS runtime
COPY --from=vendor /var/www/backend /var/www/backend
COPY backend/public/index.php ./public/index.php
COPY --from=frontend /build/dist /var/www/frontend
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/zz-pwdwork.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-pwdwork.conf
COPY deploy/docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY deploy/docker/fastcgi-app.conf /opt/pwdwork/fastcgi-app.conf.template
COPY deploy/docker/entrypoint.sh deploy/docker/run-services.sh deploy/docker/check-environment.php /opt/pwdwork/
RUN mkdir -p storage/app/private storage/fonts /tmp/nginx/client /tmp/nginx/fastcgi /tmp/nginx/proxy /tmp/nginx/uwsgi /tmp/nginx/scgi \
    && chown -R www-data:www-data storage bootstrap/cache /tmp/nginx \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache \
    && chmod 755 /opt/pwdwork/*.sh \
    && rm /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr LOG_LEVEL=warning PORT=10000
EXPOSE 10000
STOPSIGNAL SIGTERM
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --output /dev/null "http://127.0.0.1:${PORT}/up" || exit 1
ENTRYPOINT ["/usr/bin/tini", "--", "/opt/pwdwork/entrypoint.sh"]
CMD ["/opt/pwdwork/run-services.sh"]
