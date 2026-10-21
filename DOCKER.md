# Docker Development Environment

> Docker / Sail setup for NNTmux — a Laravel 12 Usenet indexer.

## Prerequisites

- [just](https://github.com/casey/just) 1.43 or newer (older distribution packages lack modules and `module_directory()`)
- Docker & **Docker Compose v2.22+** (the Justfile uses `pull --ignore-buildable`)
- A GitHub (scopeless) token for Composer private packages
- [Laravel Sail](https://laravel.com/docs/12.x/sail) (included as a dev dependency)

## Quick Start

```bash
# 1. Copy and configure your environment
cp .env.example .env
# Edit .env — set DB credentials, COMPOSER_AUTH, and uncomment the
# "Docker service hostnames" block at the bottom.

# 2. Build and start
just build
just up

# 3. Install NNTmux (first run only)
just artisan cmd="nntmux:install"

# 4. Build frontend assets
just npm-build
```

## Environment Configuration

When running via Docker, uncomment the **Docker service hostnames** block
at the bottom of `.env` so the app resolves container names:

```dotenv
DB_HOST=mariadb
REDIS_HOST=redis
MANTICORESEARCH_HOST=manticore
ELASTICSEARCH_HOST=elasticsearch
MAIL_HOST=mailpit
MAIL_PORT=1025
```

### Search Engine Selection

Set `COMPOSE_PROFILES` in `.env` to match your `SEARCH_DRIVER`:

| `SEARCH_DRIVER` | `COMPOSE_PROFILES` |
|------------------|--------------------|
| `manticore`      | `manticore`        |
| `elasticsearch`  | `elasticsearch`    |

Only the selected search service container will start. The other remains
dormant and consumes no resources.

### ManticoreSearch Image Updates

Both `docker-compose.yml` and `docker-compose.yml.prod-dist` intentionally use
the unpinned `manticoresearch/manticore` image. This keeps ManticoreSearch on
the latest published Docker image whenever images are pulled, but it also means
production can receive ManticoreSearch changes without a repository diff.

Before pulling and restarting production, validate the new image in staging by
creating indexes, indexing a small batch, and running representative searches,
filters, sorting, pagination, deletes, suggestions, fuzzy searches, and any raw
SQL diagnostics/reconciliation commands.

Manticore Search 27.1.5 is part of the 25.x-27.x release line that introduced
built-in authentication/authorization, sharded tables, conversational search,
vector-search improvements, and replication layout changes. The app does not
enable Manticore auth by default, but if auth is enabled on the server set either
`MANTICORESEARCH_USERNAME`/`MANTICORESEARCH_PASSWORD` or
`MANTICORESEARCH_TOKEN` before running the app, index-creation commands, or raw
HTTP diagnostics. Roll auth out in staging first; anonymous local Docker usage
continues to work with these variables blank.

Native Ubuntu/Debian package installs are managed by `systemd`, not Docker. If
the package install fails while starting `manticore.service`, especially with
`Status: "Replaying binlogs..."` and `accept() failed ... Too many open files`,
or if `searchd` prints a backtrace during binlog replay, use
[`docs/manticore-ubuntu-package.md`](docs/manticore-ubuntu-package.md) to raise
the service file descriptor limit, preserve crash evidence, quarantine bad
binlogs if needed, and finish `dpkg` configuration.

## Just Recipes

Run `just` or `just help` to see all recipes, or `just docker <recipe>` (or `just --justfile docker/Justfile <recipe>`) for the self-hosted `docker/` stack.

### Self-Hosted Stack (`docker/Justfile`)

| Recipe | Description |
|--------|-------------|
| `just deploy-plan` | Show per-service change classification, dirty image targets, and selective rollout plan |
| `just deploy` | Smart guarded deploy: skips non-runtime edits, builds/drains/recreates only affected services |
| `just docker build-base` | Build the cached OS + PHP extension base image (`nntmux/base:local`) |
| `just docker build` | Build dirty self-hosted service image targets (`selfhosted-web`, `selfhosted-horizon`, `selfhosted-scheduler`, `selfhosted-indexer`) |
| `just docker up` | Initial bringup or guarded deploy when app containers exist |

### Lifecycle

| Recipe            | Description                                                     |
|-------------------|-----------------------------------------------------------------|
| `just up`         | Start all services (detached)                                   |
| `just down`       | Stop all services                                               |
| `just restart`    | Restart all services                                            |
| `just recreate`   | Force-recreate containers without rebuilding                    |
| `just build`      | Build the app image (cached)                                    |
| `just rebuild`    | Pull fresh base images + `--no-cache` build + force-recreate    |
| `just pull`       | Pull latest enabled-profile base images (skips buildable ones)  |
| `just update`     | Infra-only: pull + `--pull` build + restart                     |
| `just upgrade`    | App upgrade: `update` + composer + npm + migrate + caches       |
| `just fresh`      | **Destroy volumes**, pull, rebuild, recreate (DATA LOSS)        |

### Development

| Recipe              | Description                                           |
|---------------------|-------------------------------------------------------|
| `just shell`        | Bash shell in the app container                       |
| `just root-shell`   | Root bash shell in the app container                  |
| `just tinker`       | Laravel Tinker REPL                                   |
| `just artisan cmd=` | Run any artisan command                               |
| `just migrate`      | Run pending migrations                                |
| `just migrate-fresh`| Drop all tables and re-migrate (DATA LOSS)            |
| `just seed`         | Run database seeders                                  |
| `just cache-clear`  | Clear config / route / view / app caches (and stale `bootstrap/cache` files) |
| `just optimize`     | Dev-safe: warm view cache + spatie/laravel-data           |
| `just optimize-deploy` | Production: also caches config/routes (bakes absolute paths) |
| `just queue-work`   | Foreground queue worker                               |
| `just queue-restart`| Signal queue workers to restart                       |
| `just tmux-start`   | Start the NNTmux tmux processing engine               |
| `just tmux-stop`    | Stop the tmux processing engine                       |
| `just tmux-attach`  | Attach to the running tmux session                    |
| `just horizon`      | Show Horizon queue status                             |
| `just fix-permissions` | Chown project to host UID + register git `safe.directory` |

### Testing & Quality

| Recipe           | Description                                          |
|------------------|------------------------------------------------------|
| `just test`      | PHPUnit tests (`filter=Name` optional)               |
| `just pint`      | Pint formatter on dirty files                        |
| `just pint-all`  | Pint formatter on all files                          |
| `just phpstan`   | PHPStan static analysis (2G memory limit)            |
| `just rector`    | Rector dry-run (no changes)                          |
| `just rector-fix`| Apply Rector refactorings                            |

### Frontend & Types

| Recipe                | Description                          |
|-----------------------|--------------------------------------|
| `just npm-build`      | `npm install` + `npm run build`      |
| `just npm-dev`        | Start Vite dev server                |
| `just ts-types`       | Regenerate TypeScript types          |
| `just ts-types-check` | CI: fail if generated types drift    |
| `just data-cache`     | Cache spatie/laravel-data structures |

### Logs & Status

| Recipe              | Description                                                      |
|---------------------|------------------------------------------------------------------|
| `just logs`         | Tail all container logs (or `SERVICE=name` for one)              |
| `just tail-laravel` | Tail `storage/logs/laravel.log` inside the app container         |
| `just status` / `ps`| Show running containers                                          |
| `just top`          | Show processes inside each container                             |
| `just images`       | Show images used by each service                                 |
| `just health`       | Healthcheck status per service                                   |

### Monitoring

Prometheus, Grafana, the Pushgateway and exporters from `docker-compose.monitoring.yml`. Run `php artisan monitoring:install --sail` once (it sets `MONITORING_ENABLED=true`); from then on `just up`, `just down` and the other lifecycle recipes include them. Grafana is embedded at **Admin → System → Monitoring** (proxied at `/grafana/`).

| Recipe                 | Description                                                    |
|------------------------|----------------------------------------------------------------|
| `just monitoring-up`   | Start only the monitoring containers                           |
| `just monitoring-down` | Stop and remove only them (volumes are kept)                   |
| `just monitoring-logs` | Follow their logs                                              |

### Cleanup

| Recipe        | Description                                                   |
|---------------|---------------------------------------------------------------|
| `just clean`  | Prune stopped containers / dangling images                    |
| `just nuke`   | **Remove ALL** project containers, images, volumes (DATA LOSS)|

### Flags

These can be combined with the recipes above:

| Flag           | Effect                                                                |
|----------------|-----------------------------------------------------------------------|
| `FORCE=1`      | Skip confirmation prompts on `fresh`, `nuke`, `migrate-fresh` (CI use)|
| `MAINTENANCE=1`| Wrap `upgrade` migrations in `artisan down` / `artisan up`            |
| `SERVICE=name` | Restrict `logs` to a specific compose service                         |
| `CMD="…"`      | Free-form command for `artisan` (alternative to `cmd=`)               |
| `MONITORING_ENABLED=true\|false` | Override `.env` for this run of any lifecycle recipe (`up`, `down`, `restart`, `recreate`, `pull`, `build`, `rebuild`, `update`, `upgrade`, `fresh`, `logs`, `status`, `nuke`, `check-env`) |
| `filter=Name`  | Pass `--filter=Name` to `just test`                                   |

Examples:

```bash
just fresh FORCE=1                      # non-interactive teardown + rebuild
just upgrade MAINTENANCE=1              # zero-downtime-ish upgrade with maint mode
just logs SERVICE=mariadb               # tail only mariadb
just test filter=ReleaseSearchTest      # run a single test
just up MONITORING_ENABLED=true         # include the monitoring overlay even though .env disables it
just artisan cmd='some:command --label="two words"'   # cmd is parsed with shell quoting, never globbed
```

`cmd=` (and `CMD`) is split with shell quoting rules into a real argument array, so quoted words
stay together and `*` is passed literally; an unbalanced quote is an error. The same applies to
`just docker artisan cmd=…`.

### Trailing `KEY=VALUE` arguments for `docker/Justfile`

Precedence is trailing arguments, then the environment, then `docker/.env`, then defaults. An
explicitly empty trailing argument counts as supplied: `just deploy COMPOSE_FILE= WEB_IMAGE=`
discards the `docker/.env` values and uses the defaults (`docker/docker-compose.yml`, and the
`nntmux/web:local` or `APP_IMAGE`-derived tag). An empty `COMPOSE_FILE` therefore means "the default
stack file", not "no file". Only arguments can clear a value; an empty variable in the environment is
indistinguishable from an unset one and does not override `docker/.env`.

`DC`, `COMPOSE_FILE` and the `HELPER_`/`BASE_`/`APP_`/`WEB_`/`HORIZON_`/`SCHEDULER_`/`INDEXER_IMAGE` keys
in `docker/.env` must be literal values. The Justfile and `docker/bin/compose` read them without
Compose's `$` interpolation, so an unquoted or double-quoted value containing `$` (for example
`APP_IMAGE=${REGISTRY}/nntmux:${TAG}`) stops the recipe with an error; single-quote the value for a
verbatim `$`. Other keys, such as `MARIADB_IMAGE`, are left to Compose and may interpolate.

> **Note:** `just pull` / `update` / `rebuild` use `docker compose pull --ignore-buildable`,
> which only pulls images for services in the active `COMPOSE_PROFILES` and skips images
> that are built locally (e.g. `sail-8.5/app`). Requires Docker Compose v2.22+.

You can also use `./sail` directly for anything not covered above —
unknown commands are passed through to `docker compose`.

## Networking

Sail creates a Docker network called `sail` with these default port mappings:

| Service        | Container Port | Host Port (default)                      |
|----------------|----------------|------------------------------------------|
| HTTP (nginx)   | 80             | `APP_PORT` (80)                          |
| Vite           | 5173           | `VITE_PORT` (5173)                       |
| MariaDB        | 3306           | `FORWARD_DB_PORT` (3306)                 |
| Redis          | 6379           | `FORWARD_REDIS_PORT` (6379)              |
| Manticore SQL  | 9306           | 9306                                     |
| Manticore HTTP | 9308           | 9308                                     |
| Elasticsearch  | 9200           | 9200                                     |
| Mailpit SMTP   | 1025           | `FORWARD_MAILPIT_PORT` (1025)            |
| Mailpit Web    | 8025           | `FORWARD_MAILPIT_DASHBOARD_PORT` (8025)  |
| Prometheus     | 9090           | `FORWARD_PROMETHEUS_PORT` (9090), 127.0.0.1 only, monitoring overlay |

If a port is already in use on your host, change the corresponding
`FORWARD_*` / `APP_PORT` variable in `.env`.

## Database

On first run MariaDB creates a user/database from your `.env`:

```dotenv
DB_USERNAME=
DB_PASSWORD=
DB_DATABASE=nntmux
```

Import an existing SQL dump or run `just artisan cmd="nntmux:install"` for
a fresh installation.

## Backups

Add this optional service to `docker-compose.yml` for automated MariaDB backups:

```yaml
    backup:
        image: fradelg/mysql-cron-backup
        depends_on:
            - mariadb
        restart: always
        volumes:
            - ./docker/backups:/backup
        environment:
            - MYSQL_USER=${DB_USERNAME}
            - MYSQL_PASS=${DB_PASSWORD}
            - MYSQL_DB=${DB_DATABASE}
            - CRON_TIME=0 3 * * *
            - MYSQL_HOST=mariadb
            - MYSQL_PORT=3306
            - TIMEOUT=10s
            - GZIP_LEVEL=9
            - MAX_BACKUPS=5
            - INIT_BACKUP=0
            - EXIT_BACKUP=1
        networks:
            - sail
```

## Production

For production deployments use `docker-compose.yml.prod-dist` as a starting
point. It includes separate `webapp`, `worker`, and `scheduler` services
and uses the root `Dockerfile` (FrankenPHP-based).

```bash
cp docker-compose.yml.prod-dist docker-compose.yml
# Edit .env for production settings
docker compose up -d
```

## Troubleshooting

| Problem                         | Solution                                                              |
|---------------------------------|-----------------------------------------------------------------------|
| Permission errors on storage/   | `just fix-permissions` (chowns project to your host UID)              |
| `composer install` permission denied | `just fix-permissions`, then re-run `just composer-install`      |
| `npm`/`ncu` EACCES on host (`package.json`) | `just fix-permissions` chowns to host UID, not container `sail` |
| `fatal: detected dubious ownership` (git) | `just fix-permissions` registers `safe.directory` in the container |
| Container `sail` UID ≠ host UID  | Set `WWWUSER=$(id -u)` / `WWWGROUP=$(id -g)` in `.env`, then `just rebuild` |
| Host artisan/npm fails with `/var/www/html/...` paths | Stale `bootstrap/cache/config.php` from a container `config:cache`. Run `just cache-clear` and prefer `just optimize` (dev-safe) over `optimize-deploy` in development |
| Port already in use             | Change `APP_PORT`, `FORWARD_DB_PORT`, etc. in `.env`                  |
| Containers won't start          | `just logs` to inspect, or `just rebuild` to start fresh              |
| Stale images after upgrade      | `just update` (pulls base images + rebuild + restart)                 |
| Need a completely clean slate   | `just fresh` (destroys all volumes; add `FORCE=1` for non-interactive)|
| CI / scripted teardown          | `just fresh FORCE=1` or `just nuke FORCE=1` to skip prompts           |
| supervisorctl not connecting    | `just root-shell` then `supervisorctl status` to verify socket path   |

