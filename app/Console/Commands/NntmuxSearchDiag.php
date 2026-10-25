<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Release;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Console\Command;
use Throwable;

class NntmuxSearchDiag extends Command
{
    /**
     * Rust stays opt-in for the Wave 39.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustAdminSettingsMonitoringLogsReconcileW39ParityTest.php.
     */
    public const bool W395_RUST_FFI_DEFAULT = false;

    private static ?bool $useW395RustFfi = null;

    protected $signature = 'nntmux:search-diag
                            {ids?* : Release numeric ids or guids}
                            {--name= : Match releases where name or searchname contains this substring (MySQL)}
                            {--limit=20 : Max rows when using --name}
                            {--show-fields : Dump DB row and index document as JSON lines}';

    protected $description = 'Compare releases table rows to Manticore releases_rt documents (diagnostics)';

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
     * Summarizes search index shard diagnostics. A shard is online only when `online` is `true`;
     * `docs` and `lag` count when they are integers (negative values as 0). Non-array entries are
     * malformed and offline. Online shards whose lag exceeds `$maxLagDocs` are lagging; the cluster
     * is ready with at least one shard, none offline and none lagging. Document totals saturate at
     * PHP_INT_MAX and exclude malformed entries.
     *
     * @param  array<array-key, mixed>  $shardMetrics
     * @return array{shards: int, online: int, offline: int, malformed: int, lagging: int, total_docs: int, max_lag: int, cluster_ready: bool}
     */
    public static function summarizeIndexShardHealth(array $shardMetrics, int $maxLagDocs = 500): array
    {
        return self::usesW395RustFfi()
            ? self::summarizeIndexShardHealthNative($shardMetrics, $maxLagDocs)
            : self::summarizeIndexShardHealthPhp($shardMetrics, $maxLagDocs);
    }

    /**
     * @param  array<array-key, mixed>  $shardMetrics
     * @return array{shards: int, online: int, offline: int, malformed: int, lagging: int, total_docs: int, max_lag: int, cluster_ready: bool}
     */
    private static function summarizeIndexShardHealthNative(array $shardMetrics, int $maxLagDocs): array
    {
        $parts = [(string) $maxLagDocs];
        foreach ($shardMetrics as $shard) {
            if (! \is_array($shard)) {
                array_push($parts, 'm', '', '');

                continue;
            }
            $docs = $shard['docs'] ?? null;
            $lag = $shard['lag'] ?? null;
            array_push($parts, ($shard['online'] ?? null) === true ? '1' : '0', \is_int($docs) ? (string) $docs : '', \is_int($lag) ? (string) $lag : '');
        }
        $fields = NntmuxSearchReconcile::evaluateW395Native(5, $parts);
        if ($fields === null || \count($fields) !== 8) {
            return self::summarizeIndexShardHealthPhp($shardMetrics, $maxLagDocs);
        }

        return [
            'shards' => (int) $fields[0],
            'online' => (int) $fields[1],
            'offline' => (int) $fields[2],
            'malformed' => (int) $fields[3],
            'lagging' => (int) $fields[4],
            'total_docs' => (int) $fields[5],
            'max_lag' => (int) $fields[6],
            'cluster_ready' => $fields[7] === '1',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $shardMetrics
     * @return array{shards: int, online: int, offline: int, malformed: int, lagging: int, total_docs: int, max_lag: int, cluster_ready: bool}
     */
    private static function summarizeIndexShardHealthPhp(array $shardMetrics, int $maxLagDocs): array
    {
        $online = $offline = $malformed = $lagging = $total = $worst = 0;
        foreach ($shardMetrics as $shard) {
            if (! \is_array($shard)) {
                $offline++;
                $malformed++;

                continue;
            }
            if (($shard['online'] ?? null) === true) {
                $online++;
                $lag = \is_int($shard['lag'] ?? null) ? max(0, $shard['lag']) : 0;
                $worst = max($worst, $lag);
                if ($lag > $maxLagDocs) {
                    $lagging++;
                }
            } else {
                $offline++;
            }
            $docs = \is_int($shard['docs'] ?? null) ? max(0, $shard['docs']) : 0;
            $total = $docs > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $docs;
        }
        $count = \count($shardMetrics);

        return [
            'shards' => $count,
            'online' => $online,
            'offline' => $offline,
            'malformed' => $malformed,
            'lagging' => $lagging,
            'total_docs' => $total,
            'max_lag' => $worst,
            'cluster_ready' => $count > 0 && $offline === 0 && $lagging === 0,
        ];
    }

    public function handle(ManticoreSearchDriver $manticore): int
    {
        if (config('search.default') !== 'manticore') {
            $this->error('This command only supports SEARCH_DRIVER=manticore.');

            return self::FAILURE;
        }

        if (! $manticore->isAvailable()) {
            $this->error('Manticore is not reachable (isAvailable() returned false).');

            return self::FAILURE;
        }

        $index = $manticore->getReleasesIndex();
        $nameFilter = (string) $this->option('name');
        $ids = $this->argument('ids');

        $releaseIds = [];
        if ($nameFilter !== '') {
            $limit = max(1, min(500, (int) $this->option('limit')));
            $like = '%'.addcslashes($nameFilter, '%_\\').'%';
            $releaseIds = Release::query()
                ->where(function ($q) use ($like): void {
                    $q->where('name', 'LIKE', $like)
                        ->orWhere('searchname', 'LIKE', $like);
                })
                ->orderByDesc('id')
                ->limit($limit)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if ($releaseIds === []) {
                $this->warn('No releases matched --name filter.');

                return self::SUCCESS;
            }
        } else {
            if ($ids === [] || $ids === null) {
                $this->error('Provide at least one id/guid or use --name=');

                return self::FAILURE;
            }
            foreach ($ids as $token) {
                $resolved = $this->resolveReleaseId((string) $token);
                if ($resolved === null) {
                    $this->warn("No release found for token: {$token}");

                    continue;
                }
                $releaseIds[] = $resolved;
            }
        }

        if ($releaseIds === []) {
            return self::FAILURE;
        }

        $showPasswords = (int) app(ConfigurationProvider::class)->ingestion()->showPasswordedReleases === 1;
        $verbose = (bool) $this->option('show-fields');

        foreach ($releaseIds as $rid) {
            $this->diagnoseOne((int) $rid, $manticore, $index, $showPasswords, $verbose);
        }

        return self::SUCCESS;
    }

    private function resolveReleaseId(string $token): ?int
    {
        if (ctype_digit($token)) {
            $id = (int) $token;
            $exists = Release::query()->whereKey($id)->exists();

            return $exists ? $id : null;
        }

        return Release::query()->where('guid', $token)->value('id');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manticoreSqlRows(ManticoreSearchDriver $driver, string $sql): array
    {
        try {
            $response = $driver->manticoreSearch->sql($sql, true);
        } catch (Throwable $e) {
            $this->error('Manticore SQL error: '.$e->getMessage());

            return [];
        }

        return $this->extractSqlRows($response);
    }

    /** @return array<int, array<string, mixed>> */
    public function extractSqlRows(mixed $response, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? $this->manticoreSqlRowsNative($response) : null) ?? $this->manticoreSqlRowsPhp($response);
    }

    /** @return array<int, array<string, mixed>>|null */
    public function manticoreSqlRowsNative(mixed $response): ?array
    {
        // Non-array responses cannot contain SQL rows. Objects inside arrays must
        // retain PHP identity rather than being converted by a JSON round trip.
        if (! is_array($response)) {
            return [];
        }
        if (self::hasObjects($response)) {
            return null;
        }
        $payload = json_encode($response, JSON_PRESERVE_ZERO_FRACTION);
        if ($payload === false) {
            return null;
        }

        /** @var array<int, array<string, mixed>>|null */
        return self::nativeJson('nntmux_search_diag_extract_sql_rows_json', $payload);
    }

    /** @return array<int, array<string, mixed>> */
    public function manticoreSqlRowsPhp(mixed $response): array
    {
        if (! \is_array($response)) {
            return [];
        }

        // In raw mode (sql($q, true)) the response goes through
        // Manticoresearch\Response\SqlToArray::getResponse(), which flattens a SELECT
        // result so each row is keyed by its `id` (and the `id` column is removed from
        // multi-column rows). Reconstruct row arrays here. The legacy
        // ['data' => [...]] shape is retained as a fallback.
        $rows = [];
        foreach ($response as $key => $value) {
            if (\is_int($key) || ctype_digit($key)) {
                if (\is_array($value)) {
                    $row = $value;
                    if (! isset($row['id'])) {
                        $row['id'] = (int) $key;
                    }
                    $rows[] = $row;
                } else {
                    // Single-column id projection: value mirrors the key.
                    $rows[] = ['id' => (int) $key];
                }
            }
        }

        if ($rows !== []) {
            return $rows;
        }

        if (isset($response['data']) && \is_array($response['data'])) {
            return array_values($response['data']);
        }

        return [];
    }

    private function diagnoseOne(
        int $releaseId,
        ManticoreSearchDriver $driver,
        string $index,
        bool $showPasswords,
        bool $verbose,
    ): void {
        $release = Release::query()->find($releaseId);
        if ($release === null) {
            $this->line(sprintf('%d | NOT_IN_DB', $releaseId));

            return;
        }

        $sql = sprintf('SELECT * FROM `%s` WHERE id = %d', str_replace('`', '``', $index), $releaseId);
        $rows = $this->manticoreSqlRows($driver, $sql);
        $indexRow = $rows[0] ?? null;

        $status = $this->classify($release, $indexRow, $showPasswords, $verbose);

        $this->line(sprintf(
            '%d | %s | searchname=%s | size=%s | passwordstatus=%s',
            $releaseId,
            $status,
            $release->searchname,
            (string) $release->size,
            (string) $release->passwordstatus
        ));

        if ($verbose) {
            $this->line('DB: '.json_encode($release->only([
                'id', 'name', 'searchname', 'categories_id', 'passwordstatus', 'nzbstatus',
                'groups_id', 'size', 'postdate', 'adddate',
            ])));
            $this->line('IX: '.json_encode($indexRow));
        }
    }

    /**
     * @param  array<string, mixed>|null  $indexRow
     */
    private function classify(Release $release, ?array $indexRow, bool $showPasswords, bool $verbose): string
    {
        if ($indexRow === null) {
            return 'MISSING_IN_INDEX';
        }
        $expected = ReleaseSearchIndexDocument::normalize($release->toArray());
        unset($expected['id']);
        // The measured candidate is selected inside classifyFields; normalization and
        // verbose output remain shared by both paths.
        [$status, $mismatches] = $this->classifyFields($expected, $indexRow, $showPasswords);
        if ($verbose) {
            foreach ($mismatches as $field) {
                if ($field !== 'postdate_ts' && $field !== 'adddate_ts') {
                    $this->line("  drift: {$field} db=".json_encode($expected[$field] ?? null).' ix='.json_encode($indexRow[$field] ?? null));
                }
            }
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $indexRow
     * @return array{string, list<string>}
     */
    public function classifyFields(array $expected, ?array $indexRow, bool $showPasswords, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? $this->classifyNative($expected, $indexRow, $showPasswords) : null)
            ?? $this->classifyPhp($expected, $indexRow, $showPasswords);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $indexRow
     * @return array{string, list<string>}
     */
    public function classifyPhp(array $expected, ?array $indexRow, bool $showPasswords): array
    {
        if ($indexRow === null) {
            return ['MISSING_IN_INDEX', []];
        }
        $mismatches = [];
        foreach (['searchname', 'name', 'categories_id', 'passwordstatus', 'size', 'groups_id', 'nzbstatus'] as $field) {
            if ((string) ($expected[$field] ?? null) !== (string) ($indexRow[$field] ?? null)) {
                $mismatches[] = $field;
            }
        }
        foreach (['postdate_ts', 'adddate_ts'] as $field) {
            if (abs((int) ($expected[$field] ?? 0) - (int) ($indexRow[$field] ?? 0)) > 2) {
                $mismatches[] = $field;
            }
        }
        $hidden = (int) ($expected['passwordstatus'] ?? 0) > ($showPasswords ? 1 : 0);

        return [$mismatches !== [] ? 'STALE_INDEX' : ($hidden ? 'STATUS_HIDDEN' : 'OK'), $mismatches];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $indexRow
     * @return array{string, list<string>}|null
     */
    public function classifyNative(array $expected, ?array $indexRow, bool $showPasswords): ?array
    {
        $payload = null;
        if ($indexRow !== null) {
            $db = $ix = [];
            foreach (['searchname', 'name', 'categories_id', 'passwordstatus', 'size', 'groups_id', 'nzbstatus'] as $field) {
                $db[] = (string) ($expected[$field] ?? null);
                $ix[] = (string) ($indexRow[$field] ?? null);
            }
            $payload = [(int) ($expected['passwordstatus'] ?? 0) > ($showPasswords ? 1 : 0), $db, $ix,
                (int) ($expected['postdate_ts'] ?? 0), (int) ($indexRow['postdate_ts'] ?? 0),
                (int) ($expected['adddate_ts'] ?? 0), (int) ($indexRow['adddate_ts'] ?? 0)];
        }
        $json = json_encode($payload);
        if ($json === false) {
            return null;
        }

        /** @var array{string, list<string>}|null */
        return self::nativeJson('nntmux_search_diag_classify_json', $json);
    }

    /** @param array<mixed> $values */
    private static function hasObjects(array $values, int $depth = 0): bool
    {
        if ($depth > 64) {
            return true;
        }
        foreach ($values as $value) {
            if (is_object($value) || is_resource($value) || (is_array($value) && self::hasObjects($value, $depth + 1))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<mixed>|null */
    private static function nativeJson(string $symbol, string $payload): ?array
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', <<<'C'
            ssize_t nntmux_search_diag_classify_json(const char *input, size_t len, char *out, size_t cap);
            ssize_t nntmux_search_diag_extract_sql_rows_json(const char *input, size_t len, char *out, size_t cap);
            C) ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $length = strlen($payload);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $length + 128), $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->$symbol($payload, $length, $scratch, $cap);
            if (is_int($written) && $written < -3) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written - 3, $cap);
                if ($scratch === null) {
                    return null;
                }
                $written = $ffi->$symbol($payload, $length, $scratch, $cap);
            }
            if (! is_int($written) || $written < 0 || $written > $cap) {
                return null;
            }
            $result = json_decode(FFI::string($scratch, $written), true, 512, JSON_THROW_ON_ERROR);

            return is_array($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }
}
