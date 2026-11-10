FROM php:8.5-fpm-alpine

RUN apk add --no-cache postgresql-libs icu-libs icu-data-full unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev icu-dev linux-headers \
    && docker-php-ext-install pdo_pgsql bcmath intl \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
