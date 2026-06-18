#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
UPLOAD_ROOT="${LEGACY_UPLOAD_PATH:-$APP_ROOT/upload}"

cd "$APP_ROOT"

mkdir -p \
  "$UPLOAD_ROOT" \
  "$APP_ROOT/rest/writable/cache" \
  "$APP_ROOT/rest/writable/cache/temp" \
  "$APP_ROOT/rest/writable/logs" \
  "$APP_ROOT/rest/writable/session" \
  "$APP_ROOT/rest/writable/uploads" \
  "$APP_ROOT/rest/writable/uploads/messages" \
  "$APP_ROOT/rest/writable/uploads/messages/drafts" \
  "$APP_ROOT/rest/writable/uploads/chat" \
  "$APP_ROOT/rest/writable/uploads/agenda_backup" \
  "$APP_ROOT/rest/writable/debugbar" \
  "$APP_ROOT/rest/writable/demo_setup" \
  "$APP_ROOT/rest/writable/demo_requests" \
  "$APP_ROOT/rest/writable/reminder_state" \
  "$APP_ROOT/rest/writable/locks"

if [ -d "$APP_ROOT/rest/upload" ] && [ ! -L "$APP_ROOT/rest/upload" ]; then
  if [ -z "$(find "$UPLOAD_ROOT" -mindepth 1 -print -quit 2>/dev/null)" ] && [ -n "$(find "$APP_ROOT/rest/upload" -mindepth 1 -print -quit 2>/dev/null)" ]; then
    cp -a "$APP_ROOT/rest/upload/." "$UPLOAD_ROOT/"
  fi

  rm -rf "$APP_ROOT/rest/upload"
fi

if [ ! -e "$APP_ROOT/rest/upload" ]; then
  ln -s "$UPLOAD_ROOT" "$APP_ROOT/rest/upload"
fi

chown -R www-data:www-data "$UPLOAD_ROOT" "$APP_ROOT/rest/writable"
chmod -R ug+rwX "$UPLOAD_ROOT" "$APP_ROOT/rest/writable"

if [ "${RUN_MIGRATIONS:-1}" = "1" ] && [ -f "$APP_ROOT/rest/spark" ]; then
  php "$APP_ROOT/rest/spark" migrate --all --no-header
fi

exec apache2-foreground
