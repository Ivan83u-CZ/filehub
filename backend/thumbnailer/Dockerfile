FROM php:8.3-cli-alpine
# GD with JPEG/PNG/WebP support. The production PHP-FPM image deliberately stays
# untouched: rebuilding it would recreate the container and destroy the tmpfs that
# holds the JTI markers and the live sessions.
# The runtime libraries (libjpeg-turbo, libpng, libwebp) are installed separately from
# the build dependencies: they must survive `apk del .build-deps`.
RUN apk add --no-cache libjpeg-turbo libpng libwebp \
 && apk add --no-cache --virtual .build-deps libjpeg-turbo-dev libpng-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j1 gd \
 && apk del .build-deps \
 && rm -rf /tmp/*
COPY worker.php /app/worker.php
COPY php.ini /usr/local/etc/php/conf.d/zz-thumbd.ini
RUN mkdir -p /var/www/thumbs
WORKDIR /app
EXPOSE 8081
# The built-in server is single-threaded by default; workers keep one slow request
# from blocking the others (same pattern as the staging dav proxy).
CMD ["php", "-S", "0.0.0.0:8081", "-t", "/app", "/app/worker.php"]
