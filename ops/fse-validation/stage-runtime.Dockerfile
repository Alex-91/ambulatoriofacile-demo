# Opt-in staging smoke image. Never selected by the product's Coolify Dockerfile.
# AF_APP_IMAGE must contain the exact application source hashes in app-source-manifest.json.
ARG AF_APP_IMAGE
FROM ${AF_APP_IMAGE}
USER root
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-venv default-jre-headless \
    && apt-get clean
COPY . /opt/fse/
RUN python3 -c "import hashlib,json,pathlib; r=pathlib.Path('/opt/fse'); m=json.loads((r/'bundle-manifest.json').read_text()); assert all(hashlib.sha256((r/p).read_bytes()).hexdigest()==h for p,h in m['files'].items()), 'bundle mismatch'" \
    && python3 -m venv /opt/fse/venv \
    && /opt/fse/venv/bin/python -m pip install --no-cache-dir --only-binary=:all: -r /opt/fse/requirements.lock.txt \
    && /opt/fse/venv/bin/python -m pip check
COPY stage-smoke.php /var/www/html/ops/fse-validation/stage-smoke.php
ENV FSE2_ALLOW_PRODUCTION=false FSE2_ALLOW_TOSCANA_STAGE=false \
    FSE2_VALIDATOR_PYTHON=/opt/fse/venv/bin/python FSE2_VALIDATOR_SETTINGS=/opt/fse/settings.json \
    PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
USER www-data:www-data
# No web entrypoint, migration or database connection is started by this image.
HEALTHCHECK NONE
ENTRYPOINT ["php", "/var/www/html/ops/fse-validation/stage-smoke.php"]
CMD ["--python=/opt/fse/venv/bin/python", "--settings=/opt/fse/settings.json", "--manifest=/opt/fse/app-source-manifest.json"]
