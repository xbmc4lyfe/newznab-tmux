<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\IGDB\DataNode;
use App\Services\IGDB\Models\BaseModel;
use App\Services\IGDB\Models\Company;
use App\Services\IGDB\Models\Game;
use App\Services\IGDB\Models\Platform;
use App\Services\TvProcessing\ParsedTvRelease;
use App\Services\TvProcessing\Pipes\LocalDbPipe;
use App\Services\TvProcessing\Pipes\ParseInfoPipe;
use App\Services\TvProcessing\TvPosterResolver;
use App\Services\TvProcessing\TvProcessingPassable;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvProcessingRunState;
use App\Services\TvProcessing\TvReleaseContext;
use App\Support\Data\BookParseResult;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 17 Worker 5:
 * TvPosterResolver, ParseInfoPipe, LocalDbPipe, IGDB\Models\BaseModel, and BookParseResult.
 *
 * Benchmarks run only with NNTMUX_TV_IGDB_STEAM_METADATA_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_TV_IGDB_STEAM_METADATA_BENCH=1 php artisan test --compact tests/Unit/RustTvIgdbSteamMetadataParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                      | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |---------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | TvPosterResolver::extractShowMetadata       | 0.14x / 0.15x       | 0.18x / 0.18x         | No      | PHP (false)  |
 * | TvPosterResolver::selectPosterUrl           | 0.18x / 0.18x       | 0.29x / 0.27x         | No      | PHP (false)  |
 * | ParseInfoPipe::isValidParseInfo             | 0.30x / 0.27x       | 0.18x / 0.19x         | No      | PHP (false)  |
 * | ParseInfoPipe::formatParseFailedMessage     | 0.40x / 0.35x       | 0.58x / 0.60x         | No      | PHP (false)  |
 * | LocalDbPipe::shouldLookupEpisode            | 0.37x / 0.39x       | 0.51x / 0.51x         | No      | PHP (false)  |
 * | LocalDbPipe::classifyOutcome                | 0.39x / 0.44x       | 0.23x / 0.17x         | No      | PHP (false)  |
 * | IGDB\Models\BaseModel::normalizeEndpoint    | 0.87x / 0.53x       | 1.83x / 1.60x         | No      | PHP (false)  |
 * | IGDB\Models\BaseModel::extractIdentifiers   | 0.50x / 0.48x       | 0.43x / 0.40x         | No      | PHP (false)  |
 * | BookParseResult::fromArray                  | 0.23x / 0.24x       | 0.30x / 0.29x         | No      | PHP (false)  |
 * | BookParseResult::searchQueryAndCompleteness | 0.12x / 0.12x       | 0.08x / 0.09x         | No      | PHP (false)  |
 */
class RustTvIgdbSteamMetadataParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASS_TOGGLED = [
        TvPosterResolver::class,
        ParseInfoPipe::class,
        LocalDbPipe::class,
        BaseModel::class,
        BookParseResult::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', TvPosterResolver::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::CLASS_TOGGLED as $class) {
            $class::setUseRustFfi(null);
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, null);
        }
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::CLASS_TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP until its Rust path passes the 1.5x gate");
        }
    }

    // ---------------------------------------------------------------------
    // 1. TvPosterResolver
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $show
     */
    #[DataProvider('tvPosterShowProvider')]
    public function test_tv_poster_resolver_extract_and_select_parity(array $show): void
    {
        $expectedMeta = TvPosterResolver::extractShowMetadataPhp($show);
        $nativeMeta = TvPosterResolver::extractShowMetadataNative($show);
        $this->assertSame($expectedMeta, $nativeMeta);

        foreach ([true, false, null] as $toggle) {
            TvPosterResolver::setUseRustFfi($toggle);
            $this->assertSame($expectedMeta, TvPosterResolver::extractShowMetadata($show));
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function tvPosterShowProvider(): array
    {
        return [
            'empty show' => [[]],
            'direct poster' => [[
                'title' => 'Severance',
                'poster' => 'https://artworks.thetvdb.com/banners/v4/series/371980/posters/1.jpg',
                'tmdb' => 95396,
                'tvdb' => 371980,
                'tvmaze' => 44933,
            ]],
            'image string fallback when poster empty' => [[
                'poster' => '',
                'image' => 'https://static.tvmaze.com/uploads/images/original_untouched/1/2.jpg',
                'thetvdb' => '371980',
                'tmdb' => '95396',
                'tvmaze' => '44933',
            ]],
            'image array original and medium' => [[
                'image' => [
                    'original' => 'https://static.tvmaze.com/orig.jpg',
                    'medium' => 'https://static.tvmaze.com/med.jpg',
                ],
                'tvdb' => 0,
                'thetvdb' => 280619,
            ]],
            'image array medium only' => [[
                'image' => [
                    'original' => '',
                    'medium' => 'https://static.tvmaze.com/med.jpg',
                ],
                'tvdb' => 280619,
            ]],
            'nul in poster fallback' => [[
                'poster' => "https://bad.example/poster\0.jpg",
                'tmdb' => 100,
                'tvdb' => 200,
            ]],
            'object fallback' => [[
                'poster' => '',
                'image' => (object) ['original' => 'https://obj.example/orig.jpg'],
                'tmdb' => 55,
            ]],
        ];
    }

    public function test_tv_poster_resolver_select_url_and_resolve_parity(): void
    {
        $urlCandidates = ['', 'https://provider.test/p.jpg', 'https://tmdb.test/t.jpg', 'https://fanart.test/f.jpg'];
        foreach ($urlCandidates as $p1) {
            foreach ($urlCandidates as $p2) {
                foreach ($urlCandidates as $p3) {
                    $expected = TvPosterResolver::selectPosterUrlPhp($p1, $p2, $p3);
                    $this->assertSame($expected, TvPosterResolver::selectPosterUrlNative($p1, $p2, $p3));
                    foreach ([true, false, null] as $toggle) {
                        TvPosterResolver::setUseRustFfi($toggle);
                        $this->assertSame($expected, TvPosterResolver::selectPosterUrl($p1, $p2, $p3));
                    }
                }
            }
        }

        config(['tmdb.api_key' => '', 'nntmux_api.fanarttv_api_key' => '']);
        $resolver = new TvPosterResolver;
        foreach ([false, true] as $toggle) {
            TvPosterResolver::setUseRustFfi($toggle);
            $this->assertSame(
                'https://provider.test/direct.jpg',
                $resolver->resolve(['poster' => 'https://provider.test/direct.jpg', 'tmdb' => 1, 'tvdb' => 2]),
            );
            $this->assertSame(
                'https://tvmaze.test/image.jpg',
                $resolver->resolve(['poster' => '', 'image' => 'https://tvmaze.test/image.jpg']),
            );
            $this->assertSame('', $resolver->resolve(['poster' => '', 'tmdb' => 1, 'tvdb' => 2]));
        }
    }

    // ---------------------------------------------------------------------
    // 2. ParseInfoPipe
    // ---------------------------------------------------------------------

    public function test_parse_info_pipe_validation_formatting_and_transition_parity(): void
    {
        $parseCases = [
            'false' => false,
            'empty array' => [],
            'valid standard' => ['name' => 'Severance', 'season' => 1, 'episode' => 2],
            'valid season zero' => ['name' => 'Doctor Who', 'season' => 0, 'episode' => 'all'],
            'empty name string' => ['name' => '', 'season' => 1, 'episode' => 1],
            'zero name string' => ['name' => '0', 'season' => 1, 'episode' => 1],
            'missing season' => ['name' => 'Severance', 'episode' => 1],
            'null episode' => ['name' => 'Severance', 'season' => 1, 'episode' => null],
            'episode list' => ['name' => 'Severance', 'season' => '1', 'episode' => [1, 2]],
        ];

        foreach ($parseCases as $label => $info) {
            $expected = ParseInfoPipe::isValidParseInfoPhp($info);
            $this->assertSame($expected, ParseInfoPipe::isValidParseInfoNative($info), "validate {$label}");
            foreach ([true, false, null] as $toggle) {
                ParseInfoPipe::setUseRustFfi($toggle);
                $this->assertSame($expected, ParseInfoPipe::isValidParseInfo($info));
            }
        }

        $parsed = new ParsedTvRelease('Severance', 'severance', '', 1, 2, false, null, null);
        foreach ([null, $parsed] as $candidate) {
            $expectedAdv = ParseInfoPipe::shouldAdvancePhp($candidate);
            $this->assertSame($expectedAdv, ParseInfoPipe::shouldAdvanceNative($candidate));
            foreach ([true, false, null] as $toggle) {
                ParseInfoPipe::setUseRustFfi($toggle);
                $this->assertSame($expectedAdv, ParseInfoPipe::shouldAdvance($candidate));
            }
        }

        $names = [
            '',
            'a3f8e2c19b7d4e6f8a1b2c3d4e5f6a7b',
            str_repeat('Very.Long.Unparseable.Release.Name.', 3),
            '日本語のリリース名・テスト・エピソード・サンプル・長い名前・追加文字列・検証用テキスト',
        ];
        foreach ($names as $name) {
            foreach ([0, 10, 50, 100] as $maxLen) {
                $expectedMsg = ParseInfoPipe::formatParseFailedMessagePhp($name, $maxLen);
                $this->assertSame($expectedMsg, ParseInfoPipe::formatParseFailedMessageNative($name, $maxLen));
                foreach ([true, false, null] as $toggle) {
                    ParseInfoPipe::setUseRustFfi($toggle);
                    $this->assertSame($expectedMsg, ParseInfoPipe::formatParseFailedMessage($name, $maxLen));
                }
            }
        }

        $context = new TvReleaseContext(10, 'BadName', 1, 5040);
        foreach ([false, true] as $toggle) {
            ParseInfoPipe::setUseRustFfi($toggle);
            $passableFail = new TvProcessingPassable($context, new TvProcessingRunState, true);
            $this->assertFalse(ParseInfoPipe::applyParseTransition($passableFail, null));
            $this->assertTrue($passableFail->parseFailed);
            $this->assertSame(TvProcessingResult::STATUS_PARSE_FAILED, $passableFail->providerResults['ParseInfo']['status']);

            $passableOk = new TvProcessingPassable($context, new TvProcessingRunState, true);
            $this->assertTrue(ParseInfoPipe::applyParseTransition($passableOk, $parsed));
            $this->assertFalse($passableOk->parseFailed);
            $this->assertSame($parsed, $passableOk->parsed);
        }
    }

    // ---------------------------------------------------------------------
    // 3. LocalDbPipe
    // ---------------------------------------------------------------------

    public function test_local_db_pipe_decisions_and_outcome_parity(): void
    {
        $releases = [
            new ParsedTvRelease('Severance', 'Severance', '', 1, 2, false, null, null),
            new ParsedTvRelease('Doctor Who', 'Doctor Who', '', 0, 5, false, null, null),
            new ParsedTvRelease('The Daily Show', 'The Daily Show', '', 0, 0, false, '2024-03-05', null),
            new ParsedTvRelease('The Expanse', 'The Expanse', '', 1, null, true, null, null),
            new ParsedTvRelease('Unknown', 'Unknown', '', null, null, false, null, null),
            new ParsedTvRelease('Negative', 'Negative', '', -1, 1, false, null, null),
        ];

        foreach ($releases as $idx => $rel) {
            $expected = LocalDbPipe::shouldLookupEpisodePhp($rel);
            $this->assertSame($expected, LocalDbPipe::shouldLookupEpisodeNative($rel), "rel {$idx}");
            foreach ([true, false, null] as $toggle) {
                LocalDbPipe::setUseRustFfi($toggle);
                $this->assertSame($expected, LocalDbPipe::shouldLookupEpisode($rel));
            }
        }

        $siteIds = [null, false, true, -10, 0, 1, 42, '', '0', '-5', '15', ' 99 ', 'abc', '12.5'];
        foreach ($siteIds as $idx => $siteId) {
            $expectedSite = LocalDbPipe::hasValidSiteIdPhp($siteId);
            $this->assertSame($expectedSite, LocalDbPipe::hasValidSiteIdNative($siteId), "siteId {$idx}");
            foreach ([true, false, null] as $toggle) {
                LocalDbPipe::setUseRustFfi($toggle);
                $this->assertSame($expectedSite, LocalDbPipe::hasValidSiteId($siteId));
            }
        }

        foreach ([-1, 0, 15] as $videoId) {
            foreach ([false, true] as $seasonPack) {
                foreach ([-11, 0, 88] as $episodeId) {
                    $expectedCode = LocalDbPipe::classifyOutcomePhp($videoId, $seasonPack, $episodeId);
                    $this->assertSame($expectedCode, LocalDbPipe::classifyOutcomeNative($videoId, $seasonPack, $episodeId));

                    $expectedRes = LocalDbPipe::buildOutcomeResultPhp($videoId, $seasonPack, $episodeId, 'Severance');
                    $nativeRes = LocalDbPipe::buildOutcomeResultNative($videoId, $seasonPack, $episodeId, 'Severance');
                    $this->assertEquals($expectedRes, $nativeRes);

                    foreach ([true, false, null] as $toggle) {
                        LocalDbPipe::setUseRustFfi($toggle);
                        $this->assertSame($expectedCode, LocalDbPipe::classifyOutcome($videoId, $seasonPack, $episodeId));
                        $this->assertEquals($expectedRes, LocalDbPipe::buildOutcomeResult($videoId, $seasonPack, $episodeId, 'Severance'));
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. IGDB\Models\BaseModel
    // ---------------------------------------------------------------------

    public function test_igdb_base_model_normalization_and_identifier_extraction_parity(): void
    {
        $endpoints = [
            '',
            'games',
            'companies',
            'platforms',
            ' /Games/ ',
            '///External_Games//Sub///',
            " \t/AGE_RATINGS/\n ",
        ];
        foreach ($endpoints as $ep) {
            $expected = BaseModel::normalizeEndpointPhp($ep);
            $this->assertSame($expected, BaseModel::normalizeEndpointNative($ep), "ep: {$ep}");
            foreach ([true, false, null] as $toggle) {
                BaseModel::setUseRustFfi($toggle);
                $this->assertSame($expected, BaseModel::normalizeEndpoint($ep));
            }
        }

        $this->assertSame('games', Game::endpoint());
        $this->assertSame('companies', Company::endpoint());
        $this->assertSame('platforms', Platform::endpoint());

        $keys = [
            '',
            'id',
            'first_release_date',
            'firstReleaseDate',
            'FirstReleaseDate',
            'IGDBId',
            '  release-date.value  ',
            '__already__snake__',
            'version2Number',
        ];
        foreach ($keys as $key) {
            $expectedKey = BaseModel::normalizeAttributeKeyPhp($key);
            $this->assertSame($expectedKey, BaseModel::normalizeAttributeKeyNative($key), "key: {$key}");
            foreach ([true, false, null] as $toggle) {
                BaseModel::setUseRustFfi($toggle);
                $this->assertSame($expectedKey, BaseModel::normalizeAttributeKey($key));
            }
        }

        $idSources = [
            null,
            0,
            -5,
            42,
            '1942',
            ' 77 ',
            '0',
            'not-an-id',
            ['id' => 99, 'name' => 'Halo'],
            ['id' => '123'],
            ['id' => 0],
            ['name' => 'No ID'],
            new Game(['id' => 555, 'name' => 'Doom']),
            new DataNode(['id' => 777]),
            (object) ['id' => 888],
        ];
        foreach ($idSources as $idx => $source) {
            $expectedId = BaseModel::extractIdentifierPhp($source);
            $this->assertSame($expectedId, BaseModel::extractIdentifierNative($source), "id source {$idx}");
            foreach ([true, false, null] as $toggle) {
                BaseModel::setUseRustFfi($toggle);
                $this->assertSame($expectedId, BaseModel::extractIdentifier($source));
            }
        }

        $game = new Game(['id' => 1942, 'name' => 'The Witcher 3']);
        $this->assertSame(1942, $game->getId());

        $idLists = [
            'empty' => [],
            'ints and duplicates' => [6, 14, 6, 0, -1, 48, 14],
            'mixed arrays and scalars' => [
                ['id' => 6, 'name' => 'PC'],
                ['id' => '14', 'name' => 'Mac'],
                6,
                ['id' => 0],
                ['name' => 'Missing'],
                new Platform(['id' => 48, 'name' => 'PS4']),
            ],
            'stdClass fallback' => [
                (object) ['id' => 12],
                ['id' => 34],
            ],
        ];
        foreach ($idLists as $label => $items) {
            $expectedIds = BaseModel::extractIdentifiersPhp($items);
            $this->assertSame($expectedIds, BaseModel::extractIdentifiersNative($items), "ids {$label}");
            foreach ([true, false, null] as $toggle) {
                BaseModel::setUseRustFfi($toggle);
                $this->assertSame($expectedIds, BaseModel::extractIdentifiers($items));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. BookParseResult
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('bookParseResultProvider')]
    public function test_book_parse_result_parity(array $data): void
    {
        $expected = BookParseResult::fromArrayPhp($data);
        $native = BookParseResult::fromArrayNative($data);

        $this->assertSame($expected->toArrayPhp(), $native->toArrayNative());
        $this->assertSame($expected->hasAuthorPhp(), $native->hasAuthorNative());
        $this->assertSame($expected->hasIsbnPhp(), $native->hasIsbnNative());
        $this->assertSame($expected->hasTitleAndAuthorPhp(), $native->hasTitleAndAuthorNative());
        $this->assertSame($expected->isCompletePhp(), $native->isCompleteNative());
        $this->assertSame($expected->completenessScorePhp(), $native->completenessScoreNative());
        $this->assertSame($expected->searchQueryPhp(), $native->searchQueryNative());

        foreach ([true, false, null] as $toggle) {
            BookParseResult::setUseRustFfi($toggle);
            $dispatched = BookParseResult::fromArray($data);
            $this->assertSame($expected->toArrayPhp(), $dispatched->toArray());
            $this->assertSame($expected->hasAuthorPhp(), $dispatched->hasAuthor());
            $this->assertSame($expected->hasIsbnPhp(), $dispatched->hasIsbn());
            $this->assertSame($expected->hasTitleAndAuthorPhp(), $dispatched->hasTitleAndAuthor());
            $this->assertSame($expected->isCompletePhp(), $dispatched->isComplete());
            $this->assertSame($expected->completenessScorePhp(), $dispatched->completenessScore());
            $this->assertSame($expected->searchQueryPhp(), $dispatched->searchQuery());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function bookParseResultProvider(): array
    {
        return [
            'complete book' => [[
                'rawName' => 'Robert C. Martin - Clean Code (2008) EPUB 9780132350884',
                'title' => 'Clean Code',
                'author' => 'Robert C. Martin',
                'isbn' => '9780132350884',
                'year' => 2008,
                'format' => 'EPUB',
                'isJunk' => false,
                'isMagazine' => false,
            ]],
            'snake_case keys and string year' => [[
                'raw_name' => 'Frank Herbert - Dune (1965).mobi',
                'title' => 'Dune',
                'author' => 'Frank Herbert',
                'year' => '1965',
                'format' => 'MOBI',
                'is_junk' => 0,
                'is_magazine' => 1,
            ]],
            'title only' => [[
                'rawName' => 'The Art of Computer Programming',
                'title' => 'The Art of Computer Programming',
            ]],
            'whitespace author and title' => [[
                'rawName' => '   ',
                'title' => '  Domain-Driven Design  ',
                'author' => '   ',
                'isbn' => '',
                'format' => '',
            ]],
            'isbn without author' => [[
                'rawName' => '9780132350884.pdf',
                'title' => 'Clean Code',
                'isbn' => '9780132350884',
            ]],
            'junk release with metadata' => [[
                'rawName' => 'Mega Pack',
                'title' => 'Clean Code',
                'author' => 'Robert C. Martin',
                'isbn' => '9780132350884',
                'isJunk' => true,
            ]],
            'empty defaults' => [[]],
            'nul byte fallback' => [[
                'rawName' => "Raw\0Name",
                'title' => 'Title',
                'author' => 'Author',
            ]],
        ];
    }

    public function test_book_parse_result_object_vars_and_to_array_exclude_static_toggles(): void
    {
        $parsed = new BookParseResult(rawName: 'Raw', title: 'Title', author: 'Author');
        $vars = get_object_vars($parsed);
        $this->assertArrayNotHasKey('useRustFfi', $vars);
        $this->assertArrayNotHasKey('ffi', $vars);
        $this->assertArrayNotHasKey('useRustFfi', $parsed->toArray());
        $this->assertArrayNotHasKey('ffi', $parsed->toArray());
    }

    // ---------------------------------------------------------------------
    // 6. C-ABI contract & Fallback
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_rust_symbols_resolve_and_honor_contract_codes(): void
    {
        $posterFfi = RustPipelineLibrary::resolveDomain('metadata', TvPosterResolver::FFI_CDEF);
        $this->assertNotNull($posterFfi);
        $this->assertSame(-1, self::ffiCall($posterFfi, 'nntmux_tv_poster_extract_show_meta', null, 5, null, null, null, null, 0));
        $this->assertSame(-4, self::ffiCall($posterFfi, 'nntmux_tv_poster_select_url', 'http', 4, '', 0, '', 0, null, 0));

        $parseFfi = RustPipelineLibrary::resolveDomain('metadata', ParseInfoPipe::FFI_CDEF);
        $this->assertNotNull($parseFfi);
        $this->assertSame(-1, self::ffiCall($parseFfi, 'nntmux_tv_parse_pipe_validate_info', null, 4));
        $this->assertSame(1, self::ffiCall($parseFfi, 'nntmux_tv_parse_pipe_should_advance', 1));
        $this->assertSame(-1, self::ffiCall($parseFfi, 'nntmux_tv_parse_pipe_format_error', 'abc', 3, -1, null, 0));
        $this->assertLessThan(-1, self::ffiCall($parseFfi, 'nntmux_tv_parse_pipe_format_error', 'abc', 3, 50, null, 0));

        $localFfi = RustPipelineLibrary::resolveDomain('metadata', LocalDbPipe::FFI_CDEF);
        $this->assertNotNull($localFfi);
        $this->assertSame(1, self::ffiCall($localFfi, 'nntmux_tv_local_pipe_should_lookup_episode', 0, 1, 1, 1, 2, 0));
        $this->assertSame(-1, self::ffiCall($localFfi, 'nntmux_tv_local_pipe_has_valid_site_id', 99, 0, null, 0));
        $this->assertSame(2, self::ffiCall($localFfi, 'nntmux_tv_local_pipe_classify_outcome', 10, 0, 20));

        $igdbFfi = RustPipelineLibrary::resolveDomain('metadata', BaseModel::FFI_CDEF);
        $this->assertNotNull($igdbFfi);
        $this->assertSame(-5, self::ffiCall($igdbFfi, 'nntmux_igdb_model_normalize_endpoint', '/games/', 7, null, 0));
        $this->assertSame(-18, self::ffiCall($igdbFfi, 'nntmux_igdb_model_normalize_key', 'firstReleaseDate', 16, null, 0));
        $this->assertSame(-1, self::ffiCall($igdbFfi, 'nntmux_igdb_model_extract_id', null, 4, 'id', 2, null));
        $this->assertSame(-1, self::ffiCall($igdbFfi, 'nntmux_igdb_model_extract_ids', null, 4, 'id', 2, null, 0));

        $bookFfi = RustPipelineLibrary::resolveDomain('metadata', BookParseResult::FFI_CDEF);
        $this->assertNotNull($bookFfi);
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_parse_result_from_serialized', null, 4, null, null, null, 0));
        $this->assertSame(-1, self::ffiCall(
            $bookFfi,
            'nntmux_book_parse_result_inspect',
            'Title',
            5,
            0,
            '',
            0,
            0,
            '',
            0,
            0,
            0,
            0,
            '',
            0,
            0,
            null,
            null,
            null,
            0,
        ));
    }

    public function test_native_methods_fall_back_to_php_when_library_unavailable(): void
    {
        foreach (self::CLASS_TOGGLED as $class) {
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, false);
            $class::setUseRustFfi(true);
        }

        $meta = TvPosterResolver::extractShowMetadataNative(['poster' => 'https://p.test/1.jpg', 'tmdb' => 10, 'thetvdb' => 20]);
        $this->assertSame('https://p.test/1.jpg', $meta['poster']);
        $this->assertSame(20, $meta['tvdb']);
        $this->assertSame('https://t.test/2.jpg', TvPosterResolver::selectPosterUrlNative('', 'https://t.test/2.jpg'));

        $this->assertTrue(ParseInfoPipe::isValidParseInfoNative(['name' => 'Show', 'season' => 1, 'episode' => 1]));
        $this->assertFalse(ParseInfoPipe::shouldAdvanceNative(null));
        $this->assertSame('  ✗ Parse failed: Bad', ParseInfoPipe::formatParseFailedMessageNative('BadName', 3));

        $parsed = new ParsedTvRelease('Show', 'Show', '', 1, 2, false, null, null);
        $this->assertTrue(LocalDbPipe::shouldLookupEpisodeNative($parsed));
        $this->assertTrue(LocalDbPipe::hasValidSiteIdNative(42));
        $this->assertSame(2, LocalDbPipe::classifyOutcomeNative(10, false, 20));
        $this->assertSame(TvProcessingResult::STATUS_MATCHED, LocalDbPipe::buildOutcomeResultNative(10, false, 20, 'Show')->status);

        $this->assertSame('games', BaseModel::normalizeEndpointNative(' /Games/ '));
        $this->assertSame('first_release_date', BaseModel::normalizeAttributeKeyNative('firstReleaseDate'));
        $this->assertSame(42, BaseModel::extractIdentifierNative(['id' => 42]));
        $this->assertSame([6, 14], BaseModel::extractIdentifiersNative([6, ['id' => 14], 6]));

        $book = BookParseResult::fromArrayNative([
            'rawName' => 'Raw',
            'title' => 'Clean Code',
            'author' => 'Robert C. Martin',
            'isbn' => '9780132350884',
            'year' => 2008,
            'format' => 'EPUB',
        ]);
        $this->assertTrue($book->hasAuthorNative());
        $this->assertTrue($book->hasIsbnNative());
        $this->assertTrue($book->hasTitleAndAuthorNative());
        $this->assertTrue($book->isCompleteNative());
        $this->assertSame(5, $book->completenessScoreNative());
        $this->assertSame('Robert C. Martin Clean Code', $book->searchQueryNative());
    }

    // ---------------------------------------------------------------------
    // 7. Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TV_IGDB_STEAM_METADATA_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_IGDB_STEAM_METADATA_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $commonShow = [
            'title' => 'Severance',
            'poster' => 'https://artworks.thetvdb.com/banners/v4/series/371980/posters/620e7f.jpg',
            'tmdb' => 95396,
            'tvdb' => 371980,
            'tvmaze' => 44933,
        ];
        $uncommonShow = [
            'title' => 'International Show',
            'poster' => '',
            'image' => [
                'original' => 'https://static.tvmaze.com/uploads/images/original_untouched/400/1000234.jpg',
                'medium' => 'https://static.tvmaze.com/uploads/images/medium_portrait/400/1000234.jpg',
            ],
            'tvdb' => 0,
            'thetvdb' => '371980',
            'tmdb' => '95396',
            'tvmaze' => '44933',
        ];

        $parsedEp = new ParsedTvRelease('Severance', 'Severance', '', 1, 2, false, null, null);
        $parsedPack = new ParsedTvRelease('Severance', 'Severance', '', 1, null, true, null, null);
        $parsedAirdate = new ParsedTvRelease('The Daily Show', 'The Daily Show', '', 0, 0, false, '2024-03-05', null);

        $completeBook = new BookParseResult(
            rawName: 'Robert C. Martin - Clean Code (2008) EPUB 9780132350884',
            title: 'Clean Code',
            author: 'Robert C. Martin',
            isbn: '9780132350884',
            year: 2008,
            format: 'EPUB',
        );
        $sparseBook = new BookParseResult(
            rawName: 'Unknown.Book.2024',
            title: '  Unknown Book  ',
            author: '   ',
            isbn: null,
            year: null,
            format: null,
            isJunk: true,
        );

        $workloads = [
            'TvPosterResolver::extractShowMetadata' => [
                TvPosterResolver::class,
                fn () => TvPosterResolver::extractShowMetadata($commonShow),
                fn () => TvPosterResolver::extractShowMetadata($uncommonShow),
            ],
            'TvPosterResolver::selectPosterUrl' => [
                TvPosterResolver::class,
                fn () => [
                    TvPosterResolver::selectPosterUrl('https://provider.test/p.jpg', 'https://tmdb.test/t.jpg'),
                    TvPosterResolver::selectPosterUrl('', 'https://tmdb.test/t.jpg', 'https://fanart.test/f.jpg'),
                ],
                fn () => [
                    TvPosterResolver::selectPosterUrl('', '', 'https://fanart.test/f.jpg'),
                    TvPosterResolver::selectPosterUrl('', '', ''),
                ],
            ],
            'ParseInfoPipe::isValidParseInfo' => [
                ParseInfoPipe::class,
                fn () => [
                    ParseInfoPipe::isValidParseInfo(['name' => 'Severance', 'season' => 1, 'episode' => 2]),
                    ParseInfoPipe::isValidParseInfo(false),
                ],
                fn () => [
                    ParseInfoPipe::isValidParseInfo(['name' => '', 'season' => 1, 'episode' => 2]),
                    ParseInfoPipe::isValidParseInfo(['name' => 'Severance', 'season' => 0, 'episode' => 'all']),
                ],
            ],
            'ParseInfoPipe::formatParseFailedMessage' => [
                ParseInfoPipe::class,
                fn () => ParseInfoPipe::formatParseFailedMessage('a3f8e2c19b7d4e6f8a1b2c3d4e5f6a7b', 50),
                fn () => ParseInfoPipe::formatParseFailedMessage(
                    '日本語のリリース名・テスト・エピソード・サンプル・長い名前・追加文字列・検証用テキスト・さらに長い文字列',
                    50,
                ),
            ],
            'LocalDbPipe::shouldLookupEpisode' => [
                LocalDbPipe::class,
                fn () => [
                    LocalDbPipe::shouldLookupEpisode($parsedEp),
                    LocalDbPipe::shouldLookupEpisode($parsedPack),
                ],
                fn () => [
                    LocalDbPipe::shouldLookupEpisode($parsedAirdate),
                    LocalDbPipe::hasValidSiteId('371980'),
                ],
            ],
            'LocalDbPipe::classifyOutcome' => [
                LocalDbPipe::class,
                fn () => [
                    LocalDbPipe::classifyOutcome(15, false, 42),
                    LocalDbPipe::classifyOutcome(0, false, 0),
                ],
                fn () => [
                    LocalDbPipe::classifyOutcome(15, true, 0),
                    LocalDbPipe::classifyOutcome(15, false, 0),
                ],
            ],
            'IGDB\\Models\\BaseModel::normalizeEndpoint' => [
                BaseModel::class,
                fn () => [
                    BaseModel::normalizeEndpoint('games'),
                    BaseModel::normalizeEndpoint('companies'),
                ],
                fn () => [
                    BaseModel::normalizeEndpoint(' /Games//External_Games/ '),
                    BaseModel::normalizeAttributeKey('firstReleaseDate'),
                ],
            ],
            'IGDB\\Models\\BaseModel::extractIdentifiers' => [
                BaseModel::class,
                fn () => BaseModel::extractIdentifiers([6, 14, 48, 130]),
                fn () => BaseModel::extractIdentifiers([
                    ['id' => 6, 'name' => 'PC'],
                    ['id' => '14', 'name' => 'Mac'],
                    ['id' => 6],
                    ['id' => 48, 'name' => 'PS4'],
                ]),
            ],
            'BookParseResult::fromArray' => [
                BookParseResult::class,
                fn () => BookParseResult::fromArray([
                    'rawName' => 'Robert C. Martin - Clean Code (2008) EPUB',
                    'title' => 'Clean Code',
                    'author' => 'Robert C. Martin',
                    'isbn' => '9780132350884',
                    'year' => 2008,
                    'format' => 'EPUB',
                ])->title,
                fn () => BookParseResult::fromArray([
                    'raw_name' => 'Frank Herbert - Dune (1965).mobi',
                    'title' => 'Dune',
                    'author' => 'Frank Herbert',
                    'year' => '1965',
                    'is_magazine' => 1,
                ])->year,
            ],
            'BookParseResult::searchQueryAndCompleteness' => [
                BookParseResult::class,
                fn () => [
                    $completeBook->searchQuery(),
                    $completeBook->completenessScore(),
                    $completeBook->hasTitleAndAuthor(),
                ],
                fn () => [
                    $sparseBook->searchQuery(),
                    $sparseBook->isComplete(),
                    $sparseBook->hasIsbn(),
                ],
            ],
        ];

        $rows = [];
        foreach ($workloads as $name => [$class, $common, $uncommon]) {
            $worst = ['common_mean' => INF, 'common_p99' => INF, 'uncommon_mean' => INF, 'uncommon_p99' => INF];
            for ($rep = 0; $rep < 3; $rep++) {
                foreach (['common' => $common, 'uncommon' => $uncommon] as $kind => $fn) {
                    $class::setUseRustFfi(true);
                    $rustOut = self::invoke($fn);
                    $class::setUseRustFfi(false);
                    $this->assertSame(self::invoke($fn), $rustOut, "{$name} {$kind} benchmark output parity");

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1705 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-48s | %.2fx / %.2fx     | %.2fx / %.2fx       | %-12s |',
                $name,
                $worst['common_mean'],
                $worst['common_p99'],
                $worst['uncommon_mean'],
                $worst['uncommon_p99'],
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

        $times = [true => [], false => []];
        mt_srand($seed);
        for ($s = 0; $s < $samples; $s++) {
            $order = mt_rand(0, 1) === 0 ? [true, false] : [false, true];
            foreach ($order as $toggle) {
                $class::setUseRustFfi($toggle);
                $t0 = hrtime(true);
                for ($i = 0; $i < $innerLoops; $i++) {
                    $fn();
                }
                $times[$toggle][] = (hrtime(true) - $t0) / 1000.0 / $innerLoops;
            }
        }

        $rust = $times[true];
        $php = $times[false];
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
