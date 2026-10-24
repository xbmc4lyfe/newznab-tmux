<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\TvLookupStatus;
use App\Models\Category;
use App\Models\Release;
use App\Models\RootCategory;
use App\Services\BookProcessingCandidateQuery;
use App\Services\SeriesReleaseService;
use App\Services\TvProcessing\TvLookupOutcomeWriter;
use App\Services\TvProcessing\TvProcessingResult;
use App\Services\TvProcessing\TvReleaseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, Category container regression, and paired benchmark
 * verification for Wave 16 Worker 5:
 * TvLookupOutcomeWriter, TvReleaseContext, BookProcessingCandidateQuery,
 * SeriesReleaseService, and CollectionFileCheckStatus.
 *
 * Benchmarks run only with NNTMUX_TV_BOOK_SERIES_LOOKUP_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_TV_BOOK_SERIES_LOOKUP_BENCH=1 php artisan test --compact tests/Unit/RustTvBookSeriesLookupParityTest.php
 *
 * Recorded end-to-end PHP vs Rust FFI speedups (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                           | Common (mean / p99) | Uncommon (mean / p99) | Default      |
 * |--------------------------------------------------|---------------------|-----------------------|--------------|
 * | TvLookupOutcomeWriter::resolveOutcome            | 0.21x / 0.18x       | 0.23x / 0.24x         | PHP (false)  |
 * | TvLookupOutcomeWriter::hasLinkChanged            | 0.42x / 0.40x       | 0.43x / 0.37x         | PHP (false)  |
 * | TvLookupOutcomeWriter::shouldBumpCacheVersion    | 0.39x / 0.35x       | 0.49x / 0.42x         | PHP (false)  |
 * | TvReleaseContext::fromRelease                    | 0.20x / 0.17x       | 0.20x / 0.18x         | PHP (false)  |
 * | BookProcessingCandidateQuery::workCondition      | 0.84x / 0.73x       | 0.91x / 0.87x         | PHP (false)  |
 * | BookProcessingCandidateQuery::retryDelay         | 0.43x / 0.33x       | 0.40x / 0.42x         | PHP (false)  |
 * | SeriesReleaseService::planSeasonWindow           | 0.15x / 0.14x       | 0.15x / 0.15x         | PHP (false)  |
 * | SeriesReleaseService::mergeSeasonCounts          | 0.24x / 0.25x       | 0.42x / 0.36x         | PHP (false)  |
 * | SeriesReleaseService::hasValidFallbackEpisode    | 0.27x / 0.25x       | 0.27x / 0.19x         | PHP (false)  |
 * | SeriesReleaseService::normalizeCategoryIds       | 0.25x / 0.25x       | 0.33x / 0.35x         | PHP (false)  |
 * | CollectionFileCheckStatus::description           | 0.26x / 0.23x       | 0.37x / 0.25x         | PHP (false)  |
 * | CollectionFileCheckStatus::isProcessingComplete  | 0.45x / 0.43x       | 0.46x / 0.44x         | PHP (false)  |
 * | CollectionFileCheckStatus::inProgress            | 0.14x / 0.13x       | 0.14x / 0.13x         | PHP (false)  |
 */
class RustTvBookSeriesLookupParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array CLASS_TOGGLED = [
        TvLookupOutcomeWriter::class,
        TvReleaseContext::class,
        BookProcessingCandidateQuery::class,
        SeriesReleaseService::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', TvLookupOutcomeWriter::FFI_CDEF) === null) {
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
        CollectionFileCheckStatus::setUseRustFfi(null);
        CollectionFileCheckStatus::setFfiForTesting(null);
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        foreach (self::CLASS_TOGGLED as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP until its Rust path passes the 1.5x gate");
        }
        $this->assertFalse(CollectionFileCheckStatus::usesRustFfi());
    }

    // ---------------------------------------------------------------------
    // 1. TvLookupOutcomeWriter
    // ---------------------------------------------------------------------

    public function test_tv_lookup_outcome_writer_resolve_and_columns_for_parity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-19 12:00:00'));

        try {
            $writer = new TvLookupOutcomeWriter;
            $previousVideos = [0, 15, 99];
            $previousStatuses = [
                TvLookupStatus::Pending->value,
                TvLookupStatus::ShowNotFound->value,
                TvLookupStatus::EpisodeMissing->value,
                TvLookupStatus::SeasonPack->value,
                TvLookupStatus::ParseFailed->value,
                42,
            ];
            $previousAttemptsList = [0, 1, 2, 3, 4, 5, 6, 7, 8, PHP_INT_MAX];
            $results = [
                TvProcessingResult::matched(15, 42, 'TVDB'),
                TvProcessingResult::matched(99, 100, 'TVMaze'),
                TvProcessingResult::seasonPack(15, 'TVDB'),
                TvProcessingResult::episodeMissing(15, 'TVDB', [], false, null),
                TvProcessingResult::episodeMissing(15, 'TVDB', [], true, 120),
                TvProcessingResult::episodeMissing(15, 'TVDB', [], true, 3600),
                TvProcessingResult::transient('TVDB', null),
                TvProcessingResult::transient('TVDB', 120),
                TvProcessingResult::transient('TVDB', 7200),
                TvProcessingResult::parseFailed(),
                TvProcessingResult::notFound('TVDB'),
                TvProcessingResult::skipped('disabled', 'TVDB'),
                TvProcessingResult::pending(),
            ];

            foreach ($previousVideos as $prevVid) {
                foreach ($previousStatuses as $prevStatus) {
                    foreach ($previousAttemptsList as $prevAttempts) {
                        foreach ($results as $rIdx => $result) {
                            $expected = TvLookupOutcomeWriter::resolveOutcomePhp($prevVid, $prevStatus, $prevAttempts, $result);
                            $native = TvLookupOutcomeWriter::resolveOutcomeNative($prevVid, $prevStatus, $prevAttempts, $result);
                            $this->assertSame($expected, $native, "resolve vid={$prevVid} status={$prevStatus} att={$prevAttempts} r={$rIdx}");

                            if ($prevAttempts < PHP_INT_MAX) {
                                $release = new Release;
                                $release->forceFill([
                                    'videos_id' => $prevVid,
                                    'tv_episodes_id' => $prevStatus,
                                    'tv_lookup_attempts' => $prevAttempts,
                                ]);
                                $phpCols = $writer->columnsForPhp($release, $result);
                                $nativeCols = $writer->columnsForNative($release, $result);
                                $this->assertEquals($phpCols, $nativeCols);

                                foreach ([true, false, null] as $toggle) {
                                    TvLookupOutcomeWriter::setUseRustFfi($toggle);
                                    $this->assertSame($expected, TvLookupOutcomeWriter::resolveOutcome($prevVid, $prevStatus, $prevAttempts, $result));
                                    $this->assertEquals($phpCols, $writer->columnsFor($release, $result));
                                }
                            }
                        }
                    }
                }
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_tv_lookup_outcome_writer_link_changed_and_cache_bump_parity(): void
    {
        $ids = [-100, -12, -11, -10, 0, 1, 42, PHP_INT_MAX, PHP_INT_MIN];
        foreach ([0, 10, 20] as $prevVid) {
            foreach ([0, 10, 30] as $newVid) {
                foreach ($ids as $prevEp) {
                    foreach ($ids as $newEp) {
                        $expected = TvLookupOutcomeWriter::hasLinkChangedPhp($prevVid, $prevEp, $newVid, $newEp);
                        $this->assertSame(
                            $expected,
                            TvLookupOutcomeWriter::hasLinkChangedNative($prevVid, $prevEp, $newVid, $newEp),
                        );
                    }
                }
            }
        }

        $statuses = ['matched', 'season_pack', 'episode_missing', 'transient', 'parse_failed', 'not_found', '', "matched\0", 'MATCHED'];
        foreach ([false, true] as $linked) {
            foreach ($statuses as $status) {
                $expected = TvLookupOutcomeWriter::shouldBumpCacheVersionPhp($linked, $status);
                $this->assertSame($expected, TvLookupOutcomeWriter::shouldBumpCacheVersionNative($linked, $status));
                foreach ([true, false, null] as $toggle) {
                    TvLookupOutcomeWriter::setUseRustFfi($toggle);
                    $this->assertSame($expected, TvLookupOutcomeWriter::shouldBumpCacheVersion($linked, $status));
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. TvReleaseContext
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>|object  $input
     */
    #[DataProvider('tvReleaseContextProvider')]
    public function test_tv_release_context_from_release_parity(array|object $input): void
    {
        $expected = TvReleaseContext::fromReleasePhp($input);
        $native = TvReleaseContext::fromReleaseNative($input);
        $this->assertEquals($expected, $native);

        foreach ([true, false, null] as $toggle) {
            TvReleaseContext::setUseRustFfi($toggle);
            $this->assertEquals($expected, TvReleaseContext::fromRelease($input));
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>|object}>
     */
    public static function tvReleaseContextProvider(): array
    {
        $model = new Release;
        $model->forceFill([
            'id' => 77,
            'searchname' => 'Severance.S02E01.2160p.WEB-DL',
            'groups_id' => 4,
            'categories_id' => 5045,
            'videos_id' => 19,
            'tv_episodes_id' => -11,
            'guid' => 'abcd1234ef567890',
            'leftguid' => 'a',
        ]);

        return [
            'empty array' => [[]],
            'full array' => [[
                'id' => 42,
                'searchname' => 'The.Expanse.S01E02.1080p',
                'groups_id' => 3,
                'categories_id' => 5040,
                'videos_id' => 15,
                'tv_episodes_id' => 99,
                'guid' => 'deadbeefcafe',
                'leftguid' => 'd',
            ]],
            'string numeric fields' => [[
                'id' => '105',
                'searchname' => 'Andor.S01E03.720p',
                'groups_id' => '7',
                'categories_id' => '5030',
                'videos_id' => '0',
                'tv_episodes_id' => '-12',
                'guid' => null,
                'leftguid' => null,
            ]],
            'stdClass object' => [(object) [
                'id' => 88,
                'searchname' => 'Silo.S01E01.1080p',
                'groups_id' => 2,
                'categories_id' => 5040,
            ]],
            'eloquent model' => [$model],
            'nul byte fallback in searchname' => [[
                'id' => 9,
                'searchname' => "Null\0InName.S01E01",
                'groups_id' => 1,
                'categories_id' => 5040,
                'guid' => "guid\0nul",
            ]],
            'float id fallback' => [[
                'id' => 12.7,
                'searchname' => 'Float.Id.S01E01',
                'groups_id' => 1,
                'categories_id' => 5040,
            ]],
        ];
    }

    // ---------------------------------------------------------------------
    // 3. BookProcessingCandidateQuery
    // ---------------------------------------------------------------------

    public function test_book_processing_candidate_query_parity(): void
    {
        $this->assertSame(
            BookProcessingCandidateQuery::categoryConditionPhp(),
            BookProcessingCandidateQuery::categoryConditionNative(),
        );
        $this->assertSame(
            BookProcessingCandidateQuery::settledConditionPhp(),
            BookProcessingCandidateQuery::settledConditionNative(),
        );

        foreach ([-2, -1, 0, 1, 2, 3, 99, PHP_INT_MIN, PHP_INT_MAX] as $mode) {
            $expectedMeta = BookProcessingCandidateQuery::metadataConditionPhp($mode);
            $this->assertSame($expectedMeta, BookProcessingCandidateQuery::metadataConditionNative($mode), "meta mode={$mode}");

            $expectedNorm = BookProcessingCandidateQuery::normalizationConditionPhp($mode);
            $this->assertSame($expectedNorm, BookProcessingCandidateQuery::normalizationConditionNative($mode), "norm mode={$mode}");

            $expectedWork = BookProcessingCandidateQuery::workConditionPhp($mode);
            $this->assertSame($expectedWork, BookProcessingCandidateQuery::workConditionNative($mode), "work mode={$mode}");

            foreach ([true, false, null] as $toggle) {
                BookProcessingCandidateQuery::setUseRustFfi($toggle);
                $this->assertSame($expectedMeta, BookProcessingCandidateQuery::metadataCondition($mode));
                $this->assertSame($expectedNorm, BookProcessingCandidateQuery::normalizationCondition($mode));
                $this->assertSame($expectedWork, BookProcessingCandidateQuery::workCondition($mode));
            }
        }

        $retryAfters = [PHP_INT_MIN, -100, 0, 1, 299, 300, 301, 1200, 21600, 99999, PHP_INT_MAX];
        foreach ([PHP_INT_MIN, -1, 0, 1, 2, 3, 4, 5, 10, PHP_INT_MAX] as $failures) {
            foreach ($retryAfters as $retryAfter) {
                $expectedDelay = BookProcessingCandidateQuery::retryDelayPhp($failures, $retryAfter);
                $this->assertSame(
                    $expectedDelay,
                    BookProcessingCandidateQuery::retryDelayNative($failures, $retryAfter),
                    "retryDelay {$failures}/{$retryAfter}",
                );
                foreach ([true, false, null] as $toggle) {
                    BookProcessingCandidateQuery::setUseRustFfi($toggle);
                    $this->assertSame($expectedDelay, BookProcessingCandidateQuery::retryDelay($failures, $retryAfter));
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4. SeriesReleaseService
    // ---------------------------------------------------------------------

    public function test_series_release_service_plan_window_merge_counts_and_helpers_parity(): void
    {
        $counts = [0, 1, 3, 10, 25];
        $offsets = [-5, 0, 1, 2, 3, 5, 10, 30];
        $limits = [-1, 0, 1, 2, 5, 10, 50];

        foreach ($counts as $matchedCount) {
            foreach ($counts as $fallbackCount) {
                foreach ($offsets as $offset) {
                    foreach ($limits as $limit) {
                        $expected = SeriesReleaseService::planSeasonWindowPhp($matchedCount, $fallbackCount, $offset, $limit);
                        $this->assertSame(
                            $expected,
                            SeriesReleaseService::planSeasonWindowNative($matchedCount, $fallbackCount, $offset, $limit),
                            "window m={$matchedCount} f={$fallbackCount} o={$offset} l={$limit}",
                        );
                        foreach ([true, false, null] as $toggle) {
                            SeriesReleaseService::setUseRustFfi($toggle);
                            $this->assertSame($expected, SeriesReleaseService::planSeasonWindow($matchedCount, $fallbackCount, $offset, $limit));
                        }
                    }
                }
            }
        }

        $mergeCases = [
            'both empty' => [[], []],
            'matched only' => [[3 => 5, 1 => 2], []],
            'fallback only' => [[], [2 => 4, 1 => 7]],
            'overlapping and string keys' => [['2' => '4', 1 => 3, 0 => 1], [1 => 2, '3' => 5, 0 => 4]],
            'negative and high seasons' => [[2024 => 8, -1 => 1, 10 => 3], [10 => 7, 2 => 6]],
        ];

        foreach ($mergeCases as $label => [$matched, $fallback]) {
            $expected = SeriesReleaseService::mergeSeasonCountsPhp($matched, $fallback);
            $this->assertSame($expected, SeriesReleaseService::mergeSeasonCountsNative($matched, $fallback), "merge {$label}");
            foreach ([true, false, null] as $toggle) {
                SeriesReleaseService::setUseRustFfi($toggle);
                $this->assertSame($expected, SeriesReleaseService::mergeSeasonCounts($matched, $fallback));
            }
        }

        $seriesValues = [null, '', '0', '1', 'S01', 0, 1, 2, -1];
        $episodeValues = [null, '', '0', '1', 'E01', 0, 1, 5, -1, false, true, []];
        foreach ($seriesValues as $sIdx => $series) {
            foreach ($episodeValues as $eIdx => $episode) {
                $expected = SeriesReleaseService::hasValidFallbackEpisodePhp($series, $episode);
                $this->assertSame(
                    $expected,
                    SeriesReleaseService::hasValidFallbackEpisodeNative($series, $episode),
                    "fallback ep s={$sIdx} e={$eIdx}",
                );
                foreach ([true, false, null] as $toggle) {
                    SeriesReleaseService::setUseRustFfi($toggle);
                    $this->assertSame($expected, SeriesReleaseService::hasValidFallbackEpisode($series, $episode));
                }
            }
        }

        $categoryCases = [
            'null' => null,
            'empty' => [],
            'int list' => [5010, 5030, 5040, 5045],
            'string and keyed' => ['a' => '5010', 'b' => 5040, 5 => '5070'],
            'mixed fallback' => ['5010x', 5040.9],
        ];
        foreach ($categoryCases as $label => $cats) {
            $expected = SeriesReleaseService::normalizeCategoryIdsPhp($cats);
            $this->assertSame($expected, SeriesReleaseService::normalizeCategoryIdsNative($cats), "cats {$label}");
            foreach ([true, false, null] as $toggle) {
                SeriesReleaseService::setUseRustFfi($toggle);
                $this->assertSame($expected, SeriesReleaseService::normalizeCategoryIds($cats));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 5. CollectionFileCheckStatus
    // ---------------------------------------------------------------------

    public function test_collection_file_check_status_parity(): void
    {
        foreach (CollectionFileCheckStatus::cases() as $case) {
            $expectedDesc = $case->descriptionPhp();
            $this->assertSame($expectedDesc, $case->descriptionNative(), $case->name);

            $expectedComplete = $case->isProcessingCompletePhp();
            $this->assertSame($expectedComplete, $case->isProcessingCompleteNative(), $case->name);

            foreach ([true, false, null] as $toggle) {
                CollectionFileCheckStatus::setUseRustFfi($toggle);
                $this->assertSame($expectedDesc, $case->description());
                $this->assertSame($expectedComplete, $case->isProcessingComplete());
            }
        }

        $expectedReady = CollectionFileCheckStatus::readyForReleasePhp();
        $this->assertSame($expectedReady, CollectionFileCheckStatus::readyForReleaseNative());

        $expectedInProgress = CollectionFileCheckStatus::inProgressPhp();
        $this->assertSame($expectedInProgress, CollectionFileCheckStatus::inProgressNative());

        foreach ([true, false, null] as $toggle) {
            CollectionFileCheckStatus::setUseRustFfi($toggle);
            $this->assertSame($expectedReady, CollectionFileCheckStatus::readyForRelease());
            $this->assertSame($expectedInProgress, CollectionFileCheckStatus::inProgress());
        }
    }

    // ---------------------------------------------------------------------
    // 6. Category::isParent container regression
    // ---------------------------------------------------------------------

    public function test_category_is_parent_works_when_container_lacks_cache_binding(): void
    {
        $origApp = Facade::getFacadeApplication();
        $origResolver = RootCategory::getConnectionResolver();
        $origUseRust = Category::$useRustFfi;

        try {
            $facadeAppProp = new \ReflectionProperty(Facade::class, 'app');
            foreach ([new Container, new Application] as $bareContainer) {
                $facadeAppProp->setValue(null, $bareContainer);
                Facade::clearResolvedInstances();
                $this->assertFalse($bareContainer->bound('cache'));

                foreach ([false, true] as $useRust) {
                    Category::setUseRustFfi($useRust);
                    $this->assertTrue(Category::isParent(Category::TV_ROOT));
                    $this->assertFalse(Category::isParent(Category::TV_HD));
                }
            }
        } finally {
            Category::setUseRustFfi($origUseRust);
            Facade::setFacadeApplication($origApp);
            Facade::clearResolvedInstances();
            RootCategory::setConnectionResolver($origResolver);
        }
    }

    // ---------------------------------------------------------------------
    // 7. C-ABI contract & Fallback
    // ---------------------------------------------------------------------

    private static function ffiCall(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    public function test_rust_symbols_resolve_and_honor_contract_codes(): void
    {
        $writerFfi = RustPipelineLibrary::resolveDomain('metadata', TvLookupOutcomeWriter::FFI_CDEF);
        $this->assertNotNull($writerFfi);
        $this->assertSame(1, self::ffiCall($writerFfi, 'nntmux_tv_outcome_has_link_changed', 0, 0, 15, 42));
        $this->assertSame(-1, self::ffiCall($writerFfi, 'nntmux_tv_outcome_should_bump_cache', 1, null, 7));
        $this->assertSame(-1, self::ffiCall($writerFfi, 'nntmux_tv_outcome_resolve', 0, 0, 0, 'matched', 7, 1, 2, 0, 0, null, null, null, null, null));

        $contextFfi = RustPipelineLibrary::resolveDomain('metadata', TvReleaseContext::FFI_CDEF);
        $this->assertNotNull($contextFfi);
        $this->assertSame(-1, self::ffiCall($contextFfi, 'nntmux_tv_release_context_from_serialized', null, 5, null, null, null, null, null, null, null, 0));

        $bookFfi = RustPipelineLibrary::resolveDomain('metadata', BookProcessingCandidateQuery::FFI_CDEF);
        $this->assertNotNull($bookFfi);
        $this->assertSame(300, self::ffiCall($bookFfi, 'nntmux_book_candidate_retry_delay', 1, 0));
        $this->assertLessThan(-1, self::ffiCall($bookFfi, 'nntmux_book_candidate_sql_condition', 0, 1, 0, null, 0));
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_candidate_sql_condition', 99, 1, 0, null, 0));

        $seriesFfi = RustPipelineLibrary::resolveDomain('metadata', SeriesReleaseService::FFI_CDEF);
        $this->assertNotNull($seriesFfi);
        $this->assertSame(-1, self::ffiCall($seriesFfi, 'nntmux_series_plan_season_window', 1, 1, 0, 10, null, null, null, null, null, null));
        $this->assertSame(-1, self::ffiCall($seriesFfi, 'nntmux_series_has_valid_fallback_episode', 99, 0, 0, 0, null, 0));
        $this->assertSame(-1, self::ffiCall($seriesFfi, 'nntmux_series_normalize_category_ids', null, 4, null, 0));

        $enumFfi = RustPipelineLibrary::resolveDomain('metadata', CollectionFileCheckStatus::FFI_CDEF);
        $this->assertNotNull($enumFfi);
        $this->assertSame(-15, self::ffiCall($enumFfi, 'nntmux_collection_file_check_description', 3, null, 0));
        $this->assertSame(-1, self::ffiCall($enumFfi, 'nntmux_collection_file_check_description', 999, null, 0));
        $this->assertSame(-1, self::ffiCall($enumFfi, 'nntmux_collection_file_check_classify', 999));
        $this->assertSame(-1, self::ffiCall($enumFfi, 'nntmux_collection_file_check_list_values', 9, null, 0));
    }

    public function test_native_methods_fall_back_to_php_when_library_unavailable(): void
    {
        foreach (self::CLASS_TOGGLED as $class) {
            (new \ReflectionProperty($class, 'ffi'))->setValue(null, false);
            $class::setUseRustFfi(true);
        }
        CollectionFileCheckStatus::setFfiForTesting(false);
        CollectionFileCheckStatus::setUseRustFfi(true);

        $this->assertTrue(TvLookupOutcomeWriter::hasLinkChangedNative(0, 0, 10, 20));
        $this->assertTrue(TvLookupOutcomeWriter::shouldBumpCacheVersionNative(true, 'matched'));
        $outcome = TvLookupOutcomeWriter::resolveOutcomeNative(0, 0, 0, TvProcessingResult::matched(10, 20, 'TVDB'));
        $this->assertSame(20, $outcome['tv_episodes_id']);

        $ctx = TvReleaseContext::fromReleaseNative(['id' => 5, 'searchname' => 'Show.S01E01', 'groups_id' => 2, 'categories_id' => 5040]);
        $this->assertSame(5, $ctx->releaseId);

        $this->assertStringContainsString('7000', BookProcessingCandidateQuery::categoryConditionNative());
        $this->assertSame('0 = 1', BookProcessingCandidateQuery::metadataConditionNative(0));
        $this->assertSame('0 = 1', BookProcessingCandidateQuery::normalizationConditionNative(0));
        $this->assertSame(900, BookProcessingCandidateQuery::retryDelayNative(2, 0));

        $plan = SeriesReleaseService::planSeasonWindowNative(2, 3, 1, 4);
        $this->assertSame(5, $plan['total']);
        $this->assertSame([1 => 5], SeriesReleaseService::mergeSeasonCountsNative([1 => 2], [1 => 3]));
        $this->assertTrue(SeriesReleaseService::hasValidFallbackEpisodeNative(1, 2));
        $this->assertSame([5030, 5040], SeriesReleaseService::normalizeCategoryIdsNative(['5030', 5040]));

        $this->assertSame('Size calculated', CollectionFileCheckStatus::Sized->descriptionNative());
        $this->assertTrue(CollectionFileCheckStatus::Inserted->isProcessingCompleteNative());
        $this->assertSame([CollectionFileCheckStatus::Sized], CollectionFileCheckStatus::readyForReleaseNative());
        $this->assertCount(5, CollectionFileCheckStatus::inProgressNative());
    }

    // ---------------------------------------------------------------------
    // 8. Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_TV_BOOK_SERIES_LOOKUP_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_TV_BOOK_SERIES_LOOKUP_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $matchedResult = TvProcessingResult::matched(15, 42, 'TVDB');
        $epMissingResult = TvProcessingResult::episodeMissing(15, 'TVDB', [], false, null);
        $transientResult = TvProcessingResult::transient('TVMaze', 120);

        $commonReleaseData = [
            'id' => 42,
            'searchname' => 'Severance.S02E01.1080p.WEB.H264-GROUP',
            'groups_id' => 3,
            'categories_id' => 5040,
            'videos_id' => 15,
            'tv_episodes_id' => 0,
            'guid' => 'deadbeef1234567890abcdef12345678',
            'leftguid' => 'd',
        ];
        $uncommonReleaseData = [
            'id' => '987654',
            'searchname' => str_repeat('Long.Show.Title.', 8).'S12E24.2160p.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-SCENE',
            'groups_id' => '19',
            'categories_id' => '5045',
            'videos_id' => '0',
            'tv_episodes_id' => '-11',
            'guid' => sha1('uncommon'),
            'leftguid' => substr(sha1('uncommon'), 0, 1),
        ];

        $workloads = [
            'TvLookupOutcomeWriter::resolveOutcome' => [
                TvLookupOutcomeWriter::class,
                fn () => [
                    TvLookupOutcomeWriter::resolveOutcome(0, 0, 0, $matchedResult),
                    TvLookupOutcomeWriter::resolveOutcome(15, -11, 1, $epMissingResult),
                ],
                fn () => [
                    TvLookupOutcomeWriter::resolveOutcome(0, 0, 7, $transientResult),
                    TvLookupOutcomeWriter::resolveOutcome(15, -11, 5, $epMissingResult),
                ],
            ],
            'TvLookupOutcomeWriter::hasLinkChanged' => [
                TvLookupOutcomeWriter::class,
                fn () => [
                    TvLookupOutcomeWriter::hasLinkChanged(0, 0, 15, 42),
                    TvLookupOutcomeWriter::hasLinkChanged(0, 0, 0, -10),
                ],
                fn () => [
                    TvLookupOutcomeWriter::hasLinkChanged(15, 42, 15, -11),
                    TvLookupOutcomeWriter::hasLinkChanged(15, -11, 15, -12),
                ],
            ],
            'TvLookupOutcomeWriter::shouldBumpCacheVersion' => [
                TvLookupOutcomeWriter::class,
                fn () => [
                    TvLookupOutcomeWriter::shouldBumpCacheVersion(true, 'matched'),
                    TvLookupOutcomeWriter::shouldBumpCacheVersion(false, 'not_found'),
                ],
                fn () => [
                    TvLookupOutcomeWriter::shouldBumpCacheVersion(true, 'season_pack'),
                    TvLookupOutcomeWriter::shouldBumpCacheVersion(true, 'episode_missing'),
                ],
            ],
            'TvReleaseContext::fromRelease' => [
                TvReleaseContext::class,
                fn () => TvReleaseContext::fromRelease($commonReleaseData)->releaseId,
                fn () => TvReleaseContext::fromRelease($uncommonReleaseData)->releaseId,
            ],
            'BookProcessingCandidateQuery::workCondition' => [
                BookProcessingCandidateQuery::class,
                fn () => [
                    BookProcessingCandidateQuery::workCondition(1),
                    BookProcessingCandidateQuery::metadataCondition(1),
                ],
                fn () => [
                    BookProcessingCandidateQuery::workCondition(2),
                    BookProcessingCandidateQuery::workCondition(0),
                ],
            ],
            'BookProcessingCandidateQuery::retryDelay' => [
                BookProcessingCandidateQuery::class,
                fn () => [
                    BookProcessingCandidateQuery::retryDelay(1, 0),
                    BookProcessingCandidateQuery::retryDelay(2, 0),
                ],
                fn () => [
                    BookProcessingCandidateQuery::retryDelay(5, 36000),
                    BookProcessingCandidateQuery::retryDelay(3, 120),
                ],
            ],
            'SeriesReleaseService::planSeasonWindow' => [
                SeriesReleaseService::class,
                fn () => [
                    SeriesReleaseService::planSeasonWindow(12, 3, 0, 20),
                    SeriesReleaseService::planSeasonWindow(5, 10, 0, 10),
                ],
                fn () => [
                    SeriesReleaseService::planSeasonWindow(5, 25, 10, 15),
                    SeriesReleaseService::planSeasonWindow(8, 12, 5, 10),
                ],
            ],
            'SeriesReleaseService::mergeSeasonCounts' => [
                SeriesReleaseService::class,
                fn () => SeriesReleaseService::mergeSeasonCounts([1 => 10, 2 => 8, 3 => 6], [2 => 2, 4 => 5]),
                fn () => SeriesReleaseService::mergeSeasonCounts(
                    array_combine(range(20, 1), range(1, 20)),
                    array_combine(range(10, 30), range(5, 25)),
                ),
            ],
            'SeriesReleaseService::hasValidFallbackEpisode' => [
                SeriesReleaseService::class,
                fn () => [
                    SeriesReleaseService::hasValidFallbackEpisode(1, 2),
                    SeriesReleaseService::hasValidFallbackEpisode(null, null),
                ],
                fn () => [
                    SeriesReleaseService::hasValidFallbackEpisode('0', 'E01'),
                    SeriesReleaseService::hasValidFallbackEpisode('', '0'),
                ],
            ],
            'SeriesReleaseService::normalizeCategoryIds' => [
                SeriesReleaseService::class,
                fn () => SeriesReleaseService::normalizeCategoryIds([5010, 5030, 5040, 5045]),
                fn () => SeriesReleaseService::normalizeCategoryIds(['5010', '5020', '5030', '5040', '5045', '5060', '5070', '5080', '5090', '5999']),
            ],
            'CollectionFileCheckStatus::description' => [
                CollectionFileCheckStatus::class,
                fn () => [
                    CollectionFileCheckStatus::Default->description(),
                    CollectionFileCheckStatus::Sized->description(),
                ],
                fn () => [
                    CollectionFileCheckStatus::TempComplete->description(),
                    CollectionFileCheckStatus::ZeroPart->description(),
                ],
            ],
            'CollectionFileCheckStatus::isProcessingComplete' => [
                CollectionFileCheckStatus::class,
                fn () => [
                    CollectionFileCheckStatus::Default->isProcessingComplete(),
                    CollectionFileCheckStatus::Inserted->isProcessingComplete(),
                ],
                fn () => [
                    CollectionFileCheckStatus::Delete->isProcessingComplete(),
                    CollectionFileCheckStatus::ZeroPart->isProcessingComplete(),
                ],
            ],
            'CollectionFileCheckStatus::inProgress' => [
                CollectionFileCheckStatus::class,
                fn () => [
                    CollectionFileCheckStatus::readyForRelease(),
                    CollectionFileCheckStatus::inProgress(),
                ],
                fn () => [
                    CollectionFileCheckStatus::inProgress(),
                    CollectionFileCheckStatus::readyForRelease(),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1605 + $rep);
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
