# PHP-only attribution packet — SOURCE ONLY, UNVALIDATED

The locked Pint formatter has run on the private PHP copies under the coordinator-authorized static-check window. No planner/driver functional execution, test, native build, timing, CI, Git mutation or production command was executed. The coordinator reran locked Pint --test and focused PHPStan on byte-identical private worktree copies; both exited0 (static-checks.json, attempt2). These are static results only; no driver/planner or benchmark execution occurred. Files are private diagnostics; coordinator authorization and independent source review are required before execution. No Rust source/instrumentation/build is proposed. All timing arms reuse exactly one frozen native.so and vendor tree from sample-plan-current-x86-raw.zip.

## Identity and source preservation

Source commit: 0e62874c6a1090072de9fac50e50a6ec7f4c5bea.
Original wrapper SHA256: aa43ff2b697dbdd91fef8d8de104579181828772a72a5db1bf0468e5950d8f42.
Archive SHA256: 8011646596debf96645b1d18ede0afb0e66e098471ea3ec34fd77215a7005e8a.
The archive's wrapper bytes equal the worktree's original bytes; control.php contains exactly those bytes. instrumentation.patch is the explicit complete source diff. manifest.json pins all packet files except itself. native.so.sha256 pins the reused binary. Control requires the exact frozen root/app/Services/Releases/Verification/NativeSpotCheckPlanner.php directly and asserts its original hash; control.php is a review artifact only. No control autoload substitution occurs. Both arms require the frozen original class first, then locked vendor autoload. Only the instrumented arm additionally requires the unique private ProfiledNativeSpotCheckPlanner subclass; its original method bodies and private library/cache state remain independent of the frozen parent. Both use the archive's unchanged SpotCheckPlanner and vendor; no Laravel bootstrap/configuration/provider or database endpoints are loaded.

Original guards, short circuiting, manifest checks, projection order, JSON flags, FFI declarations, known input/expected probe, pointer null check, FFI string/free finally, decode, catches, semantic validation and fallback remain in original order. The deliberate class declaration changes to ProfiledNativeSpotCheckPlanner extends NativeSpotCheckPlanner; the original private constructor property, constants, library/cache and method flow remain in that private copy. The only original method signature/call-site change adds a private diagnostic role argument to call() (1 probe, default2 actual workload). Added try/finally wrappers close spans on original early returns/errors. Resource exhaustion, clock access and extra allocation can affect execution: semantic invariance remains unvalidated until authorized checks. No exception/error equivalence has been executed.

## Driver boundary and trace

The driver verifies the exact library hash before any caller execution. It times the complete unchanged SpotCheckPlanner::select, including the initial supportsInventory dispatch and PHP fallback. The same SampleProfileCounters trait supplies constructor/select counters to one anonymous class per base (original or Profiled), with the same single parent::select call and no additional adapter call. It follows the existing benchmark subclass attempt/success counters and one cold, one warmup, one warm call per fresh process. Small-dispatch never invokes native select; unsupported-coercion performs the original cold library qualification before projection rejects it and PHP fallback runs.

Cold uses scalar defaults phase0/publicOrdinal0. No observer accessor/reset, phase setter, cdef, native symbol call or preload occurs before that timed call. First native loading and known-input probe remain naturally inside library(). Warmup/warm scalar phase setters occur outside measured calls. Readout occurs immediately after each complete caller, in each arm's same loop location. Control returns null; instrumented copies a cumulative PHP trace. This accessor/cache/allocation asymmetry, retained snapshots (PHP copy-on-write on later appends), extra clock calls and finally blocks are retained interference. Do not subtract measured overhead or synthesize an adoption result.

Each event is exactly eight scalars: [phase,public_call_ordinal,call_invocation_ordinal,role,stage,start_ns,end_ns,closed]. closed1 means the span closed, NOT operation success. Error/fallback validity comes from exact result and execution counters. Roles0=not a call child,1=probe,2=workload. Phases0=cold,1=warmup,2=warm. Invocation ordinals increase at call() entry, before its original input bound check; an ABI stage is evidence the aggregate ABI call was attempted, not proof it returned. No Rust-level stage detail is available.

Trace is bounded128events,8invocations. Expected maximum for fixed three calls: successful native path16cold+11warmup+11warm=38events and4invocations (probe1/workload2cold/workload3warmup/workload4warm). Unsupported path10cold+5warmup+5warm=20events,1probe invocation; small-dispatch0events,0invocations. The unchanged caller explicitly invokes NativeSpotCheckPlanner::supportsInventory on the frozen parent: that outer dispatch support cost is retained in complete-caller residual and is not separately instrumented. Each small-dispatch snapshot is therefore a valid empty trace. Capacity overflow sets invalidity; evidence is never overwritten/reset. Scalar event storage allocates bounded PHP arrays, not zero allocation. This differs from a preallocated native trace and is explicit instrumentation interference.

Stages:
1 Profiled supportsInventory nested within native admission; original outer dispatch support remains in complete-caller residual
2 native select admission (includes nested support1)
3 entire native select
4 typed projection including post-library list/null admission
5 json_encode plus its original JsonException handling
6 library resolution/cache/cdef/known-input qualification INCLUSIVE
7 aggregate native ABI invocation including PHP argument evaluation/boundary/locked Rust/return (never label pure FFI)
8 FFI::string plus original guaranteed free
9 json_decode
10 private call() INCLUSIVE (input-size guard,7,8,9 and original catch)
11 FFI::cdef nested in6
12 semantic result validation

Accounting tree: full caller -> native select3 + outer original support/dispatch/possible fallback/unattributed residual; native select3 -> admission2, library6, projection4, encoding5, workload call10, validation12; admission2 -> inner support1; library6 -> cdef11 and probe call10; each call10 -> aggregate ABI7,string/free8,decode9. Compute exclusive parent durations only by subtracting immediate contained children. Never add inclusive parent and children as separate caller costs. Keep observer/allocator/dispatch/fallback/unattributed residual explicit. Probe semantic equality remains within library6 residual, separate from result validation12.

Freeze clock/hrtime unit and measured resolution outside the calls; parent protocol must set tolerance before running. Require every child interval contained in its actual enclosing span, sorted nonoverlapping immediate child intervals, and child sum <= parent+tolerance. Do not clip negatives. Missing/extra invocation, nonclosed span, phase/ordinal mismatch, trace overflow, fresh-process residue, unexpected fallback/success, result mismatch/crash/timeout/missing observation invalidates the pair; retain all artifacts.

## Workload, order and external validation

Use the six EXACT archived *.fixture.serialized files, each created with variant0 by the original benchmark. Repeat31fresh pairs per fixture, NOT31distinct fixture variants. order.json freezes seed20261016 randomized control/instrumented order. Both arms require fresh PHP processes per pair/arm; each process runs its own cold/warmup/warm sequence. Use the archived exact PHP reference bytes outside measurements; no extra reference process is needed. The optional php-reference arm remains available but is not part of this fixed diagnostic batch. Validate all three exact serialized outputs against frozen archived stratum-0-php.result.serialized (and fixture property checks); require native attempts/successes per stratum: small0/0; unsupported1/0; other four1/1. These are per public call; known probe has its own trace invocation and is not a subclass success.

The parent executor owns process groups, all stdout/stderr/exit codes and per-worker30s/batch600s limits. Timeout terminate the owned process group and prove group closure; record invalidity without replacing results. This packet supplies worker source and deterministic order, NOT an authorized running coordinator. Host/resource telemetry and process elapsed must be retained around each worker, as in original protocol. In-driver CPU/cgroup after samples include observer readout, serialization and property checks as in the original broad resource context; they are not exact timed-call resource costs.

## Required preflight, no rebuild

Before any PHP execution: bind archive/build-manifest.json and runtime.json, original API digest and source/compiler/default-feature flags to native.so.sha256. Record known and unknown native CPU requirements from the original receipt and nonloading ELF inspection; architecture labels alone cannot establish compatibility. The coordinator (thread01a12d8e-7f36-7fa3-81b8-f6f988ce9a02) explicitly permits unknown historical required ISA for this private, non-adopting attribution on an existing disposable GitHub-hosted actual x86 executor. Retain actual CPU flags, ELF notes/dependencies, and the approving protocol/receipt. Every load, SIGILL or ABI failure is invalid and retained; no resampling, substitution, rebuild, portability, adoption or p99 claim is allowed. Use actual disposable x86_64 Linux, inspect CPU flags, ELF machine/ABI, dynamic dependencies and exported original symbols with nonloading tools. Compare any known binary-required features and ABI versions with the actual host. Preserve unknown requirements explicitly under the approved diagnostic exception; never infer compatibility from a label or run an extra preload probe. The original known-input ABI probe remains naturally inside cold. Inspect exports nntmux_spot_check_sample_v1_json and nntmux_metadata_string_free; no diagnostic symbol exists. Use existing reviewed runtime and locked vendor; no installs, fleet provisioning or rebuilt native binary. Record PHP/OS/kernel/CPU/cgroups/locks/vendor+included-file hashes and source/API/runtime receipt identities for both arms. Preflight must not call FFI or reset/read diagnostics.

## Exact future command templates — NOT RUN

After coordinator grant and completed fail-closed preflight, on selected x86_64 Linux executor copy this directory and the ZIP into these same absolute paths. Extract the frozen archive once into this packet's private frozen/ directory using Python zipfile after verifying the pinned SHA and safe member paths. The frozen source root is then /tmp/sample-planner-php-profile/frozen/sample-planning/source and binary is frozen/sample-planning/native.so. This extraction is setup, outside observations. Do not use production config or a production checkout.

Review and syntax checks, only after compute grant:

    php -l /tmp/sample-planner-php-profile/NativeSpotCheckPlanner.instrumented.php
    php -l /tmp/sample-planner-php-profile/driver.php

Each scheduled worker command (replace STRATUM,PAIR,ARM literally from order.json; ARM is control or instrumented). Run through the coordinator's bounded process-group wrapper; freeze PHP ini to match original receipt, including opcache/JIT/memory and FFI setting, rather than silently adopting this illustrative FFI option:

    php -d ffi.enable=1 /tmp/sample-planner-php-profile/driver.php ARM /tmp/sample-planner-php-profile/frozen/sample-planning/source /tmp/sample-planner-php-profile/frozen/sample-planning/STRATUM.fixture.serialized /tmp/sample-planner-php-profile/frozen/sample-planning/native.so /tmp/sample-planner-php-profile/results/STRATUM-PAIR-ARM.json

The fixed batch uses archived references and does not schedule the optional php-reference arm. No timing from reference enters paired native control/instrumented attribution. Save stdout/stderr separately for every command, including failed setup and worker executions.

This diagnostic screen cannot qualify p99, replacement adoption or deployment. Any proposed optimization needs fresh uninstrumented complete-caller correctness and required common/p99 speed evidence on required platforms.

Source generation: generate.py regenerates the wrapper from the frozen original, deliberately renames the private subclass, invokes only the existing locked Pint on private instrumented/hand-maintained driver files, then regenerates the explicit diff and manifest. It cannot silently restore the previous same-name class. The driver is hand-maintained; source generation never rewrites it from the obsolete design.
