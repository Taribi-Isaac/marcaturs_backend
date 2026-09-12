# MarcatursHub API — multi-target image (ENG-040A)
# Targets: runtime (shared), api, worker, scheduler, reverb
#
# Build examples:
#   docker build -f docker/Dockerfile --target api -t marcaturshub-api:staging .
#   docker build -f docker/Dockerfile --target worker -t marcaturshub-worker:staging .
#   docker build -f docker/Dockerfile --target scheduler -t marcaturshub-scheduler:staging .
#   docker build -f docker/Dockerfile --target reverb -t marcaturshub-reverb:staging .

FROM php:8.4-cli-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl libzip-dev libpng-dev libicu-dev libonig-dev \
        libsqlite3-dev default-mysql-client \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql pdo_sqlite bcmath intl zip pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS vendor
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM base AS runtime
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache \
    && php artisan package:discover --ansi || true

ENV APP_ENV=staging \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    RUN_MIGRATIONS=false

COPY docker/entrypoint.sh /usr/local/bin/marcaturshub-entrypoint
RUN chmod +x /usr/local/bin/marcaturshub-entrypoint

ENTRYPOINT ["marcaturshub-entrypoint"]

# --- HTTP API (php built-in server behind ALB for staging bootstrap) ---
# Production hardening may replace this with php-fpm + nginx without changing targets.
FROM runtime AS api
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM runtime AS worker
CMD ["php", "artisan", "queue:work", "redis", "--sleep=1", "--tries=3", "--max-time=3600"]

FROM runtime AS scheduler
CMD ["php", "artisan", "schedule:work"]

FROM runtime AS reverb
EXPOSE 8080
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
