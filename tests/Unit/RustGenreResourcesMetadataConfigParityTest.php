<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LookupMode;
use App\Http\Resources\AnidbResource;
use App\Http\Resources\BookResource;
use App\Http\Resources\MovieResource;
use App\Models\AnidbInfo;
use App\Models\BookInfo;
use App\Models\Genre;
use App\Models\MovieInfo;
use App\Services\GenreService;
use App\Support\Configuration\MetadataConfigurationData;
use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use Closure;
use FFI;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Parity, C-ABI contract, fallback, and paired benchmark verification for Wave 18 Worker 5:
 * GenreService, AnidbResource, BookResource, MovieResource, and MetadataConfigurationData.
 *
 * Benchmarks run only with NNTMUX_GENRE_RESOURCES_METADATA_CONFIG_BENCH=1:
 *   cargo build --manifest-path rust/pipeline-ffi/Cargo.toml --release
 *   NNTMUX_GENRE_RESOURCES_METADATA_CONFIG_BENCH=1 php artisan test --compact tests/Unit/RustGenreResourcesMetadataConfigParityTest.php
 *
 * Paired randomized benchmark evidence (3 repetitions x 41 paired samples x 200 inner loops,
 * worst-repetition PHP latency / Rust latency):
 *
 * | Method                                     | Common (Mean / P99) | Uncommon (Mean / P99) | >= 1.5x | Default      |
 * |--------------------------------------------|---------------------|-----------------------|---------|--------------|
 * | GenreService::buildGenresCacheKey          | 0.45x / 0.40x       | 0.62x / 0.60x         | No      | PHP (false)  |
 * | GenreService::normalizeGenresMap           | 0.17x / 0.19x       | 0.16x / 0.17x         | No      | PHP (false)  |
 * | AnidbResource::resolvePictureTarget        | 0.48x / 0.43x       | 0.37x / 0.31x         | No      | PHP (false)  |
 * | AnidbResource::toArray                     | 0.80x / 0.79x       | 0.77x / 0.75x         | No      | PHP (false)  |
 * | BookResource::resolveCoverAssetKey         | 0.16x / 0.20x       | 0.21x / 0.27x         | No      | PHP (false)  |
 * | BookResource::toArray                      | 0.77x / 0.72x       | 0.83x / 0.65x         | No      | PHP (false)  |
 * | MovieResource::resolveCoverAssetKey        | 0.19x / 0.16x       | 0.17x / 0.16x         | No      | PHP (false)  |
 * | MovieResource::toArray                     | 0.70x / 0.58x       | 0.68x / 0.58x         | No      | PHP (false)  |
 * | MetadataConfigurationData::defaults        | 0.37x / 0.32x       | 0.38x / 0.37x         | No      | PHP (false)  |
 * | MetadataConfigurationData::toArray         | 0.28x / 0.25x       | 0.28x / 0.27x         | No      | PHP (false)  |
 */
class RustGenreResourcesMetadataConfigParityTest extends TestCase
{
    /** @var list<class-string> */
    private const array TOGGLED_CLASSES = [
        GenreService::class,
        AnidbResource::class,
        BookResource::class,
        MovieResource::class,
        MetadataConfigurationData::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetFfiState();
        if (RustPipelineLibrary::resolveDomain('metadata', GenreService::FFI_CDEF) === null) {
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
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setUseRustFfi(null);
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

    // ---------------------------------------------------------------------
    // 1. GenreService
    // ---------------------------------------------------------------------

    public function test_genre_service_cache_key_parity(): void
    {
        $types = [
            '',
            '0',
            (string) GenreService::CONSOLE_TYPE,
            (string) GenreService::MUSIC_TYPE,
            (string) GenreService::GAME_TYPE,
            'custom_type',
            "with\0nul",
            '日本語ジャンル',
            str_repeat('type_', 200),
        ];

        foreach ($types as $type) {
            foreach ([false, true] as $activeOnly) {
                $expected = GenreService::buildGenresCacheKeyPhp($type, $activeOnly);
                $this->assertSame(
                    $expected,
                    GenreService::buildGenresCacheKeyNative($type, $activeOnly),
                    'type='.bin2hex($type).' active='.($activeOnly ? '1' : '0')
                );
                foreach ([true, false, null] as $toggle) {
                    GenreService::setUseRustFfi($toggle);
                    $this->assertSame($expected, GenreService::buildGenresCacheKey($type, $activeOnly));
                }
            }
        }
    }

    public function test_genre_service_normalize_map_parity(): void
    {
        $eloquentGenres = new EloquentCollection([
            new Genre(['id' => 1, 'title' => 'Action & Adventure', 'type' => GenreService::GAME_TYPE, 'disabled' => 0]),
            new Genre(['id' => 2, 'title' => 'Role-Playing (RPG)', 'type' => GenreService::GAME_TYPE, 'disabled' => 0]),
            new Genre(['id' => 10, 'title' => 'SYNTHWAVE / ELECTRONIC', 'type' => GenreService::MUSIC_TYPE, 'disabled' => 0]),
        ]);

        $cases = [
            'empty array' => [],
            'empty collection' => new EloquentCollection,
            'eloquent collection' => $eloquentGenres,
            'array rows' => [
                ['id' => 5, 'title' => 'Indie Rock'],
                ['id' => 3, 'title' => 'HIP-HOP'],
                ['id' => 8, 'title' => 'Classical'],
            ],
            'duplicate integer and canonical string keys overwrite in place' => [
                ['id' => 1, 'title' => 'First Title'],
                ['id' => 2, 'title' => 'Second Title'],
                ['id' => '1', 'title' => 'Overwritten First'],
                ['id' => '0', 'title' => 'Zero Key'],
                ['id' => 0, 'title' => 'Zero Key Overwritten'],
            ],
            'non-canonical string keys and boundaries' => [
                ['id' => '-0', 'title' => 'Negative Zero String'],
                ['id' => '01', 'title' => 'Leading Zero String'],
                ['id' => '+1', 'title' => 'Plus One String'],
                ['id' => '-42', 'title' => 'Negative Int Key'],
                ['id' => '9223372036854775807', 'title' => 'Max Int64 Key'],
                ['id' => '9223372036854775808', 'title' => 'Overflow Int64 Key'],
                ['id' => '-9223372036854775808', 'title' => 'Min Int64 Key'],
                ['id' => '-9223372036854775809', 'title' => 'Underflow Int64 Key'],
            ],
            'utf8 and nul bytes in title' => [
                ['id' => 11, 'title' => 'Café ÜBER-Beat 日本語'],
                ['id' => 12, 'title' => "Null\0Inside MIXED"],
            ],
            'stdClass objects' => [
                (object) ['id' => 21, 'title' => 'Sci-Fi'],
                (object) ['id' => 22, 'title' => 'Fantasy'],
            ],
        ];

        foreach ($cases as $label => $genres) {
            $expected = GenreService::normalizeGenresMapPhp($genres);
            $actual = GenreService::normalizeGenresMapNative($genres);
            $this->assertSame($expected, $actual, "normalizeGenresMap failed for: {$label}");

            foreach ([true, false, null] as $toggle) {
                GenreService::setUseRustFfi($toggle);
                $this->assertSame($expected, GenreService::normalizeGenresMap($genres));
            }
        }
    }

    // ---------------------------------------------------------------------
    // 2. AnidbResource
    // ---------------------------------------------------------------------

    #[DataProvider('anidbPictureProvider')]
    public function test_anidb_resource_resolve_picture_and_to_array_parity(mixed $picture, mixed $anidbid): void
    {
        $expectedTarget = AnidbResource::resolvePictureTargetPhp($picture, $anidbid);
        $nativeTarget = AnidbResource::resolvePictureTargetNative($picture, $anidbid);
        $this->assertSame($expectedTarget, $nativeTarget);

        $model = new AnidbInfo([
            'anidbid' => $anidbid,
            'anilist_id' => 9876,
            'mal_id' => 5432,
            'title' => 'Cowboy Bebop',
            'english_title' => 'Cowboy Bebop',
            'original_title' => 'カウボーイビバップ',
            'romaji_title' => 'Cowboy Bebop',
            'type' => 'TV Series',
            'media_type' => 'ANIME',
            'country' => 'JP',
            'startdate' => '1998-04-03',
            'enddate' => '1999-04-24',
            'episodes' => 26,
            'duration' => 24,
            'status' => 'FINISHED',
            'source' => 'ORIGINAL',
            'rating' => '8.80',
            'description' => 'Space bounty hunters.',
            'picture' => $picture,
            'categories' => 'Action, Sci-Fi, Space',
            'characters' => 'Spike Spiegel, Jet Black',
            'creators' => 'Sunrise',
            'related' => 'Cowboy Bebop: Knockin on Heavens Door',
            'similar' => 'Samurai Champloo',
            'hashtag' => '#cowboybebop',
            'updated' => '2026-01-15 12:00:00',
        ]);

        $resource = new AnidbResource($model);
        $request = new Request;

        $expectedUrl = $resource->getPictureUrlPhp();
        $this->assertSame($expectedUrl, $resource->getPictureUrlNative());

        $expectedArray = $resource->toArrayPhp($request);
        $this->assertSame($expectedArray, $resource->toArrayNative($request));

        foreach ([true, false, null] as $toggle) {
            AnidbResource::setUseRustFfi($toggle);
            $this->assertSame($expectedTarget, AnidbResource::resolvePictureTarget($picture, $anidbid));
            $this->assertSame($expectedUrl, $resource->getPictureUrl());
            $this->assertSame($expectedArray, $resource->toArray($request));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function anidbPictureProvider(): array
    {
        return [
            'null picture' => [null, 23],
            'empty string picture' => ['', 23],
            'zero string picture' => ['0', 23],
            'double zero string picture' => ['00', 23],
            'https url' => ['https://cdn.anidb.net/images/main/12345.jpg', 23],
            'http url with port and query' => ['http://images.example.com:8080/anime/23.webp?v=2#top', 23],
            'ftp url' => ['ftp://mirror.example.org/pub/anime/23.jpg', 23],
            'trailing dot domain url' => ['https://cdn.example.com./anime/23.jpg', 23],
            'local filename' => ['12345.jpg', 23],
            'local filename string anidbid' => ['anime_cover.webp', '4567'],
            'url with space rejected by filter_var' => ['https://cdn.example.com/anime 23.jpg', 23],
            'url with unicode rejected by filter_var' => ['https://cdn.example.com/ü.jpg', 23],
            'url with invalid port 65536' => ['https://cdn.example.com:65536/23.jpg', 23],
            'url with leading hyphen host' => ['https://-bad.example.com/23.jpg', 23],
            'url with trailing hyphen host' => ['https://bad-.example.com/23.jpg', 23],
            'url with underscore host' => ['https://bad_host.example.com/23.jpg', 23],
            'protocol relative url' => ['//cdn.example.com/23.jpg', 23],
            'mailto exotic url' => ['mailto:admin@example.com', 23],
            'file exotic url' => ['file:///var/covers/23.jpg', 23],
            'ipv6 url' => ['http://[::1]:8080/23.jpg', 23],
            'userinfo url' => ['https://user:pass@example.com/23.jpg', 23],
            'port zero url' => ['https://example.com:0/23.jpg', 23],
            'empty port colon url' => ['https://example.com:/23.jpg', 23],
        ];
    }

    // ---------------------------------------------------------------------
    // 3. BookResource
    // ---------------------------------------------------------------------

    #[DataProvider('bookCoverProvider')]
    public function test_book_resource_cover_and_to_array_parity(mixed $id, mixed $cover, ?string $createdAt): void
    {
        $expectedKey = BookResource::resolveCoverAssetKeyPhp($id, $cover);
        $nativeKey = BookResource::resolveCoverAssetKeyNative($id, $cover);
        $this->assertSame($expectedKey, $nativeKey);

        $model = new BookInfo([
            'id' => $id,
            'title' => 'The Rust Programming Language',
            'author' => 'Steve Klabnik & Carol Nichols',
            'asin' => 'B07SR89259',
            'isbn' => '9781718500440',
            'ean' => '9781718500440',
            'url' => 'https://nostarch.com/rust-programming-language-2nd-edition',
            'salesrank' => 42,
            'publisher' => 'No Starch Press',
            'publishdate' => '2023-02-28',
            'pages' => '560',
            'overview' => 'Official guide to Rust.',
            'genre' => 'Programming',
            'cover' => $cover,
        ]);
        $model->id = $id;
        $model->created_at = $createdAt !== null ? Carbon::parse($createdAt) : null;
        $model->updated_at = $createdAt !== null ? Carbon::parse($createdAt)->addHour() : null;

        $resource = new BookResource($model);
        $request = new Request;

        $expectedUrl = $resource->getCoverUrlPhp();
        $this->assertSame($expectedUrl, $resource->getCoverUrlNative());

        $expectedArray = $resource->toArrayPhp($request);
        $this->assertSame($expectedArray, $resource->toArrayNative($request));

        foreach ([true, false, null] as $toggle) {
            BookResource::setUseRustFfi($toggle);
            $this->assertSame($expectedKey, BookResource::resolveCoverAssetKey($id, $cover));
            $this->assertSame($expectedUrl, $resource->getCoverUrl());
            $this->assertSame($expectedArray, $resource->toArray($request));
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: ?string}>
     */
    public static function bookCoverProvider(): array
    {
        return [
            'positive int with cover' => [101, 1, '2026-01-10T08:00:00Z'],
            'positive int without cover' => [101, 0, '2026-01-10T08:00:00Z'],
            'single digit id with cover' => [7, true, null],
            'zero id with cover' => [0, true, null],
            'negative id with cover' => [-5, true, null],
            'null id with cover' => [null, true, null],
            'numeric string id with cover' => ['42', '1', '2026-02-15T12:30:00Z'],
            'padded numeric string id' => ['0042', true, null],
            'whitespace numeric string id' => [' 42 ', true, null],
            'max i64 string id' => ['9223372036854775807', true, null],
            'overflow i64 string id' => ['9223372036854775808', true, null],
            'alphanumeric string id' => ['42abc', true, null],
            'non-numeric string id' => ['abc', true, null],
            'empty string cover' => [50, '', null],
            'zero string cover' => [50, '0', null],
        ];
    }

    // ---------------------------------------------------------------------
    // 4. MovieResource
    // ---------------------------------------------------------------------

    #[DataProvider('movieResourceProvider')]
    public function test_movie_resource_assets_urls_and_to_array_parity(mixed $imdbid, mixed $cover, mixed $backdrop): void
    {
        $this->assertSame(
            MovieResource::resolveCoverAssetKeyPhp($imdbid, $cover),
            MovieResource::resolveCoverAssetKeyNative($imdbid, $cover)
        );
        $this->assertSame(
            MovieResource::resolveBackdropAssetKeyPhp($imdbid, $backdrop),
            MovieResource::resolveBackdropAssetKeyNative($imdbid, $backdrop)
        );
        $this->assertSame(
            MovieResource::formatImdbUrlPhp($imdbid),
            MovieResource::formatImdbUrlNative($imdbid)
        );

        $model = new MovieInfo([
            'imdbid' => $imdbid,
            'tmdbid' => 603,
            'traktid' => 481,
            'title' => 'The Matrix',
            'year' => '1999',
            'rating' => '8.7',
            'rtrating' => '88%',
            'tagline' => 'Welcome to the Real World.',
            'plot' => 'A computer hacker learns about the true nature of his reality.',
            'genre' => 'Action, Sci-Fi',
            'type' => 'movie',
            'director' => 'Lana Wachowski, Lilly Wachowski',
            'actors' => 'Keanu Reeves, Laurence Fishburne, Carrie-Anne Moss',
            'language' => 'English',
            'cover' => $cover,
            'backdrop' => $backdrop,
            'trailer' => 'https://www.youtube.com/watch?v=vKQi3bBA1y8',
        ]);
        $model->id = 55;
        $model->created_at = Carbon::parse('2026-01-01T00:00:00Z');
        $model->updated_at = Carbon::parse('2026-01-02T00:00:00Z');

        $resource = new MovieResource($model);
        $request = new Request;

        $this->assertSame($resource->getCoverUrlPhp(), $resource->getCoverUrlNative());
        $this->assertSame($resource->getBackdropUrlPhp(), $resource->getBackdropUrlNative());

        $expectedArray = $resource->toArrayPhp($request);
        $this->assertSame($expectedArray, $resource->toArrayNative($request));

        foreach ([true, false, null] as $toggle) {
            MovieResource::setUseRustFfi($toggle);
            $this->assertSame($expectedArray, $resource->toArray($request));
            $this->assertSame($resource->getCoverUrlPhp(), $resource->getCoverUrl());
            $this->assertSame($resource->getBackdropUrlPhp(), $resource->getBackdropUrl());
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: mixed}>
     */
    public static function movieResourceProvider(): array
    {
        return [
            'standard imdbid with cover and backdrop' => ['0133093', 1, 1],
            'standard imdbid cover only' => ['0133093', true, false],
            'standard imdbid backdrop only' => ['0133093', 0, 1],
            'standard imdbid neither' => ['0133093', 0, 0],
            'integer imdbid' => [133093, true, true],
            'empty imdbid' => ['', true, true],
            'null imdbid' => [null, true, true],
            'zero string imdbid' => ['0', true, true],
            'double zero string imdbid' => ['00', true, true],
            'zero int imdbid' => [0, true, true],
        ];
    }

    // ---------------------------------------------------------------------
    // 5. MetadataConfigurationData
    // ---------------------------------------------------------------------

    public function test_metadata_configuration_data_defaults_and_to_array_parity(): void
    {
        $phpDefaults = MetadataConfigurationData::defaultsPhp();
        $nativeDefaults = MetadataConfigurationData::defaultsNative();
        $this->assertEquals($phpDefaults, $nativeDefaults);
        $this->assertSame($phpDefaults->toArrayPhp(), $nativeDefaults->toArrayNative());
        $this->assertCount(15, $nativeDefaults->toArrayNative());

        foreach ([true, false, null] as $toggle) {
            MetadataConfigurationData::setUseRustFfi($toggle);
            $dispatched = MetadataConfigurationData::defaults();
            $this->assertEquals($phpDefaults, $dispatched);
            $this->assertSame($phpDefaults->toArrayPhp(), $dispatched->toArray());
        }

        $cases = [
            new MetadataConfigurationData(
                LookupMode::Disabled,
                LookupMode::All,
                LookupMode::Renamed,
                LookupMode::All,
                LookupMode::Disabled,
                LookupMode::Renamed,
                'en',
                false,
                100,
                300,
                150,
                100,
                150,
                75,
                1000,
            ),
            new MetadataConfigurationData(
                LookupMode::Renamed,
                LookupMode::Disabled,
                LookupMode::All,
                LookupMode::Renamed,
                LookupMode::All,
                LookupMode::Disabled,
                'de-DE',
                true,
                0,
                -1,
                PHP_INT_MAX,
                PHP_INT_MIN,
                500,
                250,
                0,
            ),
            new MetadataConfigurationData(
                LookupMode::All,
                LookupMode::All,
                LookupMode::All,
                LookupMode::All,
                LookupMode::All,
                LookupMode::All,
                "ja\0JP",
                true,
                1,
                2,
                3,
                4,
                5,
                6,
                7,
            ),
        ];

        foreach ($cases as $idx => $config) {
            $expected = $config->toArrayPhp();
            $this->assertSame($expected, $config->toArrayNative(), "config case {$idx}");
            foreach ([true, false, null] as $toggle) {
                MetadataConfigurationData::setUseRustFfi($toggle);
                $this->assertSame($expected, $config->toArray());
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

    public function test_c_abi_null_overflow_alignment_and_buffer_contracts(): void
    {
        $genreFfi = RustPipelineLibrary::resolveDomain('metadata', GenreService::FFI_CDEF);
        $this->assertNotNull($genreFfi);
        $small = $genreFfi->new('char[8]');
        $this->assertSame(-39, self::ffiCall($genreFfi, 'nntmux_genre_service_cache_key', '3000', 4, 1, $small, 8));
        $this->assertSame(-1, self::ffiCall($genreFfi, 'nntmux_genre_service_cache_key', null, 4, 1, $small, 8));
        $this->assertSame(-1, self::ffiCall($genreFfi, 'nntmux_genre_service_cache_key', '3000', 4, 1, null, 8));
        $this->assertSame(-1, self::ffiCall($genreFfi, 'nntmux_genre_service_normalize_map', 'invalid', 7, $small, 8));

        $anidbFfi = RustPipelineLibrary::resolveDomain('metadata', AnidbResource::FFI_CDEF);
        $this->assertNotNull($anidbFfi);
        $mode = $anidbFfi->new('int32_t[1]');
        $this->assertSame(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_resource_resolve_picture', '1.jpg', 5, 1, '42', 2, null, $small, 8));
        $this->assertSame(-11, self::ffiCall($anidbFfi, 'nntmux_anidb_resource_resolve_picture', '1.jpg', 5, 1, '42', 2, $mode, $small, 8));

        // Unaligned out_mode pointer must decline (-1) cleanly
        $rawBytes = $anidbFfi->new('uint8_t[16]');
        $unalignedMode = $anidbFfi->cast('int32_t*', FFI::addr($rawBytes[1]));
        $this->assertSame(-1, self::ffiCall($anidbFfi, 'nntmux_anidb_resource_resolve_picture', '1.jpg', 5, 1, '42', 2, $unalignedMode, $small, 8));

        $bookFfi = RustPipelineLibrary::resolveDomain('metadata', BookResource::FFI_CDEF);
        $this->assertNotNull($bookFfi);
        $this->assertSame(-2, self::ffiCall($bookFfi, 'nntmux_book_resource_resolve_cover_key', 1, 7, null, 0, 1, null, 0));
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_resource_resolve_cover_key', 99, 7, null, 0, 1, $small, 8));
        $this->assertSame(-1, self::ffiCall($bookFfi, 'nntmux_book_resource_resolve_cover_key', 2, 0, '9223372036854775808', 19, 1, $small, 8));

        $movieFfi = RustPipelineLibrary::resolveDomain('metadata', MovieResource::FFI_CDEF);
        $this->assertNotNull($movieFfi);
        $this->assertSame(-1, self::ffiCall($movieFfi, 'nntmux_movie_resource_format_asset_or_url', '0133093', 7, 1, 1, 99, $small, 8));
        $this->assertSame(-13, self::ffiCall($movieFfi, 'nntmux_movie_resource_format_asset_or_url', '0133093', 7, 1, 1, 0, $small, 8));

        $cfgFfi = RustPipelineLibrary::resolveDomain('metadata', MetadataConfigurationData::FFI_CDEF);
        $this->assertNotNull($cfgFfi);
        $ints = $cfgFfi->new('int64_t[14]');
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_metadata_config_defaults', null, 14, $small, 8));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_metadata_config_defaults', $ints, 13, $small, 8));
        $unalignedInts = $cfgFfi->cast('int64_t*', FFI::addr($rawBytes[1]));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_metadata_config_defaults', $unalignedInts, 14, $small, 8));
        $this->assertSame(-1, self::ffiCall($cfgFfi, 'nntmux_metadata_config_to_array', 99, 1, 1, 1, 1, 1, 'en', 2, 0, 100, 300, 150, 100, 150, 75, 1000, $small, 8));
    }

    public function test_native_methods_fall_back_to_php_when_ffi_unavailable(): void
    {
        foreach (self::TOGGLED_CLASSES as $class) {
            $class::setFfiForTesting(false);
            $class::setUseRustFfi(true);
        }

        $this->assertSame(
            GenreService::buildGenresCacheKeyPhp('3000', true),
            GenreService::buildGenresCacheKeyNative('3000', true)
        );
        $this->assertSame(
            [1 => 'rock', 2 => 'jazz'],
            GenreService::normalizeGenresMapNative([['id' => 1, 'title' => 'Rock'], ['id' => 2, 'title' => 'JAZZ']])
        );

        $anidb = new AnidbResource(new AnidbInfo(['anidbid' => 10, 'title' => 'Test', 'picture' => 'https://example.com/a.jpg', 'updated' => '2026-01-01']));
        $this->assertSame('https://example.com/a.jpg', $anidb->getPictureUrlNative());
        $this->assertSame($anidb->toArrayPhp(new Request), $anidb->toArrayNative(new Request));

        $bookModel = new BookInfo(['title' => 'Book', 'author' => 'Author', 'genre' => 'Tech', 'cover' => 1]);
        $bookModel->id = 25;
        $book = new BookResource($bookModel);
        $this->assertSame('25', BookResource::resolveCoverAssetKeyNative(25, 1));
        $this->assertSame($book->toArrayPhp(new Request), $book->toArrayNative(new Request));

        $movieModel = new MovieInfo(['imdbid' => '0133093', 'title' => 'Matrix', 'cover' => 1, 'backdrop' => 1]);
        $movieModel->id = 1;
        $movie = new MovieResource($movieModel);
        $this->assertSame('0133093-cover', MovieResource::resolveCoverAssetKeyNative('0133093', 1));
        $this->assertSame('0133093-backdrop', MovieResource::resolveBackdropAssetKeyNative('0133093', 1));
        $this->assertSame('https://www.imdb.com/title/tt0133093/', MovieResource::formatImdbUrlNative('0133093'));
        $this->assertSame($movie->toArrayPhp(new Request), $movie->toArrayNative(new Request));

        $this->assertEquals(MetadataConfigurationData::defaultsPhp(), MetadataConfigurationData::defaultsNative());
        $this->assertSame(
            MetadataConfigurationData::defaultsPhp()->toArrayPhp(),
            MetadataConfigurationData::defaultsPhp()->toArrayNative()
        );
    }

    // ---------------------------------------------------------------------
    // Paired Randomized Benchmarks
    // ---------------------------------------------------------------------

    public function test_paired_randomized_benchmarks(): void
    {
        if (getenv('NNTMUX_GENRE_RESOURCES_METADATA_CONFIG_BENCH') !== '1') {
            $this->markTestSkipped('Set NNTMUX_GENRE_RESOURCES_METADATA_CONFIG_BENCH=1 to run paired Rust/PHP benchmarks.');
        }

        $commonGenres = array_map(
            static fn (int $i): array => ['id' => $i, 'title' => "Genre Title {$i}"],
            range(1, 12)
        );
        $uncommonGenres = array_map(
            static fn (int $i): array => ['id' => (string) $i, 'title' => "Extended International Genre Title {$i} SYNTHWAVE / ROCK"],
            range(1, 80)
        );

        $anidbDirect = new AnidbResource(new AnidbInfo([
            'anidbid' => 23,
            'anilist_id' => 1,
            'mal_id' => 1,
            'title' => 'Cowboy Bebop',
            'type' => 'TV Series',
            'media_type' => 'ANIME',
            'country' => 'JP',
            'episodes' => 26,
            'duration' => 24,
            'status' => 'FINISHED',
            'source' => 'ORIGINAL',
            'rating' => '8.80',
            'description' => 'Bounty hunters in space.',
            'picture' => 'https://cdn.anidb.net/images/main/23.jpg',
            'updated' => '2026-01-15 12:00:00',
        ]));
        $anidbEmptyPic = new AnidbResource(new AnidbInfo([
            'anidbid' => 9999,
            'title' => str_repeat('Long Anime Description ', 8),
            'description' => str_repeat('Detailed synopsis paragraph. ', 20),
            'picture' => '',
            'updated' => '2026-01-15 12:00:00',
        ]));

        $bookModelCommon = new BookInfo([
            'title' => 'Clean Architecture',
            'author' => 'Robert C. Martin',
            'isbn' => '9780134494166',
            'genre' => 'Software Engineering',
            'cover' => 0,
        ]);
        $bookModelCommon->id = 42;
        $bookCommon = new BookResource($bookModelCommon);

        $bookModelUncommon = new BookInfo([
            'title' => str_repeat('Comprehensive Systems Programming ', 4),
            'author' => 'Multiple Authors',
            'overview' => str_repeat('Chapter overview text. ', 25),
            'genre' => 'Computer Science',
            'cover' => 0,
        ]);
        $bookModelUncommon->id = 987654;
        $bookModelUncommon->created_at = Carbon::parse('2026-01-01T00:00:00Z');
        $bookModelUncommon->updated_at = Carbon::parse('2026-01-02T00:00:00Z');
        $bookUncommon = new BookResource($bookModelUncommon);

        $movieModelCommon = new MovieInfo([
            'imdbid' => '0133093',
            'tmdbid' => 603,
            'traktid' => 481,
            'title' => 'The Matrix',
            'year' => '1999',
            'rating' => '8.7',
            'genre' => 'Action, Sci-Fi',
            'cover' => 0,
            'backdrop' => 0,
        ]);
        $movieModelCommon->id = 55;
        $movieCommon = new MovieResource($movieModelCommon);

        $movieModelUncommon = new MovieInfo([
            'imdbid' => '12345678',
            'tmdbid' => 99999,
            'traktid' => 88888,
            'title' => str_repeat('Extended Directors Cut Title ', 3),
            'plot' => str_repeat('Extended plot summary content. ', 20),
            'actors' => str_repeat('Actor Name, ', 15),
            'cover' => 0,
            'backdrop' => 0,
        ]);
        $movieModelUncommon->id = 999;
        $movieUncommon = new MovieResource($movieModelUncommon);

        $cfgDefault = MetadataConfigurationData::defaultsPhp();
        $cfgCustom = new MetadataConfigurationData(
            LookupMode::Renamed,
            LookupMode::All,
            LookupMode::Disabled,
            LookupMode::Renamed,
            LookupMode::All,
            LookupMode::Renamed,
            'de-DE',
            true,
            250,
            500,
            300,
            200,
            350,
            150,
            2500,
        );
        $request = new Request;

        $workloads = [
            'GenreService::buildGenresCacheKey' => [
                GenreService::class,
                static fn () => GenreService::buildGenresCacheKey('3000', true),
                static fn () => GenreService::buildGenresCacheKey(str_repeat('custom_genre_type_', 10), false),
            ],
            'GenreService::normalizeGenresMap' => [
                GenreService::class,
                static fn () => GenreService::normalizeGenresMap($commonGenres),
                static fn () => GenreService::normalizeGenresMap($uncommonGenres),
            ],
            'AnidbResource::resolvePictureTarget' => [
                AnidbResource::class,
                static fn () => AnidbResource::resolvePictureTarget('https://cdn.anidb.net/images/main/23.jpg', 23),
                static fn () => AnidbResource::resolvePictureTarget('23456_local_cover_file.webp', 23456),
            ],
            'AnidbResource::toArray' => [
                AnidbResource::class,
                static fn () => $anidbDirect->toArray($request),
                static fn () => $anidbEmptyPic->toArray($request),
            ],
            'BookResource::resolveCoverAssetKey' => [
                BookResource::class,
                static fn () => BookResource::resolveCoverAssetKey(42, true),
                static fn () => BookResource::resolveCoverAssetKey('9223372036854775807', true),
            ],
            'BookResource::toArray' => [
                BookResource::class,
                static fn () => $bookCommon->toArray($request),
                static fn () => $bookUncommon->toArray($request),
            ],
            'MovieResource::resolveCoverAssetKey' => [
                MovieResource::class,
                static fn () => [
                    MovieResource::resolveCoverAssetKey('0133093', true),
                    MovieResource::resolveBackdropAssetKey('0133093', true),
                ],
                static fn () => [
                    MovieResource::formatImdbUrl('0133093'),
                    MovieResource::resolveCoverAssetKey('', true),
                ],
            ],
            'MovieResource::toArray' => [
                MovieResource::class,
                static fn () => $movieCommon->toArray($request),
                static fn () => $movieUncommon->toArray($request),
            ],
            'MetadataConfigurationData::defaults' => [
                MetadataConfigurationData::class,
                static fn () => MetadataConfigurationData::defaults()->movieLanguage,
                static fn () => MetadataConfigurationData::defaults()->amazonSleepMilliseconds,
            ],
            'MetadataConfigurationData::toArray' => [
                MetadataConfigurationData::class,
                static fn () => $cfgDefault->toArray(),
                static fn () => $cfgCustom->toArray(),
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

                    $stats = $this->runPairedBenchmark($class, $fn, 200, 41, 1805 + $rep);
                    fwrite(STDERR, json_encode(['method' => $name, 'workload' => $kind, 'repetition' => $rep] + $stats).PHP_EOL);
                    $worst["{$kind}_mean"] = min($worst["{$kind}_mean"], $stats['mean_speedup']);
                    $worst["{$kind}_p99"] = min($worst["{$kind}_p99"], $stats['p99_speedup']);
                }
            }
            $class::setUseRustFfi(null);
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
