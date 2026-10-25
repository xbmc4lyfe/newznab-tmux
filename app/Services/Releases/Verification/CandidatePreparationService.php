<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
