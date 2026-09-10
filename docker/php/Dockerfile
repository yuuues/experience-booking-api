FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-dev libpq-dev $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY conf.d/app.ini /usr/local/etc/php/conf.d/app.ini
COPY php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
