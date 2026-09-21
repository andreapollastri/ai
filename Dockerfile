FROM php:8.4-cli-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libffi-dev libonig-dev libsqlite3-dev libpng-dev libzip-dev \
    && docker-php-ext-install ffi pcntl pdo pdo_sqlite gd zip \
    && echo "ffi.enable=true" > /usr/local/etc/php/conf.d/ffi.ini \
    && echo "memory_limit=2048M" > /usr/local/etc/php/conf.d/memory.ini \
    && echo "max_execution_time=180" > /usr/local/etc/php/conf.d/timeout.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

FROM node:22-bookworm AS assets

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --ignore-scripts
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php-base AS app

WORKDIR /app
COPY . .
COPY --from=assets /app/public/build /app/public/build

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && mkdir -p storage/app/george-models database \
    && chown -R www-data:www-data storage bootstrap/cache database

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
