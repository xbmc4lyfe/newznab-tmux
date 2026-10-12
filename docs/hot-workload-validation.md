# Hot-workload nonregression resolved

The fixed-sample isolated study completed October 11, 2026, 20:05:17–20:17:46 America/Chicago. [Evidence summary](performance/evidence/2026-10-11-hot-nonregression.json) records the design, effects, hashes and scope.

The safe PRIMARY-access candidate passes the declared 10% hot-workload nonregression budget in this controlled profile. No additional source change, unhinted fallback, weakened lock or runtime tuning was needed.

| Primary hot-workload metric | Candidate/baseline effect | 95% interval | Gate |
|---|---:|---:|---|
| Successful new-part throughput | 1.0018 | 0.9864–1.0174 | Lower bound ≥ 0.90: pass |
| Full-service batch p95 latency | 0.9897 | 0.9680–1.0119 | Upper bound ≤ 1.10: pass |

## Design and correctness

- Fixed 20 independent hot paired blocks, eight independently restored 10,240-part fixture epochs per source arm; four spread paired blocks with four epochs per arm as a secondary safeguard.
- One warmup per arm retained separately: 352 measured epochs plus 48 warmups, all 400 initially correct. Replay, conflicting first-write checks and normal worker exits also passed throughout.
- Source order counterbalanced 10/10 for hot blocks and 2/2 for spread blocks with a fixed randomized schedule. No interim budget evaluations, optional stopping, outlier deletion or treating nested epochs as independent samples.
- Primary effects are matched epoch-log throughput and p95 ratios averaged within each block; two-sided Student-t intervals use the 20 outer hot blocks. Aggregate work/time and pooled batch p95 are secondary diagnostics, not replacements for the preregistered primary metrics.
- App and database used distinct physical cores through logical CPUs 11 and 10, without CFS quota throttling; memory stayed 512 MiB each and buffer pool 128 MiB. Source, runtime, schema, input, retry and correctness identities remained frozen.

## What explains the earlier 12% estimate

The original two hot pairs both ran candidate first and used short quota-throttled observations with substantial between-run CPU drift. The balanced 40-observation follow-up did not reproduce that point penalty, but its interval was too wide. The separate pinned follow-up also remained imprecise. This longer, fixed grouped study resolved the budget without changing the candidate.

The actual hot-table baseline and candidate both choose PRIMARY range access. The changed locking SELECT is not a consistent dominant timing cost. Passive diagnostics found no garbage-collector runs during the 320 measured hot epochs. Indexed second-resolution timestamp changes correlated with only small median differences here; they do not establish the cause of earlier outliers. The exact cause of each old slow observation remains unproved. The evidence supports sampling/order/runtime variation rather than a persistent 12% source-code penalty; it does not support inventing a narrower locking fallback.

## Deadlock gains retained

Across measured epochs, baseline deadlocks totaled 147 and candidate deadlocks zero, with all candidate storage correct. The spread safeguard's geometric throughput ratio was about 2.05 and p95 ratio about 0.146. It is secondary evidence with four outer blocks, not a general production speed claim.

Cumulative row-lock waiting still rose from 173,072 ms to 197,071 ms. Successful serialization and failed deadlock attempts have different costs. Do not describe the result as lower total waiting or universal deadlock elimination.

The study uses synthetic cache-fit data, tmpfs, PHP NTS/x86 and a pinned resource profile. It establishes the declared experimental budget and preserves the reproduced correctness gains; it does not prove production capacity, real-storage performance or deployed-schema parity. Source review, merge, deployment and live verification remain the source/deployment owners' separate gates.
