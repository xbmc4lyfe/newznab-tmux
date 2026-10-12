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
artifacts for 4 KiB through 8 MiB ordinary and escape-heavy fixtures, and
reports wall time, PHP peak memory, Linux peak RSS, and output correctness.

**Predeclared qualification protocol:** Item 5 runs three independent
fresh-process repetitions of randomized PHP/current-native/fused-native pairs,
after three warmups and with eleven measured pairs per variant. It retains all
rows, uses the same pinned cloud-indexer-equivalent runtime and resource limits,
and records bootstrap source, fixtures, commits, image digests, and tool
versions. A caller is eligible only if every decode is correct, each 1 MiB and
8 MiB escape-heavy full-body fixture has at least a 3x median wall-time
speedup in at least two of the three repetitions, and no other gated fixture
has more than a 10% p95 wall-time regression. A 5x or 20x result is reported
when measured, not assumed. Any failed criterion keeps the PHP selection.

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
