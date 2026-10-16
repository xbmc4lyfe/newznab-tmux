# ──────────────────────────────────────────────────────────────
# NNTmux – Root Justfile (Production Stack + Dev / Sail Recipes)
# ──────────────────────────────────────────────────────────────
#
# Run `just` or `just help` to see all available recipes.
# Production self-hosted stack lives in `docker/Justfile` (`just docker <recipe>`
# or `just deploy`, `just deploy-plan`, `just build-stack`).
# ──────────────────────────────────────────────────────────────
set dotenv-load := true
set positional-arguments := true
set shell := ["bash", "-cu"]

mod docker 'docker/Justfile'

SAIL := env("SAIL", "./sail")
DOCKER_COMPOSE := env("DOCKER_COMPOSE", "docker compose")
SERVICE := env("SERVICE", "")
CMD := env("CMD", "")
cmd := env("cmd", "")
FORCE := env("FORCE", "")
MAINTENANCE := env("MAINTENANCE", "")
filter := env("filter", "")
HOST_UID := `id -u`
HOST_GID := `id -g`
MONITORING_KEY := "storage/app/monitoring/public/grafana-jwt.pub"
MONITORING_SERVICES := "prometheus pushgateway grafana node-exporter mysqld-exporter redis-exporter"

# Show available recipes
help:
    @just --list --justfile "{{justfile()}}"

# ── Production Self-Hosted Stack Shortcuts (delegates to docker/Justfile) ──

# Smart selective deploy of the production docker/ stack
deploy *args:
    @just --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy "$@"

# Show per-service change detection and selective deployment plan
deploy-plan *args:
    @just --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" deploy-plan "$@"

# Build dirty self-hosted service image targets
build-stack *args:
    @just --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" build "$@"

# Build only the cached base OS/PHP-extension image (nntmux/base:local)
build-base *args:
    @just --justfile "{{justfile_directory()}}/docker/Justfile" --working-directory "{{justfile_directory()}}/docker" build-base "$@"

# ── Pre-flight ───────────────────────────────────────────────

# Verify .env exists before running Sail targets
check-env:
    @if [ ! -f .env ]; then echo "✘ .env not found. Run: cp .env.example .env"; exit 1; fi
    @if [ "${MONITORING_ENABLED:-}" = "true" ] && [ ! -f "{{MONITORING_KEY}}" ]; then \
        echo "⚠  MONITORING_ENABLED=true but {{MONITORING_KEY}} is missing; monitoring stays off. Run: php artisan monitoring:install --sail"; \
    fi

# ── Sail / Dev Lifecycle ─────────────────────────────────────

# Start all Sail services in the background
up: check-env
    @{{SAIL}} up -d

# Stop all Sail services
down:
    @{{SAIL}} down

# Alias for 'down'
stop: down

# Restart all Sail services
restart:
    @{{SAIL}} restart

# Force-recreate Sail containers without rebuilding
recreate: check-env
    @{{SAIL}} up -d --force-recreate --remove-orphans

# Always pull latest service images for configured tags (skips buildable)
pull: check-env
    @{{DOCKER_COMPOSE}} pull --ignore-buildable --policy always

# Pull latest service and base images, then build (cached layers OK)
build: check-env pull
    @{{SAIL}} build --pull

# Rebuild from scratch with fresh base images and recreate
rebuild: check-env pull
    @{{SAIL}} build --no-cache --pull
    @{{SAIL}} up -d --force-recreate --remove-orphans

# Infra-only: pull base images, rebuild with --pull, restart
update: check-env pull
    @{{SAIL}} build --pull
    @{{SAIL}} up -d --remove-orphans

# Full app upgrade: update + perms + composer + npm + migrate + caches
upgrade: update fix-permissions composer-install npm-build
    @if [ "{{MAINTENANCE}}" = "1" ]; then {{SAIL}} artisan down; fi
    @{{SAIL}} artisan migrate --force
    @if [ "{{MAINTENANCE}}" = "1" ]; then {{SAIL}} artisan up; fi
    @just --justfile "{{justfile()}}" data-cache
    @just --justfile "{{justfile()}}" optimize

# Destroy ALL volumes, rebuild, and start clean (DATA LOSS!)
fresh: check-env
    @if [ "{{FORCE}}" != "1" ]; then printf "⚠  This will destroy all Docker volumes (database, redis, search index). Continue? [y/N] "; read confirm; [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || (echo "Aborted." && exit 1); fi
    @{{SAIL}} down -v
    @{{DOCKER_COMPOSE}} pull --ignore-buildable --policy always
    @{{SAIL}} build --no-cache --pull
    @{{SAIL}} up -d --force-recreate --remove-orphans

# ── Shell Access ─────────────────────────────────────────────

# Open a bash shell in the app container
shell:
    @{{SAIL}} shell

# Open a root bash shell in the app container
root-shell:
    @{{SAIL}} root-shell

# Open a Laravel Tinker session
tinker:
    @{{SAIL}} tinker

# ── Artisan / PHP ────────────────────────────────────────────

# Run an artisan command (usage: just artisan cmd="migrate")
artisan:
    @{{SAIL}} artisan {{cmd}}{{CMD}}

# Run pending database migrations
migrate:
    @{{SAIL}} artisan migrate --force

# Drop all tables and re-run migrations (DATA LOSS!)
migrate-fresh:
    @if [ "{{FORCE}}" != "1" ]; then printf "⚠  This will DROP ALL TABLES and re-run every migration. Continue? [y/N] "; read confirm; [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || (echo "Aborted." && exit 1); fi
    @{{SAIL}} artisan migrate:fresh --force

# Run database seeders
seed:
    @{{SAIL}} artisan db:seed --force

# Clear config / route / view / application caches
cache-clear:
    @{{SAIL}} artisan config:clear
    @{{SAIL}} artisan route:clear
    @{{SAIL}} artisan view:clear
    @{{SAIL}} artisan cache:clear
    @rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/services.php bootstrap/cache/packages.php

# Dev-safe optimize: warm data structures + view cache only
optimize:
    @{{SAIL}} artisan view:cache
    @{{SAIL}} artisan data:cache-structures

# Production optimize: cache config / routes / views + data structures
optimize-deploy:
    @{{SAIL}} artisan config:cache
    @{{SAIL}} artisan route:cache
    @{{SAIL}} artisan view:cache
    @{{SAIL}} artisan data:cache-structures

# Run a foreground queue worker
queue-work:
    @{{SAIL}} artisan queue:work

# Signal all queue workers to restart gracefully
queue-restart:
    @{{SAIL}} artisan queue:restart

# Start the NNTmux tmux processing engine
tmux-start:
    @{{SAIL}} artisan tmux:start

# Stop the NNTmux tmux processing engine
tmux-stop:
    @{{SAIL}} artisan tmux:stop

# Attach to the running tmux session
tmux-attach:
    @{{SAIL}} artisan tmux:attach

# Show Horizon status
horizon:
    @{{SAIL}} artisan horizon:status

# ── Testing & Quality ────────────────────────────────────────

# Run the PHPUnit test suite (usage: just test filter=TestName)
test:
    @php artisan test --compact {{ if filter != "" { "--filter=" + filter } else { "" } }}

# Run Laravel Pint code formatter on dirty files
pint:
    @./vendor/bin/pint --dirty

# Run Laravel Pint on all files
pint-all:
    @./vendor/bin/pint

# Run PHPStan static analysis
phpstan:
    @./vendor/bin/phpstan analyse --memory-limit=2G

# Run Rector in dry-run mode (no changes written)
rector:
    @./vendor/bin/rector process --dry-run

# Apply Rector refactorings
rector-fix:
    @./vendor/bin/rector process

# ── Frontend ─────────────────────────────────────────────────

# Run npm install and build
npm-build:
    @npm install
    @npm run build

# Start Vite dev server
npm-dev:
    @npm run dev

# Regenerate TypeScript types from PHP DTOs/Enums
ts-types:
    @php artisan typescript:transform

# CI: regenerate TS types and fail if working tree drifts
ts-types-check:
    @php artisan typescript:transform --quiet
    @git diff --exit-code resources/js/types/generated.d.ts || (echo "❌ resources/js/types/generated.d.ts is out of date — run 'just ts-types' and commit." && exit 1)

# Cache spatie/laravel-data structures
data-cache:
    @php artisan data:cache-structures

# ── Dependencies ─────────────────────────────────────────────

# Run composer install
composer-install:
    @composer install

# Run composer update
composer-update:
    @composer update

# ── Database / Services ──────────────────────────────────────

# Open a MariaDB CLI session
db:
    @{{SAIL}} mariadb

# Open a Redis CLI session
redis-cli:
    @{{SAIL}} redis

# Align UIDs: remap sail to host UID, chown project, register git safe.directory
fix-permissions:
    @{{DOCKER_COMPOSE}} exec -u root laravel.test usermod -u {{HOST_UID}} -o sail >/dev/null 2>&1 || true
    @{{DOCKER_COMPOSE}} exec -u root laravel.test groupmod -g {{HOST_GID}} -o sail >/dev/null 2>&1 || true
    @{{DOCKER_COMPOSE}} exec -u root laravel.test chown -R {{HOST_UID}}:{{HOST_GID}} /var/www/html
    @{{DOCKER_COMPOSE}} exec -u root laravel.test git config --system --add safe.directory /var/www/html

# Alias for 'fix-permissions'
fix-perms: fix-permissions

# Register /var/www/html as a git safe.directory inside the container
git-safe-directory:
    @{{DOCKER_COMPOSE}} exec -u root laravel.test git config --system --add safe.directory /var/www/html >/dev/null 2>&1 || true
    @{{DOCKER_COMPOSE}} exec laravel.test git config --global --add safe.directory /var/www/html >/dev/null 2>&1 || true

# ── Monitoring ───────────────────────────────────────────────

# Start only the monitoring containers
monitoring-up: check-env
    @if [ ! -f "{{MONITORING_KEY}}" ]; then echo "✘ No Grafana JWT key. Run: php artisan monitoring:install --sail"; exit 1; fi
    @{{DOCKER_COMPOSE}} -f docker-compose.yml -f docker-compose.monitoring.yml up -d

# Stop and remove only the monitoring containers
monitoring-down:
    @{{DOCKER_COMPOSE}} -f docker-compose.yml -f docker-compose.monitoring.yml rm --stop --force {{MONITORING_SERVICES}}

# Follow logs of the monitoring containers
monitoring-logs:
    @{{DOCKER_COMPOSE}} -f docker-compose.yml -f docker-compose.monitoring.yml logs -f --tail=100 {{MONITORING_SERVICES}}

# ── Logs & Status ────────────────────────────────────────────

# Tail logs (usage: just logs SERVICE=mariadb)
logs:
    @{{DOCKER_COMPOSE}} logs -f --tail=100 {{SERVICE}}

# Tail storage/logs/laravel.log inside the app container
tail-laravel:
    @{{SAIL}} exec laravel.test tail -f storage/logs/laravel.log

# Show running containers and their status
status:
    @{{DOCKER_COMPOSE}} ps

# Alias for 'status'
ps: status

# Show running processes inside each container
top:
    @{{DOCKER_COMPOSE}} top

# Show images used by each service
images:
    @{{DOCKER_COMPOSE}} images

# Show healthcheck status for each service
health:
    @{{DOCKER_COMPOSE}} ps --format json 2>/dev/null | awk -F'"' '/"Service"/ {svc=$4} /"Health"/ {print svc": "$4}' | sort -u || {{DOCKER_COMPOSE}} ps

# ── Cleanup ──────────────────────────────────────────────────

# Remove stopped containers and dangling images
clean:
    @docker system prune -f

# Remove ALL project containers, images, and volumes (DATA LOSS!)
nuke:
    @if [ "{{FORCE}}" != "1" ]; then printf "⚠  This will remove ALL project containers/images/volumes. Continue? [y/N] "; read confirm; [ "$confirm" = "y" ] || [ "$confirm" = "Y" ] || (echo "Aborted." && exit 1); fi
    @{{SAIL}} down -v --rmi all
