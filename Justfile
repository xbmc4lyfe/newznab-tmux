# ──────────────────────────────────────────────────────────────
# NNTmux – Root Justfile (Production Stack + Dev / Sail Recipes)
# ──────────────────────────────────────────────────────────────
#
# Run `just` or `just help` to see all available recipes.
# Production self-hosted stack lives in `docker/Justfile` (`just docker <recipe>`
# or `just deploy`, `just deploy-plan`, `just build-stack`).
# ──────────────────────────────────────────────────────────────
# No dotenv-load: just exports dotenv values to every module recipe, and the Laravel .env must
# never shadow docker/.env in the production stack. Read only the keys these recipes need.
set positional-arguments := true
set shell := ["bash", "-cu"]
# The running just binary, so nested calls work where just is not on PATH (noninteractive SSH).
export JUST := just_executable()

mod docker 'docker/Justfile'

export SAIL := env("SAIL", "./sail")
export DOCKER_COMPOSE := env("DOCKER_COMPOSE", "docker compose")
SEARCH_DRIVER := env("SEARCH_DRIVER", `scripts/dotenv-get --raw SEARCH_DRIVER`)
ENV_COMPOSE_PROFILES := `scripts/dotenv-get --raw COMPOSE_PROFILES __absent__`
# Like the former `export COMPOSE_PROFILES ?= $(SEARCH_DRIVER)` after `-include .env`: a COMPOSE_PROFILES
# line (even empty) wins, otherwise SEARCH_DRIVER.
PROFILES := env("COMPOSE_PROFILES", if ENV_COMPOSE_PROFILES != "__absent__" { ENV_COMPOSE_PROFILES } else { SEARCH_DRIVER })
MONITORING_ENABLED := env("MONITORING_ENABLED", `scripts/dotenv-get MONITORING_ENABLED`)
# Recipes read the live value as ${MONITORING_ENABLED-$MONITORING_DEFAULT}, after parsing trailing
# KEY=VAL arguments, so `just up MONITORING_ENABLED=true` (or =false, or =) overrides the .env value.
export MONITORING_DEFAULT := MONITORING_ENABLED
FORWARD_PROMETHEUS_PORT := env("FORWARD_PROMETHEUS_PORT", `scripts/dotenv-get FORWARD_PROMETHEUS_PORT 9090`)
WWWUSER := env("WWWUSER", `scripts/dotenv-get WWWUSER`)
export SERVICE := env("SERVICE", "")
export CMD := env("CMD", "")
export cmd := env("cmd", "")
export FORCE := env("FORCE", "")
export MAINTENANCE := env("MAINTENANCE", "")
export filter := env("filter", "")
# Overridable like the former `HOST_UID ?= $(shell id -u)`: environment, root .env, or arguments.
HOST_UID := env("HOST_UID", `scripts/dotenv-get HOST_UID "$(id -u)"`)
HOST_GID := env("HOST_GID", `scripts/dotenv-get HOST_GID "$(id -g)"`)
MONITORING_KEY := "storage/app/monitoring/public/grafana-jwt.pub"
MONITORING_SERVICES := "prometheus pushgateway grafana node-exporter mysqld-exporter redis-exporter"
# Every Compose call gets the search profile and, once monitoring is installed, its overlay (as the
# former Makefile exported globally).
# Runs argv[2:] followed by argv[1] parsed with shell quoting (shlex): no eval, no re-splitting or
# globbing of the parsed words, so `cmd='some:command --label="two words" *'` reaches Artisan as
# `some:command`, `--label=two words`, `*`. An empty string adds no arguments.
EXEC_SPLIT := '''import os, shlex, sys
try:
    extra = shlex.split(sys.argv[1])
except ValueError as error:
    sys.exit("Invalid quoting in cmd: " + str(error))
argv = sys.argv[2:] + extra
os.execvp(argv[0], argv)'''
COMPOSE_ENV := 'export COMPOSE_PROFILES="${COMPOSE_PROFILES-' + PROFILES + '}"; if [ -z "${COMPOSE_FILE:-}" ] && [[ "${MONITORING_ENABLED-$MONITORING_DEFAULT}" =~ ^(true|1|yes)$ ]] && [ -f "' + MONITORING_KEY + '" ]; then export COMPOSE_FILE="docker-compose.yml:docker-compose.monitoring.yml"; fi'
MONITORING_COMPOSE := '$DOCKER_COMPOSE -f docker-compose.yml -f docker-compose.monitoring.yml $(IFS=,; for profile in $COMPOSE_PROFILES; do printf -- "--profile %s " "$profile"; done)'

# Show available recipes
help *args:
    @"$JUST" --list --justfile "{{justfile()}}"

# ── Production Self-Hosted Stack Shortcuts (delegates to docker/Justfile) ──

# Smart selective deploy of the production docker/ stack (or specific component(s): `just deploy web`)
deploy *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy "$@"

# Show per-service change detection and selective deployment plan (or for specific component(s))
deploy-plan *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy-plan "$@"

# Deploy only the web service
deploy-web *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy web "$@"

# Deploy only the Horizon queue worker service
deploy-horizon *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy horizon "$@"

# Deploy only the scheduler service (with cooperative drain)
deploy-scheduler *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy scheduler "$@"

# Deploy only the indexer service (with cooperative tmux:drain)
deploy-indexer *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy indexer "$@"

# Deploy only the Caddy reverse proxy service
deploy-proxy *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy proxy "$@"

# Deploy only the monitoring services (Prometheus, Grafana, Pushgateway, exporters)
deploy-monitoring *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy monitoring "$@"

# Deploy only the Redis service (drains writers first)
deploy-redis *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy redis "$@"

# Deploy only the Manticore search service (drains writers first)
deploy-manticore *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy manticore "$@"

# Deploy only the MariaDB database service (drains writers and migrates)
deploy-mariadb *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy mariadb "$@"

# Deploy only the Mailpit service
deploy-mailpit *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy mailpit "$@"

# Deploy only the Grafana service
deploy-grafana *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy grafana "$@"

# Deploy only the Prometheus service
deploy-prometheus *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy prometheus "$@"

# Build dirty self-hosted service image targets
build-stack *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" build "$@"

# Build only the cached base OS/PHP-extension image (nntmux/base:local)
build-base *args:
    @"$JUST" --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" build-base "$@"

# ── Pre-flight ───────────────────────────────────────────────

# Verify .env exists before running Sail targets
check-env *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    if [ ! -f .env ]; then echo "✘ .env not found. Run: cp .env.example .env"; exit 1; fi
    if [[ "${MONITORING_ENABLED-$MONITORING_DEFAULT}" =~ ^(true|1|yes)$ ]] && [ ! -f "{{MONITORING_KEY}}" ]; then
        echo "⚠  MONITORING_ENABLED=true but {{MONITORING_KEY}} is missing; monitoring stays off. Run: php artisan monitoring:install --sail"
    fi

# ── Sail / Dev Lifecycle ─────────────────────────────────────

# Start all Sail services in the background
up *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{SAIL}} up -d

# Stop all Sail services
down *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    {{SAIL}} down

# Alias for 'down'
stop *args:
    @"$JUST" --justfile "{{justfile()}}" down "$@"

# Restart all Sail services
restart *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    {{SAIL}} restart

# Force-recreate Sail containers without rebuilding
recreate *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{SAIL}} up -d --force-recreate --remove-orphans

# Always pull latest service images for configured tags (skips buildable)
pull *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{DOCKER_COMPOSE}} pull --ignore-buildable --policy always

# Pull latest service and base images, then build (cached layers OK)
build *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{DOCKER_COMPOSE}} pull --ignore-buildable --policy always
    {{SAIL}} build --pull

# Rebuild from scratch with fresh base images and recreate
rebuild *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{DOCKER_COMPOSE}} pull --ignore-buildable --policy always
    {{SAIL}} build --no-cache --pull
    {{SAIL}} up -d --force-recreate --remove-orphans
    echo "✔ Rebuild complete."

# Infra-only: pull base images, rebuild with --pull, restart
update *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    {{DOCKER_COMPOSE}} pull --ignore-buildable --policy always
    {{SAIL}} build --pull
    {{SAIL}} up -d --remove-orphans
    echo "✔ Infra updated."

# Full app upgrade: update + perms + composer + npm + migrate + caches
upgrade *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" update "$@"
    "$JUST" --justfile "{{justfile()}}" fix-permissions "$@"
    "$JUST" --justfile "{{justfile()}}" composer-install "$@"
    "$JUST" --justfile "{{justfile()}}" npm-build "$@"
    if [ "${MAINTENANCE:-}" = "1" ]; then {{SAIL}} artisan down; fi
    {{SAIL}} artisan migrate --force
    if [ "${MAINTENANCE:-}" = "1" ]; then {{SAIL}} artisan up; fi
    "$JUST" --justfile "{{justfile()}}" data-cache "$@"
    "$JUST" --justfile "{{justfile()}}" optimize "$@"
    echo "✔ Upgrade complete."

# Destroy ALL volumes, rebuild, and start clean (DATA LOSS!)
fresh *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    if [ "${FORCE:-}" != "1" ]; then
        printf "⚠  This will destroy all Docker volumes (database, redis, search index). Continue? [y/N] "
        read -r confirm
        [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || { echo "Aborted."; exit 1; }
    else
        echo "⚠  FORCE=1 set — skipping confirmation."
    fi
    {{SAIL}} down -v
    {{DOCKER_COMPOSE}} pull --ignore-buildable --policy always
    {{SAIL}} build --no-cache --pull
    {{SAIL}} up -d --force-recreate --remove-orphans
    echo "✔ Fresh environment is up. Run 'just artisan cmd=nntmux:install' to initialise."

# ── Shell Access ─────────────────────────────────────────────

# Open a bash shell in the app container
shell *args:
    @{{COMPOSE_ENV}}; $SAIL shell

# Open a root bash shell in the app container
root-shell *args:
    @{{COMPOSE_ENV}}; $SAIL root-shell

# Open a Laravel Tinker session
tinker *args:
    @{{COMPOSE_ENV}}; $SAIL tinker

# ── Artisan / PHP ────────────────────────────────────────────

# Run an artisan command (usage: just artisan cmd="migrate")
artisan *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    target_cmd="${cmd:-${CMD:-}}"
    exec python3 -I -c '{{EXEC_SPLIT}}' "$target_cmd" $SAIL artisan

# Run pending database migrations
migrate *args:
    @{{COMPOSE_ENV}}; $SAIL artisan migrate --force

# Drop all tables and re-run migrations (DATA LOSS!)
migrate-fresh *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    if [ "${FORCE:-}" != "1" ]; then
        printf "⚠  This will DROP ALL TABLES and re-run every migration. Continue? [y/N] "
        read -r confirm
        [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || { echo "Aborted."; exit 1; }
    else
        echo "⚠  FORCE=1 set — skipping confirmation."
    fi
    $SAIL artisan migrate:fresh --force

# Run database seeders
seed *args:
    @{{COMPOSE_ENV}}; $SAIL artisan db:seed --force

# Clear config / route / view / application caches
cache-clear *args:
    @{{COMPOSE_ENV}}; $SAIL artisan config:clear
    @{{COMPOSE_ENV}}; $SAIL artisan route:clear
    @{{COMPOSE_ENV}}; $SAIL artisan view:clear
    @{{COMPOSE_ENV}}; $SAIL artisan cache:clear
    @rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/services.php bootstrap/cache/packages.php
    @echo "✔ Caches cleared."

# Dev-safe optimize: warm data structures + view cache only
optimize *args:
    @{{COMPOSE_ENV}}; $SAIL artisan view:cache
    @{{COMPOSE_ENV}}; $SAIL artisan data:cache-structures
    @echo "✔ Optimized (dev-safe)."

# Production optimize: cache config / routes / views + data structures
optimize-deploy *args:
    @echo "⚠  This caches absolute paths from inside the container — only run on the deploy host."
    @{{COMPOSE_ENV}}; $SAIL artisan config:cache
    @{{COMPOSE_ENV}}; $SAIL artisan route:cache
    @{{COMPOSE_ENV}}; $SAIL artisan view:cache
    @{{COMPOSE_ENV}}; $SAIL artisan data:cache-structures
    @echo "✔ Optimized for deploy."

# Run a foreground queue worker
queue-work *args:
    @{{COMPOSE_ENV}}; $SAIL artisan queue:work

# Signal all queue workers to restart gracefully
queue-restart *args:
    @{{COMPOSE_ENV}}; $SAIL artisan queue:restart

# Start the NNTmux tmux processing engine
tmux-start *args:
    @{{COMPOSE_ENV}}; $SAIL artisan tmux:start

# Stop the NNTmux tmux processing engine
tmux-stop *args:
    @{{COMPOSE_ENV}}; $SAIL artisan tmux:stop

# Attach to the running tmux session
tmux-attach *args:
    @{{COMPOSE_ENV}}; $SAIL artisan tmux:attach

# Show Horizon status
horizon *args:
    @{{COMPOSE_ENV}}; $SAIL artisan horizon:status

# ── Testing & Quality ────────────────────────────────────────

# Run the PHPUnit test suite (usage: just test filter=TestName)
test *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    if [ -n "${filter:-}" ]; then
        "$SAIL" test "--filter=${filter}"
    else
        "$SAIL" test
    fi

# Run Laravel Pint code formatter on dirty files
pint *args:
    @{{COMPOSE_ENV}}; $SAIL pint --dirty

# Run Laravel Pint on all files
pint-all *args:
    @{{COMPOSE_ENV}}; $SAIL pint

# Run PHPStan static analysis
phpstan *args:
    @{{COMPOSE_ENV}}; $SAIL php vendor/bin/phpstan analyse --memory-limit=2G

# Run Rector in dry-run mode (no changes written)
rector *args:
    @{{COMPOSE_ENV}}; $SAIL php vendor/bin/rector process --dry-run

# Apply Rector refactorings
rector-fix *args:
    @{{COMPOSE_ENV}}; $SAIL php vendor/bin/rector process

# ── Frontend ─────────────────────────────────────────────────

# Run npm install and build inside the container
npm-build *args:
    @{{COMPOSE_ENV}}; $SAIL npm install
    @{{COMPOSE_ENV}}; $SAIL npm run build

# Start Vite dev server inside the container
npm-dev *args:
    @{{COMPOSE_ENV}}; $SAIL npm run dev

# Regenerate TypeScript types from PHP DTOs/Enums
ts-types *args:
    @{{COMPOSE_ENV}}; $SAIL artisan typescript:transform

# CI: regenerate TS types and fail if working tree drifts
ts-types-check *args:
    @{{COMPOSE_ENV}}; $SAIL artisan typescript:transform --quiet
    @git diff --exit-code resources/js/types/generated.d.ts || (echo "❌ resources/js/types/generated.d.ts is out of date — run 'just ts-types' and commit." && exit 1)

# Cache spatie/laravel-data structures (run on deploy)
data-cache *args:
    @{{COMPOSE_ENV}}; $SAIL artisan data:cache-structures

# ── Dependencies ─────────────────────────────────────────────

# Run composer install inside the container
composer-install *args:
    @{{COMPOSE_ENV}}; $SAIL composer install

# Run composer update inside the container
composer-update *args:
    @{{COMPOSE_ENV}}; $SAIL composer update

# ── Database / Services ──────────────────────────────────────

# Open a MariaDB CLI session
db *args:
    @{{COMPOSE_ENV}}; $SAIL mariadb

# Open a Redis CLI session
redis-cli *args:
    @{{COMPOSE_ENV}}; $SAIL redis

# Align UIDs: remap sail to host UID, chown project, register git safe.directory
fix-permissions *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    uid="${HOST_UID:-{{HOST_UID}}}" gid="${HOST_GID:-{{HOST_GID}}}"
    echo "→ Remapping container 'sail' user to UID $uid and chowning /var/www/html…"
    $DOCKER_COMPOSE exec -u root laravel.test usermod -u "$uid" -o sail >/dev/null 2>&1 || true
    $DOCKER_COMPOSE exec -u root laravel.test groupmod -g "$gid" -o sail >/dev/null 2>&1 || true
    $DOCKER_COMPOSE exec -u root laravel.test chown -R "$uid:$gid" /var/www/html
    $DOCKER_COMPOSE exec -u root laravel.test git config --system --add safe.directory /var/www/html
    echo "✔ Permissions fixed."
    if [ "{{WWWUSER}}" != "$uid" ] || [ -z "{{WWWUSER}}" ]; then echo "⚠  WWWUSER ({{WWWUSER}}) ≠ host UID ($uid). Set WWWUSER=$uid and WWWGROUP=$gid in .env, then run 'just rebuild' to align the container's sail user."; fi

# Alias for 'fix-permissions'
fix-perms *args:
    @"$JUST" --justfile "{{justfile()}}" fix-permissions "$@"

# Register /var/www/html as a git safe.directory inside the container
git-safe-directory *args:
    @{{COMPOSE_ENV}}; $DOCKER_COMPOSE exec -u root laravel.test git config --system --add safe.directory /var/www/html >/dev/null 2>&1 || true
    @{{COMPOSE_ENV}}; $DOCKER_COMPOSE exec laravel.test git config --global --add safe.directory /var/www/html >/dev/null 2>&1 || true

# ── Monitoring ───────────────────────────────────────────────

# Start only the monitoring containers
monitoring-up *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    "$JUST" --justfile "{{justfile()}}" check-env
    if [ ! -f "{{MONITORING_KEY}}" ]; then echo "✘ No Grafana JWT key. Run: php artisan monitoring:install --sail"; exit 1; fi
    {{MONITORING_COMPOSE}} up -d
    echo "✔ Monitoring started. Open /admin/monitoring (Prometheus: http://127.0.0.1:${FORWARD_PROMETHEUS_PORT:-{{FORWARD_PROMETHEUS_PORT}}})."

# Stop and remove only the monitoring containers
monitoring-down *args:
    #!/usr/bin/env bash
    set -euo pipefail
    {{COMPOSE_ENV}}
    {{MONITORING_COMPOSE}} rm --stop --force {{MONITORING_SERVICES}}

# Follow logs of the monitoring containers
monitoring-logs *args:
    #!/usr/bin/env bash
    set -euo pipefail
    {{COMPOSE_ENV}}
    {{MONITORING_COMPOSE}} logs -f --tail=100 {{MONITORING_SERVICES}}

# ── Logs & Status ────────────────────────────────────────────

# Tail logs (usage: just logs SERVICE=mariadb)
logs *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    $DOCKER_COMPOSE logs -f --tail=100 ${SERVICE:-}

# Tail storage/logs/laravel.log inside the app container
tail-laravel *args:
    @{{COMPOSE_ENV}}; $SAIL exec laravel.test tail -f storage/logs/laravel.log

# Show running containers and their status
status *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    $DOCKER_COMPOSE ps

# Alias for 'status'
ps *args:
    @"$JUST" --justfile "{{justfile()}}" status "$@"

# Show running processes inside each container
top *args:
    #!/usr/bin/env bash
    set -euo pipefail
    {{COMPOSE_ENV}}
    $DOCKER_COMPOSE top

# Show images used by each service
images *args:
    #!/usr/bin/env bash
    set -euo pipefail
    {{COMPOSE_ENV}}
    $DOCKER_COMPOSE images

# Show healthcheck status for each service
health *args:
    #!/usr/bin/env bash
    set -euo pipefail
    {{COMPOSE_ENV}}
    # A Go template yields one `service: health` line per container (health is empty without a healthcheck).
    if ! health=$($DOCKER_COMPOSE ps --format '{{{{.Service}}: {{{{.Health}}' 2>/dev/null); then
        $DOCKER_COMPOSE ps
        exit
    fi
    printf '%s\n' "$health" | sort -u

# ── Cleanup ──────────────────────────────────────────────────

# Remove stopped containers and dangling images
clean *args:
    @docker system prune -f
    @echo "✔ Cleaned up dangling resources."

# Remove ALL project containers, images, and volumes (DATA LOSS!)
nuke *args:
    #!/usr/bin/env bash
    set -euo pipefail
    for arg in "$@"; do [[ "$arg" =~ ^[A-Za-z_][A-Za-z0-9_]*= ]] && export "$arg"; done
    {{COMPOSE_ENV}}
    if [ "${FORCE:-}" != "1" ]; then
        printf "⚠  This will remove ALL project containers/images/volumes. Continue? [y/N] "
        read -r confirm
        [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || { echo "Aborted."; exit 1; }
    else
        echo "⚠  FORCE=1 set — skipping confirmation."
    fi
    $SAIL down -v --rmi all
    echo "✔ All project Docker resources removed."
