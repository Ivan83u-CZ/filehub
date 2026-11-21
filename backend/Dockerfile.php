FROM php:8.3-fpm-alpine
RUN install -d -m 0770 -o root -g www-data /var/www/jti
# Build XML parser extension with bounded concurrency for 1 GiB VPS.
RUN apk add --no-cache libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libxml2-dev curl-dev \
    && docker-php-ext-install -j1 dom curl \
    && apk del .build-deps
