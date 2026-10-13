ARG PHP_IMAGE=php:8.3-fpm-bookworm
FROM ${PHP_IMAGE} AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        default-mysql-client \
        libfcgi-bin \
        libicu-dev \
        libonig-dev libsqlite3-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
        unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j2 intl mbstring mysqli opcache pdo_mysql zip gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/cloud-drive.ini /usr/local/etc/php/conf.d/zz-cloud-drive.ini
COPY docker/php/php-fpm.d/zz-cloud-drive.conf /usr/local/etc/php-fpm.d/zz-cloud-drive.conf
COPY docker/php/docker-entrypoint.sh /usr/local/bin/cloud-drive-entrypoint

RUN mkdir -p /var/cloud-drive/storage \
    && chown -R www-data:www-data /var/cloud-drive \
    && sed -i 's/\r$//' /usr/local/bin/cloud-drive-entrypoint \
    && chmod +x /usr/local/bin/cloud-drive-entrypoint

ENTRYPOINT ["cloud-drive-entrypoint"]

FROM base AS development

CMD ["php-fpm"]

FROM base AS dependencies

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . /var/www/html
RUN mkdir -p writable/cache writable/debugbar writable/logs writable/session writable/uploads
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM dependencies AS test
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader
CMD ["php", "vendor/bin/phpunit", "--no-coverage"]

FROM base AS production

COPY --chown=www-data:www-data . /var/www/html
COPY --from=dependencies --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

RUN mkdir -p \
        writable/cache \
        writable/debugbar \
        writable/logs \
        writable/session \
        writable/uploads \
    && chown -R www-data:www-data writable /var/cloud-drive

CMD ["php-fpm"]
