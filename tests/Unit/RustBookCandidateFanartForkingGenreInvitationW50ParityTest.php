<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Invitation;
use App\Services\BookProcessingCandidateQuery;
use App\Services\FanartTvService;
use App\Services\ForkingService;
use App\Services\GenreService;
use App\Services\InvitationService;
use App\Support\RustPipelineLibrary;
use FFI;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Wave 50.4 batch helpers: BookProcessingCandidateQuery candidate states, FanartTvService
 * image ranks, ForkingService timing-header parses, GenreService title keys and
 * InvitationService ledgers. PHP joins each text column with NUL bytes and packs integer
 * scalars with pack('P*'); Rust writes fixed-width int64 words per record that PHP decodes
 * with one unpack('P*'), so a batch costs one FFI crossing.
 *
 * The paired benchmark alternates PHP/Rust order at random per sample (fixed seed), times
 * the public dispatch including packing and decoding, and requires every default-enabled
 * helper to clear 1.5x on common and uncommon mean and p99. Pairs per workload:
 * NNTMUX_W50_BENCH_PAIRS (default 2000, minimum 200). It skips without the native library.
 *
 * Three runs at d6909c009 + this change (Apple Silicon, PHP 8.5.12 CLI, rustc 1.99.1 release
 * build, 2000 pairs per workload after 64 warmups per mode, load average ~9), minimum
 * speedup per gate (common mean/p99, uncommon mean/p99): candidateStateBatch 2.71/1.99,
 * 2.87/2.67; rankImageBatch 2.25/2.24, 3.19/2.75; parseTimingHeaderBatch 2.82/2.83,
 * 2.46/2.06; titleKeyBatch 4.12/4.04, 4.21/3.56; invitationLedgerBatch 3.33/2.62,
 * 3.25/2.63. Common batches hold 64 realistic records; uncommon batches hold 192 mixing
 * long, multibyte, whitespace-heavy and malformed values with extreme integers. All five
 * default to Rust.
 */
final class RustBookCandidateFanartForkingGenreInvitationW50ParityTest extends TestCase
{
    /** [class, batch method, int64 words per record]. */
    private const array HELPERS = [
        'book' => [BookProcessingCandidateQuery::class, 'candidateStateBatch', 2],
        'fanart' => [FanartTvService::class, 'rankImageBatch', 2],
        'forking' => [ForkingService::class, 'parseTimingHeaderBatch', 4],
        'genre' => [GenreService::class, 'titleKeyBatch', 3],
        'invitation' => [InvitationService::class, 'invitationLedgerBatch', 3],
    ];

    private const int SEED = 5042026;

    private const int NOW_US = 1_792_000_000_000_000;

    protected function tearDown(): void
    {
        foreach (self::HELPERS as [$class]) {
            (new ReflectionProperty($class, 'useW50RustFfi'))->setValue(null, null);
            (new ReflectionProperty($class, 'w50Ffi'))->setValue(null, null);
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
        $cdef = (new ReflectionClass($class))->getConstant('W50_FFI_CDEF');
        self::assertIsString($cdef);

        return RustPipelineLibrary::resolveDomain('metadata_w50', $cdef);
    }

    /**
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private static function dispatch(string $class, string $method, bool $native, array $args): array
    {
        $result = $class::withW50RustFfi($native, static fn (): array => $class::{$method}(...$args));
        self::assertIsArray($result);
        self::assertTrue(array_is_list($result));

        return $result;
    }

    /** @param list<mixed> $args */
    private static function rows(array $args): int
    {
        $last = $args[array_key_last($args)];
        self::assertIsArray($last);

        return count($last);
    }

    /** @param list<mixed> $args */
    private static function assertParity(string $class, string $method, int $words, array $args, string $label): void
    {
        $php = self::dispatch($class, $method, false, $args);
        self::assertSame($php, $class::{$method.'Php'}(...$args), $label.': PHP dispatch');
        self::assertSame($php, self::dispatch($class, $method, true, $args), $label.': Rust dispatch');
        self::assertSame($php, $class::{$method.'Native'}(...$args), $label.': native method');
        self::assertCount(self::rows($args) * $words, $php, $label.': words per record');
        foreach ($php as $word) {
            self::assertIsInt($word);
        }
    }

    // ---------------------------------------------------------------------
    // API contract
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_w50_switch_api_and_default(string $class, string $method, int $words): void
    {
        self::assertGreaterThan(0, $words);
        $reflection = new ReflectionClass($class);
        $constant = $reflection->getReflectionConstant('W50_RUST_FFI_DEFAULT');
        self::assertNotFalse($constant);
        self::assertTrue($constant->isPrivate());
        self::assertIsBool($constant->getValue());
        self::assertTrue($reflection->getProperty('useW50RustFfi')->isPrivate());
        self::assertTrue($reflection->getProperty('useW50RustFfi')->isStatic());
        foreach (['usesW50RustFfi', 'withW50RustFfi', $method, $method.'Php', $method.'Native'] as $name) {
            self::assertTrue($reflection->getMethod($name)->isPublic() && $reflection->getMethod($name)->isStatic(), $name);
        }

        self::assertSame($constant->getValue(), $class::usesW50RustFfi());
        self::assertFalse($class::withW50RustFfi(false, static fn (): bool => $class::usesW50RustFfi()));
        self::assertTrue($class::withW50RustFfi(true, static fn (): bool => $class::usesW50RustFfi()));
        self::assertSame($constant->getValue(), $class::usesW50RustFfi(), 'withW50RustFfi restores the default');

        $nested = $class::withW50RustFfi(false, static fn (): array => [
            $class::usesW50RustFfi(),
            $class::withW50RustFfi(true, static fn (): bool => $class::usesW50RustFfi()),
            $class::usesW50RustFfi(),
        ]);
        self::assertSame([false, true, false], $nested);

        try {
            $class::withW50RustFfi(! $constant->getValue(), static function (): never {
                throw new RuntimeException('boom');
            });
            self::fail('The callback exception must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($constant->getValue(), $class::usesW50RustFfi(), 'restored after an exception');
    }

    #[DataProvider('helperProvider')]
    public function test_empty_batches_and_shape_errors(string $class, string $method, int $words): void
    {
        self::assertGreaterThan(0, $words);
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach ([false, true] as $native) {
            self::assertSame([], self::dispatch($class, $method, $native, self::batch($class, $random, 0, false)));
            foreach (self::malformed($class, self::batch($class, $random, 4, false)) as $label => $args) {
                try {
                    self::dispatch($class, $method, $native, $args);
                    self::fail($label.' must be rejected.');
                } catch (InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, list<mixed>>
     */
    private static function malformed(string $class, array $args): array
    {
        $cases = [];
        $columns = array_keys(array_filter($args, 'is_array'));
        foreach ($columns as $index) {
            $short = $args;
            $short[$index] = array_slice($short[$index], 1);
            $cases['short column '.$index] = $short;
            $holes = $args;
            $holes[$index] = array_combine(range(1, count($holes[$index])), $holes[$index]);
            $cases['non-list column '.$index] = $holes;
        }
        if ($class === ForkingService::class || $class === GenreService::class) {
            unset($cases['short column 0']);
        }

        return $cases;
    }

    // ---------------------------------------------------------------------
    // Hand-calculated contracts
    // ---------------------------------------------------------------------

    public function test_book_candidate_hand_cases_match_sql_conditions(): void
    {
        $searchnames = ['Some.Book.epub', 'n:/nzb/abc', 'Book', 'x', 'NxNZB-a', 'Audio'];
        $names = ['a', '', 'N_NZB_', 'N:/NZ', '', 'b'];
        $scalars = [
            7010, 1, 0, 0, 1, 0, 0, 0,
            7999, 1, 0, 4, 1, 0, 0, 0,
            6000, 1, 1, 1, 0, 1, 0, 1000,
            3030, 0, 0, 0, 0, 1, 1, 0,
            7000, 1, 0, PHP_INT_MAX, 1, 0, 0, 0,
            3030, 0, 0, 2, 1, 1, 0, 7200,
        ];
        $expected = [
            1 | 2 | 4 | 16, 300,
            1 | 2 | 4 | 8 | 16 | 32, BookProcessingCandidateQuery::EXHAUSTED,
            2 | 32, 1000,
            1, 300,
            1 | 2 | 8 | 16 | 32, BookProcessingCandidateQuery::EXHAUSTED,
            1 | 2 | 4 | 16, 7200,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(BookProcessingCandidateQuery::class, 'candidateStateBatch', $native, [1, true, $scalars, $searchnames, $names]));
            $mode2 = self::dispatch(BookProcessingCandidateQuery::class, 'candidateStateBatch', $native, [2, false, $scalars, $searchnames, $names]);
            self::assertSame([1 | 2 | 4 | 16, 1 | 2 | 4 | 8 | 16 | 32, 1 | 2, 1 | 2], [$mode2[0], $mode2[2], $mode2[6], $mode2[10]]);
            $off = self::dispatch(BookProcessingCandidateQuery::class, 'candidateStateBatch', $native, [0, true, $scalars, $searchnames, $names]);
            for ($i = 0; $i < 6; $i++) {
                self::assertSame(0, $off[$i * 2] & 28);
            }
        }
        // The delay agrees with retryDelayPhp() for the next failure.
        self::assertSame(BookProcessingCandidateQuery::retryDelayPhp(2, 1000), $expected[5]);
        self::assertSame([7000, 7999, 3030, 5], [Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN, Category::MUSIC_AUDIOBOOK, BookProcessingCandidateQuery::MAX_FAILURES]);
    }

    public function test_fanart_rank_hand_cases_pick_first_most_liked(): void
    {
        $groups = [1, 1, 1, 2, 2, 3, 3];
        $likes = ['3', ' 7 ', '7', 'x1', '', '99999999999999999999', '0'];
        $urls = ['https://assets.FANART.tv/fanart/a.JPG', 'http://x/preview/b.png', '', '  //cdn/c.jpeg?x  ', "\t", 'https://assets.fanart.tv/d.webp', 'plain'];
        $expected = [
            3, 2 | 4 | 8 | 32,
            7, 1 | 2 | 16 | 64,
            7, 2 | 128,
            0, 1,
            0, 128,
            PHP_INT_MAX, 1 | 2 | 4 | 8,
            0, 2,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(FanartTvService::class, 'rankImageBatch', $native, [$groups, $likes, $urls]));
        }
        // The best flag agrees with selectBestPosterUrlPhp() for numeric likes.
        $posters = [['url' => 'a', 'likes' => '3'], ['url' => 'b', 'likes' => '7'], ['url' => 'c', 'likes' => '7']];
        self::assertSame('b', (new ReflectionClass(FanartTvService::class))->newInstanceWithoutConstructor()->selectBestPosterUrlPhp($posters));
    }

    public function test_forking_timing_hand_cases_round_trip_timing_header(): void
    {
        $date = 'Sun, 25 Oct 2026 21:10:10 +0100';
        $lines = [
            ForkingService::timingHeaderPhp('backfill', 12, $date),
            'Multi-processing for custom finished in 0 seconds at .',
            'Multi-processing for  finished in 5 seconds at x.',
            'Multi-processing for releases finished in -1 seconds at x.',
            'Multi-processing for releases finished in 1 seconds at x',
            'multi-processing for releases finished in 1 seconds at x.',
            'Multi-processing for postProcess_tv finished in 1234567890123456789 seconds at x.',
            "Multi-processing for update_per_group finished in 007 seconds at a.b.\n\n",
        ];
        $span = static fn (int $offset, int $length): int => ($offset << 32) | $length;
        $expected = [
            0, 12, 8, $span(56, 31),
            -1, 0, 6, $span(53, 0),
            -1, 5, 0, $span(47, 1),
            -2, 0, 0, 0,
            -2, 0, 0, 0,
            -2, 0, 0, 0,
            -2, 0, 0, 0,
            -2, 0, 0, 0,
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(ForkingService::class, 'parseTimingHeaderBatch', $native, [$lines]));
            $all = array_map(static fn (string $type): string => ForkingService::timingHeaderPhp($type, 3, $date), ForkingService::WORK_TYPES);
            $parsed = self::dispatch(ForkingService::class, 'parseTimingHeaderBatch', $native, [$all]);
            foreach (ForkingService::WORK_TYPES as $index => $type) {
                self::assertSame([$index, 3, strlen($type)], array_slice($parsed, $index * 4, 3), $type);
                $word = $parsed[$index * 4 + 3];
                self::assertSame($date, substr($all[$index], $word >> 32, $word & 0xFFFFFFFF));
            }
        }
    }

    public function test_genre_title_hand_cases_match_normalize_genres_map(): void
    {
        $titles = [' Sci-Fi & Fantasy ', 'sci-fi & fantasy', '', 'Action', 'ACTION', "Caf\u{e9} 80s", 'a / / b;'];
        $span = static fn (int $offset, int $length): int => ($offset << 32) | $length;
        $expected = [
            -1, $span(1, 16), 2 | 8 | 32 | 64 | (2 << 8),
            0, $span(0, 16), 2 | 8 | (2 << 8),
            -1, $span(0, 0), 1,
            -1, $span(0, 6), 32 | 64 | (1 << 8),
            3, $span(0, 6), 64 | (1 << 8),
            -1, $span(0, 9), 4 | 16 | 32 | 64 | (1 << 8),
            -1, $span(0, 8), 2 | (2 << 8),
        ];
        foreach ([false, true] as $native) {
            $words = self::dispatch(GenreService::class, 'titleKeyBatch', $native, [$titles]);
            self::assertSame($expected, $words);
            // Non-duplicates are exactly the keys normalizeGenresMapPhp() keeps first.
            $map = GenreService::normalizeGenresMapPhp(array_map(static fn (int $id, string $title): array => ['id' => $id, 'title' => trim($title)], array_keys($titles), $titles));
            foreach ($titles as $i => $title) {
                if (trim($title) !== '') {
                    $first = $words[$i * 3] === -1 ? $i : $words[$i * 3];
                    self::assertSame($map[$first], strtolower(trim($title)));
                }
            }
        }
    }

    public function test_invitation_ledger_hand_cases_match_invitation_state(): void
    {
        $emails = [' Alice@Example.com ', 'alice@example.com', 'bob@x', '', 'c d@e.f', '@e.f'];
        $scalars = [1, 200, 0, 1, 50, 0, 0, 100, 0, 0, 300, 1, 1, 100, 0, 1, PHP_INT_MIN, 0];
        $span = static fn (int $offset, int $length): int => ($offset << 32) | $length;
        $expected = [
            1 | 16 | 32, -1, $span(1, 17),
            2 | 16, 0, $span(0, 17),
            8, -1, $span(0, 5),
            4, -1, $span(0, 0),
            0, -1, $span(0, 7),
            2, -1, $span(0, 4),
        ];
        foreach ([false, true] as $native) {
            self::assertSame($expected, self::dispatch(InvitationService::class, 'invitationLedgerBatch', $native, [100, $scalars, $emails]));
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        [$now, $rows, $mail] = self::batch(InvitationService::class, $random, 64, true);
        self::assertIsInt($now);
        self::assertIsArray($rows);
        self::assertIsArray($mail);
        $words = InvitationService::invitationLedgerBatchPhp($now, $rows, $mail);
        foreach ($mail as $i => $email) {
            $state = Invitation::evaluateInvitationStatePhp($rows[$i * 3] !== 0, $rows[$i * 3 + 1], $now, $rows[$i * 3 + 2] !== 0);
            self::assertSame([$state['is_valid'], $state['is_expired'], $state['is_used']], [($words[$i * 3] & 1) !== 0, ($words[$i * 3] & 2) !== 0, ($words[$i * 3] & 4) !== 0]);
            self::assertSame(Invitation::normalizeEmailPhp($email) !== $email, ($words[$i * 3] & 32) !== 0);
        }
    }

    // ---------------------------------------------------------------------
    // Native execution and fallbacks
    // ---------------------------------------------------------------------

    #[DataProvider('helperProvider')]
    public function test_rust_dispatch_executes_natively(string $class, string $method, int $words): void
    {
        if (self::ffi($class) === null) {
            self::markTestSkipped('Native pipeline library with W50 symbols is not built.');
        }
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 64, false);
        $expected = $class::{$method.'Php'}(...$args);
        // A library that cannot be resolved must not be what produced the native result.
        $property = new ReflectionProperty($class, 'w50Ffi');
        $property->setValue(null, null);
        self::assertSame($expected, $class::{$method.'Native'}(...$args));
        self::assertInstanceOf(FFI::class, $property->getValue());
        self::assertCount(64 * $words, $expected);
    }

    #[DataProvider('helperProvider')]
    public function test_nul_bytes_and_missing_library_fall_back_to_php(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $args = self::batch($class, $random, 16, true);
        $textColumn = array_key_last($args);
        $withNul = $args;
        self::assertIsArray($withNul[$textColumn]);
        $withNul[$textColumn][3] = "a\0b";
        self::assertParity($class, $method, $words, $withNul, 'NUL byte');

        $property = new ReflectionProperty($class, 'w50Ffi');
        $property->setValue(null, false);
        self::assertSame($class::{$method.'Php'}(...$args), $class::{$method.'Native'}(...$args));
        $property->setValue(null, null);
    }

    #[DataProvider('helperProvider')]
    public function test_realistic_and_uncommon_dataset_parity(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        self::assertParity($class, $method, $words, self::batch($class, $random, 64, false), 'common');
        self::assertParity($class, $method, $words, self::batch($class, $random, 128, true), 'uncommon');
        // Batches beyond the cached output buffer grow it.
        self::assertParity($class, $method, $words, self::batch($class, $random, 1500, true), 'large');
        self::assertParity($class, $method, $words, self::batch($class, $random, 1, true), 'single');
    }

    #[DataProvider('helperProvider')]
    public function test_randomized_parity(string $class, string $method, int $words): void
    {
        $random = new Randomizer(new Mt19937(self::SEED + crc32($class)));
        for ($round = 0; $round < 60; $round++) {
            self::assertParity($class, $method, $words, self::batch($class, $random, $random->getInt(1, 96), $round % 2 === 1), 'round '.$round);
        }
    }

    // ---------------------------------------------------------------------
    // Datasets
    // ---------------------------------------------------------------------

    /** @param list<mixed> $values */
    private static function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, count($values) - 1)];
    }

    /** Random bytes biased toward the characters each parser inspects. */
    private static function noise(Randomizer $random, int $max): string
    {
        $alphabet = " \t\n\r\x0B.-/,&|;@:_?#0123456789aAzZnNbBtTsSxX\x80\xC3\xA9\xE2\x82\xAC\x7F";
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
            BookProcessingCandidateQuery::class => self::bookBatch($random, $count, $uncommon),
            FanartTvService::class => self::fanartBatch($random, $count, $uncommon),
            ForkingService::class => [self::timingLines($random, $count, $uncommon)],
            GenreService::class => [self::genreTitles($random, $count, $uncommon)],
            InvitationService::class => self::invitationBatch($random, $count, $uncommon),
            default => throw new RuntimeException('Unknown helper '.$class),
        };
    }

    /** @return list<mixed> */
    private static function bookBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $scalars = [];
        $searchnames = [];
        $names = [];
        $titles = ['Stephen.King-The.Stand.1978.EPUB-BOOKS', 'Brandon_Sanderson_-_Mistborn_(2006)_retail.mobi', 'N:/NZB/7f3a9c', 'N_NZB_4d21', 'Some.Audiobook.MP3.2019-GRP', 'Learning.Rust.2nd.Edition.PDF'];
        for ($i = 0; $i < $count; $i++) {
            if ($uncommon) {
                $category = (int) self::pick($random, [Category::BOOKS_ROOT - 1, Category::BOOKS_ROOT, Category::BOOKS_UNKNOWN, Category::BOOKS_UNKNOWN + 1, Category::MUSIC_AUDIOBOOK, PHP_INT_MIN, PHP_INT_MAX, 0]);
                $searchnames[] = self::pick($random, ['n:/nzB', 'N€NZB_', 'n_nzb', str_repeat('N:/NZB', 40), self::noise($random, 80), '']);
                $names[] = self::noise($random, 40);
                array_push($scalars, $category, $random->getInt(-1, 2), $random->getInt(0, 1), (int) self::pick($random, [PHP_INT_MAX, PHP_INT_MIN, -1, 3, 4, 5, 6]), $random->getInt(0, 1), $random->getInt(0, 1), $random->getInt(0, 1), (int) self::pick($random, [PHP_INT_MAX, PHP_INT_MIN, 0, 86400]));
            } else {
                $category = (int) self::pick($random, [7010, 7020, 7030, 7040, 7999, 3030, 2040, 5040]);
                $searchnames[] = self::pick($random, $titles).$random->getInt(0, 999);
                $names[] = self::pick($random, $titles);
                array_push($scalars, $category, $random->getInt(0, 1), $random->getInt(0, 1), $random->getInt(0, 6), $random->getInt(0, 1), $random->getInt(0, 1), $random->getInt(0, 3) === 0 ? 1 : 0, (int) self::pick($random, [0, 0, 600, 7200]));
            }
        }
        $mode = $uncommon ? (int) self::pick($random, [-1, 0, 1, 2, 3, PHP_INT_MAX]) : (int) self::pick($random, [1, 2]);

        return [$mode, $random->getInt(0, 1) === 1, $scalars, $searchnames, $names];
    }

    /** @return list<mixed> */
    private static function fanartBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $groups = [];
        $likes = [];
        $urls = [];
        $group = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($random->getInt(0, 3) === 0) {
                $group++;
            }
            if ($uncommon) {
                $groups[] = (int) self::pick($random, [$group, PHP_INT_MIN, PHP_INT_MAX, $group]);
                $likes[] = (string) self::pick($random, [' 12 ', '-3', '1e3', '007', '99999999999999999999999', '', "\t", 'x', '5abc', (string) $random->getInt(0, 50)]);
                $urls[] = self::pick($random, [
                    '  HTTPS://Assets.Fanart.TV/fanart/tv/1/tvposter/a-'.$random->getInt(1, 99999).'.JPEG  ',
                    'http://fanart.tv/preview/'.str_repeat('x', $random->getInt(0, 300)).'.png',
                    "caf\u{e9}.jpg?",
                    self::noise($random, 120),
                    '',
                    '.',
                    'https://',
                ]);
            } else {
                $groups[] = $group;
                $likes[] = (string) $random->getInt(0, 40);
                $kind = self::pick($random, ['tvposter', 'movieposter', 'showbackground', 'hdtvlogo']);
                $urls[] = 'https://assets.fanart.tv/fanart/tv/'.$random->getInt(70000, 450000).'/'.$kind.'/show-'.dechex($random->getInt(0x10000000, 0x7FFFFFFF)).'.'.self::pick($random, ['jpg', 'jpg', 'png']);
            }
        }

        return [$groups, $likes, $urls];
    }

    /** @return list<string> */
    private static function timingLines(Randomizer $random, int $count, bool $uncommon): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $date = 'Sun, 25 Oct 2026 '.sprintf('%02d:%02d:%02d', $random->getInt(0, 23), $random->getInt(0, 59), $random->getInt(0, 59)).' +0100';
            $type = self::pick($random, ForkingService::WORK_TYPES);
            if (! $uncommon) {
                $lines[] = ForkingService::timingHeaderPhp($type, $random->getInt(0, 7200), $date);

                continue;
            }
            $lines[] = self::pick($random, [
                ForkingService::timingHeaderPhp($type.self::noise($random, 4), $random->getInt(0, PHP_INT_MAX), $date),
                ForkingService::timingHeaderPhp(strtoupper($type), $random->getInt(0, 9), self::noise($random, 30)),
                'Multi-processing for '.$type.' finished in  seconds at x.',
                'Multi-processing for '.$type.' finished in '.str_repeat('9', $random->getInt(17, 20)).' seconds at x.',
                'Multi-processing for a finished in 1 seconds at b finished in 2 seconds at c.',
                "Multi-processing for \u{e9}\u{e9} finished in 4 seconds at ".str_repeat('z', 200).".\n",
                'Multi-processing for '.$type.' finished in 3 seconds at',
                'Multi-processing for ',
                self::noise($random, 90),
                '',
            ]);
        }

        return $lines;
    }

    /** @return list<string> */
    private static function genreTitles(Randomizer $random, int $count, bool $uncommon): array
    {
        $common = ['Action', 'Adventure', 'Animation', 'Comedy', 'Crime', 'Documentary', 'Drama', 'Family', 'Fantasy', 'Horror', 'Music', 'Mystery', 'Romance', 'Science Fiction', 'Thriller', 'War', 'Western', 'Sci-Fi & Fantasy', 'Action & Adventure', 'Hip-Hop', 'R&B', 'Rock', 'Pop', 'Jazz', 'Electronic', 'Classical'];
        $titles = [];
        for ($i = 0; $i < $count; $i++) {
            $title = self::pick($random, $common);
            if ($uncommon) {
                $title = self::pick($random, [
                    '  '.strtoupper($title)." \t",
                    $title.' / '.self::pick($random, $common).', '.self::pick($random, $common),
                    "M\u{fa}sica Popular Brasileira",
                    '80s '.$title,
                    str_repeat($title.'; ', $random->getInt(1, 20)),
                    ' / ; , ',
                    self::noise($random, 60),
                    '',
                ]);
            } elseif ($random->getInt(0, 4) === 0) {
                $title = strtolower($title);
            }
            $titles[] = $title;
        }

        return $titles;
    }

    /** @return list<mixed> */
    private static function invitationBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $scalars = [];
        $emails = [];
        $domains = ['gmail.com', 'example.org', 'mail.example.co.uk', 'proton.me', 'outlook.com'];
        $day = 86_400_000_000;
        for ($i = 0; $i < $count; $i++) {
            $user = self::pick($random, ['alice', 'bob', 'carol.smith', 'dave+nzb', 'erin_99', 'frank']).$random->getInt(0, 40);
            if ($uncommon) {
                $emails[] = self::pick($random, [
                    '  '.strtoupper($user).'@'.strtoupper(self::pick($random, $domains)).' ',
                    $user.'@@'.self::pick($random, $domains),
                    '@'.self::pick($random, $domains),
                    $user.'@.com',
                    $user.'@domain.',
                    $user.'@nodot',
                    "j\u{f6}rg@m\u{fc}ller.de",
                    $user." x@y.z\x7F",
                    str_repeat('a', 300).'@'.self::pick($random, $domains),
                    self::noise($random, 50),
                    '',
                ]);
                array_push($scalars, (int) self::pick($random, [0, 1, -1, PHP_INT_MAX]), (int) self::pick($random, [PHP_INT_MIN, PHP_INT_MAX, self::NOW_US, self::NOW_US - 1, self::NOW_US + 1]), (int) self::pick($random, [0, 1, PHP_INT_MIN]));
            } else {
                $emails[] = $user.'@'.self::pick($random, $domains);
                array_push($scalars, $random->getInt(0, 9) === 0 ? 0 : 1, self::NOW_US + $random->getInt(-7, 7) * $day + $random->getInt(0, 999_999), $random->getInt(0, 4) === 0 ? 1 : 0);
            }
        }

        return [self::NOW_US, $scalars, $emails];
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
        sort($samples);

        return ['mean' => array_sum($samples) / count($samples), 'p99' => $samples[(int) floor(0.99 * (count($samples) - 1))]];
    }

    public function test_paired_randomized_benchmark_matches_defaults(): void
    {
        foreach (self::HELPERS as [$class]) {
            if (self::ffi($class) === null) {
                self::markTestSkipped('Native pipeline library with W50 symbols is not built.');
            }
        }
        $pairs = max(200, (int) (getenv('NNTMUX_W50_BENCH_PAIRS') ?: 2000));
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
                    $class::withW50RustFfi($native, static function () use ($class, $method, $args, $expected): void {
                        for ($i = 0; $i < 64; $i++) {
                            self::assertSame($expected, $class::{$method}(...$args));
                        }
                    });
                }
                for ($sample = 0; $sample < $pairs; $sample++) {
                    foreach ($random->getInt(0, 1) === 1 ? [false, true] : [true, false] as $native) {
                        $elapsed = $class::withW50RustFfi($native, static function () use ($class, $method, $args, $expected): int {
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
            fwrite(STDERR, sprintf("\nW50.4 %s %s", $method, json_encode($ratios + ['qualified' => $qualified], JSON_THROW_ON_ERROR)));
            self::assertSame($qualified, $class::usesW50RustFfi(), $name.' '.$method.' default must match the 1.5x mean/p99 gates: '.json_encode($ratios, JSON_THROW_ON_ERROR));
        }
    }
}
