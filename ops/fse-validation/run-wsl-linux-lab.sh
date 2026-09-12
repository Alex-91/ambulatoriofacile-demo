#!/bin/sh
# Run only a generated synthetic Compose project in the dedicated WSL distro.
set -eu
test "${WSL_DISTRO_NAME:-}" = AmbulatorioFacile-FSE
context=${1:?Context required}
action=${2:?Action required}
case "$context" in /opt/af-fse-labs/*) ;; *) exit 1;; esac
id=${context##*/}
test "${#id}" = 32
case "$id" in *[!a-f0-9]*) exit 1;; esac
test ! -L "$context"
test "$(realpath "$context")" = "$context"
test -f "$context/runtime/linux-lab-image"
test -f "$context/compose.yaml"
cd "$context"
umask 077
mkdir -p evidence
FSE_APP_IMAGE=$(docker image inspect "af-fse-lab:$id" --format '{{.Id}}')
FSE_MYSQL_IMAGE=library/mysql@sha256:8c19b656bb381f163750b238852bd377ba5764e1ec30cdd3f02e55cf8e2f89b7
export FSE_APP_IMAGE FSE_MYSQL_IMAGE
case "$FSE_APP_IMAGE" in sha256:*) ;; *) exit 1;; esac
compose() { docker compose -f "$context/compose.yaml" "$@"; }
case "$action" in
  up)
    if test -n "$(compose ps --all --quiet)"; then
      echo 'Project already initialized; use resume, not up (init must not run twice).'
      exit 1
    fi
    compose config --format json > evidence/compose-resolved.json
    docker image inspect "$FSE_APP_IMAGE" "$FSE_MYSQL_IMAGE" --format '{{.Id}} {{.RepoDigests}} {{.Os}}/{{.Architecture}}' > evidence/images.txt
    compose up -d
    compose ps --all
    ;;
  resume)
    # Start exact existing containers without restarting the one-shot initializer.
    mysql=$(compose ps --all --quiet mysql)
    app=$(compose ps --all --quiet app)
    test -n "$mysql" && test -n "$app"
    docker start "$mysql"
    attempt=0
    until test "$(docker inspect "$mysql" --format '{{.State.Health.Status}}')" = healthy; do
      attempt=$((attempt + 1))
      test "$attempt" -lt 60 || exit 1
      sleep 2
    done
    docker start "$app"
    compose ps --all
    ;;
  seed)
    compose exec -T app php ops/fse-validation/app-lab-seed.php
    ;;
  unit)
    compose exec -T -e FSE2_RUN_ARTIFACT_INTEGRATION=1 app php rest/vendor/phpunit/phpunit/phpunit -c ops/fse-validation/phpunit.xml --bootstrap ops/fse-validation/linux-clinical-bootstrap.php --filter Fse --fail-on-skipped --do-not-cache-result
    compose exec -T app php rest/vendor/phpunit/phpunit/phpunit -c ops/fse-validation/linux-clinical.xml --fail-on-skipped
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/test_validator.py
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/test_clinical_signature.py ClinicalSignatures
    ;;
  fse)
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/app-lab-rehearsal.py
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/app-lab-http.py
    ;;
  billing)
    compose exec -T app php ops/fse-validation/app-lab-billing.php seed
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/app-lab-billing-http.py
    ;;
  clinical)
    compose exec -T app php ops/fse-validation/app-lab-clinical.php
    compose exec -T app /opt/fse/venv/bin/python ops/fse-validation/app-lab-clinical-http.py
    ;;
  recovery)
    compose stop app
    compose run --rm --no-deps --entrypoint php app ops/fse-validation/app-lab-recovery.php
    ;;
  status) compose ps --all ;;
  stop) compose stop ;;
  *) echo 'Unsupported action'; exit 1;;
esac
