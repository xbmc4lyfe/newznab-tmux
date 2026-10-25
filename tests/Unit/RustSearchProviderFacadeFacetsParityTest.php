<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Elasticsearch;
use App\Http\Requests\Admin\AdminLogFacetsRequest;
use App\Http\Requests\Admin\AdminLogSearchRequest;
use App\Models\ReleaseSearchData;
use App\Models\SiteStat;
use App\Providers\SearchServiceProvider;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib, PHP 8.5 on Apple Silicon Darwin. See the commit message for the measured run; each run prints
 * its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and decoding dominate these sub-microsecond helpers, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default (`usesRustFfi() === false`).
 */
class RustSearchProviderFacadeFacetsParityTest extends TestCase
{
    private const CLASSES = [
        SearchServiceProvider::class,
        Elasticsearch::class,
        ReleaseSearchData::class,
        SiteStat::class,
        AdminLogFacetsRequest::class,
    ];

    /** Bytes that exercise trimming, case folding, separators, control bytes and invalid UTF-8. */
    private const ALPHABET = ['a', 'Z', '0', '9', '_', '-', '.', '+', ' ', "\t", "\n", "\0", "\x0B", "\x01", "\x7F", ':', '#', ',', '/', '*', '"', '|', "\xc3", "\x89", "\xff", 'E', 'r'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }
        (new ReflectionClass(SearchServiceProvider::class))->setStaticPropertyValue('ffi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse($class::usesRustFfi(), "{$class} must default to PHP");
            $class::setUseRustFfi(true);
            $this->assertTrue($class::usesRustFfi());
            $class::setUseRustFfi(false);
            $this->assertFalse($class::usesRustFfi());
            $class::setUseRustFfi(true);
            $class::setUseRustFfi(null);
            $this->assertFalse($class::usesRustFfi());
        }
    }

    public function test_facets_toggle_is_independent_of_inherited_boundary_toggle(): void
    {
        $inherited = AdminLogSearchRequest::usesRustFfi();
        try {
            AdminLogSearchRequest::setUseRustFfi(false);
            AdminLogFacetsRequest::setUseRustFfi(true);
            $this->assertFalse(AdminLogSearchRequest::usesRustFfi());
            $this->assertTrue(AdminLogFacetsRequest::usesRustFfi());
            AdminLogSearchRequest::setUseRustFfi(true);
            AdminLogFacetsRequest::setUseRustFfi(false);
            $this->assertTrue(AdminLogSearchRequest::usesRustFfi());
            $this->assertFalse(AdminLogFacetsRequest::usesRustFfi());
        } finally {
            AdminLogSearchRequest::setUseRustFfi($inherited);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function driverProvider(): array
    {
        return [
            'manticore' => ['manticore', 'manticore'],
            'elasticsearch' => ['elasticsearch', 'elasticsearch'],
            'trim and case' => [" ElasticSearch\n", 'elasticsearch'],
            'nul padding trimmed' => ["\0manticore\x0B", 'manticore'],
            'unknown alias' => ['es', 'manticore'],
            'blank' => ['', 'manticore'],
            'inner space' => ['elastic search', 'manticore'],
            'multibyte' => ["\xc3\x89lastic", 'manticore'],
        ];
    }

    #[DataProvider('driverProvider')]
    public function test_driver_alias_map_parity(string $driver, string $expected): void
    {
        $this->requireFfi();

        $map = ['default' => $expected, 'search' => $expected, 'search.manticore' => 'manticore', 'search.elasticsearch' => 'elasticsearch'];
        $this->assertSame($map, SearchServiceProvider::normalizeDriverAliasMapPhp($driver));
        $this->assertSame($map, SearchServiceProvider::normalizeDriverAliasMapNative($driver));
        SearchServiceProvider::setUseRustFfi(true);
        $this->assertSame($map, SearchServiceProvider::normalizeDriverAliasMap($driver));
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: array{index: string, valid: bool, operations: int, balanced: bool}}>
     */
    public static function indexProvider(): array
    {
        return [
            'releases' => [' Releases_RT ', 10, ['index' => 'releases_rt', 'valid' => true, 'operations' => 5, 'balanced' => true]],
            'odd body' => ['predb', 7, ['index' => 'predb', 'valid' => true, 'operations' => 3, 'balanced' => false]],
            'empty body' => ['predb', 0, ['index' => 'predb', 'valid' => true, 'operations' => 0, 'balanced' => true]],
            'negative body' => ['predb', -4, ['index' => 'predb', 'valid' => true, 'operations' => 0, 'balanced' => false]],
            'min int body' => ['x', PHP_INT_MIN, ['index' => 'x', 'valid' => true, 'operations' => 0, 'balanced' => false]],
            'max int body' => ['x', PHP_INT_MAX, ['index' => 'x', 'valid' => true, 'operations' => intdiv(PHP_INT_MAX, 2), 'balanced' => false]],
            'leading underscore' => ['_rel', 2, ['index' => '_rel', 'valid' => false, 'operations' => 1, 'balanced' => true]],
            'leading dash' => ['-rel', 2, ['index' => '-rel', 'valid' => false, 'operations' => 1, 'balanced' => true]],
            'leading plus' => ['+rel', 2, ['index' => '+rel', 'valid' => false, 'operations' => 1, 'balanced' => true]],
            'dot' => ['.', 0, ['index' => '.', 'valid' => false, 'operations' => 0, 'balanced' => true]],
            'dot dot' => ['..', 0, ['index' => '..', 'valid' => false, 'operations' => 0, 'balanced' => true]],
            'hidden index' => ['.kibana', 0, ['index' => '.kibana', 'valid' => true, 'operations' => 0, 'balanced' => true]],
            'inner space' => ['a b', 0, ['index' => 'a b', 'valid' => false, 'operations' => 0, 'balanced' => true]],
            'colon' => ['a:b', 0, ['index' => 'a:b', 'valid' => false, 'operations' => 0, 'balanced' => true]],
            'blank' => [" \t", 0, ['index' => '', 'valid' => false, 'operations' => 0, 'balanced' => true]],
            '255 bytes' => [str_repeat('a', 255), 0, ['index' => str_repeat('a', 255), 'valid' => true, 'operations' => 0, 'balanced' => true]],
            '256 bytes' => [str_repeat('a', 256), 0, ['index' => str_repeat('a', 256), 'valid' => false, 'operations' => 0, 'balanced' => true]],
            'multibyte kept' => ["\xc3\x89", 0, ['index' => "\xc3\x89", 'valid' => true, 'operations' => 0, 'balanced' => true]],
        ];
    }

    /**
     * @param  array{index: string, valid: bool, operations: int, balanced: bool}  $expected
     */
    #[DataProvider('indexProvider')]
    public function test_index_operation_params_parity(string $index, int $bodyCount, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, Elasticsearch::normalizeIndexOperationParamsPhp($index, $bodyCount));
        $this->assertSame($expected, Elasticsearch::normalizeIndexOperationParamsNative($index, $bodyCount));
        Elasticsearch::setUseRustFfi(true);
        $this->assertSame($expected, Elasticsearch::normalizeIndexOperationParams($index, $bodyCount));
    }

    public function test_search_document_fields_parity(): void
    {
        $this->requireFfi();

        $this->assertSame([
            'id' => 42,
            'name' => 'n',
            'searchname' => 'Some.Movie-2024',
            'plainsearchname' => 'Some Movie 2024',
            'fromname' => 'poster@example.test',
            'categories_id' => 2040,
            'indexable' => true,
        ], ReleaseSearchData::normalizeSearchDocumentFieldsNative(42, ' n ', " Some.Movie-2024\n", "\tposter@example.test", 2040));
        $this->assertSame(
            ['id' => 0, 'name' => '', 'searchname' => '', 'plainsearchname' => '', 'fromname' => '', 'categories_id' => 0, 'indexable' => false],
            ReleaseSearchData::normalizeSearchDocumentFieldsPhp(PHP_INT_MIN, '', ' ', '', -5),
        );
        $this->assertFalse(ReleaseSearchData::normalizeSearchDocumentFieldsNative(7, 'x', " \0", 'y', 1)['indexable']);

        mt_srand(3505);
        for ($i = 0; $i < 400; $i++) {
            $args = [self::randomInt(), self::randomBytes(12), self::randomBytes(24), self::randomBytes(12), self::randomInt()];
            $this->assertNotNull(SearchServiceProvider::evaluateNative(3, [(string) $args[0], $args[1], $args[2], $args[3], (string) $args[4]]));
            ReleaseSearchData::setUseRustFfi(true);
            $this->assertSame(ReleaseSearchData::normalizeSearchDocumentFieldsPhp(...$args), ReleaseSearchData::normalizeSearchDocumentFields(...$args), bin2hex(serialize($args)));
        }
    }

    /**
     * @return array<string, array{0: string, 1: int|float, 2: array{key: string, value: int|float, valid: bool}}>
     */
    public static function statProvider(): array
    {
        return [
            'plain' => ['releases', 12, ['key' => 'releases', 'value' => 12, 'valid' => true]],
            'snake case' => ['  Releases Added (24h) ', 3, ['key' => 'releases_added_24h', 'value' => 3, 'valid' => true]],
            'multibyte run' => ["__a\xc3\x89b__", -3, ['key' => 'a_b', 'value' => -3, 'valid' => true]],
            'existing underscores kept' => ['a__!b', 0, ['key' => 'a___b', 'value' => 0, 'valid' => true]],
            'dots replaced' => ['api.requests', PHP_INT_MAX, ['key' => 'api_requests', 'value' => PHP_INT_MAX, 'valid' => true]],
            'only symbols' => ['!!!', PHP_INT_MIN, ['key' => '', 'value' => PHP_INT_MIN, 'valid' => false]],
            'cut at 64' => [str_repeat('a', 70), 1, ['key' => str_repeat('a', 64), 'value' => 1, 'valid' => true]],
            'cut then trim' => [str_repeat('a', 63).'!b', 1, ['key' => str_repeat('a', 63), 'value' => 1, 'valid' => true]],
            'float' => ['ratio', 1.5, ['key' => 'ratio', 'value' => 1.5, 'valid' => true]],
            'tiny float' => ['ratio', 5.0E-324, ['key' => 'ratio', 'value' => 5.0E-324, 'valid' => true]],
            'nan' => ['ratio', NAN, ['key' => 'ratio', 'value' => 0.0, 'valid' => true]],
            'infinite' => ['ratio', -INF, ['key' => 'ratio', 'value' => 0.0, 'valid' => true]],
        ];
    }

    /**
     * @param  array{key: string, value: int|float, valid: bool}  $expected
     */
    #[DataProvider('statProvider')]
    public function test_stat_entry_parity(string $key, int|float $value, array $expected): void
    {
        $this->requireFfi();

        $php = SiteStat::normalizeStatEntryPhp($key, $value);
        $native = SiteStat::normalizeStatEntryNative($key, $value);
        $this->assertSame($expected, $php);
        $this->assertSame($expected, $native);
        SiteStat::setUseRustFfi(true);
        $this->assertSame($expected, SiteStat::normalizeStatEntry($key, $value));
    }

    public function test_stat_entry_float_bits_and_randomized_parity(): void
    {
        $this->requireFfi();

        $this->assertSame(pack('e', 0.0), pack('e', SiteStat::normalizeStatEntryPhp('x', -0.0)['value']));
        $this->assertSame(pack('e', 0.0), pack('e', SiteStat::normalizeStatEntryNative('x', -0.0)['value']));

        mt_srand(4505);
        for ($i = 0; $i < 400; $i++) {
            $key = self::randomBytes(80);
            $value = match (mt_rand(0, 3)) {
                0 => self::randomInt(),
                1 => (mt_rand() / mt_getrandmax() - 0.5) * 10 ** mt_rand(-300, 300),
                2 => unpack('e', random_bytes(8))[1],
                default => [NAN, INF, -INF, -0.0, PHP_FLOAT_MAX, PHP_FLOAT_MIN][mt_rand(0, 5)],
            };
            $tag = \is_float($value) ? 'f' : 'i';
            $this->assertNotNull(SearchServiceProvider::evaluateNative(4, [$key, $tag, $tag === 'f' ? pack('e', $value) : (string) $value]));
            $php = SiteStat::normalizeStatEntryPhp($key, $value);
            $native = SiteStat::normalizeStatEntryNative($key, $value);
            $context = bin2hex($key).' '.(\is_float($value) ? bin2hex(pack('e', $value)) : (string) $value);
            $this->assertSame([$php['key'], $php['valid']], [$native['key'], $native['valid']], $context);
            $this->assertSame(\is_float($php['value']) ? pack('e', $php['value']) : $php['value'], \is_float($native['value']) ? pack('e', $native['value']) : $native['value'], $context);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: array{term: ?string, term_valid: bool, level: ?string, channel: ?string}}>
     */
    public static function facetProvider(): array
    {
        return [
            'all set' => ['  timeout ', ' ERROR ', ' queue.default ', ['term' => 'timeout', 'term_valid' => true, 'level' => 'error', 'channel' => 'queue.default']],
            'all null' => [null, null, null, ['term' => null, 'term_valid' => true, 'level' => null, 'channel' => null]],
            'blank values' => [" \t", '', '  ', ['term' => null, 'term_valid' => true, 'level' => null, 'channel' => null]],
            'unknown level' => ['ab', 'fatal', 'bad channel', ['term' => 'ab', 'term_valid' => true, 'level' => null, 'channel' => null]],
            'control byte term' => ["a\x01b", 'EMERGENCY', 'local-1_x', ['term' => "a\x01b", 'term_valid' => false, 'level' => 'emergency', 'channel' => 'local-1_x']],
            'delete byte term' => ["x\x7F", 'debug', str_repeat('c', 64), ['term' => "x\x7F", 'term_valid' => false, 'level' => 'debug', 'channel' => str_repeat('c', 64)]],
            'long channel' => ['x', 'info', str_repeat('c', 65), ['term' => 'x', 'term_valid' => true, 'level' => 'info', 'channel' => null]],
            'trailing newline trimmed' => ["q\n", "notice\n", "app\n", ['term' => 'q', 'term_valid' => true, 'level' => 'notice', 'channel' => 'app']],
            'inner newline' => ["a\nb", 'warn', "a\nb", ['term' => "a\nb", 'term_valid' => false, 'level' => null, 'channel' => null]],
            'multibyte' => ["\xc3\x89t\xc3\xa9", 'critical', "\xc3\x89", ['term' => "\xc3\x89t\xc3\xa9", 'term_valid' => true, 'level' => 'critical', 'channel' => null]],
        ];
    }

    /**
     * @param  array{term: ?string, term_valid: bool, level: ?string, channel: ?string}  $expected
     */
    #[DataProvider('facetProvider')]
    public function test_facet_filter_input_parity(?string $term, ?string $level, ?string $channel, array $expected): void
    {
        $this->requireFfi();

        $this->assertSame($expected, AdminLogFacetsRequest::normalizeFacetFilterInputPhp($term, $level, $channel));
        $this->assertSame($expected, AdminLogFacetsRequest::normalizeFacetFilterInputNative($term, $level, $channel));
        AdminLogFacetsRequest::setUseRustFfi(true);
        $this->assertSame($expected, AdminLogFacetsRequest::normalizeFacetFilterInput($term, $level, $channel));
    }

    public function test_randomized_driver_index_and_facet_parity(): void
    {
        $this->requireFfi();

        mt_srand(5505);
        $nullable = static fn (string $value): ?string => mt_rand(0, 4) === 0 ? null : $value;
        for ($i = 0; $i < 400; $i++) {
            $driver = mt_rand(0, 3) === 0 ? (mt_rand(0, 1) === 1 ? ' ElasticSearch ' : "manticore\n") : self::randomBytes(14);
            $this->assertNotNull(SearchServiceProvider::evaluateNative(1, [$driver]));
            $this->assertSame(SearchServiceProvider::normalizeDriverAliasMapPhp($driver), SearchServiceProvider::normalizeDriverAliasMapNative($driver), bin2hex($driver));

            [$index, $body] = [self::randomBytes(20), self::randomInt()];
            $this->assertNotNull(SearchServiceProvider::evaluateNative(2, [$index, (string) $body]));
            $this->assertSame(Elasticsearch::normalizeIndexOperationParamsPhp($index, $body), Elasticsearch::normalizeIndexOperationParamsNative($index, $body), bin2hex($index)." {$body}");

            $level = mt_rand(0, 2) === 0 ? ' '.strtoupper(['debug', 'info', 'error', 'alert'][mt_rand(0, 3)])."\t" : self::randomBytes(8);
            $args = [$nullable(self::randomBytes(20)), $nullable($level), $nullable(self::randomBytes(10))];
            $this->assertNotNull(SearchServiceProvider::evaluateNative(5, [
                $args[0] === null ? '0' : '1', $args[0] ?? '', $args[1] === null ? '0' : '1', $args[1] ?? '', $args[2] === null ? '0' : '1', $args[2] ?? '',
            ]));
            $this->assertSame(AdminLogFacetsRequest::normalizeFacetFilterInputPhp(...$args), AdminLogFacetsRequest::normalizeFacetFilterInputNative(...$args), bin2hex(serialize($args)));
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['default', 'elasticsearch', 'search', 'elasticsearch', 'search.manticore', 'manticore', 'search.elasticsearch', 'elasticsearch'], SearchServiceProvider::evaluateNative(1, [' ELASTICSEARCH ']));
        $this->assertSame(['releases_rt', '1', '2', '1'], SearchServiceProvider::evaluateNative(2, ['Releases_RT', '4']));
        $this->assertSame(['1', 'n', 'a.b', 'a b', 'f', '0', '1'], SearchServiceProvider::evaluateNative(3, ['1', 'n', ' a.b ', 'f', '-2']));
        $this->assertSame(['a_b', '1', 'i', '5'], SearchServiceProvider::evaluateNative(4, ['A B', 'i', '5']));
        $this->assertSame(['x', '1', 'f', pack('e', 0.0)], SearchServiceProvider::evaluateNative(4, ['x', 'f', pack('e', NAN)]));
        $this->assertSame(['1', 'ab', '1', '1', 'info', '0', ''], SearchServiceProvider::evaluateNative(5, ['1', ' ab ', '1', 'INFO', '1', '!']));
        $this->assertNull(SearchServiceProvider::evaluateNative(-1, ['x']));
        $this->assertNull(SearchServiceProvider::evaluateNative(6, ['x']));
        $this->assertNull(SearchServiceProvider::evaluateNative(1, array_fill(0, 65, 'x')));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        (new ReflectionClass(SearchServiceProvider::class))->setStaticPropertyValue('ffi', false);

        $this->assertNull(SearchServiceProvider::evaluateNative(1, ['manticore']));
        $this->assertSame(SearchServiceProvider::normalizeDriverAliasMapPhp(' ElasticSearch '), SearchServiceProvider::normalizeDriverAliasMapNative(' ElasticSearch '));
        $this->assertSame(Elasticsearch::normalizeIndexOperationParamsPhp('_x', 3), Elasticsearch::normalizeIndexOperationParamsNative('_x', 3));
        $this->assertSame(ReleaseSearchData::normalizeSearchDocumentFieldsPhp(1, 'a', 'b.c', 'd', 2), ReleaseSearchData::normalizeSearchDocumentFieldsNative(1, 'a', 'b.c', 'd', 2));
        $this->assertSame(['key' => 'x', 'value' => 0.0, 'valid' => true], SiteStat::normalizeStatEntryNative('X', NAN));
        $this->assertSame(['key' => 'x_y', 'value' => 9, 'valid' => true], SiteStat::normalizeStatEntryNative('x y', 9));
        $this->assertSame(['term' => 'ab', 'term_valid' => true, 'level' => 'info', 'channel' => null], AdminLogFacetsRequest::normalizeFacetFilterInputNative(' ab ', 'INFO', '!'));
        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(true);
        }
        $this->assertSame('elasticsearch', SearchServiceProvider::normalizeDriverAliasMap('elasticsearch')['default']);
        $this->assertTrue(Elasticsearch::normalizeIndexOperationParams('releases', 2)['valid']);

        $missing = RustPipelineLibrary::resolveDomain('search', SearchServiceProvider::FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_malformed_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['manticore']);
        $buf = $ffi->new('char[256]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w35_search_provider_facade_facets($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(1, $valid, strlen($valid), $buf, 256);
        $this->assertGreaterThan(8, $written);
        $this->assertSame(self::packet(['default', 'manticore', 'search', 'manticore', 'search.manticore', 'manticore', 'search.elasticsearch', 'elasticsearch']), FFI::string($buf, $written));
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), null, 0));
        $this->assertSame(-1, $call(1, null, 4, $buf, 256));
        $this->assertSame(-1, $call(1, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(0, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(6, $valid, strlen($valid), $small, 8));
        $this->assertSame(-1, $call(1, '', 0, $small, 8));
        $this->assertSame(-1, $call(1, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(1, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(1, $valid."\0", strlen($valid) + 1, $small, 8));
        $this->assertSame(-1, $call(1, "\x41\0\0\0".str_repeat("\0", 260), 264, $small, 8));
        $this->assertSame(-1, $callParts(1, []));
        $this->assertSame(-1, $callParts(1, ['a', 'b']));
        $this->assertSame(-1, $callParts(2, ['x', '+1']));
        $this->assertSame(-1, $callParts(2, ['x', '01']));
        $this->assertSame(-1, $callParts(2, ['x']));
        $this->assertSame(-1, $callParts(3, ['1', '', '', '']));
        $this->assertSame(-1, $callParts(3, ['1.0', '', '', '', '0']));
        $this->assertSame(-1, $callParts(4, ['x', 'f', '1234567']));
        $this->assertSame(-1, $callParts(4, ['x', 's', '1']));
        $this->assertSame(-1, $callParts(4, ['x', 'i', '1e3']));
        $this->assertSame(-1, $callParts(5, ['0', 'x', '0', '', '0', '']));
        $this->assertSame(-1, $callParts(5, ['2', '', '0', '', '0', '']));
        $this->assertSame(-1, $callParts(5, ['0', '', '0', '']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $common = static function (): void {
            SearchServiceProvider::normalizeDriverAliasMap('manticore');
            Elasticsearch::normalizeIndexOperationParams('releases_rt', 1000);
            ReleaseSearchData::normalizeSearchDocumentFields(123456, 'Some.Movie.2024.1080p', 'Some.Movie.2024.1080p-GRP', 'poster@example.test', 2040);
            SiteStat::normalizeStatEntry('releases_added', 4521);
            AdminLogFacetsRequest::normalizeFacetFilterInput('timeout', 'error', 'production');
        };
        $longName = str_repeat('Long.Release.Name-', 30);
        $uncommon = static function () use ($longName): void {
            SearchServiceProvider::normalizeDriverAliasMap(" \tElastic Search\n");
            Elasticsearch::normalizeIndexOperationParams(' _Invalid:Index '.str_repeat('x', 300), -7);
            ReleaseSearchData::normalizeSearchDocumentFields(-1, " {$longName} ", " {$longName}\n", "\0", PHP_INT_MIN);
            SiteStat::normalizeStatEntry(" Résumé Downloads (Last 24h) !!! \xff".str_repeat('-x', 40), NAN);
            AdminLogFacetsRequest::normalizeFacetFilterInput(" a\x01b ", ' FATAL ', 'bad channel name');
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::setUseRustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => $common, 'uncommon' => $uncommon] as $name => $workload) {
            for ($w = 0; $w < 20; $w++) {
                foreach ([false, true] as $rust) {
                    $setAll($rust);
                    $workload();
                }
            }

            $times = ['php' => [], 'rust' => []];
            for ($s = 0; $s < 300; $s++) {
                $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                foreach ($order as $rust) {
                    $setAll($rust);
                    $t0 = hrtime(true);
                    for ($i = 0; $i < 20; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / 20_000;
                }
            }

            $results[$name] = [
                'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                'php_mean_us' => self::mean($times['php']),
                'rust_mean_us' => self::mean($times['rust']),
                'php_p99_us' => self::p99($times['php']),
                'rust_p99_us' => self::p99($times['rust']),
            ];
        }

        foreach (self::CLASSES as $class) {
            $class::setUseRustFfi(null);
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 35.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
            $results['common']['mean'],
            $results['common']['php_mean_us'],
            $results['common']['rust_mean_us'],
            $results['common']['p99'],
            $results['common']['php_p99_us'],
            $results['common']['rust_p99_us'],
            $results['uncommon']['mean'],
            $results['uncommon']['php_mean_us'],
            $results['uncommon']['rust_mean_us'],
            $results['uncommon']['p99'],
            $results['uncommon']['php_p99_us'],
            $results['uncommon']['rust_p99_us'],
        ));

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesRustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesRustFfi());
            }
        }
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
            1 => [PHP_INT_MIN, PHP_INT_MAX, 0, -1, 1][mt_rand(0, 4)],
            default => mt_rand(-100, 100_000),
        };
    }

    /**
     * @param  list<string>  $parts
     */
    private static function packet(array $parts): string
    {
        $packet = pack('V', count($parts));
        foreach ($parts as $part) {
            $packet .= pack('V', strlen($part)).$part;
        }

        return $packet;
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): float
    {
        return array_sum($values) / max(1, count($values));
    }

    /**
     * @param  list<float>  $values
     */
    private static function p99(array $values): float
    {
        sort($values);

        return $values[(int) min(count($values) - 1, (int) ceil(0.99 * count($values)) - 1)];
    }

    private function requireFfi(): FFI
    {
        $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
        $ffi = RustPipelineLibrary::resolveDomain('search', SearchServiceProvider::FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
