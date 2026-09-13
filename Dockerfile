# Official PHP 8.2 Apache Bookworm, resolved 2026-09-13 (Python 3.11 runtime).
FROM php@sha256:d2d7559c815220accfb1b48704a1ce59623aa21f7d2dcc9bff13838749a678d4

ARG DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        unzip \
        python3 \
        python3-venv \
        default-jre-headless \
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
        soap \
        zip \
    && a2enmod headers rewrite \
    && sed -ri "/<Directory \\/var\\/www\\/>/,/<\\/Directory>/ s/AllowOverride None/AllowOverride All/" /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer@sha256:0baf37e7f495f1c42cee6bdd9cf3e00e9435f193b512529e7c484d66c623773f /usr/bin/composer /usr/bin/composer

# Public tools only: no clinical roots, certificates, keys or test credentials.
COPY ops/fse-validation/install-runtime.py ops/fse-validation/production-catalog.lock.json ops/fse-validation/verapdf-install.xml ops/fse-validation/settings.example.json ops/fse-validation/requirements-linux.lock.txt /usr/local/share/af-fse-build/
RUN python3 -I -B /usr/local/share/af-fse-build/install-runtime.py \
    && python3 -m venv /opt/fse/venv \
    && /opt/fse/venv/bin/pip install --no-cache-dir --only-binary=:all: --require-hashes --disable-pip-version-check -r /usr/local/share/af-fse-build/requirements-linux.lock.txt \
    && /opt/fse/venv/bin/pip check

ENV FSE2_VALIDATOR_PYTHON=/opt/fse/venv/bin/python \
    FSE2_VALIDATOR_SETTINGS=/opt/fse/settings.json \
    FSE2_VALIDATOR_MAX_CONCURRENT=1 \
    FSE2_VALIDATOR_MIN_AVAILABLE_MIB=768 \
    FSE2_ALLOW_PRODUCTION=false \
    FSE2_ALLOW_TOSCANA_STAGE=false

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/codex-app.ini
COPY docker/start-container.sh /usr/local/bin/start-container

RUN chmod +x /usr/local/bin/start-container \
    && (composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress \
        || (rm -rf vendor \
            && (composer clear-cache || true) \
            && composer install --no-dev --optimize-autoloader --no-interaction --prefer-source --no-progress)) \
    && mkdir -p upload rest/writable rest/writable/cache rest/writable/cache/temp rest/writable/logs rest/writable/session rest/writable/uploads rest/writable/uploads/messages rest/writable/uploads/messages/drafts rest/writable/uploads/chat rest/writable/uploads/agenda_backup rest/writable/debugbar rest/writable/demo_setup rest/writable/demo_requests rest/writable/reminder_state rest/writable/locks \
    && chown -R www-data:www-data /var/www/html

# A broken validator prevents image publication. This bypasses app/bootstrap/DB.
RUN su -s /bin/sh www-data -c 'php /var/www/html/ops/fse-validation/runtime-self-test.php' > /opt/fse/build-self-test.json \
    && cat /opt/fse/build-self-test.json

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
  CMD curl -fsS http://127.0.0.1/ || exit 1

ENTRYPOINT ["/usr/local/bin/start-container"]
