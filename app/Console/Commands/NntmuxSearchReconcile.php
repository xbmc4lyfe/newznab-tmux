<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Facades\Search;
use App\Models\Predb;
use App\Models\Release;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use FFI\CData;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Manticoresearch\Endpoints\Sql;
use RuntimeException;
use Throwable;

class NntmuxSearchReconcile extends Command
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_reconcile_parse_since(const char *input, size_t len, int64_t *amount);
        ssize_t nntmux_reconcile_missing_ids(const char *batch, size_t batch_count, const char *indexed, size_t indexed_count, char *out, size_t out_cap);
        ssize_t nntmux_reconcile_probe_sql(const char *index, size_t index_len, const char *ids, size_t count, char *out, size_t out_cap);
        C;

    /**
     * Smallest id list routed to Rust; below ~32 ids the FFI call and pack/unpack cost more
     * than PHP's own `array_diff()` / `implode()`.
     */
    public const int NATIVE_MIN_IDS = 64;

    private static FFI|false|null $reconcileFfi = null;

    private static ?CData $reconcileIntOut = null;

    private static ?bool $useRustFfi = null;

    private static bool $adoptMissingIdsFfi = true;

    private static bool $adoptProbeSqlFfi = true;

    private static bool $adoptParseSinceFfi = false;

    public const string W395_FFI_CDEF = 'ptrdiff_t nntmux_w39_admin_settings_monitoring_logs_reconcile(uint32_t mode, const char *input, size_t input_len, char *out, size_t cap);';

    /**
     * Rust stays opt-in for the Wave 39.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustAdminSettingsMonitoringLogsReconcileW39ParityTest.php.
     */
    public const bool W395_RUST_FFI_DEFAULT = false;

    private static ?bool $useW395RustFfi = null;

    private const int W395_NATIVE_MAX_PARTS = 1 << 20;

    private const int W395_NATIVE_MAX_BYTES = 16 * 1024 * 1024;

    private static FFI|false|null $w395Ffi = null;

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
                    foreach (self::missingIds($batch, $indexed) as $mid) {
                        $missingAll[] = $mid;
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
                        $missing = self::missingIds($ids, $indexed);
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
                            if (self::missingIds($missing, $this->fetchPredbIndexedIds($driver, $index, $missing)) !== []) {
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
        $window = self::parseSinceWindow($since);
        if ($window !== null) {
            return $window[0] === 'h' ? Carbon::now()->subHours($window[1]) : Carbon::now()->subDays($window[1]);
        }
        $since = trim($since);

        try {
            return Carbon::parse($since, config('app.timezone'));
        } catch (Throwable) {
            $this->warn("Could not parse --since={$since}; defaulting to 24 hours.");

            return Carbon::now()->subHours(24);
        }
    }

    public static function useW395RustFfi(?bool $use = true): void
    {
        self::$useW395RustFfi = $use;
    }

    public static function usesW395RustFfi(): bool
    {
        return self::$useW395RustFfi ?? self::W395_RUST_FFI_DEFAULT;
    }

    public static function resetW395RustFfi(): void
    {
        self::$useW395RustFfi = null;
    }

    /**
     * Plans an ID reconcile between MySQL release IDs and search index IDs. Only positive integers
     * are valid (others count as invalid); both sides are deduplicated. Missing (DB only) and orphan
     * (index only) IDs are sorted ascending and each repair list is capped at `$maxRepairs` (a
     * negative cap plans nothing).
     *
     * @param  array<array-key, mixed>  $dbIds
     * @param  array<array-key, mixed>  $indexIds
     * @return array{db: int, index: int, invalid: int, missing_in_index: int, orphans_in_index: int, in_sync: int, planned_upserts: int, planned_deletes: int, upsert_ids: list<int>, delete_ids: list<int>, truncated: bool}
     */
    public static function planReconcileIdDiff(array $dbIds, array $indexIds, int $maxRepairs = 1000): array
    {
        return self::usesW395RustFfi()
            ? self::planReconcileIdDiffNative($dbIds, $indexIds, $maxRepairs)
            : self::planReconcileIdDiffPhp($dbIds, $indexIds, $maxRepairs);
    }

    /**
     * @param  array<array-key, mixed>  $dbIds
     * @param  array<array-key, mixed>  $indexIds
     * @return array{db: int, index: int, invalid: int, missing_in_index: int, orphans_in_index: int, in_sync: int, planned_upserts: int, planned_deletes: int, upsert_ids: list<int>, delete_ids: list<int>, truncated: bool}
     */
    private static function planReconcileIdDiffNative(array $dbIds, array $indexIds, int $maxRepairs): array
    {
        $parts = [(string) $maxRepairs, (string) \count($dbIds)];
        foreach ([$dbIds, $indexIds] as $ids) {
            foreach ($ids as $id) {
                $parts[] = \is_int($id) ? (string) $id : '';
            }
        }
        $fields = self::evaluateW395Native(4, $parts);
        if ($fields === null || \count($fields) < 8 || \count($fields) !== 8 + (int) $fields[6] + (int) $fields[7]) {
            return self::planReconcileIdDiffPhp($dbIds, $indexIds, $maxRepairs);
        }
        $ids = array_map(intval(...), \array_slice($fields, 8));

        return self::idDiffPlan(
            (int) $fields[0],
            (int) $fields[1],
            (int) $fields[2],
            (int) $fields[3],
            (int) $fields[4],
            (int) $fields[5],
            \array_slice($ids, 0, (int) $fields[6]),
            \array_slice($ids, (int) $fields[6]),
        );
    }

    /**
     * @param  array<array-key, mixed>  $dbIds
     * @param  array<array-key, mixed>  $indexIds
     * @return array{db: int, index: int, invalid: int, missing_in_index: int, orphans_in_index: int, in_sync: int, planned_upserts: int, planned_deletes: int, upsert_ids: list<int>, delete_ids: list<int>, truncated: bool}
     */
    private static function planReconcileIdDiffPhp(array $dbIds, array $indexIds, int $maxRepairs): array
    {
        $invalid = 0;
        $sets = [[], []];
        foreach ([$dbIds, $indexIds] as $side => $ids) {
            foreach ($ids as $id) {
                if (\is_int($id) && $id > 0) {
                    $sets[$side][$id] = true;
                } else {
                    $invalid++;
                }
            }
        }
        [$db, $index] = $sets;
        $missing = array_keys(array_diff_key($db, $index));
        $orphans = array_keys(array_diff_key($index, $db));
        sort($missing);
        sort($orphans);
        $cap = max(0, $maxRepairs);

        return self::idDiffPlan(
            \count($db),
            \count($index),
            $invalid,
            \count($missing),
            \count($orphans),
            \count($db) - \count($missing),
            \array_slice($missing, 0, $cap),
            \array_slice($orphans, 0, $cap),
        );
    }

    /**
     * @param  list<int>  $upserts
     * @param  list<int>  $deletes
     * @return array{db: int, index: int, invalid: int, missing_in_index: int, orphans_in_index: int, in_sync: int, planned_upserts: int, planned_deletes: int, upsert_ids: list<int>, delete_ids: list<int>, truncated: bool}
     */
    private static function idDiffPlan(int $db, int $index, int $invalid, int $missing, int $orphans, int $inSync, array $upserts, array $deletes): array
    {
        return [
            'db' => $db,
            'index' => $index,
            'invalid' => $invalid,
            'missing_in_index' => $missing,
            'orphans_in_index' => $orphans,
            'in_sync' => $inSync,
            'planned_upserts' => \count($upserts),
            'planned_deletes' => \count($deletes),
            'upsert_ids' => $upserts,
            'delete_ids' => $deletes,
            'truncated' => \count($upserts) < $missing || \count($deletes) < $orphans,
        ];
    }

    /**
     * Shared Wave 39.5 bridge to `nntmux_w39_admin_settings_monitoring_logs_reconcile`. Returns the
     * decoded result fields, or null when the library is missing, the input is too large or Rust
     * declines (callers then use their PHP implementation).
     *
     * @param  list<string>  $parts
     * @return list<string>|null
     */
    public static function evaluateW395Native(int $mode, array $parts): ?array
    {
        $ffi = self::w395Ffi();
        if ($ffi === null || $mode < 0 || \count($parts) > self::W395_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = pack('V', \count($parts));
            foreach ($parts as $part) {
                $input .= pack('V', \strlen($part)).$part;
            }
            if (\strlen($input) > self::W395_NATIVE_MAX_BYTES) {
                return null;
            }

            $minBytes = \strlen($input) * 2 + 256;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $out = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
                if ($out === null) {
                    return null;
                }
                $capacity = min($capacity, self::W395_NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w39_admin_settings_monitoring_logs_reconcile($mode, $input, \strlen($input), $out, $capacity);
                if (! \is_int($written)) {
                    return null;
                }
                if ($written >= 4 && $written <= $capacity) {
                    return self::unpackW395(FFI::string($out, $written));
                }
                if ($written > -4 || -$written <= $capacity || -$written > self::W395_NATIVE_MAX_BYTES) {
                    return null;
                }
                $minBytes = -$written;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /** @return list<string>|null */
    private static function unpackW395(string $packet): ?array
    {
        $length = \strlen($packet);
        $header = unpack('V', $packet);
        $count = \is_array($header) ? $header[1] : null;
        if (! \is_int($count) || $count > intdiv($length - 4, 4)) {
            return null;
        }

        $offset = 4;
        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            if ($offset + 4 > $length) {
                return null;
            }
            $size = unpack('V', $packet, $offset);
            $size = \is_array($size) ? $size[1] : null;
            if (! \is_int($size) || $offset + 4 + $size > $length) {
                return null;
            }
            $parts[] = substr($packet, $offset + 4, $size);
            $offset += 4 + $size;
        }

        return $offset === $length ? $parts : null;
    }

    private static function w395Ffi(): ?FFI
    {
        if (self::$w395Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w395Ffi = RustPipelineLibrary::resolveDomain('search_w395', self::W395_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
        }

        return self::$w395Ffi instanceof FFI ? self::$w395Ffi : null;
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::$adoptMissingIdsFfi;
    }

    public static function reconcileFfi(): ?FFI
    {
        return self::resolveReconcileFfi();
    }

    /**
     * `--since` shorthand: `['h'|'d', amount]` for `<n>h` / `<n>d`, otherwise null.
     *
     * @return array{0: 'h'|'d', 1: int}|null
     */
    public static function parseSinceWindow(string $since): ?array
    {
        if (self::$useRustFfi ?? self::$adoptParseSinceFfi) {
            $supported = false;
            $native = self::parseSinceWindowNative($since, $supported);
            if ($supported) {
                return $native;
            }
        }

        return self::parseSinceWindowPhp($since);
    }

    /**
     * @param-out bool $supported
     *
     * @return array{0: 'h'|'d', 1: int}|null
     */
    public static function parseSinceWindowNative(string $since, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $ffi = self::resolveReconcileFfi();
        if ($ffi === null || self::$reconcileIntOut === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $kind = $ffi->nntmux_reconcile_parse_since($since, \strlen($since), self::$reconcileIntOut);
        } catch (Throwable) {
            return null;
        }
        if (! \in_array($kind, [0, 1, 2], true)) {
            return null;
        }
        $supported = true;
        if ($kind === 0) {
            return null;
        }

        return [$kind === 1 ? 'h' : 'd', (int) self::$reconcileIntOut[0]];
    }

    /**
     * @return array{0: 'h'|'d', 1: int}|null
     */
    public static function parseSinceWindowPhp(string $since): ?array
    {
        $since = trim($since);
        if (preg_match('/^(\d+)h$/i', $since, $m)) {
            return ['h', (int) $m[1]];
        }
        if (preg_match('/^(\d+)d$/i', $since, $m)) {
            return ['d', (int) $m[1]];
        }

        return null;
    }

    /**
     * Ids from `$batch` absent from `$indexed`, in batch order with duplicates kept.
     *
     * @param  list<int>  $batch
     * @param  list<int>  $indexed
     * @return list<int>
     */
    public static function missingIds(array $batch, array $indexed): array
    {
        if ((self::$useRustFfi ?? self::$adoptMissingIdsFfi) && \count($batch) >= self::NATIVE_MIN_IDS) {
            $supported = false;
            $native = self::missingIdsNative($batch, $indexed, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::missingIdsPhp($batch, $indexed);
    }

    /**
     * @param  list<int>  $batch
     * @param  list<int>  $indexed
     *
     * @param-out bool $supported
     *
     * @return list<int>|null
     */
    public static function missingIdsNative(array $batch, array $indexed, bool &$supported = false): ?array
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $count = \count($batch);
        if ($count === 0) {
            $supported = true;

            return [];
        }
        $ffi = self::resolveReconcileFfi();
        if ($ffi === null) {
            return null;
        }
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, $count * 8, $capacity);
        if ($scratch === null) {
            return null;
        }

        try {
            $packedBatch = pack('q*', ...$batch);
            $packedIndexed = $indexed === [] ? '' : pack('q*', ...$indexed);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_reconcile_missing_ids($packedBatch, $count, $packedIndexed, \count($indexed), $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($written) || $written < 0 || $written % 8 !== 0) {
            return null;
        }
        $supported = true;
        if ($written === 0) {
            return [];
        }

        /** @var list<int> */
        return array_values((array) unpack('q*', FFI::string($scratch, $written)));
    }

    /**
     * @param  list<int>  $batch
     * @param  list<int>  $indexed
     * @return list<int>
     */
    public static function missingIdsPhp(array $batch, array $indexed): array
    {
        $missing = [];
        foreach (array_diff($batch, $indexed) as $id) {
            $missing[] = (int) $id;
        }

        return $missing;
    }

    /**
     * Raw-mode id probe for `$ids` against `$index` with LIMIT and max_matches raised to the batch size.
     *
     * @param  list<int>  $ids
     */
    public static function indexedIdsProbeSql(string $index, array $ids): string
    {
        if ((self::$useRustFfi ?? self::$adoptProbeSqlFfi) && \count($ids) >= self::NATIVE_MIN_IDS) {
            $supported = false;
            $native = self::indexedIdsProbeSqlNative($index, $ids, $supported);
            if ($supported && $native !== null) {
                return $native;
            }
        }

        return self::indexedIdsProbeSqlPhp($index, $ids);
    }

    /**
     * @param  list<int>  $ids
     *
     * @param-out bool $supported
     */
    public static function indexedIdsProbeSqlNative(string $index, array $ids, bool &$supported = false): ?string
    {
        $supported = false;
        if (self::$useRustFfi === false) {
            return null;
        }
        $ffi = self::resolveReconcileFfi();
        if ($ffi === null) {
            return null;
        }
        $count = \count($ids);
        $indexLen = \strlen($index);
        $capacity = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 2 * $indexLen + 21 * $count + 96, $capacity);
        if ($scratch === null) {
            return null;
        }

        try {
            $packed = $count === 0 ? '' : pack('q*', ...$ids);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_reconcile_probe_sql($index, $indexLen, $packed, $count, $scratch, $capacity);
        } catch (Throwable) {
            return null;
        }
        if (! \is_int($written) || $written <= 0) {
            return null;
        }
        $supported = true;

        return FFI::string($scratch, $written);
    }

    /**
     * @param  list<int>  $ids
     */
    public static function indexedIdsProbeSqlPhp(string $index, array $ids): string
    {
        $list = implode(',', array_map(static fn (int $id): string => (string) $id, $ids));
        $safeIndex = str_replace('`', '``', $index);
        $limit = max(\count($ids), 1);

        return "SELECT id FROM `{$safeIndex}` WHERE id IN ({$list}) LIMIT {$limit} OPTION max_matches={$limit}";
    }

    private static function resolveReconcileFfi(): ?FFI
    {
        if (self::$reconcileFfi !== null) {
            return self::$reconcileFfi === false ? null : self::$reconcileFfi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF);
        if ($ffi === null) {
            self::$reconcileFfi = false;

            return null;
        }
        try {
            self::$reconcileIntOut = $ffi->new('int64_t[1]');
            self::$reconcileFfi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$reconcileFfi = false;

            return null;
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

        // Manticore SQL applies an implicit LIMIT 20 when no LIMIT clause is given, AND
        // max_matches defaults to 1000. Without explicitly raising both, our IN() probe
        // would only return the first 20 (or 1000) matches per batch, falsely flagging
        // the rest as missing. Use the actual batch size with a small ceiling cushion.
        $sql = self::indexedIdsProbeSql($index, $ids);

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

        $sql = self::indexedIdsProbeSql($index, $sampleIds);

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
