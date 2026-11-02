## Worker roles

There is no tmux. Each role is its own Compose service running `nntmux-worker <role>`, and `docker/docker-compose.yml` is the layout. Roles that aren't ported yet sit in the `pending` profile.

- `supervisor` runs the scheduled tasks (`rust/crates/supervisor/src/schedule.rs`). It takes a database lease, so a second copy waits instead of running tasks twice.
- `ingest` and `backfill` fetch headers (`rust/crates/ingest`). `releases` assembles releases and NZBs. `fixnames` and `predb` fix names. `postprocess` does NFO, PAR2, archives and media. `fingerprint` and `verify` collect and check release fingerprints. `search-sync` keeps Manticore current.
- Roles claim work, process it, commit, and loop (`rust/crates/runtime`). Per-role flags come from `RoleArgs`.

### Drain and shutdown

- On SIGTERM a role stops claiming work, finishes its in-flight units and exits. `stop_grace_period` is 30 s for `web`, 120 s by default, and 1920 s for `fingerprint`, `verify` and `postprocess`, whose units fetch from NNTP and can run for many minutes.
- A rollout is `docker compose up -d --wait` with a new image, so this drain is the whole safety mechanism. There is no separate drain command. Never force-kill a worker to speed a rollout.

### Tuning

- Behavior settings live in the database `settings` table, as before. Seed values are in `docker/config/seed/tuning.sql`.
- Resource limits (`WORKER_MEMORY`, `POSTPROCESS_MEMORY`) are in `docker/.env`. NNTP and verification settings are in `docker/config/app.env`.
- Operator commands that used to be `php artisan` run through `nntmux-ctl`. Locally, `cargo run -p nntmux-ctl -- <command>` from `rust/`. On the stack, `just docker ctl cmd="<command>"`.
