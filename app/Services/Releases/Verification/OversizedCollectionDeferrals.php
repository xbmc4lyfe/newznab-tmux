<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Exceptions\CandidateMetadataBudgetExceeded;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Remembers collections whose headers exceeded the metadata budget, so archival stops reading every part of
 * them again on each pass. More articles only make an upload larger, so a retry can only succeed once the
 * budget is raised (which ends the deferral at once) or after the backoff, which covers anything else that
 * changed. Their headers stay in the ingestion tables meanwhile. Bookkeeping failures never stop archival:
 * a collection whose deferral cannot be read or written is simply tried again.
 */
final class OversizedCollectionDeferrals
{
    private const string TABLE = 'release_verification_oversized_collections';

    private const int FIRST_BACKOFF_SECONDS = 21600;

    private const int MAX_BACKOFF_SECONDS = 604800;

    private const string PRUNE_LOCK = 'release-verification:oversized-prune';

    private const int PRUNE_SECONDS = 3600;

    private const int PRUNE_ROWS = 1000;

    private ?bool $available = null;

    /** The most header metadata one candidate may hold, counted as in CandidateRepository's snapshot. */
    public static function budget(): int
    {
        return max(1, (int) config('release_verification.metadata_bytes', 50331648));
    }

    /**
     * Archive each collection unless it is waiting out an over-budget backoff at the current budget.
     *
     * @param  list<int>  $ids
     * @param  callable(int): void  $archive
     * @return array<int, array{error: Throwable|null, retry_after: string|null}> The ids not archived:
     *                                                                            deferred (no error) or failed.
     */
    public function archiveEach(array $ids, callable $archive): array
    {
        $recorded = $this->guarded(fn (): array => $this->lookup($ids), []);
        $kept = [];
        foreach ($ids as $id) {
            if ($recorded[$id] ?? false) {
                $kept[$id] = ['error' => null, 'retry_after' => null];

                continue;
            }
            try {
                $archive($id);
            } catch (Throwable $error) {
                $kept[$id] = ['error' => $error, 'retry_after' => $error instanceof CandidateMetadataBudgetExceeded
                    ? $this->guarded(fn (): ?string => $this->record($id), null) : null];
            }
        }
        // A collection archive() left in place (another archiver held it, or it was not ready) keeps its record.
        $settled = array_values(array_filter(array_keys($recorded), static fn (int $id): bool => ! isset($kept[$id])));
        $this->guarded(function () use ($settled): void {
            $this->dropStale($settled);
        }, null);

        return $kept;
    }

    /**
     * Drop the records of collections deleted without archival.
     *
     * @param  list<int>  $ids
     */
    public function forget(array $ids): void
    {
        $this->guarded(function () use ($ids): void {
            $this->dropStale($ids);
        }, null);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, bool> Whether each recorded collection is still deferred; ids without a record are absent.
     */
    private function lookup(array $ids): array
    {
        if ($ids === [] || ! $this->available()) {
            return [];
        }
        if (Cache::add(self::PRUNE_LOCK, true, self::PRUNE_SECONDS)) {
            $this->dropStale(DB::table(self::TABLE)->orderBy('collection_id')->limit(self::PRUNE_ROWS)
                ->pluck('collection_id')->map(static fn ($id): int => (int) $id)->all());
        }
        $now = now()->format('Y-m-d H:i:s');
        $budget = self::budget();
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $current = DB::table('collections')->whereIn('id', $chunk)->pluck('collectionhash', 'id')->all();
            foreach (DB::table(self::TABLE)->whereIn('collection_id', $chunk)->get() as $row) {
                $id = (int) $row->collection_id;
                // A deferral left by a replaced collection under a reused id never holds back the new one.
                $found[$id] = ($current[$id] ?? null) === $row->collectionhash
                    && (int) $row->budget_bytes >= $budget && (string) $row->retry_after > $now;
            }
        }

        return $found;
    }

    /** Record an over-budget failure, doubling the backoff on each repeat at the same budget. */
    private function record(int $collectionId): ?string
    {
        if (! $this->available()) {
            return null;
        }
        $hash = DB::table('collections')->where('id', $collectionId)->value('collectionhash');
        if (! is_string($hash)) {
            return null;
        }
        $budget = self::budget();
        $previous = DB::table(self::TABLE)->where('collection_id', $collectionId)->first(['collectionhash', 'budget_bytes', 'attempts']);
        $attempts = $previous !== null && $previous->collectionhash === $hash && (int) $previous->budget_bytes === $budget
            ? (int) $previous->attempts + 1 : 1;
        $now = now();
        $retryAfter = $now->copy()->addSeconds(min(self::MAX_BACKOFF_SECONDS, self::FIRST_BACKOFF_SECONDS * 2 ** min(10, $attempts - 1)));
        DB::table(self::TABLE)->upsert([['collection_id' => $collectionId, 'collectionhash' => $hash, 'budget_bytes' => $budget,
            'attempts' => $attempts, 'failed_at' => $now->format('Y-m-d H:i:s'), 'retry_after' => $retryAfter->format('Y-m-d H:i:s')]],
            ['collection_id'], ['collectionhash', 'budget_bytes', 'attempts', 'failed_at', 'retry_after']);

        return $retryAfter->toIso8601String();
    }

    /**
     * Drop these ids' records whose collection is gone or was replaced: archived, deleted or reused. A record
     * for a collection that still holds its headers is kept, whoever last wrote it. Collections are read
     * without locks; records are deleted by primary key and recorded hash only.
     *
     * @param  list<int>  $ids
     */
    private function dropStale(array $ids): void
    {
        if ($ids === [] || ! $this->available()) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            $current = DB::table('collections')->whereIn('id', $chunk)->pluck('collectionhash', 'id')->all();
            foreach (DB::table(self::TABLE)->whereIn('collection_id', $chunk)->get(['collection_id', 'collectionhash']) as $row) {
                if (($current[(int) $row->collection_id] ?? null) !== $row->collectionhash) {
                    DB::table(self::TABLE)->where('collection_id', $row->collection_id)->where('collectionhash', $row->collectionhash)->delete();
                }
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @param  T  $fallback
     * @return T
     */
    private function guarded(callable $work, mixed $fallback): mixed
    {
        try {
            return $work();
        } catch (Throwable $error) {
            Log::warning('Over-budget collection bookkeeping failed; archival continues without it.', ['error_type' => $error::class]);

            return $fallback;
        }
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }
}
