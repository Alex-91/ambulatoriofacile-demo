# Test-only image. Never selected by the product's production Dockerfile.
FROM library/php@sha256:7438f520602081aacfd093ae408b2e31f8646fc6a11ae431947175b818291cf7
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv default-jre-headless \
    libicu-dev libonig-dev libxml2-dev libsqlite3-dev libzip-dev unzip git \
    && docker-php-ext-install -j1 intl mbstring mysqli pdo_mysql pdo_sqlite soap zip \
    && apt-get clean
COPY --from=library/composer@sha256:0baf37e7f495f1c42cee6bdd9cf3e00e9435f193b512529e7c484d66c623773f /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY --chmod=755 app/ /var/www/html/
COPY --chmod=755 runtime/ /opt/fse/
RUN python3 /opt/fse/verify-linux-bundle.py /var/www/html /opt/fse \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins \
    && cd rest && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress --prefer-dist --no-scripts --no-plugins \
    && python3 -m venv /opt/fse/venv \
    && /opt/fse/venv/bin/python -m pip install --no-cache-dir --only-binary=:all: -r /opt/fse/requirements.lock.txt \
    && /opt/fse/venv/bin/python -m pip check \
    && mkdir -p /var/www/html/rest/writable \
    && chmod 755 /var/www/html/ops/fse-validation/linux-lab-entrypoint.sh
ENV FSE2_ALLOW_PRODUCTION=false FSE2_ALLOW_TOSCANA_STAGE=false \
    FSE2_VALIDATOR_PYTHON=/opt/fse/venv/bin/python FSE2_VALIDATOR_SETTINGS=/opt/fse/settings.json \
    PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 XDEBUG_MODE=off
USER 33:33
ENTRYPOINT ["/var/www/html/ops/fse-validation/linux-lab-entrypoint.sh"]
