<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WeakReference;

/** Keep only leases and sample IDs between reads; never retain a batch of full manifests. */
final class CandidateSampleBatch
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w17_nzb_xml_verification_timing(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Paired common/uncommon mean and p99 must all pass before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Reject objects before serialization can invoke user code; bound cycles. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::$useRustFfi) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w17_nzb_xml_verification_timing($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    public static function pendingCount(int $count): bool
    {
        return self::pendingCountNative($count) ?? self::pendingCountPhp($count);
    }

    public static function pendingCountPhp(int $count): bool
    {
        return $count > 0;
    }

    public static function pendingCountNative(int $count): ?bool
    {
        $result = self::computeNative(6, [$count]);

        return is_bool($result) ? $result : null;
    }

    public static function claimsLimit(int $admitted, int $maximumClaims): bool
    {
        return self::claimsLimitNative($admitted, $maximumClaims) ?? self::claimsLimitPhp($admitted, $maximumClaims);
    }

    public static function claimsLimitPhp(int $admitted, int $maximumClaims): bool
    {
        return $admitted >= $maximumClaims;
    }

    public static function claimsLimitNative(int $admitted, int $maximumClaims): ?bool
    {
        $result = self::computeNative(7, [$admitted, $maximumClaims]);

        return is_bool($result) ? $result : null;
    }

    public static function verifiedRate(int $bytes, float $now, float $startedAt): float
    {
        return self::verifiedRateNative($bytes, $now, $startedAt) ?? self::verifiedRatePhp($bytes, $now, $startedAt);
    }

    public static function verifiedRatePhp(int $bytes, float $now, float $startedAt): float
    {
        return $bytes / max(0.001, $now - $startedAt);
    }

    public static function verifiedRateNative(int $bytes, float $now, float $startedAt): ?float
    {
        $result = self::computeNative(8, [$bytes, $now, $startedAt]);

        return is_float($result) ? $result : null;
    }

    public static function claimBudget(int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): int
    {
        return self::claimBudgetNative($maximumClaims, $admitted, $configured, $sourceBatchSize) ?? self::claimBudgetPhp($maximumClaims, $admitted, $configured, $sourceBatchSize);
    }

    public static function claimBudgetPhp(int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): int
    {
        return (int) max(0, min($maximumClaims - $admitted, max(1, min(16, $configured)), max(1, $sourceBatchSize)));
    }

    public static function claimBudgetNative(int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): ?int
    {
        $result = self::computeNative(9, [$maximumClaims, $admitted, $configured, $sourceBatchSize]);

        return is_int($result) ? $result : null;
    }

    /** @var array<int, object> */
    private array $pending = [];

    private int $admitted = 0;

    private ?float $prefetchStartedAt = null;

    private int $verifiedBytes = 0;

    private readonly LeaseHeartbeat $pendingHeartbeat;

    /** @param array<string, mixed> $options */
    public function __construct(private readonly CandidateRepository $repository, private readonly ManifestStore $store,
        private readonly VerificationWorkerLocks $locks, private readonly NntpArticleSource $source,
        private readonly array $options, private readonly ?VerificationRunDiagnostics $diagnostics = null,
        private readonly ?Closure $clock = null)
    {
        $owner = WeakReference::create($this);
        $this->pendingHeartbeat = new LeaseHeartbeat(static function () use ($owner): void {
            $owner->get()?->heartbeat();
        }, max(0.1, min(30, (int) ($this->options['lease_seconds'] ?? 300) / 3)), $this->clock);
    }

    public function hasPending(): bool
    {
        return self::pendingCount(count($this->pending));
    }

    public function limitReached(int $maximumClaims): bool
    {
        return self::claimsLimit($this->admitted, $maximumClaims);
    }

    /** Report completed verified bytes over the whole shared download/verification interval. */
    public function verifiedBytesPerSecond(int $bytes, float $candidateStartedAt): float
    {
        $now = $this->clock === null ? microtime(true) : ($this->clock)();
        if ($this->prefetchStartedAt === null) {
            return self::verifiedRate($bytes, $now, $candidateStartedAt);
        }
        $this->verifiedBytes += $bytes;

        return self::verifiedRate($this->verifiedBytes, $now, $this->prefetchStartedAt);
    }

    /** Per-file bookkeeping may force the active lease while queued renewals remain coalesced. */
    public function pulse(): void
    {
        if ($this->pending !== []) {
            ($this->pendingHeartbeat)();
        }
    }

    /** Return only work that has not been handed to the per-candidate verifier. */
    public function releasePending(): void
    {
        $error = null;
        foreach ($this->pending as $id => $claim) {
            $failure = $this->returnQueued($id, $claim);
            $error ??= $failure;
        }
        $this->source->clearPrefetched();
        if ($error !== null) {
            throw $error;
        }
    }

    private function returnQueued(int $id, object $claim): ?Throwable
    {
        try {
            if ($this->repository->returnQueuedClaim($claim)) {
                $this->diagnostics?->queuedReturned();
            } else {
                $this->diagnostics?->queuedLost();
            }

            return null;
        } catch (Throwable $failure) {
            $this->diagnostics?->queuedReturnFailed();

            return $failure;
        } finally {
            unset($this->pending[$id]);
            $this->locks->releaseCandidate($id);
        }
    }

    /** Renew queued work as well as the candidate currently being verified by the caller. */
    public function heartbeat(): void
    {
        if (! $this->locks->held()) {
            throw new RuntimeException('Verification worker locks were lost.');
        }
        foreach ($this->pending as $id => $claim) {
            try {
                if (! $this->locks->protects($id)) {
                    throw new LeaseLostException('Verification candidate lock was lost.');
                }
                $this->repository->heartbeat($id, $claim->lease_token);
            } catch (LeaseLostException) {
                $this->diagnostics?->queuedLost();
                unset($this->pending[$id]);
                $this->locks->releaseCandidate($id);
            } catch (Throwable $failure) {
                // A failed statement may have rolled back a publisher's transaction. Never
                // continue that transaction; outside it, only the affected queued claim is returned.
                if (DB::transactionLevel() > 0) {
                    throw $failure;
                }
                $this->returnQueued($id, $claim);
                try {
                    Log::warning('Queued verification lease renewal failed; queued work released for retry.', ['error_type' => $failure::class]);
                } catch (Throwable) {
                    // Diagnostic failure must not turn a queued renewal into active-candidate failure.
                }
            }
        }
    }

    /** @param callable(): bool $mayClaim */
    public function next(int $maximumClaims, callable $mayClaim): ?object
    {
        $this->heartbeat();
        if ($this->pending === []) {
            if ($this->limitReached($maximumClaims)) {
                return null;
            }
            $this->source->clearPrefetched();
            $this->prefetchStartedAt = null;
            $this->verifiedBytes = 0;
            $limit = self::claimBudget($maximumClaims, $this->admitted, (int) ($this->options['sample_batch_size'] ?? 1), $this->source->batchSize());
            $ids = [];
            for ($index = 0; $index < $limit && $mayClaim(); $index++) {
                $this->heartbeat();
                $preferReady = $this->locks->preferReady();
                $phase = $this->diagnostics === null ? null : hrtime(true);
                $this->diagnostics?->claimCalled();
                $acquired = [];
                $claim = null;
                try {
                    $claim = $this->repository->claim(function (int $id) use (&$acquired): bool {
                        if (! $this->locks->tryCandidate($id)) {
                            return false;
                        }
                        if (! isset($this->pending[$id])) {
                            $acquired[] = $id;
                        }

                        return true;
                    }, $preferReady);
                } finally {
                    foreach ($acquired as $id) {
                        if ($claim === null || $id !== (int) $claim->id) {
                            $this->locks->releaseCandidate($id);
                        }
                    }
                    $this->diagnostics?->phase('claim', $phase);
                }
                if ($claim === null) {
                    break;
                }
                $this->admitted++;
                $this->diagnostics?->claimed();
                $this->pending[(int) $claim->id] = $claim;
            }
            // A one-candidate run keeps the existing fetch/error behavior and avoids a second read.
            if (count($this->pending) > 1) {
                $prefetchStarted = $this->diagnostics === null ? null : hrtime(true);
                foreach ($this->pending as $claim) {
                    $this->heartbeat();
                    if (! isset($this->pending[(int) $claim->id])) {
                        continue;
                    }
                    try {
                        $manifest = $this->store->read($claim->manifest_path);
                        $assessment = (new CandidateHeaderInventory)->assessment($manifest);
                        if ($assessment['reason'] === null || ($assessment['status'] === 'waiting_upload' && $this->repository->partialAllowed($claim))) {
                            $sample = (new SpotCheckVerifier($this->source, $this->options))->sample($manifest);
                            if ($sample !== []) {
                                // Only the first article is safe before its actual raw byte count is known.
                                // Subsequent articles use the verifier's decreasing remaining allowance.
                                $ids[] = $sample[0]['messageid'];
                            }
                        }
                    } catch (Throwable) {
                        // The ordinary per-candidate path owns reporting and retrying a bad manifest.
                    } finally {
                        unset($manifest);
                    }
                }
                if ($ids !== []) {
                    $this->prefetchStartedAt = $this->clock === null ? microtime(true) : ($this->clock)();
                    // Ownership and preflight failures abort the batch. Transport failures are
                    // returned as correlated outcomes so they cannot trigger an unfenced retry.
                    $this->source->prefetch(array_values(array_unique($ids)), new LeaseHeartbeat($this->heartbeat(...), 1.0));
                }
                $this->diagnostics?->phase('prefetch', $prefetchStarted);
            }
            $this->heartbeat();
        }
        if ($this->pending === []) {
            return null;
        }
        $id = array_key_first($this->pending);
        $claim = $this->pending[$id];
        unset($this->pending[$id]);

        return $claim;
    }

    /** Wave 49.2 paired benchmarks decide this default; see RustVerificationAdmissionInventoryPrepRepoSampleW49ParityTest. */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        intptr_t nntmux_w49_candidate_sample_batch(const char *claims, size_t claims_len, const char *messages, size_t messages_len, size_t count, int64_t maximum_claims, int64_t admitted, int64_t configured, int64_t source_batch_size, int64_t *out, size_t out_cap);
        C;

    private static ?bool $w49RustFfi = null;

    private static FFI|false|null $w49Ffi = null;

    private static ?CData $w49Out = null;

    private static int $w49OutCap = 0;

    public static function usesW49RustFfi(): bool
    {
        return self::$w49RustFfi ?? self::W49_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planPrefetchBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w49RustFfi;
        self::$w49RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$w49RustFfi = $previous;
        }
    }

    /**
     * Batch prefetch plan for queued claims. The budget is claimBudgetPhp() over inputs clamped
     * to 0..2^31. One word per claim: `flags | trimmedLength << 8 | first index << 32`, where the
     * message ID is trimmed of `<>`, flags are 1 inside the budget, 2 claim id repeats an earlier
     * one, 4 claim id < 1, 8 empty message ID, 16 message ID repeats an earlier one, 32 prefetched
     * (1 alone), and first index is the earliest claim with the same message ID (its own index
     * when empty; length clamped to 2^24 - 1, index to 2^31 - 1). Then the budget, prefetch count,
     * whether the claim limit is reached, whether any claim is queued and the XXH64 digest of
     * all earlier words.
     *
     * @param  list<int>  $claimIds
     * @param  list<string>  $messageIds
     * @return list<int>
     */
    public static function planPrefetchBatch(array $claimIds, array $messageIds, int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): array
    {
        return self::usesW49RustFfi()
            ? self::planPrefetchBatchNative($claimIds, $messageIds, $maximumClaims, $admitted, $configured, $sourceBatchSize)
            : self::planPrefetchBatchPhp($claimIds, $messageIds, $maximumClaims, $admitted, $configured, $sourceBatchSize);
    }

    /**
     * @param  list<int>  $claimIds
     * @param  list<string>  $messageIds
     * @return list<int>
     */
    public static function planPrefetchBatchPhp(array $claimIds, array $messageIds, int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): array
    {
        $maximum = max(0, min(1 << 31, $maximumClaims));
        $taken = max(0, min(1 << 31, $admitted));
        $budget = self::claimBudgetPhp($maximum, $taken, max(0, min(1 << 31, $configured)), max(0, min(1 << 31, $sourceBatchSize)));
        $out = $claims = $first = [];
        $prefetch = 0;
        foreach ($claimIds as $i => $id) {
            $key = trim($messageIds[$i] ?? '', '<>');
            $flags = (int) ($i < $budget) | (int) isset($claims[$id]) << 1 | (int) ($id < 1) << 2 | (int) ($key === '') << 3;
            $claims[$id] = true;
            if ($key === '') {
                $index = $i;
            } elseif (isset($first[$key])) {
                $flags |= 16;
                $index = $first[$key];
            } else {
                $first[$key] = $index = $i;
            }
            if ($flags === 1) {
                $flags |= 32;
                $prefetch++;
            }
            $out[] = $flags | min(\strlen($key), 0xFFFFFF) << 8 | min($index, 0x7FFFFFFF) << 32;
        }
        $out[] = $budget;
        $out[] = $prefetch;
        $out[] = (int) ($taken >= $maximum);
        $out[] = (int) ($claimIds !== []);

        return self::w49Digest($out);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or a
     * message ID holds a NUL byte.
     *
     * @param  list<int>  $claimIds
     * @param  list<string>  $messageIds
     * @return list<int>
     */
    public static function planPrefetchBatchNative(array $claimIds, array $messageIds, int $maximumClaims, int $admitted, int $configured, int $sourceBatchSize): array
    {
        $count = \count($claimIds);
        $ffi = $count === 0 ? null : (self::$w49Ffi instanceof FFI ? self::$w49Ffi : self::w49Ffi());
        if ($ffi === null || \count($messageIds) !== $count) {
            return self::planPrefetchBatchPhp($claimIds, $messageIds, $maximumClaims, $admitted, $configured, $sourceBatchSize);
        }
        $joined = implode("\0", $messageIds);
        $words = $count + 5;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w49Out($ffi, $words);
            $ints = pack('P*', ...$claimIds);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_candidate_sample_batch($ints, \strlen($ints), $joined, \strlen($joined), $count, $maximumClaims, $admitted, $configured, $sourceBatchSize, $out, self::$w49OutCap);
            $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planPrefetchBatchPhp($claimIds, $messageIds, $maximumClaims, $admitted, $configured, $sourceBatchSize);
    }

    private static function w49Ffi(): ?FFI
    {
        self::$w49Ffi ??= RustPipelineLibrary::resolveDomain('nfo_w49', self::W49_FFI_CDEF) ?? false;

        return self::$w49Ffi === false ? null : self::$w49Ffi;
    }

    /** A reusable output buffer of at least $words `int64_t` words; FFI::new() throws on failure. */
    private static function w49Out(FFI $ffi, int $words): CData
    {
        if (self::$w49Out === null || self::$w49OutCap < $words) {
            self::$w49Out = $ffi->new('int64_t['.max(1024, $words).']');
            self::$w49OutCap = max(1024, $words);
        }

        return self::$w49Out;
    }

    /**
     * Appends the XXH64 digest of the words.
     *
     * @param  list<int>  $words
     * @return list<int>
     */
    private static function w49Digest(array $words): array
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$words), true));
        $words[] = $digest[1];

        return $words;
    }

    // ========================================================================
    // WAVE 63.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.38x common mean, 2.29x common p99, 1.64x uncommon mean and 1.75x uncommon p99; see RustVerificationAdmissionInventoryPrepRepoSampleBatchW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_FFI_CDEF = 'ssize_t nntmux_w634_candidate_sample_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w63SampleBatchPlanBatch() record. */
    public const int W63_ROW_WORDS = 5;

    /** int64 summary words after the w63SampleBatchPlanBatch() records (none for an empty batch). */
    public const int W63_TRAILER_WORDS = 7;

    /** Bytes a trimmed message ID may not hold: \s, controls, DEL and angle brackets. */
    private const string W63_INVALID_ID = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x20\x7f<>";

    private const string W63_GROUP_BYTES = 'abcdefghijklmnopqrstuvwxyz0123456789.+_-';

    private const int W63_SAMPLES_PER_CLAIM = 4;

    private const int W63_BYTES_MAX = 1 << 36;

    /**
     * Article sample selection plan rows for [group, message ID, claim id, part number, declared
     * parts, bytes] items in claim order.
     *
     * Groups are trimmed and lowercased (class 0 empty, 1 `[a-z0-9.+_-]` with an inner dot, 2
     * other); message IDs are trimmed of `<>`. Flags: 1 selected, 2 invalid message ID, 4 message
     * ID repeats an earlier row, 8 empty message ID, 16 claim id < 1, 32 bytes < 1, 64 number
     * outside 1..declared parts (or declared parts above 2^31 - 1), 128 the claim already has 4
     * selected articles. A row is selected when no other flag is set. Position: 0 out of range, 1
     * first part, 2 last part, 3 middle. Coverage is number * 1000 / declared parts (-1 when out
     * of range) and the first index is the earliest row with the same message ID (its own index
     * when empty).
     *
     * Rows [flags | group class << 8 | position << 10 | trimmed length << 12 (clamped to 2^24 -
     * 1) | the claim's selected count << 36 (clamped to 65535), fnv1a32(trimmed ID) (0),
     * fnv1a32(group) (0), first index, coverage], then the trailer [selected, duplicate IDs,
     * defective rows, distinct groups, distinct claims, selected bytes (each clamped to 2^36),
     * FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w63SampleBatchPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63SampleBatchPlanBatchNative($items) : self::w63SampleBatchPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w63SampleBatchPlanBatchPhp(array $items): array
    {
        self::w63Items($items);
        if ($items === []) {
            return [];
        }
        $out = $first = $perClaim = $groups = [];
        $selected = $duplicates = $invalid = $bytesTotal = 0;
        $digest = 2_166_136_261;
        foreach ($items as $i => [$groupName, $messageId, $claim, $number, $total, $bytes]) {
            $group = strtolower(trim($groupName));
            $key = trim($messageId, '<>');
            $length = \strlen($key);
            if ($length === 0) {
                $flags = 8;
                $index = $i;
            } else {
                $flags = strcspn($key, self::W63_INVALID_ID) === $length ? 0 : 2;
                if (isset($first[$key])) {
                    $flags |= 4;
                    $duplicates++;
                    $index = $first[$key];
                } else {
                    $first[$key] = $index = $i;
                }
            }
            if ($claim < 1) {
                $flags |= 16;
            }
            if ($bytes < 1) {
                $flags |= 32;
            }
            $inRange = $number >= 1 && $number <= $total && $total <= 0x7FFFFFFF;
            if (! $inRange) {
                $flags |= 64;
            }
            $taken = $perClaim[$claim] ?? 0;
            if ($flags === 0) {
                if ($taken < self::W63_SAMPLES_PER_CLAIM) {
                    $flags = 1;
                    $taken++;
                    $selected++;
                    $bytesTotal += min($bytes, self::W63_BYTES_MAX);
                } else {
                    $flags = 128;
                }
            }
            $perClaim[$claim] = $taken;
            if (($flags & 0x7A) !== 0) {
                $invalid++;
            }
            $position = ! $inRange ? 0 : ($number === 1 ? 1 : ($number === $total ? 2 : 3));
            $coverage = $inRange ? intdiv($number * 1000, $total) : -1;
            $groupLength = \strlen($group);
            if ($groupLength === 0) {
                $groupClass = $groupHash = 0;
            } else {
                $groups[$group] = true;
                $groupHash = self::w63Fnv($group);
                $groupClass = strspn($group, self::W63_GROUP_BYTES) === $groupLength && str_contains($group, '.') && $group[0] !== '.'
                    && $group[$groupLength - 1] !== '.' ? 1 : 2;
            }
            $idHash = $length === 0 ? 0 : self::w63Fnv($key);
            $word = $flags | $groupClass << 8 | $position << 10 | min($length, 0xFFFFFF) << 12 | min($taken, 0xFFFF) << 36;
            array_push($out, $word, $idHash, $groupHash, $index, $coverage);
            $value = $word ^ $idHash << 16 ^ $groupHash << 24 ^ $coverage;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }

        return [...$out, $selected, $duplicates, $invalid, \count($groups), \count($perClaim), $bytesTotal, $digest];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: int}>  $items
     * @return list<int>
     */
    public static function w63SampleBatchPlanBatchNative(array $items): array
    {
        self::w63Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w63Join([array_column($items, 0), array_column($items, 1)], $count);
        $ints = pack('P*', ...array_column($items, 2), ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5));

        return self::w63Call($blob, $twin, $ints, $count, $count * self::W63_ROW_WORDS + self::W63_TRAILER_WORDS)
            ?? self::w63SampleBatchPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w63Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W63 candidate sample batch plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 6 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_int($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5])) {
                throw new InvalidArgumentException('W63 candidate sample batch plan batch items must be [string group, string message id, int claim id, int number, int declared parts, int bytes] lists.');
            }
        }
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', false);
    }

    /** Drop the W63 override, binding, output buffer and declined-call count; routing returns to W63_RUST_FFI_DEFAULT. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Run $callback with the W63 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('enabled');
        self::w63RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w63Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w63Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w63RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w63', self::W63_FFI_CDEF) ?? false;
            self::w63RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w63RustFfiState('out', $out);
                self::w63RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W63_FFI_CDEF.)
            $written = $ffi->nntmux_w634_candidate_sample_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w63RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W63 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int. */
    private static function w63Fnv(string $bytes): int
    {
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
