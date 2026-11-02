---
paths:
  - 'rust/**'
---

# Performance and benchmark requirements

Everything on this branch is Rust. These rules apply to changes that touch a hot path (yEnc decoding, header ingestion, release assembly, fingerprinting, verification, search sync, API queries) or that claim a speedup. Documentation, comments and configuration-only changes need no benchmark.

## Preserve correctness first

- Compare the change with the code at the base commit. Exercise the real caller. Verify matching results, errors, ordering, storage effects and resource ownership with tests before comparing speed.
- Keep golden fixtures and `rust/porting/check.py` passing. The `unsafe` ban and its `yenc` SIMD exception in `rust/WORKSTREAMS.md` still apply.
- If a faster version fails correctness or the thresholds below, keep the base code and put the comparison in the PR.

## Measure

- Use macOS or Linux. Freeze source commit hashes, build profiles, toolchain, inputs, hardware, resource limits and the measurement protocol. Use `--release` builds for both sides, with comparable settings.
- Run repeated paired measurements with identical inputs and randomized order. Keep raw results, warmup details, failures and reproduction commands. Use the [performance validation skill](../skills/performance-validation/SKILL.md) for experiment controls.
- Measure the actual common paths and representative uncommon workloads. Include caller, serialization, allocation, startup and I/O costs the app incurs. An isolated micro-benchmark alone doesn't prove an app speedup.
- Use enough samples to estimate p99 reliably. Report uncertainty and sample counts.
- Compute latency speedup as `base latency / new latency` and throughput speedup as `new throughput / base throughput`. Compare p99 with p99. An average gain can't offset a failing p99 result or another required workload.

## Thresholds

- A change meant to make a path faster (a new algorithm, a SIMD path, a cache) needs at least a 1.5x speedup on each common path and at least 1.5x on p99 for each representative uncommon workload. Aim for 2-3x.
- Any other change must not regress a common path or its p99 beyond measurement noise. Show the numbers when it touches a hot path.
- Missing, invalid or inconclusive benchmark results don't qualify. Keep the base code and say so in the PR.
