<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Resources\MovieCollection;
use App\Models\AnidbInfo;
use App\Models\BookInfo;
use App\Models\GamesInfo;
use App\Models\MovieInfo;
use App\Services\IGDB\Client;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 19 Slice 19.5:
 * AnidbInfo, BookInfo, GamesInfo, MovieCollection, and IGDB\Client.
 *
 * Benchmarks run only with NNTMUX_METADATA_ANIDB_BOOK_GAMES_IGDB_MOVIE_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_METADATA_ANIDB_BOOK_GAMES_IGDB_MOVIE_BENCH=1 php artisan test --compact tests/Unit/RustMetadataAnidbBookGamesIgdbMovieParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                         | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |------------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | AnidbInfo::getExternalLinks                    | 1.62x / 1.49x       | 1.38x / 1.07x         | No      | PHP (false)  |
 * | AnidbInfo::formatPictureCandidateRelativePaths | 0.32x / 0.33x       | 0.31x / 0.29x         | No      | PHP (false)  |
 * | BookInfo::formatCoverCandidateRelativePaths    | 0.34x / 0.22x       | 0.36x / 0.35x         | No      | PHP (false)  |
 * | GamesInfo::resolveImageCandidatePaths          | 0.33x / 0.30x       | 0.31x / 0.27x         | No      | PHP (false)  |
 * | MovieCollection::toArray                       | 0.47x / 0.41x       | 0.40x / 0.29x         | No      | PHP (false)  |
 * | IGDB\Client::buildQueryCacheKey                | 0.45x / 0.31x       | 0.76x / 0.63x         | No      | PHP (false)  |
 * | IGDB\Client::normalizeRequestTarget            | 0.17x / 0.11x       | 0.19x / 0.20x         | No      | PHP (false)  |
 */
class RustMetadataAnidbBookGamesIgdbMovieParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        AnidbInfo::class,
        BookInfo::class,
        GamesInfo::class,
        MovieCollection::class,
        Client::class,
    ];

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', AnidbInfo::FFI_CDEF) === null) {
            $this->markTestSkipped('Rust pipeline library is not built; run cargo build --release.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];

        foreach (array_reverse($this->tempDirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        $this->tempDirs = [];

        $this->resetFfiState();
        parent::tearDown();
    }

    private function resetFfiState(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(null);
            $class::setFfiForTesting(null);
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->tempDirs[] = $dir;
        }
    }

    private function touchTempFile(string $path): void
    {
        $this->ensureDir(\dirname($path));
        file_put_contents($path, 'img');
        $this->tempFiles[] = $path;
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

    // ---------------------------------------------------------------------
    // 1. AnidbInfo
    // ---------------------------------------------------------------------

    #[DataProvider('anidbProviderLinksProvider')]
    public function test_anidb_info_urls_links_and_paths_parity(
        mixed $anidbid,
        mixed $anilistId,
        mixed $malId,
        mixed $picture,
    ): void {
        $this->assertSame(
            AnidbInfo::formatPictureCandidateRelativePathsPhp($anidbid),
            AnidbInfo::formatPictureCandidateRelativePathsNative($anidbid),
        );
        $this->assertSame(
            AnidbInfo::formatProviderUrlPhp(0, $anidbid),
            AnidbInfo::formatProviderUrlNative(0, $anidbid),
        );
        $this->assertSame(
            AnidbInfo::formatProviderUrlPhp(1, $anilistId),
            AnidbInfo::formatProviderUrlNative(1, $anilistId),
        );
        $this->assertSame(
            AnidbInfo::formatProviderUrlPhp(2, $malId),
            AnidbInfo::formatProviderUrlNative(2, $malId),
        );
        $this->assertSame(
            AnidbInfo::buildExternalLinksPhp($anidbid, $anilistId, $malId),
            AnidbInfo::buildExternalLinksNative($anidbid, $anilistId, $malId),
        );

        $model = new AnidbInfo([
            'anidbid' => $anidbid,
            'anilist_id' => $anilistId,
            'mal_id' => $malId,
            'picture' => $picture,
            'updated' => '2026-01-15 12:00:00',
        ]);

        $this->assertSame($model->getPicturePathPhp(), $model->getPicturePathNative());
        $this->assertSame($model->hasPictureImagePhp(), $model->hasPictureImageNative());
        $this->assertSame($model->getPictureUrlPhp(), $model->getPictureUrlNative());
        $this->assertSame($model->getAnidbUrlPhp(), $model->getAnidbUrlNative());
        $this->assertSame($model->getAnilistUrlPhp(), $model->getAnilistUrlNative());
        $this->assertSame($model->getMalUrlPhp(), $model->getMalUrlNative());
        $this->assertSame($model->getMyAnimeListUrlPhp(), $model->getMyAnimeListUrlNative());
        $this->assertSame($model->getExternalLinksPhp(), $model->getExternalLinksNative());
        $this->assertSame($model->hasExternalLinksPhp(), $model->hasExternalLinksNative());

        foreach ([true, false, null] as $toggle) {
            AnidbInfo::setUseRustFfi($toggle);
            $this->assertSame($model->getPicturePathPhp(), $model->getPicturePath());
            $this->assertSame($model->hasPictureImagePhp(), $model->hasPictureImage());
            $this->assertSame($model->getPictureUrlPhp(), $model->getPictureUrl());
            $this->assertSame($model->getAnidbUrlPhp(), $model->getAnidbUrl());
            $this->assertSame($model->getAnilistUrlPhp(), $model->getAnilistUrl());
            $this->assertSame($model->getMalUrlPhp(), $model->getMalUrl());
            $this->assertSame($model->getMyAnimeListUrlPhp(), $model->getMyAnimeListUrl());
            $this->assertSame($model->getExternalLinksPhp(), $model->getExternalLinks());
            $this->assertSame($model->hasExternalLinksPhp(), $model->hasExternalLinks());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed, 3: mixed}>
     */
    public static function anidbProviderLinksProvider(): array
    {
        return [
            'all three providers present with direct url' => [12345, 9253, 5114, 'https://cdn.anidb.net/images/main/12345.jpg'],
            'anidb only with missing local cover' => [23, null, null, '23.jpg'],
            'anilist and mal only' => [0, 154587, 52991, ''],
            'all empty' => [null, '', 0, null],
            'zero strings' => ['0', '0', '0', '0'],
            'double zero strings' => ['00', '00', '00', '00.jpg'],
            'string ids with special bytes' => ['42', '99', '100', 'http://images.example.com:8080/anime/42.webp?v=1'],
            'nul byte in anidbid declines cleanly' => ["42\0id", 10, 20, 'local.jpg'],
        ];
    }

    public function test_anidb_info_local_picture_variants_parity(): void
    {
        $anidbid = 9919501;
        $model = new AnidbInfo([
            'anidbid' => $anidbid,
            'picture' => 'local_cover.jpg',
            'updated' => '2026-01-15 12:00:00',
        ]);

        // 1. Old legacy path (<anidbid>.jpg)
        $oldLegacy = storage_path("covers/anime/{$anidbid}.jpg");
        $this->touchTempFile($oldLegacy);
        $this->assertTrue($model->hasPictureImagePhp());
        $this->assertTrue($model->hasPictureImageNative());
        $this->assertSame($model->getPictureUrlPhp(), $model->getPictureUrlNative());
        @unlink($oldLegacy);

        // 2. Legacy cover path (<anidbid>-cover.jpg)
        $legacy = storage_path("covers/anime/{$anidbid}-cover.jpg");
        $this->touchTempFile($legacy);
        $this->assertTrue($model->hasPictureImagePhp());
        $this->assertTrue($model->hasPictureImageNative());
        $this->assertSame($model->getPictureUrlPhp(), $model->getPictureUrlNative());
        @unlink($legacy);

        // 3. Primary webp path (<anidbid>-cover.webp)
        $webp = storage_path("covers/anime/{$anidbid}-cover.webp");
        $this->touchTempFile($webp);
        $this->assertTrue($model->hasPictureImagePhp());
        $this->assertTrue($model->hasPictureImageNative());
        $this->assertSame($model->getPictureUrlPhp(), $model->getPictureUrlNative());
    }

    // ---------------------------------------------------------------------
    // 2. BookInfo
    // ---------------------------------------------------------------------

    #[DataProvider('bookInfoProvider')]
    public function test_book_info_paths_and_urls_parity(mixed $id, mixed $cover): void
    {
        $this->assertSame(
            BookInfo::formatCoverCandidateRelativePathsPhp($id),
            BookInfo::formatCoverCandidateRelativePathsNative($id),
        );
        $this->assertSame(
            BookInfo::resolveCoverAssetKeyPhp($id, $cover),
            BookInfo::resolveCoverAssetKeyNative($id, $cover),
        );

        $model = new BookInfo([
            'title' => 'Designing Data-Intensive Applications',
            'author' => 'Martin Kleppmann',
            'genre' => 'Distributed Systems',
            'cover' => $cover,
        ]);
        $model->id = $id;

        $this->assertSame($model->getCoverPathPhp(), $model->getCoverPathNative());
        $this->assertSame($model->hasCoverImagePhp(), $model->hasCoverImageNative());
        $this->assertSame($model->getCoverUrlPhp(), $model->getCoverUrlNative());

        foreach ([true, false, null] as $toggle) {
            BookInfo::setUseRustFfi($toggle);
            $this->assertSame($model->getCoverPathPhp(), $model->getCoverPath());
            $this->assertSame($model->hasCoverImagePhp(), $model->hasCoverImage());
            $this->assertSame($model->getCoverUrlPhp(), $model->getCoverUrl());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function bookInfoProvider(): array
    {
        return [
            'positive int with cover' => [101, 1],
            'positive int without cover' => [101, 0],
            'zero id' => [0, 1],
            'negative id' => [-15, 1],
            'null id' => [null, 1],
            'numeric string id' => ['42', true],
            'padded numeric string id' => ['0042', true],
            'whitespace numeric string id' => [' 42 ', true],
            'max i64 string id' => ['9223372036854775807', true],
            'overflow i64 string id' => ['9223372036854775808', true],
            'alphanumeric string id' => ['42abc', true],
            'non-numeric string id' => ['abc', true],
        ];
    }

    public function test_book_info_existing_local_cover_parity(): void
    {
        $bookId = 9919502;
        $model = new BookInfo(['title' => 'Book', 'author' => 'Author', 'genre' => 'Tech', 'cover' => 1]);
        $model->id = $bookId;

        $legacyJpg = storage_path("covers/book/{$bookId}.jpg");
        $this->touchTempFile($legacyJpg);
        $this->assertTrue($model->hasCoverImagePhp());
        $this->assertTrue($model->hasCoverImageNative());
        $this->assertSame($model->getCoverUrlPhp(), $model->getCoverUrlNative());
        @unlink($legacyJpg);

        $webp = storage_path("covers/book/{$bookId}.webp");
        $this->touchTempFile($webp);
        $this->assertTrue($model->hasCoverImagePhp());
        $this->assertTrue($model->hasCoverImageNative());
        $this->assertSame($model->getCoverUrlPhp(), $model->getCoverUrlNative());
    }

    // ---------------------------------------------------------------------
    // 3. GamesInfo
    // ---------------------------------------------------------------------

    public function test_games_info_cover_and_backdrop_paths_parity(): void
    {
        $tmpRoot = sys_get_temp_dir().'/nntmux_games_test_'.uniqid();
        $gamesDir = $tmpRoot.'/games';
        $this->ensureDir($tmpRoot);
        $this->ensureDir($gamesDir);
        config(['nntmux_settings.covers_path' => $tmpRoot]);

        $gameId = 777;
        $model = new GamesInfo([
            'title' => 'Half-Life 2',
            'cover' => true,
            'backdrop' => true,
            'trailer' => '',
            'classused' => 'Steam',
        ]);
        $model->id = $gameId;

        // 1. Neither file exists -> null
        $this->assertNull($model->getCoverPathPhp());
        $this->assertNull($model->getCoverPathNative());
        $this->assertNull($model->getBackdropPathPhp());
        $this->assertNull($model->getBackdropPathNative());

        // 2. Legacy .jpg exists -> returns .jpg path
        $coverJpg = "{$gamesDir}/{$gameId}.jpg";
        $backdropJpg = "{$gamesDir}/{$gameId}-backdrop.jpg";
        $this->touchTempFile($coverJpg);
        $this->touchTempFile($backdropJpg);
        $this->assertSame($coverJpg, $model->getCoverPathPhp());
        $this->assertSame($coverJpg, $model->getCoverPathNative());
        $this->assertSame($backdropJpg, $model->getBackdropPathPhp());
        $this->assertSame($backdropJpg, $model->getBackdropPathNative());

        // 3. Primary .webp exists -> prefers .webp over .jpg
        $coverWebp = "{$gamesDir}/{$gameId}.webp";
        $backdropWebp = "{$gamesDir}/{$gameId}-backdrop.webp";
        $this->touchTempFile($coverWebp);
        $this->touchTempFile($backdropWebp);
        $this->assertSame($coverWebp, $model->getCoverPathPhp());
        $this->assertSame($coverWebp, $model->getCoverPathNative());
        $this->assertSame($backdropWebp, $model->getBackdropPathPhp());
        $this->assertSame($backdropWebp, $model->getBackdropPathNative());

        // 4. Disabled flags -> returns null even when files exist
        $model->cover = false;
        $model->backdrop = false;
        $this->assertNull($model->getCoverPathPhp());
        $this->assertNull($model->getCoverPathNative());
        $this->assertNull($model->getBackdropPathPhp());
        $this->assertNull($model->getBackdropPathNative());
    }

    // ---------------------------------------------------------------------
    // 4. MovieCollection
    // ---------------------------------------------------------------------

    public function test_movie_collection_paginator_and_plain_collection_parity(): void
    {
        $items = new Collection([
            new MovieInfo(['imdbid' => '0133093', 'title' => 'The Matrix', 'year' => '1999', 'cover' => 0, 'backdrop' => 0]),
            new MovieInfo(['imdbid' => '0234215', 'title' => 'The Matrix Reloaded', 'year' => '2003', 'cover' => 0, 'backdrop' => 0]),
        ]);
        $request = new Request;

        // Plain collection (no paginator methods)
        $plainCollection = new MovieCollection($items);
        $expectedPlain = $plainCollection->toArrayPhp($request);
        $this->assertSame($expectedPlain, $plainCollection->toArrayNative($request));

        // LengthAwarePaginator
        $paginator = new LengthAwarePaginator(
            $items,
            total: 48,
            perPage: 2,
            currentPage: 3,
            options: ['path' => 'https://api.example.test/v2/movies'],
        );
        $paginatedCollection = new MovieCollection($paginator);
        $expectedPaginated = $paginatedCollection->toArrayPhp($request);
        $this->assertSame($expectedPaginated, $paginatedCollection->toArrayNative($request));

        foreach ([true, false, null] as $toggle) {
            MovieCollection::setUseRustFfi($toggle);
            $this->assertSame($expectedPlain, $plainCollection->toArray($request));
            $this->assertSame($expectedPaginated, $paginatedCollection->toArray($request));
        }

        // Direct resolveMeta and buildPaginationEnvelope edge cases
        $metaCases = [
            [null, null, null, null, 0],
            [null, null, null, null, 7],
            [120, 25, 2, 5, 25],
            [0, 10, 1, 1, 0],
        ];
        foreach ($metaCases as [$tot, $pp, $cp, $lp, $cnt]) {
            $this->assertSame(
                MovieCollection::resolveMetaPhp($tot, $pp, $cp, $lp, $cnt),
                MovieCollection::resolveMetaNative($tot, $pp, $cp, $lp, $cnt),
            );
            $this->assertSame(
                MovieCollection::buildPaginationEnvelopePhp($tot, $pp, $cp, $lp, $cnt, 'https://a?p=1', 'https://a?p=5', null, 'https://a?p=3'),
                MovieCollection::buildPaginationEnvelopeNative($tot, $pp, $cp, $lp, $cnt, 'https://a?p=1', 'https://a?p=5', null, 'https://a?p=3'),
            );
        }
    }

    // ---------------------------------------------------------------------
    // 5. IGDB\Client
    // ---------------------------------------------------------------------

    public function test_igdb_client_helpers_and_http_request_parity(): void
    {
        $targets = [
            ['https://api.igdb.com/v4', 'games'],
            ['https://api.igdb.com/v4///', '///games/platforms'],
            ['', '/companies'],
            ['///', '///'],
            ["https://api.igdb.com/v4\0extra/", "/games\0path"],
        ];
        foreach ($targets as [$base, $ep]) {
            $this->assertSame(
                Client::normalizeRequestTargetPhp($base, $ep),
                Client::normalizeRequestTargetNative($base, $ep),
            );
            $this->assertSame(
                Client::buildQueryCacheKeyPhp($ep, 'fields *; limit 10;'),
                Client::buildQueryCacheKeyNative($ep, 'fields *; limit 10;'),
            );
        }

        $ttlValues = [null, false, true, -100, 0, 30, 60, 120, 3600, 86400, '3600', ' 180 ', '0', '-50', '9223372036854775807', '9223372036854775808', '120sec'];
        foreach ($ttlValues as $val) {
            $this->assertSame(
                Client::normalizeCacheLifetimePhp($val),
                Client::normalizeCacheLifetimeNative($val),
            );
            $this->assertSame(
                Client::computeTokenTtlPhp($val),
                Client::computeTokenTtlNative($val),
            );
        }

        $this->assertSame(
            Client::buildRequestHeadersPhp('my-client-id'),
            Client::buildRequestHeadersNative('my-client-id'),
        );
        $this->assertSame(
            Client::buildTokenFormPayloadPhp('my-client-id', 'my-secret'),
            Client::buildTokenFormPayloadNative('my-client-id', 'my-secret'),
        );

        // Full Client::request() execution under both Rust FFI and PHP
        config([
            'igdb.base_url' => 'https://api.igdb.test/v4///',
            'igdb.token_url' => 'https://id.twitch.test/oauth2/token',
            'igdb.credentials.client_id' => 'client-id-195',
            'igdb.credentials.client_secret' => 'client-secret-195',
            'igdb.cache_lifetime' => 300,
        ]);

        foreach ([false, true] as $useRust) {
            Cache::flush();
            Client::setUseRustFfi($useRust);

            Http::fake([
                'https://id.twitch.test/oauth2/token' => Http::response([
                    'access_token' => 'token-195',
                    'expires_in' => 3600,
                ]),
                'https://api.igdb.test/v4/games' => Http::response([
                    ['id' => 42, 'name' => 'Halo'],
                ]),
            ]);

            $client = new Client;
            $res = $client->request('/games', 'fields id,name; limit 1;');
            $this->assertSame([['id' => 42, 'name' => 'Halo']], $res);

            Http::assertSent(static fn (HttpClientRequest $req): bool => $req->url() === 'https://api.igdb.test/v4/games'
                && $req->hasHeader('Client-ID', 'client-id-195')
                && $req->hasHeader('Authorization', 'Bearer token-195'));
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
        $anidbFfi = RustPipelineLibrary::resolveDomain('metadata', AnidbInfo::FFI_CDEF);
        $this->assertNotNull($anidbFfi);
        $small = $anidbFfi->new('char[8]');
        $rawBytes = $anidbFfi->new('uint8_t[24]');
        $unalignedUsize = $anidbFfi->cast('size_t*', FFI::addr($rawBytes[1]));
        $alignedUsize = $anidbFfi->new('size_t[1]');

        $this->assertSame(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_info_picture_paths', '42', 2, $unalignedUsize, $alignedUsize, $small, 8));
        $this->assertLessThan(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_info_picture_paths', '42', 2, $alignedUsize, $alignedUsize, $small, 8));
        $this->assertSame(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_info_provider_url', 99, '42', 2, 1, $small, 8));
        $this->assertSame(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_info_external_links', '1', 1, 1, '', 0, 0, '', 0, 0, $unalignedUsize, $small, 8));

        $bookFfi = RustPipelineLibrary::resolveDomain('metadata', BookInfo::FFI_CDEF);
        $this->assertNotNull($bookFfi);
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_info_cover_paths', 1, 7, null, 0, $unalignedUsize, $small, 8));
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_info_cover_paths', 2, 0, '9223372036854775808', 19, $alignedUsize, $small, 8));

        $gamesFfi = RustPipelineLibrary::resolveDomain('metadata', GamesInfo::FFI_CDEF);
        $this->assertNotNull($gamesFfi);
        $this->assertSame(-1, self::ffiCall($gamesFfi, 'nntmux_games_info_image_paths', '/tmp', 4, '7', 1, 1, 0, $unalignedUsize, $small, 8));

        $mcFfi = RustPipelineLibrary::resolveDomain('metadata', MovieCollection::FFI_CDEF);
        $this->assertNotNull($mcFfi);
        $unalignedI64 = $mcFfi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $this->assertSame(-1, self::ffiCall($mcFfi, 'nntmux_movie_collection_resolve_meta', 1, 10, 1, 10, 1, 1, 1, 1, 10, $unalignedI64, 4));

        $igdbFfi = RustPipelineLibrary::resolveDomain('metadata', Client::FFI_CDEF);
        $this->assertNotNull($igdbFfi);
        $this->assertSame(-43, self::ffiCall($igdbFfi, 'nntmux_igdb_client_query_cache_key', 'games', 5, 'fields *;', 9, $small, 8));
        $this->assertSame(-1, self::ffiCall($igdbFfi, 'nntmux_igdb_client_normalize_target', 'https://a', 9, '/b', 2, $unalignedUsize, $small, 8));
        $this->assertSame(-1, self::ffiCall($igdbFfi, 'nntmux_igdb_client_compute_ttl', 0, 2, 100, null, 0, $unalignedI64));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $anime = new AnidbInfo(['anidbid' => 123, 'anilist_id' => 456, 'mal_id' => 789, 'picture' => 'https://example.com/a.jpg', 'updated' => '2026-01-01']);
        $this->assertSame($anime->getExternalLinksPhp(), $anime->getExternalLinksNative());
        $this->assertSame($anime->getPicturePathPhp(), $anime->getPicturePathNative());
        $this->assertSame($anime->getPictureUrlPhp(), $anime->getPictureUrlNative());

        $book = new BookInfo(['title' => 'T', 'author' => 'A', 'genre' => 'G', 'cover' => 1]);
        $book->id = 55;
        $this->assertSame($book->getCoverPathPhp(), $book->getCoverPathNative());

        $this->assertSame(
            GamesInfo::resolveImageCandidatePathsPhp('/covers', 88, true, true),
            GamesInfo::resolveImageCandidatePathsNative('/covers', 88, true, true),
        );

        $mc = new MovieCollection(new Collection);
        $this->assertSame($mc->toArrayPhp(new Request), $mc->toArrayNative(new Request));

        $this->assertSame(
            Client::buildQueryCacheKeyPhp('games', 'fields *;'),
            Client::buildQueryCacheKeyNative('games', 'fields *;'),
        );
        $this->assertSame(
            Client::normalizeRequestTargetPhp('https://api.igdb.com/v4/', '/games'),
            Client::normalizeRequestTargetNative('https://api.igdb.com/v4/', '/games'),
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_METADATA_ANIDB_BOOK_GAMES_IGDB_MOVIE_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_METADATA_ANIDB_BOOK_GAMES_IGDB_MOVIE_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $anidbCommon = new AnidbInfo(['anidbid' => 12345, 'anilist_id' => 9253, 'mal_id' => 5114, 'updated' => '2026-01-01']);
        $anidbUncommon = new AnidbInfo(['anidbid' => 9876543, 'anilist_id' => null, 'mal_id' => 112233, 'updated' => '2026-01-01']);

        $request = new Request;
        $paginatorCommon = new LengthAwarePaginator(
            new Collection([new MovieInfo(['imdbid' => '0133093', 'title' => 'The Matrix'])]),
            total: 50,
            perPage: 25,
            currentPage: 1,
            options: ['path' => 'https://api.example.test/v2/movies'],
        );
        $mcCommon = new MovieCollection($paginatorCommon);
        $mcUncommon = new MovieCollection(new Collection);

        $workloads = [
            'AnidbInfo::getExternalLinks' => [
                AnidbInfo::class,
                static fn () => $anidbCommon->getExternalLinks(),
                static fn () => $anidbUncommon->getExternalLinks(),
            ],
            'AnidbInfo::formatPictureCandidateRelativePaths' => [
                AnidbInfo::class,
                static fn () => AnidbInfo::formatPictureCandidateRelativePaths(12345),
                static fn () => AnidbInfo::formatPictureCandidateRelativePaths('987654321'),
            ],
            'BookInfo::formatCoverCandidateRelativePaths' => [
                BookInfo::class,
                static fn () => BookInfo::formatCoverCandidateRelativePaths(42),
                static fn () => BookInfo::formatCoverCandidateRelativePaths('9223372036854775807'),
            ],
            'GamesInfo::resolveImageCandidatePaths' => [
                GamesInfo::class,
                static fn () => GamesInfo::resolveImageCandidatePaths('/var/www/storage/covers', 42, true, false),
                static fn () => GamesInfo::resolveImageCandidatePaths('/var/www/storage/covers', 987654, true, true),
            ],
            'MovieCollection::toArray' => [
                MovieCollection::class,
                static fn () => $mcCommon->toArray($request),
                static fn () => $mcUncommon->toArray($request),
            ],
            'IGDB\\Client::buildQueryCacheKey' => [
                Client::class,
                static fn () => Client::buildQueryCacheKey('games', 'fields *,cover.url; search "Halo"; limit 10;'),
                static fn () => Client::buildQueryCacheKey('games/platforms', str_repeat('fields id,name,slug; ', 20)),
            ],
            'IGDB\\Client::normalizeRequestTarget' => [
                Client::class,
                static fn () => Client::normalizeRequestTarget('https://api.igdb.com/v4', 'games'),
                static fn () => Client::normalizeRequestTarget('https://api.igdb.com/v4///', '///games/platforms'),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1905 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
            $qualified = min($worst) >= 1.5;
            $rows[] = sprintf(
                ' * | %-46s | %.2fx / %.2fx       | %.2fx / %.2fx         | %-7s | %-12s |',
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
