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

    private const int CHUNK = 25;

    public function __construct(private readonly CollectionCleanupService $cleanup) {}

    /** @return array{inspected: int, archived: int, kept: int} */
    public function settle(int $limit, float $seconds): array
    {
        $limit = max(1, $limit);
        $deadline = microtime(true) + max(0.0, $seconds);
        $cursor = Cache::get(self::CURSOR);
        $after = is_array($cursor) && is_string($cursor['at'] ?? null) && is_numeric($cursor['id'] ?? null)
            ? ['at' => $cursor['at'], 'id' => (int) $cursor['id']] : null;
        $rows = DB::table('release_verification_candidates')->where('status', 'dirty')
            ->when($after !== null, static fn ($query) => $query->where(static fn ($q) => $q->where('next_attempt_at', '>', $after['at'])
                ->orWhere(static fn ($same) => $same->where('next_attempt_at', $after['at'])->where('id', '>', $after['id']))))
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->get(['id', 'source_identity', 'next_attempt_at']);
        $totals = ['inspected' => 0, 'archived' => 0, 'kept' => 0];
        $last = null;
        foreach ($rows->chunk(self::CHUNK) as $chunk) {
            if ($last !== null && microtime(true) >= $deadline) {
                break;
            }
            $hashes = $chunk->pluck('source_identity')->filter(static fn ($hash): bool => is_string($hash) && ctype_xdigit($hash) && strlen($hash) === 40)
                ->map(static fn (string $hash): string => (string) hex2bin($hash))->unique()->values()->all();
            $ids = $hashes === [] ? [] : $this->cleanup->verificationReadyCollections()->whereIn('collectionhash', $hashes)
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
            $totals['inspected'] += $chunk->count();
            $totals['archived'] += count($ids) - count($kept);
            $totals['kept'] += count($kept);
            $last = $chunk->last();
        }
        if ($last === null || ($totals['inspected'] === $rows->count() && $rows->count() < $limit)) {
            // Nothing dirty past the cursor, or the walk reached the newest: start again from the oldest.
            Cache::forget(self::CURSOR);
        } else {
            Cache::forever(self::CURSOR, ['at' => (string) $last->next_attempt_at, 'id' => (int) $last->id]);
        }

        return $totals;
    }
}
