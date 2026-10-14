---
paths:
  - '**'
---

# Rust implementation and benchmark requirements

Try Rust first for new app logic. When fixing or upgrading code written in another language, attempt a Rust replacement for the affected logic. Keep the attempt within the task. Follow existing dependency and production rules. Documentation, comments, and configuration-only changes don't require a Rust rewrite.

## Preserve correctness

- Compare the Rust candidate with the existing implementation or an implementation native to that codebase, such as PHP or C++.
- Exercise the real caller. Verify matching results, errors, ordering, storage effects, and resource ownership with tests before comparing speed.
- Retain a working native implementation when Rust fails correctness or performance checks. Document what you tried and why Rust wasn't adopted.

## Measure the speedup

- Use macOS or Linux. Freeze source commit hashes, build profiles, build tools, inputs, hardware, resource limits, and the measurement protocol. Use optimized Rust builds and comparable baseline settings.
- Run repeated paired measurements with identical inputs and randomized order. Preserve raw results, warmup details, failures, and reproduction commands. Use the [performance validation skill](../skills/performance-validation/SKILL.md) for experiment controls.
- Measure the actual common paths and representative uncommon workloads. Include caller, foreign function interface, serialization, allocation, startup, and I/O costs that the app incurs. A isolated benchmark alone doesn't prove an app speedup.
- For each common path, require at least a 1.5× speedup. For each representative uncommon workload, require at least a 1.5× improvement in p99 latency. Aim for 2–3× in both cases. Use enough samples to estimate p99 reliably. Report uncertainty and sample counts.
- Compute latency speedup as `baseline latency / Rust latency`. Compute throughput speedup as `Rust throughput / baseline throughput`. Compare p99 latency with p99 latency. An average gain can't offset a failing p99 result or another required workload.
- Adopt the Rust replacement only when correctness passes and the evidence meets these thresholds. Missing, invalid, or inconclusive benchmark results don't qualify. Keep the native fix when Rust doesn't qualify and include the comparison in the PR.
