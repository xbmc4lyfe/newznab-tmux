<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers collections whose headers exceeded the metadata budget, so release creation stops reading
 * every part of them again on each pass. More articles only make an upload larger, so a retry can only
 * succeed once the budget is raised (which clears the deferral at once) or after the backoff, which
 * covers anything else that changed. Their headers stay in the ingestion tables meanwhile.
 */
final class OversizedCollectionDeferrals
{
    private const string TABLE = 'release_verification_oversized_collections';

    private const int FIRST_BACKOFF_SECONDS = 21600;

    private const int MAX_BACKOFF_SECONDS = 604800;

    private const string PRUNE_LOCK = 'release-verification:oversized-prune';

    private const int PRUNE_SECONDS = 3600;

    private ?bool $available = null;

    /** The most header metadata one candidate may hold, counted as in CandidateRepository's snapshot. */
    public static function budget(): int
    {
        return max(1, (int) config('release_verification.metadata_bytes', 50331648));
    }

    /**
     * Which of these collections have a recorded over-budget failure, and whether each is still waiting out
     * its backoff at the current budget. A deferral left by a replaced collection under a reused id never
     * holds back the new one.
     *
     * @param  list<int>  $ids
     * @return array<int, bool> Deferred, by collection id; ids without a record are absent.
     */
    public function lookup(array $ids): array
    {
        if ($ids === [] || ! $this->available()) {
            return [];
        }
        $this->prune();
        $now = now()->format('Y-m-d H:i:s');
        $budget = self::budget();
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = DB::table(self::TABLE.' as o')->leftJoin('collections as c', 'c.id', '=', 'o.collection_id')
                ->whereIn('o.collection_id', $chunk)->get(['o.collection_id', 'o.collectionhash', 'o.budget_bytes', 'o.retry_after', 'c.collectionhash as current_hash']);
            foreach ($rows as $row) {
                $found[(int) $row->collection_id] = $row->current_hash === $row->collectionhash
                    && (int) $row->budget_bytes >= $budget && (string) $row->retry_after > $now;
            }
        }

        return $found;
    }

    /** Record an over-budget failure, doubling the backoff on each repeat at the same budget. */
    public function record(int $collectionId): ?Carbon
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

        return $retryAfter;
    }

    /** @param list<int> $ids */
    public function clear(array $ids): void
    {
        if ($ids === [] || ! $this->available()) {
            return;
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table(self::TABLE)->whereIn('collection_id', $chunk)->delete();
        }
    }

    /** Hourly, drop deferrals whose collection is gone or was replaced; the table holds only oversized uploads. */
    private function prune(): void
    {
        if (! Cache::add(self::PRUNE_LOCK, true, self::PRUNE_SECONDS)) {
            return;
        }
        DB::table(self::TABLE)->whereNotExists(static fn ($query) => $query->selectRaw('1')->from('collections as c')
            ->whereColumn('c.id', self::TABLE.'.collection_id')->whereColumn('c.collectionhash', self::TABLE.'.collectionhash'))
            ->delete();
    }

    private function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }
}
