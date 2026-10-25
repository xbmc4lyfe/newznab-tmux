<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnidbService;
use App\Services\ArchiveProcessingService;
use App\Services\IGDB\Exceptions\IgdbHttpException;
use App\Services\MovieBrowseService;
use App\Services\MovieService;
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
 * Wave 53.4 batch helpers: AnidbService episode markers, ArchiveProcessingService entry classes,
 * MovieBrowseService facet rows, MovieService metadata rows and IgdbHttpException failure
 * classes. PHP joins every text field with NUL bytes (row-major for multi-field records,
 * messages then bodies for IGDB) and packs IGDB status codes with pack('P*'); Rust writes
 * fixed-width int64 words per record that PHP decodes with one unpack('P*'), so a batch costs
 * one FFI crossing. A value holding a NUL byte adds a field, which the export rejects, and the
 * batch falls back to PHP.
 *
 * Every helper keeps its routing override, cached binding and output buffer in the function
 * static of w53RustFfiState().
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times the
 * public dispatch including packing and decoding, and requires every default-enabled helper to
 * clear 1.5x on common and uncommon mean and p99. Pairs per workload: NNTMUX_W53_BENCH_PAIRS
 * (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at 3805d156e + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~8-9), minimum speedup
 * per gate (common mean/p99, uncommon mean/p99): anidbEpisodeMarkerBatch 2.83/2.17, 2.45/2.00;
 * archiveEntryClassBatch 4.24/3.36, 3.91/2.64; movieFacetRowBatch 3.67/3.09, 3.49/2.55;
 * movieMetadataRowBatch 3.73/2.90, 3.85/2.68; classifyHttpFailureBatch 3.02/2.29, 2.67/2.06.
 * Common batches hold 64 realistic records; uncommon batches hold 192 mixing long, multibyte,
 * malformed and whitespace-heavy values with extreme integers. All five default to Rust.
 */
final class RustAnidbArchiveMovieBrowseMovieServiceIgdbExW53ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, values per record in the first argument]. */
    private const array HELPERS = [
        'anidb' => [AnidbService::class, 'anidbEpisodeMarkerBatch', 4, 1],
        'archive' => [ArchiveProcessingService::class, 'archiveEntryClassBatch', 3, 1],
        'browse' => [MovieBrowseService::class, 'movieFacetRowBatch', 5, 3],
        'movie' => [MovieService::class, 'movieMetadataRowBatch', 4, 4],
        'igdb' => [IgdbHttpException::class, 'classifyHttpFailureBatch', 4, 1],
    ];

    private const int SEED = 5342026;

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
        return (new ReflectionMethod($class, 'w53RustFfiState'))->invoke(null, ...$args);
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W53_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w53', $cdef);
    }

    private static function symbol(string $class): string
    {
        $cdef = (new ReflectionClass($class))->getConstant('W53_FFI_CDEF');
        self::assertIsString($cdef);
        self::assertSame(1, preg_match('/\b(nntmux_w534_\w+)\(/', $cdef, $match));

        return $match[1];
    }

    /**
     * @param  list<list<mixed>>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW53RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<list<mixed>> $args */
    private static function records(array $args, int $per): int
    {
        return intdiv(count($args[0]), $per);
    }

    /** @param list<list<mixed>> $args */
    private static function assertParity(string $class, string $method, int $words, int $per, array $args, string $label): void
    {
        $php = self::dispatch($class, $method, false, $args);
        self::assertSame($php, $class::{$method.'Php'}(...$args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertCount(self::records($args, $per) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    /**
     * Text and int buffers exactly as the native method builds them.
     *
     * @param  list<list<mixed>>  $args
     * @return array{0: string, 1: string}
     */
    private static function encode(string $class, array $args): array
    {
        if ($class === IgdbHttpException::class) {
            return [implode("\0", $args[1])."\0".implode("\0", $args[2]), pack('P*', ...$args[0])];
        }

        return [implode("\0", $args[0]), ''];
    }

    private static function span(int $offset, int $length): int
    {
        return ($offset << 32) | $length;
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w53_switch_api_and_default(string $class, string $method, int $words, int $per): void
    {
        self::assertGreaterThan(0, $words * $per);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W53_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        foreach (['usesW53RustFfi', 'withW53RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w53RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());

        self::assertSame($constant->getValue(), $class::usesW53RustFfi());
        self::assertFalse($class::withW53RustFfi(false, static fn (): bool => $class::usesW53RustFfi()));
        self::assertTrue($class::withW53RustFfi(true, static fn (): bool => $class::usesW53RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW53RustFfi(), 'withW53RustFfi restores the default');

        $nested = $class::withW53RustFfi(false, static fn (): array => [
            $class::usesW53RustFfi(),
            $class::withW53RustFfi(true, static fn (): bool => $class::usesW53RustFfi()),
            $class::usesW53RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW53RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW53RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w53', $property->getName(), $class);
            }
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW53RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0], self::state($class));
        }
        // Each class keeps its own state.
        AnidbService::withW53RustFfi(false, static function (): void {
            self::assertFalse(AnidbService::usesW53RustFfi());
            self::assertNull(self::state(MovieService::class, 'enabled'));
            self::assertNull(self::state(IgdbHttpException::class, 'enabled'));
        });
    }

    public function test_earlier_wave_switches_are_independent(): void
    {
        $earlier = static fn (): array => [
            AnidbService::usesW394RustFfi(),
            AnidbService::usesW454RustFfi(),
            AnidbService::usesRustFfi(),
            ArchiveProcessingService::usesW466RustFfi(),
            MovieBrowseService::usesMovieBrowseFilterRustFfi(),
            MovieBrowseService::usesW48MovieFacetRustFfi(),
            MovieService::usesW404RustFfi(),
            IgdbHttpException::usesRustFfi(),
        ];
        $before = $earlier();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                self::assertSame($before, $class::withW53RustFfi($enabled, $earlier), $class);
            }
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $per): void
    {
        self::assertGreaterThan(0, $words);
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 4, false);
        $empty = array_fill(0, count($args), []);
        $cases = [];
        foreach (array_keys($args) as $k) {
            $nonList = $args;
            $nonList[$k] = array_combine(range(1, count($args[$k])), $args[$k]);
            $cases['non-list '.$k] = $nonList;
        }
        if ($per > 1) {
            $cases['partial row'] = [array_slice($args[0], 1)];
        }
        if (count($args) > 1) {
            $short = $args;
            $short[2] = array_slice($short[2], 1);
            $cases['short column'] = $short;
        }
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, $empty));
            foreach ($cases as $label => $input) {
                foreach ([$method, $method.'Php', $method.'Native'] as $entry) {
                    try {
                        $class::withW53RustFfi($native, static fn (): array => $class::{$entry}(...$input));
                        self::fail($label.' must be rejected by '.$entry.'.');
                    } catch (InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts
    // ---------------------------------------------------------------------

    public function test_anidb_episode_marker_hand_cases(): void
    {
        $cases = [
            '[SubsPlease] Frieren - 12 (1080p) [ABCD1234].mkv' => [3, 20, 12, 1],
            'Show.Name.S02E07.1080p.WEB' => [1, 9, 7, 0],
            'Show Name 3x09 Title' => [2, 9, 9, 0],
            '[Grp][Other]  Title Ep 05 [BD]' => [4, 19, 5, 2],
            'Title Episode 007' => [4, 5, 7, 0],
            'Title E0000' => [4, 5, 0, 0],
            'Title E00012' => [4, 5, 12, 0],
            'Title - 1234' => [0, 12, -1, 0],
            'Title - 12v2' => [0, 12, -1, 0],
            'Some_Title_Movie_2019' => [5, 11, -1, 0],
            'Complete Series Box' => [5, 0, -1, 0],
            'Name [1080p] [x264]' => [6, 5, -1, 0],
            'Name [BDRip]' => [6, 5, -1, 0],
            'Show S1E99999999999999999999999' => [1, 4, PHP_INT_MAX, 0],
            '' => [0, 0, -1, 0],
            '[] Title -  7' => [3, 8, 7, 0],
        ];
        $expected = array_merge(...array_values($cases));
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(AnidbService::class, 'anidbEpisodeMarkerBatch', $native, [array_map('strval', array_keys($cases))]));
        }
    }

    public function test_archive_entry_class_hand_cases_match_file_name_class(): void
    {
        $cases = [
            'movie.part01.rar' => [6, 1, 0],
            'movie.PART0000.RAR' => [6, 0, 0],
            'movie.part12345.rar' => [6, 0, 0],
            'movie.r00' => [6, 1, 0],
            'movie.R17' => [6, 18, 0],
            'movie.007' => [6, 7, 0],
            'dir/Sample/clip.mkv' => [1, -1, 3],
            'x\\.hidden.nfo' => [4, -1, 6],
            'proof.jpg' => [3, -1, 1],
            'samples.txt' => [4, -1, 0],
            'noext' => [0, -1, 0],
            'archive.7Z' => [6, -1, 0],
            'set.vol01+02.PAR2' => [5, -1, 0],
        ];
        $names = array_map('strval', array_keys($cases));
        $codes = ['other' => 0, 'video' => 1, 'audio' => 2, 'image' => 3, 'text' => 4, 'par2' => 5, 'archive' => 6];
        foreach ([false, true] as $native) {
            $words = self::dispatch(ArchiveProcessingService::class, 'archiveEntryClassBatch', $native, [$names]);
            self::assertSame(array_merge(...array_values($cases)), $words);
            foreach ($names as $i => $name) {
                self::assertSame($codes[ArchiveProcessingService::fileNameClassPhp($name)], $words[3 * $i], $name);
            }
        }
    }

    public function test_movie_facet_rows_match_facet_summary(): void
    {
        $fields = [
            '1999', ' 8.7 ', 'Action, Sci-Fi|action/Unknown',
            '2105', '10.0', '',
            '20xx', '10.1', ' , drama ,',
            '19999', '7', 'FILM-NOIR',
            '2001', '7.25', 'x/y',
        ];
        $expected = [87, 1999, 1 | 1 << 20, 2, 1, 100, 0, 0, 0, 0, -1, 0, 1 << 7, 1, 0, 70, 0, 1 << 10, 1, 0, -1, 2001, 0, 0, 2];
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MovieBrowseService::class, 'movieFacetRowBatch', $native, [$fields]));
            // Row sums agree with the W48 facet summary of the same movies.
            [$values] = self::batch(MovieBrowseService::class, $random, 128, $native);
            $words = self::dispatch(MovieBrowseService::class, 'movieFacetRowBatch', $native, [$values]);
            $movies = [];
            foreach (array_chunk($values, 3) as [$year, $rating, $genre]) {
                $movies[] = ['year' => $year, 'rating' => $rating, 'genre' => $genre];
            }
            $summary = MovieBrowseService::scoreMovieFacetBatchPhp($movies);
            $rows = array_chunk($words, 5);
            self::assertSame($summary['rated_count'], count(array_filter($rows, static fn (array $r): bool => $r[0] >= 0)));
            self::assertSame($summary['rating_tenths_sum'], array_sum(array_map(static fn (array $r): int => max(0, $r[0]), $rows)));
            self::assertSame($summary['valid_year_count'], count(array_filter($rows, static fn (array $r): bool => $r[1] > 0)));
            self::assertSame($summary['genre_mask'], array_reduce($rows, static fn (int $mask, array $r): int => $mask | $r[2], 0));
            self::assertSame($summary['known_genre_sum'], array_sum(array_column($rows, 3)));
            self::assertSame($summary['unknown_genre_sum'], array_sum(array_column($rows, 4)));
        }
    }

    public function test_movie_metadata_rows_match_completeness_summary(): void
    {
        $fields = [
            ' tt0111161 ', '278', '1', '1994',
            'tt0000000', '000', '0', '3000',
            '1234567890', '99999999999999999999999', 'x', '2999',
            'TT0111161', '', '12', " 1000\n",
        ];
        $expected = [31, 111161, 278, 1994, 0, -1, -1, -1, 11, 1234567890, PHP_INT_MAX, 2999, 12, -1, -1, 1000];
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(MovieService::class, 'movieMetadataRowBatch', $native, [$fields]));
            [$values] = self::batch(MovieService::class, $random, 128, $native);
            $rows = array_chunk(self::dispatch(MovieService::class, 'movieMetadataRowBatch', $native, [$values]), 4);
            $movies = [];
            foreach (array_chunk($values, 4) as [$imdb, $tmdb, $cover, $year]) {
                $movies[] = ['imdbid' => $imdb, 'tmdbid' => $tmdb, 'cover' => $cover, 'year' => $year];
            }
            $count = static fn (int $bit): int => count(array_filter($rows, static fn (array $r): bool => ($r[0] & $bit) !== 0));
            self::assertSame([
                'total_rows' => 128,
                'valid_imdb_id_count' => $count(1),
                'has_tmdb_id_count' => $count(2),
                'has_cover_count' => $count(4),
                'missing_year_count' => 128 - $count(8),
                'complete_record_count' => $count(16),
            ], MovieService::summarizeMovieMetadataCompletenessPhp($movies));
        }
    }

    public function test_igdb_failure_classes_match_classify_http_failure(): void
    {
        $statuses = [0, 403, 429, 418, 503, -7, 404, 408];
        $messages = ["  boom \t\x0C bang  ", '', "\x0C", 'teapot', 'a  b', ' x ', "\n", 'gone'];
        $bodies = ['', '  {}  ', str_repeat('z', 300), "\x0C", 'err', null, null, " \x0Bbody\x0C "];
        $expected = [
            16, self::span(2, 12), 9, -1,
            1 | 32, 0, 40, self::span(2, 2),
            4 | 16 | 32, self::span(1, 0), 40, 256,
            5, 6, 6, -1,
            6 | 16, 4, 3, 3,
            7, self::span(1, 1), 1, -1,
            2 | 32, self::span(1, 0), 40, -1,
            3 | 16, 4, 4, self::span(2, 4),
        ];
        $categories = ['transport', 'auth', 'not_found', 'timeout', 'rate_limited', 'client', 'server', 'unknown'];
        foreach ([false, true] as $native) {
            $words = self::dispatch(IgdbHttpException::class, 'classifyHttpFailureBatch', $native, [$statuses, $messages, $bodies]);
            self::assertSame($expected, $words);
            $random = new Randomizer(new Mt19937(self::SEED));
            [$codes, $texts, $payloads] = self::batch(IgdbHttpException::class, $random, 128, $native);
            $rows = array_chunk(self::dispatch(IgdbHttpException::class, 'classifyHttpFailureBatch', $native, [$codes, $texts, $payloads]), 4);
            foreach ($rows as $i => [$flags, $message, $length, $excerpt]) {
                $classified = IgdbHttpException::classifyHttpFailurePhp($codes[$i], $texts[$i], $payloads[$i]);
                self::assertSame($classified['category'], $categories[$flags & 15]);
                self::assertSame($classified['retryable'], ($flags & 16) !== 0);
                self::assertSame(strlen($classified['message']), $length);
                if (($flags & 32) === 0) {
                    self::assertSame(substr($texts[$i], $message >> 32, $message & 0xFFFFFFFF), trim($texts[$i], " \t\n\r\0\x0B\x0C"));
                }
                self::assertSame($classified['body_excerpt'], $excerpt === -1 ? null : substr((string) $payloads[$i], $excerpt >> 32, $excerpt & 0xFFFFFFFF));
            }
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words, int $per): void
    {
        $ffi = self::ffi($class);
        if ($ffi === null) {
            self::markTestSkipped('Native pipeline library with W53 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 64, false);
        self::assertSame(64, self::records($args, $per));
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
    public function test_nul_bytes_and_missing_library_fall_back(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 16, true);
        $text = $class === IgdbHttpException::class ? 1 : 0;
        $withNul = $args;
        $withNul[$text][3] = "a\0b";
        $withNul[$text][7] = "\0";
        $withNul[$text][9] = "\0".$withNul[$text][9]."\0";
        self::assertParity($class, $method, $words, $per, $withNul, 'NUL byte');
        $ffi = self::ffi($class);
        if ($ffi !== null) {
            [$encoded, $ints] = self::encode($class, $withNul);
            $out = $ffi->new('int64_t['.(16 * $words).']');
            self::assertSame(-1, $ffi->{self::symbol($class)}($encoded, strlen($encoded), $ints, strlen($ints), self::records($withNul, $per), $out, 16 * $words));
        }

        self::state($class, 'ffi', false);
        self::assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args));
        self::assertFalse(self::state($class, 'ffi'));
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 192, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, $per, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words, int $per): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 80; $round++) {
            self::assertParity($class, $method, $words, $per, self::batch($class, $random, $random->getInt(1, 256), $round % 2 === 1), 'round '.$round);
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

    /** Random bytes biased toward the characters each classifier inspects. */
    private static function noise(Randomizer $random, int $max): string
    {
        $alphabet = " \t\n\r\x0B\x0C-_.[]()/\\,|+0123456789sSeExXpPiIoOdDvVaAmMcCtTlLrRbBuUhHfFwW\x80\x85\xA0\xC3\xA9\xE2\x82\xAC\x7F";
        $out = '';
        $length = $random->getInt(0, $max);
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /** @return list<list<mixed>> */
    private static function batch(string $class, Randomizer $random, int $count, bool $uncommon): array
    {
        return match ($class) {
            AnidbService::class => [self::releaseNames($random, $count, $uncommon)],
            ArchiveProcessingService::class => [self::entryNames($random, $count, $uncommon)],
            MovieBrowseService::class => [self::facetRows($random, $count, $uncommon)],
            MovieService::class => [self::metadataRows($random, $count, $uncommon)],
            IgdbHttpException::class => self::failures($random, $count, $uncommon),
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<string> */
    private static function releaseNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $groups = ['[SubsPlease] ', '[Erai-raws] ', '[HorribleSubs][Multi] ', '', '', '[] ', '[Grp]  [Tag] '];
        $titles = ['Frieren Beyond Journeys End', 'Sousou no Frieren', 'Kimetsu_no_Yaiba', 'One.Piece', 'Mushoku Tensei II', 'Spy x Family', 'Oshi no Ko'];
        $markers = [' - 12', ' - 007v2', ' S02E07', '.S1E12.', ' 3x09', ' Ep 05', ' Episode 0012', ' E0000', ' Movie', ' OVA', ' Complete Series', ' Full Series', ' [BD]', ' [1080p]', ' [x265]', ' - 1234', ''];
        $tails = [' (1080p) [ABCD1234].mkv', ' [1080p][HEVC].mkv', '.720p.WEB.x264', ' [BDRip 1080p]', '', ' END'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::pick($random, $groups).self::pick($random, $titles).self::pick($random, $markers).self::pick($random, $tails);
            if ($uncommon) {
                $name = match ($random->getInt(0, 5)) {
                    0 => self::noise($random, 80),
                    1 => str_repeat(' - ', $random->getInt(1, 40)).self::pick($random, $markers).self::noise($random, 20),
                    2 => self::pick($random, $groups).'Ünïcödé タイトル'.self::pick($random, $markers).' S'.str_repeat('9', $random->getInt(1, 30)).'E'.str_repeat('0', $random->getInt(1, 25)).'1',
                    3 => strtoupper($name).self::noise($random, 12),
                    4 => str_replace(' ', "\t", $name),
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return list<string> */
    private static function entryNames(Randomizer $random, int $count, bool $uncommon): array
    {
        $stems = ['Movie.2024.1080p.BluRay.x264-GRP', 'Show.S01E02.720p', 'album/01 - Track', 'Sample/movie-sample', 'Proof/cover', '.hidden', 'dir\\sub\\file'];
        $extensions = ['.part01.rar', '.part001.rar', '.PART0000.RAR', '.rar', '.r00', '.r17', '.R42', '.001', '.mkv', '.MP4', '.flac', '.jpg', '.nfo', '.sfv', '.vol01+02.par2', '.7z', '.zip', '.exe', '', '.'];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $name = self::pick($random, $stems).self::pick($random, $extensions);
            if ($uncommon) {
                $name = match ($random->getInt(0, 5)) {
                    0 => self::noise($random, 60),
                    1 => str_repeat('a/', $random->getInt(1, 40)).'x.part'.str_repeat('0', $random->getInt(0, 6)).$random->getInt(0, 99999).'.rar',
                    2 => 'Ünïcödé/sample/ファイル'.self::pick($random, $extensions),
                    3 => self::noise($random, 20).'.'.self::noise($random, 4),
                    4 => strtoupper($name),
                    default => $name,
                };
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return list<string> */
    private static function facetRows(Randomizer $random, int $count, bool $uncommon): array
    {
        $years = ['1999', '2024', '1987', '2105', '0', '', '20xx', '19999'];
        $ratings = ['8.7', '7', ' 6.5 ', '10', '10.0', '10.1', '', 'N/A', '7.25', '.5', '9.'];
        $genres = ['Action, Sci-Fi', 'Drama|Romance', 'Comedy/Family/Animation', 'Horror, Thriller, Mystery', 'Film-Noir', 'Documentary', '', 'Unknown, Action', ' , drama ,', 'ACTION, action'];
        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [self::pick($random, $years), self::pick($random, $ratings), self::pick($random, $genres)];
            if ($uncommon) {
                $k = $random->getInt(0, 2);
                $row[$k] = match ($random->getInt(0, 3)) {
                    0 => self::noise($random, 40),
                    1 => implode(self::pick($random, [',', '|', '/', ' , ']), array_map(static fn (): string => self::pick($random, ['Action', 'Sci-Fi', 'Western', 'Bogus', ' ', 'Ünïcödé']), range(0, $random->getInt(0, 30)))),
                    2 => str_repeat('9', $random->getInt(1, 25)),
                    default => strtoupper($row[$k]),
                };
            }
            array_push($fields, ...$row);
        }

        return $fields;
    }

    /** @return list<string> */
    private static function metadataRows(Randomizer $random, int $count, bool $uncommon): array
    {
        $imdb = ['tt0111161', '0111161', ' tt1234567890 ', 'tt0000000', 'TT0111161', 'tt012345', 'tt12345678901', 'ttt1234567', ''];
        $tmdb = ['278', '0', '000', '', '12a', ' 550 ', '99999999999999999999999'];
        $cover = ['1', '0', '', 'x', "12\n"];
        $year = ['1994', '2024', '3000', '0999', '', ' 2001 ', '19x4'];
        $fields = [];
        for ($i = 0; $i < $count; $i++) {
            $row = [self::pick($random, $imdb), self::pick($random, $tmdb), self::pick($random, $cover), self::pick($random, $year)];
            if ($uncommon) {
                $k = $random->getInt(0, 3);
                $row[$k] = match ($random->getInt(0, 2)) {
                    0 => self::noise($random, 30),
                    1 => " \t".str_repeat('0', $random->getInt(0, 20)).$random->getInt(1, PHP_INT_MAX)."\x0B",
                    default => 'tt'.str_pad((string) $random->getInt(0, 9999999999), $random->getInt(7, 11), '0', STR_PAD_LEFT),
                };
            }
            array_push($fields, ...$row);
        }

        return $fields;
    }

    /** @return array{0: list<int>, 1: list<string>, 2: list<?string>} */
    private static function failures(Randomizer $random, int $count, bool $uncommon): array
    {
        $statuses = [0, 400, 401, 403, 404, 408, 418, 429, 500, 502, 503, 599, 600, 302];
        $messages = ['Client error: `POST https://api.igdb.com/v4/games` resulted in a `429 Too Many Requests` response', "  cURL error 28:\tOperation timed out  ", 'Unauthorized', '', "\x0C\n", 'Server   error', 'Not Found'];
        $bodies = ['{"message":"Too Many Requests"}', '  [{"title":"Syntax Error","status":400}]  ', '', null, "\x0C", '<html>'.str_repeat('x', 300).'</html>'];
        $codes = $texts = $payloads = [];
        for ($i = 0; $i < $count; $i++) {
            $code = self::pick($random, $statuses);
            $text = self::pick($random, $messages);
            $body = self::pick($random, $bodies);
            if ($uncommon) {
                switch ($random->getInt(0, 3)) {
                    case 0:
                        $code = $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
                        break;
                    case 1:
                        $text = self::noise($random, 120);
                        break;
                    case 2:
                        $body = str_repeat(" \t", $random->getInt(0, 200)).self::noise($random, 600);
                        break;
                    default:
                        $text = str_repeat(" \x0C\t", $random->getInt(1, 50)).'Ünïcödé  '.self::noise($random, 30);
                }
            }
            $codes[] = $code;
            $texts[] = $text;
            $payloads[] = $body;
        }

        return [$codes, $texts, $payloads];
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
                self::markTestSkipped('Native pipeline library with W53 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W53_BENCH_PAIRS') ?: 2000));
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
                    $class::withW53RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW53RustFfi($native, static function () use ($class, $method, $args, $expected): int {
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
            fwrite(STDERR, sprintf("\nW53.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW53RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
