FROM php:8.4-cli-alpine

RUN apk add --no-cache libpq-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
