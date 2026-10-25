<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BookProviderException;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\IGDB\Models\BaseModel;
use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\Models\Platform;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClassConstant;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class RustIgdbBookTvProviderMetadataParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        Company::class,
        Game::class,
        Platform::class,
        IgdbHttpException::class,
        BookProviderException::class,
    ];

    /** Strings that exercise trimming, collapsing, slugging, escaping, casing, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'Nintendo',
        ' Nintendo   EPD ',
        "Sony\tInteractive\nEntertainment, Inc.",
        'The Legend of Zelda: Breath of the Wild',
        'The "Last" of Us \\ Part II',
        '\\"',
        'PlayStation 4',
        ' ps4 ',
        'xbox series x|s',
        'PC (Microsoft Windows)',
        'Google Books',
        ' Open_Library ',
        'isbndb',
        'Too Many Requests',
        '{"message":"Not Found"}',
        "<html>\n<body>502 Bad Gateway</body>\n</html>",
        '--',
        '!!',
        'Ünïcödé  Gämé',
        "\u{00A0}nbsp\u{00A0}",
        'ポケモン',
        "emoji \u{1F3AE} game",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "\x1F",
        "nul\0byte",
    ];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 6, 48, 200, 392, 401, 403, 404, 408, 418, 429, 499, 500, 503, 599, 600, 999, 1000, 86400, 86401, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClassConstant($class, 'RUST_FFI_DEFAULT'))->getValue(), $class);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi(), $class);
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi(), $class);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi(), $class);
        }
    }

    public function test_model_toggles_are_independent_of_base_model(): void
    {
        Game::setUseRustFfi(true);
        $this->assertFalse(BaseModel::usesRustFfi());
        BaseModel::setUseRustFfi(true);
        try {
            $this->assertFalse(Company::usesRustFfi());
            $this->assertTrue(BaseModel::usesRustFfi());
        } finally {
            BaseModel::setUseRustFfi(null);
        }
    }

    public function test_existing_class_contracts_are_unchanged(): void
    {
        $this->assertSame('companies', (new ReflectionClassConstant(Company::class, 'ENDPOINT'))->getValue());
        $this->assertSame('games', (new ReflectionClassConstant(Game::class, 'ENDPOINT'))->getValue());
        $this->assertSame('platforms', (new ReflectionClassConstant(Platform::class, 'ENDPOINT'))->getValue());
        $this->assertSame(['id' => 7, 'name' => 'Zelda'], (new Game(['id' => 7, 'name' => 'Zelda']))->toArray());

        $http = new IgdbHttpException('rate limited', 429, '{"x":1}');
        $this->assertInstanceOf(RuntimeException::class, $http);
        $this->assertSame(429, $http->getStatusCode());
        $this->assertSame(429, $http->getCode());
        $this->assertSame('{"x":1}', $http->getResponseBody());

        $previous = new RuntimeException('inner');
        $book = new BookProviderException('google', 'boom', 503, 30, $previous);
        $this->assertSame('google', $book->provider);
        $this->assertSame(503, $book->statusCode);
        $this->assertSame(30, $book->retryAfterSeconds);
        $this->assertSame(503, $book->getCode());
        $this->assertSame($previous, $book->getPrevious());
        $this->assertSame(0, (new BookProviderException('x', 'y'))->getCode());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame(
            ['id' => 70, 'name' => 'Nintendo EPD', 'slug' => 'nintendo-epd', 'country' => 392, 'is_valid' => true],
            Company::normalizeCompanyRecordPhp(70, " Nintendo \t EPD\n", 392),
        );
        $this->assertSame(
            ['id' => null, 'name' => '', 'slug' => '', 'country' => null, 'is_valid' => false],
            Company::normalizeCompanyRecordPhp(0, " \0 ", 1000),
        );
        $this->assertSame(
            [
                'search' => 'The "Last" of Us',
                'escaped' => 'The \\"Last\\" of Us',
                'limit' => 500,
                'platform_id' => 48,
                'query' => 'search "The \\"Last\\" of Us"; fields *; where platforms = (48); limit 500;',
                'is_valid' => true,
            ],
            Game::normalizeGameLookupSpecPhp('  The "Last"   of Us ', 9999, 48),
        );
        $this->assertSame(
            ['search' => '', 'escaped' => '', 'limit' => 1, 'platform_id' => null, 'query' => 'search ""; fields *; limit 1;', 'is_valid' => false],
            Game::normalizeGameLookupSpecPhp('', -3, 0),
        );
        $this->assertSame(10, Game::normalizeGameLookupSpecPhp('x')['limit']);
        $this->assertSame(
            ['id' => 48, 'name' => 'PlayStation 4', 'slug' => 'playstation-4', 'abbreviation' => 'PS4', 'label' => 'PS4', 'is_valid' => true],
            Platform::normalizePlatformRecordPhp(48, 'PlayStation  4', ' ps4 '),
        );
        $this->assertSame(
            ['id' => 6, 'name' => 'PC', 'slug' => 'pc', 'abbreviation' => null, 'label' => 'PC', 'is_valid' => true],
            Platform::normalizePlatformRecordPhp(6, 'PC', '  '),
        );
        $this->assertSame(
            ['status_code' => 429, 'category' => 'rate_limited', 'retryable' => true, 'message' => 'Too many', 'body_excerpt' => '{"x":1}'],
            IgdbHttpException::classifyHttpFailurePhp(429, ' Too  many ', ' {"x":1} '),
        );
        $this->assertSame(
            ['status_code' => 404, 'category' => 'not_found', 'retryable' => false, 'message' => 'IGDB request failed with HTTP status 404', 'body_excerpt' => null],
            IgdbHttpException::classifyHttpFailurePhp(404, '', ' '),
        );
        $this->assertSame(256, strlen((string) IgdbHttpException::classifyHttpFailurePhp(500, 'x', str_repeat('a', 300))['body_excerpt']));
        $this->assertSame('unknown', IgdbHttpException::classifyHttpFailurePhp(302, 'x')['category']);
        $this->assertSame('transport', IgdbHttpException::classifyHttpFailurePhp(0, 'x')['category']);
        $this->assertSame(
            ['provider' => 'google_books', 'status_code' => 429, 'category' => 'rate_limited', 'retryable' => true, 'retry_after_seconds' => 120, 'backoff_seconds' => 120],
            BookProviderException::classifyProviderFailurePhp(' Google Books ', 429, 120),
        );
        $this->assertSame(
            ['provider' => 'unknown', 'status_code' => null, 'category' => 'transport', 'retryable' => true, 'retry_after_seconds' => null, 'backoff_seconds' => 30],
            BookProviderException::classifyProviderFailurePhp('!!', null, -5),
        );
        $this->assertSame(60, BookProviderException::classifyProviderFailurePhp('x', 429, null)['backoff_seconds']);
        $this->assertSame(86400, BookProviderException::classifyProviderFailurePhp('x', 503, PHP_INT_MAX)['retry_after_seconds']);
        $this->assertSame(0, BookProviderException::classifyProviderFailurePhp('x', 401, null)['backoff_seconds']);
    }

    public function test_company_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::TEXTS as $text) {
            foreach ([PHP_INT_MIN, 0, 1, PHP_INT_MAX] as $id) {
                foreach ([null, PHP_INT_MIN, 0, 1, 392, 999, 1000] as $country) {
                    $this->assertCompanyParity($id, $text, $country);
                }
            }
        }
    }

    public function test_game_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::TEXTS as $text) {
            foreach ([PHP_INT_MIN, 0, 1, 10, 500, 501, PHP_INT_MAX] as $limit) {
                foreach ([null, -1, 0, 48, PHP_INT_MAX] as $platform) {
                    $this->assertGameParity($text, $limit, $platform);
                }
            }
        }
    }

    public function test_platform_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::TEXTS as $name) {
            foreach ([null, ...self::TEXTS] as $abbreviation) {
                $this->assertPlatformParity(48, $name, $abbreviation);
            }
        }
        foreach (self::INTS as $id) {
            $this->assertPlatformParity($id, 'PC', 'pc');
        }
    }

    public function test_http_exception_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::INTS as $status) {
            foreach (self::TEXTS as $message) {
                $this->assertHttpParity($status, $message, null);
                $this->assertHttpParity($status, $message, $message);
            }
        }
        $this->assertHttpParity(500, 'x', '  '.str_repeat('é', 200).'  ');
    }

    public function test_book_provider_exception_native_and_dispatch_match_php(): void
    {
        $this->ffiOrSkip();
        foreach (self::TEXTS as $provider) {
            foreach ([null, ...self::INTS] as $status) {
                foreach ([null, PHP_INT_MIN, 0, 1, 86400, 86401, PHP_INT_MAX] as $retryAfter) {
                    $this->assertBookParity($provider, $status, $retryAfter);
                }
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $this->ffiOrSkip();
        $random = new Randomizer(new Mt19937(3504));
        $alphabet = "aZ09 \t\n\0\x0B\x0C\"\\-_.,:!|()é\xff\xc3";
        $string = static function () use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, 40);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }

            return $out;
        };
        $int = static fn (): int => $random->getInt(0, 3) === 0
            ? self::INTS[$random->getInt(0, count(self::INTS) - 1)]
            : $random->getInt(-1000, 1000);
        $nullableInt = static fn (): ?int => $random->getInt(0, 4) === 0 ? null : $int();
        $nullableString = static fn (): ?string => $random->getInt(0, 4) === 0 ? null : $string();

        for ($i = 0; $i < 400; $i++) {
            $this->assertCompanyParity($int(), $string(), $nullableInt());
            $this->assertGameParity($string(), $int(), $nullableInt());
            $this->assertPlatformParity($int(), $string(), $nullableString());
            $this->assertHttpParity($int(), $string(), $nullableString());
            $this->assertBookParity($string(), $nullableInt(), $nullableInt());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 70, 1, 392, 'Nintendo', 8, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $exact = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 70, 1, 392, 'Nintendo', 8, $exact, -$needed));
        $this->assertSame("70\x1FNintendo\x1Fnintendo\x1F392\x1F1", FFI::string($exact, -$needed));

        $needed = $this->callFfi($ffi, 'nntmux_w35_igdb_game_lookup_spec', 10, 0, 0, 'Zelda', 5, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $out = $ffi->new('char[512]');
        $written = $this->callFfi($ffi, 'nntmux_w35_igdb_game_lookup_spec', 10, 1, 48, 'Zelda', 5, $out, 512);
        $this->assertSame("Zelda\x1FZelda\x1F10\x1F48\x1Fsearch \"Zelda\"; fields *; where platforms = (48); limit 10;\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_igdb_platform_record', 6, 0, "PC\x1F", 3, $out, 512);
        $this->assertSame("6\x1FPC\x1Fpc\x1F\x1E\x1FPC\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_igdb_http_failure_classify', 503, 0, "down\x1F", 5, $out, 512);
        $this->assertSame("503\x1Fserver\x1F1\x1Fdown\x1F\x1E", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 3, 429, 90, 'isbndb', 6, $out, 512);
        $this->assertSame("isbndb\x1F429\x1Frate_limited\x1F1\x1F90\x1F90", FFI::string($out, $written));

        // Unknown flags, values behind cleared presence flags and malformed wire input decline with -1.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 2, 0, 'n', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 0, 392, 'n', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 0, 0, "a\x1Fb", 3, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 0, 0, "a\x1Eb", 3, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_game_lookup_spec', 10, 0, 48, 'z', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_game_lookup_spec', 10, 7, 0, 'z', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_platform_record', 1, 0, "PC\x1Fpc", 5, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_platform_record', 1, 1, 'PC', 2, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_platform_record', 1, 2, "PC\x1F", 3, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_http_failure_classify', 500, 0, "m\x1Fbody", 6, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_http_failure_classify', 500, 1, "m\x1Fa\x1Fb", 5, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_http_failure_classify', 500, 3, "m\x1F", 2, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 4, 0, 0, 'x', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 0, 500, 0, 'x', 1, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 1, 500, 30, 'x', 1, $out, 512));

        // Null pointers decline instead of crashing.
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 0, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_game_lookup_spec', 1, 0, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_platform_record', 1, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_http_failure_classify', 1, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 0, 0, 0, null, 0, $out, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_igdb_company_record', 1, 0, 0, 'n', 1, null, 512));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_w35_book_provider_failure_classify', 0, 0, 0, 'x', 1, null, 0));

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        Company::normalizeCompanyRecord(1, 'Nintendo');
        Game::normalizeGameLookupSpec('Zelda');
        Platform::normalizePlatformRecord(6, 'PC');
        IgdbHttpException::classifyHttpFailure(500, 'down');
        BookProviderException::classifyProviderFailure('google', 503, null);
        foreach (self::CLASSES as $class) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'ffi'))->getValue(), $class.' must resolve its native export');
        }
    }

    public function test_missing_library_falls_back_to_php(): void
    {
        $property = static fn (string $class): ReflectionProperty => new ReflectionProperty($class, 'ffi');
        $saved = [];
        foreach (self::CLASSES as $class) {
            $saved[$class] = $property($class)->getValue();
            $property($class)->setValue(null, false);
            $class::setUseRustFfi(true);
        }

        try {
            $this->assertSame(Company::normalizeCompanyRecordPhp(1, ' Nintendo ', 392), Company::normalizeCompanyRecord(1, ' Nintendo ', 392));
            $this->assertSame(Game::normalizeGameLookupSpecPhp(' "Zelda" ', 0, 48), Game::normalizeGameLookupSpec(' "Zelda" ', 0, 48));
            $this->assertSame(Platform::normalizePlatformRecordPhp(48, 'PlayStation 4', 'ps4'), Platform::normalizePlatformRecord(48, 'PlayStation 4', 'ps4'));
            $this->assertSame(IgdbHttpException::classifyHttpFailurePhp(429, '', 'body'), IgdbHttpException::classifyHttpFailure(429, '', 'body'));
            $this->assertSame(BookProviderException::classifyProviderFailurePhp('Google', null, 5), BookProviderException::classifyProviderFailure('Google', null, 5));
            $this->assertSame(Company::normalizeCompanyRecordPhp(1, 'x'), Company::normalizeCompanyRecordNative(1, 'x'));
        } finally {
            foreach (self::CLASSES as $class) {
                $property($class)->setValue(null, $saved[$class]);
            }
        }

        $missing = RustPipelineLibrary::resolveDomain('metadata', Company::RECORD_FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical IGDB company/game/platform rows and HTTP/book provider failures; uncommon = long,
        // Unicode, padded, out-of-range and wire-marker fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 3 rounds):
        // - Round 1: common mean 0.34x / p99 0.33x, uncommon mean 1.04x / p99 1.01x
        // - Round 2: common mean 0.34x / p99 0.36x, uncommon mean 1.06x / p99 1.05x
        // - Round 3: common mean 0.34x / p99 0.35x, uncommon mean 1.03x / p99 1.01x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustIgdbBookTvProviderMetadataParityTest.php --filter benchmark
        // Microsecond PHP string/int work is cheaper than the FFI round trip, so every class stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3504));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustIgdbBookTvProviderMetadata speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::CLASSES as $class) {
                $this->assertFalse($class::usesRustFfi(), $class.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertCompanyParity(int $id, string $name, ?int $country): void
    {
        $expected = Company::normalizeCompanyRecordPhp($id, $name, $country);
        $context = json_encode([$id, bin2hex($name), $country]) ?: '';
        $this->assertSameAttributes($expected, Company::normalizeCompanyRecordNative($id, $name, $country), $context);
        Company::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Company::normalizeCompanyRecord($id, $name, $country), $context);
        Company::setUseRustFfi(false);
        $this->assertSameAttributes($expected, Company::normalizeCompanyRecord($id, $name, $country), $context);
        Company::setUseRustFfi(null);
    }

    private function assertGameParity(string $title, int $limit, ?int $platform): void
    {
        $expected = Game::normalizeGameLookupSpecPhp($title, $limit, $platform);
        $context = json_encode([bin2hex($title), $limit, $platform]) ?: '';
        $this->assertSameAttributes($expected, Game::normalizeGameLookupSpecNative($title, $limit, $platform), $context);
        Game::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Game::normalizeGameLookupSpec($title, $limit, $platform), $context);
        Game::setUseRustFfi(false);
        $this->assertSameAttributes($expected, Game::normalizeGameLookupSpec($title, $limit, $platform), $context);
        Game::setUseRustFfi(null);
    }

    private function assertPlatformParity(int $id, string $name, ?string $abbreviation): void
    {
        $expected = Platform::normalizePlatformRecordPhp($id, $name, $abbreviation);
        $context = json_encode([$id, bin2hex($name), $abbreviation === null ? null : bin2hex($abbreviation)]) ?: '';
        $this->assertSameAttributes($expected, Platform::normalizePlatformRecordNative($id, $name, $abbreviation), $context);
        Platform::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Platform::normalizePlatformRecord($id, $name, $abbreviation), $context);
        Platform::setUseRustFfi(false);
        $this->assertSameAttributes($expected, Platform::normalizePlatformRecord($id, $name, $abbreviation), $context);
        Platform::setUseRustFfi(null);
    }

    private function assertHttpParity(int $status, string $message, ?string $body): void
    {
        $expected = IgdbHttpException::classifyHttpFailurePhp($status, $message, $body);
        $context = json_encode([$status, bin2hex($message), $body === null ? null : bin2hex($body)]) ?: '';
        $this->assertSameAttributes($expected, IgdbHttpException::classifyHttpFailureNative($status, $message, $body), $context);
        IgdbHttpException::setUseRustFfi(true);
        $this->assertSameAttributes($expected, IgdbHttpException::classifyHttpFailure($status, $message, $body), $context);
        IgdbHttpException::setUseRustFfi(false);
        $this->assertSameAttributes($expected, IgdbHttpException::classifyHttpFailure($status, $message, $body), $context);
        IgdbHttpException::setUseRustFfi(null);
    }

    private function assertBookParity(string $provider, ?int $status, ?int $retryAfter): void
    {
        $expected = BookProviderException::classifyProviderFailurePhp($provider, $status, $retryAfter);
        $context = json_encode([bin2hex($provider), $status, $retryAfter]) ?: '';
        $this->assertSameAttributes($expected, BookProviderException::classifyProviderFailureNative($provider, $status, $retryAfter), $context);
        BookProviderException::setUseRustFfi(true);
        $this->assertSameAttributes($expected, BookProviderException::classifyProviderFailure($provider, $status, $retryAfter), $context);
        BookProviderException::setUseRustFfi(false);
        $this->assertSameAttributes($expected, BookProviderException::classifyProviderFailure($provider, $status, $retryAfter), $context);
        BookProviderException::setUseRustFfi(null);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['company', [70, 'Nintendo EPD', 392]],
            ['company', [1234, 'Ubisoft Montreal', 124]],
            ['game', ['The Legend of Zelda: Breath of the Wild', 10, 130]],
            ['game', ['Halo Infinite', 5, null]],
            ['platform', [48, 'PlayStation 4', 'PS4']],
            ['platform', [6, 'PC (Microsoft Windows)', 'PC']],
            ['http', [429, 'Too Many Requests', '{"message":"Too Many Requests"}']],
            ['http', [500, 'Internal Server Error', null]],
            ['book', ['google', 429, 60]],
            ['book', ['openlibrary', 503, null]],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Gämé  Tïtlé  ', 40);

        return [
            ['company', [PHP_INT_MIN, "  \t".$long."\n ", PHP_INT_MAX]],
            ['company', [1, "sep\x1Finside", 1]],
            ['game', ['  '.str_repeat('"Quoted" \\ title  ', 20), PHP_INT_MAX, PHP_INT_MIN]],
            ['game', ["bad \xff utf8 \x1E", PHP_INT_MIN, 0]],
            ['platform', [PHP_INT_MAX, $long, str_repeat(' xbox  series ', 10)]],
            ['platform', [0, '', '   ']],
            ['http', [PHP_INT_MIN, " \0 ", str_repeat("<html>\n<body>502</body></html>\n", 40)]],
            ['http', [0, "\x1F", "\x1E"]],
            ['book', [str_repeat(' Google Books! ', 20), PHP_INT_MAX, PHP_INT_MAX]],
            ['book', ['!!', null, PHP_INT_MIN]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'company' => Company::normalizeCompanyRecord(...$args),
                'game' => Game::normalizeGameLookupSpec(...$args),
                'platform' => Platform::normalizePlatformRecord(...$args),
                'http' => IgdbHttpException::classifyHttpFailure(...$args),
                default => BookProviderException::classifyProviderFailure(...$args),
            };
            $count += count($result);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            Company::RECORD_FFI_CDEF,
            Game::LOOKUP_FFI_CDEF,
            Platform::RECORD_FFI_CDEF,
            IgdbHttpException::FFI_CDEF,
            BookProviderException::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('metadata', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the IGDB/book provider metadata exports is not available.');
        }

        return $ffi;
    }
}
