<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/** Keep only leases and sample IDs between reads; never retain a batch of full manifests. */
final class CandidateSampleBatch
{
    /** @var array<int, object> */
    private array $pending = [];

    /** @param array<string, mixed> $options */
    public function __construct(private readonly CandidateRepository $repository, private readonly ManifestStore $store,
        private readonly VerificationWorkerLocks $locks, private readonly NntpArticleSource $source,
        private readonly array $options, private readonly ?VerificationRunDiagnostics $diagnostics = null) {}

    public function hasPending(): bool
    {
        return $this->pending !== [];
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
    public function next(int $remaining, callable $mayClaim): ?object
    {
        $this->heartbeat();
        if ($this->pending === []) {
            $articles = max(1, min(16, (int) ($this->options['spot_check_articles'] ?? 1)));
            $limit = min(max(1, $remaining), max(1, min(16, (int) ($this->options['sample_batch_size'] ?? 1))),
                max(1, intdiv($this->source->batchSize(), $articles)));
            $ids = [];
            for ($index = 0; $index < $limit && $mayClaim(); $index++) {
                $this->heartbeat();
                $preferReady = $this->locks->preferReady();
                $phase = $this->diagnostics === null ? null : hrtime(true);
                $this->diagnostics?->claimCalled();
                try {
                    $claim = $this->repository->claim(fn (int $id): bool => $this->locks->tryCandidate($id), $preferReady);
                } finally {
                    $this->diagnostics?->phase('claim', $phase);
                }
                if ($claim === null) {
                    break;
                }
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
                            $ids = array_merge($ids, array_column((new SpotCheckVerifier($this->source, $this->options))->sample($manifest), 'messageid'));
                        }
                    } catch (Throwable) {
                        // The ordinary per-candidate path owns reporting and retrying a bad manifest.
                    } finally {
                        unset($manifest);
                    }
                }
                if ($ids !== []) {
                    try {
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
