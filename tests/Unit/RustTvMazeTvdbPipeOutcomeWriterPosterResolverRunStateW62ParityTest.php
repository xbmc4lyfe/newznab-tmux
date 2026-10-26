<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Pipes\TvdbPipe;
use App\Services\TvProcessing\Pipes\TvMazePipe;
use App\Services\TvProcessing\TvLookupOutcomeWriter;
use App\Services\TvProcessing\TvPosterResolver;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Support\RustPipelineLibrary;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Wave 62.4 batch helpers: TvMazePipe and TvdbPipe lookup plan rows (query normalization with a
 * trailing year, airdate, country or TVDB id and first-aired year checks, and the provider
 * lookup step), TvLookupOutcomeWriter outcome plan rows (status/provider classification, the
 * resolveOutcomePhp() transition, link change, cache bump and retry delay), TvPosterResolver
 * resolution plan rows (URL/path/content type/dimension validation and the provider, TMDB,
 * Fanart.tv fallback chain) and TvProcessingRunState plan rows (attempted-title and episode
 * lookup de-duplication, refresh freshness, circuit state and provider hit/miss rollup). Each
 * export takes a field-major blob plus packed int64 words; Rust writes fixed-width int64 rows and
 * a batch trailer that PHP decodes with one unpack('P*').
 *
 * Every helper keeps its routing override, cached binding, output buffer and declined-call count
 * in the function static of w62RustFfiState().
 *
 * The paired benchmark (NNTMUX_W624_BENCH=1) times the PHP and native batch methods, including
 * validation, packing and decoding, over interleaved rounds of 320 records in random order per
 * round (fixed seed). Rounds per workload: NNTMUX_W624_BENCH_ROUNDS (default 300, minimum 60).
 * It requires every default-enabled helper to clear 1.5x on common and uncommon mean and p99,
 * asserts that helpers below 1.5x retain PHP by default, and skips without the native library.
 *
 * Three runs at 309185f3c + this change (Apple Silicon, PHP 8.5.12 CLI, release build, two at 300
 * and one at 600 rounds per workload after 10 warmups, load average 5-6), minimum speedup per gate
 * (common mean/p99, uncommon mean/p99): tvMazeLookupPlanRowBatch 2.78/2.48, 2.16/2.00;
 * tvdbLookupPlanRowBatch 2.68/2.61, 2.05/2.09; lookupOutcomePlanRowBatch 2.23/2.01, 1.99/1.64;
 * posterResolutionPlanRowBatch 2.88/2.58, 2.40/2.23; runStatePlanRowBatch 2.58/2.30, 2.23/2.07.
 * Common batches hold realistic titles, airdates, TVDB ids, statuses, providers, poster URLs and
 * episode keys; uncommon batches mix mutated, truncated, random-byte (often NUL-bearing, so the
 * 0x01 twin is sent) and empty strings with extreme ints. Every helper defaults to Rust.
 */
final class RustTvMazeTvdbPipeOutcomeWriterPosterResolverRunStateW62ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record, trailing summary words, CDEF constant]. */
    private const array HELPERS = [
        'tvmaze' => [TvMazePipe::class, 'tvMazeLookupPlanRowBatch', 6, 8, 'W62_TVMAZE_PIPE_FFI_CDEF'],
        'tvdb' => [TvdbPipe::class, 'tvdbLookupPlanRowBatch', 6, 8, 'W62_TVDB_PIPE_FFI_CDEF'],
        'outcome' => [TvLookupOutcomeWriter::class, 'lookupOutcomePlanRowBatch', 5, 8, 'W62_OUTCOME_WRITER_FFI_CDEF'],
        'poster' => [TvPosterResolver::class, 'posterResolutionPlanRowBatch', 5, 7, 'W62_POSTER_RESOLVER_FFI_CDEF'],
        'runstate' => [TvProcessingRunState::class, 'runStatePlanRowBatch', 6, 11, 'W62_RUN_STATE_FFI_CDEF'],
    ];

    /** W62_RUST_FFI_DEFAULT per class, from the paired benchmark decision. */
    private const array DEFAULTS = [
        TvMazePipe::class => true,
        TvdbPipe::class => true,
        TvLookupOutcomeWriter::class => true,
        TvPosterResolver::class => true,
        TvProcessingRunState::class => true,
    ];

    private const int SEED = 6242026;

    private const int NOW = 1_793_000_000;

    private const array SPICE = ['é', 'ß', '日本', '😀', "\t", "\n", "\r", "\r\n", "\0", "\x01", "\x0b", "\x1f", "\x7f", "\xff", "\x80", "\xc3", "\xed\xa0\x80",
        ' ', ':', '::', '.', '..', '-', '--', '_', '/', '//', '?', '#', ';', ',', '@', 'x', 'X', 'A', 'z', '0', '9', '(', ')'];

    private const array TITLES = ['The Office US', 'Doctor Who (2005)', 'Law & Order: Special Victims Unit', 'Grey\'s Anatomy', 'Shōgun (2024)',
        'The.Last.of.Us', 'Stranger Things', 'Mr. Robot', 'The Bear', 'Slow Horses', 'Severance 2022', 'House of the Dragon', 'Blue Bloods',
        'Bluey (2018)', 'Top Gear', 'Taskmaster', 'MasterChef Australia', 'Survivor', 'Jeopardy!', 'S.W.A.T. 2017', ''];

    private const array COUNTRIES = ['US', 'GB', 'gb', ' AU ', 'CA', 'JP', 'NZ', '', '', 'USA', 'U1'];

    private const array STATUSES = ['matched', 'matched', 'season_pack', 'episode_missing', 'episode_missing', 'not_found', 'parse_failed', 'transient',
        'transient', 'skipped', 'pending', 'Matched', ' transient ', 'bogus', ''];

    private const array PROVIDERS = ['TVDB', 'TVMaze', 'TMDB', 'Trakt', 'Local DB', 'tvdb', ' tvmaze ', 'Other', ''];

    private const array CONTENT_TYPES = ['image/jpeg', 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'Image/JPEG; charset=binary', 'image/avif',
        'text/html; charset=utf-8', 'application/octet-stream', '', ' image/jpg '];

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            self::state($class, 'reset');
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: int, 4: string}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => [$class, $method, $words, $trailer]) {
            $cases[$name] = [$class, $method, $words, $trailer, $name];
        }

        return $cases;
    }

    private static function state(string $class, mixed ...$args): mixed
    {
        return (new ReflectionMethod($class, 'w62RustFfiState'))->invoke(null, ...$args);
    }

    private static function native(string $class): bool
    {
        $constant = null;
        foreach (self::HELPERS as [$helperClass, , , , $name]) {
            if ($helperClass === $class) {
                $constant = $name;
            }
        }
        self::assertIsString($constant);
        $cdef = (new ReflectionClass($class))->getConstant($constant);
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w62', $cdef) !== null;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $items): array
    {
        $result = $class::withW62RustFfi($native, static fn (): array => $class::{$method}($items));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $items */
    private static function assertParity(string $class, string $method, int $words, int $trailer, array $items, string $label): void
    {
        $php = $class::{$method.'Php'}($items);
        self::assertSame($php, self::dispatch($class, $method, false, $items), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $items), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}($items), $label.': native method');
        self::assertCount($items === [] ? 0 : \count($items) * $words + $trailer, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // Input generators
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    private static function bytes(Randomizer $random, int $length): string
    {
        return $length <= 0 ? '' : $random->getBytes($length);
    }

    private static function extremeInt(Randomizer $random): int
    {
        return self::pick($random, [0, 1, -1, 2, 3, 4, 5, 6, 7, 8, 9, -10, -11, -12, -100, 99, 100, 599, 600, 750, 751, 1899, 1900, 2099, 2100,
            3599, 3600, 3601, 9999, 10_000, 99_999, 100_000, 604_800, 604_801, 999_999, 1_000_000, 1_000_001, 20_000, 20_001, 20_971_520,
            20_971_521, 99_999_999, 100_000_000, 1_000_000_000, 1_000_000_001, self::NOW, self::NOW + 1, self::NOW - 3600, (1 << 40) - 1,
            1 << 40, (1 << 40) + 1, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX - 1, PHP_INT_MIN + 1, $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            $random->getInt(-1000, 1000), $random->getInt(0, 1 << 41)]);
    }

    /** Insert, replace, delete or truncate a few bytes. */
    private static function mutate(Randomizer $random, string $value): string
    {
        for ($n = $random->getInt(1, 3); $n > 0; $n--) {
            $length = \strlen($value);
            $at = $length === 0 ? 0 : $random->getInt(0, $length - 1);
            $value = match ($random->getInt(0, 3)) {
                0 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at),
                1 => substr($value, 0, $at).self::pick($random, self::SPICE).substr($value, $at + 1),
                2 => substr($value, 0, $at).substr($value, $at + 1),
                default => substr($value, 0, $at),
            };
        }

        return $value;
    }

    /** A realistic string, sometimes mutated, random or empty in uncommon batches. */
    private static function spoil(Randomizer $random, string $value, bool $uncommon): string
    {
        if (! $uncommon) {
            return $value;
        }

        return match ($random->getInt(0, 5)) {
            0, 1 => self::mutate($random, $value),
            2 => self::bytes($random, $random->getInt(0, 40)),
            3 => '',
            default => $value,
        };
    }

    private static function int(Randomizer $random, int $value, bool $uncommon): int
    {
        return $uncommon && $random->getInt(0, 3) === 0 ? self::extremeInt($random) : $value;
    }

    private static function date(Randomizer $random): string
    {
        return \sprintf('%04d-%02d-%02d', $random->getInt(1990, 2026), $random->getInt(1, 12), $random->getInt(1, 28));
    }

    private static function title(Randomizer $random): string
    {
        $title = self::pick($random, self::TITLES);

        return match ($random->getInt(0, 7)) {
            0 => str_replace(' ', '.', $title),
            1 => ' '.$title.' ('.$random->getInt(1995, 2025).')',
            2 => strtoupper($title),
            default => $title,
        };
    }

    /** @return list<mixed> */
    private static function tvmazeItem(Randomizer $random, bool $uncommon): array
    {
        $known = $random->getInt(0, 3) !== 0;

        return [
            self::spoil($random, self::title($random), $uncommon),
            self::spoil($random, $random->getInt(0, 2) === 0 ? self::date($random) : '', $uncommon),
            self::spoil($random, self::pick($random, self::COUNTRIES), $uncommon),
            self::int($random, $known ? $random->getInt(1, 90_000) : 0, $uncommon),
            self::int($random, $random->getInt(0, 9) === 0 ? 0 : $random->getInt(1, 80_000), $uncommon),
            self::int($random, $random->getInt(0, 30), $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $random->getInt(1, 24), $uncommon),
            self::int($random, $random->getInt(0, 12) === 0 ? 1 : 0, $uncommon),
            self::int($random, $random->getInt(0, 15) === 0 ? 3 : $random->getInt(0, 2), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function tvdbItem(Randomizer $random, bool $uncommon): array
    {
        $id = (string) $random->getInt(70_000, 450_000);

        return [
            self::spoil($random, self::title($random), $uncommon),
            self::spoil($random, match ($random->getInt(0, 5)) {
                0 => '',
                1, 2 => 'series-'.$id,
                default => $id,
            }, $uncommon),
            self::spoil($random, $random->getInt(0, 2) === 0 ? self::date($random) : '', $uncommon),
            self::spoil($random, $random->getInt(0, 4) === 0 ? '' : self::date($random).($random->getInt(0, 1) === 0 ? 'T00:00:00Z' : ''), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 90_000), $uncommon),
            self::int($random, $random->getInt(0, 30), $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $random->getInt(1, 24), $uncommon),
            self::int($random, $random->getInt(0, 12) === 0 ? 1 : 0, $uncommon),
            self::int($random, $random->getInt(0, 15) === 0 ? 3 : $random->getInt(0, 2), $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $random->getInt(1990, 2026), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function outcomeItem(Randomizer $random, bool $uncommon): array
    {
        return [
            self::spoil($random, self::pick($random, self::STATUSES), $uncommon),
            self::spoil($random, self::pick($random, self::PROVIDERS), $uncommon),
            self::int($random, $random->getInt(0, 2) === 0 ? 0 : $random->getInt(1, 90_000), $uncommon),
            self::int($random, self::pick($random, [0, 0, -10, -11, -11, -12, -100, -1, -4, $random->getInt(1, 5_000_000)]), $uncommon),
            self::int($random, $random->getInt(0, 9), $uncommon),
            self::int($random, $random->getInt(0, 6) === 0 ? 0 : $random->getInt(1, 90_000), $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? 0 : $random->getInt(1, 5_000_000), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 1 : 0, $uncommon),
            self::int($random, self::pick($random, [0, 0, 0, 60, 300, 1200, 7200, 86_400]), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function posterItem(Randomizer $random, bool $uncommon): array
    {
        $hex = bin2hex($random->getBytes(8));
        $ext = self::pick($random, ['jpg', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'JPG']);
        $provider = match ($random->getInt(0, 5)) {
            0, 1 => 'https://static.tvmaze.com/uploads/images/original_untouched/'.$random->getInt(1, 600).'/'.$random->getInt(1000, 999_999).'.'.$ext,
            2 => 'https://artworks.thetvdb.com/banners/posters/'.$random->getInt(70_000, 450_000).'-'.$random->getInt(1, 9).'.'.$ext,
            3 => 'http://thetvdb.com/banners/'.$hex.'.'.$ext.'?v='.$random->getInt(1, 9),
            default => '',
        };

        return [
            self::spoil($random, $provider, $uncommon),
            self::spoil($random, $random->getInt(0, 3) === 0 ? '' : '/'.$hex.'.'.$ext, $uncommon),
            self::spoil($random, $random->getInt(0, 2) === 0 ? '' : 'https://assets.fanart.tv/fanart/tv/'.$random->getInt(70_000, 450_000).'/tvposter/'.$hex.'.'.$ext, $uncommon),
            self::spoil($random, self::pick($random, self::CONTENT_TYPES), $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? 0 : $random->getInt(200, 2000), $uncommon),
            self::int($random, $random->getInt(0, 4) === 0 ? 0 : $random->getInt(300, 3000), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 300_000), $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(70_000, 450_000), $uncommon),
            self::int($random, $random->getInt(0, 5) === 0 ? 0 : $random->getInt(10_000, 3_000_000), $uncommon),
        ];
    }

    /** @return list<mixed> */
    private static function runStateItem(Randomizer $random, bool $uncommon): array
    {
        $now = self::NOW + $random->getInt(-86_400, 86_400);
        $key = match ($random->getInt(0, 4)) {
            0 => '',
            1 => self::date($random),
            default => $random->getInt(1, 30).'x'.$random->getInt(1, 24),
        };

        return [
            self::spoil($random, self::pick($random, self::PROVIDERS), $uncommon),
            self::spoil($random, self::title($random), $uncommon),
            self::spoil($random, $key, $uncommon),
            self::int($random, $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 300), $uncommon),
            self::int($random, $random->getInt(0, 12) === 0 ? 3 : $random->getInt(0, 2), $uncommon),
            self::int($random, match ($random->getInt(0, 3)) {
                0 => 0,
                1 => $now - $random->getInt(3600, 90_000),
                default => $now - $random->getInt(0, 3599),
            }, $uncommon),
            self::int($random, $now, $uncommon),
            self::int($random, $random->getInt(0, 500), $uncommon),
            self::int($random, $random->getInt(0, 200), $uncommon),
        ];
    }

    /** @return list<list<mixed>> */
    private static function batch(string $key, Randomizer $random, int $size, bool $uncommon): array
    {
        $items = [];
        for ($i = 0; $i < $size; $i++) {
            $items[] = match ($key) {
                'tvmaze' => self::tvmazeItem($random, $uncommon),
                'tvdb' => self::tvdbItem($random, $uncommon),
                'outcome' => self::outcomeItem($random, $uncommon),
                'poster' => self::posterItem($random, $uncommon),
                default => self::runStateItem($random, $uncommon),
            };
        }

        return $items;
    }

    /** @return list<list<mixed>> */
    private static function edgeItems(string $key): array
    {
        $now = self::NOW;
        $nul = "a\0b";
        $long = str_repeat('word ', 30);

        return match ($key) {
            'tvmaze' => [
                ['Doctor Who (2005)', '2024-01-06', ' gb ', 7, 210, 1, 2, 0, 0],
                ['', '', '', 0, 0, 0, 0, 0, 0],
                [' 2005', '2024-02-29', 'us', 1, 1, 0, 0, 0, 0],
                ['x 1899', '2023-02-29', 'u', 1, 1, 0, 0, 0, 0],
                ['x 2099', '2000.02.29', 'USA', 1, 99_999_999, 0, 0, 0, 2],
                ['x 2100', '2000/02/29', '日本', 1, 100_000_000, 5, 5, 0, 0],
                ['x  1900', '2000-02/29', 'JP', 1, 0, 0, 0, 1, 0],
                [$long, '0000-01-01', 'NZ', 1, 1, 10_000, 100_000, 0, 0],
                [str_repeat('a', 121), ' 2024-01-06 ', "\0CA\0", 1, 1, -5, -5, 0, 0],
                [str_repeat('a', 120), '2024-1-06', 'C A', -1, 1, 0, 0, 0, 3],
                ['Shōgun', '20240106', 'ca', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX],
                ['!!!', '', '', 1, 1, 1, 1, 0, 0],
                [$nul, $nul, $nul, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ["Title\x7f2020", '9999-12-31', 'zz', 1, 1, 9999, 99_999, 0, 0],
            ],
            'tvdb' => [
                ['Lost (2004)', 'series-73739', '', '2004-09-22', 9, 1, 0, 0, 0, 0],
                ['Lost', '73739', '2004-09-22', '2005', 9, 1, 3, 0, 0, 2004],
                ['Lost', 'series-', 'bad', '200', 9, 1, 3, 0, 0, 2003],
                ['Lost', 'Series-1', '', 'abcd', 9, 1, 3, 0, 0, 1899],
                ['Lost', '0000', '', '2100-01-01', 9, 0, 0, 0, 0, 2100],
                ['Lost', '12345678901', '', '1900', 9, 0, 0, 1, 0, 1900],
                ['Lost', '1234567890', '2004-09-22', '2099', 9, 0, 0, 0, 0, 2099],
                ['Lost 2004', ' 42 ', '', '', 0, 0, 0, 0, 5, 0],
                ['', '', '', '', 0, 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, $nul, 0, 0, 0, 0, 0, 0],
                ['Lost', 'series-73739', '', '', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, 0, PHP_INT_MIN, PHP_INT_MAX],
                ['Lost', 'series-73739', '', '', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['Café 2020', "series-7\x00", '2020-02-30', '2020-01-01', 1, 1, 1, 0, 0, 0],
            ],
            'outcome' => [
                ['transient', 'TVDB', 0, 0, 2, 0, 0, 1, 50],
                ['matched', 'tvmaze', 0, 0, 0, 12, 345, 0, 0],
                ['matched', '', 12, 345, 0, 12, 345, 0, 0],
                ['matched', 'x', 12, 345, 0, 12, 0, 0, 0],
                ['season_pack', 'tmdb', 12, -12, 0, 12, 0, 0, 0],
                ['season_pack', 'tmdb', 0, 0, 0, 0, 0, 0, 0],
                ['episode_missing', 'trakt', 12, -11, 4, 12, 0, 0, 0],
                ['episode_missing', 'trakt', 12, -11, 5, 12, 0, 0, 0],
                ['episode_missing', 'trakt', 12, -11, 5, 12, 0, 1, 99],
                ['episode_missing', 'trakt', 12, 0, PHP_INT_MAX, 12, 0, 1, PHP_INT_MAX],
                ['transient', 'local db', 0, 0, 6, 0, 0, 0, 0],
                ['transient', 'local db', 0, 0, 7, 0, 0, 0, 0],
                ['transient', 'local db', 0, -10, 7, 0, 0, 0, -50],
                ['transient', 'local db', 0, 0, PHP_INT_MAX, 0, 0, 0, 0],
                ['parse_failed', 'LOCAL DB', 5, 99, 0, 0, 0, 0, 0],
                ['not_found', 'tvdb', 5, 99, 0, -5, 0, 0, 0],
                ['', '', PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['MATCHED', ' TVDB ', PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                [$nul, $nul, 0, 0, 0, 0, 0, 0, 0],
                ["matched\x0b", "tvdb\0", 0, 0, 0, 1, 1, 0, 0],
            ],
            'poster' => [
                ['https://static.tvmaze.com/p/1.jpg', '/abc.png', '', 'image/jpeg', 680, 1000, 5, 0, 1000],
                ['ftp://x', '/abc.png', 'http://assets.fanart.tv/a.gif', 'text/html', 0, 0, 0, 9, -1],
                ['', '/abc.png', '', 'image/png', 1, 1, 1, 0, 20_971_520],
                ['', 'https://image.tmdb.org/t/p/w500/a.webp#x', '', ' IMAGE/WEBP ; q=1', 20_000, 20_000, 1, 0, 20_971_521],
                ['//cdn.example/a.png', '//x', 'https://localhost/a.png', 'image/', 20_001, 1, 1, 1, 0],
                ['HTTP://A.B/C.JPEG?x.png', '/', 'https://.a.b/x', 'image/x', 0, 1, 0, 1, 0],
                ['https://a.b./c', 'relative/path.jpg', 'https://a.b', ';', -1, -1, 0, 0, 0],
                ['https://a b/c.jpg', "/x\x7f.png", "https://a.b/\x01", 'image/jpeg;', 600, 1000, 0, 0, 0],
                ['http://', '/a.gif', 'https://a.b/c.gif?d', 'image/gif', 750, 1000, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                [' https://a.b/c.png ', '', '', "\timage/png\n", 751, 1000, 0, 0, PHP_INT_MIN],
                ['', '', '', '', 0, 0, 0, 0, 0],
                [$nul, $nul, $nul, $nul, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                ['https://é.com/a.jpg', '/é.png', 'http://a.b/é.webp', 'image/jpeg', 599, 1000, 1, 1, 1],
            ],
            default => [
                ['TVDB', 'The Office', '1x2', 7, 0, $now - 100, $now, 3, 1],
                ['TVDB', 'The Office', '1x2', 7, 0, $now - 100, $now, 3, 1],
                ['x', '', '', 0, 5, 0, $now, 0, 0],
                ['tvdb', 'the  office!', '2024-01-06', 0, 2, $now - 3600, $now, 0, 5],
                [' TVDB ', 'THE.OFFICE', '2024-02-30', 0, 0, $now + 1, $now, 1_000_000_001, 1_000_000_001],
                ['TVMaze', 'Show', '1234x12345', 1, 0, $now - 3599, $now, -1, -1],
                ['TVMaze', 'Show', '12345x1', 1, 0, $now - 3599, $now, 0, 0],
                ['TVMaze', 'Show', 'x1', 1, 0, $now - 3599, $now, 0, 0],
                ['TVMaze', 'Show', '1x', 1, 0, $now - 3599, $now, 0, 0],
                ['TVMaze', 'Show', '1x123456', 1, 0, 1, 0, 0, 0],
                ['a:1', 'Show', '2:x', 2, 0, 1, PHP_INT_MAX, 0, 0],
                ['a', 'Show', 'x', 1, 0, 1, PHP_INT_MIN, 0, 0],
                ['Local DB', 'Café', ' 3x4 ', PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MAX],
                ['', '', '', PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN, PHP_INT_MIN],
                [$nul, $nul, $nul, 1, 0, $now, $now, 0, 0],
                ["tv\x1fdb", "a\x1fb", '1x1', 1, 0, $now, $now, 0, 0],
            ],
        };
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w62_switch_api_and_default(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W62_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPublic());
        self::assertIsBool($constant->getValue());
        $cdef = $reflection->getReflectionConstant(self::HELPERS[$key][4]);
        self::assertNotFalse($cdef);
        self::assertTrue($cdef->isPrivate());
        self::assertSame($class, $cdef->getDeclaringClass()->getName());
        self::assertStringContainsString('nntmux_w624_', (string) $cdef->getValue());
        self::assertSame($words, $reflection->getConstant('W62_ROW_WORDS'));
        self::assertSame($trailer, $reflection->getConstant('W62_TRAILER_WORDS'));
        foreach (['usesW62RustFfi', 'withW62RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }
        $state = $reflection->getMethod('w62RustFfiState');
        self::assertTrue($state->isPrivate() && $state->isStatic());
        self::assertSame($class, $state->getDeclaringClass()->getName());

        self::assertSame($constant->getValue(), $class::usesW62RustFfi());
        self::assertFalse($class::withW62RustFfi(false, static fn (): bool => $class::usesW62RustFfi()));
        self::assertTrue($class::withW62RustFfi(true, static fn (): bool => $class::usesW62RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW62RustFfi(), 'withW62RustFfi restores the default');

        $nested = $class::withW62RustFfi(false, static fn (): array => [
            $class::usesW62RustFfi(),
            $class::withW62RustFfi(true, static fn (): bool => $class::usesW62RustFfi()),
            $class::usesW62RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW62RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW62RustFfi(), 'restored after an exception');
    }

    public function test_state_lives_in_function_statics(): void
    {
        foreach (self::HELPERS as [$class]) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertStringNotContainsStringIgnoringCase('w62', $property->getName(), $class);
            }
            self::state($class, 'enabled', true);
            self::assertTrue($class::usesW62RustFfi());
            self::state($class, 'enabled', false);
            self::assertFalse($class::usesW62RustFfi());
            self::assertNull(self::state($class, 'reset'));
            self::assertSame(['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0], self::state($class));
        }
        TvMazePipe::withW62RustFfi(false, static function (): void {
            foreach ([TvdbPipe::class, TvLookupOutcomeWriter::class, TvPosterResolver::class, TvProcessingRunState::class] as $other) {
                self::assertNull(self::state($other, 'enabled'), $other);
                self::assertSame(self::DEFAULTS[$other], $other::usesW62RustFfi(), $other);
            }
        });
    }

    public function test_defaults_follow_the_benchmark_decision(): void
    {
        foreach (self::DEFAULTS as $class => $enabled) {
            self::assertSame($enabled, $class::W62_RUST_FFI_DEFAULT, $class);
            self::assertSame($enabled, $class::usesW62RustFfi(), $class);
        }
    }

    public function test_existing_rust_ffi_toggles_are_independent(): void
    {
        $snapshot = static fn (): array => [TvMazePipe::usesRustFfi(), TvMazePipe::usesW49RustFfi(), TvdbPipe::usesRustFfi(), TvdbPipe::usesW49RustFfi(),
            TvLookupOutcomeWriter::usesRustFfi(), TvLookupOutcomeWriter::usesW49RustFfi(), TvPosterResolver::usesRustFfi(), TvPosterResolver::usesW49RustFfi(),
            TvProcessingRunState::usesRustFfi(), TvProcessingRunState::usesW47RunStateRustFfi()];
        $before = $snapshot();
        foreach ([false, true] as $enabled) {
            foreach (self::HELPERS as [$class]) {
                $class::withW62RustFfi($enabled, static function () use ($before, $snapshot): void {
                    self::assertSame($before, $snapshot());
                    self::assertSame('TVMaze', TvMazePipe::resolvePipeSpecPhp()['name']);
                    self::assertTrue(TvdbPipe::evaluateAvailabilityPhp(null));
                    self::assertTrue(TvLookupOutcomeWriter::hasLinkChangedPhp(1, 0, 2, 0));
                    self::assertSame('b', TvPosterResolver::selectPosterUrlPhp('', 'b'));
                    self::assertSame('tv:eps-refreshed:tvdb:5', TvProcessingRunState::refreshKeyPhp('TVDB', 5));
                    self::assertSame([1, 0, 0, 0, 0, 0, 0, 1, 0, 1], TvMazePipe::w49EpisodeBatchPhp([['airdate' => '', 'type' => '', 'season' => 1, 'number' => 0]]));
                });
            }
        }
        self::assertSame($before, $snapshot());
    }

    public function test_native_library_exports_every_symbol(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            self::assertTrue(self::native($class), $class);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words, int $trailer, string $key): void
    {
        self::assertGreaterThan(0, $words + $trailer);
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, []));
        }
        $items = self::batch($key, new Randomizer(new Mt19937(self::SEED)), 3, false);
        $first = $items[0];
        $bad = [
            array_combine(['a', 'b', 'c'], $items),
            [1 => $first],
            [$first, 'not a record'],
            [\array_slice($first, 1)],
            [[...$first, 0]],
            [array_combine(range(1, \count($first)), $first)],
            [array_map(static fn (mixed $v): mixed => \is_int($v) ? (string) $v : 1.5, $first)],
        ];
        foreach ($bad as $shape) {
            foreach (['Php', 'Native', ''] as $suffix) {
                try {
                    $class::{$method.$suffix}($shape);
                    self::fail($method.$suffix.' must reject a malformed batch');
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('W62', $e->getMessage());
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_randomized_and_edge_parity(string $class, string $method, int $words, int $trailer, string $key): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($key)));
        $records = 0;
        foreach ([false, true] as $uncommon) {
            foreach ([1, 2, 7, 64, 320] as $size) {
                $items = self::batch($key, $random, $size, $uncommon);
                self::assertParity($class, $method, $words, $trailer, $items, $key.' batch '.$size.($uncommon ? ' uncommon' : ''));
                $records += $size;
            }
        }
        $edges = self::edgeItems($key);
        foreach ($edges as $i => $item) {
            self::assertParity($class, $method, $words, $trailer, [$item], $key.' edge '.$i.' '.json_encode($item, JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::assertParity($class, $method, $words, $trailer, $edges, $key.' edges together');
        self::assertGreaterThanOrEqual(300, $records + \count($edges));
        if (self::native($class)) {
            self::assertSame(0, self::state($class, 'misses'), $key.': every native call must succeed');
        }
    }

    public function test_hand_checked_rows(): void
    {
        $fnv = static function (string $value): int {
            /** @var array{1: int} $hash */
            $hash = unpack('N', hash('fnv1a32', $value, true));

            return $hash[1];
        };
        $now = self::NOW;

        $tvmaze = TvMazePipe::tvMazeLookupPlanRowBatchPhp([
            ['Doctor Who (2005)', '2024-01-06', ' gb ', 7, 210, 1, 2, 0, 0],
            ['', '', '', 0, 0, 0, 0, 0, 0],
        ]);
        self::assertSame([6 | 1 << 4 | 1 << 6 | 1 << 8 | 10 << 16 | 2005 << 24, $fnv('doctor who'), 20_240_106, 100_002, 210, \ord('G') << 8 | \ord('B')],
            \array_slice($tvmaze, 0, 6));
        self::assertSame([1, 0, 0, -1, -1, 0], \array_slice($tvmaze, 6, 6));
        self::assertSame([1, 0, 0, 0, 1, 0, 0], \array_slice($tvmaze, 12, 7));

        $tvdb = TvdbPipe::tvdbLookupPlanRowBatchPhp([['Lost (2004)', 'series-73739', '', '2004-09-22', 9, 1, 0, 0, 0, 0]]);
        self::assertSame([8 | 2 << 4 | 9 << 8 | 4 << 16 | 2004 << 24, $fnv('lost'), 0, -1, 73_739, 0], \array_slice($tvdb, 0, 6));
        self::assertSame([0, 0, 0, 0, 0, 0, 20], \array_slice($tvdb, 6, 7));

        $outcome = TvLookupOutcomeWriter::lookupOutcomePlanRowBatchPhp([
            ['transient', 'TVDB', 0, 0, 2, 0, 0, 1, 50],
            ['matched', 'tvmaze', 0, 0, 0, 12, 345, 0, 0],
        ]);
        self::assertSame([6 | 1 << 4 | 1 << 8 | 8 << 12, 0, 0, 3, 3600], \array_slice($outcome, 0, 5));
        self::assertSame([1 | 2 << 4 | 3 << 8 | 3 << 12, 12, 345, 0, -1], \array_slice($outcome, 5, 5));
        self::assertSame([1, 0, 1, 0, 1, 1, 3600], \array_slice($outcome, 10, 7));

        $url = 'https://static.tvmaze.com/p/1.jpg';
        $poster = TvPosterResolver::posterResolutionPlanRowBatchPhp([
            [$url, '/abc.png', '', 'image/jpeg', 680, 1000, 5, 0, 1000],
            ['ftp://x', '/abc.png', 'http://assets.fanart.tv/a.gif', 'text/html', 0, 0, 0, 9, -1],
        ]);
        self::assertSame([1 | 1 << 4 | 3 << 8 | 1 << 16 | 1 << 20 | 1 << 24, $fnv($url), \strlen($url), 680, 370], \array_slice($poster, 0, 5));
        self::assertSame([3 | 4 << 4 | 3 << 8 | 2 << 12 | 4 << 16 | 6 << 20 | 27 << 28, $fnv('http://assets.fanart.tv/a.gif'), 29, -1, 70],
            \array_slice($poster, 5, 5));
        self::assertSame([1, 0, 1, 0, 1, 1000], \array_slice($poster, 10, 6));

        $runState = TvProcessingRunState::runStatePlanRowBatchPhp([
            ['TVDB', 'The Office', '1x2', 7, 0, $now - 100, $now, 3, 1],
            ['TVDB', 'The Office', '1x2', 7, 0, $now - 100, $now, 3, 1],
            ['x', '', '', 0, 5, 0, $now, 0, 0],
        ]);
        $row = [$fnv("tvdb\x1fthe office"), $fnv('tv:eps-refreshed:tvdb:7'), $fnv('TVDB:7:1x2'), 750, 100];
        self::assertSame([6 | 1 << 4 | 1 << 8 | 1 << 10 | 10 << 20, ...$row], \array_slice($runState, 0, 6));
        self::assertSame([7 | 1 << 4 | 1 << 8 | 1 << 10 | 3 << 12 | 10 << 20, ...$row], \array_slice($runState, 6, 6));
        self::assertSame([1 | 6 << 4 | 4 << 12, 0, $fnv('tv:eps-refreshed:x:0'), $fnv('x:0:'), -1, -1], \array_slice($runState, 12, 6));
        self::assertSame([1, 0, 0, 0, 0, 1, 1, 0, 6, 2], \array_slice($runState, 18, 10));
    }

    // ---------------------------------------------------------------------
    // Paired benchmark
    // ---------------------------------------------------------------------

    public function test_paired_benchmark(): void
    {
        if (getenv('NNTMUX_W624_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_W624_BENCH=1 to run the paired benchmark.');
        }
        $rounds = max(60, (int) (getenv('NNTMUX_W624_BENCH_ROUNDS') ?: 300));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        $failures = [];
        foreach (self::HELPERS as $key => [$class, $method]) {
            if (! self::native($class)) {
                self::markTestSkipped('The native pipeline library is not built.');
            }
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $php = [];
                $rust = [];
                for ($round = -10; $round < $rounds; $round++) {
                    $items = self::batch($key, $random, 320, $uncommon);
                    $order = $random->getInt(0, 1) === 0 ? ['Php', 'Native'] : ['Native', 'Php'];
                    $times = [];
                    foreach ($order as $suffix) {
                        $start = hrtime(true);
                        $class::{$method.$suffix}($items);
                        $times[$suffix] = hrtime(true) - $start;
                    }
                    if ($round >= 0) {
                        $php[] = $times['Php'];
                        $rust[] = $times['Native'];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil(0.99 * $rounds) - 1;
                $ratios[$workload.' mean'] = array_sum($php) / max(1, array_sum($rust));
                $ratios[$workload.' p99'] = $php[$p99] / max(1, $rust[$p99]);
            }
            $report[] = \sprintf('%s::%s %s', $key, $method, implode(', ', array_map(
                static fn (string $label, float $ratio): string => \sprintf('%s %.2fx', $label, $ratio), array_keys($ratios), $ratios)));
            // A default-enabled helper below the gate fails; one below it must retain PHP.
            if ($class::usesW62RustFfi() && min($ratios) < 1.5) {
                $failures[] = $key.' min '.round(min($ratios), 2).' must retain PHP by default below 1.5x';
            }
        }
        fwrite(STDERR, "\nW62.4 paired benchmark ({$rounds} rounds x 320 records per workload):\n".implode("\n", $report)."\n");
        self::assertSame([], $failures, 'Default-enabled helpers must clear 1.5x on every gate.');
    }
}
