#!/bin/bash
# Container entrypoint for both PHP services.
#
# CONTAINER_ROLE=app        first boot of a fresh clone: create .env, install
#                           dependencies, generate the app key, build assets,
#                           run migrations, then hand off to Apache.
# CONTAINER_ROLE=scheduler  wait until the app container has finished that work,
#                           then hand off to `artisan schedule:work`.
#
# Everything here is idempotent, so restarts are cheap.
set -euo pipefail

ROLE="${CONTAINER_ROLE:-app}"
APP_DIR=/var/www/html
OWNER="www-data:www-data"

cd "$APP_DIR"

log() { printf '\033[36m[entrypoint:%s]\033[0m %s\n' "$ROLE" "$*"; }

own() {
    # Best effort: bind mounts on Docker Desktop virtualise ownership and reject chown.
    chown -R "$OWNER" "$@" 2>/dev/null || true
}

# Reads a setting the way Laravel does: a real environment variable wins, then
# .env, then the fallback. Keeps .env the single source of truth for DB_*.
env_value() {
    local key="$1" fallback="${2:-}" value="${!1:-}"

    if [ -z "$value" ] && [ -f .env ]; then
        value="$(sed -n "s/^${key}=//p" .env | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")"
    fi

    printf '%s' "${value:-$fallback}"
}

wait_for_db() {
    local host port user password
    host="$(env_value DB_HOST db)"
    port="$(env_value DB_PORT 3306)"
    user="$(env_value DB_USERNAME milly)"
    password="$(env_value DB_PASSWORD '')"

    log "waiting for MySQL at ${host}:${port}"
    for _ in $(seq 1 60); do
        if php -r '
            try {
                new PDO(sprintf("mysql:host=%s;port=%s", $argv[1], $argv[2]), $argv[3], $argv[4]);
            } catch (Throwable $e) {
                exit(1);
            }
        ' "$host" "$port" "$user" "$password" 2>/dev/null; then
            log "MySQL is up"
            return 0
        fi
        sleep 2
    done
    log "ERROR: MySQL at ${host}:${port} did not become reachable in time"
    return 1
}

install_php_dependencies() {
    if [ ! -f vendor/autoload.php ]; then
        log "installing PHP dependencies (first boot, this takes a minute)"
        composer install --no-interaction --prefer-dist
        own vendor composer.lock
    fi
}

install_node_dependencies() {
    if [ ! -d node_modules/vite ]; then
        log "installing Node dependencies"
        npm ci --no-audit --no-fund
        own node_modules
    fi
}

build_assets() {
    if [ ! -f public/build/manifest.json ]; then
        log "building front-end assets"
        npm run build
        own public/build
    fi
}

# Must happen before `composer install`, because its package-discovery script
# boots Laravel, which wants a .env.
ensure_env_file() {
    if [ ! -f .env ]; then
        log ".env missing, copying .env.example"
        cp .env.example .env
        own .env
    fi
}

# Must happen after `composer install`: artisan needs vendor/autoload.php.
ensure_app_key() {
    if ! grep -qE '^APP_KEY=.+' .env; then
        log "generating APP_KEY"
        php artisan key:generate --force
    fi
}

fix_permissions() {
    own storage bootstrap/cache
    chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
}

case "$ROLE" in
app)
    ensure_env_file
    install_php_dependencies
    ensure_app_key
    fix_permissions
    install_node_dependencies
    build_assets
    wait_for_db
    log "running migrations"
    php artisan migrate --force
    # The starting settings and cook schedule (docs/decisions.md). Only ever adds,
    # so a value the household has changed on the Settings page survives a restart.
    log "installing household defaults"
    php artisan db:seed --class=Database\\Seeders\\HouseholdDefaultsSeeder --force
    log "clearing stale caches"
    php artisan config:clear
    php artisan view:clear
    log "ready on http://localhost:${APP_PORT:-8000}"
    ;;
scheduler)
    # The app container owns installs and migrations; just wait them out. compose
    # already gates this on `app` being healthy, so this is belt and braces.
    log "waiting for the app container to finish installing"
    for _ in $(seq 1 150); do
        if [ -f vendor/autoload.php ] && [ -f .env ]; then
            break
        fi
        sleep 2
    done
    if [ ! -f vendor/autoload.php ]; then
        log "ERROR: vendor/autoload.php never appeared"
        exit 1
    fi
    wait_for_db
    log "scheduling in time zone ${TZ:-UTC}; next run at $(date '+%H:%M:%S %Z')"
    ;;
*)
    log "ERROR: unknown CONTAINER_ROLE '$ROLE' (expected 'app' or 'scheduler')"
    exit 1
    ;;
esac

exec "$@"
