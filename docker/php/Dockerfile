FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        libzip-dev \
        libpq-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        zip \
        pdo_pgsql

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer

COPY php.ini /usr/local/etc/php/conf.d/app.ini

ARG UID=1000
ARG GID=1000
RUN deluser www-data \
    && addgroup -g ${GID} app \
    && adduser -u ${UID} -G app -D -h /home/app app \
    && sed -i 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app
USER app

CMD ["php-fpm"]
