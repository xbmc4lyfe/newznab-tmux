<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\CollectionCleanupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Archive the late headers of dirty candidates. Ingestion marks a pending candidate dirty when more of its
 * articles arrive, and only archive() of that collection makes it claimable again. Release creation reaches
 * a collection only when its group's id cursor does, which on a group with a million ready collections can
 * take weeks, so this walks the dirty candidates themselves, oldest first, resuming after the last one it
 * looked at and starting over once it reaches the newest. archive() keeps every guarantee it gives release
 * creation, and over-budget uploads keep their deferral.
 */
final class DirtyCandidateSettler
{
    private const string CURSOR = 'release-verification:dirty-settle:cursor';

    public function __construct(private readonly CollectionCleanupService $cleanup) {}

    /**
     * @return array{inspected: int, settled: int, kept: int} Candidates looked at, those no longer dirty
     *                                                        afterwards, and late collections kept for later.
     */
    public function settle(int $limit, float $seconds): array
    {
        $totals = ['inspected' => 0, 'settled' => 0, 'kept' => 0];
        if (! config('release_verification.required', true)) {
            return $totals;
        }
        $limit = max(1, $limit);
        $deadline = microtime(true) + max(0.0, $seconds);
        $cursor = Cache::get(self::CURSOR);
        $after = is_array($cursor) && is_string($cursor['at'] ?? null) && is_numeric($cursor['id'] ?? null)
            ? ['at' => $cursor['at'], 'id' => (int) $cursor['id']] : null;
        $rows = DB::table('release_verification_candidates')->where('status', 'dirty')
            ->when($after !== null, static fn ($query) => $query->where(static fn ($q) => $q->where('next_attempt_at', '>', $after['at'])
                ->orWhere(static fn ($same) => $same->where('next_attempt_at', $after['at'])->where('id', '>', $after['id']))))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get(['id', 'source_identity', 'next_attempt_at']);
        if ($rows->isEmpty()) {
            // Nothing dirty past the cursor: start again from the oldest.
            Cache::forget(self::CURSOR);

            return $totals;
        }
        foreach ($rows as $row) {
            if ($totals['inspected'] > 0 && microtime(true) >= $deadline) {
                return $totals;
            }
            // Moved past first: a process that dies inside archive() (out of memory, killed) skips this candidate
            // until the walk comes round again instead of retrying it on every run ahead of the rest.
            Cache::forever(self::CURSOR, ['at' => (string) $row->next_attempt_at, 'id' => (int) $row->id]);
            $totals['inspected']++;
            $hash = is_string($row->source_identity) && strlen($row->source_identity) === 40 && ctype_xdigit($row->source_identity)
                ? (string) hex2bin($row->source_identity) : null;
            $ids = $hash === null ? [] : $this->cleanup->verificationReadyCollections()->where('collectionhash', $hash)
                ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $kept = app(OversizedCollectionDeferrals::class)->archiveEach($ids, static function (int $id): void {
                app(CandidateRepository::class)->archive($id);
            });
            foreach ($kept as $id => $outcome) {
                if ($outcome['error'] !== null) {
                    Log::warning('Verification archival failed while settling dirty candidates; original headers retained.', ['collection_id' => $id,
                        'error_type' => $outcome['error']::class] + ($outcome['retry_after'] === null ? [] : ['retry_after' => $outcome['retry_after']]));
                }
            }
            $totals['kept'] += count($kept);
            if (DB::table('release_verification_candidates')->where('id', $row->id)->value('status') !== 'dirty') {
                $totals['settled']++;
            }
        }
        if ($rows->count() < $limit) {
            // The walk reached the newest dirty candidate: start again from the oldest.
            Cache::forget(self::CURSOR);
        }

        return $totals;
    }
}
