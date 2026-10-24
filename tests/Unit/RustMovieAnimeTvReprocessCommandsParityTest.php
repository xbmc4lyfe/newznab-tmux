<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\FetchMovieByImdb;
use App\Console\Commands\MigrateAnimeCovers;
use App\Console\Commands\MovieReverifyMatches;
use App\Console\Commands\ReprocessTvRelease;
use App\Console\Commands\ReprocessUnmatchedTvReleases;
use App\Enums\TvLookupStatus;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paired randomized benchmark evidence (`NNTMUX_MOVIE_ANIME_TV_REPROCESS_BENCH=1`,
 * 3 rounds x 41 samples x 200 iterations, warmup=8, Apple Silicon Darwin release dylib):
 *
 * - Round 1: PHP median = 0.329 ms (max 0.485), Rust FFI median = 1.428 ms (max 1.686), 0.23x
 * - Round 2: PHP median = 0.330 ms (max 0.436), Rust FFI median = 1.416 ms (max 1.663), 0.23x
 * - Round 3: PHP median = 0.330 ms (max 0.411), Rust FFI median = 1.428 ms (max 1.753), 0.23x
 * - Per-call FFI marshaling (scratch buffers, CData out-params, length splitting) dominates these
 *   sub-microsecond string helpers, so PHP stays the production default (`usesRustFfi() === false`)
 *   for all five commands: the 1.5x threshold is not met on the median or the tail.
 */
class RustMovieAnimeTvReprocessCommandsParityTest extends TestCase
{
    private const CLASSES = [
        FetchMovieByImdb::class,
        MovieReverifyMatches::class,
        MigrateAnimeCovers::class,
        ReprocessTvRelease::class,
        ReprocessUnmatchedTvReleases::class,
    ];

    private const DIRECT_CDEF = <<<'CDEF'
    ssize_t nntmux_fetch_movie_by_imdb_normalize_id(const char *raw_ptr, size_t raw_len, uint32_t *out_valid, char *out_buf, size_t out_cap);
    ssize_t nntmux_fetch_movie_by_imdb_cache_keys(const char *imdb_ptr, size_t imdb_len, size_t *out_lens, char *out_buf, size_t out_cap);
    int32_t nntmux_movie_reverify_normalize_limit(uint32_t kind, int64_t int_value, const char *str_ptr, size_t str_len, int64_t *out_limit);
    ssize_t nntmux_movie_reverify_format_summary(int64_t checked, int64_t skipped, int64_t mismatched, char *out_buf, size_t out_cap);
    ssize_t nntmux_movie_reverify_format_outcome(uint32_t dry_run, int64_t mismatched, char *out_buf, size_t out_cap);
    ssize_t nntmux_migrate_anime_covers_resolve_filename(const char *filename_ptr, size_t filename_len, int64_t *out_anidbid, char *out_buf, size_t out_cap);
    ssize_t nntmux_migrate_anime_covers_format_summary(uint32_t dry_run, int64_t renamed, int64_t skipped, size_t *out_lens, char *out_buf, size_t out_cap);
    ssize_t nntmux_reprocess_tv_release_truncate_search_name(const char *search_name_ptr, size_t search_name_len, int64_t max_chars, char *out_buf, size_t out_cap);
    ssize_t nntmux_reprocess_tv_release_final_state_retry_at(uint32_t has_retry_at, const char *retry_at_ptr, size_t retry_at_len, char *out_buf, size_t out_cap);
    ssize_t nntmux_reprocess_unmatched_tv_target_statuses(uint32_t include_parse_failed, int64_t *out_values, size_t values_cap);
    int32_t nntmux_reprocess_unmatched_tv_clamp_process_count(int64_t total_count, int64_t limit, int64_t *out_count);
    ssize_t nntmux_reprocess_unmatched_tv_format_match_rate(int64_t matched, int64_t processed, char *out_buf, size_t out_cap);
    CDEF;

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetRustFfi();
        }

        parent::tearDown();
    }

    public function test_production_defaults_remain_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
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
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function imdbIdProvider(): array
    {
        return [
            'prefixed' => ['tt0133093', '0133093', '0133093'],
            'upper_prefix_and_php_trim_ws' => ["\0\x0b TT1234567\r\n", '1234567', '1234567'],
            'bare_digits' => ['1234567', '1234567', '1234567'],
            'separators_stripped' => ['tt12-34 5', '12345', '12345'],
            'too_short' => ['tt1234', null, '1234'],
            'empty' => ['', null, ''],
            'formfeed_is_not_trimmed' => ["\x0ctt1234567", '1234567', '1234567'],
            'only_one_tt_removed' => ['ttt12345', '12345', '12345'],
            'fullwidth_digits_dropped' => ['tt１２３４５', null, ''],
            'invalid_utf8' => ["\xfftt98765", '98765', '98765'],
            'embedded_nul' => ["12\x00345", '12345', '12345'],
        ];
    }

    #[DataProvider('imdbIdProvider')]
    public function test_fetch_movie_by_imdb_id_parity(string $raw, ?string $expected, string $digits): void
    {
        $this->requireMetadataFfi();

        $this->assertSame($expected, FetchMovieByImdb::normalizeImdbIdPhp($raw));
        $this->assertSame($expected, FetchMovieByImdb::normalizeImdbIdNative($raw));
        $this->assertSame($digits, FetchMovieByImdb::extractImdbDigitsPhp($raw));
        $this->assertSame($digits, FetchMovieByImdb::extractImdbDigitsNative($raw));

        foreach ([false, true] as $useRust) {
            FetchMovieByImdb::setUseRustFfi($useRust);
            $this->assertSame($expected, FetchMovieByImdb::normalizeImdbId($raw));
            $this->assertSame($digits, FetchMovieByImdb::extractImdbDigits($raw));
        }
    }

    public function test_fetch_movie_by_imdb_cache_keys_and_details_parity(): void
    {
        $this->requireMetadataFfi();

        foreach (['0133093', '12345678', '1'.str_repeat('0', 40)] as $imdbId) {
            $expected = [
                'tmdb_movie_'.md5('tt'.$imdbId),
                'trakt_movie_'.md5($imdbId),
                'imdb_movie_'.md5($imdbId),
                'imdb_scrape_id_'.$imdbId,
                'omdb_movie_'.md5($imdbId),
            ];
            $this->assertSame($expected, FetchMovieByImdb::buildMovieCacheKeysPhp($imdbId));
            $this->assertSame($expected, FetchMovieByImdb::buildMovieCacheKeysNative($imdbId));
        }

        $stringable = new class
        {
            public function __toString(): string
            {
                return 'Stringable Title';
            }
        };
        $cases = [
            ['The Matrix', 1999, '0133093', 603, 'tt-trakt', 8.7, 'Action, Sci-Fi', 1],
            [null, null, '0133093', null, null, null, null, null],
            ['', '', '0133093', 0, 0, 0.0, '', 0],
            ['Amélie 🎬', '2001', '0211915', '194', 481, '8.3', 'Comedy', '1'],
            ["Nul\0Title", 2001.5, '0000001', true, false, 1e20, "Line\nBreak", '1.0'],
            ['Cover loose', 2000, '0000002', 1, 2, 3, 'Drama', ' 1'],
            ['Cover true', 2000, '0000003', 1, 2, 3, 'Drama', true],
            ['Cover two', 2000, '0000004', 1, 2, 3, 'Drama', 2],
            [$stringable, 2000, '0000005', 1, 2, 3, 'Drama', 1],
        ];
        foreach ($cases as $case) {
            $php = FetchMovieByImdb::formatMovieDetailsLinesPhp(...$case);
            $this->assertCount(7, $php);
            $this->assertSame($php, FetchMovieByImdb::formatMovieDetailsLinesNative(...$case));
            FetchMovieByImdb::setUseRustFfi(true);
            $this->assertSame($php, FetchMovieByImdb::formatMovieDetailsLines(...$case));
            FetchMovieByImdb::setUseRustFfi(false);
        }

        $this->assertSame(
            [
                'Updated movie: Unknown Title ()',
                'IMDb: tt0133093',
                'TMDB ID: N/A',
                'Trakt ID: N/A',
                'Rating: N/A',
                'Genre: N/A',
                'Cover: No',
            ],
            FetchMovieByImdb::formatMovieDetailsLinesNative(null, null, '0133093', null, null, null, null, null)
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: ?int}>
     */
    public static function limitProvider(): array
    {
        return [
            'null' => [null, null],
            'empty' => ['', null],
            'positive_string' => ['25', 25],
            'zero' => ['0', null],
            'negative' => ['-5', null],
            'padded' => [" \t\n\r\x0b\x0c7 \n", 7],
            'fraction_truncates' => ['1.9', 1],
            'fraction_below_one' => ['0.5', null],
            'exponent' => ['1e3', 1000],
            'signed_leading_dot' => ['+.5e1', 5],
            'trailing_dot_exponent' => ['1.e3', 1000],
            'float_overflow_caps' => ['1e100', PHP_INT_MAX],
            'integer_overflow_caps' => ['99999999999999999999', PHP_INT_MAX],
            'int_max_exact' => ['9223372036854775807', PHP_INT_MAX],
            'near_int_max_exact' => ['9223372036854775806', 9223372036854775806],
            'infinite_is_zero' => ['1e400', null],
            'hex_not_numeric' => ['0x1A', null],
            'nul_not_numeric' => ["5\0", null],
            'dangling_exponent' => ['1e', null],
            'underscore' => ['1_000', null],
            'word' => ['ten', null],
            'int' => [12, 12],
            'negative_int' => [-12, null],
            'float' => [3.7, 3],
            'true' => [true, null],
            'array' => [['5'], null],
        ];
    }

    #[DataProvider('limitProvider')]
    public function test_movie_reverify_limit_parity(mixed $limit, ?int $expected): void
    {
        $this->requireMetadataFfi();

        $this->assertSame($expected, MovieReverifyMatches::normalizePositiveLimitPhp($limit));
        $this->assertSame($expected, MovieReverifyMatches::normalizePositiveLimitNative($limit));

        MovieReverifyMatches::setUseRustFfi(true);
        $this->assertSame($expected, MovieReverifyMatches::normalizePositiveLimit($limit));
    }

    public function test_movie_reverify_messages_parity(): void
    {
        $this->requireMetadataFfi();

        foreach ([[7, 'Movie.2020.1080p', '0133093'], [-1, '', ''], [PHP_INT_MAX, "Amélie\0 (2001) ".str_repeat('x', 300), '12345']] as [$id, $name, $imdb]) {
            $php = MovieReverifyMatches::formatMismatchLinePhp($id, $name, $imdb);
            $this->assertSame($php, MovieReverifyMatches::formatMismatchLineNative($id, $name, $imdb));
        }
        $this->assertSame(
            'Mismatch: release 7 (Movie.2020.1080p) matched to tt0133093',
            MovieReverifyMatches::formatMismatchLineNative(7, 'Movie.2020.1080p', '0133093')
        );

        foreach ([[0, 0, 0], [500, 20, 3], [PHP_INT_MAX, PHP_INT_MIN, -1]] as [$checked, $skipped, $mismatched]) {
            $this->assertSame(
                MovieReverifyMatches::formatSummaryMessagePhp($checked, $skipped, $mismatched),
                MovieReverifyMatches::formatSummaryMessageNative($checked, $skipped, $mismatched)
            );
        }

        foreach ([true, false] as $dryRun) {
            foreach ([0, 1, 42, -3] as $mismatched) {
                $php = MovieReverifyMatches::formatOutcomeMessagePhp($dryRun, $mismatched);
                $this->assertSame($php, MovieReverifyMatches::formatOutcomeMessageNative($dryRun, $mismatched));
                MovieReverifyMatches::setUseRustFfi(true);
                $this->assertSame($php, MovieReverifyMatches::formatOutcomeMessage($dryRun, $mismatched));
                MovieReverifyMatches::setUseRustFfi(false);
            }
        }
        $this->assertNull(MovieReverifyMatches::formatOutcomeMessageNative(true, 0));
    }

    /**
     * @return array<string, array{0: string, 1: ?array{anidbid: int, new_filename: string}}>
     */
    public static function coverFilenameProvider(): array
    {
        return [
            'old_format' => ['123.jpg', ['anidbid' => 123, 'new_filename' => '123-cover.jpg']],
            'leading_zeros_kept' => ['007.jpg', ['anidbid' => 7, 'new_filename' => '007-cover.jpg']],
            'pcre_dollar_allows_final_newline' => ["42.jpg\n", ['anidbid' => 42, 'new_filename' => '42-cover.jpg']],
            'overflow_caps' => ['99999999999999999999.jpg', ['anidbid' => PHP_INT_MAX, 'new_filename' => '99999999999999999999-cover.jpg']],
            'already_new_format' => ['123-cover.jpg', null],
            'two_newlines' => ["42.jpg\n\n", null],
            'uppercase_extension' => ['42.JPG', null],
            'no_digits' => ['.jpg', null],
            'jpeg' => ['42.jpeg', null],
            'arabic_digits' => ['١٢.jpg', null],
            'nul' => ["42\0.jpg", null],
            'empty' => ['', null],
        ];
    }

    /**
     * @param  array{anidbid: int, new_filename: string}|null  $expected
     */
    #[DataProvider('coverFilenameProvider')]
    public function test_migrate_anime_covers_filename_parity(string $filename, ?array $expected): void
    {
        $this->requireMetadataFfi();

        $this->assertSame($expected, MigrateAnimeCovers::resolveNewCoverFilenamePhp($filename));
        $this->assertSame($expected, MigrateAnimeCovers::resolveNewCoverFilenameNative($filename));

        MigrateAnimeCovers::setUseRustFfi(true);
        $this->assertSame($expected, MigrateAnimeCovers::resolveNewCoverFilename($filename));
    }

    public function test_migrate_anime_covers_summary_parity(): void
    {
        $this->requireMetadataFfi();

        foreach ([true, false] as $dryRun) {
            foreach ([[0, 0], [12, 3], [PHP_INT_MAX, PHP_INT_MIN]] as [$renamed, $skipped]) {
                $php = MigrateAnimeCovers::formatMigrationSummaryPhp($dryRun, $renamed, $skipped);
                $this->assertCount($dryRun ? 2 : 1, $php);
                $this->assertSame($php, MigrateAnimeCovers::formatMigrationSummaryNative($dryRun, $renamed, $skipped));
            }
        }
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function searchNameProvider(): array
    {
        return [
            'short' => ['Severance.S02E01', 60],
            'exactly_sixty' => [str_repeat('a', 60), 60],
            'sixty_one' => [str_repeat('a', 61), 60],
            'multibyte_short_chars_long_bytes' => [str_repeat('é', 31), 60],
            'multibyte_over_limit' => [str_repeat('日本', 40), 60],
            'emoji' => [str_repeat('🎬', 70), 60],
            'nul_bytes' => [str_repeat("a\0", 40), 60],
            'empty' => ['', 60],
            'zero_limit' => ['abc', 0],
            'negative_limit' => ['abcdef', -2],
            'invalid_utf8' => [str_repeat("\xff\xfe", 40), 60],
            'truncated_sequence' => [str_repeat('a', 59)."\xe2\x82", 60],
        ];
    }

    #[DataProvider('searchNameProvider')]
    public function test_reprocess_tv_release_truncation_parity(string $searchName, int $maxChars): void
    {
        $this->requireMetadataFfi();

        $php = ReprocessTvRelease::truncateSearchNamePhp($searchName, $maxChars);
        $this->assertSame($php, ReprocessTvRelease::truncateSearchNameNative($searchName, $maxChars));

        ReprocessTvRelease::setUseRustFfi(true);
        $this->assertSame($php, ReprocessTvRelease::truncateSearchName($searchName, $maxChars));
        if ($maxChars === 60) {
            $this->assertSame($php, ReprocessTvRelease::truncateSearchName($searchName));
        }
    }

    public function test_reprocess_tv_release_final_state_row_parity(): void
    {
        $this->requireMetadataFfi();

        $date = new \DateTimeImmutable('2026-10-25 12:00:00');
        $cases = [
            [12, 345, 2, '2026-10-26 08:00:00'],
            [0, TvLookupStatus::Pending->value, 0, null],
            [null, null, null, ''],
            ['7', '-10', '3', "nul\0"],
            [1, 2, 3, $date],
        ];
        foreach ($cases as $case) {
            $php = ReprocessTvRelease::projectFinalStateRowPhp(...$case);
            $this->assertSame($php, ReprocessTvRelease::projectFinalStateRowNative(...$case));
        }
        $this->assertSame([0, 0, 0, '-'], ReprocessTvRelease::projectFinalStateRowNative(0, 0, 0, null));
    }

    public function test_reprocess_unmatched_tv_helpers_parity(): void
    {
        $this->requireMetadataFfi();

        foreach ([false, true] as $includeParseFailed) {
            $php = ReprocessUnmatchedTvReleases::buildTargetStatusesPhp($includeParseFailed);
            $this->assertSame($php, ReprocessUnmatchedTvReleases::buildTargetStatusesNative($includeParseFailed));
        }
        $this->assertSame(
            [TvLookupStatus::Pending->value, TvLookupStatus::ShowNotFound->value, TvLookupStatus::ParseFailed->value],
            ReprocessUnmatchedTvReleases::buildTargetStatusesNative(true)
        );

        foreach ([[100, 0], [100, 10], [5, 10], [0, 3], [100, -1], [PHP_INT_MAX, PHP_INT_MIN], [-5, 2]] as [$total, $limit]) {
            $this->assertSame(
                ReprocessUnmatchedTvReleases::clampProcessCountPhp($total, $limit),
                ReprocessUnmatchedTvReleases::clampProcessCountNative($total, $limit)
            );
        }

        $rates = [
            [0, 0], [5, 0], [5, -2], [0, 7], [1, 3], [2, 3], [1, 8], [29, 200], [57, 200], [1, 6], [1, 7],
            [7, 7], [9, 4], [-1, 3], [1, PHP_INT_MAX], [PHP_INT_MAX, 1], [PHP_INT_MAX, PHP_INT_MAX],
            [(1 << 53) + 1, (1 << 53) + 3], [123456789, 987654321],
        ];
        foreach ($rates as [$matched, $processed]) {
            $php = ReprocessUnmatchedTvReleases::formatMatchRatePhp($matched, $processed);
            $this->assertSame($php, ReprocessUnmatchedTvReleases::formatMatchRateNative($matched, $processed), "{$matched}/{$processed}");
            ReprocessUnmatchedTvReleases::setUseRustFfi(true);
            $this->assertSame($php, ReprocessUnmatchedTvReleases::formatMatchRate($matched, $processed));
            ReprocessUnmatchedTvReleases::setUseRustFfi(false);
        }
    }

    public function test_match_rate_rounding_parity_over_dense_grid(): void
    {
        $this->requireMetadataFfi();

        for ($processed = 1; $processed <= 400; $processed++) {
            for ($matched = 0; $matched <= $processed + 2; $matched++) {
                $php = ReprocessUnmatchedTvReleases::formatMatchRatePhp($matched, $processed);
                $native = ReprocessUnmatchedTvReleases::formatMatchRateNative($matched, $processed);
                if ($php !== $native) {
                    $this->fail("{$matched}/{$processed}: PHP {$php} vs Rust {$native}");
                }
            }
        }

        mt_srand(3004);
        for ($i = 0; $i < 20_000; $i++) {
            $processed = mt_rand(1, PHP_INT_MAX >> mt_rand(0, 40));
            $matched = mt_rand(0, $processed);
            $this->assertSame(
                ReprocessUnmatchedTvReleases::formatMatchRatePhp($matched, $processed),
                ReprocessUnmatchedTvReleases::formatMatchRateNative($matched, $processed),
                "{$matched}/{$processed}"
            );
        }
    }

    public function test_randomized_string_helpers_parity(): void
    {
        $this->requireMetadataFfi();

        $alphabet = [' ', "\t", "\n", "\r", "\x0b", "\x0c", "\0", '+', '-', '.', 'e', 'E', '0', '1', '5', '9', 'x', 't', 'T', 'é', '日', "\xff", '.jpg'];
        mt_srand(304);
        for ($i = 0; $i < 20_000; $i++) {
            $value = '';
            for ($j = mt_rand(0, 14); $j > 0; $j--) {
                $value .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $maxChars = mt_rand(0, 16);

            $this->assertSame(MovieReverifyMatches::normalizePositiveLimitPhp($value), MovieReverifyMatches::normalizePositiveLimitNative($value), bin2hex($value));
            $this->assertSame(FetchMovieByImdb::normalizeImdbIdPhp($value), FetchMovieByImdb::normalizeImdbIdNative($value), bin2hex($value));
            $this->assertSame(FetchMovieByImdb::extractImdbDigitsPhp($value), FetchMovieByImdb::extractImdbDigitsNative($value), bin2hex($value));
            $this->assertSame(MigrateAnimeCovers::resolveNewCoverFilenamePhp($value), MigrateAnimeCovers::resolveNewCoverFilenameNative($value), bin2hex($value));
            $this->assertSame(ReprocessTvRelease::truncateSearchNamePhp($value, $maxChars), ReprocessTvRelease::truncateSearchNameNative($value, $maxChars), bin2hex($value));
        }
    }

    public function test_all_five_classes_fall_back_cleanly_when_ffi_is_unavailable(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::enableRustFfi();
        }

        $this->assertSame('0133093', FetchMovieByImdb::normalizeImdbId('tt0133093'));
        $this->assertSame('123', FetchMovieByImdb::extractImdbDigits('tt123'));
        $this->assertSame(FetchMovieByImdb::buildMovieCacheKeysPhp('0133093'), FetchMovieByImdb::buildMovieCacheKeys('0133093'));
        $this->assertSame(
            FetchMovieByImdb::formatMovieDetailsLinesPhp('Matrix', 1999, '0133093', 603, null, 8.7, null, 1),
            FetchMovieByImdb::formatMovieDetailsLines('Matrix', 1999, '0133093', 603, null, 8.7, null, 1)
        );
        $this->assertSame(10, MovieReverifyMatches::normalizePositiveLimit('10'));
        $this->assertSame(MovieReverifyMatches::formatMismatchLinePhp(1, 'a', '1'), MovieReverifyMatches::formatMismatchLine(1, 'a', '1'));
        $this->assertSame(MovieReverifyMatches::formatSummaryMessagePhp(3, 2, 1), MovieReverifyMatches::formatSummaryMessage(3, 2, 1));
        $this->assertSame('Dry run: no changes made.', MovieReverifyMatches::formatOutcomeMessage(true, 1));
        $this->assertSame(['anidbid' => 5, 'new_filename' => '5-cover.jpg'], MigrateAnimeCovers::resolveNewCoverFilename('5.jpg'));
        $this->assertSame(MigrateAnimeCovers::formatMigrationSummaryPhp(true, 1, 2), MigrateAnimeCovers::formatMigrationSummary(true, 1, 2));
        $this->assertSame(str_repeat('x', 60).'...', ReprocessTvRelease::truncateSearchName(str_repeat('x', 61)));
        $this->assertSame([1, 2, 3, '-'], ReprocessTvRelease::projectFinalStateRow(1, 2, 3, null));
        $this->assertSame([0, -10], ReprocessUnmatchedTvReleases::buildTargetStatuses(false));
        $this->assertSame(10, ReprocessUnmatchedTvReleases::clampProcessCount(50, 10));
        $this->assertSame('33.33%', ReprocessUnmatchedTvReleases::formatMatchRate(1, 3));
    }

    public function test_direct_c_abi_rejects_null_unaligned_invalid_flags_and_undersized_buffers(): void
    {
        $ffi = $this->requireMetadataFfi();

        $raw = $ffi->new('uint8_t[128]');
        $unalignedInt64 = $ffi->cast('int64_t *', FFI::addr($raw[1]));
        $unalignedSizeT = $ffi->cast('size_t *', FFI::addr($raw[1]));
        $unalignedU32 = $ffi->cast('uint32_t *', FFI::addr($raw[1]));
        $valid = $ffi->new('uint32_t[1]');
        $lens = $ffi->new('size_t[5]');
        $out = $ffi->new('int64_t[3]');
        $buf = $ffi->new('char[256]');

        $small = $ffi->new('char[4]');
        for ($i = 0; $i < 4; $i++) {
            $small[$i] = 'Z';
        }

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_normalize_id', null, 0, $valid, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_normalize_id', 'tt1', 3, $unalignedU32, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_normalize_id', 'tt1', 3, $valid, null, 256));
        $this->assertSame(-5, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_normalize_id', 'tt12345', 7, $valid, $small, 4));
        $this->assertSame(1, (int) $valid[0]);
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_cache_keys', '1', 1, $unalignedSizeT, $buf, 256));
        $this->assertLessThan(-4, self::callFfi($ffi, 'nntmux_fetch_movie_by_imdb_cache_keys', '1', 1, $lens, $small, 4));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_movie_reverify_normalize_limit', 1, 5, null, 0, $unalignedInt64));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_movie_reverify_normalize_limit', 2, 0, null, 0, $out));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_movie_reverify_normalize_limit', 9, 0, null, 0, $out));
        $this->assertSame(-69, self::callFfi($ffi, 'nntmux_movie_reverify_format_summary', 1, 1, 1, $small, 4));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_movie_reverify_format_outcome', 2, 1, $buf, 256));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_migrate_anime_covers_resolve_filename', '1.jpg', 5, $unalignedInt64, $buf, 256));
        $this->assertSame(-11, self::callFfi($ffi, 'nntmux_migrate_anime_covers_resolve_filename', '1.jpg', 5, $out, $small, 4));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_migrate_anime_covers_format_summary', 2, 1, 1, $lens, $buf, 256));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_tv_release_truncate_search_name', 'abc', 3, -1, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_tv_release_truncate_search_name', "\xff", 1, 60, $buf, 256));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_tv_release_final_state_retry_at', 2, '', 0, $buf, 256));

        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_target_statuses', 2, $out, 3));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_target_statuses', 0, $unalignedInt64, 3));
        $this->assertSame(-3, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_target_statuses', 1, $out, 2));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_clamp_process_count', 1, 1, $unalignedInt64));
        $this->assertSame(-6, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_format_match_rate', 1, 3, $small, 4));
        $this->assertSame(-1, self::callFfi($ffi, 'nntmux_reprocess_unmatched_tv_format_match_rate', -1, 3, $buf, 256));

        $this->assertSame('ZZZZ', FFI::string($small, 4));
    }

    public function test_paired_randomized_benchmark(): void
    {
        if ((string) getenv('NNTMUX_MOVIE_ANIME_TV_REPROCESS_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_MOVIE_ANIME_TV_REPROCESS_BENCH=1 to run the paired benchmark.');
        }

        $this->requireMetadataFfi();

        $searchName = 'Severance.S02E01.Hello.Ms.Cobel.2160p.ATVP.WEB-DL.DDP5.1.Atmos.DV.HDR.H.265-NTb';
        $runBatch = static function (int $iterations) use ($searchName): void {
            for ($i = 0; $i < $iterations; $i++) {
                FetchMovieByImdb::normalizeImdbId(' tt0133093 ');
                FetchMovieByImdb::buildMovieCacheKeys('0133093');
                FetchMovieByImdb::formatMovieDetailsLines('The Matrix', 1999, '0133093', 603, 481, 8.7, 'Action, Sci-Fi', 1);
                MovieReverifyMatches::normalizePositiveLimit('250');
                MovieReverifyMatches::formatMismatchLine(42, $searchName, '0133093');
                MovieReverifyMatches::formatSummaryMessage(1250, 30, 12);
                MovieReverifyMatches::formatOutcomeMessage(false, 12);
                MigrateAnimeCovers::resolveNewCoverFilename('12345.jpg');
                MigrateAnimeCovers::formatMigrationSummary(true, 120, 4);
                ReprocessTvRelease::truncateSearchName($searchName);
                ReprocessTvRelease::projectFinalStateRow(12, 345, 2, '2026-10-26 08:00:00');
                ReprocessUnmatchedTvReleases::buildTargetStatuses(true);
                ReprocessUnmatchedTvReleases::clampProcessCount(5000, 250);
                ReprocessUnmatchedTvReleases::formatMatchRate(875, 1250);
            }
        };

        $setAllModes = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
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
                $order = (($round + $s) % 2) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAllModes($rust);
                    $t0 = hrtime(true);
                    $runBatch($iterations);
                    $elapsed = (hrtime(true) - $t0) / 1_000_000;
                    if ($rust) {
                        $rustTimes[] = $elapsed;
                    } else {
                        $phpTimes[] = $elapsed;
                    }
                }
            }

            sort($phpTimes);
            sort($rustTimes);
            $phpMedian = $phpTimes[intdiv($samples, 2)];
            $rustMedian = $rustTimes[intdiv($samples, 2)];
            $summary[] = sprintf(
                'Round %d: PHP median=%.3f ms max=%.3f ms, Rust median=%.3f ms max=%.3f ms, speedup=%.2fx',
                $round,
                $phpMedian,
                $phpTimes[$samples - 1],
                $rustMedian,
                $rustTimes[$samples - 1],
                $phpMedian / max(0.0001, $rustMedian)
            );
        }

        fwrite(STDERR, "\n[Wave 30.4 Benchmark] ".implode(' | ', $summary)."\n");
        $this->assertCount(3, $summary);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): int
    {
        return (int) $ffi->$symbol(...$args);
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
