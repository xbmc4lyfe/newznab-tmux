<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Country;
use App\Models\ParHash;
use App\Models\ReleasesGroups;
use App\Models\RootCategory;
use App\Models\ShortGroup;
use App\Support\RustPipelineLibrary;
use FFI;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionProperty;
use Tests\TestCase;

final class RustCategoryGroupParhashModelsParityTest extends TestCase
{
    /** @var list<class-string> */
    private const MODELS = [
        RootCategory::class,
        ShortGroup::class,
        ReleasesGroups::class,
        Country::class,
        ParHash::class,
    ];

    /** Strings that exercise trimming, collapsing, truncation, UTF-8 and wire-marker fallbacks. */
    private const TEXTS = [
        '',
        ' ',
        "\0\x0B\x0C\t\r\n",
        'Movies',
        '  TV  HD  ',
        "Console\t",
        'alt.binaries.teevee',
        ' Alt.Binaries.HDTV.X264 ',
        'United Kingdom',
        'Ünïcödé  Kingdom',
        "\u{00A0}nbsp\u{00A0}",
        '日本',
        "emoji \u{1F3AC} title",
        "bad \xff utf8",
        "\xc3",
        "sep\x1Finside",
        "null\x1Emark",
        "\x1E",
        "nul\0byte",
        'gb',
        ' US ',
        'GBR',
        'g1',
        'ÄB',
        "us\n",
        '0123456789abcdef0123456789ABCDEF',
        '0123456789abcdef0123456789abcdeg',
        ' 0123456789abcdef0123456789abcdef',
    ];

    private const INTS = [PHP_INT_MIN, -1, 0, 1, 2, 7, 1000, 2000, 4294967295, 4294967296, PHP_INT_MAX];

    protected function tearDown(): void
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(null);
        }
        parent::tearDown();
    }

    public function test_rust_ffi_is_disabled_by_default_and_toggles(): void
    {
        foreach (self::MODELS as $model) {
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(true);
            $this->assertTrue($model::usesRustFfi(), $model);
            $model::setUseRustFfi(false);
            $this->assertFalse($model::usesRustFfi(), $model);
            $model::setUseRustFfi(null);
            $this->assertFalse($model::usesRustFfi(), $model);
        }
    }

    public function test_eloquent_model_configuration_is_unchanged(): void
    {
        $root = new RootCategory;
        $this->assertSame('root_categories', $root->getTable());
        $this->assertSame([], $root->getGuarded());
        $this->assertTrue($root->usesTimestamps());

        $short = new ShortGroup;
        $this->assertSame('short_groups', $short->getTable());
        $this->assertFalse($short->usesTimestamps());

        $mapping = new ReleasesGroups;
        $this->assertSame('releases_groups', $mapping->getTable());
        $this->assertSame(['releases_id', 'groups_id'], $mapping->compositeKeyColumns());
        $this->assertFalse($mapping->usesTimestamps());

        $country = new Country;
        $this->assertSame('iso_3166_2', $country->getKeyName());
        $this->assertSame('string', $country->getKeyType());
        $this->assertFalse($country->getIncrementing());
        $this->assertFalse($country->usesTimestamps());

        $hash = new ParHash;
        $this->assertSame('par_hashes', $hash->getTable());
        $this->assertSame(['releases_id', 'hash'], $hash->compositeKeyColumns());
        $this->assertFalse($hash->usesTimestamps());
    }

    public function test_php_reference_semantics(): void
    {
        $this->assertSame([
            'id' => 2000,
            'title' => 'Movies HD',
            'status' => 1,
            'disablepreview' => 1,
            'is_active' => true,
            'title_key' => 'movies hd',
        ], RootCategory::normalizeRootCategoryAttributesPhp(2000, "  Movies \t HD ", 1, 5));
        $this->assertSame(0, RootCategory::normalizeRootCategoryAttributesPhp(-3, '', 1, 0)['id']);
        $this->assertSame(0, RootCategory::normalizeRootCategoryAttributesPhp(1, 'x', 7, 0)['status']);
        $this->assertSame(2, RootCategory::normalizeRootCategoryAttributesPhp(1, 'x', 2, 0)['status']);
        $this->assertFalse(RootCategory::normalizeRootCategoryAttributesPhp(1, 'x', 2, 0)['is_active']);
        $this->assertSame('Ä', RootCategory::normalizeRootCategoryAttributesPhp(1, 'Ä', 1, 0)['title_key']);
        $this->assertSame(255, mb_strlen(RootCategory::normalizeRootCategoryAttributesPhp(1, str_repeat('é', 300), 1, 0)['title']));

        $this->assertSame([
            'name' => 'alt.binaries.teevee',
            'first_record' => 100,
            'last_record' => 199,
            'article_count' => 100,
            'is_valid' => true,
        ], ShortGroup::normalizeArticleWatermarkRangePhp(" Alt.Binaries.Teevee\n", 100, 199));
        $this->assertSame(0, ShortGroup::normalizeArticleWatermarkRangePhp('a.b', 0, 50)['article_count']);
        $this->assertSame(0, ShortGroup::normalizeArticleWatermarkRangePhp('a.b', 10, 5)['article_count']);
        $this->assertFalse(ShortGroup::normalizeArticleWatermarkRangePhp('', 1, 1)['is_valid']);
        $this->assertSame([0, 0], array_values(array_intersect_key(ShortGroup::normalizeArticleWatermarkRangePhp('a.b', -5, -1), ['first_record' => 0, 'last_record' => 0])));
        $this->assertSame(PHP_INT_MAX, ShortGroup::normalizeArticleWatermarkRangePhp('a.b', 1, PHP_INT_MAX)['article_count']);

        $this->assertSame([
            'releases_id' => 5,
            'groups_id' => 9,
            'composite_key' => '5:9',
            'is_valid' => true,
        ], ReleasesGroups::normalizeCompositeMappingPhp(5, 9));
        $this->assertSame([
            'releases_id' => 4294967295,
            'groups_id' => 0,
            'composite_key' => '4294967295:0',
            'is_valid' => false,
        ], ReleasesGroups::normalizeCompositeMappingPhp(PHP_INT_MAX, -1));
        $this->assertFalse(ReleasesGroups::normalizeCompositeMappingPhp(4294967296, 1)['is_valid']);

        $this->assertSame([
            'iso_3166_2' => 'GB',
            'name' => 'United Kingdom',
            'full_name' => null,
            'is_valid' => true,
        ], Country::normalizeIsoCountryRecordPhp(' gb ', ' United  Kingdom ', '  '));
        $this->assertSame('United Kingdom of Great Britain', Country::normalizeIsoCountryRecordPhp('GB', 'UK', ' United Kingdom of  Great Britain ')['full_name']);
        $this->assertNull(Country::normalizeIsoCountryRecordPhp('GBR', 'x')['iso_3166_2']);
        $this->assertNull(Country::normalizeIsoCountryRecordPhp('g1', 'x')['iso_3166_2']);
        $this->assertNull(Country::normalizeIsoCountryRecordPhp('ÄB', 'x')['iso_3166_2']);
        $this->assertFalse(Country::normalizeIsoCountryRecordPhp('US', ' ')['is_valid']);

        $this->assertSame([
            'releases_id' => 7,
            'hash' => '0123456789abcdef0123456789abcdef',
            'is_valid' => true,
        ], ParHash::normalizePar2Hash16kPhp(7, '0123456789ABCDEF0123456789abcdef'));
        $this->assertSame(['releases_id' => 7, 'hash' => null, 'is_valid' => false], ParHash::normalizePar2Hash16kPhp(7, 'xyz'));
        $this->assertFalse(ParHash::normalizePar2Hash16kPhp(0, str_repeat('a', 32))['is_valid']);
        $this->assertSame(0, ParHash::normalizePar2Hash16kPhp(-1, str_repeat('a', 32))['releases_id']);
        $this->assertNull(ParHash::normalizePar2Hash16kPhp(1, ' '.str_repeat('a', 31))['hash']);
    }

    public function test_root_category_native_and_dispatch_match_php(): void
    {
        $texts = [...self::TEXTS, str_repeat('Ω ', 300)];
        foreach (self::INTS as $id) {
            foreach ($texts as $title) {
                foreach ([-1, 0, 1, 2, 3, PHP_INT_MAX] as $status) {
                    foreach ([0, 1, -1, PHP_INT_MIN] as $preview) {
                        $this->assertRootParity($id, $title, $status, $preview);
                    }
                }
            }
        }
    }

    public function test_short_group_native_and_dispatch_match_php(): void
    {
        $texts = [...self::TEXTS, str_repeat('alt.binaries.é', 40)];
        foreach ($texts as $name) {
            foreach (self::INTS as $first) {
                foreach (self::INTS as $last) {
                    $this->assertShortGroupParity($name, $first, $last);
                }
            }
        }
    }

    public function test_releases_groups_native_and_dispatch_match_php(): void
    {
        foreach (self::INTS as $release) {
            foreach (self::INTS as $group) {
                $this->assertMappingParity($release, $group);
            }
        }
    }

    public function test_country_native_and_dispatch_match_php(): void
    {
        $values = [...self::TEXTS, str_repeat('Ü', 300)];
        foreach ($values as $i => $iso) {
            foreach ($values as $j => $name) {
                $full = ($i + $j) % 4 === 0 ? null : $values[($i * 3 + $j) % count($values)];
                $this->assertCountryParity($iso, $name, $full);
                $this->assertCountryParity($iso, $name, null);
            }
        }
    }

    public function test_par_hash_native_and_dispatch_match_php(): void
    {
        $hashes = [...self::TEXTS, str_repeat('F', 32), str_repeat('0', 31), str_repeat('a', 33), str_repeat("\xff", 32), str_repeat("\x1F", 32)];
        foreach (self::INTS as $release) {
            foreach ($hashes as $hash) {
                $this->assertParHashParity($release, $hash);
            }
        }
    }

    public function test_randomized_inputs_match_php(): void
    {
        $random = new Randomizer(new Mt19937(3303));
        $alphabet = ['a', 'Z', 'f', 'F', '0', '9', '.', ' ', "\t", "\n", "\0", "\x0B", "\x0C", '-', ':', 'é', 'ß', '日', "\u{1F3AC}", "\u{00A0}", "\x1F", "\x1E", "\xff"];
        $text = static function (int $max = 40) use ($random, $alphabet): string {
            $out = '';
            $length = $random->getInt(0, $max);
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[$random->getInt(0, count($alphabet) - 1)];
            }

            return $out;
        };
        $hex = static fn (): string => $random->getInt(0, 2) === 0
            ? $text(34)
            : bin2hex($random->getBytes(16)).($random->getInt(0, 5) === 0 ? 'x' : '');
        $int = static fn (): int => match ($random->getInt(0, 3)) {
            0 => $random->getInt(-10, 10),
            1 => $random->getInt(0, 5000),
            2 => $random->getInt(PHP_INT_MIN, PHP_INT_MAX),
            default => $random->getInt(4294967290, 4294967300),
        };

        for ($i = 0; $i < 2000; $i++) {
            $this->assertRootParity($int(), $text(), $random->getInt(-1, 3), $int());
            $this->assertShortGroupParity($text(), $int(), $int());
            $this->assertMappingParity($int(), $int());
            $this->assertCountryParity($text(3), $text(), $random->getInt(0, 3) === 0 ? null : $text());
            $this->assertParHashParity($int(), $hex());
        }
    }

    public function test_native_exports_validate_input_and_capacity(): void
    {
        $ffi = $this->ffiOrSkip();

        $small = $ffi->new('char[4]');
        FFI::memset($small, ord('Z'), 4);
        $needed = $this->callFfi($ffi, 'nntmux_root_category_normalize_attributes', 2000, 1, 0, 'Movies', 6, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));
        $buffer = $ffi->new('char['.(-$needed).']');
        $this->assertSame(-$needed, $this->callFfi($ffi, 'nntmux_root_category_normalize_attributes', 2000, 1, 0, 'Movies', 6, $buffer, -$needed));
        $this->assertSame("2000\x1FMovies\x1F1\x1F0\x1F1\x1Fmovies", FFI::string($buffer, -$needed));

        $needed = $this->callFfi($ffi, 'nntmux_releases_groups_normalize_mapping', 12, 34, $small, 4);
        $this->assertLessThan(-1, $needed);
        $this->assertSame('ZZZZ', FFI::string($small, 4));

        $out = $ffi->new('char[256]');
        $written = $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 0, "us\x1FUnited States\x1F", 17, $out, 256);
        $this->assertSame("US\x1FUnited States\x1F\x1E\x1F1", FFI::string($out, $written));
        $written = $this->callFfi($ffi, 'nntmux_par_hash_normalize_16k', 9, "\x1F\x1E", 2, $out, 256);
        $this->assertSame("9\x1F\x1E\x1F0", FFI::string($out, $written));

        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_root_category_normalize_attributes', 1, 1, 0, "\xff", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_root_category_normalize_attributes', 1, 1, 0, "a\x1Fb", 3, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_short_group_normalize_watermark', 1, 1, "\x1E", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_short_group_normalize_watermark', 1, 1, "\xc3", 1, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 0, "us\x1Fname\x1Fx", 10, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 2, "us\x1Fname\x1F", 8, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 1, "us\x1Fname", 7, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 1, "us\x1Fa\x1Fb\x1Fc", 9, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_country_normalize_iso_record', 1, "u\x1Es\x1Fname\x1F", 10, $out, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_root_category_normalize_attributes', 1, 1, 0, 'Movies', 6, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_releases_groups_normalize_mapping', 1, 1, null, 256));
        $this->assertSame(-1, $this->callFfi($ffi, 'nntmux_par_hash_normalize_16k', 1, null, 0, $out, 256));

        foreach (self::MODELS as $model) {
            $model::setUseRustFfi(true);
        }
        RootCategory::normalizeRootCategoryAttributes(1000, 'Console', 1, 0);
        ShortGroup::normalizeArticleWatermarkRange('alt.binaries.test', 1, 2);
        ReleasesGroups::normalizeCompositeMapping(1, 2);
        Country::normalizeIsoCountryRecord('US', 'United States');
        ParHash::normalizePar2Hash16k(1, str_repeat('a', 32));
        foreach (self::MODELS as $model) {
            $this->assertInstanceOf(FFI::class, (new ReflectionProperty($model, 'ffi'))->getValue(), $model.' must resolve its native exports');
        }
    }

    public function test_paired_randomized_benchmark_records_rust_vs_php_evidence(): void
    {
        // Paired, randomized-order benchmark of the five model helpers through their public dispatchers,
        // including wire packing, the FFI call and decoding (release dylib, 5 warmups, 301 samples x 40 iterations).
        // Common = typical category/group/mapping/country/hash rows; uncommon = long, Unicode, padded,
        // out-of-range and fallback inputs.
        // Speedup = PHP latency / Rust latency per workload pass (PHP 8.5.12 NTS, Apple M5, 4 rounds):
        // - Round 1: common mean 0.40x / p99 0.40x, uncommon mean 0.46x / p99 0.32x
        // - Round 2: common mean 0.29x / p99 0.30x, uncommon mean 0.50x / p99 0.49x
        // - Round 3: common mean 0.29x / p99 0.28x, uncommon mean 0.46x / p99 0.31x
        // - Round 4: common mean 0.29x / p99 0.29x, uncommon mean 0.49x / p99 0.47x
        // Reproduce: php vendor/bin/phpunit tests/Unit/RustCategoryGroupParhashModelsParityTest.php --filter benchmark
        // Microsecond PHP string/int work is cheaper than the FFI round trip, so every model stays below the
        // 1.5x bar in .ai/rules/rust-performance.md and keeps RUST_FFI_DEFAULT = false.
        $this->ffiOrSkip();
        $workloads = ['common' => $this->commonWorkload(), 'uncommon' => $this->uncommonWorkload()];
        $random = new Randomizer(new Mt19937(3303));
        $warmup = 5;
        $samples = 301;
        $iterations = 40;
        $sink = 0;

        $speedups = [];
        foreach ($workloads as $name => $workload) {
            for ($w = 0; $w < $warmup; $w++) {
                $sink += $this->runWorkload($workload, false) + $this->runWorkload($workload, true);
            }
            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < $samples; $s++) {
                $order = $random->getInt(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $useRust) {
                    $start = hrtime(true);
                    for ($i = 0; $i < $iterations; $i++) {
                        $sink += $this->runWorkload($workload, $useRust);
                    }
                    $times[$useRust ? 'rust' : 'php'][] = (hrtime(true) - $start) / 1_000 / $iterations;
                }
            }
            foreach (self::MODELS as $model) {
                $model::setUseRustFfi(null);
            }
            $speedups[$name] = [
                'mean' => $this->mean($times['php']) / $this->mean($times['rust']),
                'p99' => $this->p99($times['php']) / $this->p99($times['rust']),
            ];
        }

        $this->assertGreaterThan(0, $sink);
        $summary = json_encode($speedups, JSON_PRESERVE_ZERO_FRACTION) ?: '';
        fwrite(STDERR, "\nRustCategoryGroupParhashModels speedup: {$summary}\n");
        if ($speedups['common']['mean'] < 1.5 || $speedups['common']['p99'] < 1.5 || $speedups['uncommon']['p99'] < 1.5) {
            foreach (self::MODELS as $model) {
                $this->assertFalse($model::usesRustFfi(), $model.' must keep PHP below 1.5x: '.$summary);
            }
        }
        foreach ($speedups as $name => $speedup) {
            $this->assertGreaterThan(0.0, $speedup['mean'], $name);
            $this->assertGreaterThan(0.0, $speedup['p99'], $name);
        }
    }

    private function assertRootParity(int $id, string $title, int $status, int $preview): void
    {
        $expected = RootCategory::normalizeRootCategoryAttributesPhp($id, $title, $status, $preview);
        $context = json_encode([$id, bin2hex($title), $status, $preview]) ?: '';
        $this->assertSameAttributes($expected, RootCategory::normalizeRootCategoryAttributesNative($id, $title, $status, $preview), $context);
        RootCategory::setUseRustFfi(true);
        $this->assertSameAttributes($expected, RootCategory::normalizeRootCategoryAttributes($id, $title, $status, $preview), $context);
        RootCategory::setUseRustFfi(false);
        $this->assertSameAttributes($expected, RootCategory::normalizeRootCategoryAttributes($id, $title, $status, $preview), $context);
        RootCategory::setUseRustFfi(null);
    }

    private function assertShortGroupParity(string $name, int $first, int $last): void
    {
        $expected = ShortGroup::normalizeArticleWatermarkRangePhp($name, $first, $last);
        $context = json_encode([bin2hex($name), $first, $last]) ?: '';
        $this->assertSameAttributes($expected, ShortGroup::normalizeArticleWatermarkRangeNative($name, $first, $last), $context);
        ShortGroup::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ShortGroup::normalizeArticleWatermarkRange($name, $first, $last), $context);
        ShortGroup::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ShortGroup::normalizeArticleWatermarkRange($name, $first, $last), $context);
        ShortGroup::setUseRustFfi(null);
    }

    private function assertMappingParity(int $release, int $group): void
    {
        $expected = ReleasesGroups::normalizeCompositeMappingPhp($release, $group);
        $context = json_encode([$release, $group]) ?: '';
        $this->assertSameAttributes($expected, ReleasesGroups::normalizeCompositeMappingNative($release, $group), $context);
        ReleasesGroups::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ReleasesGroups::normalizeCompositeMapping($release, $group), $context);
        ReleasesGroups::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ReleasesGroups::normalizeCompositeMapping($release, $group), $context);
        ReleasesGroups::setUseRustFfi(null);
    }

    private function assertCountryParity(string $iso, string $name, ?string $full): void
    {
        $expected = Country::normalizeIsoCountryRecordPhp($iso, $name, $full);
        $context = json_encode([bin2hex($iso), bin2hex($name), $full === null ? null : bin2hex($full)]) ?: '';
        $this->assertSameAttributes($expected, Country::normalizeIsoCountryRecordNative($iso, $name, $full), $context);
        Country::setUseRustFfi(true);
        $this->assertSameAttributes($expected, Country::normalizeIsoCountryRecord($iso, $name, $full), $context);
        Country::setUseRustFfi(false);
        $this->assertSameAttributes($expected, Country::normalizeIsoCountryRecord($iso, $name, $full), $context);
        Country::setUseRustFfi(null);
    }

    private function assertParHashParity(int $release, string $hash): void
    {
        $expected = ParHash::normalizePar2Hash16kPhp($release, $hash);
        $context = json_encode([$release, bin2hex($hash)]) ?: '';
        $this->assertSameAttributes($expected, ParHash::normalizePar2Hash16kNative($release, $hash), $context);
        ParHash::setUseRustFfi(true);
        $this->assertSameAttributes($expected, ParHash::normalizePar2Hash16k($release, $hash), $context);
        ParHash::setUseRustFfi(false);
        $this->assertSameAttributes($expected, ParHash::normalizePar2Hash16k($release, $hash), $context);
        ParHash::setUseRustFfi(null);
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertSameAttributes(array $expected, array $actual, string $context): void
    {
        $this->assertSame($expected, $actual, $context);
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function commonWorkload(): array
    {
        return [
            ['root', [2000, 'Movies', 1, 0]],
            ['root', [5000, 'TV', 1, 0]],
            ['short', ['alt.binaries.teevee', 1500000000, 1500250000]],
            ['short', ['alt.binaries.hdtv.x264', 98000000, 98123456]],
            ['mapping', [987654, 42]],
            ['mapping', [987655, 7]],
            ['country', ['US', 'United States', 'United States of America']],
            ['country', ['GB', 'United Kingdom', null]],
            ['hash', [987654, '0123456789abcdef0123456789abcdef']],
            ['hash', [987655, 'fedcba9876543210fedcba9876543210']],
        ];
    }

    /**
     * @return list<array{string, list<mixed>}>
     */
    private function uncommonWorkload(): array
    {
        $long = str_repeat('Ünïcödé  Tïtlé  ', 30);

        return [
            ['root', [PHP_INT_MAX, $long, 7, -1]],
            ['root', [-1, "bad \xff utf8 title", 2, 1]],
            ['short', ['  '.str_repeat('Alt.Binaries.Multimedia.', 15).'  ', -5, PHP_INT_MAX]],
            ['short', ["alt.binaries.\x1Fmarker", 10, 5]],
            ['mapping', [PHP_INT_MAX, PHP_INT_MIN]],
            ['country', [" gb\t", "  United \t Kingdom of Great Britain  ", $long]],
            ['country', ['GBR', "\u{00A0}Côte d’Ivoire\u{00A0}", '  ']],
            ['hash', [0, '0123456789ABCDEF0123456789ABCDEF']],
            ['hash', [4294967296, str_repeat("\xff", 40)]],
        ];
    }

    /**
     * @param  list<array{string, list<mixed>}>  $workload
     */
    private function runWorkload(array $workload, bool $useRust): int
    {
        foreach (self::MODELS as $model) {
            $model::setUseRustFfi($useRust);
        }
        $count = 0;
        foreach ($workload as [$kind, $args]) {
            $result = match ($kind) {
                'root' => RootCategory::normalizeRootCategoryAttributes(...$args),
                'short' => ShortGroup::normalizeArticleWatermarkRange(...$args),
                'mapping' => ReleasesGroups::normalizeCompositeMapping(...$args),
                'country' => Country::normalizeIsoCountryRecord(...$args),
                default => ParHash::normalizePar2Hash16k(...$args),
            };
            $count += count($result);
        }

        return $count;
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(count($values) * 0.99) - 1)];
    }

    private function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private function ffiOrSkip(): FFI
    {
        $cdef = implode("\n", [
            RootCategory::FFI_CDEF,
            ShortGroup::FFI_CDEF,
            ReleasesGroups::FFI_CDEF,
            Country::FFI_CDEF,
            ParHash::FFI_CDEF,
        ]);
        $ffi = RustPipelineLibrary::resolveDomain('categorize', $cdef);
        if ($ffi === null) {
            $this->markTestSkipped('libnntmux_pipeline with the category/group/par-hash model exports is not available.');
        }

        return $ffi;
    }
}
