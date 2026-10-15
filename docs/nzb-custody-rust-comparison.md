# NZB custody: rejected Rust comparison

Decision: retain the native PHP directory synchronization and custody coordinator.
No Rust replacement, PHP computational fallback after replacement, deployment,
production speedup or live release yield is claimed.

The coordinator joins captured file generations, admission transactions, NFO SQL
receipts, and durable filesystem intents. It must retain sources on uncertainty.
The Rust trial replaces its directory durability boundary through an isolated
Rust process; all actual manifest capture, outcome, immediate deletion and
terminal companion cleanup calls remain identical. Moving SQL ownership into
that process has not been qualified. The current caller includes the durable moved-generation checkpoint before unlink
and persisted, retryable journal reclamation after manifest completion.
The synthetic failed-import fixture bypasses
XML parsing, NNTP, the application bootstrap and SQL, and does not certify the
whole importer or publication path.

## Reproduce the current rejection screen

From the repository root with its existing locked vendor directory and installed
PHP/Rust toolchains:

```sh
python3 benchmarks/nzb-custody/compare.py --output custody-screen-output
```

The output directory must not already exist. The runner compiles the std-only
Rust candidate with optimization level 3 and panic=abort, without changing Cargo
or dependencies. It substitutes a bridge in a separate PHP process, creates
random owned synthetic directories, verifies the captured digest, terminal
outcome, relinquishment, deletion flags and absent payloads, and removes only
its own fixtures. The PHP reference calls the production implementation.
Directory/file/missing primitive results must agree before timing.

The seed169 protocol randomizes paired variant order. Each of two warm blocks
uses one warmup plus five retained complete custody flows per variant. Ten cold
blocks use fresh PHP processes and one retained flow per variant. Process wall
includes autoload, startup, serialization, filesystem I/O and cleanup. Flow timing
includes create, capture, outcome, source and companion cleanup. All24 processes
and40 measured flows passed the stated parity checks. Source hashes, tools,
platform, raw observations and summaries are committed under
`benchmarks/nzb-custody/recorded`.

| Current synthetic screen | PHP median ms | Rust process median ms | PHP / Rust |
| --- | ---: | ---: | ---: |
| Warm complete custody flow | 7.031 | 254.918 | 0.028 |
| Cold complete custody flow | 16.768 | 281.692 | 0.060 |
| Cold whole PHP process | 101.164 | 376.093 | 0.269 |

This fails the required1.5x common-path gate by a wide margin. The shared host
was uncontrolled; the focused recovery run finished before this comparison;
these are rejection screens, not causal production throughput estimates.
Ten observations per arm do not qualify p99. The recorded sample quantile is an
order statistic only; representative uncommon-workload p99 remains unqualified.
No confidence interval or production performance claim is inferred.

An earlier larger run was aborted after a fixture assertion/style edit invalidated
its source freeze; its seven observations remain private invalid evidence and
are excluded above. It is not a successful comparison or a selected faster run.

## Earlier frozen alternatives and limits

Prior macOS ARM64/PHP8.5.11/Rust1.98.0 optimized offline prototypes used the frozen
capture/outcome service SHA256
`af54a8c686d2864f25a926b3478adf078836efffec415b2f7490a952bfabee1e`
and fixture SHA256
`3e35d2c5100b02023e64c26edd0271fa7fe03a0e647418207220da5808c77d90`.
These predate the current recovery protocol and cannot certify its correctness.
Their raw artifacts remain preserved privately; the current screen above is the
committed reproducible evidence.

- A direct Rust C ABI with request-local cached FFI passed normal primitive and
  captured-generation checks, but repeated warm whole-caller speedups were only
  1.25–1.29x versus the per-boundary Rust process. Fresh-process improvement was
  1.16–1.24x. Missing/disabled FFI failed closed. Synchronous native calls could
  not provide the helper's child isolation or bound a stuck syscall.
- A persistent Rust worker used bounded frames, one outstanding request, strict
  matching IDs/status, monotonic deadlines and owned-child teardown. Normal,
  changed-byte/inode and injected-sync checks passed; wrong-ID, oversized,
  partial, EOF, killed and hung replies failed closed. Warm caller medians improved
  1.66–1.88x against per-boundary processes, but cold whole-process gains were
  1.07–1.13x and cold create1.00–1.09x. The all-common gate failed. Production
  Linux, descriptor pressure, blocked-kernel-call teardown, signals and reliable
  p99 were not qualified.
- Native PHP plainfile directory descriptors passed type/error checks, with
  descriptors closed before callbacks and warning/exception failure preserved.
  Ten focused Linux tests/147 assertions previously passed in an owned,
  network-disabled fixture; macOS checks also passed. Current tests separately
  exercise the evolving custody protocol. Neither platform result is a hardware
  power-loss guarantee or proof about the production filesystem.

These experiments justify rejecting Rust adoption, rather than claiming a Rust
speedup from a primitive-only benchmark. Keep current correctness tests and
review gates; a future full Rust coordinator requires transaction/ownership,
interruption, error/runtime and representative end-to-end qualification afresh.
