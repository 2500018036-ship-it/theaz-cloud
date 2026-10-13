ARG PHP_IMAGE=php:8.2-fpm-bookworm
FROM ${PHP_IMAGE} AS base

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        default-mysql-client \
        libfcgi-bin \
        libicu-dev \
        libonig-dev \
        unzip \
    && docker-php-ext-install intl mbstring mysqli opcache pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/conf.d/cloud-drive.ini /usr/local/etc/php/conf.d/zz-cloud-drive.ini
COPY docker/php/php-fpm.d/zz-cloud-drive.conf /usr/local/etc/php-fpm.d/zz-cloud-drive.conf
COPY docker/php/docker-entrypoint.sh /usr/local/bin/cloud-drive-entrypoint

RUN mkdir -p /var/cloud-drive/storage \
    && chown -R www-data:www-data /var/cloud-drive \
    && chmod +x /usr/local/bin/cloud-drive-entrypoint

ENTRYPOINT ["cloud-drive-entrypoint"]

FROM base AS development

CMD ["php-fpm"]

FROM base AS production

COPY --chown=www-data:www-data . /var/www/html

RUN mkdir -p \
        writable/cache \
        writable/debugbar \
        writable/logs \
        writable/session \
        writable/uploads \
    && chown -R www-data:www-data writable /var/cloud-drive

CMD ["php-fpm"]
