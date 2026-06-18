FROM php:8.2-apache

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        dom \
        gd \
        intl \
        mbstring \
        mysqli \
        pdo_mysql \
        zip \
    && a2enmod headers rewrite \
    && sed -ri "/<Directory \\/var\\/www\\/>/,/<\\/Directory>/ s/AllowOverride None/AllowOverride All/" /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/codex-app.ini
COPY docker/start-container.sh /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && mkdir -p upload rest/writable rest/writable/cache rest/writable/cache/temp rest/writable/logs rest/writable/session rest/writable/uploads rest/writable/uploads/messages rest/writable/uploads/messages/drafts rest/writable/uploads/chat rest/writable/uploads/agenda_backup rest/writable/debugbar rest/writable/demo_setup rest/writable/demo_requests rest/writable/reminder_state rest/writable/locks \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS http://127.0.0.1/ || exit 1

ENTRYPOINT ["/usr/local/bin/start-container"]
