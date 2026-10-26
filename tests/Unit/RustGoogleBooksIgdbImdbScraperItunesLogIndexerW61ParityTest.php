<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GoogleBooksService;
use App\Services\IGDBService;
use App\Services\ImdbScraper;
use App\Services\ItunesService;
use App\Services\LogViewer\Index\LogIndexer;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Paired randomized benchmark (`test_paired_randomized_benchmark_gates_rust_default`, run with
 * `NNTMUX_W615_BENCH=1`): per helper, 200 interleaved samples per workload after 20 warmup rounds,
 * per-sample random PHP/Rust order, each side the fastest of three back-to-back calls, release dylib.
 * Common workloads hold 320 records and uncommon ones 384 records with edge cases. Timings include
 * PHP-side packing, the FFI call and output decoding; each run prints its ratios to STDERR.
 *
 * A helper only defaults to Rust when every recorded common and uncommon mean and p99 ratio reaches 1.5x
 * (QUALIFICATION); each benchmark run asserts that the Rust defaults still clear 1.5x.
 */
class RustGoogleBooksIgdbImdbScraperItunesLogIndexerW61ParityTest extends TestCase
{
    /**
     * Batch method per class; each has `<method>Php` and `<method>Native` variants.
     *
     * @var array<class-string, string>
     */
    private const METHODS = [
        GoogleBooksService::class => 'lookupPlanBatch',
        IGDBService::class => 'lookupPlanBatch',
        ImdbScraper::class => 'scrapePlanBatch',
        ItunesService::class => 'lookupPlanBatch',
        LogIndexer::class => 'checkpointPlanBatch',
    ];

    /**
     * Private C declaration constant and entry point per class.
     *
     * @var array<class-string, array{string, string}>
     */
    private const FUNCTIONS = [
        GoogleBooksService::class => ['W61_GOOGLE_BOOKS_PLAN_FFI_CDEF', 'nntmux_w615_google_books_lookup_plan'],
        IGDBService::class => ['W61_IGDB_PLAN_FFI_CDEF', 'nntmux_w615_igdb_lookup_plan'],
        ImdbScraper::class => ['W61_IMDB_PLAN_FFI_CDEF', 'nntmux_w615_imdb_scrape_plan'],
        ItunesService::class => ['W61_ITUNES_PLAN_FFI_CDEF', 'nntmux_w615_itunes_lookup_plan'],
        LogIndexer::class => ['W61_LOG_INDEXER_PLAN_FFI_CDEF', 'nntmux_w615_log_indexer_plan'],
    ];

    /**
     * Output words per record.
     *
     * @var array<class-string, int>
     */
    private const WIDTHS = [
        GoogleBooksService::class => 8,
        IGDBService::class => 9,
        ImdbScraper::class => 10,
        ItunesService::class => 9,
        LogIndexer::class => 10,
    ];

    /**
     * Benchmark-qualified defaults (see the class docblock for the gate).
     *
     * @var array<class-string, bool>
     */
    private const DEFAULTS = [
        GoogleBooksService::class => true,
        IGDBService::class => true,
        ImdbScraper::class => true,
        ItunesService::class => true,
        LogIndexer::class => true,
    ];

    /**
     * Common mean/p99 then uncommon mean/p99 speedups (PHP / Rust), the per-metric minimum of three
     * `NNTMUX_W615_BENCH=1` runs (200 samples per workload, seed 6152, release Rust, PHP CLI without
     * opcache, Apple Silicon macOS on a shared host).
     *
     * @var array<class-string, array{float, float, float, float}>
     */
    private const QUALIFICATION = [
        GoogleBooksService::class => [6.29, 4.98, 3.54, 3.14],
        IGDBService::class => [5.44, 3.76, 4.75, 3.51],
        ImdbScraper::class => [5.27, 4.32, 5.34, 3.44],
        ItunesService::class => [6.14, 5.29, 4.11, 3.11],
        LogIndexer::class => [5.72, 3.61, 5.83, 5.04],
    ];

    /** Back-to-back calls per side of a sample; the fastest is kept (drops preemption spikes). */
    private const REPEATS = 3;

    private const RANDOM_ROUNDS = 400;

    private const BENCH_SAMPLES = 200;

    private const BYTES = ['a', 'Z', 'x', 'X', 't', 'T', '0', '1', '7', '9', ' ', "\t", "\n", "\r", "\x0b", "\x0c", "\x01", "\x7f", '-', '+', '_', '.', ',', ':', '/', '(', "'", "\xc3\xa9", "\xc3", "\xff", "\0"];

    /** Fragments glued into otherwise structured records so separators and keywords actually occur. */
    private const FRAGMENTS = ["\t", "\t\t", ',', ',,', ' ', '  ', '-', '.', ':', 'X', 'x', '0', '1', '9', '19', '20', '1994', 'tt', 'TT', '978', '1024', '8388608', '999999999999999999', '1234567890123456789', 'xbox', 'ps3', 'album', 'ebook', 'music', 'tv_series', 'movie', 'The', "\x0c", "\x01", "\xc3\xa9"];

    protected function tearDown(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            self::state($class, 'reset', null);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{class-string, string}>
     */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::METHODS as $class => $method) {
            $cases[(new ReflectionClass($class))->getShortName()] = [$class, $method];
        }

        return $cases;
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_methods_defaults_and_scoped_toggles(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        foreach (['usesW61RustFfi', 'withW61RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            $this->assertTrue($reflection->hasMethod($name), "{$class}::{$name}");
            $this->assertTrue($reflection->getMethod($name)->isStatic() && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
        }
        $this->assertTrue($reflection->getMethod('w61RustFfiState')->isPrivate(), "{$class} keeps its W61 state private");
        $this->assertTrue((new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->isPrivate(), "{$class} keeps its W61 declaration private");
        $constant = new ReflectionClassConstant($class, 'W61_RUST_FFI_DEFAULT');
        $this->assertTrue($constant->isPublic(), "{$class} default must be public");
        $this->assertSame(self::DEFAULTS[$class], $constant->getValue(), "{$class} default must match the benchmark evidence");
        $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, self::DEFAULTS[$class], "{$class} default must follow its recorded qualification");
        $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), "{$class} routes by its default (PHP when it did not qualify)");

        foreach ([true, false] as $enabled) {
            $this->assertSame([$enabled, 'ok'], $class::withW61RustFfi($enabled, static fn () => [$class::usesW61RustFfi(), 'ok']));
            $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), "{$class} must restore after the callback");
        }
        $nested = $class::withW61RustFfi(false, static fn () => [$class::withW61RustFfi(true, static fn () => $class::usesW61RustFfi()), $class::usesW61RustFfi()]);
        $this->assertSame([true, false], $nested);
        $caught = null;
        try {
            $class::withW61RustFfi(! $class::usesW61RustFfi(), static fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException $exception) {
            $caught = $exception->getMessage();
        }
        $this->assertSame('boom', $caught, 'The callback exception must propagate.');
        $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), "{$class} must restore after an exception");

        $class::withW61RustFfi(! $class::usesW61RustFfi(), function () use ($class): void {
            foreach (array_keys(self::METHODS) as $other) {
                if ($other !== $class) {
                    $this->assertSame(self::DEFAULTS[$other], $other::usesW61RustFfi(), "{$class} leaked into {$other}");
                }
            }
        });
    }

    public function test_w61_state_adds_no_properties(): void
    {
        foreach (array_keys(self::METHODS) as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('w61', $property->getName(), $class);
            }
        }
        $this->assertSame([], array_values(array_filter(
            (new ReflectionClass(IGDBService::class))->getProperties(ReflectionProperty::IS_STATIC),
            static fn (ReflectionProperty $property): bool => ! \in_array($property->getName(), ['ffi', 'ffiResolved', 'useRustFfi', 'scoreBuf', 'scoreCap', 'w47Ffi', 'w47UseRustFfi'], true),
        )), 'IGDBService gains no static properties');
    }

    public function test_earlier_wave_toggles_are_untouched(): void
    {
        $snapshot = static fn (): array => [
            GoogleBooksService::usesW434RustFfi(),
            IGDBService::usesW47CandidateScoreRustFfi(),
            ImdbScraper::usesW464RustFfi(),
            ItunesService::usesW464RustFfi(),
            LogIndexer::usesRustFfi(),
            LogIndexer::usesW47LogIndexerRustFfi(),
        ];
        $before = $snapshot();
        $this->assertSame([true, true, true, true, true, true], $before);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($before, $class::withW61RustFfi($enabled, $snapshot), $class);
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_existing_public_api_is_preserved(): void
    {
        foreach ([
            GoogleBooksService::class => ['hasApiKey', 'isConfigured', 'findByIsbn', 'searchBook', 'searchBooks', 'normalizerFfi', 'normalizerString', 'providerFields', 'buildSearchQueryNative', 'buildSearchQueryPhp', 'normalizeBookResultNative', 'normalizeBookResultPhp', 'useW434RustFfi', 'usesW434RustFfi', 'resetW434RustFfi', 'summarizeBookIdentifierBatch', 'summarizeBookIdentifierBatchNative', 'summarizeBookIdentifierBatchPhp'],
            IGDBService::class => ['setUseRustFfi', 'isConfigured', 'search', 'searchConsole', 'getGameDetails', 'scoreCandidatesNative', 'buildConsoleData', 'buildGameData', 'getImageUrl', 'extractBaseTitleNative', 'normalizeForMatchNative', 'computeSimilarityNative', 'matchGenre', 'isKnownGenre', 'clearCache', 'useW47CandidateScoreRustFfi', 'usesW47CandidateScoreRustFfi', 'resetW47CandidateScoreRustFfi', 'scoreCandidateBatch', 'scoreCandidateBatchNative', 'scoreCandidateBatchPhp'],
            ImdbScraper::class => ['wasBlockedByWaf', 'isScrapingEnabled', 'isImdbApiDevEnabled', 'isEnabled', 'isWafBackoffActive', 'wasSkipped', 'getLastFetchSource', 'getLastFailureReason', 'getLastFallbackFailureReason', 'fetchById', 'search', 'extractYearFromTextNative', 'normalizeImageUrlNative', 'normalizeSearchSlugNative', 'parseSuggestionJsonNative', 'parseSearchHtmlNative', 'useW464RustFfi', 'usesW464RustFfi', 'resetW464RustFfi', 'summarizeImdbSearchTextBatch', 'summarizeImdbSearchTextBatchNative', 'summarizeImdbSearchTextBatchPhp'],
            ItunesService::class => ['limit', 'country', 'lastRequestFailed', 'searchAlbums', 'searchTracks', 'searchArtists', 'searchEbooks', 'searchAudiobooks', 'lookupById', 'lookupArtist', 'findAlbum', 'findTrack', 'findEbook', 'findEbooks', 'normalizerFfi', 'normalizerEnvironmentSupported', 'normalizeAlbumResultNative', 'normalizeAlbumResultPhp', 'normalizeTrackResultNative', 'normalizeTrackResultPhp', 'normalizeEbookResultNative', 'normalizeEbookResultPhp', 'getHighResCoverNative', 'getHighResCoverPhp', 'useW464RustFfi', 'usesW464RustFfi', 'resetW464RustFfi', 'summarizeItunesArtworkBatch', 'summarizeItunesArtworkBatchNative', 'summarizeItunesArtworkBatchPhp'],
            LogIndexer::class => ['run', 'reset', 'forget', 'rowsFor', 'status', 'isCaughtUp', 'documentFields', 'documentFieldsNative', 'scrubUtf8Native', 'setUseRustFfi', 'usesRustFfi', 'useW47LogIndexerRustFfi', 'usesW47LogIndexerRustFfi', 'resetW47LogIndexerRustFfi', 'scanChunkBatch', 'scanChunkBatchPhp', 'scanChunkBatchNative'],
        ] as $class => $methods) {
            $reflection = new ReflectionClass($class);
            foreach ($methods as $name) {
                $this->assertTrue($reflection->hasMethod($name) && $reflection->getMethod($name)->isPublic(), "{$class}::{$name}");
            }
        }
        $this->assertSame([LogIndexer::STATE_INDEXED, LogIndexer::STATE_PARTIAL, LogIndexer::STATE_NONE], ['indexed', 'partial', 'none']);

        $earlier = static fn (): array => [
            GoogleBooksService::summarizeBookIdentifierBatchPhp(['978-0-306-40615-7', '0306406152', 'bogus']),
            ImdbScraper::summarizeImdbSearchTextBatchPhp(['The Matrix 1999', '']),
            ItunesService::summarizeItunesArtworkBatchPhp(['https://is1-ssl.mzstatic.com/image/thumb/a/100x100bb.jpg']),
            LogIndexer::scanChunkBatchPhp(["[2024-01-01 00:00:00] local.ERROR: boom\n#0 trace\n"]),
        ];
        $expected = $earlier();
        $this->assertSame([[1, 1, 0, 0, 0, 40, 40, 0]], [array_values(LogIndexer::scanChunkBatchPhp(["[2024-01-01 00:00:00] local.ERROR: boom\n"])[0])]);
        foreach (array_keys(self::METHODS) as $class) {
            foreach ([true, false] as $enabled) {
                $this->assertSame($expected, $class::withW61RustFfi($enabled, $earlier), $class);
            }
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_entry_points_route_by_toggle(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $php = $class::{$method.'Php'}($records);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $php);
        $this->assertSame($php, $class::withW61RustFfi(false, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::withW61RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertSame($php, $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_empty_batches_return_no_words(string $class, string $method): void
    {
        $this->assertSame([], $class::{$method.'Php'}([]));
        $this->assertSame([], $class::{$method.'Native'}([]));
        $this->assertSame([], $class::{$method}([]));
        // One empty record still yields a full row.
        $this->assertSame($class::{$method.'Php'}(['']), $class::{$method.'Native'}(['']));
        $this->assertCount(self::WIDTHS[$class], $class::{$method}(['']));
    }

    public function test_google_books_plan_examples(): void
    {
        $this->requireFfi(GoogleBooksService::class);
        $this->assertBoth(GoogleBooksService::class, [
            "\t\t\t978-0-306-40615-7\t\t\t",
            "dune\tDune Messiah\tFrank Herbert\t\tdune messiah\tHerbert, Frank\t99",
            '',
            "Some Query\t\t\t12345\tsome other\t\tx",
            " q \t\tTolkien\t030640615x\tThe Hobbit\tJ.R.R. Tolkien\t0",
            "\tCaf\xc3\xa9\t\t9780306406158\tcaf\xc3\xa9\t\t40\textra",
        ], [
            1, 18, 13, 0, 0, 10, 0, 68,
            2, 43, 0, 100, 100, 40, 2, 40,
            0, 0, 0, 0, 0, 10, 0, 321,
            3, 10, 0, 50, 0, 10, 2, 18,
            2, 16, 0, 0, 100, 1, 1, 10,
            2, 13, 0, 100, 0, 40, 1, 418,
        ]);
    }

    public function test_igdb_plan_examples(): void
    {
        $this->requireFfi(IGDBService::class);
        $this->assertBoth(IGDBService::class, [
            "  Halo: Reach!! \t X360 \t1000\t950\t4\t930\tpc,Xbox 360",
            '',
            "Zelda\tvirtual boy\t900\t1000\tx\t1100\tvirtual boy",
            "Mario Kart 64\tN64\t1100\t1000\t3\t990\tnintendo 64",
            "Gran Turismo\tSuper Nintendo\t\t1000\t9\t900\tsnes,SNES\textra",
        ], [
            10, 2, 1, 2, 2, 50, 0, 40, 0,
            0, 0, 0, 0, 0, 0, 1, 0, 129,
            5, 1, -1, 1, 3, 0, 1, 0, 82,
            13, 3, 15, 1, 1, 100, 1, 0, 0,
            12, 2, 17, 1, 0, 0, 1, 0, 128,
        ]);
    }

    public function test_imdb_plan_examples(): void
    {
        $this->requireFfi(ImdbScraper::class);
        $this->assertBoth(ImdbScraper::class, [
            "tt0111161\tThe Shawshank Redemption\t(1994)\t9.3\tDrama, drama,Crime\tFrank Darabont\ta,b,c,d,e,f,g,h,i,j,k\tmovie",
            '',
            "TT12\t \tunknown\t10.5\t\t\t\ttvSeries",
            " tt0133093 \tThe Matrix\tReleased 31 March 1999\t10.0\tAction,Sci-Fi\tLana, Lilly\tKeanu Reeves\tdocumentary",
            "nm0000001\tX\t1899\t7.25\t\t\t\ttv_episode\textra",
        ], [
            111161, 1, 24, 1994, 93, 2, 1, 10, 1, 96,
            0, 0, 0, 0, -1, 0, 0, 0, 1, 261,
            12, 2, 0, 0, -1, 0, 0, 0, 2, 28,
            133093, 1, 10, 1999, 100, 2, 2, 1, 1, 128,
            0, 3, 1, 0, -1, 0, 0, 0, 2, 282,
        ]);
    }

    public function test_itunes_plan_examples(): void
    {
        $this->requireFfi(ItunesService::class);
        $this->assertBoth(ItunesService::class, [
            " Abbey   Road \talbum\tmusic\tgb\t500\tAbbey Road\tThe Beatles\tcollection",
            '',
            "x\tebook\tmusic\tusa\t0\t\t\tpodcast",
            "dune herbert\tEBOOK\tebook\t \t25\tDune\tFrank Herbert\tebook",
            "\x0cjazz\x0b\tmusicArtist\tall\tZZ\t\tKind of Blue\tMiles Davis\tartist\textra",
        ], [
            10, 2, 1, 1, 158, 200, 100, 2, 160,
            0, 0, 0, 0, 539, 10, 0, 0, 513,
            1, 1, 4, 1, 539, 1, 0, -1, 312,
            12, 2, 4, 2, 539, 25, 100, 3, 0,
            4, 1, 3, 4, 676, 10, 0, 5, 512,
        ]);
    }

    public function test_log_indexer_plan_examples(): void
    {
        $this->requireFfi(LogIndexer::class);
        $this->assertBoth(LogIndexer::class, [
            "20000000\t1000\t10\t1024\t7\t7\t950\t1000\t9000000\t5\t1000\t",
            '',
            "10\t500\t0\t0\t7\t8\t2000\t1000\t\t1000\t0\tx",
            "4096\t4096\t40\t512\t9\t9\t100\t1000\t0\t0\t\t0",
            "8388609\t0\t0\t1024\t3\t\t\t\t8388608\t\t\t\textra",
        ], [
            1, 1000, 19_999_000, 1, 8_388_608, 2, 1, 0, 3, 32,
            3, 0, 0, 0, 0, 0, 0, 1, 0, 128,
            2, 0, 10, 1, 10, 0, 0, 1, 1, 95,
            0, 4096, 0, 0, 0, 1, 0, 1, 0, 0,
            1, 0, 8_388_609, 1, 8_388_608, 2, 0, 0, 2, 160,
        ]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_every_helper_is_served_by_rust(string $class, string $method): void
    {
        $this->assertInstanceOf(FFI::class, $this->requireFfi($class));
        $records = self::firstRecords($class);
        $this->assertCount(self::WIDTHS[$class] * \count($records), $class::{$method.'Native'}($records));
        $handle = self::state($class, 'buffer', 1);
        $this->assertIsArray($handle, "{$class} must resolve the Rust helper");
        $this->assertInstanceOf(FFI::class, $handle[0]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_large_batches_grow_the_output_buffer(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = [];
        for ($i = 0; $i < 3000; $i++) {
            $records[] = self::firstRecords($class)[$i % 3].$i;
        }
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $handle = self::state($class, 'buffer', 0);
        $this->assertIsArray($handle);
        $this->assertSame(3000 * self::WIDTHS[$class], $handle[2]);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_native_helpers_fall_back_to_php_without_library(string $class, string $method): void
    {
        self::state($class, 'reset', false);
        $records = self::firstRecords($class);
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
        $this->assertSame($class::{$method.'Php'}($records), $class::withW61RustFfi(true, static fn () => $class::{$method}($records)), $class);
        $this->assertNull(self::state($class, 'buffer', 1), "{$class} must keep the disabled handle");
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_nul_bytes_decline_to_php(string $class, string $method): void
    {
        $this->requireFfi($class);
        $records = self::firstRecords($class);
        $records[1] .= "\0x";
        $this->assertSame($class::{$method.'Php'}($records), $class::{$method.'Native'}($records), $class);
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $ffi = $this->requireFfi($class);
        $function = self::abiFunction($class);
        $parts = self::firstRecords($class);
        $data = implode("\0", $parts);
        $count = \count($parts);
        $needed = 3 * self::WIDTHS[$class];
        $out = $ffi->new('int64_t[32]');
        $small = $ffi->new('int64_t[1]');
        $small[0] = 7;
        $call = static fn (int $count, int $flags, string $data, string $ints, mixed $out, int $cap): int => $ffi->{$function}($count, $flags, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);

        $this->assertSame($needed, $call($count, 0, $data, '', $out, 32), $function);
        $this->assertSame(-$needed, $call($count, 0, $data, '', $small, 1), $function);
        $this->assertSame(7, $small[0], "{$function} must not write on a capacity error");
        $this->assertSame(-$needed, $call($count, 0, $data, '', null, 0), $function);
        $this->assertSame(-1, $call($count, 0, $data, '', null, 4), $function);
        $this->assertSame(-1, $call($count, 1, $data, '', $out, 32), "{$function} flags");
        $this->assertSame(-1, $call($count + 1, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call($count - 1, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call(0, 0, $data, '', $out, 32), "{$function} record count");
        $this->assertSame(-1, $call($count, 0, $data, pack('P', 1), $out, 32), "{$function} int table");
        $this->assertSame(0, $call(0, 0, '', '', null, 0), "{$function} empty batch");
    }

    /**
     * Over 400 rounds of 1-16 records each (thousands of structured, fragment-spliced and raw-byte inputs
     * per helper), every class's Rust and PHP plans agree bit for bit; every 50th round also carries NUL
     * bytes, which must decline to PHP.
     *
     * @param  class-string  $class
     */
    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method): void
    {
        $this->assertNotSame('', $method);
        $this->requireFfi($class);
        mt_srand(6151 + crc32($class) % 1000);
        $checked = 0;
        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $withNul = $round % 50 === 49;
            $records = [];
            for ($i = 0, $count = mt_rand(1, 16); $i < $count; $i++) {
                $records[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(24, $withNul),
                    1 => self::spliced(self::structuredRecord($class), $withNul),
                    default => self::structuredRecord($class),
                };
            }
            if ($withNul) {
                $records[0] .= "\0";
            }
            $this->assertBoth($class, $records, null);
            $checked += \count($records);
        }
        $this->assertGreaterThanOrEqual(300, $checked);
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        if (getenv('NNTMUX_W615_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_W615_BENCH=1 to run the Wave 61.5 paired benchmark.');
        }
        $this->requireFfi(GoogleBooksService::class);
        mt_srand(6152);
        $workloads = [];
        $floors = [];

        foreach (['common' => 320, 'uncommon' => 384] as $name => $count) {
            $odd = $name === 'uncommon';
            $books = $games = $titles = $tracks = $checkpoints = [];
            for ($i = 0; $i < $count; $i++) {
                $now = 1_700_000_000 + $i * 37;

                $books[] = $odd
                    ? match ($i % 5) {
                        0 => " \t\t\t ISBN 978-0-306-4061{$i}-7 \tDune\tFrank Herbert\t999",
                        1 => '',
                        2 => "caf\xc3\xa9 au lait\t\xc3\x89t\xc3\xa9 \xc3\xa0 Paris\tJ.-M. Le Cl\xc3\xa9zio\t030640615X\t\xc3\xa9t\xc3\xa9 \xc3\xa0 paris\tLe Cl\xc3\xa9zio, J.-M.\t0\textra",
                        3 => str_repeat('The Lord of the Rings ', 4)."\t\t\txxxxxxxxxx\t".str_repeat('lord rings ', 6)."\t\t-1",
                        default => "{$i}\t{$i}: A Novel ({$i})\t\t97803064061{$i}\t{$i} novel\tAnon\t1.5",
                    }
                : ['Dune', 'Foundation', 'Neuromancer', 'Hyperion'][$i % 4].' '.($i % 3 === 0 ? 'Messiah' : 'Book')."\t".($i % 2 === 0 ? ['Dune Messiah', 'Foundation and Empire', 'Neuromancer', 'The Fall of Hyperion'][$i % 4] : '')."\t".($i % 3 === 1 ? ['Frank Herbert', 'Isaac Asimov', 'William Gibson', 'Dan Simmons'][$i % 4] : '')."\t".($i % 5 === 0 ? '978-0-306-40615-7' : '')."\t".['Dune Messiah', 'Foundation and Empire', 'Neuromancer: A Novel', 'The Fall of Hyperion'][$i % 4]."\t".['Herbert, Frank', 'Asimov, Isaac', 'William Gibson', 'Simmons, Dan'][$i % 4]."\t".['', '10', '20', '40'][$i % 4];

                $games[] = $odd
                    ? match ($i % 5) {
                        0 => "  The Legend of Zelda: Breath of the Wild -- Complete Edition!! \t NINTENDO SUPER NES \t{$now}\t{$now}\t4\t".($now + 30)."\tsnes, Super Nintendo ,x",
                        1 => '',
                        2 => "\xc3\x89t\xc3\xa9\tvirtual boy\tx\t1234567890123\t1234567\t-\t,,,,,virtual boy",
                        3 => str_repeat('Call of Duty ', 5)."\tps3\t".($now + 61)."\t{$now}\t9\t".($now - 59)."\t".implode(',', array_fill(0, 12, 'pc')).',PS3',
                        default => "{$i}\t{$i}\t".($now - 1)."\t{$now}\t3\t".($now - 60)."\t{$i}",
                    }
                : ['Halo 3', 'Gran Turismo 5', 'Super Mario Galaxy', 'Diablo II: Lord of Destruction'][$i % 4]."\t".['X360', 'PS3', 'Wii', 'pc'][$i % 4]."\t".($now + 3600 * ($i % 3))."\t{$now}\t".($i % 6)."\t".($now - 10 * ($i % 9))."\t".['xbox 360,pc', 'playstation 3', 'nintendo wii,wiiu', 'PC,Mac'][$i % 4];

                $titles[] = $odd
                    ? match ($i % 5) {
                        0 => " TT00{$i} \t \tReleased circa ".(1890 + $i % 200).'-'.(1990 + $i % 30)."\t10.5\tDrama, drama ,DRAMA,,Crime\t,,\t".implode(',', range(1, 14))."\tTV Mini Series",
                        1 => '',
                        2 => "nm{$i}\t\xc3\x89t\xc3\xa9\tunknown\t.5\t\t\t\tpodcast\textra",
                        3 => 'tt'.str_repeat('9', 11)."\t".str_repeat('Long Title ', 8)."\t".str_repeat('x', 40)."\t9.\t".implode(',', array_fill(0, 8, 'Sci-Fi'))."\ta,b\tc\tTVSERIES",
                        default => "{$i}\tT{$i}\t{$i}\t0{$i}.{$i}\tA\tB\tC\ttv_episode",
                    }
                : 'tt'.str_pad((string) (100000 + $i * 17), 7, '0', STR_PAD_LEFT)."\t".['The Shawshank Redemption', 'The Matrix', 'Inception', 'Breaking Bad'][$i % 4]."\t".(1990 + $i % 30)."\t".(($i % 9) + 1).'.'.($i % 10)."\t".['Drama', 'Action, Sci-Fi', 'Action,Adventure,Sci-Fi', 'Crime, Drama, Thriller'][$i % 4]."\t".['Frank Darabont', 'Lana Wachowski, Lilly Wachowski', 'Christopher Nolan', ''][$i % 4]."\t".implode(', ', \array_slice(['Tim Robbins', 'Morgan Freeman', 'Bob Gunton', 'Keanu Reeves', 'Carrie-Anne Moss', 'Leonardo DiCaprio'], 0, $i % 7))."\t".['movie', 'movie', 'movie', 'tvSeries'][$i % 4];

                $tracks[] = $odd
                    ? match ($i % 5) {
                        0 => "\x0c  The\t\tDark   Side \x0b of the Moon  \tMUSICARTIST\tebook\tusa\t999\tThe Dark Side of the Moon (Remastered)\tPink Floyd\tpodcast",
                        1 => '',
                        2 => "caf\xc3\xa9 del mar\tsong\taudiobook\t\xc3\xa9x\t-1\tCaf\xc3\xa9 del Mar\tVarious\tTRACK\textra",
                        3 => str_repeat('love song ', 8)."\tsoftware\tmovie\t1a\t0\t".str_repeat('Love ', 10)."\t".str_repeat('Song ', 10)."\tfeature-movie",
                        default => "{$i}\talbum\tmusic\t \t{$i}\t{$i}\t\tcollection",
                    }
                : ['Abbey Road', 'Thriller', 'Kind of Blue', 'Dune'][$i % 4].($i % 3 === 0 ? ' deluxe' : '')."\t".['album', 'song', 'musicArtist', 'ebook'][$i % 4]."\t".['music', 'music', 'music', 'ebook'][$i % 4]."\t".['us', 'gb', 'de', ''][$i % 4]."\t".['', '10', '25', '50'][$i % 4]."\t".['Abbey Road (Remastered)', 'Thriller', 'Kind of Blue', 'Dune'][$i % 4]."\t".['The Beatles', 'Michael Jackson', 'Miles Davis', 'Frank Herbert'][$i % 4]."\t".['collection', 'track', 'artist', 'ebook'][$i % 4];

                $size = 1_048_576 * ($i + 1);
                $checkpoints[] = $odd
                    ? match ($i % 5) {
                        0 => " {$size} \t".($size * 2)."\t0\t2048\t".($i + 1)."\t".($i + 2)."\t".($now + 600)."\t{$now}\t8388608\t1000\t0\tx",
                        1 => '',
                        2 => "-1\t1.5\tx\t\t0\t\t\t\t\t\t\t\textra",
                        3 => "999999999999999999\t1\t1\t1\t1\t1\t1\t999999999999\t999999999999999999\t999999999999999999\t1\t0",
                        default => "{$size}\t{$size}\t{$i}\t1024\t{$i}\t{$i}\t{$now}\t{$now}\t\t\t\t",
                    }
                : "{$size}\t".($size - 4096 * ($i % 300))."\t".($i * 20)."\t".($i % 7 === 0 ? 512 : 1024)."\t".(1000 + $i)."\t".(1000 + $i)."\t".($now - 30 * ($i % 10))."\t{$now}\t".(4096 * $i)."\t".($i % 1200)."\t1000\t1048576";
            }

            $workloads[GoogleBooksService::class][$name] = static fn (bool $rust) => $rust ? GoogleBooksService::lookupPlanBatchNative($books) : GoogleBooksService::lookupPlanBatchPhp($books);
            $workloads[IGDBService::class][$name] = static fn (bool $rust) => $rust ? IGDBService::lookupPlanBatchNative($games) : IGDBService::lookupPlanBatchPhp($games);
            $workloads[ImdbScraper::class][$name] = static fn (bool $rust) => $rust ? ImdbScraper::scrapePlanBatchNative($titles) : ImdbScraper::scrapePlanBatchPhp($titles);
            $workloads[ItunesService::class][$name] = static fn (bool $rust) => $rust ? ItunesService::lookupPlanBatchNative($tracks) : ItunesService::lookupPlanBatchPhp($tracks);
            $workloads[LogIndexer::class][$name] = static fn (bool $rust) => $rust ? LogIndexer::checkpointPlanBatchNative($checkpoints) : LogIndexer::checkpointPlanBatchPhp($checkpoints);
        }

        foreach ($workloads as $class => $byName) {
            $results = [];
            foreach ($byName as $name => $workload) {
                $this->assertSame($workload(false), $workload(true), "{$class} {$name} benchmark workload must agree");
                for ($w = 0; $w < 20; $w++) {
                    $workload(false);
                    $workload(true);
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
                    foreach (mt_rand(0, 1) === 1 ? [true, false] : [false, true] as $rust) {
                        $best = PHP_INT_MAX;
                        for ($r = 0; $r < self::REPEATS; $r++) {
                            $t0 = hrtime(true);
                            $workload($rust);
                            $best = min($best, hrtime(true) - $t0);
                        }
                        $times[$rust ? 'rust' : 'php'][] = $best / 1000;
                    }
                }

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, \sprintf(
                "\n[Wave 61.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $floors[$class] = min($results['common']['mean'], $results['common']['p99'], $results['uncommon']['mean'], $results['uncommon']['p99']);
        }
        fwrite(STDERR, "\n");

        // Every helper is reported before the gate is asserted; Rust defaults must still clear 1.5x.
        foreach ($floors as $class => $floor) {
            $this->assertSame(self::DEFAULTS[$class], $class::usesW61RustFfi(), $class);
            $this->assertSame(min(self::QUALIFICATION[$class]) >= 1.5, $class::usesW61RustFfi(), $class);
            if ($class::usesW61RustFfi()) {
                $this->assertGreaterThanOrEqual(1.5, $floor, \sprintf('%s: a Rust default must clear the 1.5x gate (min ratio %.2fx)', $class, $floor));
            } else {
                $this->assertFalse($class::usesW61RustFfi(), "{$class} retains PHP by default");
            }
        }
    }

    /**
     * Asserts that the PHP and native variants (and both toggled entry points) agree, and equal $expected
     * unless it is null.
     *
     * @param  class-string  $class
     * @param  list<string>  $records
     * @param  list<int>|null  $expected
     */
    private function assertBoth(string $class, array $records, ?array $expected): void
    {
        $method = self::METHODS[$class];
        $php = $class::{$method.'Php'}($records);
        if ($expected !== null) {
            $this->assertSame($expected, $php, "{$class} PHP");
        }
        $this->assertSame($php, $class::{$method.'Native'}($records), "{$class} native ".var_export($records, true));
        $this->assertSame($php, $class::withW61RustFfi(true, static fn () => $class::{$method}($records)), "{$class} Rust route");
        $this->assertSame($php, $class::withW61RustFfi(false, static fn () => $class::{$method}($records)), "{$class} PHP route");
    }

    /**
     * Three representative records per class.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function firstRecords(string $class): array
    {
        return match ($class) {
            GoogleBooksService::class => ["dune\tDune\tFrank Herbert\t\tDune\tFrank Herbert\t10", "\t\t\t0306406152\tx", ''],
            IGDBService::class => ["Halo 3\tx360\t1700003600\t1700000000\t2\t1699999990\txbox 360", "--\tbogus\tx", ''],
            ImdbScraper::class => ["tt0111161\tThe Shawshank Redemption\t1994\t9.3\tDrama\tFrank Darabont\tTim Robbins\tmovie", "x\t\tbad", ''],
            ItunesService::class => ["abbey road\talbum\tmusic\tus\t10\tAbbey Road\tThe Beatles\tcollection", "\tbogus\tx", ''],
            LogIndexer::class => ["4096\t1024\t10\t1024\t5\t5\t1700000000\t1700000060\t512\t3\t1000\t1048576", "x\t-1\tx", ''],
            default => throw new RuntimeException($class),
        };
    }

    /**
     * One record shaped like the class's input, built from plausible and adversarial pieces.
     *
     * @param  class-string  $class
     */
    private static function structuredRecord(string $class): string
    {
        $pick = static fn (array $items): string => (string) $items[mt_rand(0, \count($items) - 1)];
        $join = static function (string $separator, int $max, callable $part): string {
            $parts = [];
            for ($i = 0, $n = mt_rand(0, $max); $i < $n; $i++) {
                $parts[] = $part();
            }

            return implode($separator, $parts);
        };
        $number = static fn (): string => $pick(['', '0', '1', '3', '4', '5', '7', '10', '12', '00', '007', '-1', '-0', '+1', '1.5', ' 5', '5 ', 'x', '40', '41', '60', '61', '99', '120', '200', '201', '999', '1000', '1023', '1024', '1025', '4096', '65535', '8388607', '8388608', '8388609', '1048576', '1048577', (string) mt_rand(0, 1_000_000_000), (string) mt_rand(1_699_999_000, 1_700_001_000), '999999999999', '1000000000000', '999999999999999999', '1234567890123456789', self::randomBytes(3)]);
        $cut = static fn (array $fields, int $expected): array => \array_slice($fields, 0, mt_rand(0, 3) === 0 ? mt_rand(0, $expected + 1) : $expected);
        $words = static fn (): string => $pick(['', ' ', 'Dune', 'dune messiah', 'The Lord of the Rings', 'Harry Potter and the Philosopher\'s Stone', 'X-Men: 1', "Caf\xc3\xa9", "\xc3\x89t\xc3\xa9", 'Halo 3', 'Halo: Reach!!', 'Abbey Road', 'abbey  road', 'THE BEATLES', '1994', '--', "a\x0cb", "a\x0bb", 'Frank Herbert', 'Herbert, Frank', 'J.R.R. Tolkien', self::randomBytes(12)]);
        $mixed = static fn (): string => $join(' ', 4, $words);

        return match ($class) {
            GoogleBooksService::class => implode("\t", $cut([
                $mixed(), $mixed(), $mixed(),
                $pick(['', ' ', '978-0-306-40615-7', '9780306406157', '9780306406158', '0-306-40615-2', '030640615X', '030640615x', '123456789X', 'X123456789', '0306406152', 'isbn 0306406152', '9790000000001', '9771234567003', '978030640615', '97803064061577', 'xxxxxxxxxx', ' 978 0 306 40615 7 ', self::randomBytes(14)]),
                $mixed(), $mixed(), $number(),
                'extra',
            ], 7)),
            IGDBService::class => implode("\t", $cut([
                $mixed(),
                $pick(['', ' ', 'x360', 'X360', ' xbox360 ', 'xbla', 'xbox one', 'XboxOne', 'x-box', 'xbox', 'dsi', 'NDS', '3ds', 'ps2', 'PS3', 'ps4', 'psp', 'psvita', 'psx', 'psx2psp', 'wiiu', 'wii', 'ngc', 'gc', 'n64', 'nes', 'super nintendo', 'Nintendo Super NES', 'snes', 'pc', 'PC', 'gamecube', 'Xbox 360', 'playstation 3', 'virtual boy', "\xc3\xa9", self::randomBytes(5)]),
                $number(), $number(), $pick(['', '0', '1', '3', '4', '5', '999999', '1000000', 'x', '-1']), $number(),
                $join(',', 4, static fn (): string => $pick(['', ' ', 'pc', 'PC', 'xbox 360', 'X360', 'ps3', 'nintendo 64', 'N64', 'snes', 'gamecube', 'bogus'])),
                'extra',
            ], 7)),
            ImdbScraper::class => implode("\t", $cut([
                $pick(['', ' ', 'tt0111161', 'TT0111161', 'Tt0111161', 'tt1234567', 'tt12345678', 'tt123456789', 'tt1234567890', 'tt12345678901', '0111161', 'tt', 'tt0', 'tt0000000', 'nm0000001', ' tt0133093 ', 't1', '1', self::randomBytes(10)]),
                $mixed(),
                $pick(['', ' ', '1994', '(2001)', '1899', '2100', '1900', '2099', 'circa 1987-1990', 'unknown', '19x9', '1919', '20201', '2O20', self::randomBytes(6)]),
                $pick(['', ' ', '9.3', '10', '10.0', '10.1', '0', '0.0', '7.25', '.5', '9.', '99', '100', '07.5', '1.2.3', 'x', '-1', self::randomBytes(3)]),
                $join(',', 5, static fn (): string => $pick(['', ' ', 'Drama', 'drama', ' DRAMA ', 'Action', 'Sci-Fi', 'sci-fi', '1', '01', "\xc3\xa9", self::randomBytes(3)])),
                $join(',', 3, static fn (): string => $pick(['', ' ', 'Frank Darabont', 'Lana Wachowski', self::randomBytes(4)])),
                $join(',', 14, static fn (): string => $pick(['', ' ', 'Tim Robbins', 'Morgan Freeman', 'a', self::randomBytes(3)])),
                $pick(['', ' ', 'movie', 'Movie', 'tvSeries', 'tv_series', 'TV Mini Series', 'tv_mini_series', 'tvminiseries', 'tvspecial', 'tv_special', 'tvMovie', 'tv_movie', 'tvEpisode', 'tv_episode', 'documentary', 'tv series', self::randomBytes(4)]),
                'extra',
            ], 8)),
            ItunesService::class => implode("\t", $cut([
                $mixed(),
                $pick(['', ' ', 'album', 'Album', 'song', 'SONG', 'musicArtist', 'musicartist', 'ebook', 'audiobook', 'podcast', 'software', self::randomBytes(4)]),
                $pick(['', ' ', 'music', 'Music', 'ebook', 'audiobook', 'all', 'ALL', 'movie', self::randomBytes(4)]),
                $pick(['', ' ', 'us', 'US', 'gb', 'De', ' jp ', 'zz', 'usa', 'u', '1a', "\xc3\xa9x", "u\xc3", self::randomBytes(2)]),
                $number(), $mixed(), $mixed(),
                $pick(['', ' ', 'track', 'TRACK', 'collection', 'ebook', 'audiobook', 'artist', 'feature-movie', self::randomBytes(4)]),
                'extra',
            ], 8)),
            LogIndexer::class => implode("\t", $cut([
                $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(), $number(),
                'extra',
            ], 12)),
            default => throw new RuntimeException($class),
        };
    }

    /** Splices random fragments and bytes into $record at random offsets. */
    private static function spliced(string $record, bool $withNul): string
    {
        for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
            $insert = mt_rand(0, 1) === 1 ? self::FRAGMENTS[mt_rand(0, \count(self::FRAGMENTS) - 1)] : self::randomBytes(4, $withNul);
            $at = mt_rand(0, \strlen($record));
            $record = substr($record, 0, $at).$insert.substr($record, $at);
        }

        return $record;
    }

    /**
     * The C entry point of $class, called by name through the FFI handle.
     *
     * @param  class-string  $class
     */
    private static function abiFunction(string $class): string
    {
        return self::FUNCTIONS[$class][1];
    }

    /**
     * Invokes the class's private `w61RustFfiState()`.
     *
     * @param  class-string  $class
     */
    private static function state(string $class, ?string $op, mixed $val): mixed
    {
        return (new ReflectionMethod($class, 'w61RustFfiState'))->invoke(null, $op, $val);
    }

    private static function randomBytes(int $maxLength, bool $withNul = false): string
    {
        $value = '';
        for ($i = 0, $length = mt_rand(0, $maxLength); $i < $length; $i++) {
            $byte = self::BYTES[mt_rand(0, \count(self::BYTES) - 1)];
            $value .= $byte === "\0" && ! $withNul ? ' ' : $byte;
        }

        return $value;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    /**
     * Resolves the class's Wave 61.5 FFI handle or skips the test without the library.
     *
     * @param  class-string  $class
     */
    private function requireFfi(string $class): FFI
    {
        if (! \extension_loaded('FFI')) {
            $this->markTestSkipped('FFI extension not loaded.');
        }
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $cdef = (new ReflectionClassConstant($class, self::FUNCTIONS[$class][0]))->getValue();
        $ffi = \is_string($cdef) ? RustPipelineLibrary::resolveDomain('w61_5', $cdef, \is_string($path) && $path !== '' ? $path : null) : null;
        if (! $ffi instanceof FFI) {
            $this->markTestSkipped('Rust pipeline library not built.');
        }

        return $ffi;
    }
}
