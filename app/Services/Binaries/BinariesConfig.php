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
}
