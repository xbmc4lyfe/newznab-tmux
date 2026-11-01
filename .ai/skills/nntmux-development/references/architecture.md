## Architecture

NNTmux scans Usenet servers, collects headers, organizes releases, and enriches them with metadata. There is no PHP on this branch. Data flow:

```
NNTP -> ingest -> releases -> fixnames / postprocess / fingerprint / verify -> search-sync -> api / site
```

### Binaries

One image holds three binaries (see `Dockerfile` and `docker/docker-compose.yml`):

| Binary | Source | Role |
|--------|--------|------|
| `nntmux-web` | `rust/crates/web/src/main.rs` | Every HTTP route. Merges the `router()` of `api`, `releases`, `site` and `admin`. |
| `nntmux-worker <role>` | `rust/crates/worker/src/main.rs` | One background role per Compose service: `ingest`, `backfill`, `releases`, `fixnames`, `predb`, `postprocess`, `fingerprint`, `verify`, `search-sync`, `supervisor`, `mail`. |
| `nntmux-ctl` | `rust/crates/ctl/src/main.rs` | One-off admin commands: `migrate`, `init`, and subcommands per crate (`search`, `predb`, `fingerprint`, `ops`, ...). |

### Crates (`rust/crates/`)

| Crate | Purpose |
|-------|---------|
| `core`, `db`, `runtime`, `telemetry`, `testkit` | Shared config from the environment, the MariaDB pool and migrations, the claim-work-commit loop and SIGTERM handling, logging and `/metrics`, test support |
| `yenc`, `formats`, `nntp` | yEnc, archive and PAR2 parsing, the NNTP client and pool |
| `ingest`, `releases`, `categorize`, `namefix`, `predb` | Headers to releases, NZB creation, categorization, name fixing, PreDB feeds and IRC |
| `postprocess`, `metadata`, `fingerprint`, `verify` | Additional processing and media, metadata providers, release fingerprints, verification and remote publication |
| `search` | Manticore indexing and reads through `SearchClient` |
| `api`, `site`, `admin`, `webkit`, `mail` | Newznab API, RSS and NZB download; public pages; admin (not built yet); shared web code (sessions, CSRF, layout); mail |
| `supervisor`, `ops` | The scheduler (replaces the Laravel scheduler and tmux) and operator commands, status and metrics export |

`rust/porting/ledger/<workstream>.toml` tracks what is ported from the `php-final` tag.

### Monitoring

- Every role serves `/healthz` and `/metrics` on `NNTMUX_HEALTH_ADDR` (`:9187`). Prometheus scrapes them as job `nntmux-roles`, listed in `docker/config/prometheus/prometheus.yml`. Add a role there in the PR that moves it out of the Compose `pending` profile.
- The supervisor's metrics export pushes database-derived `nntmux_*` metrics to the Pushgateway (job `nntmux`).
- Grafana listens on `127.0.0.1:${GRAFANA_PORT:-3000}` with its own login (`nntmux-grafana-admin`). Dashboards are provisioned from `docker/monitoring/grafana/`. See `docker/monitoring/README.md`.

### External APIs

NNTP credentials and the metadata provider keys (TMDB, TVDB, TVMaze, Trakt, OMDB, IGDB, GiantBomb, Steam, AniList, AniDB) are environment variables in `docker/config/app.env`, read by the Rust config under the same names Laravel used.
