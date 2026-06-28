#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
UPLOAD_ROOT="${LEGACY_UPLOAD_PATH:-$APP_ROOT/upload}"
BOOTSTRAP_SQL_PATH="${BOOTSTRAP_SQL_PATH:-$APP_ROOT/docker/demo/ambulatoriofacile_demo.sql}"
BOOTSTRAP_DEMO_DB="${BOOTSTRAP_DEMO_DB:-1}"
RUN_DEMO_SEED="${RUN_DEMO_SEED:-0}"
DEMO_SEED_PASSWORD="${DEMO_SEED_PASSWORD:-Demo2026}"
DEMO_AUTO_RESET_ENABLED="${DEMO_AUTO_RESET_ENABLED:-0}"
DEMO_AUTO_RESET_HOUR="${DEMO_AUTO_RESET_HOUR:-2}"
DEMO_AUTO_RESET_MINUTE="${DEMO_AUTO_RESET_MINUTE:-15}"
DEMO_AUTO_RESET_CHECK_INTERVAL="${DEMO_AUTO_RESET_CHECK_INTERVAL:-300}"
DEMO_AUTO_RESET_TZ="${DEMO_AUTO_RESET_TZ:-${TZ:-Europe/Rome}}"

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

normalize_int() {
  value="${1:-}"
  fallback="${2:-0}"

  case "$value" in
    ''|*[!0-9]*)
      printf '%s' "$fallback"
      return 0
      ;;
  esac

  printf '%s' "$value"
}

demo_auto_reset_log() {
  log_message="$1"
  log_file="$APP_ROOT/rest/writable/logs/demo_auto_reset.log"
  timestamp="$(TZ="$DEMO_AUTO_RESET_TZ" date '+%Y-%m-%d %H:%M:%S %Z')"
  printf '%s %s\n' "$timestamp" "$log_message" >> "$log_file"
}

clear_runtime_caches() {
  cache_root="$APP_ROOT/rest/writable/cache"

  if [ -d "$cache_root" ]; then
    find "$cache_root" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
  fi

  mkdir -p "$cache_root/temp"
  echo "Cache runtime ripulita."
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

  echo "Verifico la presenza del database $db_name..."
  MYSQL_PWD="$db_pass" mysql \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --default-character-set=utf8mb4 \
    --execute="CREATE DATABASE IF NOT EXISTS \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

  users_count="$(MYSQL_PWD="$db_pass" mysql \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --batch \
    --skip-column-names \
    --execute="SELECT COUNT(*) FROM \`$db_name\`.\`dap01_users\`;" 2>/dev/null || printf '0')"

  appointments_count="$(MYSQL_PWD="$db_pass" mysql \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --batch \
    --skip-column-names \
    --execute="SELECT COUNT(*) FROM \`$db_name\`.\`dap12_agenda_appuntamenti\`;" 2>/dev/null || printf '0')"

  if [ "${users_count:-0}" -gt 0 ] && [ "${appointments_count:-0}" -gt 0 ]; then
    echo "Database gia inizializzato, bootstrap demo non necessario."
    return 0
  fi

  echo "Database vuoto rilevato, importo il dump demo iniziale..."
  MYSQL_PWD="$db_pass" mysql \
    --binary-mode=1 \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --default-character-set=utf8mb4 \
    "$db_name" < "$BOOTSTRAP_SQL_PATH"

  echo "Import demo completato."
}

seed_demo_runtime() {
  if [ "$RUN_DEMO_SEED" != "1" ]; then
    echo "Seed demo runtime disabilitato."
    return 0
  fi

  if [ ! -f "$APP_ROOT/rest/tools/SeedDemoData.php" ]; then
    echo "Script SeedDemoData.php non trovato, salto seed demo."
    return 0
  fi

  db_host="$(get_config_value 'database.default.hostname' "${DB_HOST:-}")"
  db_port="$(get_config_value 'database.default.port' "${DB_PORT:-3306}")"
  db_name="$(get_config_value 'database.default.database' "${DB_DATABASE:-}")"
  db_user="$(get_config_value 'database.default.username' "${DB_USERNAME:-}")"
  db_pass="$(get_config_value 'database.default.password' "${DB_PASSWORD:-}")"
  db_key="$(get_config_value 'DB_ENCRYPTION_KEY' "$(get_config_value 'database.default.DB_ENCRYPTION_KEY' '')")"
  db_mode="$(get_config_value 'DB_ENCRYPTION_MODE' 'aes-256-cbc')"
  brand_name="$(get_config_value 'PRODUCT_BRAND_NAME' 'AmbulatorioFacile')"

  if [ -z "$db_host" ] || [ -z "$db_name" ] || [ -z "$db_user" ] || [ -z "$db_key" ]; then
    echo "Configurazione demo incompleta, salto seed demo."
    return 0
  fi

  seed_env_path="$APP_ROOT/rest/writable/demo_setup/.demo-seed.env"
  cat > "$seed_env_path" <<EOF
database.default.hostname=$db_host
database.default.port=$db_port
database.default.database=$db_name
database.default.username=$db_user
database.default.password=$db_pass
database.default.DB_ENCRYPTION_KEY=$db_key
DB_ENCRYPTION_KEY=$db_key
DB_ENCRYPTION_MODE=$db_mode
PRODUCT_BRAND_NAME=$brand_name
EOF

  echo "Eseguo il seed demo applicativo corrente..."
  php "$APP_ROOT/rest/tools/SeedDemoData.php" \
    --env-file="$seed_env_path" \
    --host="$db_host" \
    --port="$db_port" \
    --user="$db_user" \
    --pass="$db_pass" \
    --database="$db_name" \
    --demo-password="$DEMO_SEED_PASSWORD"

  echo "Seed demo completato."
}

run_demo_auto_reset_once() {
  days="$(normalize_int "${DEMO_SEED_AGENDA_BUSINESS_DAYS:-5}" 5)"

  demo_auto_reset_log "Avvio reset dataset demo automatico (days=$days)."

  if php "$APP_ROOT/rest/spark" demo:reset-dataset --days="$days" >> "$APP_ROOT/rest/writable/logs/demo_auto_reset.log" 2>&1; then
    demo_auto_reset_log "Reset dataset demo automatico completato."
    return 0
  fi

  demo_auto_reset_log "Reset dataset demo automatico fallito."
  return 1
}

start_demo_auto_reset_loop() {
  if [ "$DEMO_AUTO_RESET_ENABLED" != "1" ]; then
    echo "Reset demo automatico disabilitato."
    return 0
  fi

  if [ ! -f "$APP_ROOT/rest/spark" ]; then
    echo "Spark non trovato, reset demo automatico non avviato."
    return 0
  fi

  reset_hour="$(normalize_int "$DEMO_AUTO_RESET_HOUR" 2)"
  reset_minute="$(normalize_int "$DEMO_AUTO_RESET_MINUTE" 15)"
  check_interval="$(normalize_int "$DEMO_AUTO_RESET_CHECK_INTERVAL" 300)"

  if [ "$reset_hour" -gt 23 ]; then
    reset_hour=2
  fi

  if [ "$reset_minute" -gt 59 ]; then
    reset_minute=15
  fi

  if [ "$check_interval" -lt 60 ]; then
    check_interval=300
  fi

  state_file="$APP_ROOT/rest/writable/demo_setup/demo_auto_reset_last_run.txt"
  target_hm="$(printf '%02d%02d' "$reset_hour" "$reset_minute")"

  echo "Avvio loop reset demo automatico alle ${reset_hour}:$(printf '%02d' "$reset_minute") ($DEMO_AUTO_RESET_TZ)."
  demo_auto_reset_log "Loop reset demo automatico avviato. Orario target ${reset_hour}:$(printf '%02d' "$reset_minute"), controllo ogni ${check_interval}s."

  (
    while :; do
      current_date="$(TZ="$DEMO_AUTO_RESET_TZ" date '+%Y-%m-%d')"
      current_hm="$(TZ="$DEMO_AUTO_RESET_TZ" date '+%H%M')"
      last_run_date=""

      if [ -f "$state_file" ]; then
        last_run_date="$(sed -n '1p' "$state_file" 2>/dev/null || true)"
      fi

      if [ "$current_hm" -ge "$target_hm" ] && [ "$last_run_date" != "$current_date" ]; then
        if run_demo_auto_reset_once; then
          printf '%s\n' "$current_date" > "$state_file"
        fi
      fi

      sleep "$check_interval"
    done
  ) &
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

clear_runtime_caches

bootstrap_demo_database

if [ "${RUN_MIGRATIONS:-1}" = "1" ] && [ -f "$APP_ROOT/rest/spark" ]; then
  php "$APP_ROOT/rest/spark" migrate --all --no-header
fi

seed_demo_runtime
start_demo_auto_reset_loop

exec apache2-foreground
