# Wave code lessons

The wave code added paired PHP and Rust "plan" functions, parity tests and benchmarks. It is being deleted in small slices, newest wave first. This file records what each slice taught, so the lessons outlive the code. The Rust indexer in `rust/indexer` is the intended audience.

To read deleted code, run `git show <commit>^:<path>` on the commit that removed it.

## Lessons so far

1. **Start from a caller.** Wave 68 added 25 methods and none has a caller in `app`, `routes`, `resources`, `config`, `bootstrap`, `database`, `scripts` or `docker`. Before writing a function, name the code path that will run it in production.
2. **A faster function isn't a faster system.** Wave 68 cleared the 1.5x gate on all five classes, by 4.4x to 9.9x depending on the class and measure. No Dockerfile or `docker/` file references `rust/pipeline-ffi`, so production never loads that library. Measure the running service, using the indexer's metrics.
3. **A default means nothing without a route to it.** `W68_RUST_FFI_DEFAULT = true` reads like a production rollout, but nothing reached the code it selected. Report what a deployment actually ran, not what a flag says.
4. **Don't invent inputs.** Items such as `redis|operational|12|up|ok|10` matched nothing the collectors read. The real `ServiceStatusCollector` reads stored health results. Test against data captured from the real source.
5. **Parity shows only that two copies agree.** The Wave 68 test ran 705,655 assertions and the review fuzz found 0 mismatches across 15,000 batches. Neither shows that the logic is right or needed, because both copies shared one invented specification.
6. **Generated volume hides its cost.** One pull request added 3,612 lines and changed one existing line, and it passed review with no blocking findings. Each class also gained its own toggles, library handle and packed bit layout. Review should ask whether the code should exist, not only whether it is correct.
7. **One test can hide the rest of CI.** The master run on 2026-10-26 reached the full PHPUnit step only after a stale pin was fixed, then stopped about 40 minutes in. Every later MariaDB qualification step depends on that step. Keep critical checks in separate jobs, so one failure can't skip them.
8. **Check the memory limit that actually applies.** The full suite died at the first `Premature end of PHP process` in a Wave 44.2 test, which passed alone. With that test gone, the suite ran on until it exhausted 1 GB in `PredbFilenameSourceDiagnosticTest`, an unrelated test. `phpunit.xml` sets that limit and overrides the workflow's `-d memory_limit=2G`. The first crash may have had the same cause, though its log gave no reason. Long test runs should report memory as they go, and large-input tests belong in their own bounded job.
9. **Speed up where the time goes.** Wave 67.4 sped up in-memory plans by 1.73x to 4.18x. `IsbnDbService`'s real work is an HTTP request through `$this->client->get`, which the plans never touch. The indexer's speed work should target I/O: NNTP, the database and metadata APIs.
10. **Check scope before porting.** Wave 67.4 covers IGDB games, ISBNdb books and invitations. The indexer covers TV and movies, so none of this is worth porting.
11. **Layers pile up when nothing gets removed.** `IsbnDbService` already had Wave 39.4 toggles when Wave 67.4 added a second set. Delete the old route when adding a new one, so each behavior has one path.
12. **Share a helper once it repeats.** Each wave class carries its own state helper for routing and the library handle. After this slice, `app` still has 493 copies across 400 files. Put repeated plumbing in one place, so a fix lands once.
13. **Keep one access decision.** `LogFileRepository`'s listing is its access whitelist, yet the Wave 67.6 plan computed its own `accepted` flag that nothing read. A second answer to "may this be read?" can only drift from the real one.
14. **Keep the design worth keeping.** `LogSearchCoordinator` is real code that stays: it sends a search to the index only when the index is reachable and caught up, falls back to a scan otherwise, and says which engine produced each result. The Wave 67.5 plans added nothing to that. The indexer's search should use the same shape.
15. **A check that stays red stops being a check.** The #1083 pull request noted that CI failed before PHPUnit on a stale hash pin already failing on master, then merged. Fix a broken check or remove it, because a known failure hides new ones.
16. **Never send a constant across a language boundary.** `XxxPipe::pipeName()` has PHP and native twins behind a toggle, just to return a fixed name. That code is older than Wave 67.1 and stays for a later slice. If a value needs no computation, return it.
17. **Measure cleanup by what it clears.** Wave 67.1 reported 12.16x on planning for `CollectionCleanupService`, whose real work is database cleanup. A cleanup job should be measured by rows cleared per second and time spent holding locks.
18. **Keep timing gates out of unit tests.** Four benchmark test files failed during a run on a machine with a load average above 24, then passed when rerun with load near 14. A speed gate in a unit suite fails on whatever else the machine is doing. Run benchmarks in their own job on a quiet machine, and compare trends.
19. **Use typed values, not packed words.** The Wave 67.2 episode plan packed its result into bit fields such as `min(series, 4095) << 8`, so a larger value is silently cut to the cap. Use Rust structs inside the indexer, and return an error when a value doesn't fit.
20. **Don't tune the cheap step.** Wave 67.2 reported 13.24x on planning how `ForkingService` splits work among workers. That plan is arithmetic on a few numbers, while the workers it starts run backfill, binaries and post-processing. Tune the workers' throughput.
21. **Say what a change does.** The #1052 pull request says "Migrate/accelerate", but it only added lines. Nothing was migrated and no caller changed. Report a migration only when production has switched and the old code is gone.
22. **Size is a warning.** `IgdbHttpException` was already 392 lines when Wave 67.3 added 324 more. When an exception class grows that large, ask what the code is doing there.
23. **Port what classifies, not what describes it.** Wave 66.6 added plans beside `TvPipe` and `MoviePipe`, and the pipes never called them. The real category rules stay in those pipes' own methods, so the indexer's identification work should read those methods and ignore any wave plan.

## Slices

| Slice | Removed | Commit that added it | Lessons |
| --- | --- | --- | --- |
| 1 | Wave 68.3 monitoring plans: 5 PHP classes, `monitoring_horizon_verification_status_tmux_grafana_w683` and its parity test | `436c89705` | 1 to 6 |
| 1 | Wave 44.2 parity test only. Its PHP and Rust code goes with Wave 44 | `719bd8f9e` | 7, 8 |
| 2 | Wave 67.4 metadata plans: `Company`, `Game`, `Platform`, `InvitationService`, `IsbnDbService`, their Rust module and parity test | `2fac89eed` | 9 to 11 |
| 3 | Wave 67.6 plans: `LogFileRepository`, `LogReader`, `LogSearcher`, `SearchQuery`, `Mcp/ProcessRunner`, their Rust module and parity test | `382db8a64` | 12, 13 |
| 4 | Wave 67.5 plans: `LogIndex`, `LogSearchCoordinator`, `ManticoreLogSearcher`, `LogEntry`, `LogEntryParser`, their Rust module and parity test | `2a092659e` | 14, 15 |
| 5 | Wave 67.1 plans: `XxxPipe`, `CollectionCleanupService`, `CollectionsCleaningService`, `ConfigurationProvider`, `DomainConfigurationRepository`, their Rust module and parity test | `a94d3fa42` | 16 to 18 |
| 6 | Wave 67.2 plans: `ProcessingRuntimeStateRepository`, `ConsolesProcessor`, `EpisodeHydrationService`, `FanartTvService`, `ForkingService`, their Rust module and parity test | `43f2624bf` | 19, 20 |
| 7 | Wave 67.3 plans: `GamesProcessor`, `GdprNotificationService`, `GenreService`, `IGDB/Client`, `IgdbHttpException`, their Rust module and parity test | `e16bffea1` | 21, 22 |
| 8 | Wave 66.6 plans: `MiscSafetyNetPipe`, `MoviePipe`, `MusicPipe`, `PcPipe`, `TvPipe`, their Rust module and parity test | `3e4315e2d` | 23 |
| 9 | Wave 66.1 plans: `BackfillConfig`, `BackfillService`, `BinariesConfig`, `BinariesService`, `BinaryHandler`, their Rust module and parity test | `f028654f5` | None new |
| 10 | Wave 66.4 plans: `BooksProcessor`, `CategorizationPipeline`, `CategorizationResult`, `CategorizationService`, `AbstractCategorizationPipe`, their Rust module and parity test | `9a6f92653` | None new |
| 11 | Wave 66.2 plans: `CollectionHandler`, `HeaderParser`, `HeaderStorageService`, `HeaderStorageTransaction`, `MissedPartHandler`, their Rust module and parity test. Also corrects lesson 8 | `2699b9dbd` | 8 |
