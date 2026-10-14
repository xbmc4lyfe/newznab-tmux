<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Predb;
use App\Models\Release;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Manticoresearch\Endpoints\Sql;
use RuntimeException;
use Throwable;

class NntmuxSearchReconcile extends Command
{
    protected $signature = 'nntmux:search-reconcile
                            {--predb : Reconcile PreDB documents; requires --all}
                            {--since=24h : Only releases with adddate after this window (e.g. 24h, 7d)}
                            {--all : Scan every row instead of applying --since; required for --predb}
                            {--chunk=1000 : MySQL chunk size when scanning rows}
                            {--in-batch=500 : Max ids per Manticore IN() query}
                            {--orphans : Also remove indexed ids that no longer exist in MySQL}
                            {--reindex : Repair missing release or PreDB documents}
                            {--dry-run : Report only; do not write to the index}';

    protected $description = 'Find release or PreDB rows missing from Manticore and optionally reindex them';

    public function handle(ManticoreSearchDriver $manticore): int
    {
        if ($this->option('predb') && (! $this->option('all') || $this->option('orphans'))) {
            $this->error('--predb requires --all and does not support --orphans.');

            return self::FAILURE;
        }

        if (config('search.default') !== 'manticore') {
            $this->error('This command only supports SEARCH_DRIVER=manticore.');

            return self::FAILURE;
        }

        if (! $manticore->isAvailable()) {
            $this->error('Manticore is not reachable.');

            return self::FAILURE;
        }

        if ($this->option('predb')) {
            return $this->reconcilePredb($manticore);
        }

        $since = (string) $this->option('since');
        $cutoff = $this->parseSinceCutoff($since);
        $scanAll = (bool) $this->option('all');
        $chunk = max(50, min(5000, (int) $this->option('chunk')));
        $inBatch = max(50, min(1000, (int) $this->option('in-batch')));
        $dryRun = (bool) $this->option('dry-run');
        $reindex = (bool) $this->option('reindex');
        $checkOrphans = (bool) $this->option('orphans');

        if ($reindex && $dryRun) {
            $this->warn('--reindex with --dry-run: no writes will be performed.');
            $reindex = false;
        }

        $index = $manticore->getReleasesIndex();
        $missingAll = [];
        $scanned = 0;

        $bar = $this->output->createProgressBar();
        $bar->start();

        $releaseQuery = Release::query();
        if (! $scanAll) {
            $releaseQuery->where('adddate', '>=', $cutoff);
        }
        $releaseQuery
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
        $scope = $scanAll ? 'all release rows' : "adddate >= {$cutoff->toDateTimeString()}";
        $this->info("Scanned {$scanned} release row(s); missing in Manticore index: {$missingTotal} ({$scope})");
        if ($missingTotal > 0) {
            $sample = \array_slice($missingAll, 0, 20);
            $this->line('Sample ids: '.implode(', ', $sample));
        }

        // Hardening: if a dry-run with a non-trivial scan reports 100% missing, that almost
        // always indicates a response-shape regression in fetchIndexedIds() (see
        // Manticoresearch\Response\SqlToArray::getResponse()). Emit a single warning with a
        // small probe sample so the regression is obvious without flooding logs.
        if ($dryRun && $scanned >= 50 && $missingTotal === $scanned) {
            $this->probeAndWarnAllMissing($manticore, $index, \array_slice($missingAll, 0, 5));
        }

        $orphans = $checkOrphans ? $this->findOrphanedIds($manticore, $chunk) : [];
        if ($orphans !== []) {
            $this->info('Indexed documents with no MySQL release row: '.count($orphans));
            $this->line('Orphan sample ids: '.implode(', ', array_slice($orphans, 0, 20)));
        }

        if ($missingTotal === 0 && $orphans === []) {
            return self::SUCCESS;
        }

        if (! $reindex) {
            $this->comment('Run with --reindex to repair missing documents and remove orphaned ids.');

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

        if ($orphans !== []) {
            try {
                Search::deleteReleases($orphans);
                $this->info('Removed '.count($orphans).' orphaned document(s).');
            } catch (Throwable $e) {
                $this->error('Failed removing orphaned documents: '.$e->getMessage());
            }
        }

        $this->info("Reindexed {$done} release(s).");

        return self::SUCCESS;
    }

    private function reconcilePredb(ManticoreSearchDriver $driver): int
    {
        $chunk = max(50, min(5000, (int) $this->option('chunk')));
        $inBatch = max(50, min(1000, (int) $this->option('in-batch')));
        $reindex = $this->option('reindex') && ! $this->option('dry-run');
        $maxId = 0;
        $lastCompletedId = 0;
        $scanned = 0;
        $missingTotal = 0;
        $repaired = 0;
        $sample = [];

        try {
            $maxId = (int) Predb::query()->max('id');
            $index = $driver->getPredbIndex();
            Predb::query()->select(['id', 'title', 'filename', 'source'])
                ->where('id', '<=', $maxId)
                ->chunkById($chunk, function (Collection $rows) use ($driver, $index, $inBatch, $reindex, &$scanned, &$missingTotal, &$repaired, &$sample, &$lastCompletedId): void {
                    foreach ($rows->chunk($inBatch) as $batch) {
                        $ids = $batch->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                        $indexed = $this->fetchPredbIndexedIds($driver, $index, $ids);
                        $missing = array_values(array_diff($ids, $indexed));
                        $scanned += count($ids);
                        $missingTotal += count($missing);
                        $sample = array_slice([...$sample, ...array_slice($missing, 0, 20)], 0, 20);

                        if ($reindex && $missing !== []) {
                            $documents = $batch->whereIn('id', $missing)->map(static fn (Predb $row): array => $row->getAttributes())->values()->all();
                            // INSERT rejects IDs indexed by a producer since the probe,
                            // preserving its fresher document and allowing a safe rerun.
                            $result = $driver->bulkInsertPredb($documents, replaceExisting: false);
                            if (($result['success'] ?? null) !== count($missing) || ($result['errors'] ?? null) !== 0) {
                                throw new RuntimeException('PreDB bulk write failed.');
                            }
                            if (array_diff($missing, $this->fetchPredbIndexedIds($driver, $index, $missing)) !== []) {
                                throw new RuntimeException('PreDB write verification failed.');
                            }
                            $repaired += count($missing);
                        }
                    }
                    $lastCompletedId = (int) $rows->last()->id;
                });
        } catch (Throwable $error) {
            $this->error('PreDB reconciliation failed ('.get_class($error).').');
            $this->line("Last completed ID: {$lastCompletedId}; frozen max ID: {$maxId}");

            return self::FAILURE;
        }

        $this->info("Scanned {$scanned} PreDB row(s); missing in Manticore index: {$missingTotal}");
        $this->line("Last completed ID: {$lastCompletedId}; frozen max ID: {$maxId}");
        if ($sample !== []) {
            $this->line('Sample ids: '.implode(', ', $sample));
        }
        if ($reindex) {
            $this->info("Reindexed {$repaired} PreDB row(s).");
        } elseif ($missingTotal > 0) {
            $this->comment('Run with --predb --all --reindex to repair missing documents.');
        }

        return self::SUCCESS;
    }

    /**
     * Fail closed: an empty successful result is distinct from an invalid response.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function fetchPredbIndexedIds(ManticoreSearchDriver $driver, string $index, array $ids): array
    {
        $safeIndex = str_replace('`', '``', $index);
        $list = implode(',', $ids);
        $limit = count($ids);
        // Client::sql() uses SqlToArray, which discards warnings when flattening
        // rows. Keep the raw envelope so partial results cannot trigger repairs.
        $request = new Sql(['body' => ['query' => "SELECT id FROM `{$safeIndex}` WHERE id IN ({$list}) LIMIT {$limit} OPTION max_matches={$limit}"]]);
        $request->setMode('raw');
        $response = $driver->manticoreSearch->request($request)->getResponse();
        if (is_array($response) && array_is_list($response) && count($response) === 1 && is_array($response[0]) && array_key_exists('data', $response[0])) {
            $response = $response[0];
        }
        if (! is_array($response)
            || ! is_array($response['data'] ?? null)
            || ! is_int($response['total'] ?? null)
            || $response['total'] < 0
            || ($response['error'] ?? null) !== ''
            || ($response['warning'] ?? null) !== ''
            || $response['total'] !== count($response['data'])) {
            throw new RuntimeException('Invalid PreDB index response.');
        }

        $out = [];
        foreach ($response['data'] as $row) {
            if (! is_array($row) || ! isset($row['id'])) {
                throw new RuntimeException('Invalid PreDB index row.');
            }
            $value = $row['id'];
            if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || ! in_array((int) $value, $ids, true)) {
                throw new RuntimeException('Invalid PreDB index ID.');
            }
            $out[(int) $value] = (int) $value;
        }

        return array_values($out);
    }

    /**
     * @return list<int>
     */
    private function findOrphanedIds(ManticoreSearchDriver $manticore, int $pageSize): array
    {
        $orphans = [];
        $offset = 0;

        do {
            $indexed = $manticore->indexedReleaseIds($pageSize, $offset);
            if ($indexed === []) {
                break;
            }

            $existing = Release::query()->whereIn('id', $indexed)->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $orphans = [...$orphans, ...array_values(array_diff($indexed, $existing))];
            $offset += count($indexed);
        } while (count($indexed) === $pageSize);

        return $orphans;
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
        // Manticore SQL applies an implicit LIMIT 20 when no LIMIT clause is given, AND
        // max_matches defaults to 1000. Without explicitly raising both, our IN() probe
        // would only return the first 20 (or 1000) matches per batch, falsely flagging
        // the rest as missing. Use the actual batch size with a small ceiling cushion.
        $limit = max(\count($ids), 1);
        $sql = "SELECT id FROM `{$safeIndex}` WHERE id IN ({$list}) LIMIT {$limit} OPTION max_matches={$limit}";

        try {
            $response = $driver->manticoreSearch->sql($sql, true);
        } catch (Throwable) {
            return [];
        }

        if (! \is_array($response)) {
            return [];
        }

        $requested = array_flip($ids);
        $out = [];

        // In raw mode (sql($q, true)) the response goes through
        // Manticoresearch\Response\SqlToArray::getResponse() which FLATTENS a SELECT result
        // into [id => id] for single-column projections (or [id => rowArray] for multi-column).
        // The old `$response['data']` shape only exists for non-raw mode or pre-4.x clients.
        foreach ($response as $key => $value) {
            // Top-level numeric keys mirror row ids (single-column id projection).
            if (\is_int($key) || ctype_digit($key)) {
                $id = (int) $key;
                if (isset($requested[$id])) {
                    $out[$id] = $id;
                }
            }
            // Multi-column rows: value is an array possibly containing 'id'.
            if (\is_array($value) && isset($value['id'])) {
                $id = (int) $value['id'];
                if (isset($requested[$id])) {
                    $out[$id] = $id;
                }
            }
            // Scalar value that itself is the id (defensive).
            if (\is_int($value) || (\is_string($value) && ctype_digit($value))) {
                $id = (int) $value;
                if (isset($requested[$id])) {
                    $out[$id] = $id;
                }
            }
        }

        // Legacy fallback: ['data' => [['id' => N], ...]] (non-raw / older client).
        if ($out === [] && isset($response['data']) && \is_array($response['data'])) {
            foreach ($response['data'] as $row) {
                if (\is_array($row) && isset($row['id'])) {
                    $id = (int) $row['id'];
                    if (isset($requested[$id])) {
                        $out[$id] = $id;
                    }
                }
            }
        }

        return array_values($out);
    }

    /**
     * Emit a single warning when a dry-run scan reports 100% missing. Logs a small sample
     * of the raw Manticore response so a future SqlToArray shape change is easy to spot.
     *
     * @param  list<int>  $sampleIds
     */
    private function probeAndWarnAllMissing(ManticoreSearchDriver $driver, string $index, array $sampleIds): void
    {
        if ($sampleIds === []) {
            return;
        }

        $list = implode(',', array_map(static fn (int $id): string => (string) $id, $sampleIds));
        $safeIndex = str_replace('`', '``', $index);
        $limit = max(\count($sampleIds), 1);
        $sql = "SELECT id FROM `{$safeIndex}` WHERE id IN ({$list}) LIMIT {$limit} OPTION max_matches={$limit}";

        $rawShape = 'unavailable';
        try {
            $response = $driver->manticoreSearch->sql($sql, true);
            if (\is_array($response)) {
                $rawShape = json_encode([
                    'top_keys' => \array_slice(array_keys($response), 0, 10),
                    'has_data_key' => isset($response['data']),
                    'size' => \count($response),
                ], JSON_UNESCAPED_SLASHES) ?: 'encode_failed';
            } else {
                $rawShape = 'non_array:'.\gettype($response);
            }
        } catch (Throwable $e) {
            $rawShape = 'exception:'.$e->getMessage();
        }

        $message = sprintf(
            'nntmux:search-reconcile reported 100%% missing for index "%s" — possible Manticore client response-shape regression. Probe sample ids=%s shape=%s',
            $index,
            implode(',', $sampleIds),
            $rawShape,
        );

        $this->warn($message);
        Log::warning($message);
    }
}
