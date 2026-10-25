<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/** Classify immutable header metadata without opening an NNTP connection. */
final class CandidatePreparationService
{
    private const WAITING_BATCH_SIZE = 25;

    public const string FFI_CDEF = 'int64_t nntmux_w33_release_verification_preparation(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    private const int NATIVE_MAX_BYTES = 16777216;

    /**
     * Rust stays opt-in: the FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustReleaseVerificationPreparationParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(private readonly ManifestStore $store, private readonly CandidateHeaderInventory $inventory) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /** The stored assessment reason: at most $maxBytes bytes, never splitting a UTF-8 sequence. */
    public static function truncateAssessmentReason(?string $reason, int $maxBytes = 2000): string
    {
        return self::usesRustFfi() ? self::truncateAssessmentReasonNative($reason, $maxBytes) : self::truncateAssessmentReasonPhp($reason, $maxBytes);
    }

    public static function truncateAssessmentReasonPhp(?string $reason, int $maxBytes = 2000): string
    {
        return mb_strcut($reason ?? '', 0, $maxBytes, 'UTF-8');
    }

    public static function truncateAssessmentReasonNative(?string $reason, int $maxBytes = 2000): string
    {
        // A negative length counts back from the end, which only mb_strcut() itself implements.
        $result = $maxBytes < 0 ? null : self::evaluateNative(3, pack('q', $maxBytes).($reason ?? ''));

        return $result !== null && \strlen($result) <= $maxBytes && str_starts_with($reason ?? '', $result)
            ? $result : self::truncateAssessmentReasonPhp($reason, $maxBytes);
    }

    /**
     * Shared Wave 33.2 bridge over packed native-endian 8-byte slots. Returns the raw output bytes, or
     * null when Rust declined the input or is unavailable.
     */
    public static function evaluateNative(int $operation, string $input): ?string
    {
        try {
            if ($operation < 0 || $input === '' || \strlen($input) > self::NATIVE_MAX_BYTES) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, min(self::NATIVE_MAX_BYTES, max(256, \strlen($input))), $capacity);
            if ($output === null) {
                return null;
            }
            $capacity = min($capacity, self::NATIVE_MAX_BYTES);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w33_release_verification_preparation($operation, $input, \strlen($input), $output, $capacity);
            if (\is_int($written) && $written < -1 && -$written > $capacity && -$written <= self::NATIVE_MAX_BYTES) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return null;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w33_release_verification_preparation($operation, $input, \strlen($input), $output, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return $written === 0 ? '' : FFI::string($output, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  (callable(bool): bool)|null  $preferPending
     * @param  (callable(array<string, mixed>): void)|null  $observer
     */
    public function prepare(int $limit, int $seconds, ?callable $preferPending = null, ?callable $observer = null): int
    {
        $limit = max(1, $limit);
        $phase = 0;
        $preferPending ??= static function (bool $advance) use (&$phase): bool {
            $next = ($phase + 1) % 5;
            if ($advance) {
                $phase = $next;
            }

            return $next !== 0;
        };
        $deadline = hrtime(true) + max(1, $seconds) * 1_000_000_000;
        $diagnostics = $observer === null ? null : new PreparationRunDiagnostics($deadline - max(1, $seconds) * 1_000_000_000);
        $inspected = 0;
        $changed = 0;
        $cursors = [];
        $exhausted = [];
        $error = false;
        $updates = null;
        try {
            $cutoff = now();
            $waiting = [];
            $waitingReason = '';
            while ($inspected < $limit && count($exhausted) < 2 && hrtime(true) < $deadline) {
                // Peek before selection; spend a turn only when its first metadata read is admitted.
                $statuses = $preferPending(false) ? ['pending', 'unknown'] : ['unknown', 'pending'];
                foreach ($statuses as $status) {
                    if (isset($exhausted[$status]) || hrtime(true) >= $deadline || $inspected >= $limit) {
                        continue;
                    }
                    $selectionStarted = $diagnostics?->start();
                    try {
                        $query = DB::table('release_verification_candidates')->where('status', $status)
                            ->where('next_attempt_at', '<=', $cutoff)->whereNull('release_id')->whereNull('lease_token')
                            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $cutoff));
                        if (isset($cursors[$status])) {
                            [$time, $id] = $cursors[$status];
                            $query->where(fn ($q) => $q->where('next_attempt_at', '>', $time)
                                ->orWhere(fn ($q) => $q->where('next_attempt_at', $time)->where('id', '>', $id)));
                        }
                        $pageSize = min(100, $limit - $inspected);
                        $rows = $query->orderBy('next_attempt_at')->orderBy('id')->limit($pageSize)
                            ->get(['id', 'version', 'manifest_path', 'status', 'next_attempt_at']);
                    } finally {
                        $diagnostics?->finish('selection', $selectionStarted);
                    }
                    $diagnostics?->selected($rows->count());
                    if ($rows->count() < $pageSize) {
                        $exhausted[$status] = true;
                    }
                    $phaseCommitted = false;
                    foreach ($rows as $row) {
                        if (hrtime(true) >= $deadline) {
                            $changed += $this->flushWaiting($waiting, $waitingReason, $cutoff, $diagnostics);
                            break 2;
                        }
                        if (! $phaseCommitted) {
                            $preferPending(true);
                            $phaseCommitted = true;
                        }
                        $inspected++;
                        $diagnostics?->admitted();
                        $cursors[$status] = [$row->next_attempt_at, $row->id];
                        $assessmentStarted = $diagnostics?->start();
                        try {
                            $manifest = $diagnostics === null ? $this->store->read($row->manifest_path)
                                : $this->store->readWithDiagnostics($row->manifest_path, $diagnostics);
                            $assessment = $diagnostics === null ? $this->inventory->assessment($manifest)
                                : $diagnostics->measurePhase('assessment', fn () => $this->inventory->assessment($manifest));
                            $diagnostics?->assessed($assessment['status']);
                        } catch (Throwable $readError) {
                            $diagnostics?->readAssessmentFailed();
                            $assessment = ['status' => 'unknown', 'reason' => $readError->getMessage()];
                        } finally {
                            unset($manifest);
                            $diagnostics?->finish('read_assessment', $assessmentStarted);
                        }
                        if ($assessment['status'] === 'waiting_upload') {
                            $reason = self::truncateAssessmentReason($assessment['reason'] ?? null);
                            if ($waitingReason !== $reason) {
                                $changed += $this->flushWaiting($waiting, $waitingReason, $cutoff, $diagnostics);
                                $waitingReason = $reason;
                            }
                            $waiting[] = $row;
                            if (count($waiting) === self::WAITING_BATCH_SIZE) {
                                $changed += $this->flushWaiting($waiting, $waitingReason, $cutoff, $diagnostics);
                            }

                            continue;
                        }
                        // Keep ready promotions and retry timestamps immediate and ordered.
                        $changed += $this->flushWaiting($waiting, $waitingReason, $cutoff, $diagnostics);
                        $updateStarted = $diagnostics?->start();
                        try {
                            $values = ['status' => $assessment['status'], 'header_check_pending' => false];
                            // Preserve a valid candidate's NNTP backoff and diagnostic reason.
                            $shape = PreparedCandidateUpdate::classifyUpdateShape($assessment['status'] !== 'ready', $assessment['status'] === 'unknown');
                            if (($shape & PreparedCandidateUpdate::SHAPE_REASON) !== 0) {
                                $values['reason'] = self::truncateAssessmentReason($assessment['reason'] ?? null);
                            }
                            if (($shape & PreparedCandidateUpdate::SHAPE_RETRY) !== 0) {
                                $values['next_attempt_at'] = now()->addSeconds((int) config('release_verification.retry_seconds'));
                            }
                            $query = DB::table('release_verification_candidates')->where('id', $row->id)
                                ->where('version', $row->version)->where('manifest_path', $row->manifest_path)
                                ->where('status', $row->status)->where('next_attempt_at', $row->next_attempt_at)
                                ->whereNull('release_id')->whereNull('lease_token')
                                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $cutoff));
                            $connection = $query->getConnection();
                            if ($connection instanceof Connection) {
                                $updates ??= new PreparedCandidateUpdate($connection);
                                $affected = $updates->update($query, $values);
                            } else {
                                // Custom ConnectionInterface implementations retain
                                // their own update pipeline and do not use our macro.
                                $affected = $query->update($values);
                            }
                            $changed += $affected;
                            $diagnostics?->updated($assessment['status'], $affected);
                        } finally {
                            $diagnostics?->finish('update', $updateStarted);
                        }
                    }
                    $changed += $this->flushWaiting($waiting, $waitingReason, $cutoff, $diagnostics);
                    if ($rows->isNotEmpty()) {
                        break;
                    }
                }
            }

            return $changed;
        } catch (Throwable $failure) {
            $error = true;
            throw $failure;
        } finally {
            if ($diagnostics !== null && $observer !== null) {
                try {
                    $observer($diagnostics->report($limit, max(1, $seconds), $inspected, $deadline, count($exhausted) >= 2, $error));
                } catch (Throwable) {
                    // Diagnostics must not change database outcomes or hide the original failure.
                }
            }
        }
    }

    /** @param list<stdClass> $rows */
    private function flushWaiting(array &$rows, string $reason, Carbon $cutoff, ?PreparationRunDiagnostics $diagnostics): int
    {
        if ($rows === []) {
            return 0;
        }
        $started = $diagnostics?->start();
        try {
            $affected = $this->updateWaiting($rows, $reason, $cutoff);
            $diagnostics?->updated('waiting_upload', $affected, count($rows));
            $rows = [];

            return $affected;
        } finally {
            $diagnostics?->finish('update', $started);
        }
    }

    /** @param list<stdClass> $rows */
    private function updateWaiting(array $rows, string $reason, Carbon $cutoff): int
    {
        $connection = DB::connection();
        // UPDATE ignores Query Builder's SELECT index hint. Bound its physical scan
        // to the primary-key list, including when other status indexes look cheaper.
        $table = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            ? DB::raw($connection->getQueryGrammar()->wrapTable('release_verification_candidates').' FORCE INDEX (PRIMARY)')
            : 'release_verification_candidates';

        $query = $connection->table($table)->whereIn('id', array_column($rows, 'id'))
            ->whereNull('release_id')->whereNull('lease_token')
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', $cutoff))
            ->where(function ($query) use ($rows): void {
                foreach ($rows as $row) {
                    $query->orWhere(fn ($snapshot) => $snapshot->where('id', $row->id)
                        ->where('version', $row->version)->where('manifest_path', $row->manifest_path)
                        ->where('status', $row->status)->where('next_attempt_at', $row->next_attempt_at));
                }
            });
        $values = ['status' => 'waiting_upload', 'reason' => $reason, 'header_check_pending' => false];
        if (count($rows) === 1 || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->update($values);
        }

        // Ingestion can own candidates in collection order. Acquire only available
        // candidate locks so a multirow update cannot wait while holding its prefix.
        return $connection->transaction(static function () use ($query, $values): int {
            $ids = (clone $query)->lock('FOR UPDATE SKIP LOCKED')->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }

            return $query->whereIn('id', $ids)->update($values);
        });
    }

    /** Wave 49.2 paired benchmarks decide this default; see RustVerificationAdmissionInventoryPrepRepoSampleW49ParityTest. */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        intptr_t nntmux_w49_candidate_preparation_batch(const char *statuses, size_t statuses_len, const char *reasons, size_t reasons_len, size_t count, int64_t max_bytes, int64_t batch_size, int64_t *out, size_t out_cap);
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
     * Runs $callback with planAssessmentUpdates() routed to Rust ($enabled) or PHP, then restores the previous
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
     * Batch plan of prepare()'s update stream for assessed rows. One word per row: `status code
     * (0 ready, 1 waiting_upload, 2 header_rejected, 3 unknown, 4 other) | shape << 3 | 32 a
     * waiting batch flushes before this row | 64 the waiting batch reached $batchSize and flushed
     * | stored reason length << 8 | flushes so far << 32`, where shape holds
     * PreparedCandidateUpdate's SHAPE_REASON (any status but ready) and SHAPE_RETRY (unknown) bits
     * and the stored reason is truncateAssessmentReasonPhp($reason, $maxBytes) (lengths clamped to
     * 2^24 - 1, flushes to 2^30 - 1). Consecutive waiting rows with the same stored reason share a
     * batch; any other row flushes it first. Then total flushes, immediate updates, waiting rows
     * and the XXH64 digest of all earlier words.
     *
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function planAssessmentUpdates(array $statuses, array $reasons, int $maxBytes = 2000, int $batchSize = self::WAITING_BATCH_SIZE): array
    {
        return self::usesW49RustFfi()
            ? self::planAssessmentUpdatesNative($statuses, $reasons, $maxBytes, $batchSize)
            : self::planAssessmentUpdatesPhp($statuses, $reasons, $maxBytes, $batchSize);
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function planAssessmentUpdatesPhp(array $statuses, array $reasons, int $maxBytes = 2000, int $batchSize = self::WAITING_BATCH_SIZE): array
    {
        $batch = max(1, $batchSize);
        $out = [];
        $group = '';
        $pending = $flushes = $immediate = $waiting = 0;
        foreach ($statuses as $i => $status) {
            $shape = (int) ($status !== 'ready') | (int) ($status === 'unknown') << 1;
            $text = ($shape & 1) !== 0 ? mb_strcut($reasons[$i] ?? '', 0, $maxBytes, 'UTF-8') : '';
            $flags = 0;
            if ($status === 'waiting_upload') {
                if ($text !== $group) {
                    if ($pending > 0) {
                        $flushes++;
                        $pending = 0;
                        $flags |= 32;
                    }
                    $group = $text;
                }
                $pending++;
                $waiting++;
                if ($pending === $batch) {
                    $flushes++;
                    $pending = 0;
                    $flags |= 64;
                }
            } else {
                if ($pending > 0) {
                    $flushes++;
                    $pending = 0;
                    $flags |= 32;
                }
                $immediate++;
            }
            $code = match ($status) {
                'ready' => 0,
                'waiting_upload' => 1,
                'header_rejected' => 2,
                'unknown' => 3,
                default => 4,
            };
            $out[] = $code | $shape << 3 | $flags | min(\strlen($text), 0xFFFFFF) << 8 | min($flushes, 0x3FFFFFFF) << 32;
        }
        if ($pending > 0) {
            $flushes++;
        }
        $out[] = $flushes;
        $out[] = $immediate;
        $out[] = $waiting;

        return self::w49Digest($out);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length, a
     * value holds a NUL byte or $maxBytes is negative.
     *
     * @param  list<string>  $statuses
     * @param  list<string|null>  $reasons
     * @return list<int>
     */
    public static function planAssessmentUpdatesNative(array $statuses, array $reasons, int $maxBytes = 2000, int $batchSize = self::WAITING_BATCH_SIZE): array
    {
        $count = \count($statuses);
        $ffi = $count === 0 || $maxBytes < 0 ? null : (self::$w49Ffi instanceof FFI ? self::$w49Ffi : self::w49Ffi());
        if ($ffi === null || \count($reasons) !== $count) {
            return self::planAssessmentUpdatesPhp($statuses, $reasons, $maxBytes, $batchSize);
        }
        $joinedStatuses = implode("\0", $statuses);
        $joinedReasons = implode("\0", $reasons);
        $words = $count + 4;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w49Out($ffi, $words);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w49_candidate_preparation_batch($joinedStatuses, \strlen($joinedStatuses), $joinedReasons, \strlen($joinedReasons), $count, $maxBytes, $batchSize, $out, self::$w49OutCap);
            $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::planAssessmentUpdatesPhp($statuses, $reasons, $maxBytes, $batchSize);
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
