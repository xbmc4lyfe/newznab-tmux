# Fused yEnc Decode Delivery Backlog

This is an ordered, five-PR delivery plan for reducing CPU and allocation
cost in the indexer's NNTP yEnc body path. Each item is independently
specified, reviewed, merged, and then used as the base for the next item.

## 1. Add the bounded native whole-body decoder ABI

Define and implement a Rust C ABI that accepts one already dot-unstuffed NNTP
BODY response, decodes only a complete valid yEnc frame, and writes no more
than the input length. The ABI must have explicit result codes and no partial
success result. It must not alter PHP call sites yet.

**Exit gate:** Rust unit tests demonstrate byte-exact decoding for ordinary,
escape-heavy, split-escape, false-control-prefix, multipart, plain-body, and
malformed-input fixtures. The ABI has no PHP caller in this item.

## 2. Integrate the ABI into lenient BODY decoding with safe fallback

Wire `YencService::decodeIgnore()` to the new ABI only behind a disabled
qualification switch in the existing Linux CLI native decoder. Preserve the
existing PHP path for normal operation, server SAPIs, unavailable FFI,
unframed input, malformed frames, and every uncertain ABI result. Do not move
strict decoding or metadata extraction in this item.

**Exit gate:** PHP tests prove the native and PHP results match and that all
fallback conditions retain the current observable behavior.

## 3. Preserve strict decoding and metadata behavior explicitly

Keep `decode()`, `decodeWithFrame()`, frame metadata, multipart validation,
CRC validation, and tolerant dangling-escape recovery on their existing PHP
semantics while the lenient fast path is introduced. Add differential fixtures
that pin the boundary between native success and PHP fallback.

**Exit gate:** the strict and metadata suites, including malformed and
ambiguous framing, pass with both decoder selections; no native path changes a
strict error, checksum result, or extracted metadata.

## 4. Build the reproducible parity and performance harness

Extend the existing yEnc benchmark so it retains raw samples, records the
exact source/runtime configuration, randomizes comparison order, and emits
complete PHP/current-native/fused-native full-body results. It must retain
slower and noisy rows rather than filtering them.

**Exit gate:** the harness rejects an incorrect decode, produces replayable
artifacts for 4 KiB through 8 MiB ordinary, escape-heavy, and representative
multipart BODY fixtures, and reports wall time, PHP peak memory, Linux peak
RSS, and output correctness. Multipart measurements include the complete
native attempt and PHP fallback, not just the native status call.

**Predeclared qualification protocol:** Item 5 runs three independent
fresh-process repetitions. Each repetition uses three warmups and eleven
measured observations per variant and fixture, pairing PHP, current-native,
and fused-native on identical inputs in a recorded randomized order. It retains
all rows, uses the same pinned cloud-indexer-equivalent runtime and resource
limits, and records bootstrap source, fixture hashes, commits, image digests,
tool versions, and the random seed. Freeze a 30-second deadline for each
warmup or measured sample and a 15-minute deadline for each variant process.
Timeouts, crashes, missing samples, or incorrect decodes invalidate the paired
observation and the qualification run; retain their artifacts and causes and
terminate and reap owned child processes. Do not qualify on surviving rows.

For each fixture and repetition, compute the median as the sixth sorted value
of eleven observations and p95 by nearest rank (`ceil(0.95 * 11)`, the maximum).
Do not pool repetitions. Compute speedup as current-native median divided by
fused-native median: each 1 MiB and 8 MiB escape-heavy full-body fixture must
achieve at least 3x in at least two of the three repetitions. For every fixture,
including those speedup fixtures and multipart fallback bodies, fused-native
p95 wall time, PHP peak memory, and Linux peak RSS must each be at most 110%
of **each** baseline (PHP and current-native) in every repetition. Memory and
RSS comparisons use each variant process's maximum for that fixture; collect
them in fresh processes so another fixture cannot inflate the baseline peak.
Every decode must be correct. A 5x or 20x result is reported when measured,
not assumed. Any failed criterion keeps the existing decoder selection and
the qualification switch disabled.

## 5. Qualify, integrate, and deploy only an evidence-backed improvement

Run the approved benchmark matrix on the cloud-indexer-equivalent CLI runtime,
review the immutable implementation and measurement artifacts, and enable the
previously disabled fused complete-body caller only if it meets the
predeclared qualification protocol without a correctness regression.
Deployment remains a separate operation owned by the designated deployer.

**Exit gate:** the selected implementation has a reviewed, reproducible,
complete-caller result. It must not claim a decoder-only result as an
application gain, and all parity, focused, and full-suite checks are green.
This performance/integration gate is separate from each earlier item's
source-only PR review and merge gate.
