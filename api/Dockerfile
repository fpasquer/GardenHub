# GardenHub API — PHP-FPM image for Symfony / API Platform.
# HTTP is served by the gardenhub-nginx container (FastCGI on port 9000).
FROM php:8.4.26-fpm-alpine AS base

WORKDIR /app

# PHP extensions required by Symfony, Doctrine (MySQL) and API Platform.
RUN apk add --no-cache icu-dev libzip-dev \
    && docker-php-ext-install intl zip pdo_mysql opcache

# Composer is only used at image build time; it is not needed on the host.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Composer dependencies first to benefit from Docker layer caching.
COPY composer.json composer.lock symfony.lock ./

FROM base AS development-dependencies
ENV XDEBUG_MODE=off
RUN apk add --no-cache --virtual .xdebug-build $PHPIZE_DEPS linux-headers \
    && MAKEFLAGS=-j1 pecl install xdebug-3.5.3 \
    && docker-php-ext-enable xdebug \
    && mv /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
       /usr/local/etc/php/conf.d/99-xdebug.ini \
    && apk del .xdebug-build
COPY docker/php/development.ini /usr/local/etc/php/conf.d/99-development.ini
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer clear-cache

FROM development-dependencies AS development
ENV APP_ENV=dev APP_DEBUG=1
# Application code.
COPY . .

# Generate the autoloader and run the Symfony post-install scripts.
# var/ is created inside the image; recreate containers to reset it.
RUN composer dump-autoload --optimize \
    && composer run-script post-install-cmd --no-interaction \
    && chmod -R a+rw var

FROM development AS test-runner
RUN apk add --no-cache bash python3 make docker-cli docker-cli-compose \
    util-linux curl git fcgi

FROM base AS production-dependencies
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer clear-cache

FROM production-dependencies AS production-app
ENV APP_ENV=prod APP_DEBUG=0
COPY .env ./
COPY bin/ bin/
COPY config/ config/
COPY migrations/ migrations/
COPY public/ public/
COPY src/ src/
COPY templates/ templates/
RUN composer dump-autoload --no-dev --optimize \
    && composer run-script post-install-cmd --no-interaction \
    && chown -R www-data:www-data var

FROM nginx:1.30.5-alpine AS smoke-nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production-app /app/public /app/public

FROM production-app AS production
