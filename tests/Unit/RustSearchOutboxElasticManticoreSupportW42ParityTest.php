<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\SearchIndexOutboxDispatcher;
use App\Services\Search\Support\ElasticsearchClientFactory;
use App\Services\Search\Support\ElasticsearchResponseHelper;
use App\Services\Search\Support\ManticoreClientFactory;
use App\Services\Search\Support\ManticoreIndexRegistry;
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
 * Marshaling and FFI scratch access dominate these summaries, so every helper misses the 1.5x gate
 * and keeps PHP as the default.
 */
class RustSearchOutboxElasticManticoreSupportW42ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        SearchIndexOutboxDispatcher::class,
        ElasticsearchClientFactory::class,
        ElasticsearchResponseHelper::class,
        ManticoreClientFactory::class,
        ManticoreIndexRegistry::class,
    ];

    private const RANDOM_ROUNDS = 2500;

    private const ALPHABET = ['a', 'S', 'c', '0', '1', '9', '-', ' ', "\t", "\0", ',', '/', '\\', ':', "\xc3", "\xa9", "\xff", '_'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW425RustFfi();
        }
        (new ReflectionClass(ElasticsearchResponseHelper::class))->setStaticPropertyValue('w425Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $default = (new ReflectionClassConstant($class, 'W425_RUST_FFI_DEFAULT'))->getValue();
            $this->assertIsBool($default);
            $this->assertSame($default, $class::usesW425RustFfi(), "{$class} must start at its default");
            $class::useW425RustFfi(true);
            $this->assertTrue($class::usesW425RustFfi());
            $class::useW425RustFfi(false);
            $this->assertFalse($class::usesW425RustFfi());
            $class::useW425RustFfi();
            $this->assertTrue($class::usesW425RustFfi());
            $class::resetW425RustFfi();
            $this->assertSame($default, $class::usesW425RustFfi());
        }

        // Earlier toggles are independent of the Wave 42.5 ones.
        $dispatcher = (new ReflectionClass(SearchIndexOutboxDispatcher::class))->getStaticPropertyValue('useRustFfi');
        $responseHelper = ElasticsearchResponseHelper::$useRustFfi;
        $manticore = ManticoreClientFactory::usesRustFfi();
        foreach (self::CLASSES as $class) {
            $class::useW425RustFfi(! $class::usesW425RustFfi());
        }
        $this->assertSame($dispatcher, (new ReflectionClass(SearchIndexOutboxDispatcher::class))->getStaticPropertyValue('useRustFfi'));
        $this->assertSame($responseHelper, ElasticsearchResponseHelper::$useRustFfi);
        $this->assertSame($manticore, ManticoreClientFactory::usesRustFfi());

        // Toggling one class leaves the others alone.
        foreach (self::CLASSES as $class) {
            $class::useW425RustFfi(false);
        }
        ManticoreClientFactory::useW425RustFfi(true);
        $this->assertTrue(ManticoreClientFactory::usesW425RustFfi());
        $this->assertFalse(ElasticsearchResponseHelper::usesW425RustFfi());
        $this->assertFalse(ManticoreIndexRegistry::usesW425RustFfi());
    }

    public function test_outbox_batch_examples(): void
    {
        $row = static fn (array $fields): object => (object) $fields;
        $rows = [
            $row(['id' => 10, 'entity_type' => 'releases', 'action' => 'Created', 'entity_id' => 5]),
            $row(['id' => '11', 'entity_type' => 'App\\Models\\Release', 'action' => 'update', 'entity_id' => '5']),
            $row(['id' => 12, 'entity_type' => 'predb', 'action' => 'delete', 'entity_id' => 0]),
            'not a row',
            $row(['id' => -3, 'entity_type' => 'movie_info', 'action' => 'saved', 'entity_id' => 7]),
            $row(['id' => 9, 'entity_type' => 'tv-show', 'action' => 'bogus', 'entity_id' => 8]),
            $row(['id' => 1, 'entity_type' => 'secondary', 'action' => 'remove', 'entity_id' => 9]),
            $row(['id' => 2, 'entity_type' => 'MusicInfo', 'action' => 'replace', 'entity_id' => 9]),
            $row(['id' => 3, 'entity_type' => 'widgets', 'action' => 'insert', 'entity_id' => 9]),
            $row(['id' => 4, 'entity_type' => ['x'], 'action' => 'insert', 'entity_id' => 9]),
            $row(['entity_type' => null, 'action' => null]),
        ];
        $this->assertSame(
            ['rows' => 11, 'invalid' => 2, 'missing_entity_id' => 2, 'inserts' => 2, 'upserts' => 2, 'updates' => 1, 'deletes' => 1, 'unknown_actions' => 1, 'releases' => 2, 'predb' => 0, 'movies' => 1, 'tvshows' => 1, 'secondary' => 2, 'other_types' => 1, 'distinct_documents' => 6, 'max_row_id' => 12],
            $this->both(SearchIndexOutboxDispatcher::class, static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch($rows)),
        );
        $this->assertSame(
            ['rows' => 0, 'invalid' => 0, 'missing_entity_id' => 0, 'inserts' => 0, 'upserts' => 0, 'updates' => 0, 'deletes' => 0, 'unknown_actions' => 0, 'releases' => 0, 'predb' => 0, 'movies' => 0, 'tvshows' => 0, 'secondary' => 0, 'other_types' => 0, 'distinct_documents' => 0, 'max_row_id' => 0],
            $this->both(SearchIndexOutboxDispatcher::class, static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch([])),
        );

        // Every secondary alias and the dispatcher's own normalizers agree with the summary.
        foreach (['Music', 'book-info', 'Games Info', 'App\\Models\\SteamApp', 'anidb_titles', 'console'] as $type) {
            $summary = $this->both(SearchIndexOutboxDispatcher::class, static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch([$row(['entity_type' => $type, 'action' => 'x', 'entity_id' => 1])]));
            $this->assertSame(1, $summary['secondary'], $type);
        }
        foreach (['Inserted', 'save', 'INDEXED', 'Updated', 'des-troyed', 'remove', 'nope'] as $action) {
            $summary = SearchIndexOutboxDispatcher::summarizeOutboxBatch([$row(['entity_type' => 'release', 'action' => $action, 'entity_id' => 1])]);
            $expected = match (SearchIndexOutboxDispatcher::normalizeActionPhp($action)) {
                'insert' => 'inserts',
                'upsert' => 'upserts',
                'update' => 'updates',
                'delete' => 'deletes',
                default => 'unknown_actions',
            };
            $this->assertSame(1, $summary[$expected], $action);
        }
    }

    public function test_host_config_batch_examples(): void
    {
        $hosts = [
            ' https://es:9200 ',
            " \t",
            42,
            ['host' => ' es1 ', 'scheme' => ' HTTPS ', 'port' => 9200, 'user' => 'u', 'pass' => 'p'],
            ['host' => 'es1', 'scheme' => '0'],
            ['host' => '', 'scheme' => 'https', 'port' => 1],
            ['host' => 'es2', 'port' => ''],
            ['host' => ['x']],
            ['host' => 'es3', 'user' => 'u', 'pass' => '0'],
            'http://plain',
        ];
        $this->assertSame(
            ['entries' => 10, 'string_hosts' => 2, 'structured_hosts' => 4, 'skipped' => 4, 'https' => 2, 'with_port' => 1, 'with_credentials' => 1, 'distinct_hostnames' => 3],
            $this->both(ElasticsearchClientFactory::class, static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch($hosts)),
        );
        $this->assertSame(
            ['entries' => 0, 'string_hosts' => 0, 'structured_hosts' => 0, 'skipped' => 0, 'https' => 0, 'with_port' => 0, 'with_credentials' => 0, 'distinct_hostnames' => 0],
            $this->both(ElasticsearchClientFactory::class, static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch([])),
        );

        // Accepted hosts match the URLs `buildHostsPhp()` assembles.
        $scalarOnly = array_values(array_filter($hosts, static fn (mixed $host): bool => ! is_array($host) || ! is_array($host['host'])));
        $summary = ElasticsearchClientFactory::summarizeHostConfigBatch($scalarOnly);
        $built = ElasticsearchClientFactory::buildHostsPhp(array_values(array_filter($scalarOnly, static fn (mixed $host): bool => is_string($host) || is_array($host))));
        $this->assertSame(count($built), $summary['string_hosts'] + $summary['structured_hosts']);
        $this->assertSame(['HTTPS://u:p@es1:9200', 'http://es1', 'http://es2', 'http://es3'], array_slice($built, 1, 4));
    }

    public function test_search_response_batch_examples(): void
    {
        $responses = [
            ['timed_out' => false, '_shards' => ['failed' => 0], 'hits' => ['total' => ['value' => 10, 'relation' => 'eq'], 'hits' => [['_id' => 'a'], ['_id' => 'b']]]],
            'oops',
            ['timed_out' => true, '_shards' => ['failed' => 2], 'hits' => ['total' => 5, 'hits' => []]],
            ['_shards' => ['failed' => 1], 'hits' => ['total' => ['value' => 7], 'hits' => [['_id' => 'b'], ['_id' => 7], ['_id' => null], 'x']]],
            ['timed_out' => 'true', '_shards' => 'bad', 'hits' => 'bad'],
            [],
        ];
        $this->assertSame(
            ['responses' => 6, 'invalid' => 1, 'timed_out' => 1, 'with_shard_failures' => 2, 'failed_shards' => 3, 'total_hits' => 22, 'returned_hits' => 6, 'max_total' => 10, 'empty_responses' => 3, 'distinct_ids' => 3],
            $this->both(ElasticsearchResponseHelper::class, static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($responses)),
        );
        $this->assertSame(
            ['responses' => 0, 'invalid' => 0, 'timed_out' => 0, 'with_shard_failures' => 0, 'failed_shards' => 0, 'total_hits' => 0, 'returned_hits' => 0, 'max_total' => 0, 'empty_responses' => 0, 'distinct_ids' => 0],
            $this->both(ElasticsearchResponseHelper::class, static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch([])),
        );
        $negative = [['_shards' => ['failed' => -4], 'hits' => ['total' => -9, 'hits' => [['_id' => 1.5]]]]];
        $this->assertSame(
            ['responses' => 1, 'invalid' => 0, 'timed_out' => 0, 'with_shard_failures' => 0, 'failed_shards' => 0, 'total_hits' => 0, 'returned_hits' => 1, 'max_total' => 0, 'empty_responses' => 0, 'distinct_ids' => 0],
            $this->both(ElasticsearchResponseHelper::class, static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($negative)),
        );
    }

    public function test_host_list_batch_examples(): void
    {
        $lists = [
            ' a:9308, ,0, https://b ,a:9308,',
            42,
            '  ',
            ['HTTPS://c', ['host' => 'd'], 0, '', '0', null, 5],
            [],
            ['a:9308'],
        ];
        $this->assertSame(
            ['lists' => 6, 'invalid' => 1, 'empty_lists' => 2, 'hosts' => 7, 'structured_hosts' => 1, 'https_hosts' => 2, 'distinct_hosts' => 3, 'max_hosts_per_list' => 3],
            $this->both(ManticoreClientFactory::class, static fn () => ManticoreClientFactory::summarizeHostListBatch($lists)),
        );
        $this->assertSame(
            ['lists' => 0, 'invalid' => 0, 'empty_lists' => 0, 'hosts' => 0, 'structured_hosts' => 0, 'https_hosts' => 0, 'distinct_hosts' => 0, 'max_hosts_per_list' => 0],
            $this->both(ManticoreClientFactory::class, static fn () => ManticoreClientFactory::summarizeHostListBatch([])),
        );
        foreach (['a,b', ' , ', "x\0,\x0By", '0,00'] as $list) {
            $this->assertSame(count(ManticoreClientFactory::normalizeHostsPhp($list)), $this->both(ManticoreClientFactory::class, static fn () => ManticoreClientFactory::summarizeHostListBatch([$list]))['hosts']);
        }
    }

    public function test_table_name_batch_examples(): void
    {
        $configured = ['releases' => 'my_releases', 'other' => 'my_releases', 'movies' => 'movies_rt', 'bad' => 5];
        $tables = ['my_releases', 'releases_rt', 'movies_rt', 'predb_x', 7, 'RELEASES_RT', 'music_rt', 'nope_rt'];
        $this->assertSame(
            ['tables' => 8, 'invalid' => 1, 'configured' => 2, 'defaults' => 2, 'unresolved' => 3, 'distinct_logical' => 3],
            $this->both(ManticoreIndexRegistry::class, static fn () => ManticoreIndexRegistry::summarizeTableNameBatch($tables, $configured)),
        );
        $this->assertSame(
            ['tables' => 0, 'invalid' => 0, 'configured' => 0, 'defaults' => 0, 'unresolved' => 0, 'distinct_logical' => 0],
            $this->both(ManticoreIndexRegistry::class, static fn () => ManticoreIndexRegistry::summarizeTableNameBatch([])),
        );

        // Resolution agrees with `logicalNamePhp()` table by table.
        $stringConfigured = array_filter($configured, 'is_string');
        foreach ($tables as $table) {
            if (! is_string($table)) {
                continue;
            }
            $summary = ManticoreIndexRegistry::summarizeTableNameBatch([$table], $configured);
            $this->assertSame(ManticoreIndexRegistry::logicalNamePhp($table, $stringConfigured) === null ? 1 : 0, $summary['unresolved'], $table);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['1', '0', '0', '1', '0', '0', '0', '0', '1', '0', '0', '0', '0', '0', '1', '3'], ElasticsearchResponseHelper::evaluateW425Native(1, ['1', 'Release', 'create', '4', '3']));
        $this->assertSame(['1', '1', '0', '0', '1', '0', '0', '0'], ElasticsearchResponseHelper::evaluateW425Native(2, ['1', ' https://x ', '', '0', '0']));
        $this->assertSame(['1', '0', '0', '0', '0', '4', '1', '4', '0', '1'], ElasticsearchResponseHelper::evaluateW425Native(3, ['1', '0', '0', '4', '1', '1', 'id']));
        $this->assertSame(['1', '0', '0', '2', '0', '0', '2', '2'], ElasticsearchResponseHelper::evaluateW425Native(4, ['1', 'a,b']));
        $this->assertSame(['1', '0', '0', '1', '0', '1'], ElasticsearchResponseHelper::evaluateW425Native(5, ['0', '1', 'x', 'x_rt', '1', 'x_rt']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(-1, ['x']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(0, []));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(6, []));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(1, ['1', "caf\xc3\xa9", 'x', '1', '1']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(1, ['1', 'r', 'x', '+1', '1']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(2, ['3', '', '', '0', '0']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(3, ['1', '0', '0', '1', '1', '2', 'a']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(4, ['2', '1', 's0']));
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(5, ['0']));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useW425RustFfi(true);
        }
        (new ReflectionClass(ElasticsearchResponseHelper::class))->setStaticPropertyValue('w425Ffi', false);

        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(1, []));
        $this->assertSame(1, SearchIndexOutboxDispatcher::summarizeOutboxBatch([(object) ['entity_type' => 'release', 'action' => 'delete', 'entity_id' => 1]])['deletes']);
        $this->assertSame(1, ElasticsearchClientFactory::summarizeHostConfigBatch([['host' => 'es', 'scheme' => 'https']])['https']);
        $this->assertSame(1, ElasticsearchResponseHelper::summarizeSearchResponseBatch([['timed_out' => true]])['timed_out']);
        $this->assertSame(2, ManticoreClientFactory::summarizeHostListBatch(['a,b'])['hosts']);
        $this->assertSame(1, ManticoreIndexRegistry::summarizeTableNameBatch(['predb_rt'])['defaults']);

        $missing = RustPipelineLibrary::resolveDomain('search_w425', ElasticsearchResponseHelper::W425_FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_malformed_inputs_fall_back_to_php(): void
    {
        $this->requireFfi();

        // Rust declines non-ASCII entity types (PHP `basename()` territory) and overflowing sums.
        $rows = [(object) ['entity_type' => "Caf\xc3\xa9/Release", 'action' => 'insert', 'entity_id' => 1], (object) ['entity_type' => "\xff", 'action' => "\xc3\xa9", 'entity_id' => 2]];
        $this->assertNull(ElasticsearchResponseHelper::evaluateW425Native(1, ['1', "\xff", 'x', '1', '1']));
        $this->assertSame(SearchIndexOutboxDispatcher::summarizeOutboxBatchPhp($rows), $this->both(SearchIndexOutboxDispatcher::class, static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch($rows)));
        $this->assertSame([1, 1], [SearchIndexOutboxDispatcher::summarizeOutboxBatchPhp($rows)['releases'], SearchIndexOutboxDispatcher::summarizeOutboxBatchPhp($rows)['other_types']]);

        $huge = [['hits' => ['total' => PHP_INT_MAX]], ['hits' => ['total' => PHP_INT_MAX]]];
        $this->assertSame(
            ElasticsearchResponseHelper::summarizeSearchResponseBatchPhp($huge),
            $this->both(ElasticsearchResponseHelper::class, static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($huge)),
        );

        $objects = [new stdClass, static fn (): int => 1, INF, -0.0];
        $this->assertSame(['lists' => 4, 'invalid' => 4, 'empty_lists' => 0, 'hosts' => 0, 'structured_hosts' => 0, 'https_hosts' => 0, 'distinct_hosts' => 0, 'max_hosts_per_list' => 0], $this->both(ManticoreClientFactory::class, static fn () => ManticoreClientFactory::summarizeHostListBatch($objects)));
        $this->assertSame(['entries' => 4, 'string_hosts' => 0, 'structured_hosts' => 0, 'skipped' => 4, 'https' => 0, 'with_port' => 0, 'with_credentials' => 0, 'distinct_hostnames' => 0], $this->both(ElasticsearchClientFactory::class, static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch($objects)));
        $this->assertSame(['tables' => 4, 'invalid' => 4, 'configured' => 0, 'defaults' => 0, 'unresolved' => 0, 'distinct_logical' => 0], $this->both(ManticoreIndexRegistry::class, static fn () => ManticoreIndexRegistry::summarizeTableNameBatch($objects, [1 => 'x', 'y' => null])));
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['1', 'a,b', '0']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w425_search_outbox_elastic_manticore_support($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(4, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = ElasticsearchResponseHelper::evaluateW425Native(4, ['1', 'a,b', '0']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Short capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(4, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(4, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(4, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Overlapping input/output buffers decline without writing.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, $valid, strlen($valid));
        $this->assertSame(-1, (int) $ffi->nntmux_w425_search_outbox_elastic_manticore_support(4, $shared, strlen($valid), $shared, 256)); // @phpstan-ignore method.notFound
        $this->assertSame($valid, FFI::string($shared, strlen($valid)));

        // Null pointers with non-zero lengths, oversized input, malformed packets and unknown modes.
        $this->assertSame(-1, $call(4, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(4, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(4, $valid, 16 * 1024 * 1024 + 1, $small, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(4, '', 0, $small, 8));
        $this->assertSame(-1, $call(4, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(4, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(4, $valid."\0", strlen($valid) + 1, $small, 8));

        // Non-canonical integers, bad flags and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['1', 'r', 'x', '1']));
        $this->assertSame(-1, $callParts(1, ['2', 'r', 'x', '1', '1']));
        $this->assertSame(-1, $callParts(1, ['1', 'r', 'x', '01', '1']));
        $this->assertSame(-1, $callParts(2, ['1', 'x', '', '2', '0']));
        $this->assertSame(-1, $callParts(3, ['1', '0', '-1', '1', '1', '0']));
        $this->assertSame(-1, $callParts(3, ['1', '0', '0', (string) PHP_INT_MAX, '0', '0', '1', '0', '0', '1', '0', '0']));
        $this->assertSame(-1, $callParts(3, ['1', '0', '0', '1']));
        $this->assertSame(-1, $callParts(4, ['2', '2', 'sa']));
        $this->assertSame(-1, $callParts(4, ['2', '1', 'ax']));
        $this->assertSame(-1, $callParts(4, ['3']));
        $this->assertSame(-1, $callParts(5, ['1', 'a']));
        $this->assertSame(-1, $callParts(5, ['0', '0', '2', 'x']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(4205);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $scalar = static fn (): mixed => match (mt_rand(0, 7)) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => self::randomInt(),
            3 => mt_rand(0, 7) / 4,
            4 => mt_rand(0, 1) === 0 ? [] : ['nested' => mt_rand(0, 2)],
            default => self::randomBytes(8),
        };
        $types = ['release', 'Releases', 'App\\Models\\Release', 'pre', 'PreDB', 'movie_info', 'movie-info', 'TV Shows', 'tv', 'video', 'secondary', 'Secondary Search', 'music', 'MusicInfo', 'steam-apps', 'anidb_titles', 'widgets', 'a/b//', '//', '', 'other'];
        $actions = ['insert', 'Created', 'upsert', 'saved', 'INDEX', 'update', 'Updated', 'delete', 'des-troyed', 'remove', 'removed', 'replace', 'bogus', '', ' insert'];
        $urls = ['es:9200', ' https://es ', 'HTTPS://x', 'http://y', '', ' ', "\t\n", '0', 'https:/', 'a:9308'];

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $rows = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $rows[] = $scalar();

                    continue;
                }
                $row = new stdClass;
                if (mt_rand(0, 6) !== 0) {
                    $row->id = mt_rand(0, 5) === 0 ? $scalar() : (mt_rand(0, 3) === 0 ? (string) mt_rand(-5, 50) : mt_rand(-5, 50));
                }
                if (mt_rand(0, 8) !== 0) {
                    $row->entity_type = match (mt_rand(0, 6)) {
                        0 => $scalar(),
                        1 => self::randomBytes(10),
                        default => $pick($types),
                    };
                }
                if (mt_rand(0, 8) !== 0) {
                    $row->action = mt_rand(0, 5) === 0 ? $scalar() : $pick($actions);
                }
                if (mt_rand(0, 6) !== 0) {
                    $row->entity_id = match (mt_rand(0, 5)) {
                        0 => $scalar(),
                        1 => $pick(['1', '1.5', '1e2', ' 3', 'x', '-2', '0']),
                        default => mt_rand(-1, 12),
                    };
                }
                $rows[] = $row;
            }
            $this->assertParity(SearchIndexOutboxDispatcher::class, static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch($rows), [$rows]);

            $hosts = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 4) === 0) {
                    $hosts[] = mt_rand(0, 2) === 0 ? $scalar() : $pick($urls);

                    continue;
                }
                $host = [];
                foreach (['host', 'scheme', 'port', 'user', 'pass'] as $key) {
                    if (mt_rand(0, 3) !== 0) {
                        $host[$key] = match (mt_rand(0, 5)) {
                            0 => $scalar(),
                            1 => $pick(['', ' ', '0', 'https', ' HTTPS ', 'http', 'es1', ' es1', 'es2', "\0"]),
                            2 => self::randomBytes(6),
                            default => $key === 'port' ? mt_rand(0, 2) * 9200 : $pick(['es1', 'es2', 'https', 'u', 'p']),
                        };
                    }
                }
                $hosts[] = $host;
            }
            $this->assertParity(ElasticsearchClientFactory::class, static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch($hosts), [$hosts]);

            $responses = [];
            for ($n = mt_rand(0, 6); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $responses[] = $scalar();

                    continue;
                }
                $response = [];
                if (mt_rand(0, 2) !== 0) {
                    $response['timed_out'] = mt_rand(0, 3) === 0 ? $scalar() : (bool) mt_rand(0, 1);
                }
                if (mt_rand(0, 2) !== 0) {
                    $response['_shards'] = mt_rand(0, 4) === 0 ? $scalar() : ['failed' => mt_rand(0, 4) === 0 ? $scalar() : mt_rand(-1, 3)];
                }
                if (mt_rand(0, 5) !== 0) {
                    $hitList = [];
                    for ($k = mt_rand(0, 6); $k > 0; $k--) {
                        $hitList[] = mt_rand(0, 6) === 0 ? $scalar() : ['_id' => mt_rand(0, 4) === 0 ? $scalar() : $pick(['a', 'b', '1', 1, 2, '', '0', 0])];
                    }
                    $total = match (mt_rand(0, 4)) {
                        0 => $scalar(),
                        1 => ['value' => mt_rand(0, 3) === 0 ? $scalar() : mt_rand(-2, 1000)],
                        default => mt_rand(-2, 1000),
                    };
                    $response['hits'] = mt_rand(0, 6) === 0 ? $scalar() : ['total' => $total, 'hits' => mt_rand(0, 6) === 0 ? $scalar() : $hitList];
                }
                $responses[] = $response;
            }
            $this->assertParity(ElasticsearchResponseHelper::class, static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($responses), [$responses]);

            $lists = [];
            for ($n = mt_rand(0, 6); $n > 0; $n--) {
                $lists[] = match (mt_rand(0, 5)) {
                    0 => $scalar(),
                    1 => self::randomBytes(16),
                    2, 3 => implode(',', array_map(static fn (): string => $pick($urls), range(0, mt_rand(0, 5)))),
                    default => array_map(static fn (): mixed => match (mt_rand(0, 3)) {
                        0 => $scalar(),
                        1 => ['host' => 'm'],
                        default => $pick($urls),
                    }, range(0, mt_rand(0, 5))),
                };
            }
            $this->assertParity(ManticoreClientFactory::class, static fn () => ManticoreClientFactory::summarizeHostListBatch($lists), [$lists]);

            $logicalNames = ['releases', 'predb', 'movies', 'music', 'custom', '0', ''];
            $tableNames = ['releases_rt', 'predb_rt', 'movies_rt', 'music_rt', 'anime_rt', 'my_rt', 'custom', 'RELEASES_RT', '_rt', '', '0'];
            $configured = [];
            for ($n = mt_rand(0, 4); $n > 0; $n--) {
                $key = mt_rand(0, 5) === 0 ? mt_rand(0, 3) : $pick($logicalNames);
                $configured[$key] = mt_rand(0, 6) === 0 ? $scalar() : $pick($tableNames);
            }
            $tables = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                $tables[] = match (mt_rand(0, 6)) {
                    0 => $scalar(),
                    1 => self::randomBytes(6).'_rt',
                    default => $pick($tableNames),
                };
            }
            $this->assertParity(ManticoreIndexRegistry::class, static fn () => ManticoreIndexRegistry::summarizeTableNameBatch($tables, $configured), [$tables, $configured]);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();

        $rows = [
            (object) ['id' => 1, 'entity_type' => 'release', 'action' => 'insert', 'entity_id' => 10],
            (object) ['id' => 2, 'entity_type' => 'predb', 'action' => 'update', 'entity_id' => 11],
            (object) ['id' => 3, 'entity_type' => 'movie', 'action' => 'delete', 'entity_id' => 12],
        ];
        $hosts = ['http://es1:9200', ['host' => 'es2', 'scheme' => 'https', 'port' => 9200, 'user' => 'elastic', 'pass' => 'secret']];
        $responses = [['took' => 3, 'timed_out' => false, '_shards' => ['total' => 1, 'failed' => 0], 'hits' => ['total' => ['value' => 125, 'relation' => 'eq'], 'hits' => [['_id' => '1'], ['_id' => '2'], ['_id' => '3']]]]];
        $lists = ['manticore:9308, manticore2:9308'];
        $tables = ['releases_rt', 'predb_rt'];
        $bigRows = [];
        $bigHosts = [];
        $bigResponses = [];
        $bigLists = [];
        $bigTables = [];
        $types = ['release', 'App\\Models\\Release', 'predb', 'movie_info', 'tv-show', 'secondary', 'MusicInfo', 'widgets'];
        $actionNames = ['insert', 'Created', 'saved', 'update', 'deleted', 'bogus'];
        $tableNames = ['releases_rt', 'predb_rt', 'movies_rt', 'tvshows_rt', 'music_rt', 'my_releases', 'unknown_rt', 'custom'];
        for ($i = 0; $i < 2000; $i++) {
            $bigRows[] = (object) ['id' => $i, 'entity_type' => $types[$i % 8], 'action' => $actionNames[$i % 6], 'entity_id' => $i % 17 === 0 ? 0 : $i % 700];
            $bigHosts[] = $i % 3 === 0 ? " http://es{$i}:9200 " : ['host' => 'es'.($i % 50), 'scheme' => $i % 2 === 0 ? 'https' : 'http', 'port' => $i % 5 === 0 ? '' : 9200, 'user' => 'u', 'pass' => $i % 4 === 0 ? '' : 'p'];
            if ($i < 200) {
                $bigResponses[] = ['timed_out' => $i % 10 === 0, '_shards' => ['failed' => $i % 7 === 0 ? 1 : 0], 'hits' => ['total' => ['value' => $i * 13], 'hits' => array_map(static fn (int $k): array => ['_id' => (string) ($k % 400)], range($i, $i + 9))]];
                $bigLists[] = $i % 2 === 0 ? implode(',', array_map(static fn (int $k): string => " node{$k}:9308 ", range($i, $i + 9))) : ['https://m'.($i % 30), ['host' => 'x'], '', 'node'.$i];
            }
            $bigTables[] = $tableNames[$i % 8];
        }

        $workloads = [
            SearchIndexOutboxDispatcher::class => [
                static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch($rows),
                static fn () => SearchIndexOutboxDispatcher::summarizeOutboxBatch($bigRows),
            ],
            ElasticsearchClientFactory::class => [
                static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch($hosts),
                static fn () => ElasticsearchClientFactory::summarizeHostConfigBatch($bigHosts),
            ],
            ElasticsearchResponseHelper::class => [
                static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($responses),
                static fn () => ElasticsearchResponseHelper::summarizeSearchResponseBatch($bigResponses),
            ],
            ManticoreClientFactory::class => [
                static fn () => ManticoreClientFactory::summarizeHostListBatch($lists),
                static fn () => ManticoreClientFactory::summarizeHostListBatch($bigLists),
            ],
            ManticoreIndexRegistry::class => [
                static fn () => ManticoreIndexRegistry::summarizeTableNameBatch($tables),
                static fn () => ManticoreIndexRegistry::summarizeTableNameBatch($bigTables, ['releases' => 'my_releases']),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $results = [];
            foreach (['common' => [$common, 50], 'uncommon' => [$uncommon, 2]] as $name => [$workload, $calls]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW425RustFfi($rust);
                        $this->assertSame($class::usesW425RustFfi(), $rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < 300; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW425RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::resetW425RustFfi();

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 42.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
                $this->assertFalse($class::usesW425RustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesW425RustFfi());
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
        $class::useW425RustFfi(false);
        $php = $plan();
        $class::useW425RustFfi(true);
        $rust = $plan();
        $class::resetW425RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW425RustFfi(false);
        $php = $plan();
        $class::useW425RustFfi(true);
        $rust = $plan();
        $class::resetW425RustFfi();
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
        $ffi = RustPipelineLibrary::resolveDomain('search_w425', ElasticsearchResponseHelper::W425_FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
