<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
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
}
