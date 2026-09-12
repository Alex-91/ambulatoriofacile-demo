#!/bin/sh
set -eu
test "$(id -u)" = 33
test -f /opt/fse/linux-lab-image
test -f "$FSE_LAB_ROOT/lab.json"
test "${FSE2_ALLOW_PRODUCTION}" = false
test "${FSE2_ALLOW_TOSCANA_STAGE}" = false
cd /var/www/html
# Seeding is separate and explicit: restarts must not erase/recreate an incomplete lab.
exec php -S 127.0.0.1:8088 -t /var/www/html ops/fse-validation/app-lab-router.php
