<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Release;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class NntmuxSearchReconcile extends Command
{
    protected $signature = 'nntmux:search-reconcile
                            {--since=24h : Only releases with adddate after this window (e.g. 24h, 7d)}
                            {--chunk=1000 : MySQL chunk size when scanning releases}
                            {--in-batch=500 : Max ids per Manticore IN() query}
                            {--reindex : Call Search::updateRelease for each missing id}
                            {--dry-run : Report only; do not write to the index}';

    protected $description = 'Find releases rows missing from Manticore releases_rt and optionally reindex them';

    public function handle(ManticoreSearchDriver $manticore): int
    {
        if (config('search.default') !== 'manticore') {
            $this->error('This command only supports SEARCH_DRIVER=manticore.');

            return self::FAILURE;
        }

        if (! $manticore->isAvailable()) {
            $this->error('Manticore is not reachable.');

            return self::FAILURE;
        }

        $since = (string) $this->option('since');
        $cutoff = $this->parseSinceCutoff($since);
        $chunk = max(50, min(5000, (int) $this->option('chunk')));
        $inBatch = max(50, min(1000, (int) $this->option('in-batch')));
        $dryRun = (bool) $this->option('dry-run');
        $reindex = (bool) $this->option('reindex');

        if ($reindex && $dryRun) {
            $this->warn('--reindex with --dry-run: no writes will be performed.');
            $reindex = false;
        }

        $index = $manticore->getReleasesIndex();
        $missingAll = [];
        $scanned = 0;

        $bar = $this->output->createProgressBar();
        $bar->start();

        Release::query()
            ->where('adddate', '>=', $cutoff)
            ->orderBy('id')
            ->chunkById($chunk, function ($releases) use ($manticore, $index, $inBatch, &$missingAll, &$scanned, $bar): void {
                $ids = $releases->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                $scanned += \count($ids);
                foreach (array_chunk($ids, $inBatch) as $batch) {
                    $indexed = $this->fetchIndexedIds($manticore, $index, $batch);
                    foreach (array_diff($batch, $indexed) as $mid) {
                        $missingAll[] = (int) $mid;
                    }
                }
                $bar->advance($releases->count());
            }, 'id');

        $bar->finish();
        $this->newLine(2);

        $missingTotal = \count($missingAll);
        $this->info("Scanned {$scanned} release row(s); missing in Manticore index: {$missingTotal} (adddate >= {$cutoff->toDateTimeString()})");
        if ($missingTotal > 0) {
            $sample = \array_slice($missingAll, 0, 20);
            $this->line('Sample ids: '.implode(', ', $sample));
        }

        if ($missingTotal === 0) {
            return self::SUCCESS;
        }

        if (! $reindex) {
            $this->comment('Run with --reindex to push Search::updateRelease() for each missing id.');

            return self::SUCCESS;
        }

        $this->info('Reindexing missing releases...');
        $done = 0;
        foreach ($missingAll as $mid) {
            try {
                Search::updateRelease($mid);
                $done++;
            } catch (Throwable $e) {
                $this->error("Failed reindex id={$mid}: ".$e->getMessage());
            }
        }

        $this->info("Reindexed {$done} release(s).");

        return self::SUCCESS;
    }

    private function parseSinceCutoff(string $since): Carbon
    {
        $since = trim($since);
        if (preg_match('/^(\d+)h$/i', $since, $m)) {
            return Carbon::now()->subHours((int) $m[1]);
        }
        if (preg_match('/^(\d+)d$/i', $since, $m)) {
            return Carbon::now()->subDays((int) $m[1]);
        }

        try {
            return Carbon::parse($since, config('app.timezone'));
        } catch (Throwable) {
            $this->warn("Could not parse --since={$since}; defaulting to 24 hours.");

            return Carbon::now()->subHours(24);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function fetchIndexedIds(ManticoreSearchDriver $driver, string $index, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $list = implode(',', array_map(static fn (int $id): string => (string) $id, $ids));
        $safeIndex = str_replace('`', '``', $index);
        $sql = "SELECT id FROM `{$safeIndex}` WHERE id IN ({$list})";

        try {
            $response = $driver->manticoreSearch->sql($sql, true);
        } catch (Throwable) {
            return [];
        }

        if (! \is_array($response) || ! isset($response['data']) || ! \is_array($response['data'])) {
            return [];
        }

        $out = [];
        foreach ($response['data'] as $row) {
            if (isset($row['id'])) {
                $out[] = (int) $row['id'];
            }
        }

        return $out;
    }
}
