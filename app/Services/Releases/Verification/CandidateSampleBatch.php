<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
}
