<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Api\XML_Response;
use App\Models\Category;
use App\Services\Releases\ReleaseSearchService;
use App\Services\Search\Support\ManticoreIndexRegistry;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Parity, boundary, and benchmark suite for Wave 9 Slice 9.6 Rust FFI exports in `rust/pipeline-ffi/src/search.rs`:
 * - `nntmux_api_build_cdata` (`XML_Response`)
 * - `nntmux_search_normalize_mysql_fallback_phrase` (`ReleaseSearchService`)
 * - `nntmux_search_build_range_sql_conditions` (`ReleaseSearchService`)
 * - `nntmux_search_build_episode_join_predicates` (`ReleaseSearchService`)
 * - `nntmux_search_resolve_size_range_bounds` (`ReleaseSearchService`)
 * - `nntmux_manticore_schema_compare_columns_nul` (`ManticoreSchemaInspector`)
 * - `nntmux_manticore_schema_missing_settings_nul` (`ManticoreSchemaInspector`)
 *
 * Paired randomized benchmark summary (macOS Darwin arm64, `cargo build --release`, N=40 interleaved pairs):
 * - `ReleaseSearchService::normalizeMysqlFallbackPhrase` (common multi-term phrase): PHP 295.1 ns mean / 375.0 ns p99 vs Rust 153.7 ns mean / 209.0 ns p99 -> 1.92x mean, 1.79x p99 (adopted in production).
 * - `ReleaseSearchService::normalizeMysqlFallbackPhrase` (uncommon p99 248-char phrase): PHP 945.8 ns mean / 1208.0 ns p99 vs Rust 234.1 ns mean / 333.0 ns p99 -> 4.04x mean, 3.63x p99 (adopted in production).
 * - `ReleaseSearchService::buildRangeSqlConditions` (common 5-bound int ranges): PHP 1306.4 ns mean / 1667.0 ns p99 vs Rust 524.7 ns mean / 750.0 ns p99 -> 2.49x mean, 2.22x p99 (adopted in production).
 * - `ReleaseSearchService::buildRangeSqlConditions` (uncommon 10-bound string ranges): PHP 1895.3 ns mean / 2208.0 ns p99 vs Rust 938.1 ns mean / 1167.0 ns p99 -> 2.02x mean, 1.89x p99 (adopted in production).
 * - `ManticoreSchemaInspector::missingSettings` (common bundled settings): PHP 696.9 ns mean / 792.0 ns p99 vs Rust 311.3 ns mean / 375.0 ns p99 -> 2.24x mean, 2.11x p99 (adopted in production).
 * - `ManticoreSchemaInspector::missingSettings` (uncommon multiline quoted settings): PHP 1389.1 ns mean / 2000.0 ns p99 vs Rust 455.3 ns mean / 542.0 ns p99 -> 3.05x mean, 3.69x p99 (adopted in production).
 * - `XML_Response::buildCdataForRelease`: PHP 575.5 ns mean / 709.0 ns p99 vs Rust 696.9 ns mean / 1458.0 ns p99 on movie cData (0.83x mean, 0.49x p99) and PHP 928.5 ns mean / 1125.0 ns p99 vs Rust 708.4 ns mean / 875.0 ns p99 on music cData (1.31x mean, 1.29x p99) -> retained native PHP in production; `buildCdataForReleaseNative` tested.
 * - `ReleaseSearchService::buildEpisodeJoinPredicates`: PHP 199.8 ns mean / 250.0 ns p99 vs Rust 216.4 ns mean / 292.0 ns p99 -> 0.92x mean, 0.86x p99 (retained native PHP in production; `buildEpisodeJoinPredicatesNative` tested).
 * - `ReleaseSearchService::resolveSizeRangeBounds`: PHP 70.5 ns mean / 84.0 ns p99 vs Rust 137.2 ns mean / 167.0 ns p99 -> 0.51x mean, 0.50x p99 (retained native PHP in production; `resolveSizeRangeBoundsNative` tested).
 * - `ManticoreSchemaInspector::compareColumns`: PHP 6305.6 ns mean / 10625.0 ns p99 vs Rust 9010.4 ns mean / 19791.0 ns p99 -> 0.70x mean, 0.54x p99 (retained native PHP in production; `compareColumnsNative` tested).
 */
final class RustApiXmlSearchSqlBuilderParityTest extends TestCase
{
    private const string CDEF = <<<'C'
        ssize_t nntmux_api_build_cdata(const char *pairs_nul, size_t pairs_len, size_t pair_count, uint32_t tz_is_utc, char *out_buf, size_t out_cap);
        ssize_t nntmux_search_normalize_mysql_fallback_phrase(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_search_build_range_sql_conditions(int64_t size_min, int64_t size_max, int64_t totalpart_min, int64_t totalpart_max, int64_t grabs_min, int64_t grabs_max, int64_t comments_min, int64_t comments_max, int64_t adddate_min, int64_t adddate_max, char *out_buf, size_t out_cap);
        ssize_t nntmux_search_build_episode_join_predicates(const char *series_ptr, size_t series_len, const char *episode_ptr, size_t episode_len, const char *airdate_ptr, size_t airdate_len, char *out_buf, size_t out_cap);
        int32_t nntmux_search_resolve_size_range_bounds(int64_t size_from, int64_t size_to, int64_t *out_bounds2);
        ssize_t nntmux_manticore_schema_compare_columns_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_manticore_schema_missing_settings_nul(const char *actual_nul, size_t actual_len, size_t actual_count, const char *expected_nul, size_t expected_len, size_t expected_count, char *out_buf, size_t out_cap);
        C;

    private string $previousTz = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        XML_Response::setUseRustFfi(true);
        ReleaseSearchService::setUseRustFfi(true);
        ManticoreSchemaInspector::setUseRustFfi(true);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTz);
        XML_Response::setUseRustFfi(true);
        ReleaseSearchService::setUseRustFfi(true);
        ManticoreSchemaInspector::setUseRustFfi(true);
        parent::tearDown();
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('api_xml_search_sql_builder_test', self::CDEF);
        if ($ffi === null) {
            self::markTestSkipped('Rust pipeline FFI library is not available.');
        }

        return $ffi;
    }

    #[Test]
    public function c_abi_resize_decline_and_error_contracts_work(): void
    {
        $ffi = $this->requireFfi();
        $tiny = $ffi->new('char[4]');

        // 1. nntmux_api_build_cdata
        $cdataIn = "mu_artist\x00Daft Punk\x00year\x002013";
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_api_build_cdata($cdataIn, strlen($cdataIn), 2, 1, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_build_cdata(null, 5, 1, 1, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_api_build_cdata('', 0, 0, 1, $tiny, 4));
        $cdataBadDate = "mu_releasedate\x00not-a-date";
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_api_build_cdata($cdataBadDate, strlen($cdataBadDate), 1, 1, $tiny, 4));

        // 2. nntmux_search_normalize_mysql_fallback_phrase
        $phrase = 'Example.Show.S01E02.1080p';
        // @phpstan-ignore method.notFound
        self::assertSame(-25, $ffi->nntmux_search_normalize_mysql_fallback_phrase($phrase, strlen($phrase), $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_normalize_mysql_fallback_phrase(null, 5, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_normalize_mysql_fallback_phrase('', 0, $tiny, 4));

        // 3. nntmux_search_build_range_sql_conditions
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_search_build_range_sql_conditions(20, 40, -1, -1, -1, -1, -1, -1, -1, -1, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_search_build_range_sql_conditions(-1, -1, -1, -1, -1, -1, -1, -1, -1, -1, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_search_build_range_sql_conditions(-1, -1, -1, -1, -1, -1, -1, -1, 253402300800, -1, $tiny, 4));

        // 4. nntmux_search_build_episode_join_predicates
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_search_build_episode_join_predicates('S01', 3, 'E02', 3, '', 0, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_build_episode_join_predicates(null, 3, '', 0, '', 0, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-2, $ffi->nntmux_search_build_episode_join_predicates('', 0, '', 0, "2024-03-05'bad", 14, $tiny, 4));

        // 5. nntmux_search_resolve_size_range_bounds
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_search_resolve_size_range_bounds(1, 2, null));

        // 6. nntmux_manticore_schema_compare_columns_nul
        $actCols = "passwordstatus\x00uint";
        $expCols = "passwordstatus\x00bigint\x00haspreview\x00bigint";
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_manticore_schema_compare_columns_nul($actCols, strlen($actCols), 1, $expCols, strlen($expCols), 2, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_manticore_schema_compare_columns_nul(null, 5, 1, $expCols, strlen($expCols), 2, $tiny, 4));

        // 7. nntmux_manticore_schema_missing_settings_nul
        $actSet = "\x01\x00min_infix_len = 2";
        $expSet = "min_infix_len\x002\x00exact_words\x001";
        // @phpstan-ignore method.notFound
        self::assertLessThan(-4, $ffi->nntmux_manticore_schema_missing_settings_nul($actSet, strlen($actSet), 1, $expSet, strlen($expSet), 2, $tiny, 4));
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_manticore_schema_missing_settings_nul(null, 5, 1, $expSet, strlen($expSet), 2, $tiny, 4));
    }

    #[Test]
    public function xml_response_build_cdata_matches_php_across_movie_music_console_and_edge_cases(): void
    {
        $this->requireFfi();

        $movieCols = ['rating', 'plot', 'year', 'genre', 'director', 'actors'];
        $musicCols = ['mu_artist', 'mu_genre', 'mu_publisher', 'mu_releasedate', 'mu_review'];
        $gameCols = ['co_genre', 'co_publisher', 'year', 'co_review'];

        $cases = [
            [
                (object) [
                    'rating' => '8.7',
                    'plot' => 'A computer hacker learns from mysterious rebels about the true nature of his reality.',
                    'year' => '1999',
                    'genre' => 'Action, Sci-Fi',
                    'director' => 'Lana Wachowski, Lilly Wachowski',
                    'actors' => 'Keanu Reeves, Laurence Fishburne, Carrie-Anne Moss',
                ],
                $movieCols,
                true,
            ],
            [
                (object) [
                    'mu_artist' => 'Daft Punk',
                    'mu_genre' => 'Electronic',
                    'mu_publisher' => 'Columbia',
                    'mu_releasedate' => '2013-05-17 00:00:00',
                    'mu_review' => 'Random Access Memories is the fourth studio album by French electronic music duo Daft Punk.',
                ],
                $musicCols,
                true,
            ],
            [
                (object) [
                    'co_genre' => 'Action RPG',
                    'co_publisher' => 'FromSoftware',
                    'year' => 2022,
                    'co_review' => str_repeat('Elden Ring review paragraph with rich detail. ', 30),
                ],
                $gameCols,
                true,
            ],
            [
                (object) [
                    'rating' => '',
                    'plot' => '0',
                    'year' => 0,
                    'genre' => null,
                ],
                $movieCols,
                true,
            ],
            [
                (object) [
                    'ab_' => 'EmptyAfterPrefix',
                    'XY_title' => 'UpperPrefix',
                    'a_short' => 'SingleCharPrefixNotStripped',
                    'mu_releasedate' => '2024-02-29T12:30:00Z',
                ],
                ['ab_', 'XY_title', 'a_short', 'mu_releasedate'],
                true,
            ],
            [
                (object) [
                    'mu_artist' => 'Artist',
                    'mu_releasedate' => 'May 17, 2013',
                ],
                $musicCols,
                false,
            ],
            [
                (object) [
                    'plot' => "Has\0Nul",
                ],
                $movieCols,
                false,
            ],
        ];

        foreach ($cases as $idx => [$release, $cols, $expectSupported]) {
            $expected = XML_Response::buildCdataForReleasePhp($release, $cols);
            $supported = false;
            $native = XML_Response::buildCdataForReleaseNative($release, $cols, $supported);
            self::assertSame($expectSupported, $supported, "Unexpected supported flag at case #{$idx}");
            if ($expectSupported) {
                self::assertSame($expected, $native, "buildCdataForReleaseNative mismatch at case #{$idx}");
            } else {
                self::assertNull($native);
            }
            self::assertSame($expected, XML_Response::buildCdataForRelease($release, $cols), "buildCdataForRelease mismatch at case #{$idx}");
        }

        // Full RSS XML response parity for Movie, Music, and Console releases
        foreach ([
            (object) [
                'searchname' => 'The.Matrix.1999.1080p.BluRay-GRP',
                'guid' => 'guid-movie-1',
                'adddate' => '2026-05-29 12:00:00',
                'postdate' => '2026-05-28 08:30:00',
                'category_name' => 'Movies > HD',
                'categories_id' => 2040,
                'size' => 4294967296,
                'group_name' => 'alt.binaries.movies',
                'fromname' => 'poster@example.test',
                'passwordstatus' => 0,
                'nfostatus' => 1,
                'parentid' => Category::MOVIE_ROOT,
                'imdbid' => '0133093',
                'rating' => '8.7',
                'plot' => 'A hacker discovers the Matrix.',
                'year' => '1999',
                'genre' => 'Sci-Fi',
                'director' => 'Wachowskis',
                'actors' => 'Keanu Reeves',
                'musicinfo_id' => 0,
                'consoleinfo_id' => 0,
                '_totalrows' => 1,
            ],
            (object) [
                'searchname' => 'Daft.Punk-Random.Access.Memories-2013-FLAC',
                'guid' => 'guid-music-1',
                'adddate' => '2026-05-29 12:00:00',
                'postdate' => '2026-05-28 08:30:00',
                'category_name' => 'Audio > Lossless',
                'categories_id' => 3040,
                'size' => 524288000,
                'group_name' => 'alt.binaries.sounds.flac',
                'fromname' => 'poster@example.test',
                'passwordstatus' => 0,
                'nfostatus' => 0,
                'parentid' => Category::MUSIC_ROOT,
                'imdbid' => '',
                'musicinfo_id' => 12,
                'mu_url' => 'https://example.test/music/12',
                'mu_title' => 'Random Access Memories',
                'mu_artist' => 'Daft Punk',
                'mu_genre' => 'Electronic',
                'mu_publisher' => 'Columbia',
                'mu_releasedate' => '2013-05-17 00:00:00',
                'mu_review' => 'Album review text.',
                'mu_tracks' => 'Give Life Back to Music|Get Lucky',
                'consoleinfo_id' => 0,
                '_totalrows' => 1,
            ],
        ] as $rIdx => $rssRelease) {
            $opts = [
                'Parameters' => [
                    'extended' => '1',
                    'del' => '0',
                    'token' => 'tok',
                    'uid' => 1,
                    'requests' => 1,
                    'apilimit' => 100,
                    'grabs' => 0,
                    'downloadlimit' => 100,
                    'oldestapi' => '',
                    'oldestgrab' => '',
                ],
                'Data' => [$rssRelease],
                'Server' => [
                    'server' => [
                        'title' => 'NNTmux',
                        'strapline' => 'Test',
                        'email' => 'noreply@example.test',
                        'meta' => 'usenet',
                        'url' => 'https://indexer.example.test',
                    ],
                ],
                'Offset' => 0,
                'Type' => 'rss',
            ];
            XML_Response::setUseRustFfi(false);
            $phpXml = (new XML_Response($opts))->returnXML();
            XML_Response::setUseRustFfi(true);
            $rustXml = (new XML_Response($opts))->returnXML();
            self::assertSame($phpXml, $rustXml, "RSS XML response mismatch at release #{$rIdx}");
        }

        // Non-UTC timezone declines mu_releasedate to PHP transparently
        date_default_timezone_set('America/New_York');
        $musicObj = (object) ['mu_artist' => 'Daft Punk', 'mu_releasedate' => '2013-05-17 00:00:00'];
        $supported = false;
        self::assertNull(XML_Response::buildCdataForReleaseNative($musicObj, $musicCols, $supported));
        self::assertFalse($supported);
        self::assertSame(
            XML_Response::buildCdataForReleasePhp($musicObj, $musicCols),
            XML_Response::buildCdataForRelease($musicObj, $musicCols),
        );
    }

    #[Test]
    public function release_search_service_sql_builders_and_normalizers_match_php(): void
    {
        $this->requireFfi();
        $reflection = new ReflectionClass(ReleaseSearchService::class);
        /** @var ReleaseSearchService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        // 1. normalizeMysqlFallbackPhrase
        $phraseCases = [
            '',
            '   ',
            " \t\n\r\0\x0B ",
            '..--__',
            '._-  -_ .',
            "\x0cHello.World\x0c",
            "Example.Show-S01_E02  \t\n  1080p.WEB-DL-GRP",
            "Example.Show O'Reilly",
            'MÖVIE.Title-2026_1080p',
            str_repeat('Long.Release-Title_Segment.2026 ', 25),
        ];

        $random = new Randomizer(new Mt19937(20261027));
        $alphabet = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789.-_ \t\n\r\0\x0B\x0C'!";
        for ($i = 0; $i < 300; $i++) {
            $len = $random->getInt(0, 80);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= $alphabet[$random->getInt(0, strlen($alphabet) - 1)];
            }
            $phraseCases[] = $s;
        }

        foreach ($phraseCases as $idx => $phrase) {
            $expected = $service->normalizeMysqlFallbackPhrasePhp($phrase);
            $native = $service->normalizeMysqlFallbackPhraseNative($phrase);
            self::assertSame($expected, $native, "normalizeMysqlFallbackPhraseNative mismatch at #{$idx}");
            self::assertSame($expected, $service->normalizeMysqlFallbackPhrase($phrase));
        }

        // 2. buildRangeSqlConditions
        $rangeCases = [
            [],
            ['unknown' => ['min' => 1, 'max' => 2]],
            ['size' => ['min' => -5, 'max' => 'invalid']],
            [
                'size' => ['min' => 20, 'max' => 40],
                'comments' => ['max' => 0],
                'adddate_ts' => ['min' => 1700000000, 'max' => 1700086399],
            ],
            [
                'size' => ['min' => '104857600', 'max' => '10737418240'],
                'totalpart' => ['min' => 1, 'max' => 200],
                'grabs' => ['min' => '0', 'max' => '+50'],
                'comments' => ['min' => '-0', 'max' => '00'],
                'adddate_ts' => ['min' => 0, 'max' => 253402300799],
            ],
            [
                'adddate_ts' => ['min' => 253402300800],
            ],
        ];

        foreach ($rangeCases as $idx => $ranges) {
            $expected = $service->buildRangeSqlConditionsPhp($ranges);
            if (($ranges['adddate_ts']['min'] ?? 0) <= 253402300799) {
                self::assertSame($expected, $service->buildRangeSqlConditionsNative($ranges), "buildRangeSqlConditionsNative mismatch at #{$idx}");
            } else {
                self::assertNull($service->buildRangeSqlConditionsNative($ranges));
            }
            self::assertSame($expected, $service->buildRangeSqlConditions($ranges), "buildRangeSqlConditions mismatch at #{$idx}");
        }

        // 3. buildEpisodeJoinPredicates (with SQLite PDO for escapeString parity)
        $pdo = new \PDO('sqlite::memory:');
        $resolver = new ConnectionResolver(['testing' => new SQLiteConnection($pdo)]);
        $resolver->setDefaultConnection('testing');
        DB::swap($resolver);

        try {
            $episodeCases = [
                ['', '', '', true],
                ['0', '0', '0', true],
                ['S01', 'E02', '', true],
                ['s006', 'e0024', '1994-10-09', true],
                ['12', '5', '', true],
                ['5', '1/2', '', true],
                ['5', '', '', true],
                ['5', '0', '', true],
                ['1899', '1', '', true],
                ['1900', '1', '', true],
                ['2024', '1', '2024-03-05', true],
                ['', 'E02', '2024-03-05', true],
                ['', '', '2026/10/24', true],
                ['', '', "2024-03-05' OR '1'='1", false],
                ['S01E02', '1', '', false],
            ];

            foreach ($episodeCases as $idx => [$series, $episode, $airDate, $expectNative]) {
                $expected = $service->buildEpisodeJoinPredicatesPhp($series, $episode, $airDate);
                $native = $service->buildEpisodeJoinPredicatesNative($series, $episode, $airDate);
                if ($expectNative) {
                    self::assertSame($expected, $native, "buildEpisodeJoinPredicatesNative mismatch at #{$idx}");
                } else {
                    self::assertNull($native, "Expected native decline at episode case #{$idx}");
                }
                self::assertSame($expected, $service->buildEpisodeJoinPredicates($series, $episode, $airDate), "buildEpisodeJoinPredicates mismatch at #{$idx}");
            }
        } finally {
            Facade::clearResolvedInstance('db');
        }

        // 4. resolveSizeRangeBounds
        $bucketValues = [-1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, '', '0', '1', '2', '11', '12', '01', ' 2 ', '-1', null, true, false, []];
        foreach ($bucketValues as $from) {
            foreach ($bucketValues as $to) {
                $expected = $service->resolveSizeRangeBoundsPhp($from, $to);
                $native = $service->resolveSizeRangeBoundsNative($from, $to);
                self::assertSame($expected, $native);
                self::assertSame($expected, $service->resolveSizeRangeBounds($from, $to));
            }
        }
    }

    #[Test]
    public function manticore_schema_inspector_matches_php_across_all_index_definitions_and_formats(): void
    {
        $this->requireFfi();

        foreach (ManticoreIndexRegistry::definitions() as $indexName => $definition) {
            $expectedCols = $definition['columns'];
            $actualAssociative = [];
            $actualRows = [];
            foreach ($expectedCols as $colName => $colDef) {
                $actualType = $colDef['type'] === 'integer' ? 'uint' : strtoupper((string) $colDef['type']);
                $actualAssociative[$colName] = ['Type' => $actualType, 'Properties' => []];
                $actualRows[] = ['Field' => $colName, 'Type' => $actualType];
            }

            foreach ([$actualAssociative, $actualRows] as $actual) {
                $expectedRes = ManticoreSchemaInspector::compareColumnsPhp($actual, $expectedCols);
                self::assertSame($expectedRes, ManticoreSchemaInspector::compareColumnsNative($actual, $expectedCols), "compareColumnsNative match failed for {$indexName}");
                self::assertSame($expectedRes, ManticoreSchemaInspector::compareColumns($actual, $expectedCols));
            }

            // Drift case: drop last column and mutate first column type
            $drifted = $actualAssociative;
            $keys = array_keys($drifted);
            $drifted[$keys[0]]['Type'] = 'float';
            unset($drifted[$keys[count($keys) - 1]]);

            $expectedDrift = ManticoreSchemaInspector::compareColumnsPhp($drifted, $expectedCols);
            self::assertSame($expectedDrift, ManticoreSchemaInspector::compareColumnsNative($drifted, $expectedCols));
            self::assertSame($expectedDrift, ManticoreSchemaInspector::compareColumns($drifted, $expectedCols));
        }

        // Duplicate field names in $actual where later entry overwrites earlier entry
        $dupActual = [
            ['Field' => 'passwordstatus', 'Type' => 'uint'],
            [' field' => 'ignored'],
            ['field' => 'passwordstatus', 'type' => 'bigint'],
        ];
        $dupExpected = ['passwordstatus' => ['type' => 'bigint'], 'haspreview' => ['type' => 'bigint']];
        self::assertSame(
            ManticoreSchemaInspector::compareColumnsPhp($dupActual, $dupExpected),
            ManticoreSchemaInspector::compareColumnsNative($dupActual, $dupExpected),
        );

        // missingSettings across all input shapes
        $expectedSettings = [
            'min_infix_len' => 2,
            'exact_words' => 1,
            'index_field_lengths' => 1,
        ];

        $settingsInputs = [
            null,
            'not-an-array',
            [],
            ['settings' => "min_infix_len = 2\nexact_words = 1\nindex_field_lengths = 1"],
            ['SETTINGS' => "  min_infix_len = '2'\r\n  EXACT_WORDS = \"1\" \r index_field_lengths = 2 \x0B bad_line \x0C empty = \"\" "],
            [
                'min_infix_len' => ['Value' => '2'],
                'exact_words' => ['value' => 1],
                'index_field_lengths' => ['other' => '1'],
            ],
            [
                ['Setting_name' => 'min_infix_len', 'Value' => '2'],
                ['Variable_name' => 'exact_words', 'Value' => '0'],
                ['name' => 'index_field_lengths', 'value' => '1'],
            ],
            [
                'min_infix_len' => 2,
                'exact_words' => '1',
            ],
            [
                'settings' => "min_infix_len = '   '\nexact_words = 1",
            ],
        ];

        foreach ($settingsInputs as $idx => $actualSettings) {
            $expected = ManticoreSchemaInspector::missingSettingsPhp($actualSettings, $expectedSettings);
            self::assertSame(
                $expected,
                ManticoreSchemaInspector::missingSettingsNative($actualSettings, $expectedSettings),
                "missingSettingsNative mismatch at case #{$idx}",
            );
            self::assertSame(
                $expected,
                ManticoreSchemaInspector::missingSettings($actualSettings, $expectedSettings),
                "missingSettings mismatch at case #{$idx}",
            );
        }

        ManticoreSchemaInspector::setUseRustFfi(false);
        self::assertFalse(ManticoreSchemaInspector::usesRustFfi());
        self::assertNull(ManticoreSchemaInspector::missingSettingsNative($settingsInputs[3], $expectedSettings));
        self::assertNull(ManticoreSchemaInspector::compareColumnsNative($dupActual, $dupExpected));
        self::assertSame(
            ManticoreSchemaInspector::missingSettingsPhp($settingsInputs[3], $expectedSettings),
            ManticoreSchemaInspector::missingSettings($settingsInputs[3], $expectedSettings),
        );
        ManticoreSchemaInspector::setUseRustFfi(true);
    }

    /**
     * Opt-in paired randomized benchmark of the adopted Slice 9.6 methods.
     * NNTMUX_API_XML_SEARCH_SQL_BENCHMARK=1 php -d opcache.enable_cli=1 artisan test --compact
     * tests/Unit/RustApiXmlSearchSqlBuilderParityTest.php --filter=paired_benchmark
     */
    #[Test]
    public function paired_benchmark(): void
    {
        if (getenv('NNTMUX_API_XML_SEARCH_SQL_BENCHMARK') !== '1') {
            self::markTestSkipped('Opt in with NNTMUX_API_XML_SEARCH_SQL_BENCHMARK=1 to run 40 paired blocks per workload.');
        }
        $this->requireFfi();
        $random = new Randomizer(new Mt19937(20261028));

        $reflection = new ReflectionClass(ReleaseSearchService::class);
        /** @var ReleaseSearchService $searchService */
        $searchService = $reflection->newInstanceWithoutConstructor();

        $phraseCommon = '  The.Matrix.Reloaded - 2003  1080p_UHD.BluRay  x265-GRP  ';
        $phraseUncommon = str_repeat('Some.Long.Release-Title_With_Many---Separators..And..Spaces   ', 4);

        $rangesCommon = [
            'size' => ['min' => 104857600, 'max' => 4294967296],
            'comments' => ['max' => 0],
            'adddate_ts' => ['min' => 1700000000, 'max' => 1700086399],
        ];
        $rangesUncommon = [
            'size' => ['min' => '104857600', 'max' => '10737418240'],
            'totalpart' => ['min' => '5', 'max' => '200'],
            'grabs' => ['min' => '1', 'max' => '500'],
            'comments' => ['min' => '0', 'max' => '50'],
            'adddate_ts' => ['min' => '1700000000', 'max' => '1700086399'],
        ];

        $settingsCommon = ['settings' => "min_infix_len = 2\nexact_words = 1\nindex_field_lengths = 1"];
        $settingsUncommon = [
            'settings' => implode("\r\n", [
                "min_infix_len = '2'",
                'morphology = stem_en',
                'min_word_len = 1',
                "html_strip = '0'",
                'index_exact_words = 1',
                "exact_words = '1'",
                'index_field_lengths = "1"',
                'expand_keywords = 1',
            ]),
        ];
        $expectedSettings = ManticoreIndexRegistry::inspectableSettings();

        $p99 = static function (array $values): float {
            sort($values);

            return $values[(int) ceil(count($values) * 0.99) - 1];
        };

        $benchmarks = [
            'normalizeMysqlFallbackPhrase[common]' => [
                static fn (): string => $searchService->normalizeMysqlFallbackPhrasePhp($phraseCommon),
                static fn (): string => $searchService->normalizeMysqlFallbackPhrase($phraseCommon),
            ],
            'normalizeMysqlFallbackPhrase[uncommon]' => [
                static fn (): string => $searchService->normalizeMysqlFallbackPhrasePhp($phraseUncommon),
                static fn (): string => $searchService->normalizeMysqlFallbackPhrase($phraseUncommon),
            ],
            'buildRangeSqlConditions[common]' => [
                static fn (): array => $searchService->buildRangeSqlConditionsPhp($rangesCommon),
                static fn (): array => $searchService->buildRangeSqlConditions($rangesCommon),
            ],
            'buildRangeSqlConditions[uncommon]' => [
                static fn (): array => $searchService->buildRangeSqlConditionsPhp($rangesUncommon),
                static fn (): array => $searchService->buildRangeSqlConditions($rangesUncommon),
            ],
            'missingSettings[common:bundled]' => [
                static fn (): array => ManticoreSchemaInspector::missingSettingsPhp($settingsCommon, $expectedSettings),
                static fn (): array => ManticoreSchemaInspector::missingSettings($settingsCommon, $expectedSettings),
            ],
            'missingSettings[uncommon:multiline]' => [
                static fn (): array => ManticoreSchemaInspector::missingSettingsPhp($settingsUncommon, $expectedSettings),
                static fn (): array => ManticoreSchemaInspector::missingSettings($settingsUncommon, $expectedSettings),
            ],
        ];

        foreach ($benchmarks as $label => [$phpFn, $rustFn]) {
            self::assertSame($phpFn(), $rustFn(), "Parity check failed for {$label}");
            for ($w = 0; $w < 200; $w++) {
                $phpFn();
                $rustFn();
            }
            $samples = ['php' => [], 'rust' => []];
            for ($pair = 0; $pair < 40; $pair++) {
                $order = $random->getInt(0, 1) === 0 ? ['php', 'rust'] : ['rust', 'php'];
                foreach ($order as $variant) {
                    $fn = $variant === 'php' ? $phpFn : $rustFn;
                    for ($call = 0; $call < 50; $call++) {
                        $start = hrtime(true);
                        $fn();
                        $samples[$variant][] = hrtime(true) - $start;
                    }
                }
            }
            $summary = [
                'php' => [
                    'mean_ns' => array_sum($samples['php']) / count($samples['php']),
                    'p99_ns' => $p99($samples['php']),
                ],
                'rust' => [
                    'mean_ns' => array_sum($samples['rust']) / count($samples['rust']),
                    'p99_ns' => $p99($samples['rust']),
                ],
            ];
            $summary['mean_speedup'] = $summary['php']['mean_ns'] / $summary['rust']['mean_ns'];
            $summary['p99_speedup'] = $summary['php']['p99_ns'] / $summary['rust']['p99_ns'];
            fwrite(STDERR, "{$label} ".json_encode($summary, JSON_THROW_ON_ERROR)."\n");
            self::assertGreaterThanOrEqual(1.5, $summary['mean_speedup'], "{$label} mean speedup");
            self::assertGreaterThanOrEqual(1.5, $summary['p99_speedup'], "{$label} p99 speedup");
        }
    }
}
