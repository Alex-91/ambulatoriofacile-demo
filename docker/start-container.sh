#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
UPLOAD_ROOT="${LEGACY_UPLOAD_PATH:-$APP_ROOT/upload}"
BOOTSTRAP_SQL_PATH="${BOOTSTRAP_SQL_PATH:-$APP_ROOT/docker/demo/ambulatoriofacile_demo.sql}"
BOOTSTRAP_DEMO_DB="${BOOTSTRAP_DEMO_DB:-1}"

cd "$APP_ROOT"

get_config_value() {
  key="$1"
  fallback="${2:-}"
  value="$(printenv "$key" 2>/dev/null || true)"

  if [ -n "$value" ]; then
    printf '%s' "$value"
    return 0
  fi

  printf '%s' "$fallback"
}

bootstrap_demo_database() {
  if [ "$BOOTSTRAP_DEMO_DB" != "1" ]; then
    echo "Demo bootstrap disabilitato."
    return 0
  fi

  if [ ! -f "$BOOTSTRAP_SQL_PATH" ]; then
    echo "Dump demo non trovato in $BOOTSTRAP_SQL_PATH, salto bootstrap."
    return 0
  fi

  db_host="$(get_config_value 'database.default.hostname' "${DB_HOST:-}")"
  db_port="$(get_config_value 'database.default.port' "${DB_PORT:-3306}")"
  db_name="$(get_config_value 'database.default.database' "${DB_DATABASE:-}")"
  db_user="$(get_config_value 'database.default.username' "${DB_USERNAME:-}")"
  db_pass="$(get_config_value 'database.default.password' "${DB_PASSWORD:-}")"

  if [ -z "$db_host" ] || [ -z "$db_name" ] || [ -z "$db_user" ]; then
    echo "Credenziali database incomplete, salto bootstrap demo."
    return 0
  fi

  echo "Attendo la disponibilita del database $db_host:$db_port..."
  attempts=0
  until MYSQL_PWD="$db_pass" mysqladmin ping --host="$db_host" --port="$db_port" --user="$db_user" --silent >/dev/null 2>&1; do
    attempts=$((attempts + 1))

    if [ "$attempts" -ge 60 ]; then
      echo "Database non raggiungibile dopo 120 secondi."
      exit 1
    fi

    sleep 2
  done

  table_exists="$(MYSQL_PWD="$db_pass" mysql \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --batch \
    --skip-column-names \
    --execute="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db_name' AND table_name = 'dap01_users';" 2>/dev/null || printf '0')"

  if [ "$table_exists" != "0" ]; then
    echo "Database gia inizializzato, bootstrap demo non necessario."
    return 0
  fi

  echo "Database vuoto rilevato, importo il dump demo iniziale..."
  MYSQL_PWD="$db_pass" mysql \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --default-character-set=utf8mb4 \
    "$db_name" < "$BOOTSTRAP_SQL_PATH"

  echo "Import demo completato."
}

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

bootstrap_demo_database

if [ "${RUN_MIGRATIONS:-1}" = "1" ] && [ -f "$APP_ROOT/rest/spark" ]; then
  php "$APP_ROOT/rest/spark" migrate --all --no-header
fi

exec apache2-foreground
