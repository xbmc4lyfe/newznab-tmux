<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\ReprocessMissingEpisodes;
use App\Console\Commands\RequeueFailedBooks;
use App\Console\Commands\SteamLookupGame;
use App\Http\Controllers\Admin\AdminAnidbController;
use App\Http\Controllers\Admin\AdminBookController;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_ADMIN_ANIDB_BOOK_MEDIA_COMMANDS_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 2.607 ms, Rust FFI median = 5.870 ms (0.44x; per-call FFI marshaling overhead across 13 sub-microsecond controller/command helpers outweighs string/timestamp formatting savings)
 * - Round 2: PHP median = 2.713 ms, Rust FFI median = 6.167 ms (0.44x)
 * - Round 3: PHP median = 2.753 ms, Rust FFI median = 5.972 ms (0.46x)
 * - Overall: PHP stays the production default (`usesRustFfi() === false`) across all 5 classes because the 1.5x speedup threshold is not met.
 */
class RustAdminAnidbBookMediaCommandsParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_admin_anidb_normalize_search_filter(
        const char *filter_ptr,
        size_t filter_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_anidb_resolve_action_and_title(
        const char *context_ptr,
        size_t context_len,
        const char *action_ptr,
        size_t action_len,
        int64_t id,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_anidb_normalize_update_id(
        const char *anidbid_ptr,
        size_t anidbid_len,
        int64_t fallback_id,
        int64_t *out_id
    );
    int32_t nntmux_admin_book_normalize_edit_fields(
        const char *publishdate_ptr,
        size_t publishdate_len,
        uint32_t is_utc,
        int64_t has_cover,
        int64_t *out_scalars
    );
    ssize_t nntmux_steam_lookup_format_basic_info(
        uint32_t has_title,
        const char *title_ptr,
        size_t title_len,
        uint32_t has_steamid,
        const char *steamid_ptr,
        size_t steamid_len,
        uint32_t has_type,
        const char *type_ptr,
        size_t type_len,
        uint32_t has_publisher,
        const char *publisher_ptr,
        size_t publisher_len,
        const char *developers_ptr,
        size_t developers_len,
        uint32_t has_releasedate,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t has_genres,
        const char *genres_ptr,
        size_t genres_len,
        const char *platforms_ptr,
        size_t platforms_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_steam_lookup_format_price_rows(
        uint32_t has_currency,
        const char *currency_ptr,
        size_t currency_len,
        uint32_t has_final_formatted,
        const char *final_formatted_ptr,
        size_t final_formatted_len,
        double final_price,
        double initial_price,
        int64_t discount_percent,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_steam_lookup_format_reviews_rows(
        uint32_t has_rating,
        const char *rating_ptr,
        size_t rating_len,
        int64_t total_positive,
        int64_t total_negative,
        int64_t total_reviews,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_reprocess_missing_episodes_process_count(
        int64_t limit,
        int64_t total_count,
        int64_t *out_count
    );
    ssize_t nntmux_reprocess_missing_episodes_truncate_search_name(
        const char *search_name_ptr,
        size_t search_name_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_reprocess_missing_episodes_format_summary(
        int64_t processed,
        int64_t matched,
        int64_t failed,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_requeue_failed_books_evaluate_release(
        uint32_t has_bookinfo_id,
        int64_t bookinfo_id,
        const char *current_searchname_ptr,
        size_t current_searchname_len,
        uint32_t has_normalized,
        const char *normalized_ptr,
        size_t normalized_len,
        int64_t *out_flags
    );
    ssize_t nntmux_requeue_failed_books_format_dry_run_rename(
        int64_t release_id,
        const char *old_name_ptr,
        size_t old_name_len,
        const char *new_name_ptr,
        size_t new_name_len,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_requeue_failed_books_format_summary(
        uint32_t dry_run,
        int64_t total,
        int64_t queued,
        int64_t renamed,
        int64_t skipped,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    protected function tearDown(): void
    {
        AdminAnidbController::resetRustFfi();
        AdminBookController::resetRustFfi();
        SteamLookupGame::resetRustFfi();
        ReprocessMissingEpisodes::resetRustFfi();
        RequeueFailedBooks::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            AdminAnidbController::class,
            AdminBookController::class,
            SteamLookupGame::class,
            ReprocessMissingEpisodes::class,
            RequeueFailedBooks::class,
        ];

        foreach ($classes as $class) {
            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP implementation");

            $class::enableRustFfi();
            $this->assertTrue($class::usesRustFfi());

            $class::disableRustFfi();
            $this->assertFalse($class::usesRustFfi());

            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());

            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());

            $class::resetRustFfi();
            $this->assertFalse($class::usesRustFfi());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string, 3: int, 4: array<string, mixed>, 5: int}>
     */
    public static function anidbControllerProvider(): array
    {
        return [
            'index_with_filter_and_full_payload' => [
                'Cowboy Bebop',
                'index',
                'view',
                0,
                [
                    'anidbid' => '123',
                    'title' => 'Cowboy Bebop',
                    'type' => 'TV Series',
                    'startdate' => '1998-04-03',
                    'enddate' => '1999-04-24',
                    'related' => 'Knockin on Heavens Door',
                    'similar' => 'Samurai Champloo',
                    'creators' => 'Watanabe Shinichiro',
                    'description' => 'Space bounty hunters.',
                    'rating' => '880',
                    'categories' => 'Sci-Fi, Space',
                    'characters' => 'Spike Spiegel',
                    'epnos' => '1-26',
                    'airdates' => '1998',
                    'episodetitles' => 'Asteroid Blues',
                ],
                99,
            ],
            'edit_view_with_positive_id_and_fallback_id' => [
                '',
                'edit',
                'view',
                42,
                [
                    'title' => 'Trigun',
                ],
                42,
            ],
            'edit_submit_with_padded_id' => [
                '0',
                'edit',
                'submit',
                77,
                [
                    'anidbid' => "  456\n",
                    'title' => 'Serial Experiments Lain',
                    'rating' => 850,
                ],
                77,
            ],
            'edit_view_with_zero_id_redirects' => [
                null,
                'edit',
                'view',
                0,
                ['anidbid' => 'not-an-int'],
                15,
            ],
            'destroy_with_nonzero_and_zero_ids' => [
                '攻殻機動隊',
                'destroy',
                'view',
                1001,
                ['anidbid' => 1001],
                1001,
            ],
            'boolean_and_formfeed_or_nul_trimmed_anidbid' => [
                '',
                'edit',
                'submit',
                99,
                ['anidbid' => true],
                99,
            ],
            'nul_trimmed_anidbid_and_formfeed_fallback' => [
                '',
                'edit',
                'submit',
                99,
                ['anidbid' => "\x00777\x00"],
                99,
            ],
            'formfeed_anidbid_falls_back' => [
                '',
                'edit',
                'submit',
                99,
                ['anidbid' => "\x0c777"],
                99,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('anidbControllerProvider')]
    public function test_admin_anidb_controller_helpers_parity(
        mixed $animeTitle,
        string $context,
        string $action,
        int $id,
        array $input,
        int $fallbackId,
    ): void {
        $this->requireMetadataFfi();

        $phpFilter = AdminAnidbController::normalizeSearchFilterPhp($animeTitle);
        $nativeFilter = AdminAnidbController::normalizeSearchFilterNative($animeTitle);
        $this->assertSame($phpFilter, $nativeFilter);

        $phpState = AdminAnidbController::resolveActionAndTitlePhp($context, $action, $id);
        $nativeState = AdminAnidbController::resolveActionAndTitleNative($context, $action, $id);
        $this->assertSame($phpState, $nativeState);

        $phpPayload = AdminAnidbController::normalizeUpdatePayloadPhp($input, $fallbackId);
        $nativePayload = AdminAnidbController::normalizeUpdatePayloadNative($input, $fallbackId);
        $this->assertSame($phpPayload, $nativePayload);

        foreach ([false, true] as $useRust) {
            AdminAnidbController::setUseRustFfi($useRust);
            $this->assertSame($phpFilter, AdminAnidbController::normalizeSearchFilter($animeTitle));
            $this->assertSame($phpState, AdminAnidbController::resolveActionAndTitle($context, $action, $id));
            $this->assertSame($phpPayload, AdminAnidbController::normalizeUpdatePayload($input, $fallbackId));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: bool|int}>
     */
    public static function bookEditFieldsProvider(): array
    {
        return [
            'valid_iso_date_with_cover' => ['2024-02-29', '2020-01-01', true],
            'valid_iso_datetime_without_cover' => ['2024-03-15 14:30:45', 1577836800, false],
            'empty_publishdate_uses_fallback' => ['', '2021-07-01', 1],
            'zero_string_publishdate_uses_fallback' => ['0', '2021-07-01', 0],
            'invalid_publishdate_uses_fallback' => ['not-a-valid-date', null, true],
            'epoch_zero_uses_fallback' => ['1970-01-01 00:00:00', '2019-04-01', false],
            'slash_date_falls_back_to_php_carbon' => ['2024/03/15', '2020-01-01', 2],
            'formfeed_prefixed_date_falls_back_to_php' => ["\x0c2024-02-29", '2020-01-01', true],
        ];
    }

    #[DataProvider('bookEditFieldsProvider')]
    public function test_admin_book_controller_normalize_edit_fields_parity(
        mixed $publishdateInput,
        mixed $fallbackPublishDate,
        bool|int $hasCover,
    ): void {
        $this->requireMetadataFfi();

        $php = AdminBookController::normalizeBookEditFieldsPhp($publishdateInput, $fallbackPublishDate, $hasCover);
        $native = AdminBookController::normalizeBookEditFieldsNative($publishdateInput, $fallbackPublishDate, $hasCover);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminBookController::setUseRustFfi($useRust);
            $this->assertSame(
                $php,
                AdminBookController::normalizeBookEditFields($publishdateInput, $fallbackPublishDate, $hasCover)
            );
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function steamLookupFormattingProvider(): array
    {
        return [
            'complete_game_with_discount_and_reviews' => [
                [
                    'title' => 'Half-Life 2',
                    'steamid' => 220,
                    'type' => 'game',
                    'publisher' => 'Valve',
                    'developers' => ['Valve', 'Hidden Path'],
                    'releasedate' => '2004-11-16',
                    'genres' => 'Action, Sci-Fi',
                    'platforms' => ['Windows', 'Mac', 'Linux'],
                ],
                [
                    'currency' => 'USD',
                    'final' => 4.99,
                    'initial' => 9.99,
                    'discount_percent' => 50,
                    'final_formatted' => null,
                ],
                [
                    'review_score_desc' => 'Overwhelmingly Positive',
                    'total_positive' => 145678,
                    'total_negative' => 3210,
                    'total_reviews' => 148888,
                ],
            ],
            'sparse_game_free_price_and_empty_reviews' => [
                [],
                [
                    'currency' => 'EUR',
                    'final' => 0,
                    'initial' => 0,
                    'discount_percent' => 0,
                    'final_formatted' => 'Free',
                ],
                [],
            ],
            'dlc_type_and_zero_developers_fallback' => [
                [
                    'title' => 'Cyberpunk 2077: Phantom Liberty',
                    'steamid' => '2138330',
                    'type' => 'dlc',
                    'publisher' => 'CD PROJEKT RED',
                    'developers' => ['0'],
                    'releasedate' => '2023-09-26',
                    'genres' => 'RPG',
                    'platforms' => ['Windows'],
                ],
                [
                    'final' => 29.99,
                    'initial' => 29.99,
                    'discount_percent' => 0,
                ],
                [
                    'review_score_desc' => 'Very Positive',
                    'total_positive' => 999,
                    'total_negative' => 42,
                    'total_reviews' => 1041,
                ],
            ],
            'negative_zero_and_large_float_prices_fallback_parity' => [
                ['title' => 'Edge Price'],
                [
                    'currency' => 'USD',
                    'final' => -0.001,
                    'initial' => 1e12,
                    'discount_percent' => 10,
                ],
                ['total_positive' => 1, 'total_negative' => 0, 'total_reviews' => 1],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $price
     * @param  array<string, mixed>  $reviews
     */
    #[DataProvider('steamLookupFormattingProvider')]
    public function test_steam_lookup_game_formatting_parity(
        array $details,
        array $price,
        array $reviews,
    ): void {
        $this->requireMetadataFfi();

        $phpBasic = SteamLookupGame::formatBasicInfoRowsPhp($details);
        $nativeBasic = SteamLookupGame::formatBasicInfoRowsNative($details);
        $this->assertSame($phpBasic, $nativeBasic);

        $phpPrice = SteamLookupGame::formatPriceRowsPhp($price);
        $nativePrice = SteamLookupGame::formatPriceRowsNative($price);
        $this->assertSame($phpPrice, $nativePrice);

        $phpReviews = SteamLookupGame::formatReviewsRowsPhp($reviews);
        $nativeReviews = SteamLookupGame::formatReviewsRowsNative($reviews);
        $this->assertSame($phpReviews, $nativeReviews);

        foreach ([false, true] as $useRust) {
            SteamLookupGame::setUseRustFfi($useRust);
            $this->assertSame($phpBasic, SteamLookupGame::formatBasicInfoRows($details));
            $this->assertSame($phpPrice, SteamLookupGame::formatPriceRows($price));
            $this->assertSame($phpReviews, SteamLookupGame::formatReviewsRows($reviews));
        }

        $extremeSummaryPhp = ReprocessMissingEpisodes::formatSummaryRowPhp(1, 1_000_000_000_000_000, 0);
        $extremeSummaryNative = ReprocessMissingEpisodes::formatSummaryRowNative(1, 1_000_000_000_000_000, 0);
        $this->assertSame($extremeSummaryPhp, $extremeSummaryNative);
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int|string, 3: int|string|null, 4: string, 5: int, 6: int, 7: int}>
     */
    public static function reprocessMissingEpisodesProvider(): array
    {
        return [
            'limit_clamps_total_and_long_ascii_name_truncated' => [
                10,
                45,
                1001,
                371980,
                'Severance.S02E01.Hello.Ms.Cobel.2160p.ATVP.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-NTb',
                1250,
                875,
                375,
            ],
            'zero_limit_keeps_total_and_exact_55_chars_not_truncated' => [
                0,
                25,
                1002,
                44933,
                str_repeat('A', 55),
                3,
                1,
                2,
            ],
            '56_char_multibyte_unicode_truncated_and_two_thirds_rate' => [
                50,
                12,
                1003,
                95396,
                str_repeat('あ', 56),
                3,
                2,
                1,
            ],
            'zero_processed_yields_zero_percent' => [
                -5,
                8,
                1004,
                null,
                'Short.Show.S01E01.720p',
                0,
                0,
                0,
            ],
            'fractional_single_decimal_match_rate' => [
                5,
                5,
                1005,
                123,
                'Another.Show.S03E09',
                8,
                1,
                7,
            ],
        ];
    }

    #[DataProvider('reprocessMissingEpisodesProvider')]
    public function test_reprocess_missing_episodes_helpers_parity(
        int $limit,
        int $totalCount,
        int|string $id,
        int|string|null $videoId,
        string $searchName,
        int $processed,
        int $matched,
        int $failed,
    ): void {
        $this->requireMetadataFfi();

        $phpCount = ReprocessMissingEpisodes::resolveProcessCountPhp($limit, $totalCount);
        $nativeCount = ReprocessMissingEpisodes::resolveProcessCountNative($limit, $totalCount);
        $this->assertSame($phpCount, $nativeCount);

        $phpSample = ReprocessMissingEpisodes::formatDryRunSampleRowPhp($id, $videoId, $searchName);
        $nativeSample = ReprocessMissingEpisodes::formatDryRunSampleRowNative($id, $videoId, $searchName);
        $this->assertSame($phpSample, $nativeSample);

        $phpSummary = ReprocessMissingEpisodes::formatSummaryRowPhp($processed, $matched, $failed);
        $nativeSummary = ReprocessMissingEpisodes::formatSummaryRowNative($processed, $matched, $failed);
        $this->assertSame($phpSummary, $nativeSummary);

        foreach ([false, true] as $useRust) {
            ReprocessMissingEpisodes::setUseRustFfi($useRust);
            $this->assertSame($phpCount, ReprocessMissingEpisodes::resolveProcessCount($limit, $totalCount));
            $this->assertSame($phpSample, ReprocessMissingEpisodes::formatDryRunSampleRow($id, $videoId, $searchName));
            $this->assertSame($phpSummary, ReprocessMissingEpisodes::formatSummaryRow($processed, $matched, $failed));
        }
    }

    /**
     * @return array<string, array{0: ?int, 1: string, 2: ?string, 3: string, 4: int, 5: bool, 6: int, 7: int, 8: int, 9: int}>
     */
    public static function requeueFailedBooksProvider(): array
    {
        return [
            'exhausted_book_with_normalized_rename_and_dry_run_summary' => [
                -3,
                'N:/NZB/12345/Dune.Frank.Herbert.epub',
                'Frank Herbert - Dune',
                '2026-03-01 12:00:00',
                42,
                true,
                100,
                80,
                35,
                20,
            ],
            'unmatched_minus_two_without_rename_and_live_summary' => [
                -2,
                'Clean Book Name',
                'Clean Book Name',
                '2026-03-01 12:00:00',
                77,
                false,
                50,
                40,
                12,
                10,
            ],
            'matched_book_with_obfuscated_rename_only' => [
                15,
                'N_NZB_999_Neuromancer',
                'William Gibson - Neuromancer',
                '2026-03-01 12:00:00',
                99,
                false,
                10,
                5,
                5,
                5,
            ],
            'null_bookinfo_without_normalized_name_produces_empty_updates' => [
                null,
                'Unchanged Name',
                null,
                '2026-03-01 12:00:00',
                1,
                true,
                1,
                0,
                0,
                1,
            ],
        ];
    }

    #[DataProvider('requeueFailedBooksProvider')]
    public function test_requeue_failed_books_helpers_parity(
        ?int $bookinfoId,
        string $currentSearchName,
        ?string $normalizedSearchName,
        string $normalizedAt,
        int $releaseId,
        bool $dryRun,
        int $total,
        int $queued,
        int $renamed,
        int $skipped,
    ): void {
        $this->requireMetadataFfi();

        $phpUpdates = RequeueFailedBooks::buildReleaseUpdatesPhp(
            $bookinfoId,
            $currentSearchName,
            $normalizedSearchName,
            $normalizedAt
        );
        $nativeUpdates = RequeueFailedBooks::buildReleaseUpdatesNative(
            $bookinfoId,
            $currentSearchName,
            $normalizedSearchName,
            $normalizedAt
        );
        $this->assertSame($phpUpdates, $nativeUpdates);

        $newName = $normalizedSearchName ?? $currentSearchName;
        $phpDryLine = RequeueFailedBooks::formatDryRunRenameLinePhp($releaseId, $currentSearchName, $newName);
        $nativeDryLine = RequeueFailedBooks::formatDryRunRenameLineNative($releaseId, $currentSearchName, $newName);
        $this->assertSame($phpDryLine, $nativeDryLine);

        $phpSummary = RequeueFailedBooks::formatSummaryMessagePhp($dryRun, $total, $queued, $renamed, $skipped);
        $nativeSummary = RequeueFailedBooks::formatSummaryMessageNative($dryRun, $total, $queued, $renamed, $skipped);
        $this->assertSame($phpSummary, $nativeSummary);

        foreach ([false, true] as $useRust) {
            RequeueFailedBooks::setUseRustFfi($useRust);
            $this->assertSame(
                $phpUpdates,
                RequeueFailedBooks::buildReleaseUpdates($bookinfoId, $currentSearchName, $normalizedSearchName, $normalizedAt)
            );
            $this->assertSame(
                $phpDryLine,
                RequeueFailedBooks::formatDryRunRenameLine($releaseId, $currentSearchName, $newName)
            );
            $this->assertSame(
                $phpSummary,
                RequeueFailedBooks::formatSummaryMessage($dryRun, $total, $queued, $renamed, $skipped)
            );
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        AdminAnidbController::setFfiForTesting(false);
        AdminAnidbController::enableRustFfi();
        $this->assertSame('Bebop', AdminAnidbController::normalizeSearchFilter('Bebop'));
        $this->assertSame(
            AdminAnidbController::resolveActionAndTitlePhp('edit', 'view', 10),
            AdminAnidbController::resolveActionAndTitle('edit', 'view', 10)
        );
        $this->assertSame(
            AdminAnidbController::normalizeUpdatePayloadPhp(['anidbid' => '10', 'title' => 'Bebop'], 10),
            AdminAnidbController::normalizeUpdatePayload(['anidbid' => '10', 'title' => 'Bebop'], 10)
        );

        AdminBookController::setFfiForTesting(false);
        AdminBookController::enableRustFfi();
        $this->assertSame(
            AdminBookController::normalizeBookEditFieldsPhp('2024-02-29', '2020-01-01', true),
            AdminBookController::normalizeBookEditFields('2024-02-29', '2020-01-01', true)
        );

        SteamLookupGame::setFfiForTesting(false);
        SteamLookupGame::enableRustFfi();
        $this->assertSame(
            SteamLookupGame::formatBasicInfoRowsPhp(['title' => 'Portal']),
            SteamLookupGame::formatBasicInfoRows(['title' => 'Portal'])
        );
        $this->assertSame(
            SteamLookupGame::formatPriceRowsPhp(['final' => 9.99, 'initial' => 19.99, 'discount_percent' => 50]),
            SteamLookupGame::formatPriceRows(['final' => 9.99, 'initial' => 19.99, 'discount_percent' => 50])
        );
        $this->assertSame(
            SteamLookupGame::formatReviewsRowsPhp(['review_score_desc' => 'Positive', 'total_positive' => 1000]),
            SteamLookupGame::formatReviewsRows(['review_score_desc' => 'Positive', 'total_positive' => 1000])
        );

        ReprocessMissingEpisodes::setFfiForTesting(false);
        ReprocessMissingEpisodes::enableRustFfi();
        $this->assertSame(5, ReprocessMissingEpisodes::resolveProcessCount(5, 20));
        $this->assertSame(
            ReprocessMissingEpisodes::formatDryRunSampleRowPhp(1, 2, str_repeat('X', 60)),
            ReprocessMissingEpisodes::formatDryRunSampleRow(1, 2, str_repeat('X', 60))
        );
        $this->assertSame(
            ReprocessMissingEpisodes::formatSummaryRowPhp(10, 5, 5),
            ReprocessMissingEpisodes::formatSummaryRow(10, 5, 5)
        );

        RequeueFailedBooks::setFfiForTesting(false);
        RequeueFailedBooks::enableRustFfi();
        $this->assertSame(
            RequeueFailedBooks::buildReleaseUpdatesPhp(-3, 'old', 'new', '2026-03-01 00:00:00'),
            RequeueFailedBooks::buildReleaseUpdates(-3, 'old', 'new', '2026-03-01 00:00:00')
        );
        $this->assertSame(
            RequeueFailedBooks::formatDryRunRenameLinePhp(1, 'old', 'new'),
            RequeueFailedBooks::formatDryRunRenameLine(1, 'old', 'new')
        );
        $this->assertSame(
            RequeueFailedBooks::formatSummaryMessagePhp(false, 10, 5, 2, 5),
            RequeueFailedBooks::formatSummaryMessage(false, 10, 5, 2, 5)
        );
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));

        $smallBuf = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $smallBuf[$i] = 'Z';
        }

        $lens2 = $ffi->new('size_t[2]');
        $lens4 = $ffi->new('size_t[4]');
        $lens8 = $ffi->new('size_t[8]');
        $scalars3 = $ffi->new('int64_t[3]');

        // 1. AdminAnidbController C-ABI
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_anidb_normalize_search_filter', null, 5, $smallBuf, 4));
        $this->assertSame(-6, (int) self::callFfi($ffi, 'nntmux_admin_anidb_normalize_search_filter', 'Cowboy', 6, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_anidb_resolve_action_and_title',
            'index',
            5,
            'view',
            4,
            0,
            $unalignedSizeT,
            $smallBuf,
            4
        ));
        $this->assertSame(-15, (int) self::callFfi(
            $ffi,
            'nntmux_admin_anidb_resolve_action_and_title',
            'index',
            5,
            'view',
            4,
            0,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_anidb_normalize_update_id',
            '123',
            3,
            0,
            $unalignedInt64
        ));

        // 2. AdminBookController C-ABI
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_book_normalize_edit_fields',
            '2024-01-01',
            10,
            2,
            1,
            $scalars3
        ));

        // 3. SteamLookupGame C-ABI
        $this->assertSame(-46, (int) self::callFfi(
            $ffi,
            'nntmux_steam_lookup_format_basic_info',
            1,
            'Portal',
            6,
            1,
            '400',
            3,
            1,
            'game',
            4,
            1,
            'Valve',
            5,
            'Valve',
            5,
            1,
            '2007-10-10',
            10,
            1,
            'Puzzle',
            6,
            'Windows',
            7,
            $lens8,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-7, (int) self::callFfi(
            $ffi,
            'nntmux_steam_lookup_format_price_rows',
            1,
            'USD',
            3,
            1,
            'Free',
            4,
            0.0,
            0.0,
            0,
            $lens4,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-13, (int) self::callFfi(
            $ffi,
            'nntmux_steam_lookup_format_reviews_rows',
            1,
            'Positive',
            8,
            10,
            1,
            11,
            $lens4,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 4. ReprocessMissingEpisodes C-ABI
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_reprocess_missing_episodes_process_count',
            5,
            10,
            $unalignedInt64
        ));
        $this->assertSame(-9, (int) self::callFfi(
            $ffi,
            'nntmux_reprocess_missing_episodes_truncate_search_name',
            'Severance',
            9,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-7, (int) self::callFfi(
            $ffi,
            'nntmux_reprocess_missing_episodes_format_summary',
            10,
            5,
            5,
            $lens4,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 5. RequeueFailedBooks C-ABI
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_requeue_failed_books_evaluate_release',
            2,
            -2,
            'a',
            1,
            1,
            'b',
            1,
            $unalignedInt64
        ));
        $this->assertSame(-35, (int) self::callFfi(
            $ffi,
            'nntmux_requeue_failed_books_format_dry_run_rename',
            1,
            'a',
            1,
            'b',
            1,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-93, (int) self::callFfi(
            $ffi,
            'nntmux_requeue_failed_books_format_summary',
            1,
            1,
            1,
            1,
            0,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_ADMIN_ANIDB_BOOK_MEDIA_COMMANDS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ADMIN_ANIDB_BOOK_MEDIA_COMMANDS_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $anidbInput = [
            'anidbid' => '123',
            'title' => 'Cowboy Bebop',
            'type' => 'TV Series',
            'startdate' => '1998-04-03',
            'enddate' => '1999-04-24',
            'related' => 'Knockin on Heavens Door',
            'similar' => 'Samurai Champloo',
            'creators' => 'Watanabe Shinichiro',
            'description' => 'Space bounty hunters.',
            'rating' => '880',
            'categories' => 'Sci-Fi, Space',
            'characters' => 'Spike Spiegel',
            'epnos' => '1-26',
            'airdates' => '1998',
            'episodetitles' => 'Asteroid Blues',
        ];
        $steamDetails = [
            'title' => 'Half-Life 2',
            'steamid' => 220,
            'type' => 'game',
            'publisher' => 'Valve',
            'developers' => ['Valve'],
            'releasedate' => '2004-11-16',
            'genres' => 'Action, Sci-Fi',
            'platforms' => ['Windows', 'Mac', 'Linux'],
        ];
        $steamPrice = [
            'currency' => 'USD',
            'final' => 4.99,
            'initial' => 9.99,
            'discount_percent' => 50,
            'final_formatted' => null,
        ];
        $steamReviews = [
            'review_score_desc' => 'Overwhelmingly Positive',
            'total_positive' => 145678,
            'total_negative' => 3210,
            'total_reviews' => 148888,
        ];
        $longSearchName = 'Severance.S02E01.Hello.Ms.Cobel.2160p.ATVP.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-NTb';

        $runBatch = static function (int $iterations) use ($anidbInput, $steamDetails, $steamPrice, $steamReviews, $longSearchName): void {
            for ($i = 0; $i < $iterations; $i++) {
                AdminAnidbController::normalizeSearchFilter('Cowboy Bebop');
                AdminAnidbController::resolveActionAndTitle('edit', 'view', 123);
                AdminAnidbController::normalizeUpdatePayload($anidbInput, 123);
                AdminBookController::normalizeBookEditFields('2024-02-29', '2020-01-01', true);
                SteamLookupGame::formatBasicInfoRows($steamDetails);
                SteamLookupGame::formatPriceRows($steamPrice);
                SteamLookupGame::formatReviewsRows($steamReviews);
                ReprocessMissingEpisodes::resolveProcessCount(10, 45);
                ReprocessMissingEpisodes::formatDryRunSampleRow(1001, 371980, $longSearchName);
                ReprocessMissingEpisodes::formatSummaryRow(1250, 875, 375);
                RequeueFailedBooks::buildReleaseUpdates(-3, 'N:/NZB/12345/Dune', 'Frank Herbert - Dune', '2026-03-01 12:00:00');
                RequeueFailedBooks::formatDryRunRenameLine(42, 'N:/NZB/12345/Dune', 'Frank Herbert - Dune');
                RequeueFailedBooks::formatSummaryMessage(false, 100, 80, 35, 20);
            }
        };

        $setAllModes = static function (bool $rust): void {
            AdminAnidbController::setUseRustFfi($rust);
            AdminBookController::setUseRustFfi($rust);
            SteamLookupGame::setUseRustFfi($rust);
            ReprocessMissingEpisodes::setUseRustFfi($rust);
            RequeueFailedBooks::setUseRustFfi($rust);
        };

        for ($w = 0; $w < 8; $w++) {
            $setAllModes(false);
            $runBatch(10);
            $setAllModes(true);
            $runBatch(10);
        }

        $rounds = 3;
        $samples = 41;
        $iterations = 200;
        $summary = [];

        for ($round = 1; $round <= $rounds; $round++) {
            $phpTimes = [];
            $rustTimes = [];

            for ($s = 0; $s < $samples; $s++) {
                $rustFirst = (($round + $s) % 2) === 1;
                if ($rustFirst) {
                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;
                } else {
                    $setAllModes(false);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $phpTimes[] = (hrtime(true) - $t0) / 1_000_000;

                    $setAllModes(true);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $rustTimes[] = (hrtime(true) - $t0) / 1_000_000;
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $speedup = $phpMedian / max(0.0001, $rustMedian);
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms, Rust median=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $rustMedian,
                $speedup
            );
        }

        fwrite(STDERR, "\n[Wave 29.4 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function requireMetadataFfi(): FFI
    {
        $envLib = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain(
            'metadata',
            self::DIRECT_CDEF,
            is_string($envLib) && $envLib !== '' ? $envLib : null,
        );
        if ($ffi === null) {
            $this->markTestSkipped('Rust metadata FFI library is not built or available.');
        }

        return $ffi;
    }
}
