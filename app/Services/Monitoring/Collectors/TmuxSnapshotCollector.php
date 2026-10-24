<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\TmuxMetricsSnapshot;
use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Exposes the tmux monitor's processing statistics from its published snapshot.
 */
class TmuxSnapshotCollector implements MetricsCollector
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_tmux_collector_snapshot_age(int64_t collected_at, int64_t now);
        ssize_t nntmux_tmux_collector_classify_count_keys_nul(const char *input_ptr, size_t input_len, size_t count, int32_t *out_scalar_indices, int32_t *out_table_indices, int32_t *out_category_indices, char *out_buf, size_t out_cap);
        C;

    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private const array TABLES = ['collections_table', 'binaries_table', 'parts_table', 'missed_parts_table'];

    private const array TABLE_LABELS = ['collections', 'binaries', 'parts', 'missed_parts'];

    private const array CATEGORIES = ['tv', 'movies', 'audio', 'books', 'console', 'pc', 'xxx', 'misc'];

    private const array SCALAR_KEYS = [
        'total_work',
        'work',
        'work_available',
        'releases',
        'predb',
        'predb_matched',
        'nfo',
        'renamed',
        'active_groups',
        'all_groups',
    ];

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function categorizeFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }

        self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(private readonly TmuxMetricsSnapshot $snapshot) {}

    public function collect(): iterable
    {
        yield from $this->buildMetricFamilies($this->snapshot->read());
    }

    public function computeSnapshotAge(int $collectedAt, ?int $now = null): int
    {
        return self::$useRustFfi
            ? $this->computeSnapshotAgeNative($collectedAt, $now)
            : $this->computeSnapshotAgePhp($collectedAt, $now);
    }

    public function computeSnapshotAgePhp(int $collectedAt, ?int $now = null): int
    {
        return max(0, ($now ?? time()) - $collectedAt);
    }

    public function computeSnapshotAgeNative(int $collectedAt, ?int $now = null): int
    {
        $nowTs = $now ?? time();
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->computeSnapshotAgePhp($collectedAt, $nowTs);
        }

        // @phpstan-ignore method.notFound
        $res = $ffi->nntmux_tmux_collector_snapshot_age($collectedAt, $nowTs);
        if (! \is_int($res) || $res < 0) {
            return $this->computeSnapshotAgePhp($collectedAt, $nowTs);
        }

        return $res;
    }

    /**
     * Extract backlog, table row, category, and scalar gauge values from `$snapshot['counts']`.
     *
     * @param  array<int|string, int|float>  $counts
     * @return array{
     *     backlog: array<string, int|float>,
     *     tables: array<string, int|float>,
     *     categories: array<string, int|float>,
     *     scalars: array<string, int|float>
     * }
     */
    public function extractCountSeries(array $counts): array
    {
        return self::$useRustFfi
            ? $this->extractCountSeriesNative($counts)
            : $this->extractCountSeriesPhp($counts);
    }

    /**
     * @param  array<int|string, int|float>  $counts
     * @return array{
     *     backlog: array<string, int|float>,
     *     tables: array<string, int|float>,
     *     categories: array<string, int|float>,
     *     scalars: array<string, int|float>
     * }
     */
    public function extractCountSeriesPhp(array $counts): array
    {
        $backlog = [];
        foreach ($counts as $key => $value) {
            $strKey = (string) $key;
            if (str_starts_with($strKey, 'process')) {
                $backlog[substr($strKey, \strlen('process'))] = $value;
            }
        }

        $tables = [];
        foreach (self::TABLES as $table) {
            if (isset($counts[$table])) {
                $tables[substr($table, 0, -\strlen('_table'))] = $counts[$table];
            }
        }

        $categories = [];
        foreach (self::CATEGORIES as $category) {
            if (isset($counts[$category])) {
                $categories[$category] = $counts[$category];
            }
        }

        $scalars = [];
        foreach (self::SCALAR_KEYS as $scalarKey) {
            $scalars[$scalarKey] = $counts[$scalarKey] ?? 0;
        }

        return [
            'backlog' => $backlog,
            'tables' => $tables,
            'categories' => $categories,
            'scalars' => $scalars,
        ];
    }

    /**
     * @param  array<int|string, int|float>  $counts
     * @return array{
     *     backlog: array<string, int|float>,
     *     tables: array<string, int|float>,
     *     categories: array<string, int|float>,
     *     scalars: array<string, int|float>
     * }
     */
    public function extractCountSeriesNative(array $counts): array
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->extractCountSeriesPhp($counts);
        }

        if ($counts === []) {
            $scalars = [];
            foreach (self::SCALAR_KEYS as $scalarKey) {
                $scalars[$scalarKey] = 0;
            }

            return [
                'backlog' => [],
                'tables' => [],
                'categories' => [],
                'scalars' => $scalars,
            ];
        }

        $keys = [];
        $values = [];
        foreach ($counts as $k => $v) {
            $strKey = (string) $k;
            if (str_contains($strKey, "\0") || str_contains($strKey, "\n")) {
                return $this->extractCountSeriesPhp($counts);
            }
            $keys[] = $strKey;
            $values[] = $v;
        }

        $count = \count($keys);
        $joined = implode("\0", $keys);
        $len = \strlen($joined);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + $count * 8 + 32), $cap);
        if ($scratch === null) {
            return $this->extractCountSeriesPhp($counts);
        }
        $scalarIdxBuf = $ffi->new('int32_t[10]');
        $tableIdxBuf = $ffi->new('int32_t[4]');
        $catIdxBuf = $ffi->new('int32_t[8]');

        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_tmux_collector_classify_count_keys_nul(
            $joined,
            $len,
            $count,
            $scalarIdxBuf,
            $tableIdxBuf,
            $catIdxBuf,
            $scratch,
            $cap
        );
        if (\is_int($written) && $written < -1) {
            $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
            if ($scratch === null) {
                return $this->extractCountSeriesPhp($counts);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tmux_collector_classify_count_keys_nul(
                $joined,
                $len,
                $count,
                $scalarIdxBuf,
                $tableIdxBuf,
                $catIdxBuf,
                $scratch,
                $cap
            );
        }
        if (! \is_int($written) || $written < 0) {
            return $this->extractCountSeriesPhp($counts);
        }

        $backlog = [];
        if ($written > 0) {
            $lines = explode("\n", FFI::string($scratch, $written));
            foreach ($lines as $line) {
                $tab = strpos($line, "\t");
                if ($tab === false) {
                    return $this->extractCountSeriesPhp($counts);
                }
                $idx = (int) substr($line, 0, $tab);
                $type = substr($line, $tab + 1);
                if (! isset($values[$idx])) {
                    return $this->extractCountSeriesPhp($counts);
                }
                $backlog[$type] = $values[$idx];
            }
        }

        $tables = [];
        for ($i = 0; $i < 4; $i++) {
            /** @var int $idx */
            $idx = $tableIdxBuf[$i];
            if ($idx >= 0 && isset($values[$idx])) {
                $tables[self::TABLE_LABELS[$i]] = $values[$idx];
            }
        }

        $categories = [];
        for ($i = 0; $i < 8; $i++) {
            /** @var int $idx */
            $idx = $catIdxBuf[$i];
            if ($idx >= 0 && isset($values[$idx])) {
                $categories[self::CATEGORIES[$i]] = $values[$idx];
            }
        }

        $scalars = [];
        for ($i = 0; $i < 10; $i++) {
            /** @var int $idx */
            $idx = $scalarIdxBuf[$i];
            $scalars[self::SCALAR_KEYS[$i]] = ($idx >= 0 && isset($values[$idx])) ? $values[$idx] : 0;
        }

        return [
            'backlog' => $backlog,
            'tables' => $tables,
            'categories' => $categories,
            'scalars' => $scalars,
        ];
    }

    /**
     * @param  array{
     *     collected_at: int,
     *     is_running: int,
     *     counts: array<string, int|float>,
     *     connections: array<string, array{active: int, total: int}>,
     *     query_timers: array<string, int|float>
     * }|null  $snapshot
     * @return list<MetricFamily>
     */
    public function buildMetricFamilies(?array $snapshot, ?int $now = null): array
    {
        return self::$useRustFfi
            ? $this->buildMetricFamiliesNative($snapshot, $now)
            : $this->buildMetricFamiliesPhp($snapshot, $now);
    }

    /**
     * @param  array{
     *     collected_at: int,
     *     is_running: int,
     *     counts: array<string, int|float>,
     *     connections: array<string, array{active: int, total: int}>,
     *     query_timers: array<string, int|float>
     * }|null  $snapshot
     * @return list<MetricFamily>
     */
    public function buildMetricFamiliesPhp(?array $snapshot, ?int $now = null): array
    {
        return $this->assembleMetricFamilies(
            $snapshot,
            $snapshot === null ? 0 : $this->computeSnapshotAgePhp($snapshot['collected_at'], $now),
            $snapshot === null ? null : $this->extractCountSeriesPhp($snapshot['counts'])
        );
    }

    /**
     * @param  array{
     *     collected_at: int,
     *     is_running: int,
     *     counts: array<string, int|float>,
     *     connections: array<string, array{active: int, total: int}>,
     *     query_timers: array<string, int|float>
     * }|null  $snapshot
     * @return list<MetricFamily>
     */
    public function buildMetricFamiliesNative(?array $snapshot, ?int $now = null): array
    {
        return $this->assembleMetricFamilies(
            $snapshot,
            $snapshot === null ? 0 : $this->computeSnapshotAgeNative($snapshot['collected_at'], $now),
            $snapshot === null ? null : $this->extractCountSeriesNative($snapshot['counts'])
        );
    }

    /**
     * @param  array{
     *     collected_at: int,
     *     is_running: int,
     *     counts: array<string, int|float>,
     *     connections: array<string, array{active: int, total: int}>,
     *     query_timers: array<string, int|float>
     * }|null  $snapshot
     * @param  array{
     *     backlog: array<string, int|float>,
     *     tables: array<string, int|float>,
     *     categories: array<string, int|float>,
     *     scalars: array<string, int|float>
     * }|null  $series
     * @return list<MetricFamily>
     */
    private function assembleMetricFamilies(?array $snapshot, int $ageSeconds, ?array $series): array
    {
        $families = [
            MetricFamily::gauge('nntmux_tmux_snapshot_available', 'Whether a recent tmux monitor snapshot exists.')
                ->add($snapshot === null ? 0 : 1),
        ];

        if ($snapshot === null || $series === null) {
            return $families;
        }

        $scalars = $series['scalars'];

        $families[] = MetricFamily::gauge('nntmux_tmux_snapshot_age_seconds', 'Seconds since the tmux monitor last published statistics.')
            ->add($ageSeconds);

        $families[] = MetricFamily::gauge('nntmux_tmux_running', 'Whether tmux processing is running (1) or paused (0).')
            ->add($snapshot['is_running']);

        $backlog = MetricFamily::gauge('nntmux_processing_backlog', 'Releases waiting for post-processing, by type.');
        foreach ($series['backlog'] as $type => $value) {
            $backlog->add($value, ['type' => $type]);
        }
        $families[] = $backlog;

        $families[] = MetricFamily::gauge('nntmux_processing_total_work', 'Sum of all post-processing backlogs.')
            ->add($scalars['total_work']);

        $families[] = MetricFamily::gauge('nntmux_additional_backlog', 'Releases waiting for additional processing.')
            ->add($scalars['work']);

        $families[] = MetricFamily::gauge('nntmux_additional_backlog_available', 'Additional-processing backlog that is currently claimable.')
            ->add($scalars['work_available']);

        $tables = MetricFamily::gauge('nntmux_table_rows', 'Estimated rows in the binary processing tables.');
        foreach ($series['tables'] as $table => $value) {
            $tables->add($value, ['table' => $table]);
        }
        $families[] = $tables;

        $families[] = MetricFamily::gauge('nntmux_releases', 'Total releases.')->add($scalars['releases']);

        $categories = MetricFamily::gauge('nntmux_releases_by_category', 'Releases per parent category.');
        foreach ($series['categories'] as $category => $value) {
            $categories->add($value, ['category' => $category]);
        }
        $families[] = $categories;

        $families[] = MetricFamily::gauge('nntmux_predb_entries', 'PreDB entries.')->add($scalars['predb']);
        $families[] = MetricFamily::gauge('nntmux_predb_matched_releases', 'Releases matched to a PreDB entry.')->add($scalars['predb_matched']);
        $families[] = MetricFamily::gauge('nntmux_releases_with_nfo', 'Releases with an NFO.')->add($scalars['nfo']);
        $families[] = MetricFamily::gauge('nntmux_releases_renamed', 'Releases renamed by the name fixer.')->add($scalars['renamed']);

        $families[] = MetricFamily::gauge('nntmux_groups', 'Usenet groups by state.')
            ->add($scalars['active_groups'], ['state' => 'active'])
            ->add($scalars['all_groups'], ['state' => 'all']);

        $connections = MetricFamily::gauge('nntmux_nntp_connections', 'NNTP connections held by this host.');
        foreach ($snapshot['connections'] as $server => $connectionCounts) {
            $connections
                ->add($connectionCounts['active'], ['server' => $server, 'state' => 'active'])
                ->add($connectionCounts['total'], ['server' => $server, 'state' => 'total']);
        }
        $families[] = $connections;

        $timers = MetricFamily::gauge('nntmux_tmux_query_duration_seconds', 'Duration of the tmux monitor statistics queries.');
        foreach ($snapshot['query_timers'] as $query => $seconds) {
            $timers->add($seconds, ['query' => $query]);
        }
        $families[] = $timers;

        return $families;
    }
}
