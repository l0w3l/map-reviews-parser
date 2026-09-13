FROM node:22-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-fpm-bookworm AS app
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libonig-dev libxml2-dev libcurl4-openssl-dev libzip-dev \
    unzip ca-certificates \
    && docker-php-ext-install -j$(nproc) pdo_pgsql mbstring dom curl zip pcntl sockets opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
COPY --from=frontend /app/public/build ./public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/production.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
USER www-data
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
