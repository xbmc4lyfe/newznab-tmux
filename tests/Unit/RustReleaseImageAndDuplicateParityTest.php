<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Release;
use App\Services\Api\ApiReleaseRowCache;
use App\Services\Categorization\CategorizationResult;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseManagementService;
use FFI;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;

/**
 * Wave 14 Slice 5 parity and paired benchmark suite for `ReleaseImageService`,
 * `ReleaseDuplicateFinder`, `ReleaseManagementService`, `CategorizationResult`,
 * and `ApiReleaseRowCache` (`rust/pipeline-ffi/src/media.rs`).
 *
 * Reproduce paired randomized benchmarks:
 *   NNTMUX_RELEASE_IMAGE_DUP_BENCH=1 php artisan test --compact tests/Unit/RustReleaseImageAndDuplicateParityTest.php --filter=test_benchmark_paired_workloads
 *
 * Protocol: seed 20261024; 512 warmups; 3 repetitions of 4,096 randomized paired
 * single calls per workload (common and uncommon); nearest-rank p99; output equality
 * verified before timing. Adoption requires >= 1.5x speedup on all 4 metrics
 * (common mean, common p99, uncommon mean, uncommon p99) per `.ai/rules/rust-performance.md`.
 *
 * Paired speedup results (`PHP latency / Rust FFI latency`, mean/p99 across 3 reps):
 * - `isValidBasename`:         common 0.43x/0.40x, 0.45x/0.50x, 0.42x/0.40x | uncommon 0.49x/0.50x, 0.50x/0.50x, 0.50x/0.50x
 * - `redactedUrl`:             common 0.78x/0.87x, 0.73x/0.87x, 0.74x/0.75x | uncommon 1.01x/1.00x, 0.98x/1.04x, 0.95x/1.00x
 * - `scaleDimensions`:         common 0.55x/0.60x, 0.55x/0.60x, 0.55x/0.67x | uncommon 0.54x/0.60x, 0.55x/0.60x, 0.55x/0.60x
 * - `resolveOutputFormat`:     common 0.30x/0.33x, 0.31x/0.33x, 0.30x/0.33x | uncommon 0.31x/0.33x, 0.31x/0.33x, 0.31x/0.33x
 * - `resolveReason`:           common 0.45x/0.45x, 0.46x/0.45x, 0.46x/0.48x | uncommon 0.49x/0.50x, 0.48x/0.50x, 0.48x/0.50x
 * - `sizeBounds`:              common 0.28x/0.34x, 0.29x/0.34x, 0.28x/0.29x | uncommon 0.28x/0.34x, 0.28x/0.29x, 0.28x/0.29x
 * - `formatExportDateBound`:   common 0.50x/0.57x, 0.49x/0.57x, 0.47x/0.57x | uncommon 0.52x/0.57x, 0.51x/0.57x, 0.52x/0.57x
 * - `isSuccessful`:            common 0.45x/0.50x, 0.45x/0.50x, 0.45x/0.50x | uncommon 0.44x/0.50x, 0.45x/0.50x, 0.45x/0.50x
 * - `isProtectedMiscResult`:   common 0.46x/0.50x, 0.46x/0.50x, 0.46x/0.50x | uncommon 0.48x/0.50x, 0.48x/0.50x, 0.48x/0.50x
 * - `shouldOverride`:          common 0.57x/0.60x, 0.57x/0.60x, 0.56x/0.60x | uncommon 0.50x/0.60x, 0.50x/0.60x, 0.51x/0.60x
 * - `apiCacheNormalize`:       common 0.32x/0.31x, 0.33x/0.35x, 0.32x/0.32x | uncommon 0.39x/0.30x, 0.40x/0.40x, 0.40x/0.39x
 *
 * All methods are sub-microsecond scalar/small-string operations or require JSON
 * marshaling across the FFI boundary, so single-call FFI overhead exceeds the computation
 * cost. Per `.ai/rules/rust-performance.md`, `$useRustFfi = false` is retained as the
 * production default across all five classes while keeping `*Native` and `*Php` methods
 * tested for 100% parity.
 */
final class RustReleaseImageAndDuplicateParityTest extends TestCase
{
    protected function tearDown(): void
    {
        ReleaseImageService::setUseRustFfi(false);
        ReleaseDuplicateFinder::setUseRustFfi(false);
        ReleaseManagementService::setUseRustFfi(false);
        CategorizationResult::setUseRustFfi(false);
        ApiReleaseRowCache::setUseRustFfi(false);
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function bare(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function ffi(): FFI
    {
        $ffi = ReleaseImageService::mediaFfi();
        self::assertNotNull($ffi, 'Expected libnntmux_pipeline media domain FFI to be available.');

        return $ffi;
    }

    public function test_release_image_basename_validation_matches_php(): void
    {
        self::ffi();
        $service = self::bare(ReleaseImageService::class);

        $cases = [
            'cover',
            'a',
            'Z',
            '0',
            '9',
            'cover_thumb',
            'release-guid-123_thumb',
            'A1_b2-C3',
            str_repeat('a1_-', 128),
            '',
            '.',
            '..',
            '../cover',
            'cover/..',
            'a/b',
            'a\\b',
            "cover\0.webp",
            "\0",
            "cover\n",
            "\ncover",
            '-leading-dash',
            '_leading-underscore',
            ' cover',
            'cover ',
            'cover.jpg',
            'café',
            "bad\xffname",
        ];

        $random = new Randomizer(new Mt19937(14001));
        $alphabet = "abcXYZ019_-./\\\0\n\r\t ";
        for ($i = 0; $i < 300; $i++) {
            $cases[] = $random->getBytesFromString($alphabet, $random->getInt(1, 24));
        }

        foreach ($cases as $basename) {
            $expected = $service->isValidBasenamePhp($basename);
            self::assertSame($expected, $service->isValidBasenameNative($basename), 'native '.bin2hex($basename));
            foreach ([false, true] as $toggle) {
                ReleaseImageService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->isValidBasename($basename), 'dispatch '.bin2hex($basename));
            }
        }
    }

    public function test_release_image_redact_url_matches_php(): void
    {
        self::ffi();
        $service = self::bare(ReleaseImageService::class);

        $urls = [
            'https://images.example.test/cover.png',
            'HTTP://User:Pass@Example.COM:8080/a/b/c.jpg?token=secret#frag',
            'https://example.com',
            'https://example.com/',
            'https://example.com?foo=bar',
            'https://example.com#frag',
            'https://example.com:443?foo=bar',
            'https://[::1]:8080/test?secret=1',
            'https://[2001:db8::1]/cover.webp',
            'http://127.0.0.1:0/test',
            'http://127.0.0.1:0080/test',
            'http://127.0.0.1:000080/test',
            'http://127.0.0.1:65535/test',
            'http://127.0.0.1:65536/test',
            'http://example.com:-1/test',
            'http://example.com:abc/test',
            'http://example.com:80a/test',
            'http://example.com:+80/test',
            'http://example.com: 80/test',
            'http://example.com:/path',
            'http://example.com:?a=1',
            'http://[::1]extra/a',
            'http://[::1]bad/test',
            'http://[abc/path',
            'http://[]/path',
            'invalid-url',
            '',
            '/relative/path',
            '//example.com/path',
            'http:///path',
            'file:///path',
            'file://example.com/path',
            'http://:80/path',
            'ht+tp://example.com/path',
            'ht-tp://example.com/path',
            'ht.tp://example.com/path',
            '1http://example.com/path',
            "http://example.com/path\0withnul",
            "http://example.com/path\nwithnewline",
            "http://example.com/path\twithctrl",
            "http://ex\x01ample.com/pa\x7fth",
            'mailto:user@example.com',
            'http://user@:80',
            'http://user:pa@ss@example.com/path?x=1#y',
            'https://cdn.example.test/'.str_repeat('segment/', 60).'cover.jpg?sig='.str_repeat('a', 512),
        ];

        foreach ($urls as $url) {
            $expected = $service->redactedUrlPhp($url);
            self::assertSame($expected, $service->redactedUrlNative($url), 'native '.json_encode($url));
            foreach ([false, true] as $toggle) {
                ReleaseImageService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->redactedUrl($url), 'dispatch '.json_encode($url));
            }
        }
    }

    public function test_release_image_scale_dimensions_and_output_format_match_php(): void
    {
        self::ffi();
        $service = self::bare(ReleaseImageService::class);

        $dimensionCases = [
            [400, 200, 250, 250],
            [200, 400, 250, 250],
            [1920, 1080, 800, 600],
            [3840, 2160, 300, 450],
            [200, 100, 100, 100],
            [40, 20, 250, 250],
            [250, 250, 250, 250],
            [100, 20, 50, 50],
            [20, 100, 50, 50],
            [22, 22, 11, 11],
            [20, 20, 10, 10],
            [400, 200, null, 250],
            [400, 200, 250, null],
            [400, 200, null, null],
            [0, 200, 100, 100],
            [400, 0, 100, 100],
            [-10, 200, 100, 100],
            [400, 200, 0, 100],
            [400, 200, 100, -5],
            [1337, 751, 250, 350],
            [999, 333, 240, 180],
        ];

        foreach ($dimensionCases as [$w, $h, $mw, $mh]) {
            $expected = $service->scaleDimensionsPhp($w, $h, $mw, $mh);
            self::assertSame($expected, $service->scaleDimensionsNative($w, $h, $mw, $mh), "{$w}x{$h} -> {$mw}x{$mh}");
            foreach ([false, true] as $toggle) {
                ReleaseImageService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->scaleDimensions($w, $h, $mw, $mh));
            }
        }

        foreach (['webp', 'WEBP', 'jpg', 'JPG', 'jpeg', 'JPEG', 'png', 'PNG', '', 'avif', 'gif'] as $format) {
            $expected = $service->resolveOutputFormatPhp($format);
            self::assertSame($expected, $service->resolveOutputFormatNative($format), "format {$format}");
            foreach ([false, true] as $toggle) {
                ReleaseImageService::setUseRustFfi($toggle);
                self::assertSame($expected, $service->resolveOutputFormat($format));
            }
        }
    }

    public function test_release_duplicate_finder_reason_and_size_bounds_match_php(): void
    {
        self::ffi();
        $finder = new ReleaseDuplicateFinder;

        $releases = [];
        foreach ([
            ['predb_id' => 0, 'searchname' => 'Ubuntu.24.04.LTS.ISO'],
            ['predb_id' => 42, 'searchname' => 'Ubuntu.24.04.LTS.ISO'],
            ['predb_id' => 99, 'searchname' => ''],
            ['predb_id' => -1, 'searchname' => 'Debian.12.Netinst'],
        ] as $attrs) {
            $rel = self::bare(Release::class);
            $rel->setRawAttributes($attrs, true);
            $releases[] = $rel;
        }

        $queries = [
            ['Ubuntu.24.04.LTS.ISO', 42],
            ['Ubuntu.24.04.LTS.ISO', 0],
            ['Ubuntu.24.04.LTS.ISO', 99],
            ['Other.Release.Name', 42],
            ['Other.Release.Name', 0],
            ['', 0],
            ['', 42],
            ['Debian.12.Netinst', -1],
        ];

        foreach ($releases as $dup) {
            foreach ($queries as [$searchName, $predbId]) {
                $expected = $finder->resolveReasonPhp($dup, $searchName, $predbId);
                self::assertSame($expected, $finder->resolveReasonNative($dup, $searchName, $predbId));
                foreach ([false, true] as $toggle) {
                    ReleaseDuplicateFinder::setUseRustFfi($toggle);
                    self::assertSame($expected, $finder->resolveReason($dup, $searchName, $predbId));
                }
            }
        }

        $sizeCases = [
            [0, 0.05],
            [1, 0.05],
            [100, 0.05],
            [1024, 0.05],
            [1_468_006_400, 0.05],
            [50_000_000_000, 0.05],
            [123_456_789, 0.0],
            [123_456_789, 0.10],
            [123_456_789, 0.25],
            [999_999_999_999, 0.03],
        ];

        foreach ($sizeCases as [$size, $tolerance]) {
            $expected = $finder->sizeBoundsPhp($size, $tolerance);
            self::assertSame($expected, $finder->sizeBoundsNative($size, $tolerance), "size={$size} tol={$tolerance}");
            foreach ([false, true] as $toggle) {
                ReleaseDuplicateFinder::setUseRustFfi($toggle);
                self::assertSame($expected, $finder->sizeBounds($size, $tolerance));
            }
        }
    }

    public function test_release_management_export_date_conversion_matches_php(): void
    {
        self::ffi();
        $service = new ReleaseManagementService;

        $dates = [
            '',
            '01/01/2014',
            '15/04/2026',
            '12/31/2025',
            '1/2/2024',
            '//',
            'a/b/c',
            '2026-04-15',
            '01/01',
            '01/01/2024/extra',
            '01/02/03/04',
            str_repeat('12', 100).'/05/2026',
        ];

        foreach ($dates as $date) {
            foreach ([false, true] as $isEndOfDay) {
                $expected = $service->formatExportDateBoundPhp($date, $isEndOfDay);
                self::assertSame($expected, $service->formatExportDateBoundNative($date, $isEndOfDay), "date={$date} end=".(int) $isEndOfDay);
                foreach ([false, true] as $toggle) {
                    ReleaseManagementService::setUseRustFfi($toggle);
                    self::assertSame($expected, $service->formatExportDateBound($date, $isEndOfDay));
                }
            }
        }
    }

    public function test_categorization_result_predicates_and_override_match_php(): void
    {
        self::ffi();

        $categories = [
            Category::OTHER_MISC,
            Category::OTHER_HASHED,
            Category::MOVIE_HD,
            Category::TV_HD,
            Category::PC_GAMES,
        ];
        $confidences = [-0.1, 0.0, 0.25, 0.5, 0.5999, 0.6, 0.6001, 0.85, 1.0];
        $matchedBys = [
            'no_match',
            'none',
            'hash_md5',
            'hash_sha1',
            'obfuscated_pattern',
            'gibberish_entropy',
            'group_only_low_signal',
            'archive_extension',
            'tv_hd_regex',
            '',
        ];

        $results = [CategorizationResult::noMatch()];
        foreach ($categories as $cat) {
            foreach ($confidences as $conf) {
                foreach ($matchedBys as $mb) {
                    $results[] = new CategorizationResult($cat, $conf, $mb);
                }
            }
        }

        foreach ($results as $res) {
            self::assertSame($res->isSuccessfulPhp(), $res->isSuccessfulNative());
            self::assertSame($res->isProtectedMiscResultPhp(), $res->isProtectedMiscResultNative());
            foreach ([false, true] as $toggle) {
                CategorizationResult::setUseRustFfi($toggle);
                self::assertSame($res->isSuccessfulPhp(), $res->isSuccessful());
                self::assertSame($res->isProtectedMiscResultPhp(), $res->isProtectedMiscResult());
            }
        }

        // Sample pairwise overrides across all representative combinations
        $sample = [];
        foreach ($categories as $cat) {
            foreach ([0.0, 0.4, 0.6, 0.9] as $conf) {
                foreach (['no_match', 'hash_md5', 'obfuscated_pattern', 'group_only_low_signal', 'tv_hd_regex'] as $mb) {
                    $sample[] = new CategorizationResult($cat, $conf, $mb);
                }
            }
        }

        foreach ($sample as $left) {
            foreach ($sample as $right) {
                $expected = $left->shouldOverridePhp($right);
                self::assertSame($expected, $left->shouldOverrideNative($right));
                foreach ([false, true] as $toggle) {
                    CategorizationResult::setUseRustFfi($toggle);
                    self::assertSame($expected, $left->shouldOverride($right));
                }
            }
        }
    }

    public function test_api_release_row_cache_normalize_matches_php(): void
    {
        self::ffi();
        $cache = new ApiReleaseRowCache;

        $cases = [
            [],
            ['guid' => 'missing'],
            [
                'category' => [5030, 5040, 5030],
                'id' => ' ubuntu ',
            ],
            [
                'z_param' => " \t\nhello world\r\0\x0b ",
                'a_param' => ['x', 'b', 'x', 'a'],
                'm_param' => [
                    'nested_b' => [300, 100, 200, 100],
                    'nested_a' => '  trimmed_value  ',
                    'nested_empty' => [],
                    'flag_true' => true,
                    'flag_false' => false,
                    'null_val' => null,
                    'limit' => 100,
                    'offset' => 0,
                ],
            ],
            [
                'formfeed_preserved' => "\x0ckeep_formfeed\x0c",
                'quotes_and_slashes' => '  "quoted\\path/value"  ',
                'unicode' => '  Pokémon Édition 東京  ',
            ],
            // Fallback cases (floats, numeric keys, mixed lists)
            [
                'float_val' => 1.5,
                'zero_float' => 1.0,
                'id' => ' test ',
            ],
            [
                1 => ' b ',
                0 => ' a ',
            ],
            [
                'mixed_list' => [true, false, null, 10, ' x '],
                'numeric_strings' => ['10', '2', '01', '1'],
            ],
        ];

        foreach ($cases as $idx => $params) {
            $expected = $cache->normalizePhp($params);
            self::assertSame($expected, $cache->normalizeNative($params), "case {$idx}");
            foreach ([false, true] as $toggle) {
                ApiReleaseRowCache::setUseRustFfi($toggle);
                self::assertSame($expected, $cache->normalize($params), "dispatch case {$idx}");
            }
        }

        self::assertSame(
            '{"category":[5030,5040],"id":"ubuntu"}',
            $cache->normalizeJsonNative('{"id":" ubuntu ","category":[5030,5040,5030]}')
        );
    }

    public function test_direct_c_abi_null_and_small_buffer_contracts(): void
    {
        $ffi = self::ffi();
        $small = $ffi->new('char[4]');

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_release_image_is_valid_basename(null, 3));
        // @phpstan-ignore method.notFound
        self::assertSame(0, $ffi->nntmux_release_image_is_valid_basename(null, 0));

        // @phpstan-ignore method.notFound
        $need = $ffi->nntmux_release_image_redact_url('https://example.com/a.jpg', 25, $small, 4);
        self::assertLessThan(-4, $need);

        // @phpstan-ignore method.notFound
        $needDate = $ffi->nntmux_release_management_export_date('15/04/2026', 10, 0, $small, 4);
        self::assertLessThan(-4, $needDate);

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_release_duplicate_size_bounds(100, NAN, null, null));

        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_api_release_cache_normalize_json('{invalid', 8, $small, 4));
    }

    /**
     * Run with:
     * NNTMUX_RELEASE_IMAGE_DUP_BENCH=1 php artisan test --compact tests/Unit/RustReleaseImageAndDuplicateParityTest.php --filter=test_benchmark_paired_workloads
     */
    public function test_benchmark_paired_workloads(): void
    {
        if (getenv('NNTMUX_RELEASE_IMAGE_DUP_BENCH') !== '1') {
            self::markTestSkipped('Set NNTMUX_RELEASE_IMAGE_DUP_BENCH=1 to run paired benchmarks.');
        }

        self::ffi();
        $imageService = self::bare(ReleaseImageService::class);
        $dupFinder = new ReleaseDuplicateFinder;
        $mgmtService = new ReleaseManagementService;
        $apiCache = new ApiReleaseRowCache;

        $dupCommon = self::bare(Release::class);
        $dupCommon->setRawAttributes(['predb_id' => 0, 'searchname' => 'Ubuntu.24.04.LTS.Desktop.amd64.ISO-GRP'], true);
        $dupUncommon = self::bare(Release::class);
        $dupUncommon->setRawAttributes(['predb_id' => 0, 'searchname' => str_repeat('Very.Long.Release.Search.Name.Segment.', 8).'2160p.WEB-DL-GRP'], true);

        $catSelfCommon = new CategorizationResult(Category::TV_HD, 0.85, 'tv_hd_regex');
        $catOtherCommon = new CategorizationResult(Category::OTHER_MISC, 0.5, 'group_only_low_signal');
        $catSelfUncommon = new CategorizationResult(Category::MOVIE_HD, 0.55, 'movie_fallback_heuristic');
        $catOtherUncommon = new CategorizationResult(Category::OTHER_MISC, 0.5, 'obfuscated_pattern_long_rule_name');

        $cacheCommonParams = [
            't' => 'search',
            'q' => '  ubuntu 24.04  ',
            'cat' => [5040, 5030, 5040, 2040],
            'extended' => 1,
            'limit' => 100,
            'offset' => 0,
        ];
        $cacheUncommonParams = [
            'z_query' => '  '.str_repeat('linux distribution iso ', 6).'  ',
            'y_categories' => [5040, 5030, 2040, 2030, 5040, 5030, 7020, 8010, 2040, 3010, 4050, 5070, 6010, 6020, 6030, 6040],
            'x_groups' => ['alt.binaries.boneless', 'alt.binaries.teevee', 'alt.binaries.moovee', 'alt.binaries.boneless', 'alt.binaries.hdtv', 'alt.binaries.mom'],
            'm_filters' => [
                'z_tag' => '  1080p.bluray.x265  ',
                'a_tag' => '  remux.hdr.dv  ',
                'c_langs' => ['eng', 'fra', 'deu', 'eng', 'jpn', 'spa', 'ita', 'deu'],
                'b_ids' => [900, 100, 400, 200, 900, 300, 100, 800, 700, 500, 600],
            ],
            'a_limit' => 100,
            'b_offset' => 50,
            'c_maxage' => 365,
        ];

        $workloads = [
            'isValidBasename/common' => [
                static fn () => $imageService->isValidBasenamePhp('a1b2c3d4e5f60718293a4b5c6d7e8f90_thumb'),
                static fn () => $imageService->isValidBasenameNative('a1b2c3d4e5f60718293a4b5c6d7e8f90_thumb'),
            ],
            'isValidBasename/uncommon' => [
                static fn () => $imageService->isValidBasenamePhp(str_repeat('release_asset_identifier_segment-', 8).'cover'),
                static fn () => $imageService->isValidBasenameNative(str_repeat('release_asset_identifier_segment-', 8).'cover'),
            ],
            'redactedUrl/common' => [
                static fn () => $imageService->redactedUrlPhp('https://image.tmdb.org/t/p/w500/8cdWjvZQUExUUTzyp4t6EDMubfO.jpg?api_key=secret123'),
                static fn () => $imageService->redactedUrlNative('https://image.tmdb.org/t/p/w500/8cdWjvZQUExUUTzyp4t6EDMubfO.jpg?api_key=secret123'),
            ],
            'redactedUrl/uncommon' => [
                static fn () => $imageService->redactedUrlPhp('HTTPS://user:pass@assets.fanart.tv:8443/fanart/movies/12345/movieposter/'.str_repeat('subpath/', 16).'poster.jpg?token='.str_repeat('x', 128).'#section'),
                static fn () => $imageService->redactedUrlNative('HTTPS://user:pass@assets.fanart.tv:8443/fanart/movies/12345/movieposter/'.str_repeat('subpath/', 16).'poster.jpg?token='.str_repeat('x', 128).'#section'),
            ],
            'scaleDimensions/common' => [
                static fn () => $imageService->scaleDimensionsPhp(1000, 1500, 250, 350),
                static fn () => $imageService->scaleDimensionsNative(1000, 1500, 250, 350),
            ],
            'scaleDimensions/uncommon' => [
                static fn () => $imageService->scaleDimensionsPhp(7680, 4320, 800, 600),
                static fn () => $imageService->scaleDimensionsNative(7680, 4320, 800, 600),
            ],
            'resolveOutputFormat/common' => [
                static fn () => $imageService->resolveOutputFormatPhp('webp'),
                static fn () => $imageService->resolveOutputFormatNative('webp'),
            ],
            'resolveOutputFormat/uncommon' => [
                static fn () => $imageService->resolveOutputFormatPhp('JPEG'),
                static fn () => $imageService->resolveOutputFormatNative('JPEG'),
            ],
            'resolveReason/common' => [
                static fn () => $dupFinder->resolveReasonPhp($dupCommon, 'Ubuntu.24.04.LTS.Desktop.amd64.ISO-GRP', 0),
                static fn () => $dupFinder->resolveReasonNative($dupCommon, 'Ubuntu.24.04.LTS.Desktop.amd64.ISO-GRP', 0),
            ],
            'resolveReason/uncommon' => [
                static fn () => $dupFinder->resolveReasonPhp($dupUncommon, str_repeat('Very.Long.Release.Search.Name.Segment.', 8).'1080p.BluRay-GRP', 0),
                static fn () => $dupFinder->resolveReasonNative($dupUncommon, str_repeat('Very.Long.Release.Search.Name.Segment.', 8).'1080p.BluRay-GRP', 0),
            ],
            'sizeBounds/common' => [
                static fn () => $dupFinder->sizeBoundsPhp(1_468_006_400, 0.05),
                static fn () => $dupFinder->sizeBoundsNative(1_468_006_400, 0.05),
            ],
            'sizeBounds/uncommon' => [
                static fn () => $dupFinder->sizeBoundsPhp(85_899_345_920, 0.075),
                static fn () => $dupFinder->sizeBoundsNative(85_899_345_920, 0.075),
            ],
            'formatExportDateBound/common' => [
                static fn () => $mgmtService->formatExportDateBoundPhp('15/04/2026', false),
                static fn () => $mgmtService->formatExportDateBoundNative('15/04/2026', false),
            ],
            'formatExportDateBound/uncommon' => [
                static fn () => $mgmtService->formatExportDateBoundPhp('31/12/2026', true),
                static fn () => $mgmtService->formatExportDateBoundNative('31/12/2026', true),
            ],
            'isSuccessful/common' => [
                static fn () => $catSelfCommon->isSuccessfulPhp(),
                static fn () => $catSelfCommon->isSuccessfulNative(),
            ],
            'isSuccessful/uncommon' => [
                static fn () => $catOtherUncommon->isSuccessfulPhp(),
                static fn () => $catOtherUncommon->isSuccessfulNative(),
            ],
            'isProtectedMiscResult/common' => [
                static fn () => $catOtherCommon->isProtectedMiscResultPhp(),
                static fn () => $catOtherCommon->isProtectedMiscResultNative(),
            ],
            'isProtectedMiscResult/uncommon' => [
                static fn () => $catOtherUncommon->isProtectedMiscResultPhp(),
                static fn () => $catOtherUncommon->isProtectedMiscResultNative(),
            ],
            'shouldOverride/common' => [
                static fn () => $catSelfCommon->shouldOverridePhp($catOtherCommon),
                static fn () => $catSelfCommon->shouldOverrideNative($catOtherCommon),
            ],
            'shouldOverride/uncommon' => [
                static fn () => $catSelfUncommon->shouldOverridePhp($catOtherUncommon),
                static fn () => $catSelfUncommon->shouldOverrideNative($catOtherUncommon),
            ],
            'apiCacheNormalize/common' => [
                static fn () => $apiCache->normalizePhp($cacheCommonParams),
                static fn () => $apiCache->normalizeNative($cacheCommonParams),
            ],
            'apiCacheNormalize/uncommon' => [
                static fn () => $apiCache->normalizePhp($cacheUncommonParams),
                static fn () => $apiCache->normalizeNative($cacheUncommonParams),
            ],
        ];

        $percentile99 = static function (array $ns): float {
            sort($ns);

            return (float) $ns[(int) ceil(count($ns) * 0.99) - 1];
        };

        $random = new Randomizer(new Mt19937(20261024));
        foreach ($workloads as $name => [$phpCall, $rustCall]) {
            self::assertSame($phpCall(), $rustCall(), $name);
            for ($w = 0; $w < 512; $w++) {
                $phpCall();
                $rustCall();
            }

            $reps = [];
            for ($rep = 0; $rep < 3; $rep++) {
                $samples = [[], []];
                for ($pair = 0; $pair < 4096; $pair++) {
                    $first = $random->getInt(0, 1);
                    foreach ([$first, 1 - $first] as $side) {
                        $fn = $side === 0 ? $phpCall : $rustCall;
                        $t0 = hrtime(true);
                        $fn();
                        $samples[$side][] = hrtime(true) - $t0;
                    }
                }
                $phpMean = array_sum($samples[0]) / count($samples[0]);
                $rustMean = array_sum($samples[1]) / count($samples[1]);
                $phpP99 = $percentile99($samples[0]);
                $rustP99 = $percentile99($samples[1]);
                $reps[] = sprintf('rep%d=%.2fx/%.2fx', $rep + 1, $phpMean / $rustMean, $phpP99 / $rustP99);
            }
            fwrite(STDOUT, sprintf("%-30s %s\n", $name, implode('  ', $reps)));
        }
    }
}
