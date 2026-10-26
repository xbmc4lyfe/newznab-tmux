<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Services\Monitoring\TmuxMetricsSnapshot;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

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

    // ---- Wave 57.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 5.92x/4.86x, uncommon 5.83x/4.65x); see RustMcpPathGuardRepoContextRedactorStatusTmuxCollectorW57ParityTest. */
    private const bool W57_RUST_FFI_DEFAULT = true;

    private const string W57_FFI_CDEF = <<<'C'
        intptr_t nntmux_w57_tmux_count_key_plan_batch(const char *keys, size_t keys_len, size_t count, int64_t collected_at, int64_t now, int64_t stale_after, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 57 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w57', self::W57_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w57RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planCountKeyBatch() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w57Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w57Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch plan over snapshot count keys, filed as extractCountSeriesPhp() files them, plus
     * the snapshot's freshness. Per key: `class | index << 3 | duplicate << 7 | min(length,
     * 0xFFFFFF) << 8 | crc32(label) << 32`. Class: 1 backlog (`process*`, labelled by the
     * suffix), 2 table (labelled without `_table`), 3 category, 4 scalar (labelled by the key),
     * 0 other (empty label); index is the position in its list. The five class counts, the
     * duplicate keys, the snapshot age (`max(0, $now - $collectedAt)`, saturating), whether it
     * exceeds `max(0, $staleAfter)` and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function planCountKeyBatch(array $keys, int $collectedAt, int $now, int $staleAfter): array
    {
        return self::usesW57RustFfi() ? self::planCountKeyBatchNative($keys, $collectedAt, $now, $staleAfter)
            : self::planCountKeyBatchPhp($keys, $collectedAt, $now, $staleAfter);
    }

    /**
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function planCountKeyBatchPhp(array $keys, int $collectedAt, int $now, int $staleAfter): array
    {
        $out = $seen = [];
        $classes = array_fill(0, 5, 0);
        $duplicates = 0;
        foreach ($keys as $key) {
            $key = (string) $key;
            [$class, $index, $label] = [0, 0, ''];
            if (str_starts_with($key, 'process')) {
                [$class, $label] = [1, substr($key, \strlen('process'))];
            } elseif (($found = array_search($key, self::TABLES, true)) !== false) {
                [$class, $index, $label] = [2, $found, self::TABLE_LABELS[$found]];
            } elseif (($found = array_search($key, self::CATEGORIES, true)) !== false) {
                [$class, $index, $label] = [3, $found, $key];
            } elseif (($found = array_search($key, self::SCALAR_KEYS, true)) !== false) {
                [$class, $index, $label] = [4, $found, $key];
            }
            $duplicate = isset($seen[$key]);
            $seen[$key] = true;
            $classes[$class]++;
            $duplicates += $duplicate ? 1 : 0;
            $out[] = $class | $index << 3 | ($duplicate ? 128 : 0) | min(\strlen($label), 0xFFFFFF) << 8 | crc32($label) << 32;
        }
        $age = match (true) {
            $collectedAt >= $now => 0,
            $collectedAt < 0 && $now > PHP_INT_MAX + $collectedAt => PHP_INT_MAX,
            default => $now - $collectedAt,
        };
        array_push($out, ...$classes);
        array_push($out, $duplicates, $age, $age > max(0, $staleAfter) ? 1 : 0);
        $out[] = self::w57Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a key holds a NUL byte or the
     * column is not a list.
     *
     * @param  list<string>  $keys
     * @return list<int>
     */
    public static function planCountKeyBatchNative(array $keys, int $collectedAt, int $now, int $staleAfter): array
    {
        $count = \count($keys);
        $words = $count + 9;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w57RustFfiState('out', $words);
        if ($state === null || ! array_is_list($keys)) {
            return self::planCountKeyBatchPhp($keys, $collectedAt, $now, $staleAfter);
        }
        $blob = implode("\0", $keys);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_tmux_count_key_plan_batch($blob, \strlen($blob), $count, $collectedAt, $now, $staleAfter, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w57Words($written, $out, $words) ?? self::planCountKeyBatchPhp($keys, $collectedAt, $now, $staleAfter);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 9.13x, p99 7.72x; uncommon mean 6.29x, p99 4.32x); see RustMonitoringHorizonVerificationStatusTmuxGrafanaEmbedW68ParityTest. */
    public const bool W68_RUST_FFI_DEFAULT = true;

    public const string W68_TMUX_SNAPSHOT_COLLECTOR_FFI_CDEF = <<<'C'
        ssize_t nntmux_w683_tmux_snapshot_collector_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 68.3 batch. */
    public const int W68_TMUX_SNAPSHOT_COLLECTOR_TRAILER = 24;

    public static function enableW68RustFfi(): void
    {
        self::w68RustFfiState('set', true);
    }

    public static function disableW68RustFfi(): void
    {
        self::w68RustFfiState('set', false);
    }

    /** Restores W68_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW68RustFfi(): void
    {
        self::w68RustFfiState('reset');
    }

    public static function usesW68RustFfi(): bool
    {
        $override = self::w68RustFfiState();

        return \is_bool($override) ? $override : self::W68_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the TmuxSnapshotCollector Wave 68 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW68RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w68RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w68RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 68.3 tmux pane metrics plan. Each item is `pane|command|lastActivityAgeSeconds|backlog|processed|active`.
     * The trimmed pane is 0 empty, 1 a `session:window.pane` target (session `[A-Za-z0-9_-]+`, one to four digit
     * indexes) or 2 other. The command is matched caselessly after trimming (0 empty, 1-10 update_binaries,
     * backfill, releases, postprocess, fixnames, removecrap, predb, sharing, idle, monitor, 11 other) and maps to
     * metric category 0 none, 1 binaries, 2 releases, 3 post-processing, 4 maintenance, 5 idle or 6 other. Age (10
     * digits), backlog and processed (12) are 0 empty, 1 digits or 2 other; active is 0 empty, 1 `1`, 2 `0` or 3
     * other. The staleness window is 0 fresh (unmeasured or up to W68_TMUX_FRESH_SECONDS), 1 idle (to
     * W68_TMUX_IDLE_SECONDS), 2 stale (to W68_TMUX_STALE_SECONDS) or 3 dead. Decision 6 malformed, 0 skipped (no
     * pane), 1 idle (no or idle command), 2 dead, 3 stalled (backlog in a stale window), 4 busy (active with backlog)
     * or 5 ok. The rate is processed * 60 / age per minute (processed when the age is zero or unmeasured). Per item
     * `paneState | command << 2 | category << 6 | ageState << 9 | backlogState << 11 | processedState << 13 | active
     * << 15 | window << 17 | decision << 19 | windowIndex (cap 255) << 22 | paneIndex (cap 255) << 30 | rate (cap
     * 2^20 - 1) << 38`. Then the seven decision, four window and seven category counts, the backlog and processed
     * sums and oldest measured age (non-malformed), the 1-based oldest stale or dead item, the active panes and the
     * distinct sessions.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68TmuxSnapshotCollectorPlanBatch(array $items): array
    {
        return self::usesW68RustFfi() ? self::w68TmuxSnapshotCollectorPlanBatchNative($items) : self::w68TmuxSnapshotCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68TmuxSnapshotCollectorPlanBatchNative(array $items): array
    {
        return self::w68Invoke($items, \count($items) + self::W68_TMUX_SNAPSHOT_COLLECTOR_TRAILER) ?? self::w68TmuxSnapshotCollectorPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w68TmuxSnapshotCollectorPlanBatchPhp(array $items): array
    {
        $out = [];
        $decisions = [0, 0, 0, 0, 0, 0, 0];
        $windows = [0, 0, 0, 0];
        $categories = [0, 0, 0, 0, 0, 0, 0];
        $backlogSum = $processedSum = $maxAge = $staleAge = $oldestStale = $activeCount = 0;
        $sessions = [];
        $index = 0;
        foreach ($items as $item) {
            [$rawPane, $rawCommand, $rawAge, $rawBacklog, $rawProcessed, $rawActive] = explode('|', (string) $item, 6) + ['', '', '', '', '', ''];
            $pane = trim($rawPane);
            $windowIndex = $paneIndex = 0;
            if ($pane === '') {
                $paneState = 0;
            } elseif (preg_match('/\A([A-Za-z0-9_-]+):(\d{1,4})\.(\d{1,4})\z/', $pane, $target) === 1) {
                $paneState = 1;
                $windowIndex = (int) $target[2];
                $paneIndex = (int) $target[3];
                $sessions[$target[1]] = true;
            } else {
                $paneState = 2;
            }
            $command = self::w68Keyword($rawCommand, self::W68_TMUX_COMMANDS);
            $category = self::W68_TMUX_CATEGORIES[$command];
            [$ageState, $age] = self::w68Count($rawAge, 10);
            [$backlogState, $backlog] = self::w68Count($rawBacklog, 12);
            [$processedState, $processed] = self::w68Count($rawProcessed, 12);
            $active = match (self::w68Count($rawActive, 1)) {
                [0, 0] => 0,
                [1, 1] => 1,
                [1, 0] => 2,
                default => 3,
            };
            if ($ageState !== 1 || $age <= self::W68_TMUX_FRESH_SECONDS) {
                $window = 0;
            } elseif ($age <= self::W68_TMUX_IDLE_SECONDS) {
                $window = 1;
            } elseif ($age <= self::W68_TMUX_STALE_SECONDS) {
                $window = 2;
            } else {
                $window = 3;
            }
            if ($paneState === 2 || $ageState === 2 || $backlogState === 2 || $processedState === 2 || $active === 3) {
                $decision = 6;
            } elseif ($paneState === 0) {
                $decision = 0;
            } elseif ($category === 0 || $category === 5) {
                $decision = 1;
            } elseif ($window === 3) {
                $decision = 2;
            } elseif ($backlog > 0 && $window === 2) {
                $decision = 3;
            } elseif ($active === 1 && $backlog > 0) {
                $decision = 4;
            } else {
                $decision = 5;
            }
            $rate = $ageState === 1 && $age > 0 ? intdiv($processed * 60, $age) : $processed;
            $decisions[$decision]++;
            $windows[$window]++;
            $categories[$category]++;
            $activeCount += $active === 1 ? 1 : 0;
            if ($decision !== 6) {
                $backlogSum = min($backlogSum + $backlog, self::W68_TOTAL_CAP);
                $processedSum = min($processedSum + $processed, self::W68_TOTAL_CAP);
                if ($ageState === 1) {
                    $maxAge = max($maxAge, $age);
                }
                if ($window >= 2 && ($oldestStale === 0 || $age > $staleAge)) {
                    $staleAge = $age;
                    $oldestStale = $index + 1;
                }
            }
            $out[] = $paneState | $command << 2 | $category << 6 | $ageState << 9 | $backlogState << 11 | $processedState << 13 | $active << 15 | $window << 17
                | $decision << 19 | min($windowIndex, 255) << 22 | min($paneIndex, 255) << 30 | min($rate, 1_048_575) << 38;
            $index++;
        }
        array_push($out, ...$decisions);
        array_push($out, ...$windows);
        array_push($out, ...$categories);
        array_push($out, $backlogSum, $processedSum, $maxAge, $oldestStale, $activeCount, \count($sessions));

        return $out;
    }

    /** Wave 68.3 pane commands, in plan order. */
    private const array W68_TMUX_COMMANDS = ['update_binaries', 'backfill', 'releases', 'postprocess', 'fixnames', 'removecrap', 'predb', 'sharing', 'idle', 'monitor'];

    /** Metric category by W68_TMUX_COMMANDS keyword. */
    private const array W68_TMUX_CATEGORIES = [0, 1, 1, 2, 3, 3, 4, 4, 4, 5, 5, 6];

    /** Activity ages closing the Wave 68.3 fresh, idle and stale windows. */
    public const int W68_TMUX_FRESH_SECONDS = 300;

    public const int W68_TMUX_IDLE_SECONDS = 900;

    public const int W68_TMUX_STALE_SECONDS = 3600;

    /** Cap for Wave 68.3 running totals. */
    private const int W68_TOTAL_CAP = 1 << 62;

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w68Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /**
     * The trimmed field, lower-cased and matched against $words: 0 empty, 1-based index, or the word count + 1.
     *
     * @param  list<string>  $words
     */
    private static function w68Keyword(string $text, array $words): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        $position = array_search(strtolower($text), $words, true);

        return $position === false ? \count($words) + 1 : $position + 1;
    }

    /**
     * Wave 68 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w68RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('monitoring_w683', self::W68_TMUX_SNAPSHOT_COLLECTOR_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w68RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 68.3 TmuxSnapshotCollector export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w68Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w68RustFfiState('ffi');
            $out = self::w68RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w68RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W68_TMUX_SNAPSHOT_COLLECTOR_FFI_CDEF.)
            $written = $ffi->nntmux_w683_tmux_snapshot_collector_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
