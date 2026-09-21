# GardenHub API — PHP-FPM image for Symfony / API Platform.
# HTTP is served by the gardenhub-nginx container (FastCGI on port 9000).
FROM php:8.4-fpm-alpine

WORKDIR /app

# PHP extensions required by Symfony, Doctrine (MySQL) and API Platform.
RUN apk add --no-cache icu-dev libzip-dev \
    && docker-php-ext-install intl zip pdo_mysql opcache

# Composer is only used at image build time; it is not needed on the host.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install Composer dependencies first to benefit from Docker layer caching.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer clear-cache

# Application code.
COPY . .

# Generate the autoloader and run the Symfony post-install scripts.
# var/ is created inside the image; recreate containers to reset it.
RUN composer dump-autoload --optimize \
    && composer run-script post-install-cmd --no-interaction \
    && chmod -R a+rw var
