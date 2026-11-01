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
8. **Stress tests belong apart from unit tests.** The test that stopped that run feeds 16 MB strings and a 2,000,000-item array through the wave helpers. It printed `Premature end of PHP process` in CI but passed when run alone on an M-series Mac, so the cause isn't known. Run large-input tests in their own bounded job, so a crash can be told apart from the suite's own state.
9. **Speed up where the time goes.** Wave 67.4 sped up in-memory plans by 1.73x to 4.18x. `IsbnDbService`'s real work is an HTTP request through `$this->client->get`, which the plans never touch. The indexer's speed work should target I/O: NNTP, the database and metadata APIs.
10. **Check scope before porting.** Wave 67.4 covers IGDB games, ISBNdb books and invitations. The indexer covers TV and movies, so none of this is worth porting.
11. **Layers pile up when nothing gets removed.** `IsbnDbService` already had Wave 39.4 toggles when Wave 67.4 added a second set. Delete the old route when adding a new one, so each behavior has one path.
12. **Share a helper once it repeats.** Each wave class carries its own state helper for routing and the library handle. After this slice, `app` still has 493 copies across 400 files. Put repeated plumbing in one place, so a fix lands once.
13. **Keep one access decision.** `LogFileRepository`'s listing is its access whitelist, yet the Wave 67.6 plan computed its own `accepted` flag that nothing read. A second answer to "may this be read?" can only drift from the real one.

## Slices

| Slice | Removed | Commit that added it | Lessons |
| --- | --- | --- | --- |
| 1 | Wave 68.3 monitoring plans: 5 PHP classes, `monitoring_horizon_verification_status_tmux_grafana_w683` and its parity test | `436c89705` | 1 to 6 |
| 1 | Wave 44.2 parity test only. Its PHP and Rust code goes with Wave 44 | `719bd8f9e` | 7, 8 |
| 2 | Wave 67.4 metadata plans: `Company`, `Game`, `Platform`, `InvitationService`, `IsbnDbService`, their Rust module and parity test | `2fac89eed` | 9 to 11 |
| 3 | Wave 67.6 plans: `LogFileRepository`, `LogReader`, `LogSearcher`, `SearchQuery`, `Mcp/ProcessRunner`, their Rust module and parity test | `382db8a64` | 12, 13 |
