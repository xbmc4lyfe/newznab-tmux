<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use WeakReference;

/** Keep only leases and sample IDs between reads; never retain a batch of full manifests. */
final class CandidateSampleBatch
{
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
        }, max(0.1, min(30, (int) ($this->options['lease_seconds'] ?? 300) / 3)));
    }

    public function hasPending(): bool
    {
        return $this->pending !== [];
    }

    public function limitReached(int $maximumClaims): bool
    {
        return $this->admitted >= $maximumClaims;
    }

    /** Report completed verified bytes over the whole shared download/verification interval. */
    public function verifiedBytesPerSecond(int $bytes, float $candidateStartedAt): float
    {
        $now = $this->clock === null ? microtime(true) : ($this->clock)();
        if ($this->prefetchStartedAt === null) {
            return $bytes / max(0.001, $now - $candidateStartedAt);
        }
        $this->verifiedBytes += $bytes;

        return $this->verifiedBytes / max(0.001, $now - $this->prefetchStartedAt);
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
            try {
                $this->repository->returnQueuedClaim($claim);
            } catch (Throwable $failure) {
                $error ??= $failure;
            } finally {
                unset($this->pending[$id]);
                $this->locks->releaseCandidate($id);
            }
        }
        $this->source->clearPrefetched();
        if ($error !== null) {
            throw $error;
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
                unset($this->pending[$id]);
                $this->locks->releaseCandidate($id);
            }
        }
    }

    /** @param callable(): bool $mayClaim */
    public function next(int $maximumClaims, callable $mayClaim): ?object
    {
        $this->heartbeat();
        if ($this->pending === []) {
            $remaining = $maximumClaims - $this->admitted;
            if ($remaining < 1) {
                return null;
            }
            $this->source->clearPrefetched();
            $this->prefetchStartedAt = null;
            $this->verifiedBytes = 0;
            $limit = min(max(1, $remaining), max(1, min(16, (int) ($this->options['sample_batch_size'] ?? 1))),
                max(1, $this->source->batchSize()));
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
                    try {
                        $this->prefetchStartedAt = $this->clock === null ? microtime(true) : ($this->clock)();
                        $this->source->prefetch(array_values(array_unique($ids)), new LeaseHeartbeat($this->heartbeat(...), 1.0));
                    } catch (Throwable $error) {
                        // The transport closes interrupted pipelines before ordinary reads can retry.
                        Log::warning('Verification sample prefetch failed; candidates will retry individually.', ['error_type' => $error::class]);
                    }
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
