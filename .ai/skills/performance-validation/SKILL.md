---
name: performance-validation
description: Validate NNTmux ingestion/storage concurrency and performance claims, release admission/reset, NZB sufficiency, or cleanup correctness. Use when designing or assessing those changes and experiments.
---

# NNTmux performance validation

These procedures supplement `AGENTS.md`. They do not authorize dependency installation, production tuning, deployment, or containerized commands.

## Choose the experiment

- Start with existing `cargo test` integration tests (`just test`), real storage handlers, MariaDB `EXPLAIN` and lock-wait metadata, and OS/container resource counters.
- Treat temporary benchmark harnesses as experimental until committed, reviewed, and documented. Use Sysbench for a reproduced SQL transaction or a `cargo bench`/criterion harness for pure Rust only when existing tools cannot answer the question; follow dependency approval rules.
- Use one executor and a uniquely named disposable database/runtime per experiment. Run concurrency/capacity benchmarks on a separate host; production-host resource limits do not eliminate shared-resource interference.
- Keep production endpoints, credentials, bind mounts, and `.env` files (`docker/.env`, `docker/config/app.env`) out of validation runtimes. Use the services in `rust/compose.test.yml`.
- Before a MariaDB fixture, set an explicit private host and port (`TEST_DATABASE_URL`) and pass that endpoint to every child connection. Use `nntmux_testkit::TestDb`, which gives each test a random database name. Roll back transactions, reap children, and drop only owned fixtures.
- Full-suite runtimes need the pinned Rust toolchain (`rust/rust-toolchain.toml`), `--locked` builds, Node for the front end, and ffmpeg, mediainfo, unrar and 7z for post-processing tests. Copy all tracked test inputs (Docker, frontend, monitoring) without production data/config. Classify runtime/fixture failures before changing code and rerun affected checks after fixing the environment.

## Freeze and preserve correctness

- Freeze baseline/candidate source hashes, `Cargo.lock` identity, runtime image IDs, Rust toolchain and MariaDB versions, schema/indexes/collations, isolation/durability settings, resource caps, storage type, and protocol/order hashes. Record differences from deployment. A reduced fixture proves only its reproduced mechanism, not full-schema behavior or production capacity.
- Reproduce deadlocks through the actual handler with deterministic synchronization and an observed competing lock wait. Record both clients' statement and transaction outcomes and the matched wait graph. Show baseline failure and candidate success; an unrelated artificial deadlock or victim label is insufficient.
- Preserve transaction boundaries, upserts, aggregate COUNT/SUM/CASE calculations, retry policy, and lifecycle/deletion guards unless a separately evidenced change covers them.
- Capture access-plan evidence for dense, sparse, small, and actual maximum chunk sizes. Index hints and logical ID order do not guarantee bounded access or physical lock order; label preflight plans separately from executed-plan evidence.
- Independently verify initial committed unique parts/bytes, binary aggregates, collection readiness, crosspost membership, and orphans. Cover conflicting duplicates/first-write, replay, rollback, missing parents, and deletion races. Insert IDs remain provisional until commit; replay recovery does not make an initially exhausted batch successful.

## Compare performance

- Predeclare fixture, source pair, randomized paired order, warmup, timeouts, correctness gates, and useful-effect/regression criteria. Restore identical fixture state and use independent connections/processes. Freeze the protocol before execution; changes require a new comparison.
- Measure from start barrier through all worker joins, including retries. Capture CPU usage/throttling and database counter intervals around the measured window. Keep replay and verification outside it.
- Record missing artifacts, setup failures, child crashes, timeouts, skips, and exhausted batches as invalid observations with causes. Retain every failure.
- A pair is eligible only when both observations pass initial correctness. Report invalidity/reliability separately; normalize retry/lock costs by successful unique work and distinguish retry events, distinct failed articles, and missing unique parts.
- Small paired screens choose further experiments; they do not establish production throughput. Keep barrier-controlled causal tests separate from natural-schedule throughput tests.
- Separate CPU throttling, cache fit, storage, and lock contention before recommending concurrency, buffer-pool, or redo changes. Broad ownership refactors, hints on other writers, insert-delta arithmetic, retry-policy changes, and database tuning need their own evidence.

## Release admission and cleanup changes

For release admission/reset, interruption recovery, NZB sufficiency, or cleanup changes, read [release lifecycle and cleanup checks](references/release-lifecycle.md). Ordinary contention experiments do not require those additional checks.
