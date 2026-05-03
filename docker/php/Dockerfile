ARG PHP_VERSION=8.5

FROM composer:2 AS composer_bin

FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

WORKDIR /var/www/html

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libzip-dev \
        poppler-utils \
        unzip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        pdo_pgsql \
        zip; \
    mkdir -p /run/php /var/www/html/var; \
    chown -R www-data:www-data /run/php /var/www/html/var; \
    { \
        echo "[global]"; \
        echo "error_log = /proc/self/fd/2"; \
    } > /usr/local/etc/php-fpm.d/zzzz-app-global.conf; \
    { \
        echo "[www]"; \
        echo "listen = /run/php/php-fpm.sock"; \
        echo "listen.mode = 0666"; \
        echo "clear_env = no"; \
        echo "catch_workers_output = yes"; \
        echo "decorate_workers_output = no"; \
    } > /usr/local/etc/php-fpm.d/zzzz-app.conf; \
    rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini-common /usr/local/etc/php/conf.d/90-app.ini

HEALTHCHECK CMD php -v || exit 1

FROM php-base AS prod

COPY docker/php/php.ini-prod /usr/local/etc/php/conf.d/99-prod.ini
COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer

COPY --chown=www-data:www-data composer.json composer.lock symfony.lock ./
RUN set -eux; \
    COMPOSER_ALLOW_SUPERUSER=1 composer install \
        --no-dev \
        --prefer-dist \
        --no-progress \
        --no-interaction \
        --no-scripts \
        --optimize-autoloader

COPY --chown=www-data:www-data . .
RUN set -eux; \
    COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction; \
    APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=build-time-placeholder \
    DATABASE_URL="postgresql://komuchet:komuchet@localhost:5432/komuchet?serverVersion=18&charset=utf8" \
    DEFAULT_URI=http://localhost \
    SYMFONY_TRUSTED_HOSTS='^localhost$' \
    SYMFONY_TRUSTED_PROXIES=127.0.0.1 \
    MAILER_DSN=null://null \
    php bin/console importmap:install --no-interaction --env=prod; \
    APP_ENV=prod \
    APP_DEBUG=0 \
    APP_SECRET=build-time-placeholder \
    DATABASE_URL="postgresql://komuchet:komuchet@localhost:5432/komuchet?serverVersion=18&charset=utf8" \
    DEFAULT_URI=http://localhost \
    SYMFONY_TRUSTED_HOSTS='^localhost$' \
    SYMFONY_TRUSTED_PROXIES=127.0.0.1 \
    MAILER_DSN=null://null \
    php bin/console asset-map:compile --no-interaction --env=prod; \
    mkdir -p var/cache var/log; \
    chown -R www-data:www-data var

FROM php-base AS dev

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
    rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini-dev /usr/local/etc/php/conf.d/99-dev.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/50-xdebug.ini
