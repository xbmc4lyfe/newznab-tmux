<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Data\Api\CategoryData;
use App\Data\Api\DetailsData;
use App\Data\Api\ReleaseData;
use App\Models\Category;
use App\Services\Api\V1\ReleaseRows;
use App\Services\Api\V1\XmlResponseContext;
use App\Support\RustPipelineLibrary;
use Closure;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use RuntimeException;

final class RustApiReleaseRowsXmlContextCategoryDetailsReleaseDataW65ParityTest extends TestCase
{
    /** Class => batch method. */
    private const WAVE = [
        ReleaseRows::class => 'w65ReleaseRowsPlanBatch',
        XmlResponseContext::class => 'w65XmlResponseContextPlanBatch',
        CategoryData::class => 'w65CategoryDataPlanBatch',
        DetailsData::class => 'w65DetailsDataPlanBatch',
        ReleaseData::class => 'w65ReleaseDataPlanBatch',
    ];

    /** Header words before the per-record rows (one word per record). */
    private const HEADER = [
        ReleaseRows::class => ReleaseRows::W65_ROWS_HEADER,
        XmlResponseContext::class => XmlResponseContext::W65_XML_HEADER,
        CategoryData::class => CategoryData::W65_CATEGORY_HEADER,
        DetailsData::class => DetailsData::W65_DETAILS_HEADER,
        ReleaseData::class => ReleaseData::W65_RELEASE_HEADER,
    ];

    /** Properties each class declares itself; Wave 65.6 adds none. */
    private const PROPERTIES = [
        ReleaseRows::class => ['useW465RustFfi', 'rows', 'releases'],
        XmlResponseContext::class => ['parameters', 'data', 'server', 'offset', 'type'],
        CategoryData::class => ['useRustFfi', 'ffi', 'id', 'name', 'subcategories'],
        DetailsData::class => ['useRustFfi', 'title', 'details', 'link', 'category', 'category_name', 'added', 'size', 'files', 'grabs', 'comments',
            'password', 'usenetdate', 'imdbid', 'tmdbid', 'traktid', 'tvairdate', 'tvdbid', 'tvrageid', 'tvmazeid', 'articles_found', 'articles_expected',
            'files_found', 'files_expected'],
        ReleaseData::class => ['useRustFfi', 'ffi', 'rssBuf', 'title', 'guid', 'details', 'url', 'category', 'category_name', 'added', 'size', 'files',
            'grabs', 'comments', 'password', 'usenetdate', 'imdbid', 'tmdbid', 'traktid', 'episode_title', 'season', 'episode', 'tvairdate', 'tvdbid',
            'tvrageid', 'tvmazeid', 'articles_found', 'articles_expected', 'files_found', 'files_expected'],
    ];

    /**
     * Apple M-series/macOS arm64, PHP 8.5.12 CLI without opcache/JIT, release Rust; base cee15b3d9 plus this
     * change, seed 656, 50 warmups and 1,000 pairs/workload, four runs (w656-benchmark-*.json in system tmp).
     * The per-metric minimum over the runs of common mean/p99 then uncommon mean/p99 speedups (PHP time / Rust
     * time); a default is Rust only when all four reach 1.5x. All five classes clear 1.5x on every metric; the
     * uncommon p99 (NUL-holding, escape-heavy and oversized values dominated by marshaling) has the narrowest
     * margin, ReleaseData's ten packed integer columns most of all.
     */
    private const QUALIFICATION = [
        ReleaseRows::class => [3.976, 3.007, 2.272, 1.762],
        XmlResponseContext::class => [3.402, 2.293, 3.093, 2.498],
        CategoryData::class => [4.425, 3.459, 3.235, 2.463],
        DetailsData::class => [7.725, 5.940, 4.251, 3.560],
        ReleaseData::class => [2.907, 2.523, 2.007, 1.951],
    ];

    private const GUID = '0123456789abcdef0123456789abcdef01234567';

    private const GUID_ODD = ['', 'x', '0123456789ABCDEF0123456789ABCDEF01234567', '0123456789abcdef0123456789abcdef0123456', '0123456789abcdef0123456789abcdef012345678',
        'g123456789abcdef0123456789abcdef01234567', ' 0123456789abcdef0123456789abcdef0123456', "0123456789abcdef\x000123456789abcdef0123456", 'ü123456789abcdef0123456789abcdef0123456'];

    private const TOTAL_ODD = ['', ' 12', '12 ', '-5', '+5', '1e3', '0x1A', '1.5', '999999999999999999', '1000000000000000000', '00042', "4\x002", '٣'];

    private const TYPE_ODD = ['caps', 'C', ' search ', 'S', 'TVSEARCH', 'tv', 'Movie', 'm', 'music', 'BOOK', 'details', 'D', 'register', 'r', "\tcaps\n",
        'rss', 'get', '', ' ', 'séarch', "caps\0", 'tv-search', 'caps '];

    private const SERVER_ODD = ['https://indexer.example/', 'http://indexer.example', 'HTTPS://Indexer.Example:8443/api', 'Http://[::1]:8080/', 'https://',
        'http:/x', 'ftp://indexer.example/', '//indexer.example/', 'https://in dexer/', 'https://indexer_example/', 'https://idx.example?x=1', 'https://bücher.example/',
        "https://idx\0.example/", '', ' https://idx/', 'https:///path', 'http://a'];

    private const TITLE_ODD = ['Movies', 'TV', '', ' ', "\t\n", 'Fïlm', '電視', 'A & B', "x\0y", 'LongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLongLong', 'HD', 'SD', 'UHD', 'Other'];

    private const POSTDATE_ODD = ['2020-01-01 00:00:00', '1970-01-01 00:00:00', '1969-12-31 23:59:59', '2099-12-31 23:59:59', '2100-01-01 00:00:00',
        '2024-02-29 12:00:00', '2023-02-29 12:00:00', '2023-04-31 00:00:00', '2023-13-01 00:00:00', '2023-00-10 00:00:00', '2023-01-01 24:00:00',
        '2023-01-01 23:60:00', '2023-01-01 23:59:60', '2023-01-01T00:00:00', '2023-01-01 00:00:00 ', '2023-1-01 00:00:00', '', 'garbage',
        "2023-01-01\x0000:00:00", '２023-01-01 00:00:00', '2023-01-01 00:00'];

    private const RELEASE_TITLE_ODD = ['Movie.2024.1080p.WEB-DL.x264-GRP', 'A & B <C>', "It's \"quoted\"", '&&&&', '<>', '', 'Fïlm.2024', "x\0y",
        '&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'&<>"\'', 'Plain Title'];

    private const MOVIES = [2000, 2010, 2030, 2040, 2045, 2050, 2060, 2070, 2080, 2090, 2999];

    private const TV = [5000, 5010, 5020, 5030, 5040, 5045, 5060, 5070, 5080, 5090, 5999];

    private const OTHER = [1, 10, 20, 1000, 1010, 3010, 3040, 4010, 4050, 6010, 6040, 7010, 7020, 8010];

    private const EXTREMES = [PHP_INT_MIN, -1, 0, 1, 2_147_483_647, 2_147_483_648, 1 << 40, 1 << 50, (1 << 50) + 1, 1 << 62, PHP_INT_MAX];

    public function test_wave_methods_and_constants_exist(): void
    {
        foreach (self::WAVE as $class => $method) {
            $reflection = new ReflectionClass($class);
            $public = ['enableW65RustFfi', 'disableW65RustFfi', 'resetW65RustFfi', 'usesW65RustFfi', 'withW65RustFfi', $method, $method.'Php', $method.'Native'];
            foreach ($public as $name) {
                self::assertTrue($reflection->hasMethod($name), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isStatic(), $class.'::'.$name);
                self::assertTrue($reflection->getMethod($name)->isPublic(), $class.'::'.$name);
            }
            $state = $reflection->getMethod('w65RustFfiState');
            self::assertTrue($state->isPrivate() && $state->isStatic(), $class);
            self::assertSame(['op', 'val'], array_map(static fn ($p): string => $p->getName(), $state->getParameters()), $class);
            self::assertSame('mixed', (string) $state->getReturnType(), $class);
            self::assertTrue($reflection->getReflectionConstant('W65_RUST_FFI_DEFAULT')?->isPublic() ?? false, $class);
            self::assertIsBool($reflection->getConstant('W65_RUST_FFI_DEFAULT'), $class);
            $cdefs = array_filter($reflection->getReflectionConstants(), static fn ($constant): bool => preg_match('/^W65_\w+_FFI_CDEF$/', $constant->getName()) === 1);
            self::assertCount(1, $cdefs, $class);
            $cdef = array_values($cdefs)[0];
            self::assertTrue($cdef->isPrivate(), $class);
            self::assertStringContainsString('nntmux_w656_', (string) $cdef->getValue(), $class);
            $declared = array_values(array_map(static fn ($p): string => $p->getName(),
                array_filter($reflection->getProperties(), static fn ($p): bool => $p->getDeclaringClass()->getName() === $class)));
            self::assertSame(self::PROPERTIES[$class], $declared, $class);
        }
        self::assertTrue((new ReflectionClass(XmlResponseContext::class))->isReadOnly());
    }

    public function test_defaults_match_recorded_qualification_and_toggles_restore(): void
    {
        foreach (array_keys(self::WAVE) as $class) {
            $class::resetW65RustFfi();
            $expected = (bool) (new ReflectionClass($class))->getConstant('W65_RUST_FFI_DEFAULT');
            self::assertSame($expected, min(self::QUALIFICATION[$class]) >= 1.5, $class);
            self::assertSame($expected, $class::usesW65RustFfi(), $class);
            foreach ([true, false] as $enabled) {
                $seen = $class::withW65RustFfi($enabled, function () use ($class, $enabled): bool {
                    $inner = $class::withW65RustFfi(! $enabled, static fn (): bool => $class::usesW65RustFfi());
                    self::assertSame(! $enabled, $inner);
                    foreach (array_keys(self::WAVE) as $other) {
                        if ($other !== $class) {
                            self::assertSame((bool) (new ReflectionClass($other))->getConstant('W65_RUST_FFI_DEFAULT'), $other::usesW65RustFfi());
                        }
                    }

                    return $class::usesW65RustFfi();
                });
                self::assertSame($enabled, $seen, $class);
                self::assertSame($expected, $class::usesW65RustFfi(), $class);
            }
            $thrown = null;
            try {
                $class::withW65RustFfi(! $expected, static function (): bool {
                    throw new RuntimeException('boom');
                });
            } catch (RuntimeException $e) {
                $thrown = $e->getMessage();
            }
            self::assertSame('boom', $thrown);
            self::assertSame($expected, $class::usesW65RustFfi(), $class);
            $class::enableW65RustFfi();
            self::assertTrue($class::usesW65RustFfi(), $class);
            $class::disableW65RustFfi();
            self::assertFalse($class::usesW65RustFfi(), $class);
            $class::resetW65RustFfi();
            self::assertSame($expected, $class::usesW65RustFfi(), $class);
        }
    }

    public function test_existing_behavior_and_toggles_are_unchanged(): void
    {
        self::assertSame(42, ReleaseRows::totalRowsFromPhp('42', 3));
        self::assertSame(3, ReleaseRows::totalRowsFromPhp(null, 3));
        self::assertSame(ReleaseRows::W465_RUST_FFI_DEFAULT, ReleaseRows::usesW465RustFfi());
        self::assertSame([4, 2024, 1080, 4], ReleaseRows::searchNamePlanBatchPhp(['Movie.2024.1080p-GRP']));
        $context = XmlResponseContext::fromLegacyOptionsPhp(['Parameters' => 'x', 'Offset' => 5]);
        self::assertSame([[], 5], [$context->parameters, $context->offset]);
        self::assertFalse(XmlResponseContext::usesW465RustFfi());
        self::assertSame([9, 1, 0], XmlResponseContext::xmlEscapePlanBatchPhp(['a & b']));
        self::assertSame(['id' => 7, 'name' => 'TV', 'subcategories' => [5030 => 'SD', 5040 => '']],
            CategoryData::normalizeCategoryPayloadPhp('7', 'TV', [5030 => 'SD', 5040 => null]));
        self::assertFalse(CategoryData::usesRustFfi());
        self::assertSame(['Wed, 01 Jan 2020 00:00:00 +0000', 'Wed, 01 Jan 2020 00:00:00 +0000'],
            DetailsData::formatRssDatesPhp('2020-01-01 00:00:00', '2020-01-01 00:00:00'));
        self::assertSame([], ReleaseData::partialCounts(static fn (string $key, mixed $default = null): mixed => null));
    }

    public function test_plan_tables_mirror_category_constants(): void
    {
        $groups = [];
        foreach (Category::MOVIES_GROUP as $id) {
            $groups[$id] = 1;
        }
        foreach (Category::TV_GROUP as $id) {
            $groups[$id] = 2;
        }
        ksort($groups);
        foreach ([DetailsData::class, ReleaseData::class] as $class) {
            $table = (new ReflectionClass($class))->getConstant('W65_MEDIA_GROUPS');
            ksort($table);
            self::assertSame($groups, $table, $class);
        }
        self::assertSame(self::MOVIES, array_keys(array_filter($groups, static fn (int $g): bool => $g === 1)));
        self::assertSame(self::TV, array_keys(array_filter($groups, static fn (int $g): bool => $g === 2)));
        $rootId = (new ReflectionClass(CategoryData::class))->getMethod('w65RootId');
        foreach (Category::ROOT_CATEGORIES as $root) {
            self::assertTrue($rootId->invoke(null, $root), (string) $root);
        }
        self::assertSame([Category::STATUS_INACTIVE, Category::STATUS_ACTIVE, Category::STATUS_DISABLED], [0, 1, 2]);
    }

    public function test_release_rows_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ReleaseRows::class, [[], [], [], -9, 0]);
        self::assertSame([0, 0, 0, 0, 0, -1], [...\array_slice($empty, 0, 5), $empty[20]]);
        $out = $this->assertParity(ReleaseRows::class, [[self::GUID, 'XYZ', self::GUID], ['250', '', 'x'],
            ['categories_id' => [2040, 5030, -5], 'size' => [1_000, 0, 7], 'grabs' => [3, 0, 2_000_000], 'comments' => [0, 1, 2]], 240, 2]);
        self::assertSame([3, 250, 240, 2, 1], \array_slice($out, 0, 5));
        self::assertSame([2, 1_007, 2_000_003, 3, 1_000, 1], \array_slice($out, 15, 6));
        self::assertSame(2 | (1 | 2 | 8 | 16 | 32) << 4 | 3 << 10 | 40 << 46, $out[ReleaseRows::W65_ROWS_HEADER]);
        foreach ([[PHP_INT_MIN, PHP_INT_MIN], [-1, -1], [0, 0], [99, 1], [1_000, 100], [PHP_INT_MAX, PHP_INT_MAX]] as [$offset, $limit]) {
            foreach (self::TOTAL_ODD as $total) {
                $this->assertParity(ReleaseRows::class, [[self::GUID, ...self::GUID_ODD], [$total, ...self::TOTAL_ODD],
                    ['categories_id' => [...self::EXTREMES, 999, 1000, 9999, 10_000], 'size' => self::EXTREMES, 'grabs' => array_reverse(self::EXTREMES),
                        'comments' => self::EXTREMES], $offset, $limit]);
            }
        }
        $this->assertParity(ReleaseRows::class, [['k' => self::GUID, 9 => 7], [4 => '12'], ['size' => ['a' => 5], 'unknown' => [1]], 0, 0]);
    }

    public function test_xml_response_context_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(XmlResponseContext::class, [[], [], []]);
        self::assertSame([0, -1], [$empty[0], $empty[19]]);
        $out = $this->assertParity(XmlResponseContext::class, [[' Caps ', 'search', 'TV', 'd', 'register', 'bogus'],
            ['https://indexer.example/', 'HTTP://idx:8080/api', 'ftp://x', 'https://hést/', 'http://', 'https://ok'],
            ['offset' => [0, 10, 5, 0, 0, 0], 'total' => [0, 100, 3, 0, 0, 0], 'items' => [0, 5, 1, 2, 0, 0]]]);
        self::assertSame([1, 1, 1, 0, 0, 0, 1, 1, 1, 2, 2, 1, 1, 2, 3, 8, 100, 3, 2], \array_slice($out, 1, 19));
        self::assertSame(1 | 1 << 4 | 1 << 6 | (1 | 8 | 32) << 8 | 5 << 14 | 10 << 34 | 19 << 58, $out[XmlResponseContext::W65_XML_HEADER + 1]);
        $types = $servers = [];
        foreach (self::TYPE_ODD as $at => $type) {
            foreach (self::SERVER_ODD as $i => $server) {
                $types[] = $type;
                $servers[] = self::SERVER_ODD[($i + $at) % \count(self::SERVER_ODD)];
            }
        }
        $n = \count($types);
        $metrics = ['offset' => [], 'total' => [], 'items' => []];
        for ($i = 0; $i < $n; $i++) {
            $metrics['offset'][] = self::EXTREMES[$i % 11];
            $metrics['total'][] = self::EXTREMES[($i * 3) % 11];
            $metrics['items'][] = [0, 1, 2, 5, -1, 1_048_575, 1_048_576][$i % 7];
        }
        $this->assertParity(XmlResponseContext::class, [$types, $servers, $metrics]);
        $this->assertParity(XmlResponseContext::class, [['x' => 'caps', 'y' => 'tv'], [3 => 'https://a/'], ['items' => [9 => 4]]]);
    }

    public function test_category_data_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(CategoryData::class, [[], []]);
        self::assertSame([0, 0, 0, 0, -1, -1], [$empty[0], $empty[9], $empty[12], $empty[13], $empty[14], $empty[15]]);
        $out = $this->assertParity(CategoryData::class, [['Movies', 'HD', 'SD', ' ', 'Lost', 'Bad', 'TV', 'Fïlm', 'HD'],
            ['id' => [2000, 2040, 2030, 0, 5040, 3010, 5000, 2050, 2040], 'parentid' => [0, 2000, 2000, 0, 7000, 2000, -1, 2000, 2000],
                'status' => [1, 1, 0, 9, 1, 2, 1, 1, 1]]]);
        self::assertSame([2, 4, 1, 2, 1, 6, 1, 1, 2, 1, 1, 4, 1, 3, 4], \array_slice($out, 1, 15));
        $ids = [...Category::ROOT_CATEGORIES, 9000, 10_000, 1500, 0, -1, 99_999, 100_000, PHP_INT_MAX, PHP_INT_MIN];
        $titles = $metrics = [];
        foreach ($ids as $at => $id) {
            foreach ([...self::OTHER, ...self::MOVIES, ...self::TV, 2000, 999, 1999] as $i => $child) {
                $titles[] = self::TITLE_ODD[($i + $at) % \count(self::TITLE_ODD)];
                $metrics['id'][] = $child;
                $metrics['parentid'][] = $id;
                $metrics['status'][] = [0, 1, 2, 3, -1, PHP_INT_MAX][($i + $at) % 6];
            }
            $titles[] = 'Root';
            $metrics['id'][] = $id;
            $metrics['parentid'][] = [0, -1, PHP_INT_MIN, 1][$at % 4];
            $metrics['status'][] = 1;
        }
        $this->assertParity(CategoryData::class, [$titles, $metrics]);
        $this->assertParity(CategoryData::class, [['a' => 'X', 'b' => null], ['id' => [5 => 1000], 'nope' => [1]]]);
    }

    public function test_details_data_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(DetailsData::class, [[], [], [], 0]);
        self::assertSame([0, 0, 0, -1], [$empty[0], $empty[15], $empty[16], $empty[17]]);
        $out = $this->assertParity(DetailsData::class, [[self::GUID, self::GUID, 'bad'], ['2020-01-01 00:00:00', '2030-01-01 00:00:00', 'nope'],
            ['categories_id' => [2040, 5030, 10], 'size' => [100, 200, -1], 'totalpart' => [5, 70_000, 0], 'grabs' => [1, 2, 3], 'comments' => [0, 4, 0],
                'imdbid' => [0, 0, 7], 'tvdb' => [0, 9, 0], 'tmdb' => [3, 0, 0]], 1_600_000_000]);
        self::assertSame([2, 2, 1, 1, 1, 1, 1, 1, 1, 1, 300, 6, 4, 70_005, 1_893_456_000, 1_577_836_800, 2], \array_slice($out, 1, 17));
        self::assertSame(1 | 2 | 1 << 3 | 4 << 5 | 1 << 8 | 256 << 9 | 5 << 25 | 1 << 41, $out[DetailsData::W65_DETAILS_HEADER]);
        foreach (self::POSTDATE_ODD as $date) {
            $epoch = (new ReflectionClass(DetailsData::class))->getMethod('w65Postdate')->invoke(null, $date);
            $parsed = str_contains($date, "\0") ? false : \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date, new \DateTimeZone('UTC'));
            $strict = $parsed !== false && $parsed->format('Y-m-d H:i:s') === $date && (int) $parsed->format('Y') >= 1970 && (int) $parsed->format('Y') <= 2099;
            self::assertSame($strict ? $parsed->getTimestamp() : null, $epoch, $date);
        }
        $count = \count(self::POSTDATE_ODD) * 4;
        $metrics = [];
        foreach (DetailsData::W65_DETAILS_COLUMNS as $c => $column) {
            for ($i = 0; $i < $count; $i++) {
                $metrics[$column][] = $column === 'categories_id' ? [...self::MOVIES, ...self::TV, ...self::OTHER][($i + $c) % 36] : self::EXTREMES[($i + $c) % 11];
            }
        }
        $guids = array_map(fn (int $i): string => [self::GUID, ...self::GUID_ODD][$i % 10], range(0, $count - 1));
        $dates = array_map(fn (int $i): string => self::POSTDATE_ODD[$i % \count(self::POSTDATE_ODD)], range(0, $count - 1));
        foreach ([PHP_INT_MIN, -1, 0, 1, 1_600_000_000, 4_102_444_800, PHP_INT_MAX] as $now) {
            $this->assertParity(DetailsData::class, [$guids, $dates, $metrics, $now]);
        }
        $this->assertParity(DetailsData::class, [['z' => self::GUID], [], ['tvdb' => [4 => 1]], 5]);
    }

    public function test_release_data_parity(): void
    {
        $this->requireBindings();
        $empty = $this->assertParity(ReleaseData::class, [[], [], []]);
        self::assertSame([0, 0, 0, -1], [$empty[0], $empty[19], $empty[20], $empty[22]]);
        $out = $this->assertParity(ReleaseData::class, [[self::GUID, '', self::GUID], ['A & B <x>', 'plain', "it's \"q\""],
            ['categories_id' => [2040, 5030, 1], 'size' => [0, 2_147_483_648, 104_857_600], 'grabs' => [0, 3, -1], 'comments' => [5, 0, 0],
                'passwordstatus' => [-1, 0, 3], 'imdbid' => [1, 0, 1], 'tmdbid' => [0, 2, 1], 'traktid' => [0, 0, 0], 'tvdb' => [9, 9, 9], 'tvmaze' => [0, -4, 0]]]);
        self::assertSame([1, 1, 1, 1, 1, 0, 1, 1, 1, 0, 1, 1, 0, 1, 0, 1, 1, 2, 2_252_341_248, 47, 2, 1], \array_slice($out, 1, 22));
        self::assertSame(2 | 0b11010 << 2 | 3 << 7 | 1 << 12 | 5 << 15 | 2_048 << 31, $out[ReleaseData::W65_RELEASE_HEADER + 1]);
        $count = 240;
        $metrics = [];
        foreach (ReleaseData::W65_RELEASE_COLUMNS as $c => $column) {
            for ($i = 0; $i < $count; $i++) {
                $metrics[$column][] = $column === 'categories_id' ? [...self::MOVIES, ...self::TV, ...self::OTHER][($i * 7 + $c) % 36]
                    : [...self::EXTREMES, 104_857_599, 104_857_600, 1_073_741_823, 1_073_741_824, 10_737_418_239, 10_737_418_240, 2, 3][($i + $c) % 19];
            }
        }
        $guids = array_map(fn (int $i): string => [self::GUID, ...self::GUID_ODD][$i % 10], range(0, $count - 1));
        $titles = array_map(fn (int $i): string => self::RELEASE_TITLE_ODD[$i % 10], range(0, $count - 1));
        $this->assertParity(ReleaseData::class, [$guids, $titles, $metrics]);
        $this->assertParity(ReleaseData::class, [['q' => self::GUID, 'r' => 5], [1 => null], ['tvmaze' => [-1, -1], 'categories_id' => [5000, 5000, 5000]]]);
    }

    public function test_native_rejects_malformed_buffers(): void
    {
        $this->requireBindings();
        $ffi = RustPipelineLibrary::resolveDomain('api_w656_test', <<<'C'
            int32_t nntmux_w656_category_data_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
            int32_t nntmux_w656_release_rows_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t offset, int64_t limit, int64_t *out, size_t out_cap);
            C);
        self::assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[64]');
        $out[0] = 77;
        $ints = pack('q*', 2000, 0, 1);
        $call = static fn (string $symbol, mixed ...$args): mixed => $ffi->$symbol(...$args);
        $category = static fn (mixed ...$args): mixed => $call('nntmux_w656_category_data_batch', ...$args);
        $rows = static fn (mixed ...$args): mixed => $call('nntmux_w656_release_rows_batch', ...$args);
        // Short integer columns, mismatched twin, count above the separators, a too-small or null output.
        self::assertSame(-1, $category('Movies', 6, '', 0, 'abcd', 4, 1, $out, 64));
        self::assertSame(-1, $category("Movies\0x", 8, "Movies\x02x", 8, $ints.$ints, 48, 2, $out, 64));
        self::assertSame(-1, $category('Movies', 6, '', 0, $ints.$ints, 48, 2, $out, 64));
        self::assertSame(-2, $category('Movies', 6, '', 0, $ints, 24, 1, $out, 3));
        self::assertSame(-2, $category('Movies', 6, '', 0, $ints, 24, 1, null, 64));
        // Two string columns need exactly two items per row and four integer columns.
        self::assertSame(-1, $rows(self::GUID, 40, '', 0, pack('q*', 1, 2, 3, 4), 32, 1, 0, 0, $out, 64));
        self::assertSame(-1, $rows(self::GUID."\0".'5', 42, '', 0, pack('q*', 1, 2, 3), 24, 1, 0, 0, $out, 64));
        self::assertSame(77, $out[0]);
        self::assertSame(1, $rows(self::GUID."\0".'5', 42, '', 0, pack('q*', 2040, 2, 3, 4), 32, 1, 0, 0, $out, 64));
        self::assertSame([1, 5, 0, 1, 1], [$out[0], $out[1], $out[2], $out[3], $out[4]]);
        self::assertSame(1, $category('Movies', 6, '', 0, $ints, 24, 1, $out, 64));
        self::assertSame([1, 1, 1], [$out[0], $out[1], $out[9]]);
    }

    public function test_nul_unicode_and_mismatched_columns_parity(): void
    {
        $this->requireBindings();
        $nul = ["\0", "a\0b", "\0\0", "a\x01b", "\x01", "\0\x01"];
        $this->assertParity(ReleaseRows::class, [[...$nul, 'ü', self::GUID], ["4\0", "\x01"], ['size' => [1, 2]], 3, 2]);
        $this->assertParity(XmlResponseContext::class, [[...$nul, 'séarch', 'caps'], ["https://a\0/", "\x01", 'https://ü/'], ['items' => [1, 2, 3]]]);
        $this->assertParity(CategoryData::class, [[...$nul, 'Fïlm', '電視'], ['id' => [1000, 1010, 1020], 'parentid' => [0, 1000, 1000]]]);
        $this->assertParity(DetailsData::class, [[...$nul, self::GUID], ["2020-01-01\x0000:00:00", "\x01"], ['categories_id' => [2000]], 1]);
        $this->assertParity(ReleaseData::class, [[...$nul, self::GUID], ["A\0&", "\x01<"], ['categories_id' => [5000, 2000]]]);
        foreach (array_keys(self::WAVE) as $class) {
            $out = $this->assertParity($class, $this->benchmarkArgs($class, 0, true, 1));
            self::assertSame(0, $out[0]);
        }
    }

    public function test_native_declines_malformed_batches_and_dispatch_matches_php(): void
    {
        $this->requireBindings();
        // Short columns are padded before marshaling, so the public method serves the PHP result.
        $args = [['a', 'b'], [], ['offset' => [1, 2]]];
        $php = XmlResponseContext::w65XmlResponseContextPlanBatchPhp(...$args);
        self::assertSame($php, XmlResponseContext::withW65RustFfi(true, static fn (): array => XmlResponseContext::w65XmlResponseContextPlanBatch(...$args)));
        $ffi = RustPipelineLibrary::resolveDomain('api_w656_test', <<<'C'
            int32_t nntmux_w656_xml_context_batch(const char *strings, size_t strings_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
            C);
        self::assertInstanceOf(FFI::class, $ffi);
        $out = $ffi->new('int64_t[64]');
        // A batch whose string items disagree with the count is declined, so PHP would compute it.
        // @phpstan-ignore method.notFound
        self::assertSame(-1, $ffi->nntmux_w656_xml_context_batch("a\0b", 3, '', 0, pack('q*', 0, 0, 0, 0, 0, 0), 48, 2, $out, 64));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: int, 2: bool, 3: int}>
     */
    public static function datasetProvider(): iterable
    {
        foreach (array_keys(self::WAVE) as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            yield $short.' common' => [$class, 320, false, 656];
            yield $short.' uncommon' => [$class, 384, true, 657];
            yield $short.' large common' => [$class, 4_096, false, 658];
        }
    }

    /**
     * @param  class-string  $class
     */
    #[DataProvider('datasetProvider')]
    public function test_realistic_dataset_parity(string $class, int $count, bool $uncommon, int $seed): void
    {
        $this->requireBindings();
        $out = $this->assertParity($class, $this->benchmarkArgs($class, $count, $uncommon, $seed));
        self::assertSame($count, $out[0]);
    }

    public function test_randomized_fuzz_parity(): void
    {
        $this->requireBindings();
        $checked = array_fill_keys(array_keys(self::WAVE), 0);
        for ($round = 0; $round < 40; $round++) {
            foreach (array_keys(self::WAVE) as $class) {
                $count = (new Randomizer(new Mt19937($round)))->getInt(0, 96);
                $this->assertParity($class, $this->benchmarkArgs($class, $count, $round % 2 === 1, 9_650 + $round));
                $checked[$class] += $count;
            }
        }
        foreach ($checked as $class => $records) {
            self::assertGreaterThanOrEqual(300, $records, $class);
        }
    }

    /**
     * Protocol (set RUN_RUST_FFI_BENCHMARKS=1): seed 656, 8 fixture variants, 50 warmups and
     * NNTMUX_W656_BENCH_PAIRS (default 1,000, at least 60) randomized-order interleaved pairs per
     * class/workload. Common batches hold 320 typical records; uncommon ones 384 malformed, padded, unknown,
     * Unicode, NUL-holding, oversized or extreme records. Fixtures are built outside timing and parity is
     * asserted on every pair. Timings cover public dispatch, marshaling, FFI and unpacking. Nearest-rank p99.
     * The summary and raw pairs are written to the system temp directory.
     */
    public function test_paired_randomized_common_and_uncommon_benchmarks(): void
    {
        if (getenv('RUN_RUST_FFI_BENCHMARKS') !== '1') {
            self::markTestSkipped('Set RUN_RUST_FFI_BENCHMARKS=1 to run the paired Wave 65.6 benchmark.');
        }
        $this->requireBindings();
        $pairs = max(60, (int) (getenv('NNTMUX_W656_BENCH_PAIRS') ?: 1000));
        $random = new Randomizer(new Mt19937(656));
        $summary = $records = [];
        foreach (array_keys(self::WAVE) as $class) {
            foreach (['common' => false, 'uncommon' => true] as $workload => $uncommon) {
                $variants = [];
                for ($v = 0; $v < 8; $v++) {
                    $variants[] = $this->benchmarkCall($class, $uncommon ? 384 : 320, $uncommon, 656 + $v * 31 + ($uncommon ? 7 : 0));
                }
                $php = $rust = [];
                for ($i = -50; $i < $pairs; $i++) {
                    $variant = $random->getInt(0, 7);
                    $order = $random->getInt(0, 1) === 0 ? [false, true] : [true, false];
                    $results = $elapsed = [];
                    foreach ($order as $native) {
                        $call = $variants[$variant];
                        // The routing scope is set outside the timed region; the public batch call (its own
                        // dispatch, marshaling, FFI and unpacking) is timed inside it.
                        [$results[(int) $native], $elapsed[(int) $native]] = $class::withW65RustFfi($native, static function () use ($call): array {
                            $start = hrtime(true);
                            $result = $call();

                            return [$result, hrtime(true) - $start];
                        });
                    }
                    self::assertSame($results[0], $results[1], $class.' '.$workload);
                    if ($i >= 0) {
                        $php[] = $elapsed[0];
                        $rust[] = $elapsed[1];
                        $records[] = [$class, $workload, $variant, $order[0] ? 'rust-first' : 'php-first', $elapsed[0], $elapsed[1]];
                    }
                }
                sort($php);
                sort($rust);
                $p99 = (int) ceil($pairs * 0.99) - 1;
                $summary[$class][$workload] = [
                    'mean_speedup' => round(array_sum($php) / array_sum($rust), 3),
                    'p99_speedup' => round($php[$p99] / $rust[$p99], 3),
                    'php_mean_ns' => intdiv(array_sum($php), $pairs), 'rust_mean_ns' => intdiv(array_sum($rust), $pairs),
                    'php_p99_ns' => $php[$p99], 'rust_p99_ns' => $rust[$p99],
                ];
            }
        }
        $metadata = ['utc' => gmdate('c'), 'php' => PHP_VERSION, 'os' => php_uname(), 'pairs' => $pairs, 'warmups' => 50, 'seed' => 656, 'variants' => 8,
            'opcache_cli' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit')];
        $artifact = sys_get_temp_dir().'/w656-benchmark-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($artifact, json_encode(['metadata' => $metadata, 'summary' => $summary, 'raw_pairs' => $records], JSON_THROW_ON_ERROR));
        fwrite(STDERR, "\nW65.6 paired benchmark artifact: ".$artifact."\n".json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        foreach (array_keys(self::WAVE) as $class) {
            $eligible = true;
            foreach ($summary[$class] as $metrics) {
                $eligible = $eligible && $metrics['mean_speedup'] >= 1.5 && $metrics['p99_speedup'] >= 1.5;
            }
            // A class below 1.5x on any metric must retain PHP; one that clears it may still retain PHP after a
            // failing run (see QUALIFICATION), but a Rust default must clear every metric.
            if (! $eligible || $class::usesW65RustFfi()) {
                self::assertSame($eligible, $class::usesW65RustFfi(), $class.' must default to Rust only when it clears >=1.5x mean/p99 on both workloads');
            }
        }
    }

    /**
     * Arguments of the class's batch method for one fixture.
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function benchmarkArgs(string $class, int $count, bool $uncommon, int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));

        return match ($class) {
            ReleaseRows::class => $this->rowsBatch($random, $count, $uncommon),
            XmlResponseContext::class => $this->xmlBatch($random, $count, $uncommon),
            CategoryData::class => $this->categoryBatch($random, $count, $uncommon),
            DetailsData::class => $this->detailsBatch($random, $count, $uncommon),
            default => $this->releaseBatch($random, $count, $uncommon),
        };
    }

    /**
     * @param  class-string  $class
     * @return Closure(): list<int>
     */
    private function benchmarkCall(string $class, int $count, bool $uncommon, int $seed): Closure
    {
        $args = $this->benchmarkArgs($class, $count, $uncommon, $seed);
        $method = self::WAVE[$class];

        return static fn (): array => $class::$method(...$args);
    }

    /**
     * @template T
     *
     * @param  list<T>  $values
     * @return T
     */
    private function pick(Randomizer $random, array $values): mixed
    {
        return $values[$random->getInt(0, \count($values) - 1)];
    }

    /**
     * An odd token: listed values, NUL-holding, random bytes or oversized repeats of one.
     *
     * @param  list<string>  $odd
     */
    private function oddToken(Randomizer $random, array $odd): string
    {
        $token = $this->pick($random, $odd);

        return match ($random->getInt(0, 7)) {
            0 => $token."\0".$token,
            1 => substr($random->getBytes(24), 0, $random->getInt(0, 24)),
            2 => str_repeat($token === '' ? 'x' : $token, $random->getInt(40, 120)),
            3 => ' '.strtoupper($token)."\t",
            default => $token,
        };
    }

    private function guid(Randomizer $random): string
    {
        return bin2hex($random->getBytes(20));
    }

    private function category(Randomizer $random): int
    {
        return $this->pick($random, [...self::MOVIES, ...self::MOVIES, ...self::TV, ...self::TV, ...self::OTHER]);
    }

    private function extreme(Randomizer $random): int
    {
        return $random->getInt(0, 1) === 0 ? $this->pick($random, self::EXTREMES) : $random->getInt(PHP_INT_MIN, PHP_INT_MAX);
    }

    /**
     * A search page of release rows; uncommon ones hold malformed GUIDs and totals and extreme metrics.
     *
     * @return list<mixed>
     */
    private function rowsBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $guids = $totals = [];
        $metrics = ['categories_id' => [], 'size' => [], 'grabs' => [], 'comments' => []];
        $total = (string) $random->getInt($count, 50_000);
        for ($i = 0; $i < $count; $i++) {
            $guids[] = $uncommon && $random->getInt(0, 2) === 0 ? $this->oddToken($random, self::GUID_ODD) : $this->guid($random);
            $totals[] = $uncommon && $random->getInt(0, 2) === 0 ? $this->oddToken($random, self::TOTAL_ODD) : $total;
            $metrics['categories_id'][] = $uncommon && $random->getInt(0, 4) === 0 ? $this->extreme($random) : $this->category($random);
            $metrics['size'][] = $uncommon && $random->getInt(0, 4) === 0 ? $this->extreme($random) : $random->getInt(1 << 20, 80 << 30);
            $metrics['grabs'][] = $uncommon && $random->getInt(0, 4) === 0 ? $this->extreme($random) : $random->getInt(0, 5_000);
            $metrics['comments'][] = $uncommon && $random->getInt(0, 4) === 0 ? $this->extreme($random) : $random->getInt(0, 30);
        }
        $offset = $uncommon ? $this->extreme($random) : $random->getInt(0, 10) * 100;
        $limit = $uncommon ? $this->extreme($random) : $this->pick($random, [0, 25, 50, 100]);

        return [$guids, $totals, $metrics, $offset, $limit];
    }

    /**
     * Newznab/Torznab response contexts; uncommon ones hold aliased or unknown types, malformed or Unicode
     * servers and extreme pagination.
     *
     * @return list<mixed>
     */
    private function xmlBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $types = $servers = [];
        $metrics = ['offset' => [], 'total' => [], 'items' => []];
        for ($i = 0; $i < $count; $i++) {
            $type = $this->pick($random, ['search', 'search', 'tvsearch', 'movie', 'caps', 'details', 'music', 'book']);
            $total = $random->getInt(0, 20_000);
            $offset = $random->getInt(0, 5) * 100;
            $items = $type === 'caps' ? 0 : ($type === 'details' ? 1 : min(100, max(0, $total - $offset)));
            $server = $this->pick($random, ['https://indexer.example/', 'https://indexer.example/api', 'http://10.0.0.5:8080/', 'https://nzb.example.org']);
            if ($uncommon) {
                match ($random->getInt(0, 4)) {
                    0, 1 => $type = $this->oddToken($random, self::TYPE_ODD),
                    2 => $server = $this->oddToken($random, self::SERVER_ODD),
                    3 => [$offset, $total, $items] = [$this->extreme($random), $this->extreme($random), $this->extreme($random)],
                    default => null,
                };
            }
            $types[] = $type;
            $servers[] = $server;
            $metrics['offset'][] = $offset;
            $metrics['total'][] = $total;
            $metrics['items'][] = $items;
        }

        return [$types, $servers, $metrics];
    }

    /**
     * A category tree of roots followed by their sub-categories; uncommon ones hold orphans, duplicates,
     * out-of-range ids, unknown statuses and blank, Unicode, NUL-holding or oversized titles.
     *
     * @return list<mixed>
     */
    private function categoryBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $titles = [];
        $metrics = ['id' => [], 'parentid' => [], 'status' => []];
        for ($i = 0; $i < $count; $i++) {
            $root = $this->pick($random, Category::ROOT_CATEGORIES);
            if ($i < 8) {
                [$id, $parent] = [Category::ROOT_CATEGORIES[$i], 0];
            } else {
                [$id, $parent] = [($root === 1 ? 0 : $root) + $random->getInt(1, 99) * 10, $root];
            }
            $status = $this->pick($random, [1, 1, 1, 1, 0, 2]);
            $title = $this->pick($random, ['HD', 'SD', 'UHD', 'Foreign', 'Other', 'WEB-DL', 'x265', 'Anime', 'Documentary']);
            if ($uncommon) {
                match ($random->getInt(0, 5)) {
                    0 => $parent = $this->pick($random, [7, 999, 1500, 10_000, -1, PHP_INT_MAX]),
                    1 => $id = $this->extreme($random),
                    2 => $status = $this->extreme($random),
                    3, 4 => $title = $this->oddToken($random, self::TITLE_ODD),
                    default => null,
                };
            }
            $titles[] = $title;
            $metrics['id'][] = $id;
            $metrics['parentid'][] = $parent;
            $metrics['status'][] = $status;
        }

        return [$titles, $metrics];
    }

    /**
     * Release details rows; uncommon ones hold malformed GUIDs and postdates and extreme metrics and ids.
     *
     * @return list<mixed>
     */
    private function detailsBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $guids = $dates = [];
        $metrics = array_fill_keys(DetailsData::W65_DETAILS_COLUMNS, []);
        for ($i = 0; $i < $count; $i++) {
            $guids[] = $uncommon && $random->getInt(0, 2) === 0 ? $this->oddToken($random, self::GUID_ODD) : $this->guid($random);
            $dates[] = $uncommon && $random->getInt(0, 2) === 0 ? $this->oddToken($random, self::POSTDATE_ODD)
                : gmdate('Y-m-d H:i:s', $random->getInt(1_000_000_000, 1_790_000_000));
            $category = $this->category($random);
            $row = [$category, $random->getInt(1 << 20, 80 << 30), $random->getInt(1, 500), $random->getInt(0, 5_000), $random->getInt(0, 30),
                $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 9_999_999), $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 500_000),
                $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 1_000_000)];
            foreach (DetailsData::W65_DETAILS_COLUMNS as $c => $column) {
                $metrics[$column][] = $uncommon && $random->getInt(0, 5) === 0 ? $this->extreme($random) : $row[$c];
            }
        }
        $now = $uncommon ? $this->pick($random, [PHP_INT_MIN, 0, 1, 1_700_000_000, PHP_INT_MAX]) : 1_790_000_000;

        return [$guids, $dates, $metrics, $now];
    }

    /**
     * Release item rows; uncommon ones hold malformed GUIDs, escape-heavy, Unicode, NUL-holding or oversized
     * titles and extreme metrics and ids.
     *
     * @return list<mixed>
     */
    private function releaseBatch(Randomizer $random, int $count, bool $uncommon): array
    {
        $guids = $titles = [];
        $metrics = array_fill_keys(ReleaseData::W65_RELEASE_COLUMNS, []);
        for ($i = 0; $i < $count; $i++) {
            $guids[] = $uncommon && $random->getInt(0, 2) === 0 ? $this->oddToken($random, self::GUID_ODD) : $this->guid($random);
            $titles[] = $uncommon && $random->getInt(0, 1) === 0 ? $this->oddToken($random, self::RELEASE_TITLE_ODD)
                : 'Release.Title.'.$random->getInt(1990, 2026).'.'.$this->pick($random, ['1080p', '720p', '2160p']).'.WEB-DL-GRP'.$i;
            $row = [$this->category($random), $random->getInt(1 << 20, 80 << 30), $random->getInt(0, 5_000), $random->getInt(0, 30), $this->pick($random, [0, 0, 0, 1, 2]),
                $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 9_999_999), $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 1_000_000),
                $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 500_000), $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 500_000),
                $random->getInt(0, 3) === 0 ? 0 : $random->getInt(1, 90_000)];
            foreach (ReleaseData::W65_RELEASE_COLUMNS as $c => $column) {
                $metrics[$column][] = $uncommon && $random->getInt(0, 5) === 0 ? $this->extreme($random) : $row[$c];
            }
        }

        return [$guids, $titles, $metrics];
    }

    /**
     * Asserts the PHP, native and both routed results agree and the layout holds one word per record.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $args
     * @return list<int>
     */
    private function assertParity(string $class, array $args): array
    {
        $method = self::WAVE[$class];
        $php = $class::withW65RustFfi(false, static fn (): array => $class::$method(...$args));
        $rust = $class::withW65RustFfi(true, static fn (): array => $class::$method(...$args));
        self::assertSame($php, $class::{$method.'Php'}(...$args), $class);
        self::assertSame($php, $class::{$method.'Native'}(...$args), $class);
        self::assertSame($php, $rust, $class);
        self::assertCount(self::HEADER[$class] + $php[0], $php, $class);

        return $php;
    }

    private function requireBindings(): void
    {
        if (ReleaseRows::w65ReleaseRowsPlanBatchNative([], [], []) === null
            || XmlResponseContext::w65XmlResponseContextPlanBatchNative([], [], []) === null
            || CategoryData::w65CategoryDataPlanBatchNative([], []) === null
            || DetailsData::w65DetailsDataPlanBatchNative([], [], []) === null
            || ReleaseData::w65ReleaseDataPlanBatchNative([], [], []) === null
        ) {
            self::markTestSkipped('The Wave 65.6 Rust bindings are unavailable; build rust/pipeline-ffi in release mode.');
        }
    }
}
