# Laravel 12 API container for Render (free tier).
# PHP 8.3 CLI + PostgreSQL driver, served via the built-in dev server.
# Low-traffic single-process model — fine for the free tier; revisit FrankenPHP/Octane to scale.
FROM php:8.3-cli AS base

# System libs needed to build the PHP extensions this app uses.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libonig-dev libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip intl mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first so this layer caches across source-only changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader

# App source.
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["sh", "docker/entrypoint.sh"]
