<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MoviesProcessor;
use App\Services\MusicProcessor;
use App\Services\MusicService;
use App\Services\PostProcessService;
use App\Services\TvMazeClient;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 55.4 batch helpers: MoviesProcessor and MusicProcessor title tag rows, MusicService
 * lookup rows, PostProcessService TV lookup mode rows and TvMazeClient AKA name rows. PHP joins
 * every text column with NUL bytes (column after column) and packs the int64 header with
 * pack('P'); Rust writes fixed-width int64 words per record that PHP decodes with one
 * unpack('P*'), so a batch costs one FFI crossing. A value holding a NUL byte adds a field,
 * which the export rejects, and the batch falls back to PHP.
 *
 * Every helper keeps its routing override, cached binding and output buffer in the function
 * static of w55RustFfiState().
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times the
 * public dispatch including packing and decoding, and requires every default-enabled helper to
 * clear 1.5x on common and uncommon mean and p99. Pairs per workload: NNTMUX_W55_BENCH_PAIRS
 * (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at 4fd425d91 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~6-7), minimum speedup
 * per gate (common mean/p99, uncommon mean/p99): movieTitleTagRowBatch 6.36/3.87, 7.05/5.47;
 * musicTitleTagRowBatch 7.33/4.39, 8.29/5.88; musicLookupRowBatch 4.91/3.02, 5.97/4.81;
 * tvLookupModeRowBatch 1.19/1.00, 1.10/0.91; akaNameRowBatch 4.94/2.74, 3.48/3.16.
 * Common batches hold 64 realistic records; uncommon batches hold 192 mixing long, multibyte,
 * malformed and whitespace-heavy values with extreme integers. tvLookupModeRowBatch defaults
 * to PHP; the other four default to Rust.
 */
final class RustMoviesMusicProcessorMusicPostProcessTvMazeW55ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, index of a text argument]. */
    private const array HELPERS = [
        'movies' => [MoviesProcessor::class, 'movieTitleTagRowBatch', 4, 0],
        'music_processor' => [MusicProcessor::class, 'musicTitleTagRowBatch', 4, 0],
        'music_service' => [MusicService::class, 'musicLookupRowBatch', 4, 0],
        'post_process' => [PostProcessService::class, 'tvLookupModeRowBatch', 2, 0],
        'tvmaze' => [TvMazeClient::class, 'akaNameRowBatch', 4, 0],
    ];

    private const int SEED = 5542026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => $helper) {
            $cases[$name] = $helper;
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w55RustFfiState'))->invoke(null, ...$args);
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W55_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w55', $cdef);
    }

    private static function symbol(string $class): string
    {
        $cdef = (new ReflectionClass($class))->getConstant('W55_FFI_CDEF');
        self::assertIsString($cdef);
        self::assertSame(1, preg_match('/\b(nntmux_w554_\w+)\(/', $cdef, $match));

        return $match[1];
    }

    /**
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW55RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $args */
    private static function assertParity(string $class, string $method, int $words, array $args, string $label): void
    {
        $php = self::dispatch($class, $method, false, $args);
        self::assertSame($php, $class::{$method.'Php'}(...$args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertIsArray($args[0]);
        self::assertCount(count($args[0]) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    /**
     * Text and int buffers exactly as the native method builds them.
     *
     * @param  list<mixed>  $args
     * @return array{0: string, 1: string}
     */
    private static function encode(string $class, array $args): array
    {
        return match ($class) {
            MoviesProcessor::class, MusicProcessor::class, TvMazeClient::class => [implode("\0", $args[0]), ''],
            MusicService::class => [implode("\0", $args[0])."\0".implode("\0", $args[1]), ''],
            PostProcessService::class => [implode("\0", $args[0]), pack('P', $args[1])],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function emptyArgs(string $class): array
    {
        return match ($class) {
            MusicService::class => [[], []],
            PostProcessService::class => [[], 1],
            default => [[]],
        };
    }

    private static function span(int $offset, int $length): int
    {
        return ($offset << 32) | $length;
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w55_switch_api_and_default(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThan(0, $words);
        self::assertGreaterThanOrEqual(0, $textArg);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W55_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        foreach (['usesW55RustFfi', 'withW55RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w55RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());

        self::assertSame($constant->getValue(), $class::usesW55RustFfi());
        self::assertFalse($class::withW55RustFfi(false, static fn (): bool => $class::usesW55RustFfi()));
        self::assertTrue($class::withW55RustFfi(true, static fn (): bool => $class::usesW55RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW55RustFfi(), 'withW55RustFfi restores the default');

        $nested = $class::withW55RustFfi(false, static fn (): array => [
            $class::usesW55RustFfi(),
            $class::withW55RustFfi(true, static fn (): bool => $class::usesW55RustFfi()),
            $class::usesW55RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW55RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW55RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w55', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW55RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        // Each class keeps its own state.
        MusicService::withW55RustFfi(false, static function (): void {
            self::assertFalse(MusicService::usesW55RustFfi());
            self::assertNull(self::state(MusicProcessor::class, 'enabled'));
            self::assertNull(self::state(MoviesProcessor::class, 'enabled'));
            self::assertNull(self::state(PostProcessService::class, 'enabled'));
            self::assertNull(self::state(TvMazeClient::class, 'enabled'));
        });
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            MoviesProcessor::usesRustFfi(),
            MoviesProcessor::usesW414RustFfi(),
            MusicProcessor::usesRustFfi(),
            MusicProcessor::usesW424RustFfi(),
            MusicService::usesW404RustFfi(),
            PostProcessService::usesRustFfi(),
            PostProcessService::usesW466RustFfi(),
            TvMazeClient::usesRustFfi(),
            TvMazeClient::usesW464RustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW55RustFfi($enabled, $earlier), $class);
            }
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThan(0, $words);
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 4, false);
        $cases = [];
        $nonList = $args;
        $nonList[$textArg] = array_combine(range(1, 4), $args[$textArg]);
        $cases['non-list'] = $nonList;
        if ($class === MusicService::class) {
            $short = $args;
            $short[1] = array_slice($short[1], 1);
            $cases['short column'] = $short;
            $long = $args;
            $long[0][] = 'extra';
            $cases['long column'] = $long;
        }
        if ($class === PostProcessService::class) {
            foreach (['float' => 1.5, 'bool' => true, 'array' => [1]] as $label => $bad) {
                $invalid = $args;
                $invalid[0][2] = $bad;
                $cases[$label.' value'] = $invalid;
            }
        }
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, self::emptyArgs($class)));
            foreach ($cases as $label => $input) {
                foreach ([$method, $method.'Php', $method.'Native'] as $entry) {
                    try {
                        $class::withW55RustFfi($native, static fn (): array => $class::{$entry}(...$input));
                        self::fail($label.' must be rejected by '.$entry.'.');
                    } catch (InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts and agreement with the summary helpers
    // ---------------------------------------------------------------------

    public function test_movie_title_rows_match_summary(): void
    {
        $titles = ['The.Matrix.1999.1080p.BluRay.x264-GROUP', '  Dune 2021 2160p HDR10 WEB-DL  ', '', " \t ", 'Movie.1899.2100.DV.HEVC', 'x264x265'];
        $expected = [
            55, 7, 1999, self::span(0, 39),
            27, 6, 2021, self::span(2, 28),
            64, 0, -1, 0,
            64, 0, -1, self::span(3, 0),
            12, 5, -1, self::span(0, 23),
            0, 1, -1, self::span(0, 8),
        ];
        $bits = ['empty_title_count' => 64, 'resolution_tagged_count' => 1, 'source_tagged_count' => 2, 'codec_tagged_count' => 4, 'hdr_tagged_count' => 8, 'year_tagged_count' => 16, 'complete_tag_count' => 32];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MoviesProcessor::class, 'movieTitleTagRowBatch', $native, [$titles]));
            $random = new Randomizer(new Mt19937(self::SEED));
            $batch = self::movieTitles($random, 300, true);
            $rows = self::dispatch(MoviesProcessor::class, 'movieTitleTagRowBatch', $native, [$batch]);
            $summary = ['total_releases' => count($batch)];
            foreach ($bits as $key => $bit) {
                $summary[$key] = 0;
                for ($i = 0; $i < count($batch); $i++) {
                    $summary[$key] += ($rows[$i * 4] & $bit) !== 0 ? 1 : 0;
                }
            }
            self::assertEquals(MoviesProcessor::summarizeMovieReleaseBatchPhp($batch), $summary);
        }
    }

    public function test_music_title_rows_match_summary(): void
    {
        $titles = ['Artist-Album-2019-FLAC-WEB-320K', 'VA - Hits (CD) MP3 V0 192kbps 1999', '1K 1234K V3 2100', "\n"];
        $expected = [
            45, 6, 2019, self::span(0, 31),
            62, 7, 1999, self::span(0, 34),
            0, 4, -1, self::span(0, 16),
            64, 0, -1, self::span(1, 0),
        ];
        $bits = ['empty_title_count' => 64, 'lossless_count' => 1, 'lossy_count' => 2, 'bitrate_tagged_count' => 4, 'source_tagged_count' => 8, 'compilation_count' => 16, 'year_tagged_count' => 32];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MusicProcessor::class, 'musicTitleTagRowBatch', $native, [$titles]));
            $random = new Randomizer(new Mt19937(self::SEED));
            $batch = self::musicTitles($random, 300, true);
            $rows = self::dispatch(MusicProcessor::class, 'musicTitleTagRowBatch', $native, [$batch]);
            $summary = ['total_releases' => count($batch)];
            foreach ($bits as $key => $bit) {
                $summary[$key] = 0;
                for ($i = 0; $i < count($batch); $i++) {
                    $summary[$key] += ($rows[$i * 4] & $bit) !== 0 ? 1 : 0;
                }
            }
            self::assertEquals(MusicProcessor::summarizeMusicReleaseBatchPhp($batch), $summary);
        }
    }

    public function test_music_lookup_rows_match_summary(): void
    {
        $titles = ['Artist - Album (2019) FLAC', 'Album MP3 320', '- Album', '  ', 'Band -  ', 'x-y 2500'];
        $years = ['', ' 1998 ', '3000', '', '0999', 'abcd'];
        $expected = [
            13, 4, 2019, 7,
            22, 3, 1998, -1,
            0, 1, -1, 0,
            0, 0, -1, -1,
            0, 1, -1, 5,
            12, 3, 2500, 1,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MusicService::class, 'musicLookupRowBatch', $native, [$titles, $years]));
            $random = new Randomizer(new Mt19937(self::SEED));
            [$batchTitles, $batchYears] = self::musicLookups($random, 300, true);
            $rows = self::dispatch(MusicService::class, 'musicLookupRowBatch', $native, [$batchTitles, $batchYears]);
            $summary = ['total_candidates' => count($batchTitles), 'lossless_flac_count' => 0, 'mp3_bitrate_count' => 0, 'has_year_count' => 0, 'artist_album_splitable_count' => 0];
            $releases = [];
            foreach ($batchTitles as $i => $title) {
                $flags = $rows[$i * 4];
                $summary['lossless_flac_count'] += $flags & 1;
                $summary['mp3_bitrate_count'] += ($flags >> 1) & 1;
                $summary['has_year_count'] += ($flags >> 2) & 1;
                $summary['artist_album_splitable_count'] += ($flags >> 3) & 1;
                $releases[] = ['searchname' => $title, 'year' => $batchYears[$i]];
            }
            self::assertSame(MusicService::summarizeMusicReleaseLookupBatchPhp($releases), $summary);
        }
    }

    public function test_tv_lookup_mode_rows_match_resolution_and_summary(): void
    {
        $values = ['0', '1', '2', '', ' 2 ', 'abc', '1e999', '9.5', '-3', null, 2, PHP_INT_MIN, "\x0C7\n"];
        $expected = [0, 17, 1, 21, 2, 27, 1, 68, 2, 59, 1, 4, 0, 1, 9, 13, -3, 29, 1, 68, 2, 27, PHP_INT_MIN, 29, 7, 1 | 12 | 16 | 32];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(PostProcessService::class, 'tvLookupModeRowBatch', $native, [$values, 1]));
            self::assertSame([2, 10, 2, 10 | 64], self::dispatch(PostProcessService::class, 'tvLookupModeRowBatch', $native, [['x', null], 2]));
            $random = new Randomizer(new Mt19937(self::SEED));
            foreach ([0, 1, 2, 7, -1] as $default) {
                [$batch] = self::tvLookupValues($random, 300, true);
                $rows = self::dispatch(PostProcessService::class, 'tvLookupModeRowBatch', $native, [$batch, $default]);
                $summary = ['skip' => 0, 'all' => 0, 'renamed' => 0, 'other' => 0];
                foreach ($batch as $i => $value) {
                    $resolution = PostProcessService::resolveTvLookupModePhp($value, $default);
                    self::assertSame($resolution['resolved'], $rows[$i * 2]);
                    self::assertSame($resolution['renamedOnly'], ($rows[$i * 2 + 1] & 2) !== 0);
                    $summary[['skip', 'all', 'renamed', 'other'][($rows[$i * 2 + 1] >> 2) & 3]]++;
                }
                self::assertSame(PostProcessService::summarizeTvLookupModesPhp($default, ...$batch), $summary);
            }
        }
    }

    public function test_aka_name_rows_match_summary(): void
    {
        $names = ['The Office (US)', '  An Example (2019)  ', '', " \t", 'Amélie: Le  Film!', "A\x0CB (JP)", 'Show (20a1)', '(uk)'];
        $expected = [
            40, self::span(0, 15), -1, 0,
            156, self::span(2, 17), 2019, -1,
            1, 0, -1, -1,
            6, self::span(2, 0), -1, -1,
            832, self::span(0, 18), -1, -1,
            40, self::span(0, 8), -1, 5,
            128, self::span(0, 11), -1, -1,
            0, self::span(0, 4), -1, -1,
        ];
        $bits = ['empty_count' => 1, 'blank_count' => 2, 'padded_count' => 4, 'article_prefix_count' => 8, 'year_suffix_count' => 16, 'country_suffix_count' => 32, 'punctuation_count' => 64, 'digit_count' => 128, 'non_ascii_count' => 256, 'multi_space_count' => 512];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(TvMazeClient::class, 'akaNameRowBatch', $native, [$names]));
            $random = new Randomizer(new Mt19937(self::SEED));
            $batch = self::akaNames($random, 300, true);
            $rows = self::dispatch(TvMazeClient::class, 'akaNameRowBatch', $native, [$batch]);
            $summary = ['total_entries' => count($batch)];
            foreach ($bits as $key => $bit) {
                $summary[$key] = 0;
                for ($i = 0; $i < count($batch); $i++) {
                    $summary[$key] += ($rows[$i * 4] & $bit) !== 0 ? 1 : 0;
                }
            }
            self::assertSame(TvMazeClient::summarizeTvMazeAkaNameBatchPhp($batch), $summary);
            foreach ($batch as $i => $name) {
                $span = $rows[$i * 4 + 1];
                self::assertSame(trim($name), substr($name, $span >> 32, $span & 0xFFFFFFFF));
            }
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $ffi = self::ffi($class);
        if ($ffi === null) {
            self::markTestSkipped('Native pipeline library with W55 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 64, false);
        self::assertIsArray($args[0]);
        self::assertCount(64, $args[0]);
        $expected = $class::{$method.'Php'}(...$args);
        self::state($class, 'reset');
        self::assertSame($expected, $class::{$method.'Native'}(...$args));
        self::assertInstanceOf(FFI::class, self::state($class, 'ffi'));
        self::assertCount(64 * $words, $expected);

        // The raw export produces the same words without the PHP wrapper.
        [$text, $ints] = self::encode($class, $args);
        $out = $ffi->new('int64_t['.(64 * $words).']');
        $symbol = self::symbol($class);
        self::assertSame(64 * $words, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words));
        self::assertSame($expected, array_values(unpack('P*', FFI::string($out, 64 * $words * 8)) ?: []));
        // A short buffer reports the needed words; a wrong record count is rejected.
        self::assertSame(-(64 * $words), $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 64, $out, 64 * $words - 1));
        self::assertSame(-1, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), 63, $out, 64 * $words));
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_and_missing_library_fall_back(string $class, string $method, int $words, int $textArg): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 16, true);
        $withNul = $args;
        $withNul[$textArg][3] = "a\0b";
        $withNul[$textArg][7] = "\0";
        $withNul[$textArg][9] = "\0".$withNul[$textArg][9]."\0";
        self::assertParity($class, $method, $words, $withNul, 'NUL byte');
        $ffi = self::ffi($class);
        if ($ffi !== null) {
            [$encoded, $ints] = self::encode($class, $withNul);
            $out = $ffi->new('int64_t['.(16 * $words).']');
            self::assertSame(-1, $ffi->{self::symbol($class)}($encoded, strlen($encoded), $ints, strlen($ints), 16, $out, 16 * $words));
        }

        self::state($class, 'ffi', false);
        self::assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args));
        self::assertFalse(self::state($class, 'ffi'));
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, self::batch($class, $random, 192, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words, int $textArg): void
    {
        self::assertGreaterThanOrEqual(0, $textArg);
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 80; $round++) {
            self::assertParity($class, $method, $words, self::batch($class, $random, $random->getInt(1, 256), $round % 2 === 1), 'round '.$round);
        }
    }

    // ---------------------------------------------------------------------
    // Datasets
    // ---------------------------------------------------------------------

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    /** Random bytes biased toward the characters each helper inspects. */
    private static function noise(Randomizer $random, int $max): string
    {
        $alphabet = " \t\n\r\x0B\x0C-_./\\\"',:;!?&()+0123456789aAbBcCdDeEfFhHiIkKlLmMnNpPsStTvVxXyYzZ\x80\x85\xA0\xC3\xA9\xE2\x82\xAC\x7F";
        $out = '';
        $length = $random->getInt(0, $max);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function batch(string $class, Randomizer $random, int $count, bool $uncommon): array
    {
        return match ($class) {
            MoviesProcessor::class => [self::movieTitles($random, $count, $uncommon)],
            MusicProcessor::class => [self::musicTitles($random, $count, $uncommon)],
            MusicService::class => self::musicLookups($random, $count, $uncommon),
            PostProcessService::class => self::tvLookupValues($random, $count, $uncommon),
            TvMazeClient::class => [self::akaNames($random, $count, $uncommon)],
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<string> */
    private static function movieTitles(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = ['The.Matrix', 'Dune.Part.Two', 'Oppenheimer', 'Blade.Runner.2049', 'Spirited.Away', 'Mad Max Fury Road', 'Le.Fabuleux.Destin', 'Heat', 'Alien.Directors.Cut', 'Top.Gun.Maverick'];
        $years = ['1999', '2024', '2023', '2017', '2001', '2015', '1995', '1979', ''];
        $tags = ['1080p.BluRay.x264-SPARKS', '2160p.UHD.BluRay.REMUX.HDR.HEVC-FGT', '720p.WEB-DL.H264', '2160p.WEB.DV.HDR10.H265-NTb', '1080p.WEBRip.x265.10bit', 'DVDRip.XviD-AMIABLE', 'HDTV.AV1', '1080i.HDTV', 'PROPER.REPACK', ''];
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $title = self::pick($random, $names).'.'.self::pick($random, $years).'.'.self::pick($random, $tags);
            if ($uncommon) {
                $title = match ($random->getInt(0, 6)) {
                    0 => self::noise($random, 120),
                    1 => str_repeat('Very.Long.Movie.Title.', $random->getInt(1, 25)).self::pick($random, $tags),
                    2 => 'Ünïcödé 映画 '.strtolower($title).' 字幕',
                    3 => str_repeat(" \t", $random->getInt(1, 10)).$title.str_repeat("\n", $random->getInt(0, 4)),
                    4 => $random->getInt(0, 1) === 1 ? '' : str_repeat(" \x0B", $random->getInt(1, 6)),
                    5 => str_replace('.', self::pick($random, ['_', ' ', '-', '+']), strtoupper($title)),
                    default => $title,
                };
            }
            $titles[] = $title;
        }

        return $titles;
    }

    /** @return list<string> */
    private static function musicTitles(Randomizer $random, int $count, bool $uncommon): array
    {
        $artists = ['Daft_Punk', 'VA', 'Radiohead', 'Miles_Davis', 'Various_Artists', 'Boards_of_Canada', 'Aphex Twin', 'Beyonce', 'OST'];
        $albums = ['Random_Access_Memories', 'Kind_of_Blue', 'OK_Computer', 'Hits_Compilation', 'Original_Soundtrack', 'Discography', 'Anthology', 'Live'];
        $tags = ['(WEB)-2013-FLAC', '(VINYL)-1959-FLAC-24bit', '-CD-1997-MP3-320K', '-2CD-2019-V0', '-WEB-2023-AAC-256kbps', '-SACD-2003-DSD', '-TAPE-1985-OGG', '-LP-1971-WAV', '-CDM-2001-192K', ''];
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $title = self::pick($random, $artists).'-'.self::pick($random, $albums).self::pick($random, $tags).'-'.self::pick($random, ['GRP', 'FATHEAD', 'dh', 'PERFECT']);
            if ($uncommon) {
                $title = match ($random->getInt(0, 6)) {
                    0 => self::noise($random, 120),
                    1 => str_repeat('Long_Album_Name-', $random->getInt(1, 25)).self::pick($random, $tags),
                    2 => 'Ünïcödé 音楽 '.strtolower($title),
                    3 => str_repeat(" \r", $random->getInt(1, 10)).$title.str_repeat("\t", $random->getInt(0, 4)),
                    4 => $random->getInt(0, 1) === 1 ? '' : str_repeat(" \n", $random->getInt(1, 6)),
                    5 => str_replace(['-', '_'], [' ', '.'], strtoupper($title)).' '.$random->getInt(0, 9999).'K',
                    default => $title,
                };
            }
            $titles[] = $title;
        }

        return $titles;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private static function musicLookups(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = ['Pink Floyd - The Wall (1979) FLAC', 'Adele - 25 [2015] MP3 320', 'Metallica-Master of Puppets-1986-FLAC', 'Nirvana - Nevermind 192', 'Drake - Views (2016) [MP3 256]', 'Bach - Goldberg Variations', 'Daft Punk-Discovery-WEB-2001', 'Massive Attack - Mezzanine 1998 FLAC 24bit', 'Unknown Artist', '- Untitled -'];
        $years = ['1979', '2015', '', '', ' 2001 ', '1986', '0', '3000', 'n/a', '2023'];
        $outTitles = $outYears = [];
        for ($i = 0; $i < $count; $i++) {
            $title = self::pick($random, $titles);
            $year = self::pick($random, $years);
            if ($uncommon) {
                switch ($random->getInt(0, 5)) {
                    case 0:
                        $title = self::noise($random, 120);
                        break;
                    case 1:
                        $title = str_repeat('Very Long Artist Name ', $random->getInt(1, 20)).'- '.str_repeat('Album ', $random->getInt(0, 20)).'FLAC';
                        break;
                    case 2:
                        $year = $random->getInt(0, 1) === 1 ? (string) $random->getInt(0, 99999) : self::noise($random, 8);
                        break;
                    case 3:
                        $title = str_repeat(" \t", $random->getInt(1, 10)).$title.str_repeat("\n", $random->getInt(0, 4));
                        break;
                    case 4:
                        $title = 'Ünïcödé - アルバム '.strtolower($title);
                        break;
                    default:
                        $title = $random->getInt(0, 1) === 1 ? '' : '  -  ';
                }
            }
            $outTitles[] = $title;
            $outYears[] = $year;
        }

        return [$outTitles, $outYears];
    }

    /** @return array{0: list<int|string|null>, 1: int} */
    private static function tvLookupValues(Randomizer $random, int $count, bool $uncommon): array
    {
        $common = ['', '', '0', '1', '2', 0, 1, 2, null, '2', ' 1', '1 ', 'renamed', '02'];
        $odd = ['1e999', '-1e999', '9999999999999999999', '-9999999999999999999', '1.9', '.5', '5.', '1e3', '-2', '+2', "\t2\n", "\x0C1\x0B", '0x1A', '1_000', '1e', 'e1', ' ', '--1', '٣', '2.0e0', 'NaN', 'INF'];
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $value = self::pick($random, $common);
            if ($uncommon) {
                $value = match ($random->getInt(0, 4)) {
                    0 => self::pick($random, $odd),
                    1 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
                    2 => self::noise($random, 24),
                    3 => (string) $random->getInt(-5, 5).self::pick($random, ['', '.0', 'e0', ' ', 'x']),
                    default => $value,
                };
            }
            $values[] = $value;
        }

        return [$values, $uncommon ? self::pick($random, [0, 1, 2, 3, -7]) : 1];
    }

    /** @return list<string> */
    private static function akaNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $names = ['The Office (US)', 'The Office (UK)', 'Skam (2015)', 'La Casa de Papel', 'Money Heist: Part 1', 'Dr. Who', 'An Idiot Abroad', 'A Touch of Frost', 'Shameless (US)', 'Kingdom (KR)', 'Tokyo Vice', 'Bluey (AU)', 'Les Revenants', 'Borgen & Co', 'Who Wants to Be a Millionaire?', '24', 'Dark'];
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::pick($random, $names);
            if ($uncommon) {
                $name = match ($random->getInt(0, 6)) {
                    0 => self::noise($random, 120),
                    1 => str_repeat('Very Long Show Name ', $random->getInt(1, 20)).self::pick($random, ['(2019)', '(JP)', '(us)', '(20a1)', '']),
                    2 => 'Ünïcödé 番組 '.$name,
                    3 => str_repeat(" \t", $random->getInt(1, 10)).$name.str_repeat("\n", $random->getInt(0, 4)),
                    4 => $random->getInt(0, 1) === 1 ? '' : str_repeat(" \x0B", $random->getInt(1, 6)),
                    5 => self::pick($random, ['the ', 'AN ', 'a', 'The', "A\x0C", 'An']).$name.self::pick($random, ['  (NZ)', ' (CA)', '(1999)', ' ( 1999)', '!']),
                    default => $name,
                };
            }
            $out[] = $name;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    /**
     * @param  list<int>  $samples
     * @return array{mean: float, p99: int}
     */
    private static function statistics(array $samples): array
    {
        sort($samples);

        return ['mean' => array_sum($samples) / count($samples), 'p99' => $samples[(int) floor(0.99 * (count($samples) - 1))]];
    }

    public function test_paired_randomized_benchmark_matches_defaults(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (self::ffi($class) === null) {
                self::markTestSkipped('Native pipeline library with W55 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W55_BENCH_PAIRS') ?: 2000));
        $random = new Randomizer(new Mt19937(self::SEED));
        // Cycle collection would land on whichever sample crosses the root threshold.
        gc_collect_cycles();
        gc_disable();
        try {
            $this->runBenchmark($pairs, $random);
        } finally {
            gc_enable();
        }
        fwrite(STDERR, "\n");
    }

    private function runBenchmark(int $pairs, Randomizer $random): void
    {
        foreach (self::HELPERS as $name => [$class, $method]) {
            $ratios = [];
            foreach (['common' => [false, 64], 'uncommon' => [true, 192]] as $workload => [$uncommon, $count]) {
                $args = self::batch($class, $random, $count, $uncommon);
                $expected = $class::{$method.'Php'}(...$args);
                $timings = ['php' => [], 'rust' => []];
                foreach ([false, true] as $native) {
                    $class::withW55RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW55RustFfi($native, static function () use ($class, $method, $args, $expected): int {
                            $start = hrtime(true);
                            $actual = $class::{$method}(...$args);
                            $elapsed = hrtime(true) - $start;
                            self::assertSame($expected, $actual);

                            return $elapsed;
                        });
                        $timings[$native ? 'rust' : 'php'][] = $elapsed;
                    }
                }
                $php = self::statistics($timings['php']);
                $rust = self::statistics($timings['rust']);
                $ratios[$workload.'_mean'] = round($php['mean'] / max(1.0, $rust['mean']), 2);
                $ratios[$workload.'_p99'] = round($php['p99'] / max(1, $rust['p99']), 2);
                $ratios[$workload.'_us'] = [round($php['mean'] / 1000, 2), round($rust['mean'] / 1000, 2)];
            }
            $qualified = min($ratios['common_mean'], $ratios['common_p99'], $ratios['uncommon_mean'], $ratios['uncommon_p99']) >= 1.5;
            fwrite(STDERR, sprintf("\nW55.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW55RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
