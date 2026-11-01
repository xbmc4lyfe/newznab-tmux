# Docker stack

The Rust-native NNTmux stack. Everything is in [`docker/`](docker/): the Compose file, the stack Justfile, the deploy script and the config. The image is built from the root [`Dockerfile`](Dockerfile). For how the rewrite is organised see [rust/WORKSTREAMS.md](rust/WORKSTREAMS.md); for moving production from PHP see [rust/CUTOVER.md](rust/CUTOVER.md).

## Prerequisites

- Git, Python 3, and [just](https://github.com/casey/just) 1.43 or newer (older packages lack modules).
- Docker with Compose v2. Docker Desktop or OrbStack on macOS; Docker Engine plus the Compose plugin on Linux.
- Usenet provider credentials.
- A large host. MariaDB has an 8 GiB buffer pool (`docker/config/mariadb/conf.d/99-nntmux.cnf`) and the memory limits in `docker/.env` total roughly 30 GiB. Lower both for a smaller machine.

## What runs

All app services use one image (`APP_IMAGE`, default `nntmux/rust:local`) with a different command. Each serves `/healthz` and `/metrics` on port 9187.

| Service | Command | Purpose |
|---|---|---|
| `migrate` | `nntmux-ctl migrate` | One-shot. Applies pending schema migrations; every app service waits for it. |
| `web` | `nntmux-web` | Site, Newznab API, RSS, NZB downloads. Behind `proxy` (Caddy) on `APP_PORT` (8080). |
| `supervisor` | `nntmux-worker supervisor` | Scheduled tasks. Holds a database lease, so a second copy waits. |
| `releases`, `fixnames`, `predb` | `nntmux-worker <role>` | Release assembly and NZBs, name fixing, PreDB. |
| `fingerprint`, `verify` | `nntmux-worker <role>` | Release fingerprints and verification. Long units; 1920 s stop grace. |
| `search-sync` | `nntmux-worker search-sync` | Keeps Manticore current. |
| `ingest`, `backfill`, `postprocess`, `mail` | `nntmux-worker <role>` | Not ported yet: in the Compose `pending` profile, so a normal `up` skips them. |
| `mariadb`, `redis`, `manticore`, `mailpit` | | Data services and a mail catcher (Mailpit at 127.0.0.1:8025). |
| `prometheus`, `pushgateway`, `grafana`, exporters | | Monitoring. |

State is bind-mounted under `docker/data/` and `docker/logs/`. Dumps go to `docker/backups/`.

## Fresh install

For an empty database only:

```bash
# docker/config/usenet_servers.json: {"usenet_servers": [{"host", "port", "ssl", "username", "password", "connections"}]}
chmod 600 docker/config/usenet_servers.json
export NNTP_PRIMARY_HOST=news.example.com NNTP_ALT_HOST=news.example.com
just docker init
```

`init` runs `docker/bin/generate-env` (writes `docker/.env` and `docker/config/app.env` with generated secrets; existing values are never overwritten), builds the image, starts MariaDB, Redis, Manticore and Mailpit, runs `nntmux-ctl init` (schema, seed, administrator, search tables), applies `docker/config/seed/groups.sql` and `tuning.sql`, then starts the rest and waits for health. `nntmux-ctl init` refuses a database that already has tables. Review the seed files first: they enable starter groups and set worker counts for a large provider plan.

Open <http://localhost:8080> and log in as `admin`. The password is `ADMIN_PASS` in `docker/config/app.env`.

## Everyday commands

Run from the repository root. `just docker help` lists them all.

```bash
just docker ps                        # status and health
just docker logs s=releases           # follow logs (s=service)
just docker restart s=verify          # restart one service; workers drain first
just docker ctl cmd="search inspect"  # nntmux-ctl in a one-off container
just docker status                    # groups, binaries, releases, predb counts
just docker mysql                     # MariaDB client (bundled MariaDB only)
just docker backup                    # dump to docker/backups/, keeps 7
just docker down                      # stop the stack; data is kept
just docker up                        # start as built, no build or git checks
```

Pass `KEY=VALUE` after a recipe to set an option for one run, for example `just deploy DEPLOY_BY=me`. The recipes read `docker/.env` for the keys they need.

## Deploy

`just deploy` runs [`docker/bin/deploy`](docker/bin/deploy). Only the deployment owner runs it. It needs `DEPLOY_BY`.

1. Takes a lock directory (`DEPLOY_LOCK_DIR`, default `docker/.deploy.lock`). If another deploy holds it, it exits with status 75.
2. Requires the checkout to be on `DEPLOY_GIT_BRANCH` (default `master`) with no commits missing from `DEPLOY_GIT_REMOTE` (default `origin`), and fast-forwards it. `DEPLOY_SYNC_REMOTE=0` skips this, for offline testing only.
3. Builds the image while the old stack keeps running.
4. Runs `docker compose up -d --wait` (timeout `DEPLOY_HEALTH_TIMEOUT`, default 600 s). `migrate` runs first; changed services are recreated; unchanged ones are left alone.
5. Appends `deploy result=started` and `deploy result=finished rc=<n>` lines to `DEPLOY_LOG` (default `docker/.deploy.log`).

Workers get SIGTERM, stop claiming work, and finish in-flight units within their `stop_grace_period` (30 s `web`, 120 s default, 1920 s `fingerprint`, `verify` and `postprocess`). A long job can delay a rollout.

`just deploy-plan` (or `DRY_RUN=1 just deploy`) prints what would happen without building. `just deploy <service>` rolls out only the named services. Until the cutover, a rust-native checkout needs `DEPLOY_GIT_BRANCH=rust-native`.

## Configuration

- `docker/.env`: Compose settings (ports, memory limits, image tags, `DEPLOY_*`, the Grafana password). Template: `docker/.env.example`.
- `docker/config/app.env`: application settings read by every role: `DB_*`, `REDIS_*`, `MANTICORESEARCH_*`, `NNTP_*`, `APP_KEY`, metadata provider keys. Template: `docker/config/app.env.example`.
- Keep both private. They are ignored by Git and kept out of the image.
- An external MariaDB: set `DB_*` in `app.env`, plus `MYSQL_ATTR_SSL_CA` for TLS, and use a `COMPOSE_FILE` override list. `migrate` doesn't require the bundled `mariadb` service. `just docker mysql` and `backup` work only with the bundled one.

## Monitoring

- Grafana listens on `127.0.0.1:${GRAFANA_PORT:-3000}` with its own login: user `nntmux-grafana-admin`, password `GRAFANA_ADMIN_PASSWORD` in `docker/.env`. It isn't reachable through the web app. Provisioned dashboards are NNTmux Host, Services and Processing.
- Prometheus listens on `127.0.0.1:${PROMETHEUS_PORT:-9090}` and scrapes every role's `:9187/metrics` as job `nntmux-roles`, plus node, MariaDB, Redis and Manticore exporters and the Pushgateway. The supervisor pushes database-derived `nntmux_*` metrics there each minute. Config: `docker/config/prometheus/prometheus.yml`; alert rules: `docker/monitoring/prometheus/rules/`; dashboards: `docker/monitoring/grafana/`.
- Add a role to the `nntmux-roles` targets in the PR that moves it out of the `pending` profile.

## API for NZBHydra2

Add NNTmux to NZBHydra2 as a Newznab indexer at `/api/v1/api` with a user's API key. Check it with `/api/v1/api?t=caps`, then `t=search`, `t=tvsearch`, `t=movie` and `t=get`. The JSON API is under `/api/v2/`.

## Remote verifier

[`docker/worker/`](docker/worker/docker-compose.yml) runs only the `verify` role on another host. It uses the primary's MariaDB, publishes verified candidates through the primary's internal endpoint, and runs a small private Redis. It needs no NZB storage or Manticore.

On the primary, `INTERNAL_BIND` and `INTERNAL_PORT` (default `127.0.0.1:8090`) publish Caddy's internal listener. It has no authentication, so bind it only to an interface the worker host reaches. On the worker host, from `docker/worker/`:

1. Copy `config/app.env.example` to `config/app.env`. Fill in the database login, this host's NNTP account, `RELEASE_VERIFICATION_HOST`, a slot range no other host uses, and `RELEASE_VERIFICATION_PUBLISH_URL`.
2. Copy the database CA to `config/ca.pem`.
3. Load the primary's exact image: `ssh <primary> 'docker save nntmux/rust:local' | docker load`. A worker on older code must not run after a migration.
4. `docker compose up -d`.

Before the primary migrates verification tables, run `docker compose stop` here so the role finishes its active candidates (stop grace 900 s), then start it again on the new image.

## Troubleshooting

- A failed `init` against a partly initialized database needs recovery, not a reset. Read `just docker logs` first.
- A stale `docker/.deploy.lock`: check `docker/.deploy.log` and that no deploy is running, then remove the directory by hand.
- Commands that need the bundled database (`mysql`, `backup`, `status`) fail on an external MariaDB. Use your own client.
