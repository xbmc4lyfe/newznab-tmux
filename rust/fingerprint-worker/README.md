# Standalone fingerprint worker

A native Rust executable for macOS/Linux. It reads compressed NZB content, claims,
leases, connection budgets and results directly from MariaDB/MySQL. It never writes
NZBs or article bodies to local files and requires no PHP installation at runtime.
The application still publishes releases and enqueues database jobs.

## Build and run

```sh
cargo build --release --locked --manifest-path rust/fingerprint-worker/Cargo.toml
```

Apply the application's database-storage migration, import retained NZBs with
`php artisan nzbs:import-database --root=/legacy/nzb/root`, transfer retained Horizon jobs with `php artisan releases:fingerprint-transfer`,
and enqueue outstanding releases with
`php artisan releases:fingerprint-backfill`. Confirm those command names with
`php artisan list` for the checked-out application revision.

Supply credentials as environment variables, not command-line arguments:

```sh
export DATABASE_URL='mysql://worker:password@database.example/nntmux?ssl-mode=verify_identity&ssl-ca=/path/to/database-ca.pem'
export NNTP_SERVER='news.example'
export NNTP_PORT=563
export NNTP_SSL=true
export NNTP_USERNAME='account'
export NNTP_PASSWORD='password'
export FINGERPRINT_WORKER_ID='laptop-1'
export FINGERPRINT_CONCURRENCY=8
rust/fingerprint-worker/target/release/nntmux-fingerprint-worker
```

Use a unique worker ID on each laptop. `NNTP_SERVER_A`, `NNTP_PORT_A`, `NNTP_SSL_A`,
`NNTP_USERNAME_A`, and `NNTP_PASSWORD_A` configure an optional alternate provider;
`FINGERPRINT_ALTERNATE_PERCENT` defaults to 10. Provider TLS validates public roots
and the configured hostname.
`NNTP_TIMEOUT` defaults to 20 seconds per I/O operation.

All clients share the rows in `release_fingerprint_connections`: one row permits one
live connection. The migration reserves 32 primary and 8 alternate slots in total,
not per laptop. Reserve the remaining account allowance for other application
processing. `FINGERPRINT_CONCURRENCY` is a local task limit, not an extra connection
allowance. The worker never creates or enlarges shared budgets automatically.

`--once` handles at most one claim and exits, and `--max-jobs N` bounds a local run.
Ctrl-C stops new claims and lets active jobs finish within a 1,800-second ceiling.
A crashed/sleeping client loses its job and connection leases after 120 seconds;
active work renews them every 30 seconds. Expired claims or replaced source digests
cannot commit results. Each claim retries at most three times with a bounded delay.
Logs contain lifecycle events and release IDs, without credentials or article IDs.
An alternate-provider failure opens a shared 15-minute database cooldown; after it
expires, only one client may probe recovery, and an old probe cannot clear a newer failure.

## Local verification

```sh
cargo test --locked --manifest-path rust/fingerprint-worker/Cargo.toml
cargo clippy --locked --manifest-path rust/fingerprint-worker/Cargo.toml --all-targets -- -D warnings
cargo fmt --manifest-path rust/fingerprint-worker/Cargo.toml -- --check
cargo build --locked --manifest-path rust/fingerprint-worker/Cargo.toml
XDEBUG_MODE=off php -d memory_limit=1G rust/fingerprint-worker/tests/parity.php
```

The PHP harness uses offline fixtures and compares complete normalized compact-v1
metadata. Only collector engine and nondeterministic retrieval timings are excluded.
It covers clean/multipart/empty articles, CRC/size/framing failures, identity failures,
PAR2 extraction, sampling and record truncation. PHP is required for this parity test
only, not for the client.

Set `FINGERPRINT_TEST_DATABASE_URL` to a **disposable**, migrated MariaDB database to
run real transaction/claim and executable-to-fake-NNTP integration tests. These tests
insert and remove six test release IDs starting at 9,900,001 and an `integration-test`
provider slot. They also run three independent CLI processes against a fake NNTP
server, temporarily cap the shared primary budget at two, verify six unique results
and the observed socket cap, then restore the previous slot inventory. Without that variable, the database test prints a skip explanation; unit/socket
and gzip tests still run. Do not point this variable at production.

The first version opens provider connections per release and reuses them for that
release's sampled articles; it does not yet retain connections between releases.
No production speedup is assumed from the Rust rewrite. Benchmark provider setup,
network throughput and aggregate completion rate before replacing the live workers.
NZB storage accepts up to 128 MiB decoded, while the compact manifest analyzer retains
the PHP collector's 32 MiB parsing ceiling. Larger manifests fail collection rather
than being silently truncated or reported complete.
