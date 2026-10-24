<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\SearchService;
use App\Support\RustPipelineLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class NntmuxSearchRepair extends Command
{
    protected $signature = 'nntmux:search-repair
                            {--limit=100 : Maximum failed releases to retry}
                            {--dry-run : Report due failures without writing to Manticore}';

    protected $description = 'Retry failed release search-index updates';

    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $query = DB::table('search_index_failures')
            ->whereNull('resolved_at')
            ->where(function ($query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit);

        $rows = $query->get(['release_id', 'operation', 'attempts', 'last_error']);
        if ($rows->isEmpty()) {
            $this->info('No failed release index updates are due for repair.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $releaseId = (int) $row->release_id;
            if ((bool) $this->option('dry-run')) {
                $this->line((string) $releaseId);

                continue;
            }

            if ($row->operation === 'delete') {
                Search::deleteRelease($releaseId);
            } elseif ($row->operation === SearchService::DEFERRED_RELEASE_OPERATION) {
                $this->repairExpiredDeferral($releaseId, (string) $row->last_error, (int) $row->attempts);
            } else {
                Search::updateRelease($releaseId);
            }
        }

        $this->info(sprintf('%s release index failure(s) %s.', $rows->count(), $this->option('dry-run') ? 'reported' : 'processed'));

        return self::SUCCESS;
    }

    /**
     * Refresh a release whose deferral lease expired (its release pass died, ran long or
     * handed it over after a failed refresh). If the refresh worked, remove the marker
     * unless the pass renewed it meanwhile; otherwise retry it with backoff.
     */
    private function repairExpiredDeferral(int $releaseId, string $token, int $attempts): void
    {
        $marker = DB::table('search_index_failures')
            ->where('release_id', $releaseId)
            ->where('operation', SearchService::DEFERRED_RELEASE_OPERATION)
            ->where('last_error', $token)
            ->where('next_attempt_at', '<=', now());

        $driver = Search::driver();
        if (! $driver instanceof BulkReleaseIndexUpdater) {
            // Only bulk drivers defer. After a switch to a driver that can't report
            // success, keep the release as an ordinary failure, retried with backoff.
            $marker->update([
                'operation' => 'upsert',
                'attempts' => $attempts + 1,
                'last_error' => 'deferred lease expired',
                'next_attempt_at' => $this->backoff($attempts + 1),
                'updated_at' => now(),
            ]);
            Search::updateRelease($releaseId);

            return;
        }

        if ($driver->updateReleases([$releaseId]) === []) {
            $marker->where('attempts', $attempts)->delete();

            return;
        }

        $marker->update([
            'next_attempt_at' => $this->backoff($attempts + 1),
            'updated_at' => now(),
        ]);
    }

    private function backoff(int $attempts, bool $useRustFfi = false): \DateTimeInterface
    {
        return ($useRustFfi ? $this->backoffNative($attempts) : null) ?? $this->backoffPhp($attempts);
    }

    public function backoffPhp(int $attempts): \DateTimeInterface
    {
        return now()->addSeconds(self::backoffDelayPhp($attempts));
    }

    public function backoffNative(int $attempts): ?\DateTimeInterface
    {
        $delay = self::backoffDelayNative($attempts);

        return $delay === null ? null : now()->addSeconds($delay);
    }

    public static function backoffDelayPhp(int $attempts): int|float
    {
        return $attempts < 0 ? 2.0 ** $attempts : min(3600, 2 ** min($attempts, 10));
    }

    public static function backoffDelayNative(int $attempts): int|float|null
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'double nntmux_search_repair_backoff(int64_t attempts);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $delay = $ffi->nntmux_search_repair_backoff($attempts);

            if (! is_float($delay) || $delay < 0) {
                return null;
            }

            return $attempts < 0 ? $delay : (int) $delay;
        } catch (Throwable) {
            return null;
        }
    }
}
