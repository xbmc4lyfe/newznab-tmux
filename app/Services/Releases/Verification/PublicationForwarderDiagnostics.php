<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Fixed-size facts about one publication forwarder run, without candidate metadata: how many
 * batches it sent, how large they were, what the primary answered for each item, and how long the
 * round trips took.
 */
final class PublicationForwarderDiagnostics
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_publication_forwarder_classify(const char *label, size_t label_len, uint32_t mode);
        ssize_t nntmux_publication_forwarder_metrics(int64_t batches, int64_t items, double linger_ms, double round_trip_ms, double slowest_round_trip_ms, int64_t started, int64_t finished, char *out, size_t out_cap);
        C;

    public const int SCHEMA_VERSION = 1;

    private const array STOP_REASONS = ['deadline', 'passes', 'terminated', 'locks_lost', 'worker_unavailable', 'disabled', 'slot_unavailable', 'error'];

    /** Outcome labels counted per item; anything else is "other". */
    private const array OUTCOMES = ['published', 'filtered', 'metadata_review', 'deferred', 'stale', 'invalid', 'error', 'paused', 'retry', 'transport', 'no_release'];

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?FFI\CData $scratch = null;

    private static int $scratchCap = 0;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && self::ffi() !== null;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function scratch(FFI $ffi, int $minBytes): ?FFI\CData
    {
        if (self::$scratch === null || self::$scratchCap < $minBytes) {
            self::$scratch = RustPipelineLibrary::obtainScratch($ffi, $minBytes, self::$scratchCap);
        }

        return self::$scratch;
    }

    private ?string $stopReason = null;

    private int $adopted = 0;

    private int $batches = 0;

    private int $items = 0;

    private int $smallestBatch = 0;

    private int $largestBatch = 0;

    private int $transportFailures = 0;

    private int $requeued = 0;

    private int $expired = 0;

    private int $leaseLost = 0;

    private int $lockBlocked = 0;

    private int $quarantined = 0;

    private ?int $itemLimit = null;

    private int $orphansAdopted = 0;

    private int $finishFailures = 0;

    private int $lingersStarted = 0;

    private int $lingersFilled = 0;

    private int $lingersTimedOut = 0;

    private int $lingersInterrupted = 0;

    private float $lingerMilliseconds = 0.0;

    private float $roundTripMilliseconds = 0.0;

    private float $slowestRoundTripMilliseconds = 0.0;

    /** @var array<string, int> */
    private array $outcomes = [];

    private string $commandResult = 'exception';

    public function __construct(private readonly int $started, private readonly string $startedAt, private readonly int $slot,
        private readonly int $maxSeconds) {}

    public function stop(string $reason): void
    {
        if (self::$useRustFfi && $this->stopNative($reason)) {
            return;
        }
        $this->stopPhp($reason);
    }

    public function stopPhp(string $reason): void
    {
        $this->stopReason ??= in_array($reason, self::STOP_REASONS, true)
            ? $reason : 'error';
    }

    public function stopNative(string $reason): bool
    {
        if ($this->stopReason !== null) {
            return true;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return false;
        }
        try {
            // @phpstan-ignore method.notFound
            $idx = $ffi->nntmux_publication_forwarder_classify($reason, strlen($reason), 0);
            if (! is_int($idx) || $idx < 0) {
                return false;
            }
            $this->stopReason = self::STOP_REASONS[$idx] ?? 'error';

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function returned(int $exitCode): void
    {
        $this->commandResult = $exitCode === 0 ? 'success' : 'failure';
    }

    public function failed(): void
    {
        $this->commandResult = 'exception';
        $this->stop('error');
    }

    public function adopted(int $count): void
    {
        $this->adopted += $count;
    }

    /** One request: $size items, and the time its round trip took ($threw when it produced no per-item answers). */
    public function batch(int $size, int $started, int $finished, bool $threw): void
    {
        $this->batches++;
        $this->items += $size;
        $this->smallestBatch = $this->batches === 1 ? $size : min($this->smallestBatch, $size);
        $this->largestBatch = max($this->largestBatch, $size);
        $milliseconds = ($finished - $started) / 1_000_000.0;
        $this->roundTripMilliseconds += $milliseconds;
        $this->slowestRoundTripMilliseconds = max($this->slowestRoundTripMilliseconds, $milliseconds);
        if ($threw) {
            $this->transportFailures++;
        }
    }

    public function outcome(string $status): void
    {
        if (self::$useRustFfi && $this->outcomeNative($status)) {
            return;
        }
        $this->outcomePhp($status);
    }

    public function outcomePhp(string $status): void
    {
        $label = in_array($status, self::OUTCOMES, true) ? $status : 'other';
        $this->outcomes[$label] = ($this->outcomes[$label] ?? 0) + 1;
    }

    public function outcomeNative(string $status): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return false;
        }
        try {
            // @phpstan-ignore method.notFound
            $idx = $ffi->nntmux_publication_forwarder_classify($status, strlen($status), 1);
            if (! is_int($idx) || $idx < 0) {
                return false;
            }
            $label = self::OUTCOMES[$idx] ?? 'other';
            $this->outcomes[$label] = ($this->outcomes[$label] ?? 0) + 1;

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function requeued(): void
    {
        $this->requeued++;
    }

    public function expired(): void
    {
        $this->expired++;
    }

    public function leaseLost(): void
    {
        $this->leaseLost++;
    }

    /** The worker group refused this forwarder (a drain, an exclusive holder or a pause); its entries wait. */
    public function blocked(): void
    {
        $this->lockBlocked++;
    }

    /** An entry could not be removed and was parked (or, failing that, left alone) instead. */
    public function quarantined(): void
    {
        $this->quarantined++;
    }

    /** The primary refused a batch as too large and advertised this item limit. */
    public function itemLimit(int $limit): void
    {
        $this->itemLimit = $limit;
    }

    /** Entries moved back to pending from the in-flight folder of a forwarder number nobody runs. */
    public function orphansAdopted(int $count): void
    {
        $this->orphansAdopted += $count;
    }

    /** The forwarder began waiting for a fuller batch. */
    public function lingerStarted(): void
    {
        $this->lingersStarted++;
    }

    /**
     * A wait ended after $milliseconds: by 'fill' (the batch limit was reached), 'timeout' (the linger
     * passed with a partial batch) or anything else, an interruption (stop, refusal or urgent work).
     */
    public function lingerEnded(string $how, float $milliseconds): void
    {
        match ($how) {
            'fill' => $this->lingersFilled++,
            'timeout' => $this->lingersTimedOut++,
            default => $this->lingersInterrupted++,
        };
        $this->lingerMilliseconds += max(0.0, $milliseconds);
    }

    public function finishFailed(): void
    {
        $this->finishFailures++;
    }

    /** @return array<string, mixed> */
    public function report(int $finished): array
    {
        return (self::$useRustFfi ? $this->reportNative($finished) : null)
            ?? $this->reportPhp($finished);
    }

    /** @return array<string, mixed> */
    public function reportPhp(int $finished): array
    {
        $outcomes = [];
        foreach ([...self::OUTCOMES, 'other'] as $label) {
            $outcomes[$label] = $this->outcomes[$label] ?? 0;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => 'publication_forwarder',
            'started_at' => $this->startedAt,
            'slot' => $this->slot,
            'max_seconds' => $this->maxSeconds,
            'stop_reason' => $this->stopReason,
            'command_result' => $this->commandResult,
            'adopted' => $this->adopted,
            'batches_sent' => $this->batches,
            'items_sent' => $this->items,
            'items_per_batch' => ['min' => $this->smallestBatch, 'max' => $this->largestBatch,
                'mean' => $this->batches === 0 ? 0.0 : round($this->items / $this->batches, 2)],
            'outcomes' => $outcomes,
            'transport_failures' => $this->transportFailures,
            'requeued' => $this->requeued,
            'expired' => $this->expired,
            'lease_lost' => $this->leaseLost,
            'lock_blocked' => $this->lockBlocked,
            'quarantined' => $this->quarantined,
            'advertised_item_limit' => $this->itemLimit,
            'orphans_adopted' => $this->orphansAdopted,
            'finish_failures' => $this->finishFailures,
            'linger' => ['waits_started' => $this->lingersStarted, 'ended_by_fill' => $this->lingersFilled,
                'ended_by_timeout' => $this->lingersTimedOut, 'interrupted' => $this->lingersInterrupted,
                'total_ms' => round($this->lingerMilliseconds, 3)],
            'round_trip_ms' => ['total' => round($this->roundTripMilliseconds, 3), 'max' => round($this->slowestRoundTripMilliseconds, 3),
                'mean' => $this->batches === 0 ? 0.0 : round($this->roundTripMilliseconds / $this->batches, 3)],
            'duration_ms' => round(($finished - $this->started) / 1_000_000.0, 3),
        ];
    }

    /** @return array<string, mixed>|null */
    public function reportNative(int $finished): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $scratch = self::scratch($ffi, 64);
            if ($scratch === null) {
                return null;
            }
            $cap = self::$scratchCap;
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_publication_forwarder_metrics(
                $this->batches,
                $this->items,
                $this->lingerMilliseconds,
                $this->roundTripMilliseconds,
                $this->slowestRoundTripMilliseconds,
                $this->started,
                $finished,
                $scratch,
                $cap
            );
            if ($written !== 48) {
                return null;
            }
            /** @var array<int, float>|false $vals */
            $vals = unpack('d6', FFI::string($scratch, 48));
            if ($vals === false) {
                return null;
            }
            $outcomes = [];
            foreach ([...self::OUTCOMES, 'other'] as $label) {
                $outcomes[$label] = $this->outcomes[$label] ?? 0;
            }

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'kind' => 'publication_forwarder',
                'started_at' => $this->startedAt,
                'slot' => $this->slot,
                'max_seconds' => $this->maxSeconds,
                'stop_reason' => $this->stopReason,
                'command_result' => $this->commandResult,
                'adopted' => $this->adopted,
                'batches_sent' => $this->batches,
                'items_sent' => $this->items,
                'items_per_batch' => [
                    'min' => $this->smallestBatch,
                    'max' => $this->largestBatch,
                    'mean' => $vals[1],
                ],
                'outcomes' => $outcomes,
                'transport_failures' => $this->transportFailures,
                'requeued' => $this->requeued,
                'expired' => $this->expired,
                'lease_lost' => $this->leaseLost,
                'lock_blocked' => $this->lockBlocked,
                'quarantined' => $this->quarantined,
                'advertised_item_limit' => $this->itemLimit,
                'orphans_adopted' => $this->orphansAdopted,
                'finish_failures' => $this->finishFailures,
                'linger' => [
                    'waits_started' => $this->lingersStarted,
                    'ended_by_fill' => $this->lingersFilled,
                    'ended_by_timeout' => $this->lingersTimedOut,
                    'interrupted' => $this->lingersInterrupted,
                    'total_ms' => $vals[2],
                ],
                'round_trip_ms' => [
                    'total' => $vals[3],
                    'max' => $vals[4],
                    'mean' => $vals[5],
                ],
                'duration_ms' => $vals[6],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param callable(array<string, mixed>): void $observer */
    public function observe(callable $observer): void
    {
        try {
            $observer($this->report(hrtime(true)));
        } catch (Throwable) {
            // Diagnostics cannot change processing outcomes or replace an original exception.
        }
    }
}
