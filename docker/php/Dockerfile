FROM php:8.4-cli-alpine AS base

RUN apk add --no-cache libpq-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

# Desenvolvimento: o código entra por volume (docker-compose.yml).
FROM base AS dev

# Produção: código e dependências dentro da imagem.
FROM base AS prod

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENV PHP_CLI_SERVER_WORKERS=8
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
