# Standalone fingerprint worker

A native Rust executable for macOS/Linux. It reads compressed NZB content, claims,
leases, connection budgets and results directly from MariaDB/MySQL. It never writes
NZBs or article bodies to local files and requires no PHP installation at runtime.
The application still publishes releases and enqueues database jobs.

## Build and run

```sh
cargo build --release --locked --manifest-path rust/fingerprint-worker/Cargo.toml
```

During a staged production cutover, set `NZB_STORAGE=database`, `FINGERPRINT_WORKER=rust`,
and temporarily `NZB_DATABASE_LEGACY_READS=true`. All new sources are written only to SQL;
old unimported sources remain readable. Drain the PHP fingerprint supervisor before
transferring its queue with `php artisan releases:fingerprint-transfer --import-legacy`.
The transfer imports each queued source atomically before acknowledging Redis. Storage-only
archive imports do not enqueue millions of historical fingerprints. Disable legacy reads
after the retained archive has been imported and verified.

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

## macOS login service

Create a private environment file with shell-quoted `KEY=value` assignments and
`chmod 600 /absolute/path/worker.env`. Values are parsed as literal data; `$HOME`,
`$(...)`, backticks and other shell expressions are never expanded or executed.
The supported keys are the worker environment variables listed above.

```sh
python3 rust/fingerprint-worker/scripts/install_launchd.py \
  --binary /absolute/path/nntmux-fingerprint-worker \
  --config /absolute/path/worker.env \
  --label com.nntmux.fingerprint-worker \
  --dry-run
```

Remove `--dry-run` to install and load the service. Run as the worker's login user,
without sudo. The installer creates a private binary, runner and `worker.env` under
`~/Library/Application Support/NNTmux/fingerprint-worker`, plus a per-user LaunchAgent
plist. A unique worker ID is generated if the config omitted one. Credentials stay
out of the plist, command arguments and installer output. `--install-only` prepares
files without loading; `--domain user` selects the headless user domain instead of
`gui`. Binary/config paths containing spaces or quotes are supported.

The LaunchAgent starts at login, restarts failed workers and uses a 30-second restart
throttle. Logs are `logs/worker.stdout.log` and `logs/worker.stderr.log` under the
installation directory. SIGTERM and SIGINT stop new claims and drain active releases;
launchd allows 1,900 seconds before forced termination, above the job's 1,800-second
processing ceiling. To stop or upgrade an installed service, use `launchctl bootout`
on its `gui/<uid>/<label>` service and wait for exit before reinstalling. The installer
refuses to overwrite a loaded label. Sending SIGTERM alone drains the process but
KeepAlive subsequently restarts it.

Installer verification is fully offline and never loads a service:

```sh
python3 rust/fingerprint-worker/scripts/test_launchd.py -v
```
