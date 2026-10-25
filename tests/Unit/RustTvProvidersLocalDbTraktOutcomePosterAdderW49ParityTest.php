<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TvProcessing\Providers\LocalDbProvider;
use App\Services\TvProcessing\Providers\TraktProvider;
use App\Services\TvProcessing\TvLookupOutcomeWriter;
use App\Services\TvProcessing\TvPosterResolver;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvShowAdder;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Wave 49.4 TV batch helpers: LocalDbProvider show lookup keys, TraktProvider imdb/image
 * normalization, TvLookupOutcomeWriter outcome resolution, TvPosterResolver poster picks and
 * TvShowAdder request normalization. PHP joins each text column with NUL bytes and packs
 * integer scalars with pack('P*'); Rust writes fixed-width int64 words per record that PHP
 * decodes with one unpack('P*'), so a batch costs one FFI crossing.
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times
 * the public dispatch including packing and decoding, and requires every default-enabled
 * helper to clear 1.5x on common and uncommon mean and p99. Pairs per workload:
 * NNTMUX_W49_BENCH_PAIRS (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at df0c038a9 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~10), minimum
 * speedup per gate (common mean/p99, uncommon mean/p99): showLookupKeyBatch 5.61/3.42,
 * 4.56/3.90; traktIdsBatch 2.65/2.12, 4.23/2.86; resolveOutcomeBatch 2.21/2.05, 2.66/2.22;
 * pickPosterBatch 3.54/2.13, 3.82/2.82; normalizeRequestBatch 2.62/2.29, 3.10/3.08. Common
 * batches hold 64 realistic records; uncommon batches hold 128 mixing long, multibyte,
 * whitespace-heavy and malformed values with extreme integers. All five default to Rust.
 */
final class RustTvProvidersLocalDbTraktOutcomePosterAdderW49ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record]. */
    private const array HELPERS = [
        'local_db' => [LocalDbProvider::class, 'showLookupKeyBatch', 3],
        'trakt' => [TraktProvider::class, 'traktIdsBatch', 2],
        'outcome' => [TvLookupOutcomeWriter::class, 'resolveOutcomeBatch', 4],
        'poster' => [TvPosterResolver::class, 'pickPosterBatch', 3],
        'adder' => [TvShowAdder::class, 'normalizeRequestBatch', 2],
    ];

    private const int SEED = 4942026;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            (new ReflectionProperty($class, 'useW49RustFfi'))->setValue(null, null);
        }
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function helperProvider(): array
    {
        $cases = [];
        foreach (self::HELPERS as $name => $helper) {
            $cases[$name] = $helper;
        }

        return $cases;
    }

    private static function ffi(string $class): ?FFI
    {
        $cdef = (new ReflectionClass($class))->getConstant('W49_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w49', $cdef);
    }

    /**
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW49RustFfi($native, static fn (): array => $class::{$method}(...$args));
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
        self::assertCount(count($args[array_key_last($args)]) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w49_switch_api_and_default(string $class, string $method): void
    {
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W49_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        self::assertTrue($reflection->getProperty('useW49RustFfi')->isPrivate());
        self::assertTrue($reflection->getProperty('useW49RustFfi')->isStatic());
        foreach (['usesW49RustFfi', 'withW49RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }

        self::assertSame($constant->getValue(), $class::usesW49RustFfi());
        self::assertFalse($class::withW49RustFfi(false, static fn (): bool => $class::usesW49RustFfi()));
        self::assertTrue($class::withW49RustFfi(true, static fn (): bool => $class::usesW49RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW49RustFfi(), 'withW49RustFfi restores the default');

        $nested = $class::withW49RustFfi(false, static fn (): array => [
            $class::usesW49RustFfi(),
            $class::withW49RustFfi(true, static fn (): bool => $class::usesW49RustFfi()),
            $class::usesW49RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW49RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW49RustFfi(), 'restored after an exception');
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method): void
    {
        $columns = count(self::realistic($class));
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, array_fill(0, $columns, [])));
            $short = self::realistic($class);
            $short[0] = array_slice($short[0], 1);
            $holes = self::realistic($class);
            $holes[0] = array_combine(range(1, count($holes[0])), $holes[0]);
            foreach (['mismatched columns' => $short, 'non-list column' => $holes] as $label => $args) {
                try {
                    self::dispatch($class, $method, $native, $args);
                    self::fail($label.' must be rejected.');
                } catch (InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts
    // ---------------------------------------------------------------------

    private static function fnv(string $key): int
    {
        $digest = unpack('J', hash('fnv1a64', $key, true));
        self::assertIsArray($digest);

        return $digest[1];
    }

    public function test_local_db_show_keys_hand_cases(): void
    {
        $flags = static fn (int $base, string $country): int => $base | 8 | (ord($country[0]) << 24) | (ord($country[1]) << 16);
        $names = ['  The Office (US) (2005) ', 'Doctor.Who.2005', 'the', '', 'Show (2019', "Caf\u{e9}  Noir!", 'The The', '(2019)', 'Show 1899', 'Show (2019))', "THE\tWire\n"];
        $years = [0, 1963, 0, 0, -5, 0, 0, 0, 0, 2001, PHP_INT_MAX];
        $countries = [' us ', 'GB', 'usa', '', 'g1', 'fr', 'zz', "\tdE\0", 'U', '', 'u s'];
        $expected = [
            self::fnv('office us'), 2005, $flags(3, 'US'),
            self::fnv('doctor who'), 1963, $flags(7, 'GB'),
            self::fnv('the'), 0, 1,
            0, 0, 0,
            self::fnv('show'), 2019, 3,
            self::fnv('caf noir'), 0, $flags(1, 'FR'),
            self::fnv('the'), 0, $flags(1, 'ZZ'),
            0, 2019, 3 | 8 | (ord('D') << 24) | (ord('E') << 16),
            self::fnv('show 1899'), 0, 1,
            self::fnv('show 2019'), 2001, 5,
            self::fnv('wire'), PHP_INT_MAX, 5,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(LocalDbProvider::class, 'showLookupKeyBatch', $native, [$names, $years, $countries]));
        }
    }

    public function test_trakt_ids_hand_cases(): void
    {
        $imdb = ['tt0944947', "TT12345678\n", 'tt12345', 'x', 'tt1234567890123456789', 'att0123456', 'tt0000001', "tt123456\n\n", 'tt1234567tt123456', '', 'tT999999999999999999'];
        $images = ['https://a/p.jpg', '//walter.trakt.tv/p.jpg', '', 'walter/p', 'http', '///x', 'h', 'HTTPS://upper', ' /x', '/', 'httpx'];
        $expected = [944947, 1, 12345678, 2 | (2 << 2), 0, 0, 0, 2, -1, 1, 123456, 2 | (3 << 2), 1, 2, 0, 2, 123456, 2, 0, 2 | (1 << 2), 999999999999999999, 1];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(TraktProvider::class, 'traktIdsBatch', $native, [$imdb, $images]));
        }
    }

    public function test_outcome_hand_cases_match_resolve_outcome_and_cache_bump(): void
    {
        $statuses = ['matched', 'season_pack', 'episode_missing', 'episode_missing', 'transient', 'transient', 'not_found', 'parse_failed', 'skipped', 'transient'];
        $scalars = [
            5, 0, 3, 7, 42, 0, 0,
            7, 42, 0, 7, 0, 0, 0,
            7, -11, 2, 7, 0, 0, 0,
            7, -11, 5, 7, 0, 1, 30,
            0, 0, 2, 0, 0, 1, 5000,
            0, 0, 7, 0, 0, 1, 0,
            9, 77, 0, 9, 0, 0, 0,
            0, 0, 0, 3, 3, 0, 0,
            0, -10, 0, 0, 0, 0, 0,
            0, 0, PHP_INT_MAX, 0, 0, 1, 0,
        ];
        $expected = [
            42, 0, -1, 7,
            -12, 0, -1, 7,
            -11, 3, 86400, 4,
            -11, 6, 900, 4,
            0, 3, 5000, 0,
            -10, 0, -1, 0,
            -10, 0, -1, 1,
            -100, 0, -1, 0,
            -10, 0, -1, 0,
            -10, 0, -1, 0,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(TvLookupOutcomeWriter::class, 'resolveOutcomeBatch', $native, [$scalars, $statuses]));
        }
    }

    public function test_outcome_batch_agrees_with_single_resolve_outcome(): void
    {
        [$scalars, $statuses] = self::randomOutcomes(new Randomizer(new Mt19937(self::SEED)), 400, false);
        $batch = TvLookupOutcomeWriter::withW49RustFfi(true, static fn (): array => TvLookupOutcomeWriter::resolveOutcomeBatch($scalars, $statuses));
        foreach ($statuses as $i => $status) {
            [$prevVideo, $prevStatus, $prevAttempts, $video, $episode, $transient, $retryAfter] = array_slice($scalars, $i * 7, 7);
            $result = new TvProcessingResult($status, $video, $episode, null, [], $transient !== 0, $retryAfter);
            $single = TvLookupOutcomeWriter::resolveOutcomePhp($prevVideo, $prevStatus, $prevAttempts, $result);
            [$episodeStatus, $attempts, $delay, $flags] = array_slice($batch, $i * 4, 4);
            self::assertSame($single['videos_id'], ($flags & 4) !== 0 ? $video : 0);
            self::assertSame($single['tv_episodes_id'], $episodeStatus);
            self::assertSame($single['tv_lookup_attempts'], $attempts);
            self::assertSame($single['retry_delay_seconds'] ?? -1, $delay);
            self::assertSame($single['link_changed'], ($flags & 1) !== 0);
            self::assertSame(TvLookupOutcomeWriter::shouldBumpCacheVersionPhp($single['link_changed'], $status), ($flags & 2) !== 0);
        }
    }

    public function test_poster_pick_hand_cases(): void
    {
        $provider = [' https://img/a.JPG?x=1 ', 'https:///a.jpg', '', 'ftp://x/a.png', 'https://static.tvmaze.com/uploads/images/original_untouched/1/4600.jpg', '//x/a.jpeg#y', 'http://.webp'];
        $tmdb = ['https://b/b.png', '//cdn/b.webp#f', '', 'HTTP://x/y.jpeg', '', '', ''];
        $fanart = ['', 'x', "\t", 'http://x?.png', 'https://assets.fanart.tv/a.png', '', 'https://x/a.gif'];
        $expected = [
            0, (1 << 32) | 21, 0b011_011,
            1, 14, 0b111_010,
            -1, 0, 0,
            1, 15, 0b111_010,
            0, 70, 0b101_101,
            0, 12, 0b001_001,
            0, 12, 0b101_001,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(TvPosterResolver::class, 'pickPosterBatch', $native, [$provider, $tmdb, $fanart]));
        }
        $picked = TvPosterResolver::pickPosterBatch($provider, $tmdb, $fanart);
        self::assertSame('https://img/a.JPG?x=1', substr($provider[0], $picked[1] >> 32, $picked[1] & 0xFFFFFFFF));
        self::assertSame('//cdn/b.webp#f', substr($tmdb[1], $picked[4] >> 32, $picked[4] & 0xFFFFFFFF));
    }

    public function test_show_adder_hand_cases_match_normalize_id(): void
    {
        $sources = [' TVDB ', 'imdb', 'imdb', 'imdb', 'tmdb', 'netflix', 'IMDb', 'trakt', 'tvmaze', 'imdb', 'imdb', 'tvdb'];
        $ids = [' 81189 ', 'tt0944947', 'T12345', 'tt123', '000', '12', '0944947123456', '00000000000000000000000001', '82x', "\ttT1234567890 ", 'ttt12345', '99999999999999999999999'];
        $expected = [0, (1 << 32) | 5, 4, (2 << 32) | 7, 4, (1 << 32) | 5, 4, -2, 2, -3, -1, 2, 4, 13, 3, 26, 1, -3, 4, (3 << 32) | 10, 4, -2, 0, 23];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(TvShowAdder::class, 'normalizeRequestBatch', $native, [$sources, $ids]));
        }
        $adder = new TvShowAdder;
        $words = TvShowAdder::normalizeRequestBatch($sources, $ids);
        foreach ($sources as $i => $source) {
            $normalized = strtolower(trim($source));
            $word = $words[$i * 2 + 1];
            try {
                $id = $adder->normalizeIdPhp($normalized, $ids[$i]);
                self::assertGreaterThanOrEqual(0, $word);
                self::assertSame($id, substr($ids[$i], $word >> 32, $word & 0xFFFFFFFF));
            } catch (InvalidArgumentException) {
                self::assertSame($normalized === 'imdb' ? -2 : -3, $word);
            }
            $column = $words[$i * 2];
            self::assertSame($column >= 0 ? TvShowAdder::SUPPORTED_SOURCES[$column] : null, in_array($normalized, TvShowAdder::SUPPORTED_SOURCES, true) ? $normalized : null);
        }
    }

    // ---------------------------------------------------------------------
    // Native execution, fallbacks and parity
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words): void
    {
        if (self::ffi($class) === null) {
            self::markTestSkipped('Native pipeline library with W49 symbols is not built.');
        }
        $args = self::realistic($class);
        $expected = $class::{$method.'Php'}(...$args);
        // Warm the handle and output buffer, then poison it so a fallback is detectable.
        self::dispatch($class, $method, true, $args);
        $out = (new ReflectionProperty($class, 'w49Out'))->getValue();
        self::assertInstanceOf(CData::class, $out);
        FFI::memset($out, 0x5A, count($expected) * 8);
        self::assertSame($expected, self::dispatch($class, $method, true, $args));
        self::assertSame(pack('P*', ...$expected), FFI::string($out, count($expected) * 8), 'Rust must have written the batch.');
        self::assertInstanceOf(FFI::class, (new ReflectionProperty($class, 'w49Ffi'))->getValue());
    }

    #[DataProvider('helperProvider')]
    public function test_raw_export_contract(string $class, string $method, int $words): void
    {
        $ffi = self::ffi($class);
        if ($ffi === null) {
            self::markTestSkipped('Native pipeline library with W49 symbols is not built.');
        }
        $symbol = self::symbol($class);
        $out = $ffi->new('int64_t[64]');
        [$text, $ints, $count] = self::wire($class, self::realistic($class));
        // Capacity is reported in words, and nothing is written.
        FFI::memset($out, 0x11, 512);
        self::assertSame(-($count * $words), $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), $count, $out, $count * $words - 1));
        self::assertSame(str_repeat("\x11", 512), FFI::string($out, 512));
        // Field-count mismatches, stray text and null pointers are rejected.
        self::assertSame(-1, $ffi->{$symbol}($text."\0", strlen($text) + 1, $ints, strlen($ints), $count, $out, 64));
        self::assertSame(-1, $ffi->{$symbol}('x', 1, '', 0, 0, $out, 64));
        self::assertSame(-1, $ffi->{$symbol}(null, 3, '', 0, 1, $out, 64));
        self::assertSame(-1, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), $count, null, 64));
        self::assertSame(0, $ffi->{$symbol}(null, 0, null, 0, 0, $out, 64));
        self::assertSame(str_repeat("\x11", 512), FFI::string($out, 512));
        self::assertSame($count * $words, $ffi->{$symbol}($text, strlen($text), $ints, strlen($ints), $count, $out, 64));
        $decoded = unpack('P*', FFI::string($out, $count * $words * 8));
        self::assertIsArray($decoded);
        self::assertSame($class::{$method.'Php'}(...self::realistic($class)), array_values($decoded));
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_and_missing_library_fall_back_to_php(string $class, string $method, int $words): void
    {
        $args = self::realistic($class);
        $textColumn = $class === TvLookupOutcomeWriter::class ? 1 : 0;
        $args[$textColumn][1] = "tt0944947\0https://x/a.jpg";
        $args[$textColumn][2] = "\0";
        self::assertParity($class, $method, $words, $args, 'NUL bytes');

        $property = new ReflectionProperty($class, 'w49Ffi');
        $previous = $property->getValue();
        try {
            $property->setValue(null, false);
            self::assertSame($class::{$method.'Php'}(...$args), self::dispatch($class, $method, true, $args));
        } finally {
            $property->setValue(null, $previous);
        }
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words): void
    {
        self::assertParity($class, $method, $words, self::realistic($class), 'realistic');
        self::assertParity($class, $method, $words, self::benchmarkInput($class, false), 'common');
        self::assertParity($class, $method, $words, self::benchmarkInput($class, true), 'uncommon');
        // Batches larger than the initial output buffer grow it.
        $large = self::benchmarkInput($class, true);
        foreach ($large as $k => $column) {
            $large[$k] = [...$column, ...$column, ...$column];
        }
        self::assertParity($class, $method, $words, $large, 'large');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 60; $round++) {
            $count = $random->getInt(1, 96);
            $args = self::randomBatch($random, $class, $count);
            self::assertParity($class, $method, $words, $args, 'random round '.$round);
        }
    }

    // ---------------------------------------------------------------------
    // Datasets
    // ---------------------------------------------------------------------

    private static function symbol(string $class): string
    {
        $cdef = (new ReflectionClass($class))->getConstant('W49_FFI_CDEF');
        self::assertIsString($cdef);
        self::assertSame(1, preg_match('/(nntmux_w49_\w+)\(/', $cdef, $match));

        return $match[1];
    }

    /**
     * @param  list<list<int|string>>  $args
     * @return array{string, string, int}
     */
    private static function wire(string $class, array $args): array
    {
        if ($class === TvLookupOutcomeWriter::class) {
            return [implode("\0", $args[1]), pack('P*', ...$args[0]), count($args[1])];
        }
        if ($class === LocalDbProvider::class) {
            return [implode("\0", $args[0])."\0".implode("\0", $args[2]), pack('P*', ...$args[1]), count($args[0])];
        }

        return [implode("\0", array_map(static fn (array $column): string => implode("\0", $column), $args)), '', count($args[0])];
    }

    /** @return list<list<int|string>> */
    private static function realistic(string $class): array
    {
        return match ($class) {
            LocalDbProvider::class => [
                ['The Big Bang Theory', 'Doctor Who (2005)', 'Law & Order: Special Victims Unit', "Marvel's Agents of S.H.I.E.L.D.", 'The Office (US)', 'House of the Dragon 2022', 'Shogun', '  Grey\'s Anatomy  ', 'The.Expanse.2015', 'Planet Earth II'],
                [0, 0, 1999, 0, 2005, 0, 2024, 0, 0, 2016],
                ['US', 'GB', 'us', '', 'US', 'US', 'JP', 'usa', 'US', 'GB '],
            ],
            TraktProvider::class => [
                ['tt0944947', 'tt0903747', '', 'tt12345', 'tt11198330', 'tt0386676', 'nm0000123', 'tt7462410', 'tt5491994', 'tt0141842'],
                ['https://walter.trakt.tv/images/shows/000/001/390/posters/thumb/93df9cd612.jpg.webp', 'walter-r2.trakt.tv/images/shows/000/001/388/posters/medium/fa39b59954.jpg', '', '//walter.trakt.tv/x.jpg', 'http://legacy.trakt.tv/p.jpg', '/images/a.jpg', 'https://walter.trakt.tv/images/shows/000/001/392/fanarts/full/1.jpg', '', 'walter.trakt.tv/f.jpg', 'https://walter.trakt.tv/s.jpg'],
            ],
            TvLookupOutcomeWriter::class => [
                [
                    0, 0, 0, 1390, 52311, 0, 0,
                    1390, 52311, 0, 1390, 52311, 0, 0,
                    0, -11, 2, 81, 0, 0, 0,
                    0, 0, 3, 0, 0, 1, 120,
                    0, 0, 0, 0, 0, 0, 0,
                    44, -12, 0, 44, 0, 0, 0,
                    0, 0, 0, 0, 0, 0, 0,
                    7, 900, 0, 8, 901, 0, 0,
                    0, -11, 5, 81, 0, 1, 3600,
                    0, 0, 7, 0, 0, 1, 0,
                ],
                ['matched', 'matched', 'episode_missing', 'transient', 'not_found', 'season_pack', 'parse_failed', 'matched', 'episode_missing', 'transient'],
            ],
            TvPosterResolver::class => [
                ['https://static.tvmaze.com/uploads/images/original_untouched/1/4600.jpg', '', 'https://walter.trakt.tv/images/shows/000/001/390/posters/thumb/93df9cd612.jpg.webp', '', 'walter.trakt.tv/p.jpg', 'https://artworks.thetvdb.com/banners/posters/121361-1.jpg', '', 'https://img/a.jpg?w=500', '', 'https://example.org/poster'],
                ['', 'https://image.tmdb.org/t/p/w500/1XS1oqL89opfnbLl8WnZY1O1uJx.jpg', '', '', 'https://image.tmdb.org/t/p/original/a.png', '', '', '', 'https://image.tmdb.org/t/p/w500/b.jpg', ''],
                ['', '', '', 'https://assets.fanart.tv/fanart/tv/121361/tvposter/game-of-thrones-521bc7ee5fe8b.jpg', '', '', '', '', '', 'https://assets.fanart.tv/fanart/tv/1/tvposter/x.png'],
            ],
            default => [
                ['tvdb', 'imdb', 'tmdb', 'tvmaze', 'trakt', 'IMDB', ' tvdb', 'imdb', 'tmdb', 'anidb'],
                ['121361', 'tt0944947', '1399', '82', '1390', '0944947', '0', 'tt12', '-5', '12'],
            ],
        };
    }

    /** @return list<list<int|string>> */
    private static function benchmarkInput(string $class, bool $uncommon): array
    {
        $base = self::realistic($class);
        if ($uncommon) {
            $long = str_repeat("Caf\u{e9} The.Long-Show (Ext) ", 12);
            $extra = match ($class) {
                LocalDbProvider::class => [
                    ["  {$long} (2019)  ", "\t\x0B", 'THE '.$long, "Show.\u{65e5}\u{672c}.1999", '(2019)', $long.'))'],
                    [0, -1, PHP_INT_MAX, 0, PHP_INT_MIN, 0],
                    [' u s ', "\x0Bfr\n", 'gb', "\u{65e5}\u{672c}", 'XYZ', ''],
                ],
                TraktProvider::class => [
                    ['tt'.str_repeat('9', 40), "TT0944947\n", str_repeat('tt1', 80), 'tt', $long, ''],
                    [str_repeat('/', 30).'walter.trakt.tv/x.jpg', $long, 'https', 'h', '', str_repeat('//a', 50)],
                ],
                TvLookupOutcomeWriter::class => [
                    [
                        PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MAX, 1, PHP_INT_MAX,
                        -1, -4, -6, -1, -2, 1, -50,
                        0, -11, PHP_INT_MAX, 3, 0, 0, 0,
                        0, 0, -9, 0, 0, 0, 0,
                        5, 0, 0, 5, 0, 0, 0,
                        0, 1, 0, 0, 0, 0, 0,
                    ],
                    ['episode_missing', 'transient', 'episode_missing', 'transient', 'MATCHED', 'pending'],
                ],
                TvPosterResolver::class => [
                    ["  \t{$long}.jpg ", 'HTTPS://X/'.$long.'.JPEG', 'https:///'.$long, '//', '', 'http://x/'.str_repeat('a', 300).'.png?'.$long],
                    [$long, '', 'https://x/a.webp#'.$long, "\u{65e5}", 'ftp://x.png', ''],
                    ['https://x/'.$long.'.gif', 'https://x/a.jpg', '', '', '//cdn/'.$long.'.webp', ''],
                ],
                default => [
                    [' IMDB ', 'TvMaze', "imdb\n", 'imdb', 'netflix', $long],
                    ["  tt0944947\t", str_repeat('0', 60).'1', 'T'.str_repeat('1', 11), 'tt1234567890', $long, str_repeat('9', 40)],
                ],
            };
            foreach ($extra as $k => $column) {
                $base[$k] = [...$base[$k], ...$column];
            }
        }
        $records = count($base[$class === TvLookupOutcomeWriter::class ? 1 : 0]);
        $target = $uncommon ? 128 : 64;
        $out = [];
        foreach ($base as $k => $column) {
            $per = $class === TvLookupOutcomeWriter::class && $k === 0 ? 7 : 1;
            $rows = [];
            for ($i = 0; $i < $target; $i++) {
                array_push($rows, ...array_slice($column, ($i % $records) * $per, $per));
            }
            $out[$k] = $rows;
        }

        return $out;
    }

    private static function randomText(Randomizer $random, int $max): string
    {
        $pieces = [' ', '.', '(', ')', 't', 'T', 'h', 'http', 'https://', '//', '/', '?', '#', ':', '19', '20', '0', '7', '12345', 'the ', 'The ', '.jpg', '.PNG', '.webp', 'imdb', 'tvdb', 'us', 'GB', "\t", "\n", "\x0B", "\r", "\u{e9}", "\xff", 'x', '-', '&'];
        $text = '';
        for ($i = 0, $n = $random->getInt(0, $max); $i < $n; $i++) {
            $text .= $pieces[$random->getInt(0, count($pieces) - 1)];
        }
        if ($random->getInt(0, 40) === 0) {
            $text .= "\0";
        }

        return $text;
    }

    /** @return list<list<int|string>> */
    private static function randomBatch(Randomizer $random, string $class, int $count): array
    {
        if ($class === TvLookupOutcomeWriter::class) {
            return self::randomOutcomes($random, $count, true);
        }
        $columns = match ($class) {
            LocalDbProvider::class => 3,
            TvPosterResolver::class => 3,
            default => 2,
        };
        $out = [];
        for ($c = 0; $c < $columns; $c++) {
            $column = [];
            for ($i = 0; $i < $count; $i++) {
                if ($class === LocalDbProvider::class && $c === 1) {
                    $column[] = [0, 0, -1, 1999, 2024, PHP_INT_MAX, PHP_INT_MIN, $random->getInt(-3000, 3000)][$random->getInt(0, 7)];
                } else {
                    $column[] = self::randomText($random, 12);
                }
            }
            $out[] = $column;
        }

        return $out;
    }

    /** @return array{list<int>, list<string>} */
    private static function randomOutcomes(Randomizer $random, int $count, bool $withNul): array
    {
        $statuses = ['matched', 'season_pack', 'episode_missing', 'transient', 'parse_failed', 'not_found', 'skipped', 'pending', '', 'Matched'];
        $values = [0, 0, 1, -1, -10, -11, -12, -100, 5, 6, 7, 8, 900, 3600, PHP_INT_MAX, PHP_INT_MIN, -4, -6];
        $scalars = [];
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $names[] = $statuses[$random->getInt(0, count($statuses) - 1)].($withNul && $random->getInt(0, 60) === 0 ? "\0" : '');
            for ($k = 0; $k < 7; $k++) {
                $scalars[] = $k === 5
                    ? $random->getInt(0, 1)
                    : ($random->getInt(0, 2) === 0 ? $random->getInt(-2000, 2000) : $values[$random->getInt(0, count($values) - 1)]);
            }
        }

        return [$scalars, $names];
    }

    // ---------------------------------------------------------------------
    // Benchmark
    // ---------------------------------------------------------------------

    /**
     * @param  list<int>  $samples
     * @return array{mean: float, p99: int}
     */
    private static function statistics(array $samples): array
    {
        sort($samples, SORT_NUMERIC);

        return ['mean' => array_sum($samples) / count($samples), 'p99' => $samples[(int) ceil(0.99 * count($samples)) - 1]];
    }

    public function test_paired_randomized_benchmark_matches_defaults(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (self::ffi($class) === null) {
                self::markTestSkipped('Native pipeline library with W49 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W49_BENCH_PAIRS') ?: 2000));
        $random = new Randomizer(new Mt19937(self::SEED));
        $report = [];
        // Cycle collection would land on whichever sample crosses the root threshold.
        gc_collect_cycles();
        gc_disable();
        try {
            $this->runBenchmark($pairs, $random, $report);
        } finally {
            gc_enable();
        }
        fwrite(STDERR, "\n");
    }

    /** @param array<string, array<string, mixed>> $report */
    private function runBenchmark(int $pairs, Randomizer $random, array &$report): void
    {
        foreach (self::HELPERS as $name => [$class, $method]) {
            $ratios = [];
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $args = self::benchmarkInput($class, $uncommon);
                $expected = $class::{$method.'Php'}(...$args);
                $timings = ['php' => [], 'rust' => []];
                foreach ([false, true] as $native) {
                    $class::withW49RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW49RustFfi($native, static function () use ($class, $method, $args, $expected): int {
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
            $report[$name] = $ratios + ['qualified' => $qualified];
            fwrite(STDERR, sprintf("\nW49.4 %s %s", $method, json_encode($report[$name], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW49RustFfi(), $method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
