<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Classify immutable header metadata without opening an NNTP connection. */
final class CandidatePreparationService
{
    public function __construct(private readonly ManifestStore $store, private readonly CandidateHeaderInventory $inventory) {}

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
        try {
            $cutoff = now();
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
                            $assessment = $this->inventory->assessment($this->store->read($row->manifest_path));
                            $diagnostics?->assessed($assessment['status']);
                        } catch (Throwable $readError) {
                            $diagnostics?->readAssessmentFailed();
                            $assessment = ['status' => 'unknown', 'reason' => $readError->getMessage()];
                        } finally {
                            $diagnostics?->finish('read_assessment', $assessmentStarted);
                        }
                        $updateStarted = $diagnostics?->start();
                        try {
                            $values = ['status' => $assessment['status']];
                            // Preserve a valid candidate's NNTP backoff and diagnostic reason.
                            if ($assessment['status'] !== 'ready') {
                                $values['reason'] = mb_strcut($assessment['reason'] ?? '', 0, 2000, 'UTF-8');
                                if ($assessment['status'] === 'unknown') {
                                    $values['next_attempt_at'] = now()->addSeconds((int) config('release_verification.retry_seconds'));
                                }
                            }
                            $affected = DB::table('release_verification_candidates')->where('id', $row->id)
                                ->where('version', $row->version)->where('manifest_path', $row->manifest_path)
                                ->where('status', $row->status)->where('next_attempt_at', $row->next_attempt_at)
                                ->whereNull('release_id')->whereNull('lease_token')
                                ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', $cutoff))
                                ->update($values);
                            $changed += $affected;
                            $diagnostics?->updated($assessment['status'], $affected);
                        } finally {
                            $diagnostics?->finish('update', $updateStarted);
                        }
                    }
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
}
