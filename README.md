# NNTmux — XBMC 4 Lyfe fork

A Usenet indexer that turns article headers into searchable releases and NZBs, with a web interface and a Newznab-compatible API.

This fork builds on [NNTmux/newznab-tmux](https://github.com/NNTmux/newznab-tmux). Our focus is better release detection, more efficient processing, and a smaller, easier-to-maintain codebase. We are optimizing selected processing paths with Rust and progressively removing cruft, obsolete code, and old framework dependencies in favor of current tools.

## What we are improving

Recent work in this fork includes:

- **Release detection and naming:** recover names from raw subjects, PAR2 filenames, and file fingerprints; improve matching for obfuscated posts and reposts.
- **Verified publication:** check downloaded payload integrity before publishing new indexed releases, with bounded verification work and PAR2 recovery.
- **Broader PreDB coverage:** add feed, IRC, and WebSocket sources, and match releases against titles that arrive after the original post.
- **Rust optimization:** ship a native Rust-backed yEnc decoder in the local Docker image, with automatic PHP fallback. Further Rust optimization is ongoing and guided by correctness checks and measurement.
- **Less wasted work:** batch ingestion and search writes, reduce database contention, bound cleanup jobs, and keep release workers moving through ready work.
- **Better visibility:** expose actionable processing backlogs, release fingerprints, and browsing by the exact release submitter.

Modernization is incremental. The application currently uses Laravel 13, Livewire, Tailwind CSS, Alpine.js, and Vite; we continue simplifying inherited code and replacing outdated components as we work through the pipeline.

See the [fork's commit history](https://github.com/xbmc4lyfe/newznab-tmux/commits/rust-native/) for individual changes.

The [Rust benchmark dashboard](https://xbmc4lyfe.github.io/newznab-tmux/performance-current.html) refreshes on every rust-native build and benchmark completion, including reruns. It reports current-commit yEnc comparisons, BODY/fingerprint profiles, release rendering and Rust target coverage gaps. Median screens and diagnostic profiles do not establish the project's 1.5× common-path and uncommon p99 adoption requirements. Failed, missing and inconclusive measurements remain visible; raw evidence is linked through Actions and retained for 14 days. Run the existing performance workflows for local/manual investigations; those experiments are excluded from the current-rust-native dashboard.

Read the [personal fork documentation](https://xbmc4lyfe.github.io/newznab-tmux/) for the Rust migration, benchmark evidence, fork history, and local setup. [NNTmux](https://github.com/NNTmux/newznab-tmux) remains the main project; this fork is a personal project under development and testing.

## Run the local Docker Compose stack

The stack in [`docker/docker-compose.yml`](docker/docker-compose.yml) includes the web app, tmux indexer, Horizon workers, scheduler, MariaDB, Redis, Manticore, Caddy, Mailpit, and Prometheus/Grafana monitoring. Images build the PHP dependencies, frontend assets, and native decoder for you.

You need Git, [just](https://github.com/casey/just) 1.43 or newer (distribution packages such as Ubuntu 24.04's 1.21 are too old), Python 3, a running Docker installation with Compose v2, and Usenet provider credentials. On macOS, use Docker Desktop or OrbStack; on Linux, use Docker Engine with the Compose plugin.

The supplied settings target a substantial indexing workload: MariaDB has an 8 GiB buffer pool, and service memory limits total roughly 30 GiB. Allow enough Docker memory, or lower both the limits in `docker/.env` and the buffer pool in `docker/config/mariadb/conf.d/99-nntmux.cnf`. Verification allows up to 200 GiB of scratch space while keeping 10 GiB free; plan additional disk space for the database and NZBs, or adjust the verification limits in `docker/config/app.env`.

### 1. Clone this fork

```bash
git clone https://github.com/xbmc4lyfe/newznab-tmux.git
cd newznab-tmux
```

### 2. Configure your Usenet provider

Create `docker/config/usenet_servers.json` with your provider details. This file is ignored by Git. Replace the example values before continuing:

```json
{
  "usenet_servers": [
    {
      "host": "news.example.com",
      "port": 563,
      "ssl": true,
      "username": "YOUR_USERNAME",
      "password": "YOUR_PASSWORD",
      "connections": 10
    }
  ]
}
```

Generate the Compose and application configuration using that host for both primary and fallback connections:

```bash
chmod 600 docker/config/usenet_servers.json
export NNTP_PRIMARY_HOST=news.example.com
export NNTP_ALT_HOST="$NNTP_PRIMARY_HOST"
just docker env
```

For a separate fallback provider, add a second server entry and set `NNTP_ALT_HOST` to its host before running `just docker env`.

This creates `docker/.env` and `docker/config/app.env`, including a generated application key, database passwords, and administrator password. Keep these files private. Edit `docker/.env` for ports and memory limits, and `docker/config/app.env` for application settings and metadata API keys.

**Review the indexing settings before starting.** `docker/config/seed/groups.sql` enables starter groups, and `docker/config/seed/tuning.sql` sets worker counts: 12 header workers, 30 backfill workers, 16 additional-processing workers, and 4 NFO workers. Lower these to fit your provider's connection allowance and hardware; the example `connections` value alone does not reduce these seeded worker counts. Initialization applies these settings and starts indexing automatically.

### 3. Build and initialize

Run this for a fresh installation:

```bash
just docker init DEPLOY_BY=local-setup
just docker ps
```

Initialization builds the images, installs the database and search tables, configures monitoring, seeds groups and processing settings, attempts a PreDB import, and starts the stack. A failed public PreDB import is retried by the scheduler.

Open [http://localhost:8080](http://localhost:8080) and log in as `admin`. Read the generated password locally:

```bash
rg '^ADMIN_PASS=' docker/config/app.env
```

If `rg` is unavailable, open `docker/config/app.env` in your editor. Grafana is available through the administrator monitoring pages at `/grafana/`; Mailpit is at [http://localhost:8025](http://localhost:8025).

### Everyday commands

Run these from the repository root:

```bash
just docker ps                                 # Service status
just docker logs s=indexer                     # Follow indexing logs
just docker tmux                               # Attach to tmux; detach with Ctrl-a, then d
just docker down                               # Stop containers; retain bind-mounted data
just docker up DEPLOY_BY=local-start           # Start an initialized stack
just deploy                                    # Sync origin/rust-native & deploy dirty services
just deploy web                                # Redeploy one component (or just deploy-web)
just docker backup                             # Database dump in docker/backups/
just docker help                               # All available commands
```

When you run `just deploy-plan`, `just build-stack`, or `just deploy` (or `just deploy <component>` / `just deploy-<component>` for a single component), the deployment guard automatically verifies that the checkout is on the default branch (`rust-native`), fetches `origin/rust-native` from GitHub, and fast-forwards the checkout before planning, building, pausing affected application writers, running migrations when needed, and recreating the targeted or affected services. Use `deploy` for updates; `init` reapplies the starter groups and tuning.

Persistent data lives in `docker/data/`, logs in `docker/logs/`, and database dumps in `docker/backups/`. The backup command covers MariaDB; preserve application configuration and NZB/storage files separately. If initialization fails, inspect `just docker logs` before retrying; a partially initialized database needs recovery rather than a reset.

## Cloud deployments

For Docker-based deployments on AWS, Hetzner, and OVHcloud, use the [deployment runbooks](docs/deployment.md). They cover initialization, releases, rollback, encrypted storage, backups, and recovery.

## Credits and license

Full credit to the **NNTmux team**, [DariusIII](https://github.com/DariusIII), and [all upstream contributors](https://github.com/NNTmux/newznab-tmux/graphs/contributors) for building and maintaining the project this fork is based on. Their work provides the foundation for these improvements. You can [support upstream development](https://github.com/sponsors/DariusIII).

NNTmux itself builds on [newznab plus](https://github.com/anth0/nnplus) and [nZEDb](https://github.com/nZEDb/nZEDb). Thanks to their original authors, maintainers, and communities as well.

Licensed under [GPL-3.0-only](LICENSE). For fork-specific bugs, use [this fork's issues](https://github.com/xbmc4lyfe/newznab-tmux/issues); for upstream documentation, see the [NNTmux wiki](https://github.com/NNTmux/newznab-tmux/wiki).
