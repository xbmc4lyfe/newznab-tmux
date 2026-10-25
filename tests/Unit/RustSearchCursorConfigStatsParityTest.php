<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\CollectStats;
use App\Console\Commands\WarmAdminDashboard;
use App\Models\MetadataConfiguration;
use App\Models\SiteConfiguration;
use App\Services\Search\DTO\SearchCursor;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. See the commit message for the measured run; each run prints
 * its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and decoding dominate these sub-microsecond helpers, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default (`usesRustFfi() === false`).
 */
class RustSearchCursorConfigStatsParityTest extends TestCase
{
    private const CLASSES = [
        SearchCursor::class,
        MetadataConfiguration::class,
        SiteConfiguration::class,
        CollectStats::class,
        WarmAdminDashboard::class,
    ];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        (new ReflectionClass(SearchCursor::class))->setStaticPropertyValue('ffi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_search_cursor_properties_stay_readonly(): void
    {
        $cursor = new SearchCursor([10], 5, 'q', 'manticore', '1', 100);
        foreach ((new ReflectionClass($cursor))->getProperties() as $property) {
            $this->assertSame(! $property->isStatic(), $property->isReadOnly(), $property->getName());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int, 4: string, 5: string, 6: string, 7: int, 8: string}>
     */
    public static function cursorMatchProvider(): array
    {
        return [
            'valid' => ['q', 'manticore', '1', 100, 'q', 'manticore', '1', 100, 'valid'],
            'expires now is still valid' => ['q', 'd', 'g', 10, 'q', 'd', 'g', 10, 'valid'],
            'expired' => ['q', 'd', 'g', 9, 'q', 'd', 'g', 10, 'expired'],
            'expired wins over mismatch' => ['x', 'y', 'z', 9, 'q', 'd', 'g', 10, 'expired'],
            'query mismatch' => ['x', 'y', 'g', 11, 'q', 'd', 'g', 10, 'query_mismatch'],
            'driver mismatch' => ['q', 'y', 'z', 11, 'q', 'd', 'g', 10, 'driver_mismatch'],
            'generation mismatch is case-sensitive' => ['q', 'd', 'G', 11, 'q', 'd', 'g', 10, 'generation_mismatch'],
            'binary hashes' => ["\0\xff", "d\n", '', PHP_INT_MAX, "\0\xff", "d\n", '', PHP_INT_MIN, 'valid'],
        ];
    }

    #[DataProvider('cursorMatchProvider')]
    public function test_cursor_match_parity(string $query, string $driver, string $generation, int $expires, string $wantQuery, string $wantDriver, string $wantGeneration, int $now, string $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, SearchCursor::classifyCursorMatchPhp($query, $driver, $generation, $expires, $wantQuery, $wantDriver, $wantGeneration, $now));
        $this->assertSame($expected, SearchCursor::classifyCursorMatchNative($query, $driver, $generation, $expires, $wantQuery, $wantDriver, $wantGeneration, $now));
        $cursor = new SearchCursor([1], 0, $query, $driver, $generation, $expires);
        SearchCursor::setUseRustFfi(true);
        $this->assertSame($expected, $cursor->matchState($wantQuery, $wantDriver, $wantGeneration, $now));
    }

    /**
     * @return array<string, array{0: array<array-key, int|float|string>, 1: int}>
     */
    public static function resumeOffsetProvider(): array
    {
        return [
            'offset' => [[250], 250],
            'zero' => [[0], 0],
            'negative clamps' => [[-7], 0],
            'max int' => [[PHP_INT_MAX], PHP_INT_MAX],
            'min int' => [[PHP_INT_MIN], 0],
            'canonical string' => [['42'], 42],
            'numeric prefix string (PHP)' => [['12abc'], 12],
            'padded string (PHP)' => [[' 7'], 7],
            'exponent string (PHP)' => [['1e3'], 1000],
            'negative zero string (PHP)' => [['-0'], 0],
            'empty string' => [[''], 0],
            'float (PHP)' => [[12.9], 12],
            'negative float (PHP)' => [[-0.5], 0],
            'empty' => [[], 0],
            'sort pair' => [[1700000000, 99], 0],
            'non-list key' => [[5 => 9], 9],
        ];
    }

    /**
     * @param  array<array-key, int|float|string>  $sort
     */
    #[DataProvider('resumeOffsetProvider')]
    public function test_resume_offset_parity(array $sort, int $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, SearchCursor::resumeOffsetFromSortValuesPhp($sort));
        $this->assertSame($expected, SearchCursor::resumeOffsetFromSortValuesNative($sort));
        if (array_is_list($sort)) {
            SearchCursor::setUseRustFfi(true);
            $this->assertSame($expected, (new SearchCursor($sort, 0, '', '', '', 0))->resumeOffset());
        }
    }

    public function test_lookup_summary_parity_and_model_columns(): void
    {
        $this->requireFfi();

        $this->assertSame(['enabled' => 3, 'renamed_only' => 2, 'disabled' => 1, 'invalid' => 2], MetadataConfiguration::summarizeLookupModesPhp(0, 1, 2, 2, 9, -1));
        mt_srand(3405);
        for ($i = 0; $i < 300; $i++) {
            $modes = array_map(static fn (): int => mt_rand(0, 9) === 0 ? mt_rand(PHP_INT_MIN, PHP_INT_MAX) : mt_rand(-1, 3), range(1, 6));
            $this->assertSame(MetadataConfiguration::summarizeLookupModesPhp(...$modes), MetadataConfiguration::summarizeLookupModesNative(...$modes), json_encode($modes) ?: '');
        }

        $model = new MetadataConfiguration;
        $model->setRawAttributes(['anime_lookup' => 1, 'book_lookup' => 0, 'game_lookup' => 2, 'movie_lookup' => 1, 'music_lookup' => 0, 'tv_lookup' => 2]);
        MetadataConfiguration::setUseRustFfi(true);
        $this->assertSame(['enabled' => 4, 'renamed_only' => 2, 'disabled' => 2, 'invalid' => 0], $model->lookupSummary());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movieLanguageProvider(): array
    {
        return [
            'plain' => ['en', 'en'],
            'trim and lower' => [" DE \n", 'de'],
            'region' => ['pt-BR', 'pt-br'],
            'eight bytes' => ['abcdefgh', 'abcdefgh'],
            'nine bytes' => ['abcdefghi', 'en'],
            'blank' => [" \t\0\x0B", 'en'],
            'inner space' => ['e n', 'en'],
            'underscore' => ['pt_BR', 'en'],
            'multibyte' => ["\xc3\x89s", 'en'],
            'trailing newlines trimmed' => ["fr\n\n", 'fr'],
            'nul inside' => ["f\0r", 'en'],
        ];
    }

    #[DataProvider('movieLanguageProvider')]
    public function test_movie_language_parity(string $language, string $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, MetadataConfiguration::normalizeMovieLanguagePhp($language));
        $this->assertSame($expected, MetadataConfiguration::normalizeMovieLanguageNative($language));
    }

    public function test_site_configuration_parity(): void
    {
        $this->requireFfi();

        $this->assertSame(['width' => 480, 'height' => 345], SiteConfiguration::normalizeTrailerDimensionsPhp(0, -4));
        $this->assertSame(['width' => 640, 'height' => 65535], SiteConfiguration::normalizeTrailerDimensionsNative(640, 70000));
        mt_srand(8181);
        for ($i = 0; $i < 300; $i++) {
            [$width, $height] = [mt_rand(-100, 70000), mt_rand(0, 4) === 0 ? mt_rand(PHP_INT_MIN, PHP_INT_MAX) : mt_rand(-1, 1000)];
            $this->assertSame(SiteConfiguration::normalizeTrailerDimensionsPhp($width, $height), SiteConfiguration::normalizeTrailerDimensionsNative($width, $height));
        }

        foreach (['/', '', " \t\n", ' /home ', "https://nn.test/\0", "\xc3\x89", str_repeat('a', 4096)] as $link) {
            $this->assertSame(SiteConfiguration::normalizeHomeLinkPhp($link), SiteConfiguration::normalizeHomeLinkNative($link), bin2hex($link));
        }
        $this->assertSame('/', SiteConfiguration::normalizeHomeLinkNative("\n"));

        $model = new SiteConfiguration;
        $model->setRawAttributes(['trailers_size_x' => '0', 'trailers_size_y' => 200]);
        SiteConfiguration::setUseRustFfi(true);
        $this->assertSame(['width' => 480, 'height' => 200], $model->trailerDimensions());
    }

    public function test_command_formatting_parity(): void
    {
        $this->requireFfi();

        foreach ([['Top grabbers', 12], ['Users by role', 0], ['x', -5], ["\xc3\x89 \0", PHP_INT_MAX], ['', PHP_INT_MIN]] as [$label, $ms]) {
            $this->assertSame(CollectStats::formatStepMessagePhp($label, $ms), CollectStats::formatStepMessageNative($label, $ms));
        }
        $this->assertSame('Top grabbers collected in 12 ms.', CollectStats::formatStepMessagePhp('Top grabbers', 12));
        $this->assertSame('x collected in 0 ms.', CollectStats::formatStepMessagePhp('x', -5));

        $this->assertSame(['elapsed_ms' => 250, 'message' => 'Admin dashboard snapshot warmed in 250 ms.'], WarmAdminDashboard::formatWarmSummaryNative(10.0, 10.2505));
        mt_srand(4242);
        for ($i = 0; $i < 500; $i++) {
            $start = 1_700_000_000 + mt_rand() / mt_getrandmax() * 1e8;
            $end = $start + (mt_rand(0, 9) === 0 ? -1 : 1) * (mt_rand() / mt_getrandmax()) * 10 ** mt_rand(-6, 6);
            $this->assertSame(WarmAdminDashboard::formatWarmSummaryPhp($start, $end), WarmAdminDashboard::formatWarmSummaryNative($start, $end), "{$start} {$end}");
        }
        foreach ([[0.0, -0.0], [-0.0, 0.0], [0.0, 9.2233720368547e15], [0.0, -9.2233720368547e15], [1e-300, 2e-300]] as [$start, $end]) {
            $this->assertSame(WarmAdminDashboard::formatWarmSummaryPhp($start, $end), WarmAdminDashboard::formatWarmSummaryNative($start, $end));
        }
        // Non-representable durations decline in Rust and keep PHP's own (warning-emitting) conversion.
        foreach ([[0.0, NAN], [0.0, INF], [INF, INF], [0.0, 1e300], [0.0, 9.2233720368548e15]] as [$start, $end]) {
            $this->assertNull(SearchCursor::evaluateNative(8, [pack('e', $start), pack('e', $end)]));
            $this->assertSame(@WarmAdminDashboard::formatWarmSummaryPhp($start, $end), @WarmAdminDashboard::formatWarmSummaryNative($start, $end));
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['valid'], SearchCursor::evaluateNative(1, ['q', 'd', 'g', '2', 'q', 'd', 'g', '1']));
        $this->assertSame(['9'], SearchCursor::evaluateNative(2, ['1', '9']));
        $this->assertSame(['0'], SearchCursor::evaluateNative(2, ['2']));
        $this->assertSame(['1', '0', '5', '0'], SearchCursor::evaluateNative(3, ['1', '0', '0', '0', '0', '0']));
        $this->assertSame(['de'], SearchCursor::evaluateNative(4, [' DE ']));
        $this->assertSame(['480', '65535'], SearchCursor::evaluateNative(5, ['0', '65536']));
        $this->assertSame(['/home'], SearchCursor::evaluateNative(6, [" /home\n"]));
        $this->assertSame(['x collected in 0 ms.'], SearchCursor::evaluateNative(7, ['x', '-1']));
        $this->assertSame(['500', 'Admin dashboard snapshot warmed in 500 ms.'], SearchCursor::evaluateNative(8, [pack('e', 1.0), pack('e', 1.5)]));
        $this->assertNull(SearchCursor::evaluateNative(-1, ['x']));
        $this->assertNull(SearchCursor::evaluateNative(7, array_fill(0, 65, 'x')));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(SearchCursor::class))->setStaticPropertyValue('ffi', false);

        $this->assertNull(SearchCursor::evaluateNative(7, ['x', '1']));
        $this->assertSame('expired', SearchCursor::classifyCursorMatchNative('q', 'd', 'g', 1, 'q', 'd', 'g', 2));
        $this->assertSame(7, SearchCursor::resumeOffsetFromSortValuesNative([7]));
        $this->assertSame(MetadataConfiguration::summarizeLookupModesPhp(0, 1, 2, 3, 1, 1), MetadataConfiguration::summarizeLookupModesNative(0, 1, 2, 3, 1, 1));
        $this->assertSame('de', MetadataConfiguration::normalizeMovieLanguageNative(' DE '));
        $this->assertSame(['width' => 480, 'height' => 1], SiteConfiguration::normalizeTrailerDimensionsNative(0, 1));
        $this->assertSame('/', SiteConfiguration::normalizeHomeLinkNative(' '));
        $this->assertSame('x collected in 3 ms.', CollectStats::formatStepMessageNative('x', 3));
        $this->assertSame(WarmAdminDashboard::formatWarmSummaryPhp(1.0, 1.5), WarmAdminDashboard::formatWarmSummaryNative(1.0, 1.5));

        $missing = RustPipelineLibrary::resolveDomain('search', SearchCursor::FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_malformed_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['Top grabbers', '12']);
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w34_search_cursor_config_stats($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(7, $valid, strlen($valid), $buf, 256);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(self::packet(['Top grabbers collected in 12 ms.']), FFI::string($buf, $written));
        $this->assertSame(-$written, $call(7, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(7, $valid, strlen($valid), null, 0));
        $this->assertSame(-1, $call(7, null, 4, $buf, 256));
        $this->assertSame(-1, $call(7, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(0, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(9, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(7, '', 0, $small, 8));
        $this->assertSame(-1, $call(7, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(7, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(7, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $call(7, "\x41\0\0\0".str_repeat("\0", 260), 264, $small, 8));
        $this->assertSame(-1, $callParts(1, ['q', 'd', 'g', '+1', 'q', 'd', 'g', '0']));
        $this->assertSame(-1, $callParts(2, ['1']));
        $this->assertSame(-1, $callParts(2, ['1', '12abc']));
        $this->assertSame(-1, $callParts(2, ['-1']));
        $this->assertSame(-1, $callParts(3, ['0', '0', '0', '0', '0']));
        $this->assertSame(-1, $callParts(4, ['a', 'b']));
        $this->assertSame(-1, $callParts(5, ['640', '0x1']));
        $this->assertSame(-1, $callParts(6, []));
        $this->assertSame(-1, $callParts(7, ['x', '1.5']));
        $this->assertSame(-1, $callParts(8, [pack('e', 0.0), '1234567']));
        $this->assertSame(-1, $callParts(8, [pack('e', 0.0), pack('e', NAN)]));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $common = static function (): void {
            SearchCursor::classifyCursorMatch('5f2b9c', 'manticore', '1', 1_800_000_000, '5f2b9c', 'manticore', '1', 1_799_999_000);
            SearchCursor::resumeOffsetFromSortValues([100]);
            MetadataConfiguration::summarizeLookupModes(1, 0, 2, 1, 0, 1);
            MetadataConfiguration::normalizeMovieLanguage('en');
            SiteConfiguration::normalizeTrailerDimensions(480, 345);
            SiteConfiguration::normalizeHomeLink('/');
            CollectStats::formatStepMessage('Top grabbers', 12);
            WarmAdminDashboard::formatWarmSummary(1_799_999_000.125, 1_799_999_000.375);
        };
        $longHash = str_repeat('ab', 512);
        $longLink = ' https://nn.test/'.str_repeat('p', 1000)." \n";
        $uncommon = static function () use ($longHash, $longLink): void {
            SearchCursor::classifyCursorMatch($longHash, 'manticore', '17', PHP_INT_MAX, $longHash, 'manticore', '18', 0);
            SearchCursor::resumeOffsetFromSortValues(['12abc']);
            SearchCursor::resumeOffsetFromSortValues([1_700_000_000, 99]);
            MetadataConfiguration::summarizeLookupModes(9, -1, PHP_INT_MAX, 2, 2, 0);
            MetadataConfiguration::normalizeMovieLanguage(" \tPT-BR\n");
            SiteConfiguration::normalizeTrailerDimensions(-1, PHP_INT_MAX);
            SiteConfiguration::normalizeHomeLink($longLink);
            CollectStats::formatStepMessage('User activity daily stats', -3);
            WarmAdminDashboard::formatWarmSummary(1_799_999_000.0, 1_799_998_999.5);
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $name => $workload) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < 20; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 20_000;
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 34.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['uncommon']['mean'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search', SearchCursor::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
