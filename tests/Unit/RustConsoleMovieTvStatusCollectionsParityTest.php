<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TvLookupStatus;
use App\Http\Resources\AnidbCollection;
use App\Http\Resources\BookCollection;
use App\Models\ConsoleInfo;
use App\Models\MovieInfo;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;

/**
 * Rust/PHP parity, C-ABI safety, and paired benchmark qualification tests for Wave 21 Slice 21.5:
 * - ConsoleInfo
 * - MovieInfo
 * - TvLookupStatus
 * - AnidbCollection
 * - BookCollection
 *
 * Paired randomized benchmark evidence (macOS arm64, release cdylib, 3 repetitions x 41 paired
 * samples x 200 inner iterations, end-to-end including PHP FFI marshalling):
 * - ConsoleInfo::common: Rust mean 1378.6 ns / p99 1463.1 ns vs PHP mean 281.7 ns / p99 294.6 ns (worst mean 0.202x, p99 0.189x) -> default PHP (`false`)
 * - ConsoleInfo::uncommon: Rust mean 1271.3 ns / p99 1443.5 ns vs PHP mean 355.1 ns / p99 402.5 ns (worst mean 0.271x, p99 0.278x) -> default PHP (`false`)
 * - MovieInfo::common: Rust mean 1866.2 ns / p99 2132.3 ns vs PHP mean 742.1 ns / p99 880.2 ns (worst mean 0.397x, p99 0.408x) -> default PHP (`false`)
 * - MovieInfo::uncommon: Rust mean 2592.6 ns / p99 2887.9 ns vs PHP mean 1574.4 ns / p99 1720.2 ns (worst mean 0.607x, p99 0.579x) -> default PHP (`false`)
 * - TvLookupStatus::common: Rust mean 813.4 ns / p99 903.5 ns vs PHP mean 149.7 ns / p99 184.6 ns (worst mean 0.178x, p99 0.204x) -> default PHP (`false`)
 * - TvLookupStatus::uncommon: Rust mean 845.7 ns / p99 930.0 ns vs PHP mean 191.4 ns / p99 226.3 ns (worst mean 0.226x, p99 0.242x) -> default PHP (`false`)
 * - AnidbCollection::common: Rust mean 1154.6 ns / p99 1216.9 ns vs PHP mean 137.8 ns / p99 152.7 ns (worst mean 0.119x, p99 0.121x) -> default PHP (`false`)
 * - AnidbCollection::uncommon: Rust mean 1409.9 ns / p99 1500.2 ns vs PHP mean 243.9 ns / p99 266.9 ns (worst mean 0.171x, p99 0.176x) -> default PHP (`false`)
 * - BookCollection::common: Rust mean 1149.8 ns / p99 1209.8 ns vs PHP mean 136.1 ns / p99 140.8 ns (worst mean 0.118x, p99 0.116x) -> default PHP (`false`)
 * - BookCollection::uncommon: Rust mean 1463.9 ns / p99 1558.5 ns vs PHP mean 244.5 ns / p99 270.2 ns (worst mean 0.164x, p99 0.171x) -> default PHP (`false`)
 * All 5 targets stay below the 1.5x threshold and retain `usesRustFfi() === false` by default.
 */
class RustConsoleMovieTvStatusCollectionsParityTest extends TestCase
{
    private const string BENCH_ENV = 'NNTMUX_CONSOLE_MOVIE_TV_STATUS_COLLECTIONS_BENCH';

    private const int BENCH_REPS = 3;

    private const int BENCH_SAMPLES = 41;

    private const int BENCH_INNER = 200;

    private const string DIRECT_CDEF = <<<'C'
        ssize_t nntmux_console_info_cover_paths(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, size_t *out_webp_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_console_info_resolve_cover_key(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, uint32_t has_cover, char *out_buf, size_t out_cap);
        ssize_t nntmux_console_info_normalize_field(int32_t kind, const char *input_ptr, size_t input_len, uint32_t has_input, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_all_cache_key(const char *search_ptr, size_t search_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_normalize_imdb_id(const char *raw_ptr, size_t raw_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_image_paths(const char *imdb_id_ptr, size_t imdb_id_len, uint32_t kind, size_t *out_webp_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_split_csv(const char *raw_ptr, size_t raw_len, size_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_external_links(const char *imdb_ptr, size_t imdb_len, int64_t tmdb_id, int64_t trakt_id, char *out_buf, size_t out_cap);
        int32_t nntmux_tv_lookup_status_classify(int64_t value);
        ssize_t nntmux_tv_lookup_status_text(int64_t value, uint32_t kind, char *out_buf, size_t out_cap);
        ssize_t nntmux_tv_lookup_status_reprocessable_values(uint32_t include_legacy_pending, uint32_t include_legacy_exhausted, uint32_t include_show_not_found, uint32_t include_parse_failed, int64_t *out_values, size_t values_cap);
        ssize_t nntmux_anidb_collection_resolve_meta(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, int64_t *out_meta, size_t meta_cap);
        ssize_t nntmux_anidb_collection_build_envelope(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, uint32_t has_first, const char *first_ptr, size_t first_len, uint32_t has_last, const char *last_ptr, size_t last_len, uint32_t has_prev, const char *prev_ptr, size_t prev_len, uint32_t has_next, const char *next_ptr, size_t next_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_book_collection_resolve_meta(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, int64_t *out_meta, size_t meta_cap);
        ssize_t nntmux_book_collection_build_envelope(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, uint32_t has_first, const char *first_ptr, size_t first_len, uint32_t has_last, const char *last_ptr, size_t last_len, uint32_t has_prev, const char *prev_ptr, size_t prev_len, uint32_t has_next, const char *next_ptr, size_t next_len, char *out_buf, size_t out_cap);
        C;

    protected function tearDown(): void
    {
        ConsoleInfo::resetRustFfi();
        MovieInfo::resetRustFfi();
        TvLookupStatus::resetRustFfi();
        AnidbCollection::resetRustFfi();
        BookCollection::resetRustFfi();

        parent::tearDown();
    }

    private function requireFfi(): FFI
    {
        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::DIRECT_CDEF);
        if ($ffi === null) {
            $this->markTestSkipped('Rust pipeline FFI library is not built.');
        }

        return $ffi;
    }

    public function test_production_defaults_follow_benchmark_gate(): void
    {
        ConsoleInfo::resetRustFfi();
        MovieInfo::resetRustFfi();
        TvLookupStatus::resetRustFfi();
        AnidbCollection::resetRustFfi();
        BookCollection::resetRustFfi();

        $this->assertFalse(ConsoleInfo::usesRustFfi());
        $this->assertFalse(MovieInfo::usesRustFfi());
        $this->assertFalse(TvLookupStatus::usesRustFfi());
        $this->assertFalse(AnidbCollection::usesRustFfi());
        $this->assertFalse(BookCollection::usesRustFfi());

        foreach ([ConsoleInfo::class, MovieInfo::class, TvLookupStatus::class, AnidbCollection::class, BookCollection::class] as $target) {
            $target::enableRustFfi();
            $this->assertTrue($target::usesRustFfi(), $target.' should enable Rust FFI');

            $target::disableRustFfi();
            $this->assertFalse($target::usesRustFfi(), $target.' should disable Rust FFI');

            $target::setUseRustFfi(true);
            $this->assertTrue($target::usesRustFfi(), $target.' should set Rust FFI true');

            $target::resetRustFfi();
            $this->assertFalse($target::usesRustFfi(), $target.' should reset to production default');
        }
    }

    public function test_console_info_helpers_native_match_php(): void
    {
        $this->requireFfi();

        foreach ([null, false, -50, -1, 0, 1, 42, '42', 987654321] as $id) {
            $this->assertSame(
                ConsoleInfo::formatCoverCandidateRelativePathsPhp($id),
                ConsoleInfo::formatCoverCandidateRelativePathsNative($id),
                'formatCoverCandidateRelativePaths mismatch for id='.var_export($id, true),
            );

            foreach ([false, true, 0, 1] as $hasCover) {
                $this->assertSame(
                    ConsoleInfo::resolveCoverAssetKeyPhp($id, $hasCover),
                    ConsoleInfo::resolveCoverAssetKeyNative($id, $hasCover),
                    'resolveCoverAssetKey mismatch for id='.var_export($id, true),
                );
            }
        }

        $asins = [
            null,
            '',
            '   ',
            'b08n5wrwnw',
            '  B09V3KXJPB  ',
            '0123456789',
            'B08N5WRWN',
            'B08N5WRWNWX',
            'B08N5-RWNW',
        ];
        foreach ($asins as $asin) {
            $this->assertSame(
                ConsoleInfo::normalizeAsinPhp($asin),
                ConsoleInfo::normalizeAsinNative($asin),
                'normalizeAsin mismatch for '.var_export($asin, true),
            );
        }

        $platforms = [
            null,
            '',
            '   ',
            'ps5',
            '  PlayStation   4 ',
            'ps4',
            'ps3',
            'ps2',
            'psx',
            'psp',
            'ps vita',
            'xbox series x',
            'xbox one',
            'x360',
            'xbox',
            'nsw',
            'nintendo switch',
            'wii u',
            'wii',
            'gamecube',
            'n64',
            '3ds',
            'nds',
            'gba',
            'gbc',
            'gb',
            'pc',
            'Sega   Dreamcast',
        ];
        foreach ($platforms as $platform) {
            $this->assertSame(
                ConsoleInfo::normalizePlatformPhp($platform),
                ConsoleInfo::normalizePlatformNative($platform),
                'normalizePlatform mismatch for '.var_export($platform, true),
            );
        }

        ConsoleInfo::enableRustFfi();
        $this->assertSame('B08N5WRWNW', ConsoleInfo::normalizeAsin('b08n5wrwnw'));
        $this->assertSame('PlayStation 5', ConsoleInfo::normalizePlatform('  PlayStation   5 '));
        $this->assertSame(
            ['webp' => 'covers/console/15.webp', 'jpg' => 'covers/console/15.jpg'],
            ConsoleInfo::formatCoverCandidateRelativePaths(15),
        );
        $this->assertSame('15', ConsoleInfo::resolveCoverAssetKey(15, true));
    }

    public function test_movie_info_helpers_native_match_php(): void
    {
        $this->requireFfi();

        $searches = [
            '',
            'matrix',
            'The Lord of the Rings: The Fellowship of the Ring (2001)',
            "tt0133093 \t\n 日本語",
            str_repeat('blade-runner-2049-', 25),
        ];
        foreach ($searches as $search) {
            $this->assertSame(
                MovieInfo::buildAllCacheKeyPhp($search),
                MovieInfo::buildAllCacheKeyNative($search),
                'buildAllCacheKey mismatch for '.$search,
            );
        }

        $imdbIds = [
            null,
            '',
            '   ',
            '0',
            '0000000',
            'tt0000000',
            '133093',
            133093,
            '0133093',
            'tt0133093',
            '  TT10872600 ',
            '123456789012',
            '1234567890123',
            'tt12abc34',
        ];
        foreach ($imdbIds as $imdbId) {
            $this->assertSame(
                MovieInfo::normalizeImdbIdPhp($imdbId),
                MovieInfo::normalizeImdbIdNative($imdbId),
                'normalizeImdbId mismatch for '.var_export($imdbId, true),
            );
            $this->assertSame(
                MovieInfo::formatCoverCandidateRelativePathsPhp($imdbId),
                MovieInfo::formatCoverCandidateRelativePathsNative($imdbId),
            );
            $this->assertSame(
                MovieInfo::formatBackdropCandidateRelativePathsPhp($imdbId),
                MovieInfo::formatBackdropCandidateRelativePathsNative($imdbId),
            );
            $this->assertSame(
                MovieInfo::formatImageCandidateRelativePathsPhp($imdbId, 'invalid'),
                MovieInfo::formatImageCandidateRelativePathsNative($imdbId, 'invalid'),
            );
        }

        $csvLists = [
            null,
            '',
            ' , | , ',
            'Action, Sci-Fi, Thriller',
            'Keanu Reeves | Laurence Fishburne | Carrie-Anne Moss , Hugo Weaving',
            "  Drama \t , \n Comedy |  , Adventure  ",
            'SingleValue',
            implode(', ', array_map(static fn (int $i): string => "Actor Name {$i}", range(1, 80))),
        ];
        foreach ($csvLists as $csv) {
            $this->assertSame(
                MovieInfo::splitCommaSeparatedListPhp($csv),
                MovieInfo::splitCommaSeparatedListNative($csv),
            );
        }

        $linkCases = [
            ['tt0133093', 603, 481],
            ['133093', 0, -5],
            [null, 603, null],
            ['invalid', null, 481],
            [null, null, null],
        ];
        foreach ($linkCases as [$imdb, $tmdb, $trakt]) {
            $this->assertSame(
                MovieInfo::buildExternalLinksPhp($imdb, $tmdb, $trakt),
                MovieInfo::buildExternalLinksNative($imdb, $tmdb, $trakt),
            );
        }

        $movie = new MovieInfo;
        $movie->imdbid = '133093';
        $movie->tmdbid = 603;
        $movie->traktid = 481;
        $movie->genre = 'Action, Sci-Fi | Cyber';
        $movie->actors = 'Keanu Reeves, Carrie-Anne Moss';

        MovieInfo::enableRustFfi();
        $this->assertSame(['Action', 'Sci-Fi', 'Cyber'], $movie->getGenresList());
        $this->assertSame(['Keanu Reeves', 'Carrie-Anne Moss'], $movie->getActorsList());
        $this->assertTrue($movie->hasExternalLinks());
        $this->assertSame([
            'imdb' => 'https://www.imdb.com/title/tt0133093/',
            'tmdb' => 'https://www.themoviedb.org/movie/603',
            'trakt' => 'https://trakt.tv/movies/481',
        ], $movie->getExternalLinks());
    }

    public function test_tv_lookup_status_helpers_native_match_php(): void
    {
        $this->requireFfi();

        foreach (TvLookupStatus::cases() as $case) {
            $this->assertSame($case->labelPhp(), $case->labelNative());
            $this->assertSame($case->descriptionPhp(), $case->descriptionNative());
            $this->assertSame($case->isRetryablePhp(), $case->isRetryableNative());
            $this->assertSame($case->isTerminalPhp(), $case->isTerminalNative());
            $this->assertSame($case->requiresLinkedShowPhp(), $case->requiresLinkedShowNative());

            TvLookupStatus::enableRustFfi();
            $this->assertSame($case->labelPhp(), $case->label());
            $this->assertSame($case->descriptionPhp(), $case->description());
            $this->assertSame($case->isRetryablePhp(), $case->isRetryable());
            $this->assertSame($case->isTerminalPhp(), $case->isTerminal());
            $this->assertSame($case->requiresLinkedShowPhp(), $case->requiresLinkedShow());
            TvLookupStatus::disableRustFfi();
        }

        $rawValues = [-999, -100, -12, -11, -10, -6, -5, -4, -3, -2, -1, 0, 1, 42, 999999];
        foreach ($rawValues as $value) {
            $this->assertSame(
                TvLookupStatus::classifyValuePhp($value),
                TvLookupStatus::classifyValueNative($value),
                "classifyValue mismatch for {$value}",
            );
            $this->assertSame(
                TvLookupStatus::isMatchedEpisodeIdPhp($value),
                TvLookupStatus::isMatchedEpisodeIdNative($value),
                "isMatchedEpisodeId mismatch for {$value}",
            );
            $this->assertSame(
                TvLookupStatus::isLegacyPendingPhp($value),
                TvLookupStatus::isLegacyPendingNative($value),
                "isLegacyPending mismatch for {$value}",
            );
            $this->assertSame(
                TvLookupStatus::isLegacyExhaustedPhp($value),
                TvLookupStatus::isLegacyExhaustedNative($value),
                "isLegacyExhausted mismatch for {$value}",
            );
            $this->assertSame(
                TvLookupStatus::normalizeLegacyStatusPhp($value),
                TvLookupStatus::normalizeLegacyStatusNative($value),
                "normalizeLegacyStatus mismatch for {$value}",
            );
        }

        foreach ([false, true] as $p) {
            foreach ([false, true] as $e) {
                foreach ([false, true] as $s) {
                    foreach ([false, true] as $f) {
                        $this->assertSame(
                            TvLookupStatus::reprocessableValuesPhp($p, $e, $s, $f),
                            TvLookupStatus::reprocessableValuesNative($p, $e, $s, $f),
                        );
                    }
                }
            }
        }
    }

    public function test_anidb_and_book_collection_helpers_native_match_php(): void
    {
        $this->requireFfi();

        $metaCases = [
            [120, 25, 2, 5, 25],
            [null, null, null, null, 8],
            [0, 15, 1, 1, 0],
            [5000, 100, 50, 50, 100],
        ];
        foreach ($metaCases as [$total, $perPage, $currentPage, $lastPage, $count]) {
            $this->assertSame(
                AnidbCollection::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $count),
                AnidbCollection::resolveMetaNative($total, $perPage, $currentPage, $lastPage, $count),
            );
            $this->assertSame(
                BookCollection::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $count),
                BookCollection::resolveMetaNative($total, $perPage, $currentPage, $lastPage, $count),
            );
        }

        $longQuery = str_repeat('filter%5Bgenre%5D=science-fiction&', 30);
        $urlSets = [
            [
                'https://api.example.test/anidb?page=1',
                'https://api.example.test/anidb?page=5',
                'https://api.example.test/anidb?page=1',
                'https://api.example.test/anidb?page=3',
            ],
            [null, null, null, null],
            [
                'https://api.example.test/books?'.$longQuery.'page=1',
                'https://api.example.test/books?'.$longQuery.'page=10',
                null,
                'https://api.example.test/books?'.$longQuery.'page=2',
            ],
        ];

        foreach ($metaCases as [$total, $perPage, $currentPage, $lastPage, $count]) {
            foreach ($urlSets as [$first, $last, $prev, $next]) {
                $this->assertSame(
                    AnidbCollection::buildPaginationEnvelopePhp($total, $perPage, $currentPage, $lastPage, $count, $first, $last, $prev, $next),
                    AnidbCollection::buildPaginationEnvelopeNative($total, $perPage, $currentPage, $lastPage, $count, $first, $last, $prev, $next),
                );
                $this->assertSame(
                    BookCollection::buildPaginationEnvelopePhp($total, $perPage, $currentPage, $lastPage, $count, $first, $last, $prev, $next),
                    BookCollection::buildPaginationEnvelopeNative($total, $perPage, $currentPage, $lastPage, $count, $first, $last, $prev, $next),
                );
            }
        }

        $request = Request::create('/api/v2/anidb?page=2', 'GET');
        $paginator = new LengthAwarePaginator(
            items: collect([['anidbid' => 1], ['anidbid' => 2]]),
            total: 10,
            perPage: 2,
            currentPage: 2,
            options: ['path' => 'https://api.example.test/api/v2/anidb'],
        );

        $anidbCollection = new AnidbCollection($paginator);
        $bookCollection = new BookCollection($paginator);

        AnidbCollection::enableRustFfi();
        BookCollection::enableRustFfi();

        $this->assertSame($anidbCollection->toArrayPhp($request), $anidbCollection->toArray($request));
        $this->assertSame($bookCollection->toArrayPhp($request), $bookCollection->toArray($request));
        $this->assertSame($anidbCollection->toPaginatedArrayPhp($request), $anidbCollection->toPaginatedArray($request));
        $this->assertSame($bookCollection->toPaginatedArrayPhp($request), $bookCollection->toPaginatedArray($request));
    }

    public function test_fallback_to_php_when_ffi_disabled_for_testing(): void
    {
        foreach ([ConsoleInfo::class, MovieInfo::class, TvLookupStatus::class, AnidbCollection::class, BookCollection::class] as $target) {
            $target::enableRustFfi();
            $target::setFfiForTesting(false);
        }

        $this->assertSame(['webp' => 'covers/console/99.webp', 'jpg' => 'covers/console/99.jpg'], ConsoleInfo::formatCoverCandidateRelativePaths(99));
        $this->assertSame('99', ConsoleInfo::resolveCoverAssetKey(99, true));
        $this->assertSame('B08N5WRWNW', ConsoleInfo::normalizeAsin('b08n5wrwnw'));
        $this->assertSame('Nintendo Switch', ConsoleInfo::normalizePlatform('  Nintendo   Switch  '));

        $this->assertSame(md5('blade'), MovieInfo::buildAllCacheKey('blade'));
        $this->assertSame('0133093', MovieInfo::normalizeImdbId('tt133093'));
        $this->assertSame(['webp' => '0133093-cover.webp', 'jpg' => '0133093-cover.jpg'], MovieInfo::formatCoverCandidateRelativePaths('133093'));
        $this->assertSame(['Action', 'Drama'], MovieInfo::splitCommaSeparatedList('Action, Drama'));
        $this->assertSame(
            ['imdb' => 'https://www.imdb.com/title/tt0133093/', 'tmdb' => null, 'trakt' => null],
            MovieInfo::buildExternalLinks('133093', null, null),
        );

        $this->assertSame('Pending', TvLookupStatus::Pending->label());
        $this->assertTrue(TvLookupStatus::EpisodeMissing->isRetryable());
        $this->assertTrue(TvLookupStatus::ParseFailed->isTerminal());
        $this->assertTrue(TvLookupStatus::SeasonPack->requiresLinkedShow());
        $this->assertSame(TvLookupStatus::Pending, TvLookupStatus::normalizeLegacyStatus(-2));
        $this->assertSame([0, -11, -1, -2, -3], TvLookupStatus::reprocessableValues());

        $this->assertSame(
            ['total' => 5, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            AnidbCollection::resolveMeta(null, null, null, null, 5),
        );
        $this->assertSame(
            ['total' => 5, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
            BookCollection::resolveMeta(null, null, null, null, 5),
        );
    }

    public function test_c_abi_safety_guards_reject_null_unaligned_and_short_buffers_without_partial_writes(): void
    {
        $ffi = $this->requireFfi();

        $outWebpLen = $ffi->new('size_t[1]');
        $outWebpLen[0] = 777777;
        $shortBuf = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $shortBuf[$i] = "\x5A";
        }

        // Null pointer checks
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_console_info_cover_paths(1, 42, null, 0, null, $shortBuf, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_console_info_cover_paths(1, 42, null, 0, $outWebpLen, null, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_console_info_normalize_field(0, null, 4, 1, $shortBuf, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_console_info_normalize_field(99, 'B08N5WRWNW', 10, 1, $shortBuf, 8));

        // Short buffer checks must return -needed and leave buffer & outWebpLen untouched
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_console_info_cover_paths(1, 42, null, 0, $outWebpLen, $shortBuf, 5);
        $this->assertLessThan(-1, $rc);
        $this->assertSame(777777, (int) $outWebpLen[0]);
        $this->assertSame(str_repeat("\x5A", 8), FFI::string($shortBuf, 8));

        // MovieInfo short buffer checks
        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_movie_info_all_cache_key('matrix', 6, $shortBuf, 8);
        $this->assertSame(-32, $rc);
        $this->assertSame(str_repeat("\x5A", 8), FFI::string($shortBuf, 8));

        // @phpstan-ignore method.notFound
        $rc = $ffi->nntmux_movie_info_image_paths('tt0133093', 9, 0, $outWebpLen, $shortBuf, 8);
        $this->assertLessThan(-1, $rc);
        $this->assertSame(777777, (int) $outWebpLen[0]);
        $this->assertSame(str_repeat("\x5A", 8), FFI::string($shortBuf, 8));

        // Unaligned pointer rejection for size_t* and int64_t*
        $rawBytes = $ffi->new('uint8_t[32]');
        $unalignedSizePtr = $ffi->cast('size_t *', FFI::addr($rawBytes[1]));
        $unalignedI64Ptr = $ffi->cast('int64_t *', FFI::addr($rawBytes[1]));

        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_console_info_cover_paths(1, 42, null, 0, $unalignedSizePtr, $shortBuf, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_movie_info_split_csv('a,b', 3, $unalignedSizePtr, $shortBuf, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_tv_lookup_status_reprocessable_values(1, 0, 0, 0, $unalignedI64Ptr, 8));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_anidb_collection_resolve_meta(1, 10, 1, 10, 1, 1, 1, 1, 10, $unalignedI64Ptr, 4));
        // @phpstan-ignore method.notFound
        $this->assertSame(-1, $ffi->nntmux_book_collection_resolve_meta(1, 10, 1, 10, 1, 1, 1, 1, 10, $unalignedI64Ptr, 4));

        // Short int64_t array capacity must not write partial values
        $metaOut = $ffi->new('int64_t[4]');
        for ($i = 0; $i < 4; $i++) {
            $metaOut[$i] = 999999;
        }
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_anidb_collection_resolve_meta(1, 10, 1, 10, 1, 1, 1, 1, 10, $metaOut, 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(-4, $ffi->nntmux_book_collection_resolve_meta(1, 10, 1, 10, 1, 1, 1, 1, 10, $metaOut, 3));
        // @phpstan-ignore method.notFound
        $this->assertSame(-5, $ffi->nntmux_tv_lookup_status_reprocessable_values(1, 0, 0, 0, $metaOut, 3));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(999999, (int) $metaOut[$i]);
        }
    }

    public function test_paired_randomized_benchmark_harness(): void
    {
        if (getenv(self::BENCH_ENV) !== '1') {
            $this->assertNotSame('1', getenv(self::BENCH_ENV));

            return;
        }

        $this->requireFfi();

        $workloads = [
            'ConsoleInfo::common' => [
                'rust' => static fn (): array => [
                    ConsoleInfo::formatCoverCandidateRelativePathsNative(4215),
                    ConsoleInfo::resolveCoverAssetKeyNative(4215, true),
                    ConsoleInfo::normalizeAsinNative('b08n5wrwnw'),
                    ConsoleInfo::normalizePlatformNative('PlayStation   5'),
                ],
                'php' => static fn (): array => [
                    ConsoleInfo::formatCoverCandidateRelativePathsPhp(4215),
                    ConsoleInfo::resolveCoverAssetKeyPhp(4215, true),
                    ConsoleInfo::normalizeAsinPhp('b08n5wrwnw'),
                    ConsoleInfo::normalizePlatformPhp('PlayStation   5'),
                ],
            ],
            'ConsoleInfo::uncommon' => [
                'rust' => static fn (): array => [
                    ConsoleInfo::formatCoverCandidateRelativePathsNative(9876543210),
                    ConsoleInfo::resolveCoverAssetKeyNative(9876543210, true),
                    ConsoleInfo::normalizeAsinNative('  b09v3kxjpb  '),
                    ConsoleInfo::normalizePlatformNative('  Custom   Retro   Console   System  '),
                ],
                'php' => static fn (): array => [
                    ConsoleInfo::formatCoverCandidateRelativePathsPhp(9876543210),
                    ConsoleInfo::resolveCoverAssetKeyPhp(9876543210, true),
                    ConsoleInfo::normalizeAsinPhp('  b09v3kxjpb  '),
                    ConsoleInfo::normalizePlatformPhp('  Custom   Retro   Console   System  '),
                ],
            ],
            'MovieInfo::common' => [
                'rust' => static fn (): array => [
                    MovieInfo::buildAllCacheKeyNative('matrix'),
                    MovieInfo::normalizeImdbIdNative('tt0133093'),
                    MovieInfo::formatCoverCandidateRelativePathsNative('0133093'),
                    MovieInfo::splitCommaSeparatedListNative('Action, Sci-Fi, Thriller'),
                    MovieInfo::buildExternalLinksNative('0133093', 603, 481),
                ],
                'php' => static fn (): array => [
                    MovieInfo::buildAllCacheKeyPhp('matrix'),
                    MovieInfo::normalizeImdbIdPhp('tt0133093'),
                    MovieInfo::formatCoverCandidateRelativePathsPhp('0133093'),
                    MovieInfo::splitCommaSeparatedListPhp('Action, Sci-Fi, Thriller'),
                    MovieInfo::buildExternalLinksPhp('0133093', 603, 481),
                ],
            ],
            'MovieInfo::uncommon' => [
                'rust' => static fn (): array => [
                    MovieInfo::buildAllCacheKeyNative(str_repeat('long-movie-title-search-query-', 16)),
                    MovieInfo::normalizeImdbIdNative('  TT133093  '),
                    MovieInfo::formatBackdropCandidateRelativePathsNative('133093'),
                    MovieInfo::splitCommaSeparatedListNative('Keanu Reeves | Laurence Fishburne | Carrie-Anne Moss , Hugo Weaving , Joe Pantoliano , Marcus Chong , Julian Arahanga , Matt Doran'),
                    MovieInfo::buildExternalLinksNative('133093', 603, 481),
                ],
                'php' => static fn (): array => [
                    MovieInfo::buildAllCacheKeyPhp(str_repeat('long-movie-title-search-query-', 16)),
                    MovieInfo::normalizeImdbIdPhp('  TT133093  '),
                    MovieInfo::formatBackdropCandidateRelativePathsPhp('133093'),
                    MovieInfo::splitCommaSeparatedListPhp('Keanu Reeves | Laurence Fishburne | Carrie-Anne Moss , Hugo Weaving , Joe Pantoliano , Marcus Chong , Julian Arahanga , Matt Doran'),
                    MovieInfo::buildExternalLinksPhp('133093', 603, 481),
                ],
            ],
            'TvLookupStatus::common' => [
                'rust' => static fn (): array => [
                    TvLookupStatus::Pending->labelNative(),
                    TvLookupStatus::EpisodeMissing->isRetryableNative(),
                    TvLookupStatus::ShowNotFound->isTerminalNative(),
                    TvLookupStatus::normalizeLegacyStatusNative(-2),
                    TvLookupStatus::reprocessableValuesNative(true, false, false, false),
                ],
                'php' => static fn (): array => [
                    TvLookupStatus::Pending->labelPhp(),
                    TvLookupStatus::EpisodeMissing->isRetryablePhp(),
                    TvLookupStatus::ShowNotFound->isTerminalPhp(),
                    TvLookupStatus::normalizeLegacyStatusPhp(-2),
                    TvLookupStatus::reprocessableValuesPhp(true, false, false, false),
                ],
            ],
            'TvLookupStatus::uncommon' => [
                'rust' => static fn (): array => [
                    TvLookupStatus::ParseFailed->descriptionNative(),
                    TvLookupStatus::SeasonPack->requiresLinkedShowNative(),
                    TvLookupStatus::classifyValueNative(987654),
                    TvLookupStatus::normalizeLegacyStatusNative(-6),
                    TvLookupStatus::reprocessableValuesNative(true, true, true, true),
                ],
                'php' => static fn (): array => [
                    TvLookupStatus::ParseFailed->descriptionPhp(),
                    TvLookupStatus::SeasonPack->requiresLinkedShowPhp(),
                    TvLookupStatus::classifyValuePhp(987654),
                    TvLookupStatus::normalizeLegacyStatusPhp(-6),
                    TvLookupStatus::reprocessableValuesPhp(true, true, true, true),
                ],
            ],
            'AnidbCollection::common' => [
                'rust' => static fn (): array => [
                    AnidbCollection::resolveMetaNative(120, 25, 2, 5, 25),
                    AnidbCollection::buildPaginationEnvelopeNative(
                        120,
                        25,
                        2,
                        5,
                        25,
                        'https://api.example.test/anidb?page=1',
                        'https://api.example.test/anidb?page=5',
                        'https://api.example.test/anidb?page=1',
                        'https://api.example.test/anidb?page=3',
                    ),
                ],
                'php' => static fn (): array => [
                    AnidbCollection::resolveMetaPhp(120, 25, 2, 5, 25),
                    AnidbCollection::buildPaginationEnvelopePhp(
                        120,
                        25,
                        2,
                        5,
                        25,
                        'https://api.example.test/anidb?page=1',
                        'https://api.example.test/anidb?page=5',
                        'https://api.example.test/anidb?page=1',
                        'https://api.example.test/anidb?page=3',
                    ),
                ],
            ],
            'AnidbCollection::uncommon' => [
                'rust' => static function (): array {
                    $q = str_repeat('filter%5Btype%5D=tv&', 20);

                    return [
                        AnidbCollection::resolveMetaNative(null, null, null, null, 100),
                        AnidbCollection::buildPaginationEnvelopeNative(
                            5000,
                            100,
                            25,
                            50,
                            100,
                            'https://api.example.test/anidb?'.$q.'page=1',
                            'https://api.example.test/anidb?'.$q.'page=50',
                            'https://api.example.test/anidb?'.$q.'page=24',
                            'https://api.example.test/anidb?'.$q.'page=26',
                        ),
                    ];
                },
                'php' => static function (): array {
                    $q = str_repeat('filter%5Btype%5D=tv&', 20);

                    return [
                        AnidbCollection::resolveMetaPhp(null, null, null, null, 100),
                        AnidbCollection::buildPaginationEnvelopePhp(
                            5000,
                            100,
                            25,
                            50,
                            100,
                            'https://api.example.test/anidb?'.$q.'page=1',
                            'https://api.example.test/anidb?'.$q.'page=50',
                            'https://api.example.test/anidb?'.$q.'page=24',
                            'https://api.example.test/anidb?'.$q.'page=26',
                        ),
                    ];
                },
            ],
            'BookCollection::common' => [
                'rust' => static fn (): array => [
                    BookCollection::resolveMetaNative(120, 25, 2, 5, 25),
                    BookCollection::buildPaginationEnvelopeNative(
                        120,
                        25,
                        2,
                        5,
                        25,
                        'https://api.example.test/books?page=1',
                        'https://api.example.test/books?page=5',
                        'https://api.example.test/books?page=1',
                        'https://api.example.test/books?page=3',
                    ),
                ],
                'php' => static fn (): array => [
                    BookCollection::resolveMetaPhp(120, 25, 2, 5, 25),
                    BookCollection::buildPaginationEnvelopePhp(
                        120,
                        25,
                        2,
                        5,
                        25,
                        'https://api.example.test/books?page=1',
                        'https://api.example.test/books?page=5',
                        'https://api.example.test/books?page=1',
                        'https://api.example.test/books?page=3',
                    ),
                ],
            ],
            'BookCollection::uncommon' => [
                'rust' => static function (): array {
                    $q = str_repeat('filter%5Bauthor%5D=asimov&', 20);

                    return [
                        BookCollection::resolveMetaNative(null, null, null, null, 100),
                        BookCollection::buildPaginationEnvelopeNative(
                            5000,
                            100,
                            25,
                            50,
                            100,
                            'https://api.example.test/books?'.$q.'page=1',
                            'https://api.example.test/books?'.$q.'page=50',
                            'https://api.example.test/books?'.$q.'page=24',
                            'https://api.example.test/books?'.$q.'page=26',
                        ),
                    ];
                },
                'php' => static function (): array {
                    $q = str_repeat('filter%5Bauthor%5D=asimov&', 20);

                    return [
                        BookCollection::resolveMetaPhp(null, null, null, null, 100),
                        BookCollection::buildPaginationEnvelopePhp(
                            5000,
                            100,
                            25,
                            50,
                            100,
                            'https://api.example.test/books?'.$q.'page=1',
                            'https://api.example.test/books?'.$q.'page=50',
                            'https://api.example.test/books?'.$q.'page=24',
                            'https://api.example.test/books?'.$q.'page=26',
                        ),
                    ];
                },
            ],
        ];

        $summary = [];
        foreach ($workloads as $name => $pair) {
            $rustFn = $pair['rust'];
            $phpFn = $pair['php'];

            // Warmup
            for ($w = 0; $w < 10; $w++) {
                $rustFn();
                $phpFn();
            }

            $worstMeanSpeedup = INF;
            $worstP99Speedup = INF;
            $lastStats = [];

            for ($rep = 0; $rep < self::BENCH_REPS; $rep++) {
                $rustSamples = [];
                $phpSamples = [];

                for ($s = 0; $s < self::BENCH_SAMPLES; $s++) {
                    $rustFirst = (($rep * 97 + $s * 31) & 1) === 0;
                    if ($rustFirst) {
                        $t0 = hrtime(true);
                        for ($i = 0; $i < self::BENCH_INNER; $i++) {
                            $rustFn();
                        }
                        $rustSamples[] = (hrtime(true) - $t0) / self::BENCH_INNER;

                        $t0 = hrtime(true);
                        for ($i = 0; $i < self::BENCH_INNER; $i++) {
                            $phpFn();
                        }
                        $phpSamples[] = (hrtime(true) - $t0) / self::BENCH_INNER;
                    } else {
                        $t0 = hrtime(true);
                        for ($i = 0; $i < self::BENCH_INNER; $i++) {
                            $phpFn();
                        }
                        $phpSamples[] = (hrtime(true) - $t0) / self::BENCH_INNER;

                        $t0 = hrtime(true);
                        for ($i = 0; $i < self::BENCH_INNER; $i++) {
                            $rustFn();
                        }
                        $rustSamples[] = (hrtime(true) - $t0) / self::BENCH_INNER;
                    }
                }

                sort($rustSamples);
                sort($phpSamples);

                $rustMean = array_sum($rustSamples) / \count($rustSamples);
                $phpMean = array_sum($phpSamples) / \count($phpSamples);
                $p99Idx = (int) floor(0.99 * (\count($rustSamples) - 1));
                $rustP99 = $rustSamples[$p99Idx];
                $phpP99 = $phpSamples[$p99Idx];

                $meanSpeedup = $phpMean / max(1.0, $rustMean);
                $p99Speedup = $phpP99 / max(1.0, $rustP99);

                $worstMeanSpeedup = min($worstMeanSpeedup, $meanSpeedup);
                $worstP99Speedup = min($worstP99Speedup, $p99Speedup);
                $lastStats = [
                    'rust_mean_ns' => round($rustMean, 1),
                    'php_mean_ns' => round($phpMean, 1),
                    'rust_p99_ns' => round($rustP99, 1),
                    'php_p99_ns' => round($phpP99, 1),
                    'worst_mean_speedup' => round($worstMeanSpeedup, 3),
                    'worst_p99_speedup' => round($worstP99Speedup, 3),
                ];
            }

            $summary[$name] = $lastStats;
        }

        fwrite(STDERR, "\n".json_encode($summary, JSON_PRETTY_PRINT)."\n");
        $this->assertNotEmpty($summary);
    }
}
