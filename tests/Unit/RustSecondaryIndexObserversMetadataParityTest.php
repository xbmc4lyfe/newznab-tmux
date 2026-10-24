<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Models\BookInfo;
use App\Models\ConsoleInfo;
use App\Models\GamesInfo;
use App\Models\MusicInfo;
use App\Observers\AnidbTitleObserver;
use App\Observers\BookInfoObserver;
use App\Observers\ConsoleInfoObserver;
use App\Observers\GamesInfoObserver;
use App\Observers\MusicInfoObserver;
use App\Support\RustPipelineLibrary;
use App\Support\SecondaryIndexDocuments;
use Carbon\Carbon;
use Closure;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Stringable;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 22 Slice 22.5:
 * AnidbTitleObserver, BookInfoObserver, ConsoleInfoObserver, GamesInfoObserver, and MusicInfoObserver.
 *
 * Benchmarks run only with NNTMUX_SECONDARY_INDEX_OBSERVERS_METADATA_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_SECONDARY_INDEX_OBSERVERS_METADATA_BENCH=1 php artisan test --compact tests/Unit/RustSecondaryIndexObserversMetadataParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency, FFI call overhead included):
 *
 * | Method                                     | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | AnidbTitleObserver::resolveDocumentId      | 0.70x / 0.70x       | 0.42x / 0.38x         | No      | PHP (false)  |
 * | AnidbTitleObserver::buildSearchDocument    | 0.27x / 0.26x       | 0.25x / 0.25x         | No      | PHP (false)  |
 * | BookInfoObserver::resolveDocumentId        | 0.10x / 0.11x       | 0.05x / 0.04x         | No      | PHP (false)  |
 * | BookInfoObserver::buildSearchDocument      | 0.21x / 0.22x       | 0.19x / 0.19x         | No      | PHP (false)  |
 * | ConsoleInfoObserver::resolveDocumentId     | 0.09x / 0.11x       | 0.05x / 0.04x         | No      | PHP (false)  |
 * | ConsoleInfoObserver::releasedateToTimestamp | 0.36x / 0.31x       | 0.77x / 0.74x         | No      | PHP (false)  |
 * | ConsoleInfoObserver::buildSearchDocument   | 0.72x / 0.64x       | 3.32x / 2.54x         | No      | PHP (false)  |
 * | GamesInfoObserver::resolveDocumentId       | 0.09x / 0.09x       | 0.05x / 0.05x         | No      | PHP (false)  |
 * | GamesInfoObserver::releasedateToTimestamp  | 0.36x / 0.35x       | 0.79x / 0.79x         | No      | PHP (false)  |
 * | GamesInfoObserver::buildSearchDocument     | 0.81x / 0.65x       | 4.07x / 3.87x         | No      | PHP (false)  |
 * | MusicInfoObserver::resolveDocumentId       | 0.10x / 0.10x       | 0.05x / 0.05x         | No      | PHP (false)  |
 * | MusicInfoObserver::buildSearchDocument     | 0.21x / 0.19x       | 0.19x / 0.19x         | No      | PHP (false)  |
 */
class RustSecondaryIndexObserversMetadataParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        AnidbTitleObserver::class,
        BookInfoObserver::class,
        ConsoleInfoObserver::class,
        GamesInfoObserver::class,
        MusicInfoObserver::class,
    ];

    private string $originalTimezone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', AnidbTitleObserver::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::resetRustFfi();
            $class::setFfiForTesting(null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $this->assertFalse(
                $class::usesRustFfi(),
                "{$class} must keep PHP as default until its Rust path passes the 1.5x gate across common and uncommon mean/p99"
            );
        }
    }

    public function test_toggle_api(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());
            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    // ---------------------------------------------------------------------
    // 1. AnidbTitleObserver
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
     */
    public static function anidbDocIdProvider(): array
    {
        return [
            'official english title' => [12345, 'official', 'en', 'Cowboy Bebop'],
            'japanese main title' => [1, 'main', 'x-jat', 'Seikai no Monshou'],
            'utf8 kanji title' => [9876, 'official', 'ja', '攻殻機動隊 STAND ALONE COMPLEX'],
            'zero anidbid and empty strings' => [0, '', '', ''],
            'negative anidbid' => [-42, 'syn', 'de', 'Test Anime'],
            'pipes inside fields' => [555, 'syn|alt', 'en|us', 'Part 1 | Part 2'],
            'nul bytes inside fields' => [777, "main\0x", "en\0y", "Title\0With\0Nul"],
        ];
    }

    #[DataProvider('anidbDocIdProvider')]
    public function test_anidb_title_observer_document_id_parity(
        int $anidbid,
        string $type,
        string $lang,
        string $title,
    ): void {
        $expected = SecondarySearchIndex::animeTitleDocumentIdPhp($anidbid, $type, $lang, $title);
        $this->assertSame($expected, AnidbTitleObserver::computeDocumentIdPhp($anidbid, $type, $lang, $title));
        $this->assertSame($expected, AnidbTitleObserver::computeDocumentIdNative($anidbid, $type, $lang, $title));

        $attrs = [
            'anidbid' => $anidbid,
            'type' => $type,
            'lang' => $lang,
            'title' => $title,
        ];
        $this->assertSame($expected, AnidbTitleObserver::resolveDocumentIdFromAttributesPhp($attrs));
        $this->assertSame($expected, AnidbTitleObserver::resolveDocumentIdFromAttributesNative($attrs));

        $model = new AnidbTitle;
        $model->setRawAttributes($attrs, true);
        $this->assertSame($expected, AnidbTitleObserver::resolveDocumentIdPhp($model));
        $this->assertSame($expected, AnidbTitleObserver::resolveDocumentIdNative($model));

        foreach ([true, false, null] as $toggle) {
            AnidbTitleObserver::setUseRustFfi($toggle);
            $this->assertSame($expected, AnidbTitleObserver::computeDocumentId($anidbid, $type, $lang, $title));
            $this->assertSame($expected, AnidbTitleObserver::resolveDocumentIdFromAttributes($attrs));
            $this->assertSame($expected, AnidbTitleObserver::resolveDocumentId($model));
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>|null}>
     */
    public static function anidbSearchDocProvider(): array
    {
        return [
            'populated title and info' => [
                [
                    'anidbid' => 23,
                    'type' => 'official',
                    'lang' => 'en',
                    'title' => 'Cowboy Bebop',
                ],
                [
                    'anidbid' => 23,
                    'anilist_id' => 1,
                    'mal_id' => 1,
                    'media_type' => 'ANIME',
                    'status' => 'FINISHED',
                ],
            ],
            'title without info' => [
                [
                    'anidbid' => 104,
                    'type' => 'main',
                    'lang' => 'x-jat',
                    'title' => 'Fullmetal Alchemist',
                ],
                null,
            ],
            'sparse and numeric string attributes' => [
                [
                    'anidbid' => '512',
                    'type' => null,
                    'lang' => 'ja',
                    'title' => '鋼の錬金術師',
                ],
                [
                    'anilist_id' => '121',
                    'mal_id' => null,
                    'media_type' => null,
                    'status' => 'RELEASING',
                ],
            ],
            'empty attributes' => [
                [],
                [],
            ],
            'boolean and integer edge values' => [
                [
                    'anidbid' => true,
                    'type' => 2,
                    'lang' => false,
                    'title' => 1998,
                ],
                [
                    'anilist_id' => false,
                    'mal_id' => true,
                    'media_type' => 10,
                    'status' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $titleAttrs
     * @param  array<string, mixed>|null  $infoAttrs
     */
    #[DataProvider('anidbSearchDocProvider')]
    public function test_anidb_title_observer_search_document_and_sync_parity(
        array $titleAttrs,
        ?array $infoAttrs,
    ): void {
        $titleModel = new AnidbTitle;
        $titleModel->setRawAttributes($titleAttrs, true);

        $infoModel = null;
        if ($infoAttrs !== null) {
            $infoModel = new AnidbInfo;
            $infoModel->setRawAttributes($infoAttrs, true);
        }
        $titleModel->setRelation('info', $infoModel !== null ? new EloquentCollection([$infoModel]) : null);

        $expectedDoc = AnidbTitleObserver::normalizeSearchDocumentPhp($titleAttrs, $infoAttrs ?? []);
        $this->assertSame(
            $expectedDoc,
            AnidbTitleObserver::normalizeSearchDocumentNative($titleAttrs, $infoAttrs ?? []),
        );
        $this->assertSame(
            $expectedDoc,
            AnidbTitleObserver::buildSearchDocumentPhp($titleModel, $infoModel, false),
        );
        $this->assertSame(
            $expectedDoc,
            AnidbTitleObserver::buildSearchDocumentNative($titleModel, $infoModel, false),
        );

        if ($infoModel !== null) {
            $this->assertSame(
                SecondaryIndexDocuments::animeTitle($titleModel, $infoModel),
                $expectedDoc,
            );
        }

        $expectedDocId = AnidbTitleObserver::resolveDocumentIdPhp($titleModel);

        foreach ([false, true] as $useRust) {
            AnidbTitleObserver::setUseRustFfi($useRust);
            $this->assertSame($expectedDoc, AnidbTitleObserver::buildSearchDocument($titleModel));

            Search::shouldReceive('insertSecondary')
                ->once()
                ->with(SecondarySearchIndex::Anime, $expectedDocId, $expectedDoc);
            (new AnidbTitleObserver)->created($titleModel);

            Search::shouldReceive('deleteSecondary')
                ->once()
                ->with(SecondarySearchIndex::Anime, $expectedDocId);
            (new AnidbTitleObserver)->deleted($titleModel);
        }
    }

    // ---------------------------------------------------------------------
    // 2. BookInfoObserver
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function bookInfoProvider(): array
    {
        return [
            'populated book record' => [[
                'id' => 101,
                'title' => 'Dune',
                'author' => 'Frank Herbert',
                'publishdate' => '1965-08-01',
                'cover' => 1,
                'overview' => 'Ignored extra column',
            ]],
            'sparse book with nulls and booleans' => [[
                'id' => '202',
                'title' => 'Neuromancer',
                'author' => null,
                'publishdate' => null,
                'cover' => true,
            ]],
            'empty attributes' => [[]],
            'utf8 and special characters' => [[
                'id' => 303,
                'title' => 'L\'Étranger',
                'author' => 'Albert Camus',
                'publishdate' => '1942-05-19',
                'cover' => '0',
            ]],
            'float attribute declines cleanly to php' => [[
                'id' => 404,
                'title' => 'Float Cover Test',
                'author' => 'Author',
                'publishdate' => '2020-01-01',
                'cover' => 1.0,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('bookInfoProvider')]
    public function test_book_info_observer_search_document_and_sync_parity(array $attributes): void
    {
        $book = new BookInfo;
        $book->setRawAttributes($attributes, true);

        $expectedDoc = SecondaryIndexDocuments::book($book);
        $this->assertSame($expectedDoc, BookInfoObserver::buildSearchDocumentPhp($book));
        $this->assertSame($expectedDoc, BookInfoObserver::buildSearchDocumentNative($book));
        $this->assertSame(
            BookInfoObserver::normalizeSearchDocumentPhp($attributes),
            BookInfoObserver::normalizeSearchDocumentNative($attributes),
        );

        $expectedId = BookInfoObserver::resolveDocumentIdPhp($book);
        $this->assertSame($expectedId, BookInfoObserver::resolveDocumentIdNative($book));

        foreach ([false, true] as $useRust) {
            BookInfoObserver::setUseRustFfi($useRust);
            $this->assertSame($expectedDoc, BookInfoObserver::buildSearchDocument($book));
            $this->assertSame($expectedId, BookInfoObserver::resolveDocumentId($book));

            Search::shouldReceive('insertSecondary')
                ->once()
                ->with(SecondarySearchIndex::Books, $expectedId, $expectedDoc);
            (new BookInfoObserver)->created($book);

            Search::shouldReceive('deleteSecondary')
                ->once()
                ->with(SecondarySearchIndex::Books, $expectedId);
            (new BookInfoObserver)->deleted($book);
        }
    }

    // ---------------------------------------------------------------------
    // 3. ConsoleInfoObserver & 4. GamesInfoObserver
    // ---------------------------------------------------------------------

    public function test_console_and_games_observer_releasedate_to_timestamp_parity(): void
    {
        $stringableDate = new class implements Stringable
        {
            public function __toString(): string
            {
                return '2023-11-14 22:13:20';
            }
        };

        $inputs = [
            null,
            '',
            '0',
            '   ',
            '2023-11-14',
            '2023-11-14 22:13:20',
            '2024-02-29 00:00:00',
            '2024-02-29T15:30:45.123456Z',
            '2024-02-29T15:30:45+00:00',
            '2025-02-29',
            'invalid-date-string',
            "2023-11-14\0extra",
            Carbon::parse('2025-06-15 10:20:30'),
            $stringableDate,
        ];

        foreach (['UTC', 'Europe/Berlin'] as $tz) {
            date_default_timezone_set($tz);

            foreach ($inputs as $idx => $rd) {
                $expected = SecondaryIndexDocuments::releasedateToTimestampPhp($rd);
                $this->assertSame(
                    $expected,
                    ConsoleInfoObserver::releasedateToTimestampPhp($rd),
                    "ConsoleInfoObserver PHP releasedate mismatch at #{$idx} in {$tz}"
                );
                $this->assertSame(
                    $expected,
                    ConsoleInfoObserver::releasedateToTimestampNative($rd),
                    "ConsoleInfoObserver Native releasedate mismatch at #{$idx} in {$tz}"
                );
                $this->assertSame(
                    $expected,
                    GamesInfoObserver::releasedateToTimestampPhp($rd),
                    "GamesInfoObserver PHP releasedate mismatch at #{$idx} in {$tz}"
                );
                $this->assertSame(
                    $expected,
                    GamesInfoObserver::releasedateToTimestampNative($rd),
                    "GamesInfoObserver Native releasedate mismatch at #{$idx} in {$tz}"
                );

                foreach ([true, false, null] as $toggle) {
                    ConsoleInfoObserver::setUseRustFfi($toggle);
                    GamesInfoObserver::setUseRustFfi($toggle);
                    $this->assertSame($expected, ConsoleInfoObserver::releasedateToTimestamp($rd));
                    $this->assertSame($expected, GamesInfoObserver::releasedateToTimestamp($rd));
                }
            }
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function consoleAndGamesProvider(): array
    {
        return [
            'populated console/game record with date' => [[
                'id' => 501,
                'title' => 'Cyberpunk 2077',
                'platform' => 'PlayStation 5',
                'genres_id' => 7,
                'releasedate' => '2023-11-14',
                'cover' => 1,
            ]],
            'datetime with fractional seconds and string ints' => [[
                'id' => '502',
                'title' => 'Elden Ring: Shadow of the Erdtree',
                'platform' => 'PC',
                'genres_id' => '12',
                'releasedate' => '2024-02-29T15:30:45.123456Z',
                'cover' => true,
            ]],
            'sparse record with null releasedate' => [[
                'id' => 503,
                'title' => 'Untitled Game',
                'platform' => null,
                'genres_id' => null,
                'releasedate' => null,
                'cover' => false,
            ]],
            'invalid releasedate falls back cleanly' => [[
                'id' => 504,
                'title' => 'Mystery Title',
                'platform' => 'Switch',
                'genres_id' => 3,
                'releasedate' => 'not-a-valid-date',
                'cover' => 0,
            ]],
            'empty attributes' => [[]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('consoleAndGamesProvider')]
    public function test_console_and_games_observers_search_document_and_sync_parity(array $attributes): void
    {
        foreach (['UTC', 'Europe/Berlin'] as $tz) {
            date_default_timezone_set($tz);

            $console = new ConsoleInfo;
            $console->setRawAttributes($attributes, true);
            $expectedConsoleDoc = SecondaryIndexDocuments::consoleFromArrayPhp($console->getAttributes());
            $this->assertSame($expectedConsoleDoc, ConsoleInfoObserver::buildSearchDocumentPhp($console));
            $this->assertSame($expectedConsoleDoc, ConsoleInfoObserver::buildSearchDocumentNative($console));
            $this->assertSame(
                ConsoleInfoObserver::normalizeSearchDocumentPhp($attributes),
                ConsoleInfoObserver::normalizeSearchDocumentNative($attributes),
            );

            $game = new GamesInfo;
            $game->setRawAttributes($attributes, true);
            $expectedGameDoc = SecondaryIndexDocuments::gamesFromArrayPhp($game->getAttributes());
            $this->assertSame($expectedGameDoc, GamesInfoObserver::buildSearchDocumentPhp($game));
            $this->assertSame($expectedGameDoc, GamesInfoObserver::buildSearchDocumentNative($game));
            $this->assertSame(
                GamesInfoObserver::normalizeSearchDocumentPhp($attributes),
                GamesInfoObserver::normalizeSearchDocumentNative($attributes),
            );
        }

        date_default_timezone_set('UTC');
        $console = new ConsoleInfo;
        $console->setRawAttributes($attributes, true);
        $expectedConsoleDoc = ConsoleInfoObserver::buildSearchDocumentPhp($console);
        $expectedConsoleId = ConsoleInfoObserver::resolveDocumentIdPhp($console);

        $game = new GamesInfo;
        $game->setRawAttributes($attributes, true);
        $expectedGameDoc = GamesInfoObserver::buildSearchDocumentPhp($game);
        $expectedGameId = GamesInfoObserver::resolveDocumentIdPhp($game);

        foreach ([false, true] as $useRust) {
            ConsoleInfoObserver::setUseRustFfi($useRust);
            GamesInfoObserver::setUseRustFfi($useRust);

            Search::shouldReceive('insertSecondary')
                ->once()
                ->with(SecondarySearchIndex::Console, $expectedConsoleId, $expectedConsoleDoc);
            (new ConsoleInfoObserver)->created($console);

            Search::shouldReceive('deleteSecondary')
                ->once()
                ->with(SecondarySearchIndex::Console, $expectedConsoleId);
            (new ConsoleInfoObserver)->deleted($console);

            Search::shouldReceive('insertSecondary')
                ->once()
                ->with(SecondarySearchIndex::Games, $expectedGameId, $expectedGameDoc);
            (new GamesInfoObserver)->created($game);

            Search::shouldReceive('deleteSecondary')
                ->once()
                ->with(SecondarySearchIndex::Games, $expectedGameId);
            (new GamesInfoObserver)->deleted($game);
        }
    }

    // ---------------------------------------------------------------------
    // 5. MusicInfoObserver
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function musicInfoProvider(): array
    {
        return [
            'populated music record' => [[
                'id' => 701,
                'title' => 'Discovery',
                'artist' => 'Daft Punk',
                'year' => '2001',
                'genres_id' => 5,
                'cover' => 1,
            ]],
            'integer year and boolean cover' => [[
                'id' => '702',
                'title' => 'Random Access Memories',
                'artist' => 'Daft Punk',
                'year' => 2013,
                'genres_id' => '5',
                'cover' => true,
            ]],
            'sparse music record with nulls' => [[
                'id' => 703,
                'title' => null,
                'artist' => 'Unknown Artist',
                'year' => null,
                'genres_id' => null,
                'cover' => null,
            ]],
            'empty attributes' => [[]],
            'utf8 artist and title' => [[
                'id' => 704,
                'title' => 'Ágætis byrjun',
                'artist' => 'Sigur Rós',
                'year' => '1999',
                'genres_id' => 9,
                'cover' => 0,
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('musicInfoProvider')]
    public function test_music_info_observer_search_document_and_sync_parity(array $attributes): void
    {
        $music = new MusicInfo;
        $music->setRawAttributes($attributes, true);

        $expectedDoc = SecondaryIndexDocuments::music($music);
        $this->assertSame($expectedDoc, MusicInfoObserver::buildSearchDocumentPhp($music));
        $this->assertSame($expectedDoc, MusicInfoObserver::buildSearchDocumentNative($music));
        $this->assertSame(
            MusicInfoObserver::normalizeSearchDocumentPhp($attributes),
            MusicInfoObserver::normalizeSearchDocumentNative($attributes),
        );

        $expectedId = MusicInfoObserver::resolveDocumentIdPhp($music);
        $this->assertSame($expectedId, MusicInfoObserver::resolveDocumentIdNative($music));

        foreach ([false, true] as $useRust) {
            MusicInfoObserver::setUseRustFfi($useRust);
            $this->assertSame($expectedDoc, MusicInfoObserver::buildSearchDocument($music));
            $this->assertSame($expectedId, MusicInfoObserver::resolveDocumentId($music));

            Search::shouldReceive('insertSecondary')
                ->once()
                ->with(SecondarySearchIndex::Music, $expectedId, $expectedDoc);
            (new MusicInfoObserver)->created($music);

            Search::shouldReceive('deleteSecondary')
                ->once()
                ->with(SecondarySearchIndex::Music, $expectedId);
            (new MusicInfoObserver)->deleted($music);
        }
    }

    public function test_document_id_resolution_edge_cases_across_observers(): void
    {
        $cases = [
            [],
            ['id' => null],
            ['id' => 0],
            ['id' => 42],
            ['id' => -17],
            ['id' => true],
            ['id' => false],
            ['id' => '99'],
            ['id' => '  -123  '],
            ['id' => ''],
            ['id' => 'not-an-int'],
            ['id' => '12.5'],
            ['id' => 19.75],
            ['id' => "42\0extra"],
        ];

        foreach ([BookInfoObserver::class, ConsoleInfoObserver::class, GamesInfoObserver::class, MusicInfoObserver::class] as $class) {
            foreach ($cases as $idx => $attrs) {
                $expected = $class::resolveDocumentIdFromAttributesPhp($attrs);
                $this->assertSame(
                    $expected,
                    $class::resolveDocumentIdFromAttributesNative($attrs),
                    "{$class} resolveDocumentIdFromAttributes mismatch at case #{$idx}"
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // C-ABI Contract & Fallback Verification
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_c_abi_null_unaligned_overflow_and_buffer_contracts(): void
    {
        $combinedCdef = implode("\n", [
            AnidbTitleObserver::FFI_CDEF,
            BookInfoObserver::FFI_CDEF,
            ConsoleInfoObserver::FFI_CDEF,
            GamesInfoObserver::FFI_CDEF,
            MusicInfoObserver::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('metadata', $combinedCdef);
        $this->assertNotNull($ffi);

        $rawBytes = $ffi->new('uint8_t[32]');
        $unalignedI64 = $ffi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $alignedI64 = $ffi->new('int64_t[1]');
        $alignedI64[0] = 777;

        // 1. AnidbTitleObserver C-ABI checks
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_doc_id', 1, null, 2, 'en', 2, 'T', 1));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_doc_id', 1, 'main', 4, null, 2, 'T', 1));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_doc_id', 1, 'main', 4, 'en', 2, null, 1));

        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }
        $validSer = serialize(['title' => 'Dune']);
        $validSerLen = \strlen($validSer);

        $shortAnidb = self::ffiCall(
            $ffi,
            'nntmux_anidb_title_observer_search_doc',
            $validSer,
            $validSerLen,
            '',
            0,
            $small,
            8,
        );
        $this->assertLessThan(-1, $shortAnidb);
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_search_doc', 'invalid', 7, '', 0, $small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_search_doc', $validSer, $validSerLen, 'invalid', 7, $small, 8));
        $this->assertSame(-1, self::ffiCall($ffi, 'nntmux_anidb_title_observer_search_doc', $validSer, $validSerLen, '', 0, null, 8));

        // 2. Doc ID C-ABI checks across Book, Console, Games, Music
        foreach ([
            'nntmux_book_info_observer_doc_id',
            'nntmux_console_info_observer_doc_id',
            'nntmux_games_info_observer_doc_id',
            'nntmux_music_info_observer_doc_id',
        ] as $docIdSymbol) {
            $alignedI64[0] = 777;
            $this->assertSame(-1, self::ffiCall($ffi, $docIdSymbol, 1, 42, '', 0, $unalignedI64));
            $this->assertSame(-1, self::ffiCall($ffi, $docIdSymbol, 1, 42, '', 0, null));
            $this->assertSame(-1, self::ffiCall($ffi, $docIdSymbol, 99, 42, '', 0, $alignedI64));
            $this->assertSame(777, $alignedI64[0]);
            $this->assertSame(-1, self::ffiCall($ffi, $docIdSymbol, 2, 0, 'not-an-int', 10, $alignedI64));
            $this->assertSame(777, $alignedI64[0]);
        }

        // 3. Releasedate timestamp C-ABI checks across Console & Games
        foreach ([
            'nntmux_console_info_observer_releasedate_ts',
            'nntmux_games_info_observer_releasedate_ts',
        ] as $tsSymbol) {
            $alignedI64[0] = 888;
            $this->assertSame(-1, self::ffiCall($ffi, $tsSymbol, '2023-11-14', 10, 1, 1, $unalignedI64));
            $this->assertSame(-1, self::ffiCall($ffi, $tsSymbol, '2023-11-14', 10, 1, 1, null));
            $this->assertSame(-1, self::ffiCall($ffi, $tsSymbol, '2023-11-14', 10, 2, 1, $alignedI64));
            $this->assertSame(-1, self::ffiCall($ffi, $tsSymbol, '2023-11-14', 10, 1, 0, $alignedI64));
            $this->assertSame(888, $alignedI64[0]);
        }

        // 4. Search doc insufficient capacity & invalid input preservation
        foreach ([
            ['nntmux_book_info_observer_search_doc', [$validSer, $validSerLen, $small, 8], ['invalid', 7, $small, 8]],
            ['nntmux_console_info_observer_search_doc', [$validSer, $validSerLen, 1, $small, 8], ['invalid', 7, 1, $small, 8]],
            ['nntmux_games_info_observer_search_doc', [$validSer, $validSerLen, 1, $small, 8], ['invalid', 7, 1, $small, 8]],
            ['nntmux_music_info_observer_search_doc', [$validSer, $validSerLen, $small, 8], ['invalid', 7, $small, 8]],
        ] as [$symbol, $shortArgs, $invalidArgs]) {
            $res = self::ffiCall($ffi, $symbol, ...$shortArgs);
            $this->assertLessThan(-1, $res, "{$symbol} must report required negative capacity");
            $this->assertSame('ZZZZZZZZ', FFI::string($small, 8), "{$symbol} must not write partial output");
            $this->assertSame(-1, self::ffiCall($ffi, $symbol, ...$invalidArgs));
        }
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $anidbTitle = new AnidbTitle;
        $anidbTitle->setRawAttributes(['anidbid' => 23, 'type' => 'official', 'lang' => 'en', 'title' => 'Cowboy Bebop'], true);
        $anidbInfo = new AnidbInfo;
        $anidbInfo->setRawAttributes(['anilist_id' => 1, 'mal_id' => 1, 'media_type' => 'ANIME', 'status' => 'FINISHED'], true);

        $this->assertSame(
            AnidbTitleObserver::resolveDocumentIdPhp($anidbTitle),
            AnidbTitleObserver::resolveDocumentIdNative($anidbTitle),
        );
        $this->assertSame(
            AnidbTitleObserver::buildSearchDocumentPhp($anidbTitle, $anidbInfo, false),
            AnidbTitleObserver::buildSearchDocumentNative($anidbTitle, $anidbInfo, false),
        );

        $book = new BookInfo;
        $book->setRawAttributes(['id' => 10, 'title' => 'Dune', 'author' => 'Frank Herbert', 'publishdate' => '1965-08-01', 'cover' => 1], true);
        $this->assertSame(BookInfoObserver::resolveDocumentIdPhp($book), BookInfoObserver::resolveDocumentIdNative($book));
        $this->assertSame(BookInfoObserver::buildSearchDocumentPhp($book), BookInfoObserver::buildSearchDocumentNative($book));

        $console = new ConsoleInfo;
        $console->setRawAttributes(['id' => 20, 'title' => 'Halo', 'platform' => 'Xbox', 'genres_id' => 3, 'releasedate' => '2023-11-14', 'cover' => 1], true);
        $this->assertSame(ConsoleInfoObserver::resolveDocumentIdPhp($console), ConsoleInfoObserver::resolveDocumentIdNative($console));
        $this->assertSame(ConsoleInfoObserver::buildSearchDocumentPhp($console), ConsoleInfoObserver::buildSearchDocumentNative($console));

        $game = new GamesInfo;
        $game->setRawAttributes(['id' => 30, 'title' => 'Doom', 'genres_id' => 2, 'releasedate' => '2024-02-29', 'cover' => 1], true);
        $this->assertSame(GamesInfoObserver::resolveDocumentIdPhp($game), GamesInfoObserver::resolveDocumentIdNative($game));
        $this->assertSame(GamesInfoObserver::buildSearchDocumentPhp($game), GamesInfoObserver::buildSearchDocumentNative($game));

        $music = new MusicInfo;
        $music->setRawAttributes(['id' => 40, 'title' => 'Discovery', 'artist' => 'Daft Punk', 'year' => '2001', 'genres_id' => 5, 'cover' => 1], true);
        $this->assertSame(MusicInfoObserver::resolveDocumentIdPhp($music), MusicInfoObserver::resolveDocumentIdNative($music));
        $this->assertSame(MusicInfoObserver::buildSearchDocumentPhp($music), MusicInfoObserver::buildSearchDocumentNative($music));
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_SECONDARY_INDEX_OBSERVERS_METADATA_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_SECONDARY_INDEX_OBSERVERS_METADATA_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $anidbTitleCommon = new AnidbTitle;
        $anidbTitleCommon->setRawAttributes([
            'anidbid' => 23,
            'type' => 'official',
            'lang' => 'en',
            'title' => 'Cowboy Bebop',
        ], true);
        $anidbInfoCommon = new AnidbInfo;
        $anidbInfoCommon->setRawAttributes([
            'anidbid' => 23,
            'anilist_id' => 1,
            'mal_id' => 1,
            'media_type' => 'ANIME',
            'status' => 'FINISHED',
        ], true);

        $anidbTitleUncommon = new AnidbTitle;
        $anidbTitleUncommon->setRawAttributes([
            'anidbid' => 987654,
            'type' => 'synonym',
            'lang' => 'x-jat',
            'title' => str_repeat('Long Anime Alternative Title Segment ', 6),
        ], true);
        $anidbInfoUncommon = new AnidbInfo;
        $anidbInfoUncommon->setRawAttributes([
            'anidbid' => 987654,
            'anilist_id' => 145678,
            'mal_id' => 543210,
            'media_type' => 'ANIME',
            'status' => 'RELEASING',
        ], true);

        $bookCommon = new BookInfo;
        $bookCommon->setRawAttributes([
            'id' => 101,
            'title' => 'Dune',
            'author' => 'Frank Herbert',
            'publishdate' => '1965-08-01',
            'cover' => 1,
        ], true);
        $bookUncommon = new BookInfo;
        $bookUncommon->setRawAttributes([
            'id' => 202,
            'title' => str_repeat('Comprehensive Encyclopedia Volume ', 6),
            'author' => str_repeat('Multiple Contributing Authors ', 4),
            'publishdate' => '2024-11-15',
            'cover' => 0,
        ], true);

        $consoleCommon = new ConsoleInfo;
        $consoleCommon->setRawAttributes([
            'id' => 301,
            'title' => 'The Legend of Zelda: Tears of the Kingdom',
            'platform' => 'Nintendo Switch',
            'genres_id' => 4,
            'releasedate' => '2023-05-12',
            'cover' => 1,
        ], true);
        $consoleUncommon = new ConsoleInfo;
        $consoleUncommon->setRawAttributes([
            'id' => 302,
            'title' => str_repeat('Collector Edition Console Bundle ', 6),
            'platform' => 'PlayStation 5 Pro',
            'genres_id' => 11,
            'releasedate' => '2024-02-29T15:30:45.123456Z',
            'cover' => 1,
        ], true);

        $gamesCommon = new GamesInfo;
        $gamesCommon->setRawAttributes([
            'id' => 401,
            'title' => 'Baldur\'s Gate 3',
            'genres_id' => 7,
            'releasedate' => '2023-08-03',
            'cover' => 1,
        ], true);
        $gamesUncommon = new GamesInfo;
        $gamesUncommon->setRawAttributes([
            'id' => 402,
            'title' => str_repeat('Deluxe Remastered Anthology Edition ', 6),
            'genres_id' => 15,
            'releasedate' => '2024-02-29T15:30:45.123456Z',
            'cover' => 0,
        ], true);

        $musicCommon = new MusicInfo;
        $musicCommon->setRawAttributes([
            'id' => 501,
            'title' => 'Discovery',
            'artist' => 'Daft Punk',
            'year' => '2001',
            'genres_id' => 5,
            'cover' => 1,
        ], true);
        $musicUncommon = new MusicInfo;
        $musicUncommon->setRawAttributes([
            'id' => 502,
            'title' => str_repeat('Symphonic Live Recording Complete Box ', 5),
            'artist' => str_repeat('Philharmonic Orchestra Ensemble ', 4),
            'year' => '2024',
            'genres_id' => 18,
            'cover' => 1,
        ], true);

        $workloads = [
            'AnidbTitleObserver::resolveDocumentId' => [
                AnidbTitleObserver::class,
                static fn () => AnidbTitleObserver::resolveDocumentId($anidbTitleCommon),
                static fn () => AnidbTitleObserver::resolveDocumentId($anidbTitleUncommon),
            ],
            'AnidbTitleObserver::buildSearchDocument' => [
                AnidbTitleObserver::class,
                static fn () => AnidbTitleObserver::buildSearchDocument($anidbTitleCommon, $anidbInfoCommon, false),
                static fn () => AnidbTitleObserver::buildSearchDocument($anidbTitleUncommon, $anidbInfoUncommon, false),
            ],
            'BookInfoObserver::resolveDocumentId' => [
                BookInfoObserver::class,
                static fn () => BookInfoObserver::resolveDocumentId($bookCommon),
                static fn () => BookInfoObserver::resolveDocumentIdFromAttributes(['id' => '202']),
            ],
            'BookInfoObserver::buildSearchDocument' => [
                BookInfoObserver::class,
                static fn () => BookInfoObserver::buildSearchDocument($bookCommon),
                static fn () => BookInfoObserver::buildSearchDocument($bookUncommon),
            ],
            'ConsoleInfoObserver::resolveDocumentId' => [
                ConsoleInfoObserver::class,
                static fn () => ConsoleInfoObserver::resolveDocumentId($consoleCommon),
                static fn () => ConsoleInfoObserver::resolveDocumentIdFromAttributes(['id' => '302']),
            ],
            'ConsoleInfoObserver::releasedateToTimestamp' => [
                ConsoleInfoObserver::class,
                static fn () => ConsoleInfoObserver::releasedateToTimestamp('2023-05-12'),
                static fn () => ConsoleInfoObserver::releasedateToTimestamp('2024-02-29T15:30:45.123456Z'),
            ],
            'ConsoleInfoObserver::buildSearchDocument' => [
                ConsoleInfoObserver::class,
                static fn () => ConsoleInfoObserver::buildSearchDocument($consoleCommon),
                static fn () => ConsoleInfoObserver::buildSearchDocument($consoleUncommon),
            ],
            'GamesInfoObserver::resolveDocumentId' => [
                GamesInfoObserver::class,
                static fn () => GamesInfoObserver::resolveDocumentId($gamesCommon),
                static fn () => GamesInfoObserver::resolveDocumentIdFromAttributes(['id' => '402']),
            ],
            'GamesInfoObserver::releasedateToTimestamp' => [
                GamesInfoObserver::class,
                static fn () => GamesInfoObserver::releasedateToTimestamp('2023-08-03'),
                static fn () => GamesInfoObserver::releasedateToTimestamp('2024-02-29T15:30:45.123456Z'),
            ],
            'GamesInfoObserver::buildSearchDocument' => [
                GamesInfoObserver::class,
                static fn () => GamesInfoObserver::buildSearchDocument($gamesCommon),
                static fn () => GamesInfoObserver::buildSearchDocument($gamesUncommon),
            ],
            'MusicInfoObserver::resolveDocumentId' => [
                MusicInfoObserver::class,
                static fn () => MusicInfoObserver::resolveDocumentId($musicCommon),
                static fn () => MusicInfoObserver::resolveDocumentIdFromAttributes(['id' => '502']),
            ],
            'MusicInfoObserver::buildSearchDocument' => [
                MusicInfoObserver::class,
                static fn () => MusicInfoObserver::buildSearchDocument($musicCommon),
                static fn () => MusicInfoObserver::buildSearchDocument($musicUncommon),
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::enableRustFfi();
                    $rustOut = self::invoke($fn);
                    $class::disableRustFfi();
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 2205 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::resetRustFfi();
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-42s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
                $qualified ? 'Yes' : 'No',
                $qualified ? 'Rust (true)' : 'PHP (false)',
            );
            if ($class::usesRustFfi()) {
                $this->assertTrue($qualified, "{$name} defaults to Rust but misses the 1.5x gate");
            }
        }

        fwrite(STDERR, implode(PHP_EOL, $rows).PHP_EOL);
    }

    private static function invoke(Closure $fn): mixed
    {
        return $fn();
    }

    /**
     * @param  class-string  $class
     * @return array{rust_mean_us: float, php_mean_us: float, rust_p99_us: float, php_p99_us: float, mean_speedup: float, p99_speedup: float, samples: int, inner_loops: int}
     */
    private function runPairedBenchmark(string $class, Closure $fn, int $innerLoops, int $samples, int $seed): array
    {
        foreach ([true, false] as $toggle) {
            $class::setUseRustFfi($toggle);
            for ($w = 0; $w < 50; $w++) {
                $fn();
            }
        }

        $times = [1 => [], 0 => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[(int) $toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[1];
        $php = $times[0];
        sort($rust);
        sort($php);
        $p99 = (int) floor((\count($rust) - 1) * 0.99);
        $rustMean = array_sum($rust) / \count($rust);
        $phpMean = array_sum($php) / \count($php);

        return [
            'rust_mean_us' => $rustMean,
            'php_mean_us' => $phpMean,
            'rust_p99_us' => $rust[$p99],
            'php_p99_us' => $php[$p99],
            'mean_speedup' => $phpMean / $rustMean,
            'p99_speedup' => $php[$p99] / $rust[$p99],
            'samples' => $samples,
            'inner_loops' => $innerLoops,
        ];
    }
}
