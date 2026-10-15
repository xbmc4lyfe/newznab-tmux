# Rust benchmark Pages handoff

## Current recurring coverage

The shared `benchmark-pages.yml` publisher serves documentation and the current dashboard. It consumes only trusted master-push artifacts for the current SHA and attempt; feature and manual experiments remain separate. Existing historical reports retain their immutable URLs.

- `native-utilities-performance.yml` runs both fingerprint backends with `--coverage=full` and the CPU collector's separate synthetic and exact-host protocols on x86 and ARM. Fingerprinting covers nine cold/repeated workloads, including parsed XML, incremental collection, maximum-length IDs and disk spill. Default 31-pair screens omit unreliable p99 metrics. Manual `tail_pairs=1000` collects uncommon tails; `pairs=1000` extends every stratum.
- `archive-sample-performance.yml` measures actual PHP callers for six sample-planning strata, four PAR2 shared-projection comparisons and sixteen native archive/range diagnostics. A separate eight-stratum vendor archive comparison uses locked PHP RAR4/RAR5, ZIP and 7z readers with independent header oracles; this is shared-header evidence rather than full parser equivalence. Manual `mode=qualification`, `samples=1000` tests the predeclared common-median and uncommon-p99 criteria. Full-result differences are preserved: the PHP PAR2 fallback does not expose all native metadata.
- Every measured job preserves source/build receipts, input/result hashes, randomized order, warmup, every failed observation and resource endpoints. Resource checks compare process limits, affinity and all namespace-visible cgroup ancestors before and after execution. Outer host limits remain unknown. Missing required endpoints or changed visible static limits invalidate public eligibility. Optional I/O-controller files retain their presence and value; an absent controller remains unknown.
- Public summaries expose bounded metrics and hashes, with controls and native-only diagnostics labeled separately. They do not publish raw payloads, source graphs, binaries or private configuration. Raw evidence has 14-day Actions retention. The rejected PAR2 normalization prototype has a retained historical summary in `github-pages/docs/data/par2-normalization-screen.json`; its small common-path gains did not meet the adoption threshold.

The following original handoff is historical. Its unchecked items and old harness limits describe the initial inventory, rather than the current implementation. Remaining all-module gaps stay explicit in the generated dashboard.

## Original ownership and scope

The Pages-owner session creates and deploys the site. This change **does not** enable Pages, create a live deployment workflow, run production jobs, or claim all Rust modules are benchmarked.

- `github-pages/benchmarks/` contains frozen public reports from CI run [38593212508](https://github.com/xbmc4lyfe/newznab-tmux/actions/runs/38593212508), both architectures, PHP cost charts, 13 x86 native flamegraphs, capture receipts, provenance, and checksums.
- `prepare-benchmarks.sh` stages that tree under an existing or new site output directory's `/benchmarks/` path. It verifies checksums, rejects nonregular source files and refuses to overwrite an existing report subtree. It performs no benchmark, network call, build, or deployment.
- `benchmarks.yml.todo` is a disabled integration template outside `.github/workflows`. Integrate its staging step into the single publisher; do not enable a competing full-site deployment.
- A separate session is implementing current-build summary exporters and module coverage in branch `codex/benchmark-pages`. Coordinate with its final reviewed implementation; this document does not assert that branch is merged.

Run from the repository root, on macOS or Linux:

```bash
bash github-pages/deployment/prepare-benchmarks.sh /absolute/path/to/site-output
```

The script leaves other site files alone. Its report subtree must not exist yet. Merge the Pages owner's dashboard/root index into the same output directory, then upload that combined site. The benchmark entry point is `benchmarks/index.html`; preserve relative report-to-flamegraph links. Do not publish the deployment folder as site content.

### Preserve evidence identity

The frozen run's PR head is `dc74ec057ebdd80a3ff7d10cbcf12147c06cf55d`; its **executed checkout** is the synthetic CI merge `35c5a5a1bf1f8bd98a4e3f6f62ff4fa0f6eb4c04`. Both are recorded separately. Original artifact ZIP digests are in each architecture's `provenance.json`; `SHA256SUMS.txt` verifies the published subset. Preserve immutable `/benchmarks/runs/38593212508/{x86,arm}/` URLs and label them historical even when current source changes.

## TODO: build-wide publication contract

- [ ] Have one Pages publisher, owned by the site session, with `contents: read` by default and `pages: write` / `id-token: write` only on its deployment job. Publish trusted fork `master` only; never deploy PR checkout code or consume fork/PR artifacts as current master evidence.
- [ ] Follow [GitHub's Pages workflow contract](https://docs.github.com/en/pages/getting-started-with-github-pages/using-custom-workflows-with-github-pages) and [workflow security guidance](https://docs.github.com/en/actions/reference/security/secure-use). Configure Pages once; upload the combined output using `actions/upload-pages-artifact@v4` and deploy using `actions/deploy-pages@v4`.
- [ ] Refresh the current-build dashboard on each new master build and relevant workflow start/completion. Show pending, failed, skipped, invalid, unavailable, missing, and expired states explicitly. A green Cargo correctness run is not a performance result.
- [ ] Key every result by repository, workflow, source revision, executed checkout revision, architecture, run ID and run attempt. A rerun's failure must not be replaced by an older attempt's pass. Do not silently mix architectures/attempts into one current-build claim.
- [ ] Keep a separately labeled **last valid comparison** view. Search newest-first for a run with the required complete artifact set; a newer successful PHP-only or profile-only run must not erase an earlier complete C++ comparison. Do not describe this historical view as the current build's pass.
- [ ] Cover yEnc, article processing, release presentation, article fingerprint and CPU snapshot families, plus explicit gaps for every module below. Listen to both existing benchmark workflows, not just yEnc.
- [ ] Export bounded public summaries on failure as well as success. Archive approved summaries and report assets beyond the current 14-day CI artifact retention. Link raw CI artifacts separately; publish an allowlist of HTML/SVG/summary/status/provenance assets, not entire build directories, fixtures, binaries, logs or configuration.
- [ ] Validate report links, checksums and SVG availability before upload. Missing native sampling must remain unavailable, not become an empty success graph. Profile collection uses optimized diagnostic builds separately from uninstrumented timing.
- [ ] Freeze each measurement protocol before running, preserve every failure and invalid pair, and retain lockfile, library, source, toolchain, CPU/OS/limits, random order, warmup, timeout and output identities. Preserve CPU/throttling evidence around measured windows.

## Existing runners and commands

Inventory based on fork master `af9880b4e41912a77ae724eee594ac61747e56c5` plus PR #139. Re-audit after later module additions. There is one Rust crate, `docker/app/nzb-yenc-ffi`; it has no Cargo `[[bench]]`/Criterion bench harness. `cargo bench` alone does not run the application comparisons below.

Use disposable workers with PHP 8.5, locked Composer dependencies, FFI, Rust 1.98.0 and locked Cargo dependencies. Prefer the existing workflow as the complete command specification, including its native build/runtime manifests. Never source production `.env` or cached Laravel configuration. Do not install or change dependencies without separate approval.

```bash
composer install --prefer-dist --no-interaction --no-progress --no-scripts
cargo +1.98.0 test --release --locked --manifest-path docker/app/nzb-yenc-ffi/Cargo.toml
cargo +1.98.0 build --release --locked --manifest-path docker/app/nzb-yenc-ffi/Cargo.toml
```

Linux examples use an absolute `RUST_LIBRARY` ending in `target/release/libnzbyenc.so`; macOS uses the built `.dylib`. Set all example variables to frozen, isolated inputs and new output directories before invoking commands.

| Family | Entrypoint and command shape | Existing evidence / prerequisites | Pages work still needed |
| --- | --- | --- | --- |
| C++ RapidYenc vs Rust through PHP | `bash scripts/build-rapidyenc.sh`, then `php -d ffi.enable=true -d memory_limit=1G scripts/benchmark-yenc.php --cpp-native="$CPP_LIBRARY" --rust-native="$RUST_LIBRARY" --samples=31 --order-seed=20261013 --timeout=30 --min-improvement-percent=5 --max-regression-percent=5 --runtime-manifest="$MANIFEST" --output-dir="$NEW_OUTPUT"` | `yenc-performance.yml` paired-decoding job: 24 size/density/strict/tolerant/BODY strata, exact output, randomized pairs, simultaneous intervals, retained failures. C++ source/toolchain and runtime SIMD must pass existing hardware qualification; unsupported x86 is not a measurement. | Publish current per-stratum medians, intervals, valid/invalid counts, identities and failure causes, linked to charts and raw artifacts. |
| PHP fallback vs Rust | Same script with `--php-baseline --rust-native="$RUST_LIBRARY"` and the same protocol options | `php-decoding` job; `rust-php-performance-{runner}-{run_id}-{attempt}` artifacts. Do not substitute a C++ comparison for PHP integration evidence. | Include both x86 and ARM public summaries. |
| BODY assembly / metadata caller | `php -d ffi.enable=true -d memory_limit=1G scripts/benchmark-article-processing.php --profile --native="$RUST_LIBRARY" --samples=7 --runtime-manifest="$MANIFEST" --output-dir="$NEW_OUTPUT"` | `article-processing` job: full analyzer, framing, headers, body metadata, relationships, raw BODY and whole BODY phases. Output/metadata equality gates. `article-processing-{runner}-{run_id}-{attempt}` artifacts. | Distinguish diagnostic profiles from comparisons; publish all phases and fixture cases, including metadata FFI/JSON overhead. |
| Frozen caller baseline vs candidate | Same script with `--paired --baseline-dir="$BASELINE_ROOT" --candidate-dir="$CANDIDATE_ROOT" --baseline-native="$BASELINE_LIBRARY" --native="$CANDIDATE_LIBRARY" --samples=31 --order-seed=20261014 --runtime-manifest="$MANIFEST" --output-dir="$NEW_OUTPUT"`; optionally `--require-combined` | Manual `comparison=processing` or `all` prepares ancestor-only source snapshots on a non-default `codex/*` branch. Full baseline/candidate SHAs must be workflow ancestors, lockfiles match, and each graph gets its own locked dependencies/library. `caller_mode=screen` or `regression`. | Publish baseline/candidate identities and paired results; a profile-only run has no paired speedup. This is currently a median screen, not a p99 qualification. |
| PHP/native call-cost profiles | Workflow calls `bash scripts/profile-yenc.sh "$EVIDENCE_ROOT" "$FLAMEGRAPH_ROOT" "$PERF_BINARY"`; then `python3 scripts/yenc-performance-report.py --evidence-dir="$EVIDENCE_ROOT" --output-dir="$EVIDENCE_ROOT/visualization"` | Requires the workflow's pinned C++ archive/runtime build, Xdebug after timing, Linux perf exact DWARF/cpu-clock preflight, and pinned FlameGraph. 13 workloads. Capture limits/failure receipts must stay intact. | Include report HTML, chart SVGs and `profiles/*/native.svg`/receipts. CPU-clock weights are nanoseconds; actual sample records are separate. No timing claim from profiles. |
| Release-name presentation | `php -d opcache.enable_cli=1 -d ffi.enable=preload -d ffi.preload="$HEADER" -d opcache.preload="$PRELOAD" scripts/benchmark-release-names.php --samples=21 --iterations=100 --seed=20261014` | `release-presentation.yml` builds the native library/header, checks native/PHP/view parity, and runs a concurrent Docker web fixture. `HEADER` is the generated `benchmark-evidence/presentation.h`; `PRELOAD` is the absolute `docker/app/php/release-presentation-preload.php`. Three scenarios, seven row counts; under 16 rows uses the PHP dispatch path. Artifact `release-presentation-evidence`. | Add this separate workflow and public summary. Show PHP-dispatched small cases as N/A for native gain. Keep web-runtime proof separate from the pure rendering comparison. |
| Article fingerprint | `cargo +1.98.0 run --release --locked --manifest-path docker/app/nzb-yenc-ffi/Cargo.toml --bin benchmark-article-fingerprint -- --baseline="$BASELINE_PHP" --project="$PROJECT_ROOT" --backend=ffi --pairs=9 --output="$RESULT_JSON"`; repeat with `--backend=worker` | Standalone Rust harness, not wired to a workflow. Recover and freeze the compatible pre-Rust PHP class from history into an isolated baseline file. Build the candidate library/worker and record build identity first. Tests literal fingerprints and unique/duplicate/XML cases. Supported pair count is at most 100. | Add recurring isolated CI jobs for BOTH FFI and worker boundaries, including startup/serialization, store output JSON, source/runtime identities and invalid observations. Current harness cannot provide a large-sample p99 adoption gate. |
| CPU snapshot utility | `cargo +1.98.0 run --release --locked --manifest-path docker/app/nzb-yenc-ffi/Cargo.toml --bin benchmark-cpu-snapshot -- --baseline="$BASELINE_PYTHON" --project="$PROJECT_ROOT" --pairs=1000 --output="$NEW_OUTPUT"` | Standalone Rust/Python CLI comparison, not wired to a workflow. Recover the original `scripts/benchmark-cpu.py` from an ancestor before replacement; preserve its hash. Build `nntmux-cpu-snapshot` first. Synthetic Linux proc/cgroup fixtures, not production collector load. | Wire recurring CI, expose median/p99 and invalids, retain baseline source/runtime/binary identities. Do not confuse collecting CPU counters with benchmarking this collector. |

`yenc-performance.yml` manual inputs: `comparison=[all,php,cpp,profile,processing]`, `samples`, `seed`, `baseline_ref`, `candidate_ref`, `caller_mode=[screen,regression]`, `combined_qualification`. `all`/`processing` source comparisons require a feature branch as described above; the publisher must not present feature-branch measurements as current master. For plain master timing jobs, preserve the workflow's safe default paths.

Relevant checks before publishing changes to these harnesses:

```bash
php vendor/bin/phpunit --filter='YencBenchmarkTest|YencProfileTest'
php -d ffi.enable=true vendor/bin/phpunit --fail-on-skipped tests/Unit/ArticleProcessingBenchmarkTest.php
python3 tests/Unit/test_yenc_abi_benchmark.py
python3 tests/Unit/test_yenc_performance_report.py
shellcheck scripts/profile-yenc.sh github-pages/deployment/prepare-benchmarks.sh
actionlint .github/workflows/yenc-performance.yml .github/workflows/release-presentation.yml
```

Article-processing checks need `NNTMUX_TEST_NATIVE_LIBRARY` pointing to the built library. Release-presentation native/view tests and its web fixture need the workflow-specific preload/extension setup. PHP edits additionally require Pint, scoped PHPStan and PHP syntax lint. Inspect the actual workflow steps rather than treating this list as a substitute for their setup.

## TODO: complete module coverage

These are coverage states, not speed claims. Discover Rust modules from `src/lib.rs` and fail the dashboard's completeness audit when a new module lacks an explicit row. A module appearing indirectly inside a profile does not establish an independent comparative benchmark.

| Module family | Existing coverage | Required work before calling performance coverage complete |
| --- | --- | --- |
| `payload_decoder` and x86/ARM SIMD, `yenc_framing` | Decoder C++/PHP comparisons; real caller and profile paths | Maintain architecture/kernel qualification and independently verify each advertised path. Add representative uncommon cases and reliable p99. |
| `nntp_body`, `nntp_headers`, framing, full analyzer integration | Caller profiles / optional frozen-source comparisons | Publish phase and whole-caller costs; define stable per-module common/uncommon comparison fixtures. |
| `release_names` | Separate native/PHP rendering CI | Integrate its workflow summaries and runtime evidence. |
| `article_fingerprint` | Standalone paired harness | Wire both backends and extend uncommon-tail coverage. |
| `cpu_snapshot` | Standalone CLI paired harness; correctness tests | Wire benchmark execution separately from collection; expose p99 protocol limits. |
| `article_dates`, `article_identity`, `article_xref` | Correctness/parity or indirect caller coverage | Define explicit native-baseline comparisons and representative unusual headers/date/Xref inputs. |
| `formats`, including RAR5 Quick Open / copy-entry checksums / archive metadata | Rust/unit and PHP parity tests; one RAR5 profile fixture | Add isolated and caller-level comparisons per archive format and metadata path; preserve malformed/bounded-input semantics. A fixed RAR5 profile is not all-format performance coverage. |
| `par2`, `par2_source_ranges` | Correctness/parity only identified | Add real caller baselines, valid/corrupt/large/uncommon recovery fixtures and ownership/correctness checks. |
| `seven_zip_ranges` | Correctness/parity only identified | Add 7z-specific common/uncommon comparisons and caller overhead. |
| `sample_plan` | Correctness/parity only identified | Add representative common/uncommon planning workloads, output/order equivalence and real caller cost. |
| Evidence utility and CLI binaries | Correctness checks / used by workflows | Explicitly classify auxiliary tooling and report whether it has its own comparison; binary existence is not benchmark coverage. |
| Database/ingestion storage and other application modules | Separate historical studies, not general module CI | Review `docs/ingestion-benchmark-procedure.md`, `docs/ingestion-candidate-benchmark-protocol.md`, `docs/hot-workload-validation.md` and `docs/performance/evidence/`. Historical scratch harnesses are not supported repo commands. Design isolated current fixtures before recurring execution. |

## Acceptance gates

Correctness/parity precedes timing. Adopt new Rust replacements only with reproducible **at least 1.5×** speedup on every required common path and representative uncommon **p99** workload, including caller/FFI/serialization/allocation/startup/I/O overhead; aim for 2–3×. A 5% median useful-effect screen or 31-pair diagnostic cannot satisfy that gate. Report sample counts and uncertainty; do not infer p99 from inadequate samples. Missing, invalid or inconclusive results do not qualify.

Never benchmark against production credentials, provider endpoints, live database/bind mounts or cached configuration. Concurrency/capacity runs need a separate host and uniquely owned disposable runtime. Do not trigger production jobs, tune NNTP/concurrency, deploy, restart, or change dependencies as part of Pages publication. Follow `.ai/rules/rust-performance.md` and `.ai/skills/performance-validation/SKILL.md` when implementing each remaining benchmark.
