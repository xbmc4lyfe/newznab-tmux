# To do

## Qualify authoritative backfill date targets (2026-10-14)

Use the same selected NNTP connection for backfill targeting and storage. Date-based admission requires the requested article's parseable date from that provider/group; unrelated local part numbers, neighboring article dates and the current clock cannot supply it. Missing endpoint or midpoint metadata defers the attempt. Successful exact probes retain the approximate date search with bounded, integer-safe arithmetic; article Date ordering is still not guaranteed. Failed overview ranges must retain their cursor, while successful empty ranges remain completed work.

- [ ] Run the full PHPUnit/native retest and required CI on final integrated master, including default/reused multi-group callers, initial forward indexing, large article numbers, missing/mismatched dates, failed and empty ranges, storage repair, cooperative drain/retry boundaries and durable deployment recovery holds.
- [ ] Verify a guarded rollout, reviewed source/image parity, service health and M4 TLS connectivity. The already-loaded legacy workers lack the new stop checks; source fixes alone do not establish a safe interruption/replay procedure.
- [ ] Observe natural date targets, range spans, cursor continuity, useful release creation and backlog change. Fixture prevention of excess work is not a measured production throughput gain; preserve unknown/sparse-date deferrals and failed observations.

## Qualify bounded waiting-inventory updates (2026-10-14)

Natural preparation diagnostics identified database updates as a material cost: the initial 5,000-record passes spent 6.7–12.9 seconds updating rows, while ready yield stayed below one percent. Batch at most 25 consecutive identical `waiting_upload` classifications within each existing page. Ready promotions and unknown retry updates remain immediate. Preserve every snapshot and ownership predicate, manifests, article IDs, capacity, fairness and the approved publication spot-check contract.

Multirow MariaDB updates first acquire only available guarded primary-key rows using `FOR UPDATE SKIP LOCKED` in a short transaction. Skipped rows stay eligible for a later pass. An earlier prototype without that selection reproduced deadlock 1213 against real ingestion invalidation in reversed collection/candidate order; retain that failed observation. A failed statement now leaves at most 25 waiting classifications uncommitted, and an outer caller transaction still controls its own commit or rollback. Singletons retain a single update. This prevents the reproduced candidate-lock cycle, not every secondary-index, gap, metadata or commit wait.

- [x] Reproduce the individual-update overhead through the real preparer; cover page/limit boundaries, both waiting reasons, ready/retry ordering, changed snapshots, diagnostics, deadline flushing and failed-statement recovery without per-row fallback. SQLite preparation coverage passed 38 cases and 1,405 assertions.
- [x] Compare frozen identical full-schema fixtures in randomized order with exact candidate-state, immutable manifest/article inventory and capacity parity. Five paired observations per workload produced median paired latency speedups of 7.91× for 1,000 consecutive waiting rows and 1.41× for the 75-percent-waiting mixed fixture; alternating reasons were 0.98×. Candidate update statements fell from 1,000 to 40 or 500 respectively. All observations, including wide timing variation, are retained. This local single-worker screen measures preparation latency, not production capacity or publication throughput.
- [x] Attempt an optimized dependency-free Rust grouping planner through the real PHP FFI caller. The full-service screen did not establish the required 1.5× improvement across common paths or reliable uncommon p99 gains. A separate 1,000-pair planner screen was slower through FFI on every workload. Retain PHP; do not promote small full-service timing differences into Rust adoption evidence.
- [x] Qualify full-schema MariaDB guards, empty/partial subsets, rollback, durable-prefix statement failure/recovery and dense/sparse generated SELECT/UPDATE EXPLAIN plus executed ANALYZE evidence: 41 cases and 423 assertions passed. The final explicit READ COMMITTED/REPEATABLE READ invalidation overlap rerun passed two cases and 24 assertions. The broader identification/verification domain suite passed 2,650 cases with 23 skips and 14,410 assertions; changed service PHPStan reported zero errors.
- [ ] Repeat final integrated PHPUnit/native suites and static checks; obtain clean local and current-head GitHub Codex approval and green CI before a verified signed squash.
- [ ] Let the existing deployment owner perform the guarded rollout. Verify reviewed source/image parity, health and provider connectivity, then compare natural preparation phases, stop reasons and ready promotions with retained slow windows and workload changes. Measure fresh NZB-bearing publication and useful naming separately: the unchanged 5,000-record/minute schedule cap and metadata-read outliers can still limit throughput.

## Qualify preparation run diagnostics (2026-10-14)

Opt-in preparation diagnostics report bounded per-run outcomes and phase durations from the existing metadata pass. Scheduled preparation enables them without additional candidate scans. Inspected rows, affected updates and ready promotions are distinct; a diagnostic record does not establish faster publication or backlog throughput.

- [ ] Run the full PHPUnit/native suites on final integrated master, including preparation fairness, deadlines, claim/version/backoff races, manifest read failures and diagnostics that throw. Preserve the original return values, exceptions, retry policy, inventory evidence and worker-lock lifetime.
- [ ] After current-head Codex approval, green CI and guarded rollout by the deployment owner, verify source parity, scheduled diagnostics, service health and M4 TLS connectivity. Observe natural runs and classify the actual stop reason, ready yield and selection/read-assessment/update costs before choosing another throughput change.
- [ ] Retain deployment transitions, worker-limit changes, empty cohorts and failed observations when comparing useful work. Use isolated MariaDB qualification for any later query or transaction optimization; these aggregate timings alone do not prove server lock hold time or production capacity.

## Retest additional admission with password checks disabled (2026-10-14)

Password checks disabled at insertion produce passwordstatus=0 while media remains pending. Share the disabled-mode password-state gate across scheduling, worker selection and locked claim revalidation. Merge two individually ordered, bounded status prefixes to retain indexed claim selection. Keep enabled-mode selection, size/category/NZB/preview gates, claims and retry windows unchanged. No publication or runtime-setting change is included.

Local qualification passed 27 focused tests (85 assertions), 1,029 broader tests (eight skips, 4,939 assertions), and 24 full-schema MariaDB cases (198 assertions). The MariaDB matrix covers both isolation levels, competing locks, committed state transitions and six actual generated-query plans across dense/sparse/ineligible inputs and small/seeded category tables. Status-prefix branches avoid filesort without hints; only the at-most-2K merged prefix is sorted. Rejected rows can still increase index scan work, and these results do not establish production throughput.

- [x] Reproduce skipped pending-media rows in the existing SQLite fixture; cover selection, bucket/backlog counts, claims, enabled mode and admission exclusions.
- [ ] Run focused/broader suites, static analysis and current-head Codex review. Retest disabled-mode MariaDB access plans and concurrent ownership with dedicated fixtures before making capacity claims.
- [ ] Verify guarded deployment, eligible live processing and useful filename/media evidence. The initial idle window had only arrivals below the configured 1 GiB minimum. At 16:47 UTC, a bounded read captured 21 fresh status-zero releases passing all the other admission gates; compare that exact cohort after rollout. Retain the existing input filters and do not report an elapsed speedup without paired evidence.

## Retest scheduled verification without global summaries (2026-10-14)

Scheduled candidate workers skip the terminal global status aggregate; direct commands retain it by default. This removes an unused whole-table query while a worker slot is held. Query elimination is separate from Docker runtime recovery and does not establish sustained backlog throughput.

- [ ] Run the full PHPUnit/native suites on the final integrated master, including worker draining, duration limits, connection reuse, lease recovery, audit/reset exclusion and default manual summaries.
- [ ] After guarded deployment, verify reviewed source parity, scheduled `--no-summary` arguments, service health and M4 TLS connectivity. Observe natural worker turnover and fresh complete NZB-bearing releases; retain downtime, slow windows and workload changes when comparing sustained backlog throughput.

## Qualify mapped standalone video article reuse (2026-10-14)

Reuse a freshly checksum-qualified primary first article that matched an ambiguous PAR2 main-video entry. Return its original yEnc metadata through an opt-in single-article path after the existing connection gate. Keep normal aggregate results first, mapped archives ahead of video receipts, and pending receipts within the shared limits. Preserve the ordinary media byte/deadline/offset checks, thumbnail inputs and filename evidence; mapping itself still obtains fresh internal receipts. No publication or main-video eligibility change is included.

- [x] Reproduce mapping followed by video-head fetching with the same bytes, genuine offsets, metadata and thumbnail inputs, then verify one fewer BODY read. Cover strict admission exclusions, first failure, connection failure, alternate fallback, scope reset and unchanged offered-byte budgets.
- [x] Qualify full-video-cache admission of a later archive, full archive/aggregate rejection of video receipts, and pending/video eviction before protected entries. Measure all retained payloads against the four-entry, 8 MiB per-entry and 16 MiB total limits.
- [ ] Run final reviewed focused/native suites and required CI; obtain current-head Codex clearance, verified signed squash and guarded deployment by the existing owner. Observe actual reuse and useful naming, and attribute network versus decoding/probe/extraction costs before reporting an elapsed-time speedup.

## Retest publication phase timing (2026-10-14)

Publication timing is opt-in and reports aggregate durations after the outer transaction. State-lock acquisition includes query/network overhead; callback time after acquisition excludes commit/after-commit finalization. These measurements identify candidates for optimization, not a proven throughput improvement.

- [ ] Run the full PHPUnit suite, native parity and required CI checks on the final synchronized fork master. Focused publication checks do not replace the full retest.
- [ ] Retest real MariaDB retries, lock waits, nested transactions, rollback and after-commit failure with timing both enabled and disabled in an isolated runtime. Confirm diagnostic failures cannot change publication, artifact ownership or accounting.
- [ ] Compare natural publication phase distributions with queue, collection/capacity wait graphs and fresh NZB-bearing release counts under comparable load. Retain slow windows, workload changes and deployment downtime; distinguish callback duration from actual server lock hold and separately account for manifest cleanup transactions.
- [ ] After any optimization, independently verify newly emitted complete article inventories and source/image parity, service health and M4 TLS connectivity. Keep full payload reconstruction qualification separate from bounded spot checks.

## Qualify mapped archive article reuse (2026-10-14)

Avoid downloading a mapped archive's first article twice within one additional-processing release scope. Retain only complete primary-provider BODY results with checksum-qualified yEnc framing and an independently matched PAR2 file entry. Preserve ordered prefix/error behavior, provider fallback, release scope and group binding, aggregate-cache priority, and the shared cache limits. Cache admission failure must retain the existing download path. Publication remains on the approved bounded spot-check contract.

- [x] Reproduce ordered socket reuse through actual archive extraction, SQLite filename persistence and the ordinary naming consumer. The retained and checksumless control paths produced identical archive bytes and exact final titles, with one fewer BODY command in the retained path. Independently reproduce and fix pending receipts bypassing the shared limits; unmatched receipts are now discarded at every attempt exit and share entry/byte accounting. These fixtures establish the mechanism, not production speed.
- [ ] Run the final reviewed PHPUnit/native suites, including corrupt or checksumless framing, multipart ranges, alternate sockets, missing middle articles, connection failure, scope changes, duplicate article IDs and cache-pressure eviction. Verify physical BODY transcripts separately from logical request metrics.
- [ ] Retest the actual parser-to-persistence-to-ordinary-filename-consumer flow. Compare exact archive bytes, persisted filenames and final release identity between retained and nonretained article results; mocked filename assignment alone is insufficient.
- [ ] Obtain current-head Codex clearance and green CI, verify signed squash/source parity, and let the designated deployment owner perform the guarded rollout. Retest integrated master after subsequent throughput PRs.
- [ ] Observe naturally reused article bytes and useful release identities after rollout. Measure sustained useful naming yield against comparable workload and provider conditions. Avoided BODY reads and fixture timings do not establish production identification throughput.

## Qualify filename work reopened by additional processing (2026-10-14)

New persisted filenames reopen the ordinary filename pass for unrenamed, unlinked releases. A no-match consumer completes that pass only while its release identity and exact inspected filename inventory remain current. Duplicate-only replays do not reopen completed work, and external lookups remain outside the short completion transaction.

- [x] Qualify the core interleavings on an isolated local MariaDB 11.4.8 with the imported 121-table schema and the composite `release_files` key without an `id`. Both READ COMMITTED and REPEATABLE READ passed actual producer-first and consumer-first insertions; both also rejected a simulated same-count case-only filename replacement. Locking reads saw newly committed filenames even when ordinary REPEATABLE READ reads retained the older snapshot. The same isolated run passed six renamed/category completion cases; these fixtures do not establish production contention or throughput.
- [ ] Extend MariaDB qualification to duplicate replay, GUID replacement, claim loss, rollback, XXX mixed inventories and linked direct filename checks. Those cases currently have focused SQLite regression coverage; the core MariaDB interleavings do not qualify every lifecycle case.
- [ ] After current-head Codex clearance, green CI and a guarded rollout by the deployment owner, verify deployed source parity and observe natural filename additions reopening work. Record actual useful identities recovered and sustained naming yield; the exact-title regression fixture and queue eligibility alone do not establish production speedup.
- [ ] Qualify existing stranded filename work before any bounded historical requeue. A read-only sample of 16 completed, unrenamed releases with 357 stored filenames produced zero matches through the real ordinary consumer; syntactic filename candidates alone are not sufficient identity evidence.

## Qualify equivalent PAR2 source-proof reuse (2026-10-13)

Equivalent complete volumes reuse a successful source proof only within one verification invocation. Every volume still receives packet checksum, filename, alias, conflict and scratch checks. Mixed, incomplete or inconsistent metadata retains the original proof path. Any repair invalidates all earlier cached proofs; final source MD5 and whole-file CRC checks remain authoritative.

- [ ] Run the full PHPUnit/native suites on final integrated master, including real PAR2 repair, partial recovery, conflicting metadata, replay, lease loss and fresh reconstruction from emitted NZB article IDs. Focused regressions do not establish full qualification.
- [ ] Measure large multi-volume candidates on a separate validation host with frozen source/vendor/runtime and restored fixtures, paired randomized runs and correctness gates. Reduced proof invocation counts establish avoided work, not production throughput.
- [ ] After an authorized guarded rollout, verify source/image parity, app health and M4 TLS connectivity; measure verification yield, net backlog and candidate time under comparable load. Preserve the local database shutdown and shared deploy ownership.

## Qualify manifest preparation outside shared capacity (2026-10-13)

New ordinary archives and enqueue admissions prepare JSON/gzip bytes before requesting the shared capacity row. File persistence/fsync, authoritative candidate validation, accounting and hot-header deletion remain in the existing protected transaction. Known replays avoid unused preparation; existing-manifest merges and legacy release reconciliation retain their locked paths. A candidate committed during preparation requires a fresh locked merge. A caller that already owns capacity retains that lock.

- [ ] Run the full PHPUnit/native suites and required CI on the final integrated master; targeted lock and lifecycle regressions do not establish full regression qualification.
- [ ] Retest full-schema MariaDB admissions under both isolation levels, including fresh/caller transactions, filtered and expired replays, late candidates, legacy releases, capacity failures, publication, reset, rollback and after-commit failures. Check exact stored/emitted article sets independently.
- [ ] Measure preparation and capacity-lock duration on a separate validation host with frozen source/vendor/schema/runtime identities, identical restored fixtures, paired randomized order and declared correctness/regression gates. Preserve every failed observation; synthetic compression time and deterministic lock availability are not production-throughput evidence.
- [ ] The user authorized guarded master catch-up deployment on 2026-10-13. After each authorized rollout verify reviewed source/image/schema parity, app health and M4 TLS connectivity; observe admitted/verified work and net backlog under comparable load. Compression moved outside an admission's own lock does not shorten inherited capacity locks, existing merges, legacy reconciliation or filesystem persistence.

## Fingerprint review follow-up (not deployed)

- [ ] Re-run the complete PHPUnit and Rust native suites on the final reviewed follow-up head, including malformed RAR5 typed-header boundaries, collection budgets, terminal queue failure, and numeric overflow.
- [ ] Validate Linux CLI PHP FFI against the rebuilt native library and check native/PHP metadata parity; host PHP fallback tests do not establish Linux FFI behavior.
- [ ] Keep deployment on hold until these checks and independent review pass; the single deployment owner must verify image/source/schema parity and collected public metadata before reporting live validation.

## Exact poster lookup qualification (completed 2026-10-13)

- [x] Run the complete PHPUnit suite against the merged poster-search and index changes. PR #105 Linux CI passed 5,323 tests and 33,716 assertions, plus 85 native yEnc parity tests and 4,604 assertions; the squash merge source tree matches the reviewed CI head. The isolated macOS run retains two platform-specific failures (setgid and the Linux-only yEnc fallback warning).
- [x] Retest exact poster page/count queries with the complete release schema and joins in an isolated MariaDB runtime, including byte identity and active filters. `ExactPosterSearchMariaDbTest` restores the complete MariaDB schema into a randomly named disposable database and passes 18 assertions: paged IDs and totals, report/NFO/video/failure joins, case/whitespace/token lookalikes, group/category exclusions, size/files/grabs/comments/added-date filters, and lookup after deleting the original release. The small fixture proves query correctness, not production capacity. Run with `POSTER_SEARCH_TEST_HOST` (and optional `POSTER_SEARCH_TEST_PORT`, `POSTER_SEARCH_TEST_USERNAME`, `POSTER_SEARCH_TEST_PASSWORD`) pointing to an isolated server.
- [x] Deploy through the designated deployment owner and verify reviewed source, runtime, and schema. The guarded deployment of fork master `ee1eeac5d142884b6e94bd6156dd4f020ae920cc` finished successfully on 2026-10-13 at 20:45:08 UTC. Web, Horizon, scheduler, and indexer were healthy; the search controller, request, middleware bootstrap, detail/sidebar template, search template, and release search service matched that revision in all four containers. The poster-index migration is recorded and `ix_releases_fromname` exists. A bounded lookup through the live public search service returned one release with total one and byte-equal poster values; this small lookup does not establish production capacity.
- [x] Verify the signed-in browser flow. On the guarded `6733615dea78373f363c87dbfd337d81d774ebe8` deployment (finished 2026-10-13 at 20:56:11 UTC), clicking a live release submitter opened the exact Poster value and one matching result; unchanged resubmission preserved it. Editing Poster changed the effective exact value and returned no results for the deliberately absent value. Clearing Poster removed the exact filter and retained the Usenet Group filter with 1,234 results. Direct advanced Poster entry combined with that group returned the same one matching release. All four application containers were healthy and matched the six reviewed poster-search files; the bounded live service lookup, migration record, and poster index were rechecked after this rollout. These representative checks supplement the isolated whitespace/case and full-schema regressions; they do not establish production capacity.

Submitter links and the editable advanced Poster field are merged in PRs #102 and #105, including exact matching and deliberate whitespace edits. Full-schema qualification is merged in PR #107; the initial deployment record is merged in PR #113. Deployment, bounded server lookup, and signed-in browser verification are complete. PR this completion record, obtain current-head Codex clearance and green CI, squash merge it, and pull fork master before starting the next TODO item.

## Release identification follow-up (implemented, not deployed)

- [x] Exclude explicitly named recovery-volume subjects from ordinary naming probes, preserving explicit indexes, legacy candidates, bounded hidden candidates and durable resume. The earlier same-family partial-index restriction caused a regression. The volume-fallback repair permits standalone recovery metadata while prioritizing matching-family volumes, hidden candidates, then one standalone volume per family and remaining volumes; bounded multipart parsing and the newer heartbeat/ownership checks remain in place.
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

- [x] Validate the symmetric full-MD5/size donor inventory query on isolated MariaDB fixtures. `Par2DonorInventoryMariaDbTest` imports the complete schema dump and applies every pending migration, including the 40-character legacy GUID repair. Twelve cases pass 50 assertions under read-committed and repeatable-read isolation: sparse partial/superset/mismatched/stale/byte-different inventories, duplicate prefixes that represent one content tuple, conflicting canonical titles, legacy GUID identity, the 256/257 donor flood boundary, and a 65,536-descriptor inventory whose final descriptor prevents a partial donor match. That SQL fixture exceeds the descriptor cardinality possible in one default 8 MiB naming metadata state; the query has no fixed global descriptor ceiling, and larger configured inventories are not exhaustively qualified. CI runs this matrix against disposable MariaDB with skips treated as failures. The two-distinct-tuple threshold and symmetric coverage prevent a lone shared file or partial inventory from attaching a donor; these checks do not establish production throughput.
- [x] Decide whether filename or payload classification should be persisted independently of optional file collection. Decision: retain exact descriptor filename evidence and versioned classification with the current release GUID and content tuple, independently of `release_files` and its collection toggle. `Par2Processor::applyInventoryForRename()` already sees descriptor names but only preserves hashes and sizes when file collection is off. The separate fingerprint collector records sampled descriptor names and format observations; its asynchronous JSON record is not consumed by donor lookup and does not guarantee complete, current tuple coverage. Two distinct shared support files can still satisfy the existing complete-inventory rule. Classification is therefore additional eligibility evidence, not proof of a scene title; filename suffixes and sampled format signatures alone must not establish payload identity. This decision does not change runtime donor selection.
- [ ] Implement independent descriptor evidence and conservative donor eligibility. Preserve exact filename bytes or an explicit lossless encoding, classification provenance/version, tuple identity and release snapshot guards. Keep complete inventory certification separate from payload eligibility; missing, legacy, conflicting or heuristic-only classification stays unknown. Require current independently validated payload evidence before automatic donor attachment, reject support-only inventories, and retain existing ownership checks, symmetric coverage, ambiguity rejection, bounded probes and retry eligibility. Reuse fingerprint observations only after validating their current manifest/descriptor/tuple relationship; do not make optional file collection or the enrichment queue a naming prerequisite.
- [ ] Qualify the eligibility change with real parser-to-persistence regressions for two shared support files, payload plus support, obfuscated payload names, text/e-book payloads, conflicting aliases, disabled file collection, replay, GUID replacement and missing legacy evidence. Run the migrated MariaDB query under both isolation levels and the full PHPUnit/native suites, obtain current-head Codex clearance and green CI, squash merge, then verify guarded deployment and fresh naming evidence before checking this implementation off.

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

Historical PAR2 traversal follow-up (2026-10-14): a real parser/processor fixture with 301 noncontiguous fragments before a recoverable release reproduced the 300-release quota stopping without downloads or a rename. Streaming bounded keyset pages with a separate local-retry allowance recovered the exact title with one article read, while retaining all 301 blocked checkpoints and pending flags. The regular attempt quota, deadline, article limits, ownership checks, and frozen cohort remain in force; this fixture does not establish production names per second.

- [ ] Obtain current-head Codex clearance and green CI for the streaming traversal, then squash merge and deploy through the shared guard.
- [ ] Retest the deployed zero-allowance compatibility setting, total inspection bound, deadline and lease loss between pages, cursor wrap under new arrivals, and unchanged multipart/volume fallback behavior.
- [ ] Compare actual recovered names and elapsed time against arrivals and net backlog over a stable live window; local skips and checked releases are separate from successful identifications.

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

## PreDB search coverage and naming throughput (2026-10-14)

The live PreDB index contained 160,869 documents while SQL held 1,523,091 records. Eight missing middle-range records could not be matched by their exact titles; additive indexing restored all eight matches without changing their `searched` values. The guarded additive worker subsequently processed the frozen 1,523,091-row catalog in 61.42 seconds with zero reported errors. An independent ID-by-ID audit found zero missing source IDs. This proves catalog recovery, not release-identification throughput.

- [ ] Retest PR #117's bounded launcher count against dense and sparse MariaDB catalogs and natural worker scheduling; preserve worker partitions and record actual recovered names.
- [ ] Review, merge, deploy, and qualify the new `nntmux:search-reconcile --predb --all` recovery path, including dry-run, missing-only bulk writes, index-read/write failures, post-write ID checks, and unchanged SQL naming state.
- [ ] Measure sustained recovered names, incoming releases, and net rename backlog after catalog recovery. A sample of 64 eligible releases with 961 cleaned file names had no literal SQL PreDB matches; catalog coverage alone does not prove useful evidence exists for every pending release.
- [ ] Retest bounded PAR2 article connection reuse after complete `430` replies: repeated misses followed by a valid article, exact response pairing, alternate fallback, malformed/truncated status lines, unread BODY interruption, CRC rejection and decoded/wire ceilings. Compare reconnects, received metadata and actual recovered names over a stable live window; avoided handshakes alone do not establish identification throughput.

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


## Qualify a Rust spot-check planner after the comparison (2026-10-13)

Guarded Rust integration is now approved after the comparison. Keep small or unsupported inventories and unavailable/older native libraries on PHP. Linux caller/memory qualification and final-head review remain gates for merge and deployment. Master already contains the PHP rank-cache fix (#106). Preserve every comparison, including review failures, the 128 MiB wide-fixture setup failures and the interrupted oracle-name collision experiment. Evidence: `/Users/deploy-user/.codex/artifacts/nntmux-rust-sampler-20261013/`.

The integrated Linux caller matched 1,440 native oracle cases and passed the declared large-inventory timing/memory gates across 60 observations. Median paired speedups by large fixture were 1.7–4.5×, with up to 11% additional process peak RSS. These shared-host synthetic results do not establish live throughput or maximum-manifest memory qualification. `codex --yolo` validation passed 284 tests with 9,877 assertions, two explicit skips and existing mock notices; initial isolated-runtime failures remain preserved. Integrated evidence: `/Users/deploy-user/.codex/artifacts/nntmux-rust-sampler-linux-20261013/`.

- [ ] If Rust integration is selected, run the complete PHPUnit and Rust suites on its final reviewed head in isolated Linux runtimes. Include strict sample parity, malformed/positional records, numeric strings/floats and serialization precision, object fields and unused serialization hooks, stable ties, inferred terminal priority, duplicate file replacement and IDs, early stop, byte budgets, numeric SHA comparison fallback and integer overflow.
- [ ] Test native-library absence/older symbols, fallback behavior, matched allocation/free ownership and the input ceiling. Compare the full validated/projected PHP-to-Rust caller against current master on compact and wide inventories, including small-input overhead and independent process peak RSS; retain every raw result and failure.
- [ ] Independently review the final current head before squash merge. Refresh production ownership/source parity and M4 TLS connectivity before any guarded deployment; local MariaDB stays stopped. Measure natural live verification/publication and backlog changes separately from synthetic planner CPU speed.

- [ ] Verify the self-hosted indexer scans the existing `indexer-conf.d/99-yenc-ffi.ini`, CLI FFI is callable, and the configured native path contains the new sampler symbol. Scheduled verification already opts in through `php -d ffi.enable=true`; keep web, Horizon and scheduler parent processes on their existing disabled FFI configuration. Retest the previously shipped native yEnc/CRC, identity/xref/date and PAR2/format/range modules as they become active.
