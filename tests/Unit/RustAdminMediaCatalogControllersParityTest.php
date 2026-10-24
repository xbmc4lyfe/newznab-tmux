<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\AdminConsoleController;
use App\Http\Controllers\Admin\AdminGameController;
use App\Http\Controllers\Admin\AdminMovieController;
use App\Http\Controllers\Admin\AdminMusicController;
use App\Http\Controllers\Admin\AdminShowsController;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_ADMIN_MEDIA_CATALOG_CONTROLLERS_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.855 ms, Rust FFI median = 0.903 ms (0.95x; per-call FFI marshaling overhead on sub-microsecond controller helpers offsets the Carbon/strtotime savings)
 * - Round 2: PHP median = 0.841 ms, Rust FFI median = 0.901 ms (0.93x)
 * - Round 3: PHP median = 0.848 ms, Rust FFI median = 0.906 ms (0.94x)
 * - Overall: PHP stays the production default (`usesRustFfi() === false`) across all 5 classes because the 1.5x speedup threshold is not met.
 */
class RustAdminMediaCatalogControllersParityTest extends TestCase
{
    private const DIRECT_CDEF = <<<'CDEF'
    int32_t nntmux_admin_movie_is_numeric_imdb_id(
        const char *id_ptr,
        size_t id_len
    );
    ssize_t nntmux_admin_movie_build_image_keys(
        const char *id_ptr,
        size_t id_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_movie_normalize_update_flags(
        int64_t has_cover,
        int64_t has_backdrop,
        int64_t *out_flags
    );
    ssize_t nntmux_admin_shows_normalize_preview_ids(
        uint32_t has_tvdb,
        int64_t tvdb,
        uint32_t has_tvmaze,
        int64_t tvmaze,
        uint32_t has_tmdb,
        int64_t tmdb,
        uint32_t has_trakt,
        int64_t trakt,
        uint32_t has_tvrage,
        int64_t tvrage,
        uint32_t has_imdb,
        const char *imdb_ptr,
        size_t imdb_len,
        int64_t *out_ids,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_shows_format_create_flash(
        uint32_t existed,
        const char *title_ptr,
        size_t title_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_music_normalize_edit_fields(
        const char *salesrank_ptr,
        size_t salesrank_len,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        const char *genre_ptr,
        size_t genre_len,
        int64_t *out_scalars,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_console_normalize_edit_fields(
        const char *salesrank_ptr,
        size_t salesrank_len,
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        int64_t has_cover,
        int64_t *out_scalars,
        char *out_buf,
        size_t out_cap
    );
    ssize_t nntmux_admin_game_normalize_search_term(
        const char *search_ptr,
        size_t search_len,
        char *out_buf,
        size_t out_cap
    );
    int32_t nntmux_admin_game_parse_release_timestamp(
        const char *releasedate_ptr,
        size_t releasedate_len,
        uint32_t is_utc,
        int64_t *out_scalars
    );
    CDEF;

    protected function tearDown(): void
    {
        AdminMovieController::resetRustFfi();
        AdminShowsController::resetRustFfi();
        AdminMusicController::resetRustFfi();
        AdminConsoleController::resetRustFfi();
        AdminGameController::resetRustFfi();

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        $classes = [
            AdminMovieController::class,
            AdminShowsController::class,
            AdminMusicController::class,
            AdminConsoleController::class,
            AdminGameController::class,
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
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function movieImdbIdProvider(): array
    {
        return [
            'seven_digits_with_leading_zero' => ['0133093', true],
            'eight_digits' => ['10872600', true],
            'surrounding_whitespace' => ["  0133093\n", true],
            'integer_value' => [133093, true],
            'signed_numeric_string' => ['-123', true],
            'decimal_numeric_string' => ['12.34', true],
            'tt_prefixed_rejected' => ['tt0133093', false],
            'empty_string_rejected' => ['', false],
            'whitespace_only_rejected' => ['   ', false],
            'embedded_nul_rejected' => ["0133\093", false],
            'null_rejected' => [null, false],
        ];
    }

    #[DataProvider('movieImdbIdProvider')]
    public function test_admin_movie_controller_imdb_validation_parity(mixed $id, bool $expected): void
    {
        $this->requireMetadataFfi();

        $php = AdminMovieController::isValidNumericImdbIdPhp($id);
        $native = AdminMovieController::isValidNumericImdbIdNative($id);
        $this->assertSame($expected, $php);
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminMovieController::setUseRustFfi($useRust);
            $this->assertSame($php, AdminMovieController::isValidNumericImdbId($id));
        }
    }

    /**
     * @return array<string, array{0: int|string, 1: array<string, mixed>, 2: bool|int, 3: bool|int}>
     */
    public static function movieImageKeysAndPayloadProvider(): array
    {
        return [
            'standard_movie_with_cover_and_backdrop' => [
                '0133093',
                [
                    'actors' => 'Keanu Reeves, Laurence Fishburne',
                    'director' => 'Lana Wachowski, Lilly Wachowski',
                    'genre' => 'Action, Sci-Fi',
                    'language' => 'English',
                    'plot' => 'A computer hacker learns about the true nature of reality.',
                    'rating' => '8.7',
                    'tagline' => 'Welcome to the Real World.',
                    'title' => 'The Matrix',
                    'year' => '1999',
                ],
                true,
                1,
            ],
            'integer_id_with_missing_optional_fields' => [
                133093,
                ['title' => 'The Matrix'],
                false,
                0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('movieImageKeysAndPayloadProvider')]
    public function test_admin_movie_controller_image_keys_and_update_payload_parity(
        int|string $id,
        array $input,
        bool|int $hasCover,
        bool|int $hasBackdrop,
    ): void {
        $this->requireMetadataFfi();

        $phpKeys = AdminMovieController::buildMovieImageKeysPhp($id);
        $nativeKeys = AdminMovieController::buildMovieImageKeysNative($id);
        $this->assertSame($phpKeys, $nativeKeys);

        $phpPayload = AdminMovieController::buildMovieUpdatePayloadPhp($id, $input, $hasCover, $hasBackdrop);
        $nativePayload = AdminMovieController::buildMovieUpdatePayloadNative($id, $input, $hasCover, $hasBackdrop);
        $this->assertSame($phpPayload, $nativePayload);

        foreach ([false, true] as $useRust) {
            AdminMovieController::setUseRustFfi($useRust);
            $this->assertSame($phpKeys, AdminMovieController::buildMovieImageKeys($id));
            $this->assertSame($phpPayload, AdminMovieController::buildMovieUpdatePayload($id, $input, $hasCover, $hasBackdrop));
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: ?string}>
     */
    public static function showsPreviewAndFlashProvider(): array
    {
        return [
            'complete_preview_and_existed_flash' => [
                [
                    'title' => 'Severance',
                    'summary' => 'Mark leads a team of office workers.',
                    'started' => '2022-02-18',
                    'publisher' => 'Apple TV+',
                    'poster' => 'https://example.test/poster.jpg',
                    'tvdb' => 371980,
                    'tvmaze' => 44933,
                    'tmdb' => 95396,
                    'trakt' => 156600,
                    'imdb' => '11280740',
                    'tvrage' => 0,
                ],
                true,
                'Severance',
            ],
            'sparse_preview_defaults_and_added_flash' => [
                [
                    'title' => 'The Expanse — 宇宙',
                ],
                false,
                'The Expanse — 宇宙',
            ],
            'empty_preview_and_null_title_flash' => [
                [],
                false,
                null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    #[DataProvider('showsPreviewAndFlashProvider')]
    public function test_admin_shows_controller_preview_and_flash_parity(
        array $preview,
        bool $existed,
        ?string $title,
    ): void {
        $this->requireMetadataFfi();

        $phpPreview = AdminShowsController::normalizeLookupPreviewPayloadPhp($preview);
        $nativePreview = AdminShowsController::normalizeLookupPreviewPayloadNative($preview);
        $this->assertSame($phpPreview, $nativePreview);

        $phpFlash = AdminShowsController::formatCreateFlashPhp($existed, $title);
        $nativeFlash = AdminShowsController::formatCreateFlashNative($existed, $title);
        $this->assertSame($phpFlash, $nativeFlash);

        foreach ([false, true] as $useRust) {
            AdminShowsController::setUseRustFfi($useRust);
            $this->assertSame($phpPreview, AdminShowsController::normalizeLookupPreviewPayload($preview));
            $this->assertSame($phpFlash, AdminShowsController::formatCreateFlash($existed, $title));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: ?string, 3: mixed}>
     */
    public static function musicEditFieldsProvider(): array
    {
        return [
            'valid_digits_and_iso_date' => ['42', '2024-02-29', '2020-01-01 00:00:00', '7'],
            'leading_zeros_salesrank_and_iso_datetime' => ['00', '2024-03-15 14:30:45', '2020-01-01 00:00:00', '12'],
            'zero_salesrank_and_zero_genre_are_null' => ['0', '', '2021-05-10 12:00:00', '0'],
            'non_digit_salesrank_and_invalid_date_use_fallback' => ['12a', 'not-a-valid-date', '2019-11-20 08:15:00', null],
            'epoch_zero_date_uses_fallback' => [null, '1970-01-01 00:00:00', '2018-06-01 00:00:00', '5'],
            'non_iso_slash_date_falls_back_to_php_carbon' => ['15', '2024/03/15', '2020-01-01 00:00:00', '3'],
        ];
    }

    #[DataProvider('musicEditFieldsProvider')]
    public function test_admin_music_controller_normalize_edit_fields_parity(
        mixed $salesrankInput,
        mixed $releasedateInput,
        ?string $fallbackReleaseDate,
        mixed $genreInput,
    ): void {
        $this->requireMetadataFfi();

        $php = AdminMusicController::normalizeMusicEditFieldsPhp(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $genreInput,
        );
        $native = AdminMusicController::normalizeMusicEditFieldsNative(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $genreInput,
        );
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminMusicController::setUseRustFfi($useRust);
            $this->assertSame(
                $php,
                AdminMusicController::normalizeMusicEditFields(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $genreInput,
                )
            );
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: bool|int}>
     */
    public static function consoleEditFieldsProvider(): array
    {
        return [
            'valid_salesrank_and_iso_date' => ['0015', '2024-02-29', '2020-01-01', true],
            'iso_datetime_and_no_cover' => ['99', '2024-03-15 14:30:45', 1577836800, false],
            'zero_salesrank_and_empty_date' => ['0', '', '2021-07-01', 1],
            'invalid_salesrank_and_invalid_date' => ['abc', 'invalid-date', '2022-09-01', 0],
            'epoch_zero_uses_fallback' => [null, '1970-01-01 00:00:00', '2019-04-01', true],
        ];
    }

    #[DataProvider('consoleEditFieldsProvider')]
    public function test_admin_console_controller_normalize_edit_fields_parity(
        mixed $salesrankInput,
        mixed $releasedateInput,
        mixed $fallbackReleaseDate,
        bool|int $hasCover,
    ): void {
        $this->requireMetadataFfi();

        $php = AdminConsoleController::normalizeConsoleEditFieldsPhp(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $hasCover,
        );
        $native = AdminConsoleController::normalizeConsoleEditFieldsNative(
            $salesrankInput,
            $releasedateInput,
            $fallbackReleaseDate,
            $hasCover,
        );
        $this->assertSame($php, $native);

        foreach ([false, true] as $useRust) {
            AdminConsoleController::setUseRustFfi($useRust);
            $this->assertSame(
                $php,
                AdminConsoleController::normalizeConsoleEditFields(
                    $salesrankInput,
                    $releasedateInput,
                    $fallbackReleaseDate,
                    $hasCover,
                )
            );
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
     */
    public static function gameSearchAndReleaseDateProvider(): array
    {
        return [
            'non_empty_search_and_iso_date' => ['Halo Infinite', '2024-02-29', 1609459200],
            'zero_string_search_is_empty_and_iso_datetime' => ['0', '2024-03-15 14:30:45', 1609459200],
            'double_zero_search_preserved_and_empty_date' => ['007', '', '2021-12-08'],
            'empty_search_and_invalid_date' => ['', 'not-a-date', '2020-05-01'],
            'null_search_and_epoch_zero_date' => [null, '1970-01-01 00:00:00', '2018-01-01'],
        ];
    }

    #[DataProvider('gameSearchAndReleaseDateProvider')]
    public function test_admin_game_controller_search_and_release_date_parity(
        mixed $search,
        mixed $releasedateInput,
        mixed $fallbackReleaseDate,
    ): void {
        $this->requireMetadataFfi();

        $phpSearch = AdminGameController::normalizeGameSearchTermPhp($search);
        $nativeSearch = AdminGameController::normalizeGameSearchTermNative($search);
        $this->assertSame($phpSearch, $nativeSearch);

        $phpDate = AdminGameController::normalizeGameReleaseDatePhp($releasedateInput, $fallbackReleaseDate);
        $nativeDate = AdminGameController::normalizeGameReleaseDateNative($releasedateInput, $fallbackReleaseDate);
        $this->assertSame($phpDate, $nativeDate);

        foreach ([false, true] as $useRust) {
            AdminGameController::setUseRustFfi($useRust);
            $this->assertSame($phpSearch, AdminGameController::normalizeGameSearchTerm($search));
            $this->assertSame($phpDate, AdminGameController::normalizeGameReleaseDate($releasedateInput, $fallbackReleaseDate));
        }
    }

    public function test_all_five_controllers_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        AdminMovieController::setFfiForTesting(false);
        AdminMovieController::enableRustFfi();
        $this->assertTrue(AdminMovieController::isValidNumericImdbId('0133093'));
        $this->assertFalse(AdminMovieController::isValidNumericImdbId('tt0133093'));
        $this->assertSame(
            AdminMovieController::buildMovieImageKeysPhp('0133093'),
            AdminMovieController::buildMovieImageKeys('0133093')
        );
        $this->assertSame(
            AdminMovieController::buildMovieUpdatePayloadPhp('0133093', ['title' => 'The Matrix'], true, false),
            AdminMovieController::buildMovieUpdatePayload('0133093', ['title' => 'The Matrix'], true, false)
        );

        AdminShowsController::setFfiForTesting(false);
        AdminShowsController::enableRustFfi();
        $this->assertSame(
            AdminShowsController::normalizeLookupPreviewPayloadPhp(['title' => 'Severance', 'tvdb' => 371980]),
            AdminShowsController::normalizeLookupPreviewPayload(['title' => 'Severance', 'tvdb' => 371980])
        );
        $this->assertSame(
            AdminShowsController::formatCreateFlashPhp(true, 'Severance'),
            AdminShowsController::formatCreateFlash(true, 'Severance')
        );

        AdminMusicController::setFfiForTesting(false);
        AdminMusicController::enableRustFfi();
        $this->assertSame(
            AdminMusicController::normalizeMusicEditFieldsPhp('42', '2024-02-29', '2020-01-01 00:00:00', '7'),
            AdminMusicController::normalizeMusicEditFields('42', '2024-02-29', '2020-01-01 00:00:00', '7')
        );

        AdminConsoleController::setFfiForTesting(false);
        AdminConsoleController::enableRustFfi();
        $this->assertSame(
            AdminConsoleController::normalizeConsoleEditFieldsPhp('15', '2024-02-29', '2020-01-01', true),
            AdminConsoleController::normalizeConsoleEditFields('15', '2024-02-29', '2020-01-01', true)
        );

        AdminGameController::setFfiForTesting(false);
        AdminGameController::enableRustFfi();
        $this->assertSame('Halo', AdminGameController::normalizeGameSearchTerm('Halo'));
        $this->assertSame(
            AdminGameController::normalizeGameReleaseDatePhp('2024-02-29', 1609459200),
            AdminGameController::normalizeGameReleaseDate('2024-02-29', 1609459200)
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
        $scalars5 = $ffi->new('int64_t[5]');
        $scalars4 = $ffi->new('int64_t[4]');

        // 1. AdminMovieController C-ABI
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_movie_is_numeric_imdb_id', null, 5));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_movie_build_image_keys', '123', 3, $unalignedSizeT, $smallBuf, 4));
        $this->assertSame(-21, (int) self::callFfi($ffi, 'nntmux_admin_movie_build_image_keys', '123', 3, $lens2, $smallBuf, 4));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));
        $this->assertSame(-1, (int) self::callFfi($ffi, 'nntmux_admin_movie_normalize_update_flags', 1, 1, $unalignedInt64));

        // 2. AdminShowsController C-ABI
        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_shows_normalize_preview_ids',
            2,
            1,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            1,
            '0944947',
            7,
            $scalars5,
            $smallBuf,
            4
        ));
        $this->assertSame(-7, (int) self::callFfi(
            $ffi,
            'nntmux_admin_shows_normalize_preview_ids',
            1,
            1,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            1,
            '0944947',
            7,
            $scalars5,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-41, (int) self::callFfi(
            $ffi,
            'nntmux_admin_shows_format_create_flash',
            0,
            'Lost',
            4,
            $lens2,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        // 3. AdminMusicController, AdminConsoleController, AdminGameController C-ABI
        $this->assertSame(-19, (int) self::callFfi(
            $ffi,
            'nntmux_admin_music_normalize_edit_fields',
            '10',
            2,
            '2024-01-02',
            10,
            1,
            '3',
            1,
            $scalars5,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-5, (int) self::callFfi(
            $ffi,
            'nntmux_admin_console_normalize_edit_fields',
            '12345',
            5,
            '',
            0,
            1,
            1,
            $scalars4,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-5, (int) self::callFfi(
            $ffi,
            'nntmux_admin_game_normalize_search_term',
            'Quake',
            5,
            $smallBuf,
            4
        ));
        $this->assertSame('ZZZZ', FFI::string($smallBuf, 4));

        $this->assertSame(-1, (int) self::callFfi(
            $ffi,
            'nntmux_admin_game_parse_release_timestamp',
            '2024-01-02',
            10,
            2,
            $unalignedInt64
        ));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_ADMIN_MEDIA_CATALOG_CONTROLLERS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_ADMIN_MEDIA_CATALOG_CONTROLLERS_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $movieInput = [
            'actors' => 'Keanu Reeves, Laurence Fishburne',
            'director' => 'The Wachowskis',
            'genre' => 'Action, Sci-Fi',
            'language' => 'English',
            'plot' => 'A computer hacker learns about the true nature of reality.',
            'rating' => '8.7',
            'tagline' => 'Welcome to the Real World.',
            'title' => 'The Matrix',
            'year' => '1999',
        ];
        $showPreview = [
            'title' => 'Severance',
            'summary' => 'Mark leads a team of office workers.',
            'started' => '2022-02-18',
            'publisher' => 'Apple TV+',
            'poster' => 'https://example.test/poster.jpg',
            'tvdb' => 371980,
            'tvmaze' => 44933,
            'tmdb' => 95396,
            'trakt' => 156600,
            'imdb' => '11280740',
            'tvrage' => 0,
        ];

        $runBatch = static function (int $iterations) use ($movieInput, $showPreview): void {
            for ($i = 0; $i < $iterations; $i++) {
                AdminMovieController::isValidNumericImdbId('0133093');
                AdminMovieController::buildMovieImageKeys('0133093');
                AdminMovieController::buildMovieUpdatePayload('0133093', $movieInput, true, true);
                AdminShowsController::normalizeLookupPreviewPayload($showPreview);
                AdminShowsController::formatCreateFlash(true, 'Severance');
                AdminMusicController::normalizeMusicEditFields('42', '2024-02-29', '2020-01-01 00:00:00', '7');
                AdminConsoleController::normalizeConsoleEditFields('15', '2024-02-29', '2020-01-01', true);
                AdminGameController::normalizeGameSearchTerm('Halo Infinite');
                AdminGameController::normalizeGameReleaseDate('2024-02-29', 1609459200);
            }
        };

        $setAllModes = static function (bool $rust): void {
            AdminMovieController::setUseRustFfi($rust);
            AdminShowsController::setUseRustFfi($rust);
            AdminMusicController::setUseRustFfi($rust);
            AdminConsoleController::setUseRustFfi($rust);
            AdminGameController::setUseRustFfi($rust);
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

        fwrite(STDERR, "\n[Wave 28.4 Benchmark] ".implode(' | ', $summary)."\n");
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
