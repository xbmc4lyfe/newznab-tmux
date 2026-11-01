# Rust-native rewrite: workstreams

The goal is zero PHP, with every entry point in Rust: all routes, commands, jobs and scheduled tasks. The plan is at https://claude.ai/artifact/5ETUZZfqM8B1bPu4yzoTJz. This file replaces its shadow-mode and per-subsystem cutover steps, because the PHP is already deleted on this branch.

## How the branch works

- All work lands on `rust-native`. Open PRs against it, not `master`. Master stays deployable, and merging `rust-native` into it is the final cutover.
- The PHP is at the `php-final` tag. On this Mac it's checked out at `~/git.xbmc4lyfe/newznab-tmux-php-ref`, with `vendor/` in place and a `.env` that points only at local services. Read it there or with `git show php-final:<path>`. Never edit it. To run it, start `rust/compose.test.yml` and run `rust/porting/php-ref-setup.sh`, which builds its `nntmux` database on the test MariaDB, once.
- The database schema doesn't change shape. `rust/migrations/0001` and `0002` are generated from Laravel's migrations and seeders. Add a new migration only when a workstream truly needs it, as the next number in `crates/db/src/migrate.rs`.
- No deploys, restarts or production writes. The deployment owner handles rollouts. Reading production through a read-only login is fine when a workstream needs real samples, but never write there.

## Rules for every workstream

1. **Own only your crates.** Stay inside the crates listed for your workstream. If you need something from another crate, use its public API, or add a small function to `core`, `db` or `runtime` in its own small PR. A role, `nntmux-ctl` subcommand or router is already wired into the binaries, so you shouldn't need to edit `crates/worker`, `crates/web` or `crates/ctl`.
2. **Dependencies:** root `Cargo.toml` has the shared versions, and adding a crate is allowed. On a `Cargo.lock` conflict, take the base version and run `cargo check --workspace` to regenerate it.
3. **Rust-native only:** no `extern "C"`, `#[no_mangle]`, `cdylib`, or shelling out to PHP. `unsafe` stays denied, except for SIMD paths in `yenc`, each with a `// SAFETY:` comment. CI runs `rust/porting/check.py`.
4. **Port behaviour, not structure.** Start from the entry points in your ledger file and read what production actually calls. Skip code nothing reaches. Keep every edge case that exists because of real data: name parsing quirks, transaction order, lock and lease semantics, retries. Write idiomatic Rust rather than translating classes line by line, and use tokio concurrency for network I/O.
5. **Fixtures come from the PHP.** Run the PHP reference to generate golden data, commit it under `rust/crates/<crate>/tests/fixtures/`, and commit the small script that made it beside it. Pure logic needs a golden test; database logic needs an integration test through `nntmux_testkit::TestDb`; provider and HTTP clients need `wiremock` tests. Tests that only restate the implementation don't count.
6. **Track progress in `rust/porting/ledger/<workstream>.toml`.** Set an entry to `ported` in the PR that ports it. Set it to `dropped`, with a `note` saying why, only if it has no place in the Rust stack. Move an entry to another workstream's file if it's misfiled.
7. **PR flow:** work in `~/git.xbmc4lyfe/rn-<workstream>` on a new `codex/rn-<workstream>-<topic>` branch from `origin/rust-native`, and merge `origin/rust-native` often. Keep PRs reviewable, about 3k lines of non-fixture code or less, and land them often rather than holding a large branch.
   Before opening a PR: `cargo fmt --all --check`, `cargo clippy --workspace --all-targets --locked -- -D warnings`, `cargo test --workspace --locked`, `python3 rust/porting/check.py`, and an independent review of the full diff from a fresh subagent or Codex with no P0 or P1 findings.
   After opening it: comment `@codex review`, fix P0 and P1 findings only, and squash-merge into `rust-native` once CI passes and the review is clean. If Codex has no credits, use a fresh independent local review and record it with the head commit in the PR. Don't put a requester's name or attribution comment in the PR.
8. **Tests:** `docker compose -f rust/compose.test.yml up -d`, then export the variables named at the top of that file. The services are shared by all worktrees. `TestDb` gives each test its own database, so prefix any Redis keys and Manticore tables the same way.
9. **Build load:** this Mac is shared by every worktree, so set `CARGO_BUILD_JOBS=4` and test your own crate with `-p` while working. Run the whole workspace only before a PR.
10. **Done:** your ledger file has no `todo` entries, the role or route works end to end against local services, and any PHP-era files you own outside `app/` are gone. Report what's unverified, especially anything that needs real NNTP or production data. Don't claim parity you haven't tested.

## Workstreams

| Workstream | Crates | Plan | Port from `php-final` |
| --- | --- | --- | --- |
| `ws-formats` | `yenc`, `formats`, `nntp` | M2 | `docker/app/nzb-yenc-ffi` (keep the logic and tests, drop the C ABI, then delete the crate's PHP-facing parts when callers go), `rust/fingerprint-worker/src/nntp.rs` and `yenc.rs`, `app/Services/Yenc`, `app/Services/NNTP` |
| `ws-ingest` | `ingest` | M3 | `app/Services/Binaries`, `Backfill`, `NNTP` callers, `multiprocessing:update-per-group` and `:backfill` |
| `ws-naming` | `categorize`, `namefix`, `predb` | M4 | `app/Services/Categorization`, `NameFixing`, `Predb`, IRC scraper |
| `ws-releases` | `releases`, `fingerprint`, `verify` | M5 | `app/Services/Releases` (assembly, NZB, fingerprint, verification, remote publication), `rust/fingerprint-worker` |
| `ws-postprocess` | `postprocess` | M6 | Additional processing, NFO, PAR2, archives, samples, media through ffmpeg |
| `ws-metadata` | `metadata` | M6 | TV, movie, music, game, book and anime providers, covers, rate limits per key |
| `ws-search` | `search` | M7 | `app/Services/Search`, Manticore indexing, repair, reconcile, and the read API that `api` and `site` call |
| `ws-api` | `api` | M9 | `/api`, `/rss`, `/getnzb`, API keys, rate limits, API usage jobs. XML and JSON must match byte for byte against recorded PHP responses |
| `ws-site` | `webkit`, `site` | M10 | Public pages, auth (bcrypt hashes, TOTP, passkeys, sessions), accounts, profile, cart. Templates use askama and htmx. Everyone logs in again once at cutover, since sharing Laravel's session cookie isn't worth building |
| `ws-admin` | `admin`, `mail` | M10 | `/admin`, mail, GDPR, invitations, roles, payments (BTCPay), account jobs. Build on `webkit` |
| `ws-ops` | `supervisor`, `ops` | M8 | `routes/console.php`, tmux layout and monitor, status probes, log viewer, MCP, every artisan command nobody else owns |
| `ws-deploy` | `Dockerfile`, `docker/`, `Justfile`, `deploy/`, `.github/`, docs | M2, M11 | Rust image and Compose services for each role and `nntmux-web`, the deploy guard and drain, Caddy routes, Grafana, `AGENTS.md` and `.ai/` rules rewritten for Rust, final removal of leftover PHP and `nzb-yenc-ffi` |

Order: `ws-formats` first if threads must be staggered, since `ws-ingest`, `ws-releases` and `ws-postprocess` call it. They can start in parallel against the agreed API, which `ws-formats` should publish early as a first small PR. `ws-site` publishes `webkit` early for `ws-admin`. Everything else is independent, because it meets at the database.

`rust/indexer` is a separate SQLite and Tantivy experiment. Leave it alone unless the owner says otherwise.
