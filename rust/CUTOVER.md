# Cutover: production moves to main

`ws-deploy` keeps this file. `main` is the default branch and `master` holds only the PHP
history. Cutover means switching the production runtime checkout from `master` to `main`,
after which the deployment owner rolls the Rust stack out against the production database. Nobody starts that
without xbmc4lyfe's explicit go-ahead. The live MariaDB is the only copy of the data.

## 1. Gates before anyone schedules the cutover

Every gate is checked on the `main` commit that will be deployed. `just deploy` fast-forwards to the tip of `origin/main`, so recheck the gates against the commit `just deploy-plan` reports.

| Gate | How to check | Owner |
| --- | --- | --- |
| Every role production needs is ported and out of the Compose `pending` profile: `ingest`, `backfill`, `postprocess` (and `mail` if account mail is wanted) | `docker/docker-compose.yml` has no `profiles: [pending]` on them; each is listed in `docker/config/prometheus/prometheus.yml` | ws-ingest, ws-postprocess, ws-admin |
| Ledgers have no `todo` entry that production still calls | `python3 rust/porting/check.py`; any remaining `todo` has an agreed reason it can wait | each workstream |
| The NZBHydra2 API answers like PHP did | `ws-api` byte-for-byte fixtures pass; `GET /api/v1/api?t=caps` and a `t=search`, `t=tvsearch` (with `tvdbid`/`rid`), `t=movie` (with `imdbid`/`tmdbid`) and `t=get` against a staging stack with a copy-shaped database | ws-api, ws-deploy |
| The image builds on the production host and every service turns healthy | `just -f docker/Justfile build`, then a staging `up -d --wait` with NNTP credentials | ws-deploy |
| Production's Laravel schema is at the Rust baseline | On production, read-only: `SELECT MAX(migration) FROM migrations` must be `2026_10_20_200000_create_database_fingerprint_storage`. Anything newer makes `nntmux-ctl migrate` refuse to start | ws-deploy, read-only login |
| `process_videos` is what xbmc4lyfe wants | Read-only: `SELECT value FROM settings WHERE name = 'process_videos'`. PHP never made clips; Rust does, so `1` means more ffmpeg work per release | xbmc4lyfe |
| A restorable backup of the production database exists and was test-restored | xbmc4lyfe decides how; nothing below runs without it | xbmc4lyfe |

## 2. What changes at cutover

- **Image:** one image, `nntmux/rust:local` (`APP_IMAGE`), runs every service. The PHP images (`nntmux/web`, `horizon`, `scheduler`, `indexer`) stop being built.
- **Services:** `web` (nntmux-web on 8080), `supervisor` (the scheduler), `releases`, `fixnames`, `predb`, `fingerprint`, `verify`, `search-sync`, `ingest` and `backfill`, then `postprocess` and `mail` as they leave `pending`. `migrate` runs once before them on every rollout.
- **Gone:** `horizon`, `scheduler`, `indexer` and tmux. `docker compose up` does not remove them, so they have to be stopped and removed by hand (step 4).
- **Deploy:** `just deploy` is now `docker/bin/deploy`. It holds a lock, checks the branch and remote, refuses leftover PHP containers and any changed data-service definition (unless `DEPLOY_RECREATE_DATA=1`), builds, and, whenever a migration is pending or a data service is recreated (so on the first cutover deploy), stops every app service and holds `nntmux-ctl verify barrier` until the rollout is healthy. It runs `migrate` before recreating anything, recreates changed services (and services whose mounted config changed) and waits for health. Workers drain on SIGTERM within their `stop_grace_period`. Fingerprint, verify and postprocess keep the 1920 s grace; the other roles get 120 s. The PHP-era stamps and batching are gone; the verification barrier is the only one, and after a failure it stays held, with the writers stopped, until a later deploy succeeds.
- **Schema:** the first `migrate` against production records the Laravel baseline in `rust_migrations` without running it, then applies `0003_ingestion_activity` (a new table). Nothing existing is altered.
- **Sessions:** everyone logs in again once, because the Laravel session cookie isn't shared.
- **Grafana:** listens on `127.0.0.1:${GRAFANA_PORT:-3000}` with its own admin login (`nntmux-grafana-admin`, `GRAFANA_ADMIN_PASSWORD` in `docker/.env`). The `/grafana` route through the admin session is gone until an admin UI exists.
- **Metrics:** Prometheus scrapes every role's `:9187/metrics` (job `nntmux-roles`) as well as the Pushgateway export. The "NNTmux Rust services" dashboard (added in #1244) shows API latency and role throughput.

## 3. Handoff to the deployment owner (runs against production)

These steps are listed in order. Each one waits for xbmc4lyfe's go-ahead.

1. **Freeze the PHP side.** Drain the Horizon fingerprint queue with `php artisan releases:fingerprint-transfer`. If `releases_rt` predates the media fields, run `php artisan nntmux:update-releases-index --add-fields --update-media-ids`. Then drain and stop `horizon`, `scheduler` and `indexer` through the current guarded tooling.
2. **Back up the database**, as section 1 requires.
3. **Switch the runtime checkout.** First stop every poll, cron or redeploy loop on the runtime host, and hold deploys until xbmc4lyfe gives the go-ahead for step 5. Then, in the persistent runtime checkout, `git fetch origin main` and `git checkout main`, and set `DEPLOY_GIT_BRANCH=main` in `docker/.env` (the default for the Rust deploy). Note the `master` commit the runtime was on, for rollback.
4. **Prepare the runtime.** In the persistent runtime's `docker/.env`, add `APP_IMAGE=nntmux/rust:local`, `GRAFANA_ADMIN_PASSWORD` and `DEPLOY_HEALTH_TIMEOUT` (`docker/bin/generate-env` fills in missing values without overwriting the ones already set). Keep `COMPOSE_FILE` with the external-database override, plus `DEPLOY_LOCK_DIR`, `DEPLOY_LOG` and `DEPLOY_BY`. `config/app.env` keeps its `DB_*`, `REDIS_*`, `MANTICORESEARCH_*`, `NNTP_*` and `APP_KEY` values, which the Rust config reads under the same names. Then stop and remove the drained PHP containers. Compose no longer knows those services, so use their container names: `docker ps --filter label=com.docker.compose.service=horizon` (and `scheduler`, `indexer`) lists them, then `docker stop --time 1920 <names> && docker rm <names>`. `just deploy` refuses to run while any of them is still running. Stop the remote verification workers too: they still run PHP. Bring them back on the Rust image (`docker/worker`) after step 7; from then on the deploy's verification barrier keeps them out during migrations.
5. **Plan, then deploy.** With `DEPLOY_BY` set, run `just deploy-plan`. Expect it to refuse because the `redis` and `manticore` containers were created from the PHP-era Compose file, so their definitions differ. With every writer stopped (steps 1 and 4), run `just deploy-plan DEPLOY_RECREATE_DATA=1` and check that "Would recreate data services first" lists only `redis` and `manticore`. Production's MariaDB is external, so `mariadb` must not appear; stop if it does. Then run `just deploy DEPLOY_RECREATE_DATA=1`. Watch `migrate`'s log for "Laravel baseline already present, recording it" followed by migration 3.
6. **Rebuild derived state**, once each:
   - PreDB identities, in three phases: `just docker ctl cmd="predb identity-rebuild start"` prints a generation; repeat `just docker ctl cmd="predb identity-rebuild chunk --generation <G>"` until it reports the scan exhausted; then `just docker ctl cmd="predb identity-rebuild certify --generation <G>"`.
   - Search: `just docker ctl cmd="search reconcile --all --dry-run"` reports drift; `just docker ctl cmd="search reconcile --all --reindex"` repairs it. Without `--reindex`, reconcile only reports. Rebuild any index `search inspect` reports as needing one (see `.ai/skills/nntmux-development/references/conventions.md`).
   - Fingerprints, for releases whose job was never transferred: `just docker ctl cmd="fingerprint backfill --limit 1000"` queues one batch and prints `Resume with --after-id=<id>`. Repeat with that `--after-id` until it queues 0.
7. **Verify.** Use NZBHydra2's indexer test plus the caps and search calls from section 1. Check that every `nntmux-roles` target is up in Prometheus, that the dashboard shows work units moving, that `.deploy.log` has a `started`/`finished rc=0` pair, and that new releases appear within an ingest cycle.

## 4. Rollback

- **Before step 3:** the database and code are unchanged, but step 1 drained the fingerprint queue and stopped `horizon`, `scheduler` and `indexer`. Start them again with the current guarded tooling (`just deploy` on `master`); if `releases_rt` gained fields in step 1, the PHP app already expects them.
- **After step 3, before step 5:** check the runtime out on `master` again (`git checkout master`, which must be the commit noted in step 3), set `DEPLOY_GIT_BRANCH=master` and restore any other `docker/.env` keys step 4 changed, and restart the PHP services with `just deploy` as above. The PHP deploy guard only deploys `origin/master`, and `master` doesn't move any more.
- **After step 5:** stop the Rust services (`just docker down`) and any Rust verification workers on other hosts. Then check out `master` and redeploy PHP as above, restoring the `docker/.env` keys step 4 changed. The PHP app ignores `rust_migrations` and `ingestion_activity`, so the schema needs no rollback. Releases the Rust roles wrote meanwhile stay; they use the same tables. If step 6 rebuilt a Manticore table, run PHP's `manticore:create-indexes` and `nntmux:populate` for it, since PHP expects its own `releases_rt` shape.
- **Restoring the backup** discards every write made since it was taken. Only do it if data is damaged, only with xbmc4lyfe's explicit go-ahead, and restore to a scratch instance first to check it.

## 5. Still open (as of 2026-11-01)

- `ingest` and `backfill` are ported (#1199) and run by default. The remaining `ws-ingest` todo entries are commands; the owner says which ones production needs.
- The `postprocess` role isn't ported (`ws-postprocess`, 4 todo).
- `ws-admin` (182 todo): Phase 1 has no admin UI, so this does not block the NZBHydra2 cutover. It does mean admin pages and the `mail` role are absent after cutover.
- `ws-metadata` (8), `ws-ops` (18) and `ws-releases` (4) todo entries are mostly maintenance commands. Each owner says which ones production needs before cutover.
- The Dockerfile's runtime `apt-get` line, and a build on the production host, haven't been verified yet. The cloud sandbox couldn't reach Debian mirrors.
