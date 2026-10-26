<?php

declare(strict_types=1);

namespace App\Services\Binaries;

use App\Services\Configuration\ConfigurationProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Configuration DTO for Binaries processing.
 * Encapsulates all settings in an immutable object for easier testing and injection.
 */
final readonly class BinariesConfig
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_bcfg_obfuscation_min_articles(int64_t configured);
        int32_t nntmux_bcfg_normalize(const int64_t *raw, size_t count, int64_t *out, size_t out_count);
        int32_t nntmux_bcfg_defaults(int64_t *out, size_t out_count);
        C;

    /** `normalizeChunkSettings()` keys, matching the constructor's trailing chunk parameters. */
    public const array CHUNK_FIELDS = [
        'headerChunkSize', 'sqlChunkSize', 'reconcileBatchSize', 'nzbStreamRows',
        'reconcilePagesPerPass', 'reconcilePassSeconds', 'perArticleObfuscationMinArticles',
    ];

    /** Constructor parameter names in declaration order. */
    public const array FIELDS = [
        'messageBuffer', 'compressedHeaders', 'partRepair', 'newGroupScanByDays', 'newGroupMessagesToScan',
        'newGroupDaysToScan', 'partRepairLimit', 'partRepairMaxTries', 'echoCli', 'headerChunkSize',
        'sqlChunkSize', 'reconcileBatchSize', 'nzbStreamRows', 'reconcilePagesPerPass', 'reconcilePassSeconds',
        'perArticleObfuscationMinArticles',
    ];

    /** Boolean constructor parameter positions within {@see FIELDS}. */
    private const array BOOL_FIELDS = [1 => true, 2 => true, 3 => true, 8 => true];

    public function __construct(
        public int $messageBuffer = 20000,
        public bool $compressedHeaders = true,
        public bool $partRepair = true,
        public bool $newGroupScanByDays = false,
        public int $newGroupMessagesToScan = 50000,
        public int $newGroupDaysToScan = 3,
        public int $partRepairLimit = 15000,
        public int $partRepairMaxTries = 3,
        public bool $echoCli = false,
        // Number of headers processed (and bulk-inserted) at a time inside
        // HeaderStorageService. This MUST stay small because each chunk
        // produces multi-row INSERTs/SELECTs whose binding count and SQL size
        // grow linearly with the value. Large unbounded chunks caused MySQL
        // and PHP to allocate hundreds of MB per scan and run out of RAM.
        public int $headerChunkSize = 500,
        // One shared hard upper bound for every bulk SELECT/INSERT/UPDATE.
        public int $sqlChunkSize = 500,
        public int $reconcileBatchSize = 500,
        public int $nzbStreamRows = 5000,
        // One release pass reconciles or sizes at most this many pages, and stops
        // starting new pages after this many seconds; later passes resume.
        public int $reconcilePagesPerPass = 1000,
        public int $reconcilePassSeconds = 300,
        // Skip a range's articles whose subject is randomised per article once this many
        // share a subject shape without repeating a name; 0 keeps them.
        public int $perArticleObfuscationMinArticles = 16,
    ) {}

    // PHP remains the production default until benchmarks qualify Rust.
    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    /**
     * Constructor defaults.
     */
    public static function defaults(): self
    {
        return self::usesRustFfi() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self;
    }

    public static function defaultsNative(): self
    {
        $ffi = self::ffi();
        $values = $ffi === null ? null : self::valuesBuffer($ffi);
        if ($ffi === null || $values === null) {
            return self::defaultsPhp();
        }
        $count = \count(self::FIELDS);
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_bcfg_defaults($values, $count);
            if ($written !== $count) {
                return self::defaultsPhp();
            }
            $numeric = unpack('q'.$count, FFI::string($values, $count * 8));
        } catch (Throwable) {
            return self::defaultsPhp();
        }
        if (! \is_array($numeric) || \count($numeric) !== $count) {
            return self::defaultsPhp();
        }
        $arguments = [];
        foreach (array_values($numeric) as $position => $value) {
            $arguments[self::FIELDS[$position]] = isset(self::BOOL_FIELDS[$position]) ? (int) $value !== 0 : (int) $value;
        }

        /** @var array{messageBuffer: int, compressedHeaders: bool, partRepair: bool, newGroupScanByDays: bool, newGroupMessagesToScan: int, newGroupDaysToScan: int, partRepairLimit: int, partRepairMaxTries: int, echoCli: bool, headerChunkSize: int, sqlChunkSize: int, reconcileBatchSize: int, nzbStreamRows: int, reconcilePagesPerPass: int, reconcilePassSeconds: int, perArticleObfuscationMinArticles: int} $arguments */
        return new self(...$arguments);
    }

    /**
     * Create configuration from application settings.
     */
    public static function fromSettings(): self
    {
        $configuration = app(ConfigurationProvider::class)->ingestion();
        $chunks = self::normalizeChunkSettings(
            (int) config('nntmux.cbp.header_chunk_size', 500),
            (int) config('nntmux.cbp.sql_chunk_size', 500),
            (int) config('nntmux.cbp.reconcile_batch_size', 500),
            (int) config('nntmux.cbp.nzb_stream_rows', 5000),
            (int) config('nntmux.cbp.reconcile_pages_per_pass', 1000),
            (int) config('nntmux.cbp.reconcile_pass_seconds', 300),
            (int) config('nntmux.cbp.per_article_obfuscation_min_articles', 16),
        );

        return new self(
            messageBuffer: $configuration->maxMessages,
            compressedHeaders: (bool) config('nntmux_nntp.compressed_headers'),
            partRepair: $configuration->partRepair,
            newGroupScanByDays: $configuration->newGroupScanMethod === 1,
            newGroupMessagesToScan: $configuration->newGroupMessagesToScan,
            newGroupDaysToScan: $configuration->newGroupDaysToScan,
            partRepairLimit: $configuration->maxPartRepair,
            partRepairMaxTries: $configuration->partRepairMaxTries,
            echoCli: (bool) config('nntmux.echocli'),
            headerChunkSize: $chunks['headerChunkSize'],
            sqlChunkSize: $chunks['sqlChunkSize'],
            reconcileBatchSize: $chunks['reconcileBatchSize'],
            nzbStreamRows: $chunks['nzbStreamRows'],
            reconcilePagesPerPass: $chunks['reconcilePagesPerPass'],
            reconcilePassSeconds: $chunks['reconcilePassSeconds'],
            perArticleObfuscationMinArticles: $chunks['perArticleObfuscationMinArticles'],
        );
    }

    /**
     * Clamp the configured chunk settings to their supported bounds.
     *
     * @return array{headerChunkSize: int, sqlChunkSize: int, reconcileBatchSize: int, nzbStreamRows: int, reconcilePagesPerPass: int, reconcilePassSeconds: int, perArticleObfuscationMinArticles: int}
     */
    public static function normalizeChunkSettings(int $headerChunkSize, int $sqlChunkSize, int $reconcileBatchSize, int $nzbStreamRows, int $reconcilePagesPerPass, int $reconcilePassSeconds, int $perArticleObfuscationMinArticles): array
    {
        return self::usesRustFfi()
            ? self::normalizeChunkSettingsNative($headerChunkSize, $sqlChunkSize, $reconcileBatchSize, $nzbStreamRows, $reconcilePagesPerPass, $reconcilePassSeconds, $perArticleObfuscationMinArticles)
            : self::normalizeChunkSettingsPhp($headerChunkSize, $sqlChunkSize, $reconcileBatchSize, $nzbStreamRows, $reconcilePagesPerPass, $reconcilePassSeconds, $perArticleObfuscationMinArticles);
    }

    /**
     * @return array{headerChunkSize: int, sqlChunkSize: int, reconcileBatchSize: int, nzbStreamRows: int, reconcilePagesPerPass: int, reconcilePassSeconds: int, perArticleObfuscationMinArticles: int}
     */
    public static function normalizeChunkSettingsPhp(int $headerChunkSize, int $sqlChunkSize, int $reconcileBatchSize, int $nzbStreamRows, int $reconcilePagesPerPass, int $reconcilePassSeconds, int $perArticleObfuscationMinArticles): array
    {
        return [
            'headerChunkSize' => max(50, min(2000, $headerChunkSize)),
            'sqlChunkSize' => max(50, min(1000, $sqlChunkSize)),
            'reconcileBatchSize' => max(50, min(2000, $reconcileBatchSize)),
            'nzbStreamRows' => max(500, min(20000, $nzbStreamRows)),
            'reconcilePagesPerPass' => max(1, $reconcilePagesPerPass),
            'reconcilePassSeconds' => max(1, $reconcilePassSeconds),
            'perArticleObfuscationMinArticles' => self::obfuscationMinArticlesPhp($perArticleObfuscationMinArticles),
        ];
    }

    /**
     * @return array{headerChunkSize: int, sqlChunkSize: int, reconcileBatchSize: int, nzbStreamRows: int, reconcilePagesPerPass: int, reconcilePassSeconds: int, perArticleObfuscationMinArticles: int}
     */
    public static function normalizeChunkSettingsNative(int $headerChunkSize, int $sqlChunkSize, int $reconcileBatchSize, int $nzbStreamRows, int $reconcilePagesPerPass, int $reconcilePassSeconds, int $perArticleObfuscationMinArticles): array
    {
        $raw = [$headerChunkSize, $sqlChunkSize, $reconcileBatchSize, $nzbStreamRows, $reconcilePagesPerPass, $reconcilePassSeconds, $perArticleObfuscationMinArticles];
        $ffi = self::ffi();
        $values = $ffi === null ? null : self::valuesBuffer($ffi);
        if ($ffi === null || $values === null) {
            return self::normalizeChunkSettingsPhp(...$raw);
        }
        $count = \count(self::CHUNK_FIELDS);
        try {
            FFI::memcpy($values, pack('q*', ...$raw), $count * 8);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_bcfg_normalize($values, $count, $values, $count);
            if ($written !== $count) {
                return self::normalizeChunkSettingsPhp(...$raw);
            }
            $numeric = unpack('q'.$count, FFI::string($values, $count * 8));
        } catch (Throwable) {
            return self::normalizeChunkSettingsPhp(...$raw);
        }
        if (! \is_array($numeric) || \count($numeric) !== $count) {
            return self::normalizeChunkSettingsPhp(...$raw);
        }

        /** @var array{headerChunkSize: int, sqlChunkSize: int, reconcileBatchSize: int, nzbStreamRows: int, reconcilePagesPerPass: int, reconcilePassSeconds: int, perArticleObfuscationMinArticles: int} $normalized */
        $normalized = array_combine(self::CHUNK_FIELDS, array_map(intval(...), array_values($numeric)));

        return $normalized;
    }

    /**
     * 0 or less disables the check; any other value is held to at least 8 so a handful of
     * unrelated single articles cannot form a group.
     */
    public static function obfuscationMinArticles(int $configured): int
    {
        return self::usesRustFfi()
            ? self::obfuscationMinArticlesNative($configured)
            : self::obfuscationMinArticlesPhp($configured);
    }

    public static function obfuscationMinArticlesPhp(int $configured): int
    {
        return $configured <= 0 ? 0 : max(8, $configured);
    }

    public static function obfuscationMinArticlesNative(int $configured): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::obfuscationMinArticlesPhp($configured);
        }
        try {
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_bcfg_obfuscation_min_articles($configured);
        } catch (Throwable) {
            return self::obfuscationMinArticlesPhp($configured);
        }

        return \is_int($result) && $result >= 0 ? $result : self::obfuscationMinArticlesPhp($configured);
    }

    /** Reusable `int64_t[16]` value buffer for `$ffi`. */
    private static function valuesBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('int64_t['.\count(self::FIELDS).']');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * PHP stays the default for summarizeChunkSettingsBatch(): the paired benchmark in
     * tests/Unit/RustBinariesConfigServiceHandlerHeaderW41ParityTest.php does not clear 1.5x.
     */
    public const bool W411_RUST_FFI_DEFAULT = false;

    private const array W411_CHUNK_KEYS = ['configs', 'invalid_rows', 'non_positive', 'header_exceeds_sql', 'over_cap', 'obfuscation_disabled', 'max_sql_chunk'];

    /**
     * Summarizes candidate chunk settings rows (`headerChunkSize`, `sqlChunkSize`, `perArticleObfuscationMinArticles`):
     * a row whose header or SQL chunk is not a positive int (or 1-18 digit string) is non-positive; otherwise a header
     * chunk above the SQL chunk exceeds it, an SQL chunk above `$sqlCap` is over the cap, and the largest SQL chunk is
     * reported (0 when none). An obfuscation minimum of 0 disables the filter; non-array rows are invalid.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeChunkSettingsBatch(array $rows, int $sqlCap = 10000): array
    {
        return (self::usesW411RustFfi() ? self::summarizeChunkSettingsBatchNative($rows, $sqlCap) : null)
            ?? self::summarizeChunkSettingsBatchPhp($rows, $sqlCap);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeChunkSettingsBatchPhp(array $rows, int $sqlCap = 10000): array
    {
        $summary = array_fill_keys(self::W411_CHUNK_KEYS, 0);
        $summary['configs'] = \count($rows);
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                $summary['invalid_rows']++;

                continue;
            }
            $header = BinariesService::w411Int($row['headerChunkSize'] ?? null);
            $sql = BinariesService::w411Int($row['sqlChunkSize'] ?? null);
            if ($header === null || $sql === null || $header <= 0 || $sql <= 0) {
                $summary['non_positive']++;
            } else {
                $summary['header_exceeds_sql'] += (int) ($header > $sql);
                $summary['over_cap'] += (int) ($sql > $sqlCap);
                $summary['max_sql_chunk'] = max($summary['max_sql_chunk'], $sql);
            }
            $summary['obfuscation_disabled'] += (int) (BinariesService::w411Int($row['perArticleObfuscationMinArticles'] ?? null) === 0);
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>|null
     */
    public static function summarizeChunkSettingsBatchNative(array $rows, int $sqlCap = 10000): ?array
    {
        $words = [$sqlCap, \count($rows)];
        $blob = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                BinariesService::w411PackRow($words, $blob, [$row['headerChunkSize'] ?? null, $row['sqlChunkSize'] ?? null,
                    $row['perArticleObfuscationMinArticles'] ?? null]);
            } else {
                $words[] = 0;
            }
        }
        $values = BinariesService::w411Invoke(self::w411FfiSlot(), 1, $words, $blob);

        return $values !== null && \count($values) === \count(self::W411_CHUNK_KEYS) ? array_combine(self::W411_CHUNK_KEYS, $values) : null;
    }

    public static function useW411RustFfi(bool $enable = true): void
    {
        self::w411ToggleState($enable, true);
    }

    public static function usesW411RustFfi(): bool
    {
        return self::w411ToggleState() ?? self::W411_RUST_FFI_DEFAULT;
    }

    public static function resetW411RustFfi(): void
    {
        self::w411ToggleState(null, true);
        $slot = &self::w411FfiSlot();
        $slot = null;
    }

    private static function w411ToggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    /** The cached Wave 41.1 library handle, by reference (readonly classes cannot declare static properties). */
    private static function &w411FfiSlot(): FFI|false|null
    {
        static $ffi = null;

        return $ffi;
    }

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52 parity test). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    private const string W52_FFI_CDEF = 'ssize_t nntmux_w521_binaries_config_scan_range_end_batch(const char *firsts, size_t firsts_len, const char *newests, size_t newests_len, size_t count, int64_t message_buffer, int64_t max_headers, int64_t *out, size_t out_cap);';

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 52 batch routed to Rust or PHP, then restores the routing. */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /** Largest article number and header limit {@see scanRangeEnds()} accepts (exact in a double). */
    public const int W52_MAX_ARTICLE = 1 << 53;

    /** Largest message buffer {@see scanRangeEnds()} accepts. */
    public const int W52_MAX_BUFFER = 1 << 31;

    /**
     * The existing-group scan end (BinariesService::existingHeaderRangePhp()'s `last`) for each
     * group's `first_record` and server newest article under `$messageBuffer` and `$maxHeaders`
     * (0 for no limit), as updateAllGroups() plans every active group.
     *
     * @param  list<int>  $firsts
     * @param  list<int>  $newests
     * @return list<int>
     *
     * @throws \InvalidArgumentException for mismatched columns or values outside `[0, W52_MAX_ARTICLE]`
     *                                   (a buffer outside `[1, W52_MAX_BUFFER]`)
     */
    public static function scanRangeEnds(array $firsts, array $newests, int $messageBuffer, int $maxHeaders = 0): array
    {
        return self::usesW52RustFfi()
            ? self::scanRangeEndsNative($firsts, $newests, $messageBuffer, $maxHeaders)
            : self::scanRangeEndsPhp($firsts, $newests, $messageBuffer, $maxHeaders);
    }

    /**
     * @param  list<int>  $firsts
     * @param  list<int>  $newests
     * @return list<int>
     */
    public static function scanRangeEndsPhp(array $firsts, array $newests, int $messageBuffer, int $maxHeaders = 0): array
    {
        self::w52Validate($firsts, $newests, $messageBuffer, $maxHeaders);
        $newests = array_values($newests);
        $ends = [];
        foreach (array_values($firsts) as $i => $first) {
            $newest = $newests[$i];
            if ($first < 0 || $newest < 0 || $first > self::W52_MAX_ARTICLE || $newest > self::W52_MAX_ARTICLE) {
                throw new \InvalidArgumentException('Scan range articles must be within [0, '.self::W52_MAX_ARTICLE.'].');
            }
            $count = $newest - $first;
            $leave = $count > $messageBuffer * 2
                ? (int) round($count % $messageBuffer, 0, PHP_ROUND_HALF_DOWN) + $messageBuffer
                : (int) round($count / 2, 0, PHP_ROUND_HALF_DOWN);
            $last = max($newest - $leave, $first);
            $ends[] = $maxHeaders > 0 && $maxHeaders < $last - $first ? $first + $maxHeaders : $last;
        }

        return $ends;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a row
     * outside the domain, which PHP then rejects).
     *
     * @param  list<int>  $firsts
     * @param  list<int>  $newests
     * @return list<int>
     */
    public static function scanRangeEndsNative(array $firsts, array $newests, int $messageBuffer, int $maxHeaders = 0): array
    {
        $count = self::w52Validate($firsts, $newests, $messageBuffer, $maxHeaders);
        $buffer = $count === 0 ? null : self::w52Buffer($count);
        if ($buffer === null) {
            return self::scanRangeEndsPhp($firsts, $newests, $messageBuffer, $maxHeaders);
        }
        [$ffi, $out, $cap] = $buffer;
        $packedFirsts = pack('P*', ...array_values($firsts));
        $packedNewests = pack('P*', ...array_values($newests));
        try {
            // @phpstan-ignore method.notFound
            $words = self::w52Decode($out, $ffi->nntmux_w521_binaries_config_scan_range_end_batch($packedFirsts, \strlen($packedFirsts), $packedNewests, \strlen($packedNewests), $count, $messageBuffer, $maxHeaders, $out, $cap), $count);
        } catch (Throwable) {
            $words = null;
        }

        return $words !== null && \count($words) === $count ? $words : self::scanRangeEndsPhp($firsts, $newests, $messageBuffer, $maxHeaders);
    }

    /**
     * @param  list<int>  $firsts
     * @param  list<int>  $newests
     */
    private static function w52Validate(array $firsts, array $newests, int $messageBuffer, int $maxHeaders): int
    {
        if (\count($firsts) !== \count($newests)) {
            throw new \InvalidArgumentException('Scan range columns must have the same length.');
        }
        if ($messageBuffer < 1 || $messageBuffer > self::W52_MAX_BUFFER || $maxHeaders < 0 || $maxHeaders > self::W52_MAX_ARTICLE) {
            throw new \InvalidArgumentException('Scan range settings must keep a buffer within [1, '.self::W52_MAX_BUFFER.'] and a limit within [0, '.self::W52_MAX_ARTICLE.'].');
        }

        return \count($firsts);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: CData, 2: int}|null
     */
    private static function w52Buffer(int $words): ?array
    {
        $buffer = self::w52RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w52Decode(CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 52 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics (readonly classes cannot declare static properties).
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('backfill_binaries_w52', self::W52_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }

    // ========================================================================
    // WAVE 66.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 9.07x, p99 7.16x; uncommon mean 8.01x, p99 6.61x); see RustBackfillBinariesConfigServiceHandlerW66ParityTest. */
    public const bool W66_RUST_FFI_DEFAULT = true;

    public const string W66_BINARIES_CONFIG_FFI_CDEF = <<<'C'
        ssize_t nntmux_w661_binaries_config_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w66BinariesConfigPlanBatch(). */
    public const int W66_BINARIES_CONFIG_TRAILER = 27;

    public static function usesW66RustFfi(): bool
    {
        $override = self::w66RustFfiState();

        return \is_bool($override) ? $override : self::W66_RUST_FFI_DEFAULT;
    }

    /** Forces the BinariesConfig Wave 66 Rust path on until reset. */
    public static function enableW66RustFfi(): void
    {
        self::w66RustFfiState('set', true);
    }

    /** Forces the BinariesConfig Wave 66 PHP path until reset. */
    public static function disableW66RustFfi(): void
    {
        self::w66RustFfiState('set', false);
    }

    /** Restores the benchmark default and drops the cached library handle and scratch buffers; earlier toggles are untouched. */
    public static function resetW66RustFfi(): void
    {
        self::w66RustFfiState('reset');
    }

    /**
     * Runs $callback with the BinariesConfig Wave 66 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW66RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w66RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w66RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 66.1 binaries configuration plans, one per `message_buffer|compressed|part_repair|scan_by_days|messages_to_scan|
     * days_to_scan|repair_limit|repair_tries|header_chunk|sql_chunk|obfuscation_min` row (trimmed fields). Flags are
     * case-insensitive 1/true/yes/on or 0/false/no/off (compressed and part repair default on, scan by days off). Numbers are
     * 1-15 digits; empty takes the constructor default and each clamps (clamp mask bit in order): message buffer 1000-100000,
     * messages to scan 1000-10000000, days 1-365, repair limit 100-100000, tries 1-10, header chunk 1-1000, SQL chunk 1-1000,
     * obfuscation minimum 2-1000 (0 keeps it disabled). Decision: 0 empty row, 1 malformed flag or number, 2 header chunk
     * above the SQL chunk, 3 clamped, 4 valid. Flags: 1 compressed, 2 part repair, 4 scan by days, 8 obfuscation guard on,
     * 16 non-ASCII, 32 control byte, 64 row repeated (case-insensitive), 128 messages to scan below the buffer. Per item
     * `decision | clampMask << 3 | flags << 11 | buffer << 19 | days << 36 | tries << 45 | headerChunk << 49`; then 5 decision,
     * 8 clamp and 8 flag counts, the buffer sum and maximum, the messages-to-scan sum, the largest repair limit, the header
     * chunk sum and the distinct rows.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesConfigPlanBatch(array $items): array
    {
        return self::usesW66RustFfi() ? self::w66BinariesConfigPlanBatchNative($items) : self::w66BinariesConfigPlanBatchPhp($items);
    }

    /**
     * Rust plan; falls back to the PHP reference when the library is missing or the call declines.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesConfigPlanBatchNative(array $items): array
    {
        return self::w66Invoke($items, \count($items) + self::W66_BINARIES_CONFIG_TRAILER) ?? self::w66BinariesConfigPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w66BinariesConfigPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W66_BINARIES_CONFIG_TRAILER, 0);
        $seen = [];
        foreach ($items as $item) {
            $item = (string) $item;
            $fields = explode('|', $item, 11) + ['', '', '', '', '', '', '', '', '', '', ''];
            $compressed = self::w66Flag($fields[1], 1);
            $repair = self::w66Flag($fields[2], 1);
            $byDays = self::w66Flag($fields[3], 0);
            $raw = [$fields[0], $fields[4], $fields[5], $fields[6], $fields[7], $fields[8], $fields[9], $fields[10]];
            $value = [];
            $mask = 0;
            $malformed = $compressed < 0 || $repair < 0 || $byDays < 0;
            foreach (self::W66_BINARIES_NUMBERS as $i => [$default, $min, $max]) {
                $number = self::w66Uint($raw[$i], $default);
                $malformed = $malformed || $number < 0;
                [$effective, $clamped] = $i === 7 && $number === 0 ? [0, false] : self::w66Clamp($number, $min, $max);
                $mask |= $clamped ? 1 << $i : 0;
                $value[$i] = $effective;
            }
            $trimmed = trim($item);
            $decision = match (true) {
                $trimmed === '' => 0,
                $malformed => 1,
                $value[5] > $value[6] => 2,
                $mask !== 0 => 3,
                default => 4,
            };
            $flags = ($compressed === 1 ? 1 : 0) | ($repair === 1 ? 2 : 0) | ($byDays === 1 ? 4 : 0) | ($value[7] > 0 ? 8 : 0)
                | (preg_match('/[\x80-\xff]/', $item) === 1 ? 16 : 0) | (strcspn($item, self::W66_CONTROL_BYTES) !== \strlen($item) ? 32 : 0)
                | ($value[1] < $value[0] ? 128 : 0);
            if ($trimmed !== '') {
                $key = strtolower($trimmed);
                if (isset($seen[$key])) {
                    $flags |= 64;
                } else {
                    $seen[$key] = true;
                }
            }
            $tail[$decision]++;
            for ($b = 0; $b < 8; $b++) {
                $tail[5 + $b] += $mask >> $b & 1;
                $tail[13 + $b] += $flags >> $b & 1;
            }
            $tail[21] += $value[0];
            $tail[22] = max($tail[22], $value[0]);
            $tail[23] += $value[1];
            $tail[24] = max($tail[24], $value[3]);
            $tail[25] += $value[5];
            $out[] = $decision | $mask << 3 | $flags << 11 | $value[0] << 19 | $value[2] << 36 | $value[4] << 45 | $value[5] << 49;
        }
        $tail[26] = \count($seen);
        array_push($out, ...$tail);

        return $out;
    }

    /** @var list<array{int, int, int}> Default, minimum and maximum of each numeric field of w66BinariesConfigPlanBatch(). */
    private const array W66_BINARIES_NUMBERS = [[20_000, 1000, 100_000], [50_000, 1000, 10_000_000], [3, 1, 365], [15_000, 100, 100_000], [3, 1, 10],
        [500, 1, 1000], [500, 1, 1000], [16, 2, 1000]];

    private const string W66_DIGITS = '0123456789';

    private const string W66_CONTROL_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /** A trimmed field of 1-15 digits; empty is $empty, anything else -1. */
    private static function w66Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }
        $length = \strlen($value);

        return $length <= 15 && strspn($value, self::W66_DIGITS) === $length ? (int) $value : -1;
    }

    /** A trimmed, case-insensitive boolean: empty is $empty, 1/true/yes/on 1, 0/false/no/off 0, anything else -1. */
    private static function w66Flag(string $raw, int $empty): int
    {
        return match (strtolower(trim($raw))) {
            '' => $empty,
            '1', 'true', 'yes', 'on' => 1,
            '0', 'false', 'no', 'off' => 0,
            default => -1,
        };
    }

    /**
     * $value clamped to $min..$max and whether clamping changed it; malformed (-1) is 0, unchanged.
     *
     * @return array{0: int, 1: bool}
     */
    private static function w66Clamp(int $value, int $min, int $max): array
    {
        if ($value < 0) {
            return [0, false];
        }
        $clamped = max($min, min($max, $value));

        return [$clamped, $clamped !== $value];
    }

    /**
     * Wave 66 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'reset' clears the override, library handle and buffers, 'ffi'
     * resolves the library (null when unavailable) and 'out' and 'lens' return `int64_t` output and
     * length-column buffers of at least $val words.
     */
    private static function w66RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('binaries_config_w661', self::W66_BINARIES_CONFIG_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w66RustFfiState('ffi');
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
                $handle = self::w66RustFfiState('ffi');
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
     * Calls the Wave 66.1 BinariesConfig export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w66Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w66RustFfiState('ffi');
            $out = self::w66RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w66RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W66_BINARIES_CONFIG_FFI_CDEF.)
            $written = $ffi->nntmux_w661_binaries_config_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
