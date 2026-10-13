# To do

## Fingerprint review follow-up (not deployed)

- [ ] Re-run the complete PHPUnit and Rust native suites on the final reviewed follow-up head, including malformed RAR5 typed-header boundaries, collection budgets, terminal queue failure, and numeric overflow.
- [ ] Validate Linux CLI PHP FFI against the rebuilt native library and check native/PHP metadata parity; host PHP fallback tests do not establish Linux FFI behavior.
- [ ] Keep deployment on hold until these checks and independent review pass; the single deployment owner must verify image/source/schema parity and collected public metadata before reporting live validation.

## Exact poster lookup qualification (not deployed)

- [ ] Run the complete PHPUnit suite against the final merged poster-search and index changes; focused checks are not full regression qualification.
- [ ] Retest exact poster page/count queries with the complete release schema and joins in an isolated MariaDB runtime, including byte identity and active filters. The reduced fixture shows selective index access, not production capacity.
- [ ] Hold deployment until the designated deployment owner applies the migration and verifies reviewed source, runtime, schema, and live poster-search behavior together.

## Release identification follow-up (implemented, not deployed)

- [x] Exclude explicitly named recovery-volume subjects from ordinary naming probes, preserving explicit indexes, legacy candidates, bounded hidden candidates and durable resume. The earlier same-family partial-index restriction caused a regression. The volume-fallback repair permits standalone recovery metadata while prioritizing one volume per family, hidden candidates, then remaining volumes; bounded multipart parsing and the newer heartbeat/ownership checks remain in place.
- [x] Preserve string results from the release name cleaner and recheck cleaner-supplied PreDB references against their current stored title. Missing or changed references no longer attach an unrelated identity.
- [x] Run the affected PAR2, payload metadata, and PreDB matching regressions: 95 tests, 469 assertions (11 PHPUnit notices).
- [ ] Deploy through the single deployment owner and measure historical rename yield versus arrivals. The regression checks prove the repaired mechanisms, not sustained throughput or a shrinking production backlog.

## Retest the final synchronized PreDB/publication integration

The PR #73 review follow-up incorporates the merged PreDB/publication changes and fork master through PR #90, including fingerprint metadata (#84), native workflow checks (#86), renewable PAR2 sweep leases (#87), poster-search/verification repairs (#88), fingerprint parser/typing corrections (#89), and exact poster query/index repairs (#90). It retains the configurable RSS body cap and post-article callback alongside bounded-read ownership renewal. Completeness markers require a complete Main inventory with exact current evidence coverage and the current proof version. The additive migration leaves legacy markers untrusted until recertified and persists ownership for tables it creates; rollback preserves pre-existing marker tables and their rows. Targeted checks qualify their reproduced cases; complete integrated qualification remains deferred.

- [ ] Run the complete PHPUnit suite with an explicit 2 GiB PHP memory limit, native tests/parity, frontend build and required CI on the final synchronized PR head. A combined run aborted at the host default 128 MiB limit; that runtime failure is not a failing application assertion.
- [ ] Retest donor inventory/canonical identity, completeness generation/invalidation and reset, fresh/upgrade migration ownership, bounded RSS wire/decoded/item limits, multipart checkpoint resume and primary/alternate bounded-read ownership renewal together. Retain processing claims, exact release snapshots and standalone/missing-index recovery-volume fallback.
- [ ] Retest article-set coverage against independently reconstructed stored/emitted NZBs, publication transaction/row-lock rechecks, fingerprint after-commit dispatch, queue isolation, rollback and cleanup/reset behavior in private runtimes.
- [ ] Validate source/image/schema and live release/NZB/search evidence only through the single deployment owner after explicit deployment and writer-resume authorization. No deployment or production qualification is included in this audit.

## Qualify PAR2 donor inventory matching

- [ ] Validate the symmetric full-MD5/size donor inventory query on isolated MariaDB fixtures with sparse, dense and maximum descriptor inventories. The two-distinct-tuple threshold and symmetric coverage prevent a lone shared file or partial inventory from attaching a donor; they do not establish production throughput.
- [ ] Decide whether filename or payload classification should be persisted independently of optional file collection. Identical inventories made entirely of shared support files remain indistinguishable without that evidence; inventory agreement alone does not prove a scene release identity.

## Retest verification and throughput PRs after the expedited rollout

The 2026-10-12 throughput rollout was explicitly requested with minimal targeted checks and no PR review. Complete the deferred review and testing for PR #53, upstream PR #68 integration, and [PR #70](https://github.com/xbmc4lyfe/newznab-tmux/pull/70) (article concurrency/pipelining, candidate workers, and native decoding) before treating the expedited changes as fully qualified.

- [ ] Review the final merged diffs and run the complete PHPUnit and native suites.
- [ ] Retest pipelined BODY response ordering, missing/truncated/oversized articles, dot-stuffing, disconnects, cancellation, and alternate-provider retries.
- [ ] Retest overlapping worker claims, lease expiry, candidate-version changes, shared scratch reservations, failed cleanup, audit exclusion, and reset exclusion.
- [ ] Verify native/PHP decoding parity, corruption rejection, and reuse of decoded results without skipping manifest-identity checks.
- [ ] Reconstruct newly emitted complete and repaired NZBs from their actual article IDs in fresh scratch.
- [ ] Measure sustained admitted payload bytes versus verified payload bytes and queue growth; run capacity comparisons on an isolated host and preserve all failures.
- [ ] Check the deployed image/source/schema and live release/NZB/search evidence again after fixes.


## Turn the paused groups back on

On 2026-10-11 these groups were turned off (`active = 0`, `backfill = 0`) to cut collection churn while the backlog clears. Their files are posted with random names and random posters, so header-based grouping never completes a release. Together they held about 4.2M of the 14.1M collections, for about 270 releases over 50 MB a day.

| id | group | active | backfill | backfill_target |
|---|---|---|---|---|
| 97 | alt.binaries.coolkidweb | 1 | 1 | 3 |
| 98 | alt.binaries.faded-glory | 1 | 1 | 3 |
| 22 | alt.binaries.newznzb.golf | 1 | 1 | 3 |
| 23 | alt.binaries.newznzb.hotel | 1 | 1 | 3 |
| 24 | alt.binaries.newznzb.india | 1 | 1 | 3 |
| 25 | alt.binaries.newznzb.juliet | 1 | 1 | 3 |
| 85 | alt.binaries.superman | 1 | 1 | 3 |

The values in the table are what each group had before. To restore them:

```sql
UPDATE usenet_groups SET active = 1, backfill = 1 WHERE id IN (97, 98, 22, 23, 24, 25, 85);
```

Then clear the settings cache (`php artisan cache:clear` in the indexer container).

Their existing collections were left in place, not deleted. Headers missed while they're off can be recovered later by backfilling these groups.

## Revisit collection churn (deferred)

Options still on the table. None are applied yet, because big releases must not be lost:

- Delete one-file fragments of multi-file posts after about 15 minutes, while keeping the 2-hour delay for everything else. This needs a code change and a PR.
- Lower the collection delay from 2 hours to 1 hour for everything. The risk is that slow uploads get cut into incomplete releases.
- Filter random-name subjects at header ingestion. This is the most efficient option, but also the most likely to drop real posts.
- Decide about alt.binaries.wood: 1.44M collections for about 2.2k useful releases a day.

## Redesign rename backlog processing (deployed; throughput follow-up remains)

Implemented, reviewed, merged in PR #57, and deployed on 2026-10-11. The checkpoint migration ran before workers restarted; all application services passed health and reviewed-file parity checks. Sustained yield and net backlog trend still need assessment before increasing rename-worker concurrency. The goal is to recover useful release names while keeping incomplete and failed attempts eligible for retry.

Problems addressed in the implementation:

- `NzbContentsService::checkPar2()` calls `parsePAR2()` before checking the NZB subject, so it can download the first article of every entry before deciding whether it is a PAR2 candidate.
- Missing/unreadable NZBs and unsuccessful PAR2 attempts can set `proc_par2 = 1`. The grouped command also treats a failed NNTP connection as a false result that marks PAR2 processing complete.
- Long, unbounded PAR2 passes keep the rename pane occupied and delay the next database-only rename cycle.

Approved approach:

- [x] Select explicit PAR2 candidates before downloading; preserve a bounded fallback for obfuscated subjects.
- [x] Distinguish successful renames, completed checks without a match, and retryable failures; retain pending work after connection failures, timeouts, or unavailable NZBs.
- [x] Bound download batches and runtime so database-only rename checks run regularly. Verify fairness between recent releases and the historical backlog.
- [x] Test timeouts, missing articles, missing/corrupt NZBs, explicit and obfuscated PAR2 subjects, worker overlap, interruption/restart, and retry behavior in isolation with mocked NNTP.
- [x] Measure articles downloaded, actual names recovered, completed checks, retryable failures, and throughput. A lower pending count alone is not proof of successful recovery.
- [x] Complete affected tests, Pint, PHPStan, and PHP syntax checks before staging implementation. PR #57 PHPStan reported ten existing findings reproduced against its unmodified base; the cleanup below clears them in this follow-up worktree.
- [x] Deploy through the shared guard and validate live bounded batches, retained retry state, recovered names, and search-index synchronization.
- [ ] Assess sustained actual rename yield and net backlog trend against incoming releases before raising concurrency.

Evidence from the investigation: the separate database-only catch-up reported 33 changed names. The two supplemental experimental workers were stopped before redesign; the existing production rename worker continued. The redesign was validated in an isolated container without production mounts or network access.

Validation: the complete PHPUnit suite ran 2,633 tests and 20,987 assertions with no failures, 93 skips, and 54 PHPUnit notices. Pint and PHP syntax checks passed. Full-project PHPStan reported the same ten findings reproduced on the unmodified base revision; no new findings remain. Independent review findings were fixed and regression-tested, including the real checker completing an unmatched release too early, stale success state between releases, unavailable candidates blocking recovery volumes, and retry starvation. Shared claims were also exercised across separate PHP processes.

Default limits: 100 releases per PAR2 pass, a 60-second soft budget, four candidate probes per release per pass, and a 2 MiB limit for hidden first-segment probes. Per-release probe offsets and retry queues persist in `par2_rename_progress`, with cascading release deletion and cleanup after committed renames. Shared cache claims coordinate workers; sweep and grouped cursors have no fixed expiry. Apply the new migration before starting the updated workers. An in-flight NNTP read may finish after the soft budget. Delivery completed through [PR #57](https://github.com/xbmc4lyfe/newznab-tmux/pull/57); detailed production verification is in its [rollout record](https://github.com/xbmc4lyfe/newznab-tmux/pull/57#issuecomment-6114535667).

PR review regressions also cover malformed hidden-probe byte counts, thrown download/parser errors, grouped retry fairness, progress retained beyond seven days, raw-deletion cascade, concurrent renames, and rollback-safe checkpoint cleanup. Maintenance reset commands also clear checkpoints without relying on disabled foreign keys. The affected suites passed 108 tests and 357 assertions after these corrections; final full-suite CI is recorded on the PR.

Relevant code: `app/Services/Nzb/NzbContentsService.php`, `app/Services/Par2Processor.php`, `app/Services/NameFixing/NameFixingService.php`, `app/Console/Commands/ReleasesFixNamesGroup.php`, and `app/Services/Tmux/TmuxTaskRunner.php`.

## Remaining-work checklist (2026-10-11)

Work proceeds from small contract corrections to broader operational decisions. Completed means verification passed; changes in this follow-up worktree remain separate from deployed PR #57.

- [x] Clear the two search-cursor encryption contract findings while preserving existing tokens. `cursor_contract`: six tests / 15 assertions; full PHPStan clean.
- [x] Clear the two compressed-NNTP findings while preserving split-stream/terminator and EOF/error handling. `nntp_analysis`: five focused tests plus 17 related regressions pass; full PHPStan clean.
- [x] Clear the three movie lookup findings by using the Trakt provider's nullable result contract. `movie_null_fallbacks`: 20 tests / 48 assertions; full PHPStan clean.
- [x] Clear the remaining three findings: preflight index-name return type and obsolete User/Trakt suppressions. Preflight/Trakt tests pass; full PHPStan clean.
- [x] Finish the full regression suite and stage only this task's files. Pint, full PHPStan (zero errors), all-app/changed-test syntax lint, and independent source review pass.
- [x] Commit/push the follow-up corrections and open [PR #59](https://github.com/xbmc4lyfe/newznab-tmux/pull/59).
- [ ] Complete current-head CI and thorough independent/Codex review of PR #59; resolve any findings.
- [ ] Merge and deploy the reviewed follow-up corrections separately from PR #57.
- [ ] Compare rename completion with release arrival rate and determine why historical probes mostly defer. Keep checkpoints/retry eligibility intact while evaluating efficiency.
- [ ] Evaluate collection churn options above with evidence that large/slow uploads remain recoverable.
- [ ] Re-enable the seven paused groups only after the backlog/churn recovery conditions are met.

Live rename observation at this inventory: 17 completed bounded passes (nine recent, eight historical) report 22 recovered names and 2,433 received articles. Historical passes report two recovered names, one completed miss, five retryable outcomes, and 330 deferrals. These are reported outcomes, not evidence that the net backlog is shrinking. Continue distinguishing real recovered names from completed checks.

Next operational priority: inspect a bounded sample of existing historical NZBs and durable checkpoints and measure the candidate-priority repair after deployment. The sampled historical runs deferred 330 of 338 attempts (97.6%) and averaged 3.92 received articles per attempt, close to the four-probe cap. Current logs combine probe-budget, deadline, and claim-loss deferrals, so the live cause is not yet proven. The follow-up prioritizes explicit indexes, one recovery volume per filename family, hidden candidates, then remaining volumes. Regression fixtures reproduce repeated volumes crowding out useful candidates and retain later-volume fallback and durable resume. PR #83 superseded that policy with volume exclusion; the subsequent fallback repair restores family-based prioritization without excluding standalone volumes. Historical regression gates retained missing/corrupt-index volume recovery, partial/multiple recovery sets, obfuscated indexes, transient retries, restart/resume, changed NZBs, and worker overlap.

Monitor baseline for the next comparison (2026-10-11 22:46:54 UTC): the Renames row reports 465,835 unmatched and 24,317 matched. These monitor counts span all rename methods and must be assessed alongside actual changed-name/download outcomes.

Monitor comparison captured 2026-10-11 22:52:44 UTC: 465,869 unmatched (+34 versus baseline) and 24,334 matched (+17). In this short window names continue to recover, but unmatched rename work is still growing; measuring arrival rate and historical probe efficiency remains the next operational priority.

Follow-up correction verification: all ten PHPStan findings are cleared; full analysis of 849 files reports zero errors with no added baseline entries. The complete local PHPUnit suite passes 2,671 tests and 21,108 assertions, with 93 existing skips and 54 PHPUnit notices. Pint, PHP syntax lint, and independent source review pass. The correction batch is prepared on `codex/phpstan-backlog-cleanup` and remains separate from the deployed rename redesign. The correction batch is published in [PR #59](https://github.com/xbmc4lyfe/newznab-tmux/pull/59); current-head CI/review and release delivery are tracked above.

## Retest SQL contention repairs (2026-10-12)

Expedited shipping was requested with GitHub CI/CD skipped. Run the full regression suite and deployment qualification later; focused checks and live observations are recorded on each repair PR.

- [ ] Rerun the full PHPUnit suite, native decoder parity, and required CI checks for the expedited SQL repairs.
- [ ] Recheck rename-count equality across PAR2, metadata-only, missing-source, NZB-less, already renamed, and recent-only releases. The PAR2 fast path must skip metadata probes while preserving eligibility and counting a release once.
- [ ] Compare monitor query durations and scheduling gaps under the same workload, plus net rename backlog, actual recovered names, arrivals, and DB row-lock wait rates over an hour. The bounded SQL probe is not a sustained throughput benchmark.
- [ ] Retest verification snapshot batching with large multi-file collections, empty files, unordered segments, late arrivals/replays, expiry, capacity rejection, manifest rollback and concurrent ingestion. Compare archive transaction duration and capacity-lock waits before/after; batching retains the existing lock order and metadata budget.
- [ ] Retest processing-only Pulse suppression, the `PULSE_PROCESSING_ENABLED=true` opt-in, web/Horizon recording, config caching, file logs and Prometheus snapshots. Measure Pulse aggregate lock waits/deadlocks separately from collection/capacity-state waits; disabling processing telemetry does not resolve those other locks.
- [ ] Investigate remaining collection/capacity-state serialization under live ingestion; retain capacity accounting, manifest GC, parent locking and retry/header retention when shortening transactions.

## Retest collection archive lock order (expedited, 2026-10-12)

GitHub CI/CD and the full regression/deployment qualification were deferred at the user's request. The repair locks the exact collection through `ix_collection_collectionhash` before capacity state, skips busy rows without reserving bytes or deleting headers, and preserves candidate/manifest accounting and cleanup in the same transaction.

- [ ] Run the full PHPUnit suite, native parity and required CI checks. Rerun the affected verification, ingestion, cleanup, publication/import, rollback, expiry, filtered-replay, legacy-release and reset suites.
- [ ] Run `tests/Integration/ReleaseVerificationArchiveLockMariaDbTest.php` with `VERIFICATION_LOCK_TEST_HOST` pointing to an isolated MariaDB/MySQL server (optional username/password variables). It creates and removes only randomly named test databases. Cover primary/unique-hash contention, a locked capacity row, physical index choice, repeatable-read late arrivals (standalone and caller-owned transactions), skipped-header retention and successful retry with exact capacity totals.
- [ ] Stress concurrent header inserts, archive/delete, publication, GC, reset and legacy reconciliation. Confirm no new lock-order cycles, stale/recreated collection IDs are retained safely, busy collections retry after cursor wrap, and persistently busy collections do not starve indefinitely.
- [ ] Compare collection and capacity-row wait graphs, deadlocks, archive throughput, admitted/verified bytes and net backlog over at least an hour under comparable load. The global capacity lock still protects manifest reservation/cleanup; this repair removes collection waits while holding it, not every source of serialization.

## Retest candidate claim lock order (expedited, 2026-10-12)

GitHub CI/CD and full qualification are deferred at the user's request. Candidate claiming now selects up to 100 due IDs without locks, then revalidates and locks one primary row at a time with `SKIP LOCKED`. Refused/busy rows release their transaction immediately. A mutex acquired before a rolled-back lease stops the claim attempt if retry loses eligibility, allowing the caller to release it safely.

- [ ] Run full PHPUnit, native parity and required CI checks; rerun candidate/parallel-worker, archival, publication, expiration, reset, GC and rollback suites.
- [ ] Run the isolated MariaDB integration tests for a locked due head, concurrent primary-row updates, physical PRIMARY plan, stale eligibility and release of callback-refused rows. Stress concurrent claim/publish/archive/finish/expire under both read-committed and repeatable-read isolation.
- [ ] Retest file-mutex acquisition followed by deadlock/retry, lease loss, process interruption and restart. Ensure a worker never advances with a previous candidate's mutex held, and two workers cannot process the same candidate.
- [ ] Measure candidate/capacity-row wait chains, deadlocks, claim latency and admitted/verified throughput over an hour. The 100-ID window has best-effort fairness; test persistently busy/refused early candidates and cursor/progress behavior before assuming starvation is solved.

## Retest indexed candidate queue selection (expedited, 2026-10-12)

GitHub CI/CD and full qualification remain deferred at the user's request. Claim selection reads at most 100 eligible rows per status through the existing `(status, next_attempt_at, id)` index, merges due-time/ID order, removes duplicate IDs after concurrent status changes, and retains the same 100-ID claim window. Primary-row claim transactions and eligibility revalidation remain unchanged. Returned rows are bounded; filtered active leases can still require examining more index entries.

- [ ] Run full PHPUnit, native parity, frontend build and required CI checks on the final synchronized upstream/fork head. Rerun candidate/parallel-worker, archival, publication, expiration, reset, GC, rollback and the upstream API/RAR/movie changes included in this branch.
- [ ] Run the isolated MariaDB integration suite with production-equivalent schema, collation and isolation. Check real queue plans for all three statuses, empty/sparse/dense queues, large active-lease cohorts, global due-time/ID ties, status transitions, expired leases, refused/busy rows and the 100-ID window.
- [ ] Compare frozen baseline/candidate revisions on a separate validation host with identical fixtures and randomized paired order. Record executed rows examined, sort work, claim latency, retries and correctness; preserve failed observations. Preflight EXPLAIN and one production read-only index probe do not establish production throughput.
- [ ] After explicit writer-resume authorization, verify deployed source/image parity and observe an hour of claim latency, collection/capacity lock waits, admitted/verified work and net backlog under comparable load. Queue selection does not remove the remaining shared capacity-row serialization or manifest/filesystem work inside its transaction.

## Retest archival snapshot capacity lock scope (expedited, 2026-10-12)

GitHub CI/CD and full qualification are deferred at the user's request; deployment remains on hold. Archival builds header snapshots while holding the collection parent, before acquiring the global capacity row. An advisory filtered-status read preserves the cheap terminal-replay path; locked candidate validation remains authoritative, with a fallback snapshot if the status changed. Metadata-budget failures wait for that locked validation so a stale repeatable-read view cannot block terminal replay cleanup. Legacy release locks retain capacity-before-release order. Manifest merge/persistence, accounting, deletion and transaction/rollback boundaries remain inside their existing protected section. A caller that already owns capacity cannot have that inherited lock shortened by this change.

- [ ] Run full PHPUnit, native parity and CI qualification on the final upstream-synchronized fork head, including the integrated TV pipeline/retry migration, exact duplicate-show recovery, scheduled TV lookup-mode guards and actionable backlog/pane gating. Migrate against a private full-schema MariaDB clone and verify source/image/schema parity separately before any authorized rollout.
- [ ] Run the isolated MariaDB snapshot tests for new/legacy collections, standalone/caller-owned transactions, multi-batch headers and competing capacity/parent locks. Reproduce actual archive/ingestion/publication/delete/reset lock graphs under read-committed and repeatable-read isolation; preserve late arrivals, first-write duplicates, crossposts, replay, missing/recreated parents and rollback/after-commit failures. Retest filtered-to-pending and pending-to-filtered races and oversized filtered replays.
- [ ] Freeze source/vendor/runtime/schema/index/collation/durability identities and compare baseline/candidate on a separate validation host, using randomized paired order, independent processes, identical restored fixtures and predeclared correctness/regression gates. Record snapshot/capacity lock duration, executed query work, CPU/throttling, retries and failed observations; the deterministic lock test alone does not establish production throughput.
- [ ] After explicit deployment authorization, verify runtime parity and observe an hour of collection/capacity waits, admitted/verified work and net backlog under comparable load. Manifest compression/fsync, merges and collection deletion still hold capacity; investigate them separately if they remain dominant. Validate newly emitted NZBs in fresh scratch from their actual article IDs, preserving active files and stable lock inodes during cleanup.
