# syntax=docker/dockerfile:1

# Laravel app image: FrankenPHP (Octane worker mode) on PHP 8.5.
# The same base serves app, horizon, scheduler and the isolated renderer.
FROM dunglas/frankenphp:1.13-php8.5-trixie AS base

RUN install-php-extensions pdo_pgsql pgsql redis intl pcntl zip bcmath exif ffi

# poppler-utils: pdftoppm/pdftotext for brochure pages; libvips-tools: AVIF/WebP resizing (the aomenc plugin
# adds the AV1 encoder AVIF needs); qpdf: PDF repair
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils libvips-tools libheif-plugin-aomenc qpdf git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

FROM base AS dev

RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# --max-requests=1 restarts the worker after each request, so code edits show up without a file watcher.
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--workers=2", "--max-requests=1"]

FROM base AS prod

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan filament:optimize \
    && php artisan optimize

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
