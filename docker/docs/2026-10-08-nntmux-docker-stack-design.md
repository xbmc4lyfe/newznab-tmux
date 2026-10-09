# NNTmux self-hosted Docker stack: design spec

- **Date:** 2026-10-08
- **Status:** Approved (approach A). Implementation plan: [`2026-10-08-nntmux-docker-stack-plan.md`](./2026-10-08-nntmux-docker-stack-plan.md)
- **Target host:** Apple M5 Mac with 32 GB RAM. OrbStack gives Docker 10 CPUs and about 24.5 GB on linux/arm64. About 400 GB of disk is free.

## 1. Goal

Run the full NNTmux production stack from one compose file in `docker/`:

- Laravel web UI and API
- Horizon queues and the scheduler
- The tmux indexing engine
- A tuned MariaDB
- Manticore search
- Redis
- PreDB ingestion from both IRC and RSS/JSON feeds
- Mail capture
- Prometheus and Grafana monitoring

Every container keeps its persistent files in bind mounts under `docker/`.

**Success criteria**

- `make -C docker up` brings every service to `healthy`.
- The web UI answers on `http://localhost:8080` and an admin can log in.
- Within the first hour: active groups gain headers, then releases, and post-processing panes are working.
- `predb` rows arrive from IRC (`source` like `#PreNNTmux`) and from the feed importer (`source` like `predb.club`).
- Manticore `releases_rt` and `predb_rt` row counts track the database.
- Stopping and recreating every container loses nothing, because all state lives in `docker/data`.

**Non-goals**

- Public exposure with TLS. Caddy can add it later by changing one site address.
- Elasticsearch.
- More than two NNTP providers, since NNTmux supports one primary and one alternate.
- Multi-host deployment.

## 2. Decisions

| # | Decision | Why |
|---|---|---|
| D1 | Model the stack on `deploy/cloud/compose.yaml` (FrankenPHP image; separate web, horizon, scheduler and indexer services) | That is the maintained production layout. `docker-compose.yml.prod-dist` is broken: it calls `tmux-ui:start`, which does not exist. |
| D2 | MariaDB **11.4.8** | The schema dumps use MariaDB-only collations (`utf8mb4_uca1400_ai_ci`). `deploy/cloud` pins 11.4.8. The user chose MariaDB. |
| D3 | Manticore **28.4.4** with `EXTRA=1` | The version the code pins (ADR 0001). Buddy is needed for `/metrics`. |
| D4 | Redis 7.4.2 with AOF and `noeviction` | Redis holds queues, locks and sessions. Evicting them would corrupt work. |
| D5 | An overlay image on top of the root `Dockerfile` | The base image lacks several things NNTmux needs; see below. |
| D6 | Primary NNTP = `news.newshosting.com` (73 connections); alternate = `news.frugalusenet.com` (170 connections) | Only two providers are supported. The alternate is only used to fetch articles missing on the primary. |
| D7 | PreDB comes from the IRC scraper plus a **new** `predb:import-feed` command | Upstream has no RSS/API importer, and predb.ovh (the README's recommendation) no longer resolves. Live feeds checked on 2026-10-08: predb.club (JSON and RSS), api.predb.net (JSON), predb.me (RSS). |
| D8 | Scheduler-side `tmux:health-check --auto-restart` is gated off; the indexer supervises itself | In a split-container layout, the scheduler sees no tmux session and would call `tmux:start` inside the scheduler container, creating a second ingest session. The upstream cloud stack has the same latent bug. |
| D9 | Exposed on `localhost:8080` over plain HTTP | Chosen by the user. Caddy can bind to the LAN with `PROXY_BIND=0.0.0.0`. |
| D10 | Start with a curated set of 20 groups, backfilled 3 days | Chosen by the user. Disk-safe; more groups can be added in the admin UI. |
| D11 | Unrar/unzip scratch space is a `tmpfs` | It is ephemeral, high-churn I/O that should not go through the macOS file-sharing layer. This is the only non-bind-mount path, and it holds no state. |

What the overlay image (D5) adds to the base image:

- Debian **non-free `unrar`**: the base has `unrar-free`, which cannot read RAR5.
- `iproute2`: NNTmux counts NNTP sockets with `ss`.
- `file`, `htop`, `git`.
- `opcache`.
- RapidYenc is **not** built. FrankenPHP's PHP is thread-safe (ZTS), and the RapidYenc FFI path requires a non-threaded build, so the stack runs `YENC_DECODER=php`. Bring-up confirmed this: an earlier build that included RapidYenc logged that native yEnc was unavailable.

Rejected approaches:

- **(B) Use the `deploy/cloud` scripts as they are.** `configure.php` rejects an alternate NNTP server, and the scripts assume a Linux host (LUKS volume, systemd, `docker-daemon.json`) and public TLS.
- **(C) The Sail image (`docker/8.5`) as one container.** It is a development image with xdebug, Node and root cron, running everything under one supervisord.

## 3. Architecture

```
            host :8080 (127.0.0.1 by default)
                   │
              ┌────▼─────┐   forward_auth /grafana/ ──► web:8081 (internal auth vhost)
              │  proxy   │──────────────────────────────► grafana:3000
              │  caddy   │──► web:80 (FrankenPHP, Laravel)
              └──────────┘
 ┌──────────┐ ┌──────────┐ ┌────────────────────────────────────────┐
 │ horizon  │ │scheduler │ │ indexer (tmux: binaries, backfill,     │
 │ queues   │ │schedule: │ │ releases, post-proc, fixnames, IRC)    │──► NNTP primary/alt (563/TLS)
 └────┬─────┘ │work +    │ └───────────────┬────────────────────────┘──► IRC irc.synirc.net:6697
      │       │predb feed│                 │
      │       └────┬─────┘                 │      predb.club / api.predb.net / predb.me (HTTPS)
      ▼            ▼                       ▼
 ┌─────────┐  ┌─────────┐  ┌───────────┐  ┌─────────┐  ┌──────────────────────────────┐
 │ mariadb │  │  redis  │  │ manticore │  │ mailpit │  │ prometheus, pushgateway,     │
 │ 11.4.8  │  │  7.4.2  │  │  28.4.4   │  │         │  │ grafana, mysqld/redis export │
 └─────────┘  └─────────┘  └───────────┘  └─────────┘  └──────────────────────────────┘
```

All services share one bridge network, `nntmux`, on subnet `172.31.42.0/24`, which `TRUSTED_PROXIES` covers. Only `proxy` publishes the app port. Mailpit's UI (8025), Grafana and Prometheus publish on `127.0.0.1` only.

### Services

| Service | Command | Memory cap | Health check |
|---|---|---|---|
| proxy | caddy | 256m | `wget /healthz` |
| web | `frankenphp run` (vhosts on :80 public and :8081 internal) | 1g | `curl /up` |
| horizon | `php artisan horizon` | 1g | `horizon:status` |
| scheduler | `php artisan schedule:work` | 1g | `pgrep -f schedule:work` |
| indexer | `docker/app/bin/indexer.sh` (tmux, tty) | 6g | `tmux:health-check --require-session` |
| mariadb | mariadbd with `conf.d/99-nntmux.cnf` | 13g | `healthcheck.sh --connect --innodb_initialized` |
| redis | `redis-server /usr/local/etc/redis/redis.conf` | 1g | `redis-cli ping` |
| manticore | searchd with the mounted conf | 3g | `mysql -P9306 -e 'SHOW TABLES'` |
| mailpit | default | 128m | built in |
| prometheus, pushgateway, grafana, exporters | pinned images from `docker-compose.monitoring.yml` | about 1g total | built in |

The memory caps add up to about 24 GB, which matches what OrbStack currently allows. The MariaDB buffer pool (8 GB) is the main lever if the Mac needs memory back.

**Shared app settings (x-app):**

- Image: `nntmux/app:local`
- `init: true`, user `33:33`, `SHELL=/bin/bash` (www-data's login shell is `nologin`; tmux panes spawn `$SHELL`)
- `stop_grace_period: 120s`
- `.env` comes from `docker/config/app.env`, bind-mounted read/write. `monitoring:install` writes to `.env`.
- Each app service depends on healthy `mariadb`, `redis` and `manticore`.

## 4. Filesystem layout (bind mounts)

```
docker/
├── docker-compose.yml          # the stack
├── Makefile                    # lifecycle: env, build, init, up, down, logs, tmux, seed, backup
├── .env.example / .env         # compose-level knobs (ports, memory caps, versions); .env is gitignored
├── app/
│   ├── Dockerfile              # overlay FROM nntmux/base:local
│   ├── Caddyfile               # FrankenPHP: :80 public, :8081 internal Grafana auth
│   ├── php/zz-docker.ini       # opcache
│   └── bin/indexer.sh          # tmux supervisor loop
├── config/
│   ├── app.env.example         # Laravel .env template (no secrets)
│   ├── app.env                 # generated, gitignored, mode 600 (NNTP creds, keys)
│   ├── caddy/Caddyfile
│   ├── mariadb/conf.d/99-nntmux.cnf
│   ├── manticore/manticore.conf
│   ├── redis/redis.conf
│   ├── prometheus/prometheus.yml
│   └── seed/{groups.sql,tuning.sql}
├── bin/
│   ├── generate-env            # builds config/app.env from config.json + template
│   └── wait-healthy
├── data/        (gitignored)   # mariadb/ redis/ manticore/ storage/ install/ caddy/ prometheus/ grafana/ pushgateway/
├── logs/        (gitignored)   # mariadb/ manticore/ caddy/
├── backups/     (gitignored)
└── docs/                       # this spec + plan
```

| Host path | Container path | Service(s) |
|---|---|---|
| `data/storage` | `/app/storage` (nzb, covers, logs, framework, `app/monitoring`) | web, horizon, scheduler, indexer |
| `data/install` | `/app/_install` (`install.lock`) | app services |
| `config/app.env` | `/app/.env` | app services |
| `data/mariadb` | `/var/lib/mysql` | mariadb |
| `config/mariadb/conf.d` | `/etc/mysql/conf.d` (ro) | mariadb |
| `logs/mariadb` | `/var/log/mysql` | mariadb |
| `data/manticore` | `/var/lib/manticore` | manticore |
| `config/manticore/manticore.conf` | `/etc/manticoresearch/manticore.conf` (rw: the image entrypoint chowns it) | manticore |
| `logs/manticore` | `/var/log/manticore` | manticore |
| `data/redis` | `/data` | redis |
| `data/caddy/{data,config}` | `/data`, `/config` | proxy |
| `data/prometheus`, `data/grafana`, `data/pushgateway` | service data dirs | monitoring |
| (tmpfs 2g) | `/var/tmp/nntmux` (unrar/unzip scratch) | indexer, horizon |

**Secrets.** `docker/bin/generate-env` reads `docker/config/usenet_servers.json` (gitignored; override with `USENET_SERVERS_JSON=`). It writes the following into `config/app.env` with mode 600:

- The primary and alternate NNTP host, port, user, password and connection counts.
- A random 32-byte `APP_KEY`, DB passwords and a 24-character admin password.
- A unique IRC nickname.

It never overwrites an existing value, so re-running it is safe. No credential is ever written to a tracked file.

The ignore files gain entries for `docker/data/`, `docker/logs/`, `docker/backups/`, `docker/.env` and `docker/config/app.env`. `.gitignore` also gains `!docker/docker-compose.yml`, because the existing rule `docker-compose.yml` is unanchored and would otherwise ignore it.

## 5. Tuning

### 5.1 MariaDB (`config/mariadb/conf.d/99-nntmux.cnf`)

Based on the wiki's "Medium (32 GB)" profile, scaled to a 10 GB container cap.

```ini
[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
skip-name-resolve
skip-log-bin                              # single node, no replicas: no binlog overhead
transaction-isolation = READ-COMMITTED    # wiki: reduces gap-lock contention between binaries/backfill/releases
innodb_autoinc_lock_mode = 2              # interleaved; safe without binlog, better concurrent inserts

innodb_buffer_pool_size = 8G
innodb_log_file_size = 2G
innodb_log_buffer_size = 64M
innodb_flush_log_at_trx_commit = 2        # throughput over last-second durability (documented trade-off)
innodb_flush_method = O_DIRECT            # falls back to fsync if the mount rejects it; verified at bring-up
innodb_file_per_table = 1
innodb_io_capacity = 2000
innodb_io_capacity_max = 4000
innodb_read_io_threads = 8
innodb_write_io_threads = 8
innodb_open_files = 8000

max_connections = 400                     # tmux forks: every worker holds its own DB connection
thread_cache_size = 100
max_allowed_packet = 128M
group_concat_max_len = 65535              # NntmuxOffsetWorker sets 65535 per session; populate sets 16384
tmp_table_size = 256M
max_heap_table_size = 256M
join_buffer_size = 4M
sort_buffer_size = 4M
read_buffer_size = 2M
read_rnd_buffer_size = 4M
table_open_cache = 8000
table_definition_cache = 4000
open_files_limit = 65535
query_cache_type = 0
query_cache_size = 0

slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

Notes:

- `innodb_buffer_pool_instances` is deliberately absent. MariaDB removed it in 10.6, even though the wiki still lists it.
- `local_infile` stays off. The code never uses `LOAD DATA`.

### 5.2 Manticore (`config/manticore/manticore.conf`)

Derived from `config/manticore.conf`, with these changes:

- `data_dir = /var/lib/manticore`
- Logs go to `/var/log/manticore`.
- Listens on `9306:mysql` and `9308:http`. Sphinx port 9312 is dropped.
- `max_open_files = 524288`, `binlog_flush = 2`, `rt_flush_period = 900`
- `secondary_indexes = 1`, `pseudo_sharding = 1`, `threads = 6`

The container gets `nofile=1048576` and `memlock=-1`. Port 9308 is only published on `127.0.0.1`.

### 5.3 Redis

```
appendonly yes
appendfsync everysec
maxmemory 768mb
maxmemory-policy noeviction
save ""
```

### 5.4 PHP

Opcache:

- `opcache.enable=1`, `enable_cli=0`
- `memory_consumption=256`, `max_accelerated_files=50000`
- `validate_timestamps=0`, since the image is immutable

Limits:

- CLI `memory_limit=2048M`, already set by `docker/8.5/php.ini`

### 5.5 NNTmux runtime settings (`config/seed/tuning.sql`, typed config tables)

The budget for primary NNTP connections is about 30 of the 73 available.

| Table | Values |
|---|---|
| `ingestion_configurations` | `binary_threads=6`, `backfill_threads=4`, `release_threads=2`, `max_messages=20000`, `backfill_days_mode=1` (per-group `backfill_target`), `backfill_quantity=200000` |
| `post_processing_configurations` | `post_threads=4`, `nfo_threads=2`, `post_threads_non=3`, `post_threads_amazon=2`, `fix_name_threads=2` |
| `tmux_configurations` | `sequential_mode=0` (Full), `binaries_enabled=1`, `backfill_mode=1`, `releases_enabled=1`, `post_mode=3` (additional + NFO), `post_non_mode=1`, `post_amazon_mode=1`, `fix_names_enabled=1`, `run_irc_scraper=1`, `htop_enabled=1` |

The settings cache lasts 300 seconds, so seeding runs `cache:clear` afterwards.

### 5.6 Environment highlights (`config/app.env`)

```
APP_ENV=production   APP_DEBUG=false   APP_URL=<from docker/.env APP_URL, default http://localhost:$APP_PORT>   TRUSTED_PROXIES=172.31.42.0/24
DB_CONNECTION=mariadb  DB_HOST=mariadb  REDIS_HOST=redis  SEARCH_DRIVER=manticore
MANTICORESEARCH_HOST=manticore  MANTICORESEARCH_PORT=9308
CACHE_STORE=redis  QUEUE_CONNECTION=redis  SESSION_DRIVER=redis
HORIZON_QUEUES=api-audit,default,emails,incidents,contactemail,newreg,welcomeemails
MAIL_MAILER=smtp  MAIL_HOST=mailpit  MAIL_PORT=1025
COVERS_PATH=/app/storage/covers  PATH_TO_NZBS=/app/storage/nzb
TEMP_UNRAR_PATH=/var/tmp/nntmux/unrar  TEMP_UNZIP_PATH=/var/tmp/nntmux/unzip
USE_ALTERNATE_NNTP_SERVER=false        # true would also move header connections to the alternate
NNTP_ALTERNATE_FALLBACK=true           # alternate = missing-article fallback only
NNTP_COMPRESSED_HEADERS=false          # GZIP XFEATURE headers produced decode errors against Newshosting
YENC_DECODER=php                      # ZTS PHP: RapidYenc cannot load
SCRAPE_IRC_SERVER=irc.synirc.net  SCRAPE_IRC_PORT=6697  SCRAPE_IRC_TLS=true  SCRAPE_IRC_USERNAME=<generated>
PREDB_FEEDS_ENABLED=true  PREDB_FEED_SOURCES=predb_club,predb_net,predb_me
TMUX_SCHEDULED_HEALTH_CHECK=false
MONITORING_ENABLED=true  MONITORING_PUSHGATEWAY_URL=http://pushgateway:9091  GRAFANA_AUTH=proxy
TMDB_APIKEY / TVDB_APIKEY = the shared defaults from .env.example (replace with your own); other providers blank
```

## 6. App code changes

Both changes follow AGENTS.md: tests, Pint, PHPStan, an `.env.example` entry for every new env key, and staged but not committed.

### 6.1 `predb:import-feed`

Units, each with one job:

- **`App\Services\Predb\Feeds\PredbFeedEntry`** (readonly DTO): `title`, `source`, `category?`, `size?`, `files?`, `predate?` (`CarbonImmutable`), `nuked` (a `Predb::PRE_*` constant), `nukeReason?`, `groupName?`.
- **`App\Services\Predb\Feeds\Contracts\PredbFeedSource`**: `key(): string` and `fetch(int $page): iterable<PredbFeedEntry>`.
- **Sources**, all using Laravel `Http`, a configurable timeout and a `User-Agent`:

  | Source | Endpoint | Fields mapped |
  |---|---|---|
  | `PredbClubSource` | JSON `https://predb.club/api/v1/?count=N&page=P` | `name`, `team`, `cat`, `size` (MB), `files`, `preAt`, `nuke` |
  | `PredbNetSource` | JSON `https://api.predb.net/?limit=N&page=P` | `release`, `section`, `size`, `files`, `pretime`, `status`, `reason`, `group` |
  | `RssFeedSource` | Configurable URL and key (default `predb_me` → `https://predb.me/?rss=1`) | `<title>`, plus `<pubDate>` when present |

- **`App\Services\Predb\Feeds\PredbFeedImporter`**: takes entries and upserts them by `title`, with the same semantics as `IRCScraper`:
  - **Insert** when the title is new, then call `Search::insertPredb(...)`.
  - **Update** only fields that are empty in the database, or nuke status changes, then call `Search::updatePreDb(...)`.
  - It uses Eloquent and the query builder, not hand-built SQL strings.
  - It returns `{inserted, updated, skipped}`.
  - A row that raises an exception is logged and skipped, so the batch continues.
- **`App\Console\Commands\PredbImportFeed`**: `predb:import-feed {--source=*} {--pages=1} {--dry-run}`.
  - Iterates over the configured sources. One source failing (connection error or a non-2xx response) logs a warning and does not abort the others.
  - Exit code: 0 if at least one source succeeded, 1 if all failed.
- **`config/predb_feeds.php`** reads these env keys, all added to `.env.example`:

  | Env key | Default |
  |---|---|
  | `PREDB_FEEDS_ENABLED` | `false` |
  | `PREDB_FEED_SOURCES` | `predb_club,predb_net,predb_me` |
  | `PREDB_FEED_PAGE_SIZE` | `100` |
  | `PREDB_FEED_TIMEOUT` | `15` |
  | `PREDB_FEED_USER_AGENT` | |
  | `PREDB_FEED_RSS_URL` | |

- **Schedule:** `Schedule::command('predb:import-feed')->everyFiveMinutes()->withoutOverlapping()->when(config('predb_feeds.enabled'))`. Bootstrap also runs it once with `--pages=20` to seed recent history.
- **Tests:**
  - Unit: each source parser against JSON and XML fixtures in `tests/Fixtures/predb/`.
  - Feature: on SQLite, with `Search` faked, check that the importer inserts new titles, updates without clobbering existing values, and maps nukes.
  - Feature: the command calls `Http::fake`, isolates a failing source, honours `--dry-run`, and stays disabled when off.

### 6.2 Scheduled health-check gate

- In `config/tmux.php`, add `'scheduled_health_check' => (bool) env('TMUX_SCHEDULED_HEALTH_CHECK', true)`. The default keeps current behaviour.
- In `routes/console.php`, add `->when(static fn (): bool => (bool) config('tmux.scheduled_health_check'))` to the `tmux:health-check --auto-restart` entry.
- Add a test asserting the event is filtered when the flag is false.
- In this stack, `indexer.sh` loops on `tmux:health-check --auto-restart`, which repairs a dead monitor pane or a missing session in the right container. The container exits after 5 consecutive failures so Docker restarts it.

### 6.3 Grafana auth without nginx

`AdminMonitoringController::authorizeGrafana` only responds when the PHP server variable `NNTMUX_GRAFANA_AUTH_REQUEST=1` is set. That variable is how nginx proves the call is an internal subrequest.

- The FrankenPHP Caddyfile adds an **internal-only** vhost on `:8081` that sets the variable with `php_server { env NNTMUX_GRAFANA_AUTH_REQUEST 1 }`. Port 8081 is not published.
- The front Caddy routes `/grafana/*` through `forward_auth web:8081 { uri /admin/monitoring/grafana-auth; copy_headers X-NNTmux-Grafana-JWT>X-JWT-Assertion }` before proxying to `grafana:3000`.
- The JWT never reaches the browser, the same guarantee the nginx setup gives.
- No app code changes are needed.

## 7. Bootstrap flow (`make -C docker init`)

1. `bin/generate-env` creates `docker/.env` and `config/app.env`.
2. Create the `data/` and `logs/` trees, owned by 33:33 for app paths.
3. `docker build` the root `Dockerfile`, tagged `nntmux/base:local`, then build `docker/app/Dockerfile`, tagged `nntmux/app:local`.
4. Start `mariadb redis manticore mailpit` and wait until they are healthy.
5. `compose run --rm web php artisan nntmux:deploy-init`. This step:
   - checks the configuration
   - creates the directories
   - runs `migrate --seed` and creates the admin user
   - runs `manticore:create-indexes`
   - writes `install.lock`

   It is idempotent: it refuses to run if the lock already exists.
6. Seed `config/seed/groups.sql` (20 groups, `active=1 backfill=1 backfill_target=3`) and `tuning.sql`, then run `cache:clear`.
7. Run `predb:import-feed --pages=20`, which seeds about 2,000 recent pres per source. This step is best effort: a failure doesn't stop `init`.
8. `compose up -d` everything; the indexer starts tmux.
9. `php artisan monitoring:install --sail`-equivalent: generate the Grafana JWT keypair into `storage/app/monitoring`.

**Curated groups:**

```
alt.binaries.teevee        alt.binaries.moovee           alt.binaries.hdtv.x264    alt.binaries.hdtv
alt.binaries.boneless      alt.binaries.multimedia       alt.binaries.tv           alt.binaries.x264
alt.binaries.movies.divx   alt.binaries.movies.x264      alt.binaries.misc         alt.binaries.cores
alt.binaries.mom           alt.binaries.etc              alt.binaries.dvd          alt.binaries.bloaf
alt.binaries.ath           alt.binaries.sounds.lossless  alt.binaries.sounds.mp3.complete_cd  alt.binaries.e-book
```

## 8. Operations

| Task | Command |
|---|---|
| Start / stop | `make -C docker up` / `make -C docker down` (the tmux session stops cleanly on SIGTERM) |
| Watch the indexer | `make -C docker tmux`, which attaches to the tmux session (detach with Ctrl-a d) |
| Logs | `make -C docker logs s=indexer`; Laravel logs are in `docker/data/storage/logs` |
| Backup | `make -C docker backup`: `mariadb-dump --single-transaction` piped through gzip into `docker/backups/`, keeping 7. Manticore can be rebuilt with `nntmux:populate --manticore --all`. |
| Add groups | Admin → Groups, or extend `groups.sql` and run `make -C docker seed` |
| Grafana | `http://localhost:8080/grafana/` (admin session required), or Admin → System → Monitoring |
| Mail | `http://localhost:8025` |

## 9. Failure handling

| Failure | Behaviour |
|---|---|
| A dependency is unhealthy | App services wait (`depends_on: service_healthy`). The entrypoint refuses to start without `install.lock`. |
| The tmux session dies | `indexer.sh` auto-restarts it. After 5 consecutive failures the container exits and `restart: unless-stopped` recreates it. |
| A feed goes down | That source logs a warning. Other sources and IRC continue. |
| An NNTP article is missing | NNTmux falls back to the alternate provider. |
| Redis is full | `noeviction` returns errors rather than silently dropping queues. `maxmemory` is sized with headroom, and memory is monitored in Grafana. |
| The disk fills up | Grafana's host dashboard shows it. Part retention (72h) and the hourly `clean:directories` keep transient data bounded. |

## 10. Risks and checks during bring-up

1. **Bind-mount ownership and permissions on OrbStack.** Check that MariaDB's `chown` and UID 33 writes work. If MariaDB cannot use the bind mount, fall back to a named volume for `data/mariadb` and report it.
2. **`O_DIRECT` on a virtiofs mount.** Check `SHOW VARIABLES LIKE 'innodb_flush_method'` and the error log. Switch to `fsync` if MariaDB rejects it.
3. **Non-free `unrar` on bookworm arm64.** Check with `unrar | head -1`, which should report RAR 6 or later.
4. **Provider group availability.** `groups:update` warns about any group the primary server lacks. Deactivate those.
5. **IRC nickname collision or ban.** The nickname is randomized. A failed scraper pane does not affect ingestion.
6. **Feed rate limits.** Polling is every 5 minutes, 1 page each, and predb.club sends `max-age=60`, so the load is negligible.

## 11. Testing and verification

- **PHP:** the new and affected PHPUnit tests, Pint (`--dirty`), PHPStan, and `php -l`. These run in a container built from the `build` stage, since the host has no PHP.
- **Stack:** `docker compose config` must be valid. Every service must be healthy. `curl -fsS localhost:8080/up` must succeed and the admin login page must render.
- **Indexing:**
  - `usenet_groups.last_record > 0` for active groups.
  - `binaries`/`parts` row counts grow.
  - `releases` count is above 0 within about an hour.
  - The tmux monitor pane shows connections on the primary.
- **PreDB:** `SELECT source, COUNT(*) FROM predb GROUP BY source` shows both the IRC and the feed sources, and `predb_rt` has the same count.

## 12. Bring-up log (2026-10-09)

| Issue found | Fix |
|---|---|
| Manticore restart loop: its entrypoint `chown`s the config file, which fails on a read-only mount | Mounted the conf read/write |
| tmux utility panes died with "This account is currently not available" because `www-data`'s shell is `nologin` | `SHELL=/bin/bash` for the app services |
| OrbStack bind mounts show files as owned by whichever user is reading them | No host `chown` needed. MariaDB accepted `O_DIRECT` on the bind mount. |
| Binaries workers failed in `_getXFeatureTextResponse` (compressed headers) | `NNTP_COMPRESSED_HEADERS=false`, the upstream default |
| Native yEnc unavailable under ZTS PHP | Dropped the RapidYenc stage and FFI; `YENC_DECODER=php` |
| Some workers saw intermittent `stream_socket_client` connect failures; only about 16 of 73 connections were in use | Treated as transient: the next cycle retries. Another client sharing the same provider account may also be using its connection pool. |
| Grafana showed its own login page: Caddy sorted `request_header -X-JWT-Assertion` after `forward_auth`, which deleted the JWT before it reached Grafana | Wrapped the `/grafana*` handlers in `route { }` so they run in the order written |
| No mediainfo, samples or video previews. The cause was `unrar` exiting 7 (`Unknown option`) on NNTmux's lone `-` switch terminator, hidden by `extract_using_rar_info = 1` | Changed `-` to `--` at the three unrar call sites (BUGS.md #19), added a regression test, and set `extract_using_rar_info = 0` in `tuning.sql`. Verified end to end: `(vRAW)`, then `m` and `s`, giving Matroska/HEVC 1920×1072 in `video_data`. |
| Post-processing panes exit right after start ("no work available") | Expected before any releases exist. The monitor respawns them on their timers. |

### Throughput tuning (2026-10-09)

| Observation | Change |
|---|---|
| MariaDB reached 9.87 of its 10 GiB cap | Raised `MARIADB_MEMORY` to 13g (the buffer pool stays at 8G) |
| `pulse_aggregates` deadlocks: Pulse's storage ingest upserted from every short-lived worker | `PULSE_ENABLED=false` in the indexer, horizon and scheduler containers; web keeps recording |
| The VM was CPU-saturated (load ~25 on 10 CPUs) and header chunks were rolled back on lock contention | Rebalanced workers toward backfill: binaries 20→6, backfill 8→12→16, post-processing 11→6; `innodb_io_capacity` raised to 4000/8000 |
| The tmux monitor exact-`COUNT(*)`ed parts/binaries/collections every 60s (72M parts, about 10s per scan) | `TMUX_REFRESH_INTERVAL=900` |
| Same-name uploads from different articles were dropped | `RELEASE_DEDUPE_ENABLED=false` and `RELEASE_DEDUPE_LOCK_STORE=database` (xbmc4lyfe/newznab-tmux#1, merged as `04ccaa5d6`); Cross Post Hours set to 0 |
