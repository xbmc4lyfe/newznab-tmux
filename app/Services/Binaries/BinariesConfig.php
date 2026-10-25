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
}
