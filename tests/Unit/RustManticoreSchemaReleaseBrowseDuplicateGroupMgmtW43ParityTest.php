<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Release;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseGroupResolver;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Search\Support\ManticoreSchemaInspector;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use stdClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_gates_rust_default`):
 * per helper, 300 samples per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib. The timings include PHP-side packet marshaling, the FFI call and result decoding. See the
 * commit message for the measured run; each run prints its ratios to STDERR.
 *
 * Marshaling and FFI scratch access dominate these summaries, so a helper only defaults to Rust when
 * every common and uncommon mean and p99 ratio reaches 1.5x.
 */
class RustManticoreSchemaReleaseBrowseDuplicateGroupMgmtW43ParityTest extends TestCase
{
    /** @var array<class-string, string> */
    private const DEFAULTS = [
        ManticoreSchemaInspector::class => 'W43_MANTICORE_SCHEMA_RUST_FFI_DEFAULT',
        ReleaseBrowseService::class => 'W43_RELEASE_BROWSE_RUST_FFI_DEFAULT',
        ReleaseDuplicateFinder::class => 'W43_RELEASE_DUPLICATE_RUST_FFI_DEFAULT',
        ReleaseGroupResolver::class => 'W43_RELEASE_GROUP_RUST_FFI_DEFAULT',
        ReleaseManagementService::class => 'W43_RELEASE_MANAGEMENT_RUST_FFI_DEFAULT',
    ];

    private const RANDOM_ROUNDS = 2500;

    private const ALPHABET = ['a', 'B', 'z', '0', '7', '_', '-', '.', '/', '=', ' ', "\t", "'", '"', "\0", "\n", "\r", "\x0b", "\x0c", "\x85", "\xc3", "\xa9", "\xff"];

    private const SETTINGS_BLOB = "min_infix_len = 2\nindex_field_lengths = 1\nmorphology = 'stem_en'\nexact_words = 1\nhtml_strip = '0'";

    protected function tearDown(): void
    {
        foreach (array_keys(self::DEFAULTS) as $class) {
            $class::useW43RustFfi(null);
        }
        (new ReflectionClass(ManticoreSchemaInspector::class))->setStaticPropertyValue('w43Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::DEFAULTS as $class => $constant) {
            $this->assertTrue((new ReflectionClass($class))->hasMethod('useW43RustFfi'), $class);
            $default = (new ReflectionClassConstant($class, $constant))->getValue();
            $this->assertIsBool($default);
            $this->assertSame($default, $class::usesW43RustFfi(), "{$class} must start at its default");
            $class::useW43RustFfi(true);
            $this->assertTrue($class::usesW43RustFfi());
            $class::useW43RustFfi(false);
            $this->assertFalse($class::usesW43RustFfi());
            $class::useW43RustFfi();
            $this->assertTrue($class::usesW43RustFfi());
            $class::useW43RustFfi(null);
            $this->assertSame($default, $class::usesW43RustFfi());
        }

        // Earlier toggles are independent of the Wave 43.5 ones.
        $schema = ManticoreSchemaInspector::usesRustFfi();
        $browse = (new ReflectionClass(ReleaseBrowseService::class))->getStaticPropertyValue('useRustFfi');
        $duplicate = ReleaseDuplicateFinder::$useRustFfi;
        $group = (new ReflectionClass(ReleaseGroupResolver::class))->getStaticPropertyValue('useRustFfi');
        $management = ReleaseManagementService::$useRustFfi;
        foreach (array_keys(self::DEFAULTS) as $class) {
            $class::useW43RustFfi(! $class::usesW43RustFfi());
        }
        $this->assertSame($schema, ManticoreSchemaInspector::usesRustFfi());
        $this->assertSame($browse, (new ReflectionClass(ReleaseBrowseService::class))->getStaticPropertyValue('useRustFfi'));
        $this->assertSame($duplicate, ReleaseDuplicateFinder::$useRustFfi);
        $this->assertSame($group, (new ReflectionClass(ReleaseGroupResolver::class))->getStaticPropertyValue('useRustFfi'));
        $this->assertSame($management, ReleaseManagementService::$useRustFfi);

        // Toggling one class leaves the others alone.
        foreach (array_keys(self::DEFAULTS) as $class) {
            $class::useW43RustFfi(false);
        }
        ReleaseGroupResolver::useW43RustFfi(true);
        $this->assertTrue(ReleaseGroupResolver::usesW43RustFfi());
        $this->assertFalse(ManticoreSchemaInspector::usesW43RustFfi());
        $this->assertFalse(ReleaseManagementService::usesW43RustFfi());
    }

    public function test_settings_blob_batch_examples(): void
    {
        $expected = ['MIN_INFIX_LEN' => 2, 'index_field_lengths' => '1'];
        $blobs = [
            self::SETTINGS_BLOB,
            "min_infix_len = 3\r\nmin_infix_len = 2\x85index_field_lengths=\"1\"",
            42,
            '',
            "  Exact_Words='1' \n a = \"x y\"  \n bad line\n a=b\"c\n k-1=2\n x =  \"",
            ['settings' => 'min_infix_len = 2'],
        ];
        $this->assertSame(
            ['blobs' => 6, 'invalid' => 2, 'lines' => 15, 'settings' => 11, 'distinct_keys' => 7, 'max_settings_per_blob' => 5, 'compliant_blobs' => 2],
            $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch($blobs, $expected)),
        );
        $this->assertSame(
            ['blobs' => 0, 'invalid' => 0, 'lines' => 0, 'settings' => 0, 'distinct_keys' => 0, 'max_settings_per_blob' => 0, 'compliant_blobs' => 0],
            $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch([])),
        );
        // Lowercase-colliding expectations must all hold; null expects an empty value.
        $this->assertSame(0, $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch(['a = 1'], ['A' => 1, 'a' => 2]))['compliant_blobs']);
        $this->assertSame(1, $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch(["a =  '\nb= \"\nc = ''"], ['a' => null, 'b' => '']))['compliant_blobs']);
        $this->assertSame(1, $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch(['123 = X'], [123 => 'x']))['compliant_blobs']);

        // Compliance agrees with the inspector's own missing-settings check.
        $production = ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1];
        foreach ([self::SETTINGS_BLOB, 'min_infix_len = 2', "MIN_INFIX_LEN='2'\nexact_words=1\nindex_field_lengths = \"1\""] as $blob) {
            $summary = $this->both(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch([$blob], $production));
            $this->assertSame(ManticoreSchemaInspector::missingSettingsPhp(['settings' => $blob], $production) === [] ? 1 : 0, $summary['compliant_blobs'], $blob);
        }
    }

    public function test_browse_order_batch_examples(): void
    {
        $service = new ReleaseBrowseService;
        $orders = ['', 'name_ASC', 'cat_up', 'size', 7, 'files_desc', "stats_asc\n", 'posted_asc_x', 'name_ASC', 'comments_desc', 'added_asc', '_asc', 'bogus_DESC', ['x']];
        $this->assertSame(
            ['orders' => 14, 'invalid' => 2, 'categories_id' => 1, 'searchname' => 2, 'size' => 1, 'totalpart' => 1, 'grabs' => 1, 'comments' => 1, 'adddate' => 1, 'postdate' => 4, 'asc' => 6, 'desc' => 6, 'explicit_direction' => 10, 'index_sortable' => 10, 'distinct_orders' => 10],
            $this->both(ReleaseBrowseService::class, static fn () => $service->summarizeBrowseOrderBatch($orders)),
        );
        $this->assertSame(0, $this->both(ReleaseBrowseService::class, static fn () => $service->summarizeBrowseOrderBatch([]))['orders']);

        // Each counted column and direction is what getBrowseOrderPhp() resolves.
        foreach ($service->getBrowseOrdering() as $order) {
            [$field, $direction] = $service->getBrowseOrderPhp($order);
            $summary = $this->both(ReleaseBrowseService::class, static fn () => $service->summarizeBrowseOrderBatch([$order]));
            $this->assertSame(1, $summary[$field], $order);
            $this->assertSame(1, $summary[$direction], $order);
            $this->assertSame(1, $summary['explicit_direction'], $order);
        }
    }

    public function test_duplicate_candidate_batch_examples(): void
    {
        $finder = new ReleaseDuplicateFinder;
        $release = new Release;
        $release->setRawAttributes(['predb_id' => '7', 'searchname' => 'Show.S01', 'size' => '1000']);
        $candidates = [
            ['predb_id' => 7, 'searchname' => 'Other', 'size' => 950],
            ['predb_id' => 0, 'searchname' => 'Show.S01', 'size' => 1051],
            ['predb_id' => '3', 'searchname' => 'x', 'size' => '1050'],
            'nope',
            $release,
            new stdClass,
            ['predb_id' => [7], 'searchname' => 'Show.S01', 'size' => 1],
            ['searchname' => null],
            ['predb_id' => 7.9, 'searchname' => 12, 'size' => true],
        ];
        $this->assertSame(
            ['candidates' => 9, 'invalid' => 3, 'predb_id_match' => 3, 'searchname_match' => 1, 'name_match_fallback' => 2, 'within_size_bounds' => 3, 'distinct_searchnames' => 5],
            $this->both(ReleaseDuplicateFinder::class, static fn () => $finder->summarizeCandidateBatch($candidates, 'Show.S01', 7, 1000)),
        );
        $this->assertSame(
            ['candidates' => 2, 'invalid' => 0, 'predb_id_match' => 0, 'searchname_match' => 0, 'name_match_fallback' => 2, 'within_size_bounds' => 2, 'distinct_searchnames' => 1],
            $this->both(ReleaseDuplicateFinder::class, static fn () => $finder->summarizeCandidateBatch([['predb_id' => 0], ['predb_id' => -1]], '', 0, 0, 0.5)),
        );

        // Labels agree with resolveReasonPhp().
        foreach ([[7, 'Show.S01'], [0, 'Show.S01'], [9, 'Other'], [0, '']] as [$predb, $name]) {
            $summary = $this->both(ReleaseDuplicateFinder::class, static fn () => $finder->summarizeCandidateBatch([$release], $name, $predb, 1000));
            $this->assertSame(1, $summary[$finder->resolveReasonPhp($release, $name, $predb)]);
        }
    }

    public function test_crosspost_name_batch_examples(): void
    {
        $long = 'a.b.'.str_repeat('x', 242);
        $names = ['a.b.teevee', 'A.B.teevee', 'a.b.teevee', 'alt.binaries.teevee', 'nodot', 5, 'bad..dots', "x.y\n", "x.y\n\n", $long, $long.'x', '.x.y', 'x-1._y', '', null];
        $this->assertSame(
            ['names' => 15, 'invalid' => 2, 'duplicates' => 1, 'valid' => 6, 'rejected' => 5, 'too_long' => 1, 'aliased' => 3, 'distinct_groups' => 4, 'max_group_length' => 255],
            $this->both(ReleaseGroupResolver::class, static fn () => ReleaseGroupResolver::summarizeCrosspostNameBatch($names)),
        );
        $this->assertSame(0, $this->both(ReleaseGroupResolver::class, static fn () => ReleaseGroupResolver::summarizeCrosspostNameBatch([]))['distinct_groups']);
    }

    public function test_export_date_bound_batch_examples(): void
    {
        $service = new ReleaseManagementService;
        $dates = ['01/02/2024', '', '1/2', 20240101, '31/12/2023', '01/02/2024', 'a/b/c/d', '//', null];
        $this->assertSame(
            ['dates' => 9, 'invalid' => 2, 'empty' => 1, 'malformed' => 2, 'formatted' => 4, 'distinct_bounds' => 3, 'earliest' => '--00:00:00', 'latest' => '2024-02-0100:00:00'],
            $this->both(ReleaseManagementService::class, static fn () => $service->summarizeExportDateBoundBatch($dates)),
        );
        $this->assertSame(
            ['dates' => 1, 'invalid' => 0, 'empty' => 0, 'malformed' => 0, 'formatted' => 1, 'distinct_bounds' => 1, 'earliest' => '2023-12-3123:59:59', 'latest' => '2023-12-3123:59:59'],
            $this->both(ReleaseManagementService::class, static fn () => $service->summarizeExportDateBoundBatch(['31/12/2023'], true)),
        );
        // strcmp ordering, not PHP 8 numeric string comparison.
        $this->assertSame(
            ['10-0-000:00:00', '9-0-000:00:00'],
            array_values(array_intersect_key($this->both(ReleaseManagementService::class, static fn () => $service->summarizeExportDateBoundBatch(['0/0/9', '0/0/10'])), ['earliest' => 1, 'latest' => 1])),
        );
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['1', '0', '1', '1', '1', '1', '1'], ManticoreSchemaInspector::evaluateW43Native(1, ['1', 'a', '1', '1', 'a = 1']));
        $this->assertSame(['1', '0', '0', '1', '0', '0', '0', '0', '0', '0', '1', '0', '1', '0', '1'], ManticoreSchemaInspector::evaluateW43Native(2, ['1', 'name_asc']));
        $this->assertSame(['1', '0', '1', '0', '0', '1', '1'], ManticoreSchemaInspector::evaluateW43Native(3, ['s', '4', '1', '9', '1', '4', 'x', '5']));
        $this->assertSame(['1', '0', '0', '1', '0', '0', '1', '1', '14'], ManticoreSchemaInspector::evaluateW43Native(4, ['1', 'a.b.x']));
        $this->assertSame(['1', '0', '0', '0', '1', '1', '3-2-123:59:59', '3-2-123:59:59'], ManticoreSchemaInspector::evaluateW43Native(5, ['1', '1', '1/2/3']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(-1, ['x']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(0, []));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(6, []));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(1, ['2', 'a', '1']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(2, ['3', 'x']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(3, ['s', '+4', '1', '9']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(3, ['s', '4', '1', '9', '1', '4', 'x']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(4, ['1']));
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(5, []));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(ManticoreSchemaInspector::class))->setStaticPropertyValue('w43Ffi', false);
        $this->assertNull(ManticoreSchemaInspector::evaluateW43Native(5, ['1']));

        $this->assertSame(ManticoreSchemaInspector::summarizeSettingsBlobBatchPhp([self::SETTINGS_BLOB, 1], ['a' => 1]), ManticoreSchemaInspector::summarizeSettingsBlobBatchNative([self::SETTINGS_BLOB, 1], ['a' => 1]));
        $browse = new ReleaseBrowseService;
        $this->assertSame($browse->summarizeBrowseOrderBatchPhp(['name_asc', 1]), $browse->summarizeBrowseOrderBatchNative(['name_asc', 1]));
        $finder = new ReleaseDuplicateFinder;
        $this->assertSame($finder->summarizeCandidateBatchPhp([['predb_id' => 1]], 'x', 1, 10), $finder->summarizeCandidateBatchNative([['predb_id' => 1]], 'x', 1, 10));
        $this->assertSame(ReleaseGroupResolver::summarizeCrosspostNameBatchPhp(['a.b.c', 1]), ReleaseGroupResolver::summarizeCrosspostNameBatchNative(['a.b.c', 1]));
        $management = new ReleaseManagementService;
        $this->assertSame($management->summarizeExportDateBoundBatchPhp(['1/2/3', 1]), $management->summarizeExportDateBoundBatchNative(['1/2/3', 1]));
    }

    public function test_stringable_expected_setting_falls_back_to_php(): void
    {
        $this->requireFfi();
        $value = new class
        {
            public function __toString(): string
            {
                return '2';
            }
        };
        $expected = ['min_infix_len' => $value];
        $this->assertSame(ManticoreSchemaInspector::summarizeSettingsBlobBatchPhp([self::SETTINGS_BLOB], $expected), ManticoreSchemaInspector::summarizeSettingsBlobBatchNative([self::SETTINGS_BLOB], $expected));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();
        $small = $ffi->new('char[8]');
        $big = $ffi->new('char[4096]');
        $call = static function (int $mode, string $input, mixed $out, int $cap) use ($ffi): int {
            // @phpstan-ignore method.notFound
            return $ffi->nntmux_w435_manticore_release_browse_duplicate_group_mgmt($mode, $input, \strlen($input), $out, $cap);
        };

        $packet = self::packet(['1', '1', '01/02/2024']);
        $written = $call(5, $packet, $big, 4096);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(-$written, $call(5, $packet, $small, 8));
        $this->assertSame(-$written, $call(5, $packet, null, 0));
        $this->assertSame(-1, $call(5, $packet, null, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $packet, $big, 4096));
        }
        $this->assertSame(-1, $call(5, '', $big, 4096));
        $this->assertSame(-1, $call(5, "\x01\x00\x00", $big, 4096));
        $this->assertSame(-1, $call(5, "\x01\x00\x00\x00\x09\x00\x00\x00", $big, 4096));
        $this->assertSame(-1, $call(5, $packet."\x00", $big, 4096));
        $this->assertSame(-1, $call(5, "\xff\xff\xff\xff", $big, 4096));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();
        mt_srand(4305);
        $browse = new ReleaseBrowseService;
        $finder = new ReleaseDuplicateFinder;
        $management = new ReleaseManagementService;
        $pick = static fn (array $items): mixed => $items[mt_rand(0, \count($items) - 1)];
        $keys = ['min_infix_len', 'MIN_INFIX_LEN', 'exact_words', 'index_field_lengths', 'a', '0', '12', '_x'];
        $values = ['1', '2', '0', '', "'1'", '"stem_en"', ' 1 ', "\x001\x00", 'X'];

        for ($round = 0; $round < self::RANDOM_ROUNDS; $round++) {
            $blobs = [];
            for ($i = mt_rand(0, 4); $i > 0; $i--) {
                if (mt_rand(0, 9) === 0) {
                    $blobs[] = $pick([null, 1, ['settings' => 'a=1'], 1.5]);

                    continue;
                }
                $lines = [];
                for ($j = mt_rand(0, 5); $j > 0; $j--) {
                    $lines[] = mt_rand(0, 2) === 0
                        ? self::randomBytes(16)
                        : $pick(['', ' ', "\t"]).$pick($keys).$pick(['', ' ']).$pick(['=', '=', ':']).self::randomBytes(3).$pick($values).self::randomBytes(3);
                }
                $blobs[] = implode($pick(["\n", "\r\n", "\r", "\x85", "\x0b", "\x0c"]), $lines);
            }
            $expected = [];
            for ($i = mt_rand(0, 3); $i > 0; $i--) {
                $expected[$pick($keys)] = $pick([1, 2, '0', 'x', null, true, 1.5, "'1'", 'stem_en']);
            }
            $this->assertParity(ManticoreSchemaInspector::class, static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch($blobs, $expected), [$blobs, $expected]);

            $orders = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $orders[] = match (mt_rand(0, 4)) {
                    0 => self::randomBytes(10),
                    1 => $pick([null, 1, ['name_asc']]),
                    default => $pick(['cat', 'name', 'size', 'files', 'stats', 'comments', 'added', 'posted', '', 'x']).$pick(['', '_']).$pick(['asc', 'desc', 'ASC', 'Desc', "asc\n", "desc\n\n", 'up', '']).$pick(['', '_x', '_']),
                };
            }
            $this->assertParity(ReleaseBrowseService::class, static fn () => $browse->summarizeBrowseOrderBatch($orders), [$orders]);

            $searchName = $pick(['', 'Show.S01', 'x', self::randomBytes(4)]);
            $predbId = $pick([0, 1, 7, -3, self::randomInt()]);
            $filesize = $pick([0, 1000, mt_rand(0, 1 << 40)]);
            $tolerance = $pick([0.0, 0.05, 0.5, 1.0]);
            $candidates = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $candidates[] = mt_rand(0, 9) === 0
                    ? $pick(['x', null, new stdClass, ['predb_id' => [1]]])
                    : [
                        'predb_id' => $pick([0, 1, 7, '7', -3, $predbId, null, true]),
                        'searchname' => $pick(['', 'Show.S01', 'x', $searchName, 12, null, self::randomBytes(4)]),
                        'size' => $pick([0, 1000, 950, 1050, '1050', $filesize, (int) floor($filesize * (1 - $tolerance)), (int) ceil($filesize * (1 + $tolerance)), null, self::randomInt()]),
                    ];
            }
            $this->assertParity(ReleaseDuplicateFinder::class, static fn () => $finder->summarizeCandidateBatch($candidates, $searchName, $predbId, $filesize, $tolerance), [$candidates, $searchName, $predbId, $filesize, $tolerance]);

            $names = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $names[] = match (mt_rand(0, 5)) {
                    0 => self::randomBytes(12),
                    1 => $pick([null, 1, ['a.b.c']]),
                    2 => $pick(['a.b.', 'A.b.', 'alt.binaries.', 'x.']).str_repeat('y', $pick([1, 5, 241, 242, 243, 250, 255])),
                    default => $pick(['a.b.', 'A.B.', 'alt.', '', '.', 'x.']).$pick(['teevee', 'multimedia', 'x-y_z', 'a..b', '']).$pick(['', "\n", "\n\n", '.', '.hd']),
                };
            }
            if ($names !== [] && mt_rand(0, 1) === 1) {
                $names[] = $pick($names);
            }
            $this->assertParity(ReleaseGroupResolver::class, static fn () => ReleaseGroupResolver::summarizeCrosspostNameBatch($names), [$names]);

            $dates = [];
            for ($i = mt_rand(0, 6); $i > 0; $i--) {
                $dates[] = match (mt_rand(0, 4)) {
                    0 => self::randomBytes(8),
                    1 => $pick([null, 1, ['01/01/2024']]),
                    default => implode('/', array_map(static fn (): string => (string) mt_rand(0, 40), range(1, $pick([1, 2, 3, 3, 3, 4])))),
                };
            }
            $endOfDay = mt_rand(0, 1) === 1;
            $this->assertParity(ReleaseManagementService::class, static fn () => $management->summarizeExportDateBoundBatch($dates, $endOfDay), [$dates, $endOfDay]);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();

        $browse = new ReleaseBrowseService;
        $finder = new ReleaseDuplicateFinder;
        $management = new ReleaseManagementService;
        $production = ['min_infix_len' => 2, 'exact_words' => 1, 'index_field_lengths' => 1];
        $orders = ['posted_desc', 'name_asc', 'size_desc'];
        $candidates = [
            ['predb_id' => 7, 'searchname' => 'Show.S01E01.720p', 'size' => 1000],
            ['predb_id' => 0, 'searchname' => 'Show.S01E01.720p', 'size' => 1020],
        ];
        $names = ['alt.binaries.teevee', 'a.b.multimedia', 'alt.binaries.hdtv.x264'];
        $dates = ['01/01/2024'];

        $bigBlobs = [];
        $bigOrders = [];
        $bigCandidates = [];
        $bigNames = [];
        $bigDates = [];
        $settingNames = ['min_infix_len', 'exact_words', 'index_field_lengths', 'morphology', 'html_strip', 'min_word_len', 'charset_table', 'stopwords'];
        $ordering = $browse->getBrowseOrdering();
        for ($i = 0; $i < 2000; $i++) {
            if ($i < 100) {
                $lines = [];
                for ($j = 0; $j < 30; $j++) {
                    $lines[] = sprintf("%s = %s'%d'", $settingNames[($i + $j) % 8], $j % 3 === 0 ? ' ' : '', ($i * $j) % 5);
                }
                $bigBlobs[] = implode("\n", $lines);
            }
            $bigOrders[] = $i % 9 === 0 ? 'bogus' : $ordering[$i % \count($ordering)];
            $bigCandidates[] = ['predb_id' => $i % 3 === 0 ? 7 : $i, 'searchname' => 'Show.S01E'.($i % 40).'.720p', 'size' => 900 + ($i % 200)];
            $bigNames[] = ($i % 4 === 0 ? 'a.b.' : 'alt.binaries.').'group'.($i % 300).($i % 50 === 0 ? '..bad' : '');
            $bigDates[] = sprintf('%02d/%02d/%04d', 1 + $i % 28, 1 + $i % 12, 2000 + $i % 25);
        }

        $workloads = [
            ManticoreSchemaInspector::class => [
                static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch([self::SETTINGS_BLOB], $production),
                static fn () => ManticoreSchemaInspector::summarizeSettingsBlobBatch($bigBlobs, $production),
            ],
            ReleaseBrowseService::class => [
                static fn () => $browse->summarizeBrowseOrderBatch($orders),
                static fn () => $browse->summarizeBrowseOrderBatch($bigOrders),
            ],
            ReleaseDuplicateFinder::class => [
                static fn () => $finder->summarizeCandidateBatch($candidates, 'Show.S01E01.720p', 7, 1000),
                static fn () => $finder->summarizeCandidateBatch($bigCandidates, 'Show.S01E01.720p', 7, 1000),
            ],
            ReleaseGroupResolver::class => [
                static fn () => ReleaseGroupResolver::summarizeCrosspostNameBatch($names),
                static fn () => ReleaseGroupResolver::summarizeCrosspostNameBatch($bigNames),
            ],
            ReleaseManagementService::class => [
                static fn () => $management->summarizeExportDateBoundBatch($dates),
                static fn () => $management->summarizeExportDateBoundBatch($bigDates, true),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $results = [];
            foreach (['common' => [$common, 50], 'uncommon' => [$uncommon, 2]] as $name => [$workload, $calls]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW43RustFfi($rust);
                        $this->assertSame($class::usesW43RustFfi(), $rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < 300; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW43RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::useW43RustFfi(null);

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 43.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
                (new ReflectionClass($class))->getShortName(),
                $results['common']['mean'],
                $results['common']['php_mean_us'],
                $results['common']['rust_mean_us'],
                $results['common']['p99'],
                $results['uncommon']['mean'],
                $results['uncommon']['php_mean_us'],
                $results['uncommon']['rust_mean_us'],
                $results['uncommon']['p99'],
            ));

            $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5
                && $results['uncommon']['mean'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
            if (! $qualifies) {
                $this->assertFalse($class::usesW43RustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesW43RustFfi());
            }
        }
        fwrite(STDERR, "\n");
    }

    /**
     * Runs $plan with PHP then Rust, asserts identical results and returns the PHP result.
     *
     * @template T
     *
     * @param  class-string  $class
     * @param  callable(): T  $plan
     * @return T
     */
    private function both(string $class, callable $plan): mixed
    {
        $class::useW43RustFfi(false);
        $php = $plan();
        $class::useW43RustFfi(true);
        $rust = $plan();
        $class::useW43RustFfi(null);
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW43RustFfi(false);
        $php = $plan();
        $class::useW43RustFfi(true);
        $rust = $plan();
        $class::useW43RustFfi(null);
        $this->assertSame($php, $rust, bin2hex(serialize($args)));
    }

    private static function randomBytes(int $maxLength): string
    {
        $out = '';
        for ($i = mt_rand(0, $maxLength); $i > 0; $i--) {
            $out .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
        }

        return $out;
    }

    private static function randomInt(): int
    {
        return match (mt_rand(0, 3)) {
            0 => mt_rand(PHP_INT_MIN, PHP_INT_MAX),
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1, PHP_INT_MAX - 1][mt_rand(0, 5)],
            default => mt_rand(-2, 50_000),
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', \count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', \strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, \count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(\count($values) - 1, (int) ceil(0.99 * \count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search_w435', ManticoreSchemaInspector::W43_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
