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

    public const string W57_FFI_CDEF = 'int64_t nntmux_w575_forwarder_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W57.5 parity test's QUALIFICATION). */
    private const bool W57_RUST_FFI_DEFAULT = true;

    /**
     * Wave 57.5 routing and FFI cache, kept in function-local statics (readonly classes cannot declare static properties). Without $op it reports
     * the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[],
     * capacity]` with room for at least $val words, or null without the library.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w57_5', self::W57_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W57_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        return self::w57RustFfiState() === true;
    }

    /**
     * Runs $callback with forwarderPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 57.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w57RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w575_forwarder_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch rollup of comma-separated forwarder item results (trimmed, empty skipped). Five words per record:
     * `[items, success, retry, errors, health]`. Three-digit codes are HTTP statuses: 2xx success; 408, 429,
     * 503 and 504 retry; other 4xx and 5xx errors. Labels: `published`, `filtered`, `metadata_review`
     * success; `deferred`, `paused`, `retry`, `stale` retry; `error`, `transport`, `invalid` errors; anything
     * else only counts as an item. Health: 0 no items, 1 no errors and at most a quarter retried, 2 at most
     * a tenth errors, 3 failing.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function forwarderPlanBatch(array $records): array
    {
        return self::usesW57RustFfi() ? self::forwarderPlanBatchNative($records) : self::forwarderPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function forwarderPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $items = $success = $retry = $errors = 0;
            foreach (explode(',', $record) as $token) {
                $token = trim($token);
                if ($token === '') {
                    continue;
                }
                $items++;
                if (\strlen($token) === 3 && ctype_digit($token)) {
                    $code = (int) $token;
                    if ($code >= 200 && $code <= 299) {
                        $success++;
                    } elseif ($code === 408 || $code === 429 || $code === 503 || $code === 504) {
                        $retry++;
                    } elseif ($code >= 400 && $code <= 599) {
                        $errors++;
                    }

                    continue;
                }
                switch ($token) {
                    case 'published':
                    case 'filtered':
                    case 'metadata_review':
                        $success++;
                        break;
                    case 'deferred':
                    case 'paused':
                    case 'retry':
                    case 'stale':
                        $retry++;
                        break;
                    case 'error':
                    case 'transport':
                    case 'invalid':
                        $errors++;
                        break;
                }
            }
            $health = match (true) {
                $items === 0 => 0,
                $errors === 0 && $retry * 4 <= $items => 1,
                $errors * 10 <= $items => 2,
                default => 3,
            };
            array_push($out, $items, $success, $retry, $errors, $health);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function forwarderPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w57Words($count, implode("\0", $records), 5 * $count) ?? self::forwarderPlanBatchPhp($records);
    }
}
