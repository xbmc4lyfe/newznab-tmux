# Cutover: rust-native replaces master

`ws-deploy` keeps this file. Cutover means merging `rust-native` into `master`, after which the
deployment owner rolls the Rust stack out against the production database. Nobody starts that
without Allen's explicit go-ahead. The live MariaDB is the only copy of the data.

## 1. Gates before anyone schedules the cutover

Every gate is checked on the `rust-native` commit that will be merged.

| Gate | How to check | Owner |
| --- | --- | --- |
| Every role production needs is ported and out of the Compose `pending` profile: `ingest`, `backfill`, `postprocess` (and `mail` if account mail is wanted) | `docker/docker-compose.yml` has no `profiles: [pending]` on them; each is listed in `docker/config/prometheus/prometheus.yml` | ws-ingest, ws-postprocess, ws-admin |
| Ledgers have no `todo` entry that production still calls | `python3 rust/porting/check.py`; any remaining `todo` has an agreed reason it can wait | each workstream |
| The NZBHydra2 API answers like PHP did | `ws-api` byte-for-byte fixtures pass; `GET /api/v1/api?t=caps` and a `t=search`, `t=tvsearch` (with `tvdbid`/`rid`), `t=movie` (with `imdbid`/`tmdbid`) and `t=get` against a staging stack with a copy-shaped database | ws-api, ws-deploy |
| The image builds on the production host and every service turns healthy | `just -f docker/Justfile build`, then a staging `up -d --wait` with NNTP credentials | ws-deploy |
| Production's Laravel schema is at the Rust baseline | On production, read-only: `SELECT MAX(migration) FROM migrations` must be `2026_10_20_200000_create_database_fingerprint_storage`. Anything newer makes `nntmux-ctl migrate` refuse to start | ws-deploy, read-only login |
| `process_videos` is what Allen wants | Read-only: `SELECT value FROM settings WHERE name = 'process_videos'`. PHP never made clips; Rust does, so `1` means more ffmpeg work per release | Allen |
| A restorable backup of the production database exists and was test-restored | Allen decides how; nothing below runs without it | Allen |

## 2. What changes at cutover

- **Image:** one image, `nntmux/rust:local` (`APP_IMAGE`), runs every service. The PHP images (`nntmux/web`, `horizon`, `scheduler`, `indexer`) stop being built.
- **Services:** `web` (nntmux-web on 8080), `supervisor` (the scheduler), `releases`, `fixnames`, `predb`, `fingerprint`, `verify`, `search-sync`, then `ingest`, `backfill`, `postprocess` and `mail` as they leave `pending`. `migrate` runs once before them on every rollout.
- **Gone:** `horizon`, `scheduler`, `indexer` and tmux. `docker compose up` does not remove them, so they have to be stopped and removed by hand (step 4).
- **Deploy:** `just deploy` is now `docker/bin/deploy`. It holds a lock, checks the branch and remote, builds, runs `migrate`, recreates changed services and waits for health. Workers drain on SIGTERM within their `stop_grace_period`. Fingerprint and verify keep the 1920 s grace; the other roles get 120 s. The PHP-era drain barriers, stamps and batching are gone.
- **Schema:** the first `migrate` against production records the Laravel baseline in `rust_migrations` without running it, then applies `0003_ingestion_activity` (a new table). Nothing existing is altered.
- **Sessions:** everyone logs in again once, because the Laravel session cookie isn't shared.
- **Grafana:** listens on `127.0.0.1:${GRAFANA_PORT:-3000}` with its own admin login (`nntmux-grafana-admin`, `GRAFANA_ADMIN_PASSWORD` in `docker/.env`). The `/grafana` route through the admin session is gone until an admin UI exists.
- **Metrics:** Prometheus scrapes every role's `:9187/metrics` (job `nntmux-roles`) as well as the Pushgateway export. The "NNTmux Rust services" dashboard shows API latency and role throughput.

## 3. Handoff to the deployment owner (runs against production)

These steps are listed in order. Each one waits for Allen's go-ahead.

1. **Freeze the PHP side.** Drain the Horizon fingerprint queue with `php artisan releases:fingerprint-transfer`. If `releases_rt` predates the media fields, run `php artisan nntmux:update-releases-index --add-fields --update-media-ids`. Then drain and stop `horizon`, `scheduler` and `indexer` through the current guarded tooling.
2. **Back up the database**, as section 1 requires.
3. **Merge** `rust-native` into `master` with a merge commit, after Allen approves the PR.
4. **Prepare the runtime.** In the persistent runtime's `docker/.env`, add `APP_IMAGE=nntmux/rust:local`, `GRAFANA_ADMIN_PASSWORD` and `DEPLOY_HEALTH_TIMEOUT` (`docker/bin/generate-env` fills in missing values without overwriting the ones already set). Keep `COMPOSE_FILE` with the external-database override, plus `DEPLOY_LOCK_DIR`, `DEPLOY_LOG` and `DEPLOY_BY`. `config/app.env` keeps its `DB_*`, `REDIS_*`, `MANTICORESEARCH_*`, `NNTP_*` and `APP_KEY` values, which the Rust config reads under the same names. Then remove the PHP containers: `docker compose rm -sf horizon scheduler indexer`.
5. **Plan, then deploy.** With `DEPLOY_BY` set, run `just deploy-plan`, then `just deploy`. Watch `migrate`'s log for "Laravel baseline already present, recording it" followed by migration 3.
6. **Rebuild derived state**, once each:
   - `just docker ctl cmd="predb identity-rebuild"`
   - `just docker ctl cmd="search reconcile --all"`, or a rebuild of any index `search inspect` reports as needing one
   - `just docker ctl cmd="fingerprint backfill"`, for releases whose fingerprint job was never transferred
7. **Verify.** Use NZBHydra2's indexer test plus the caps and search calls from section 1. Check that every `nntmux-roles` target is up in Prometheus, that the dashboard shows work units moving, that `.deploy.log` has a `started`/`finished rc=0` pair, and that new releases appear within an ingest cycle.

## 4. Rollback

- Before step 5, nothing has changed in production, so stop and investigate.
- After step 5, run `just docker down` on the Rust services. Check out the pre-merge master commit, then bring back the PHP services with that commit's own tooling. The PHP app ignores `rust_migrations` and `ingestion_activity`, so the schema needs no rollback. Releases the Rust roles wrote meanwhile stay; they use the same tables.
- Restore the backup only if data is damaged, and only with Allen's go-ahead.

## 5. Still open (as of 2026-11-01)

- `ws-ingest` (13 todo): #1199 ports the `ingest` and `backfill` roles and is waiting to merge.
- The `postprocess` role isn't ported (`ws-postprocess`, 4 todo).
- `ws-admin` (182 todo): Phase 1 has no admin UI, so this does not block the NZBHydra2 cutover. It does mean admin pages and the `mail` role are absent after cutover.
- `ws-metadata` (8), `ws-ops` (18) and `ws-releases` (4) todo entries are mostly maintenance commands. Each owner says which ones production needs before cutover.
- The Dockerfile's runtime `apt-get` line, and a build on Allen's host, haven't been verified yet. The cloud sandbox couldn't reach Debian mirrors.
