# NNTmux - XBMC 4 Lyfe fork

A Usenet indexer that turns article headers into searchable releases and NZBs, with a web interface and a Newznab-compatible API.

This fork builds on [NNTmux/newznab-tmux](https://github.com/NNTmux/newznab-tmux). It is a complete Rust rewrite: no PHP, no Laravel, no tmux. The old PHP is kept at the `php-final` tag for reference. `main` is the default branch; `master` holds only the PHP history, and production moves to `main` at the cutover. See [rust/WORKSTREAMS.md](rust/WORKSTREAMS.md) for how the rewrite is organised and [rust/CUTOVER.md](rust/CUTOVER.md) for the gates and steps to cut production over.

Full credit to the NNTmux team and its contributors for the project this fork is based on (see the end of this file).

## What runs

One image, `nntmux/rust:local`, holds three binaries. The Compose stack in [`docker/docker-compose.yml`](docker/docker-compose.yml) starts them as services:

- `web` (`nntmux-web`): the site, the Newznab API, RSS and NZB downloads, behind a Caddy `proxy`.
- Worker roles (`nntmux-worker <role>`): `supervisor` (scheduled tasks), `releases`, `fixnames`, `predb`, `fingerprint`, `verify` and `search-sync`. `ingest`, `backfill`, `postprocess` and `mail` are in the Compose `pending` profile until they are ported.
- `migrate`: a one-shot that applies schema migrations before any app service starts.
- Data services: MariaDB, Redis and Manticore. Mailpit catches mail.
- Monitoring: Prometheus, Pushgateway, Grafana and exporters.

`nntmux-ctl` is the admin CLI. Every role serves `/healthz` and `/metrics` on port 9187.

## Run the Docker Compose stack

You need Git, [just](https://github.com/casey/just) 1.43 or newer, Python 3, Docker with Compose v2 (Docker Desktop or OrbStack on macOS, Docker Engine on Linux), and Usenet provider credentials. The defaults expect a large host: MariaDB has an 8 GiB buffer pool and the service memory limits total roughly 30 GiB. Lower them in `docker/.env` and `docker/config/mariadb/conf.d/99-nntmux.cnf` for a smaller machine.

```bash
git clone https://github.com/xbmc4lyfe/newznab-tmux.git
cd newznab-tmux
```

Create `docker/config/usenet_servers.json` (ignored by Git) with your provider:

```json
{ "usenet_servers": [ { "host": "news.example.com", "port": 563, "ssl": true,
                        "username": "YOUR_USERNAME", "password": "YOUR_PASSWORD", "connections": 10 } ] }
```

Then, for a fresh install on an empty database:

```bash
chmod 600 docker/config/usenet_servers.json
export NNTP_PRIMARY_HOST=news.example.com NNTP_ALT_HOST=news.example.com
just docker init
```

`just docker init` generates `docker/.env` and `docker/config/app.env` (secrets, the admin password, NNTP settings), builds the image, runs `nntmux-ctl init` (schema, seed data, administrator, search tables), applies the starter groups and tuning in `docker/config/seed/`, and starts everything. Review those seed files first: they set worker and connection counts for a large provider plan. Open <http://localhost:8080> and log in as `admin`; the password is `ADMIN_PASS` in `docker/config/app.env`.

Details, monitoring and the remote verifier are in [DOCKER.md](DOCKER.md).

### Everyday commands

```bash
just docker ps                      # service status and health
just docker logs s=releases         # follow one service's logs
just docker ctl cmd="search inspect"   # run nntmux-ctl in a one-off container
just docker status                  # indexing progress snapshot
just docker backup                  # database dump in docker/backups/
just deploy-plan                    # what a rollout would do
just deploy                         # guarded rollout (needs DEPLOY_BY)
just docker help                    # every stack recipe
```

## NZBHydra2 and other clients

The Newznab API is at `/api/v1/api`; point NZBHydra2 (as a Newznab indexer) there with a user's API key. Check it with `GET /api/v1/api?t=caps`. The JSON API is under `/api/v2/`, and RSS under `/rss/`.

## Develop

Rust code lives in `rust/` (a Cargo workspace). On macOS or Linux:

```bash
just check              # cargo fmt --check, clippy -D warnings, rust/porting/check.py
just test               # starts the test services, then cargo test (just test -p <crate> for one)
just frontend           # build the Vite front end
```

Set `CARGO_BUILD_JOBS=4` on a shared machine (the recipes default to it). Read [CONTRIBUTING.md](CONTRIBUTING.md) and [AGENTS.md](AGENTS.md) before opening a PR.

## Credits and license

Full credit to the **NNTmux team**, [DariusIII](https://github.com/DariusIII), and [all upstream contributors](https://github.com/NNTmux/newznab-tmux/graphs/contributors) for building and maintaining the project this fork is based on. You can [support upstream development](https://github.com/sponsors/DariusIII).

NNTmux itself builds on [newznab plus](https://github.com/anth0/nnplus) and [nZEDb](https://github.com/nZEDb/nZEDb). Thanks to their original authors, maintainers, and communities as well.

Licensed under [GPL-3.0-only](LICENSE). For fork-specific bugs, use [this fork's issues](https://github.com/xbmc4lyfe/newznab-tmux/issues); for upstream documentation, see the [NNTmux wiki](https://github.com/NNTmux/newznab-tmux/wiki).
