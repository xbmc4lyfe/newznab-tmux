## Project Conventions

### Configuration
- Read settings through `nntmux_core::Config` (`rust/crates/core/src/lib.rs`) or a crate's own `from_env`. Don't call `std::env` ad hoc.
- Names match what Laravel read: `DB_*`, `REDIS_*`, `MANTICORESEARCH_*`, `NNTP_*`, `APP_KEY`. Compose fills them from `docker/config/app.env`.
- Add every new or renamed environment variable to `docker/config/app.env.example` (or `docker/.env.example` for Compose settings) with a default and a short comment.
- Runtime settings that admins change live in the `settings` table.

### Database
- The schema is the one Laravel built. `rust/migrations/0001` and `0002` are generated from Laravel's migrations and seeders. Add a migration only when a workstream truly needs it, as the next number in `rust/crates/db/src/migrate.rs`.
- `nntmux-ctl migrate` records the Laravel baseline on first run against an existing database and refuses a database whose Laravel schema is newer than the baseline.

### API (`rust/crates/api`)
- v1: XML newznab-compatible, `/api/v1/api`, dispatched on `t`. NZBHydra2 uses this.
- v2: JSON REST under `/api/v2/*`. RSS is `/rss/*`, downloads are `/getnzb`.
- Responses must match the recorded PHP output byte for byte; golden fixtures guard it.

### Manticore `releases_rt` signed columns
- In Manticore, `integer` is unsigned 32-bit, so negative values such as `passwordstatus = -1` would be stored as large positives. The `releases_rt` schema in `rust/crates/search/src/registry.rs` uses `bigint` for `passwordstatus` and `haspreview` to keep them signed.
- Manticore can't `ALTER` attribute types in place. Changing a column type means dropping and recreating the table: `nntmux-ctl search create-indexes --drop`, then `nntmux-ctl search reconcile --all` to repopulate. Do this in a maintenance window with `search-sync` stopped.

### Commands
- Operator commands are `nntmux-ctl <crate> <command>` (see `rust/crates/ctl/src/main.rs`). Run `nntmux-ctl --help` for the list.
- Roles and routes are already wired into the binaries; a workstream shouldn't need to edit `crates/worker`, `crates/web` or `crates/ctl`.
- Use macOS or Linux host tools. If Docker is unavailable, check Docker Desktop or OrbStack on macOS, or the Docker service and socket access on Linux.
