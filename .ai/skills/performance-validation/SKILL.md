---
name: performance-validation
description: Validate NNTmux ingestion/storage concurrency and performance claims, release admission/reset, NZB sufficiency, or cleanup correctness. Use when designing or assessing those changes and experiments.
---

# NNTmux performance validation

These procedures supplement `AGENTS.md`. They do not authorize production tuning, deployment, or containerized commands.

## Choose the experiment

- Start with existing PHPUnit tests, real storage handlers, MariaDB `EXPLAIN` and lock-wait metadata, and OS/container resource counters.
- Treat temporary benchmark harnesses as experimental until committed, reviewed, and documented. Use Sysbench for a reproduced SQL transaction or PHPBench for pure PHP only when existing tools cannot answer the question.
- Use one executor and a uniquely named disposable database/runtime per experiment. Run concurrency/capacity benchmarks on a separate host; production-host resource limits do not eliminate shared-resource interference.
- Keep production endpoints, credentials, bind mounts, `.env` files, and cached application configuration out of validation runtimes.
- Before a MariaDB fixture, clear Laravel connection `url` and `unix_socket`, explicitly set private host and port, and pass that endpoint to every child connection. Use random fixture database names. Roll back transactions, reap children, and drop only owned fixtures in `finally`.
- Full-suite runtimes need locked test tools and extensions, including GD/EXIF, Node, Git, make, and procps as required. Copy all tracked test inputs (Docker, frontend, monitoring) without production data/config. Classify runtime/fixture failures before changing code and rerun affected checks after fixing the environment.

## Freeze and preserve correctness

- Freeze baseline/candidate source hashes, lockfile/vendor identity, runtime image IDs, PHP/MariaDB versions, schema/indexes/collations, isolation/durability settings, resource caps, storage type, and protocol/order hashes. Record differences from deployment. A reduced fixture proves only its reproduced mechanism, not full-schema behavior or production capacity.
- Reproduce deadlocks through the actual handler with deterministic synchronization and an observed competing lock wait. Record both clients' statement and transaction outcomes and the matched wait graph. Show baseline failure and candidate success; an unrelated artificial deadlock or victim label is insufficient.
- Preserve transaction boundaries, upserts, aggregate COUNT/SUM/CASE calculations, retry policy, lifecycle/deletion guards, and SQLite behavior unless a separately evidenced change covers them.
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
