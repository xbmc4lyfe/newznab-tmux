<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use App\Services\Search\Support\ReleaseIndexProjection;
use App\Services\Search\Support\SearchFailureTracker;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_keeps_php_default`):
 * 300 samples x 20 calls per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib. See the commit message for the measured run; each run prints its ratios to STDERR.
 *
 * Packet marshaling, FFI scratch access and result decoding dominate these small summaries, so Rust
 * misses the 1.5x gate and every helper keeps PHP as the default.
 */
class RustManticoreElasticSearchServiceCircuitW40ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        ManticoreSearchDriver::class,
        ElasticSearchDriver::class,
        SearchService::class,
        SearchFailureTracker::class,
        ReleaseIndexProjection::class,
    ];

    private const RANDOM_ROUNDS = 1200;

    private const ALPHABET = ['a', 'B', 'e', '0', '1', '9', '-', ' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", "\xc3", "\xa9", "\xff", '_'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW405RustFfi();
        }
        SearchService::setUseRustFfi(false);
        (new ReflectionClass(SearchService::class))->setStaticPropertyValue('w405Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_are_php_and_toggles_work(): void
    {
        foreach (self::CLASSES as $class) {
            $this->assertFalse((new ReflectionClassConstant($class, 'W405_RUST_FFI_DEFAULT'))->getValue());
            $this->assertFalse($class::usesW405RustFfi(), "{$class} must default to PHP");
            $class::useW405RustFfi(true);
            $this->assertTrue($class::usesW405RustFfi());
            $class::useW405RustFfi(false);
            $this->assertFalse($class::usesW405RustFfi());
            $class::useW405RustFfi();
            $class::resetW405RustFfi();
            $this->assertFalse($class::usesW405RustFfi());
            $class::useW405RustFfi(null);
            $this->assertFalse($class::usesW405RustFfi());
        }

        // The pre-existing SearchService toggle is independent of the Wave 40.5 one.
        SearchService::useW405RustFfi(true);
        $this->assertFalse(SearchService::$useRustFfi);
        SearchService::setUseRustFfi(true);
        SearchService::resetW405RustFfi();
        $this->assertTrue(SearchService::$useRustFfi);
        $this->assertFalse(SearchService::usesW405RustFfi());
    }

    public function test_manticore_bulk_upsert_examples(): void
    {
        // 2 + (2+1+4) + (4+5+4) + (4+1+4) + (5+3+4) = 43; doc 3: 2 + (2+1+4) + (1+3+4) = 17.
        $documents = [
            ['id' => 1, 'name' => 'hello', 'flag' => true, 'ratio' => 1.5, 'tags' => ['x'], 'none' => null],
            ['id' => '2', 'name' => 'string id'],
            ['id' => 3, 7 => 'abc'],
            ['id' => -4],
            'not a document',
        ];
        $this->assertSame(
            ['total_documents' => 5, 'valid_id_count' => 2, 'invalid_id_count' => 3, 'estimated_bytes' => 60, 'exceeds_batch_limit' => true, 'chunk_count' => 2],
            $this->both(ManticoreSearchDriver::class, static fn () => ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch($documents, 50)),
        );
        $this->assertSame(
            ['total_documents' => 5, 'valid_id_count' => 2, 'invalid_id_count' => 3, 'estimated_bytes' => 60, 'exceeds_batch_limit' => false, 'chunk_count' => 1],
            $this->both(ManticoreSearchDriver::class, static fn () => ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch($documents)),
        );
        $this->assertSame(
            ['total_documents' => 0, 'valid_id_count' => 0, 'invalid_id_count' => 0, 'estimated_bytes' => 0, 'exceeds_batch_limit' => true, 'chunk_count' => 0],
            $this->both(ManticoreSearchDriver::class, static fn () => ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch([], PHP_INT_MIN)),
        );
        $this->assertSame(3, $this->both(ManticoreSearchDriver::class, static fn () => ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch([['id' => 1], ['id' => 2], ['id' => 3]], 0))['chunk_count']);
    }

    public function test_elastic_bulk_response_examples(): void
    {
        $items = [
            ['index' => ['status' => 201]],
            ['update' => ['status' => 200]],
            ['create' => ['status' => 409, 'error' => ['type' => 'version_conflict_engine_exception']]],
            ['index' => ['status' => 429]],
            ['index' => ['status' => 503]],
            ['delete' => ['status' => 404]],
            ['index' => ['status' => '201']],
            ['index' => []],
            [],
            'bogus',
        ];
        $this->assertSame(
            ['total_items' => 10, 'created_count' => 1, 'updated_count' => 1, 'conflict_count' => 1, 'throttled_count' => 1, 'server_error_count' => 1, 'all_succeeded' => false],
            $this->both(ElasticSearchDriver::class, static fn () => ElasticSearchDriver::summarizeElasticBulkResponseItems($items)),
        );
        $this->assertSame(
            ['total_items' => 2, 'created_count' => 1, 'updated_count' => 0, 'conflict_count' => 0, 'throttled_count' => 0, 'server_error_count' => 0, 'all_succeeded' => true],
            $this->both(ElasticSearchDriver::class, static fn () => ElasticSearchDriver::summarizeElasticBulkResponseItems([['index' => ['status' => 201]], ['delete' => ['status' => 204]]])),
        );
        $this->assertTrue($this->both(ElasticSearchDriver::class, static fn () => ElasticSearchDriver::summarizeElasticBulkResponseItems([]))['all_succeeded']);
    }

    public function test_search_fallback_examples(): void
    {
        $attempts = [
            ['driver' => 'manticore', 'ok' => false, 'latency_ms' => 1200],
            ['driver' => 'elasticsearch', 'ok' => true, 'latency_ms' => 400],
            ['driver' => 'elasticsearch', 'ok' => true, 'latency_ms' => -9],
            ['driver' => 'x', 'ok' => 1, 'latency_ms' => '50'],
            null,
        ];
        $this->assertSame(
            ['attempts' => 5, 'primary_hit' => false, 'fallback_used' => true, 'total_latency_ms' => 1600, 'budget_exceeded' => true, 'winning_index' => 1],
            $this->both(SearchService::class, static fn () => SearchService::summarizeSearchFallbackTelemetry($attempts)),
        );
        $this->assertSame(
            ['attempts' => 1, 'primary_hit' => true, 'fallback_used' => false, 'total_latency_ms' => 20, 'budget_exceeded' => false, 'winning_index' => 0],
            $this->both(SearchService::class, static fn () => SearchService::summarizeSearchFallbackTelemetry([['ok' => true, 'latency_ms' => 20]])),
        );
        $this->assertSame(
            ['attempts' => 2, 'primary_hit' => false, 'fallback_used' => true, 'total_latency_ms' => PHP_INT_MAX, 'budget_exceeded' => true, 'winning_index' => -1],
            $this->both(SearchService::class, static fn () => SearchService::summarizeSearchFallbackTelemetry([['latency_ms' => PHP_INT_MAX], ['latency_ms' => 5]], PHP_INT_MAX - 1)),
        );
        $this->assertSame(
            ['attempts' => 0, 'primary_hit' => false, 'fallback_used' => false, 'total_latency_ms' => 0, 'budget_exceeded' => true, 'winning_index' => -1],
            $this->both(SearchService::class, static fn () => SearchService::summarizeSearchFallbackTelemetry([], -1)),
        );
    }

    public function test_circuit_state_window_examples(): void
    {
        $eval = fn (int ...$args): array => $this->both(SearchFailureTracker::class, static fn () => SearchFailureTracker::evaluateCircuitStateWindow(...$args));
        $this->assertSame(['state' => 'closed', 'allow_probe' => false, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => false], $eval(3, 5, 0, 100));
        $this->assertSame(['state' => 'closed', 'allow_probe' => false, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => true], $eval(4, 5, 0, 100));
        $this->assertSame(['state' => 'open', 'allow_probe' => false, 'remaining_cooldown_seconds' => 30, 'trip_on_next_failure' => false], $eval(5, 5, 100, 130));
        $this->assertSame(['state' => 'half_open', 'allow_probe' => true, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => true], $eval(9, 5, 100, 160));
        $this->assertSame(['state' => 'open', 'allow_probe' => false, 'remaining_cooldown_seconds' => 60, 'trip_on_next_failure' => false], $eval(5, 5, 200, 100));
        $this->assertSame(['state' => 'half_open', 'allow_probe' => true, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => true], $eval(PHP_INT_MAX, -3, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX));
        $this->assertSame(['state' => 'open', 'allow_probe' => false, 'remaining_cooldown_seconds' => 1, 'trip_on_next_failure' => false], $eval(1, 1, -5, PHP_INT_MAX - 6, PHP_INT_MAX));
        $this->assertSame(['state' => 'closed', 'allow_probe' => false, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => true], $eval(-5, 0, 0, 0, -60));
        $this->assertSame(['state' => 'half_open', 'allow_probe' => true, 'remaining_cooldown_seconds' => 0, 'trip_on_next_failure' => true], $eval(1, 1, 100, 100, 0));
    }

    public function test_hydration_completeness_examples(): void
    {
        $rows = [
            ['id' => 1, 'searchname' => ' Movie ', 'categories_id' => 2040, 'imdbid' => '0000000', 'size' => 1],
            ['id' => '2', 'searchname' => " \t", 'categories_id' => '0', 'imdbid' => '0012345', 'size' => '0'],
            ['id' => 3, 'searchname' => 42, 'categories_id' => '5030', 'tvdb' => 12, 'size' => '123456789012345678'],
            ['id' => 4, 'searchname' => 'x', 'videos_id' => '+5', 'anidbid' => 1.0, 'size' => '1234567890123456789'],
            ['id' => 0, 'searchname' => 'invalid'],
            ['id' => '007', 'tmdbid' => -1, 'size' => -10],
            ['searchname' => 'no id'],
            'not a row',
        ];
        $this->assertSame(
            ['total_rows' => 8, 'with_searchname' => 2, 'with_category' => 2, 'with_external_media_id' => 2, 'zero_size' => 3, 'invalid_rows' => 3],
            $this->both(ReleaseIndexProjection::class, static fn () => ReleaseIndexProjection::summarizeHydrationBatchCompleteness($rows)),
        );
        $this->assertSame(
            ['total_rows' => 0, 'with_searchname' => 0, 'with_category' => 0, 'with_external_media_id' => 0, 'zero_size' => 0, 'invalid_rows' => 0],
            $this->both(ReleaseIndexProjection::class, static fn () => ReleaseIndexProjection::summarizeHydrationBatchCompleteness([])),
        );
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(4005);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $scalar = static fn (): mixed => match (mt_rand(0, 7)) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => self::randomInt(),
            3 => mt_rand(0, 7) / 4,
            4 => ['nested' => mt_rand(0, 2)],
            default => self::randomBytes(8),
        };
        $identifier = static fn (): mixed => match (mt_rand(0, 9)) {
            0 => self::randomInt(),
            1 => (string) mt_rand(0, 99_999),
            2 => $pick(['', '0', '000', '0012', '+5', '5 ', '1e3', '1234567890123456789', '123456789012345678', 'abc']),
            3 => $pick([null, 1.0, true, ['x']]),
            default => mt_rand(-1, 50_000),
        };

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $documents = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $documents[] = $scalar();

                    continue;
                }
                $document = [];
                if (mt_rand(0, 7) !== 0) {
                    $document['id'] = mt_rand(0, 4) === 0 ? $identifier() : mt_rand(1, 1_000);
                }
                for ($f = mt_rand(0, 5); $f > 0; $f--) {
                    $document[$pick(['name', 'searchname', 'size', 'categories_id', 0, 7, 'é', ''])] = $scalar();
                }
                $documents[] = $document;
            }
            $max = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(0, 200);
            $this->assertParity(ManticoreSearchDriver::class, static fn () => ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch($documents, $max), [$documents, $max]);

            $items = [];
            for ($n = mt_rand(0, 10); $n > 0; $n--) {
                $status = mt_rand(0, 6) === 0 ? $pick([self::randomInt(), '201', null, 2.0]) : $pick([200, 201, 204, 404, 409, 429, 500, 502, 503, 599, 600, 199]);
                $items[] = match (mt_rand(0, 9)) {
                    0 => $scalar(),
                    1 => [],
                    2 => ['index' => $scalar()],
                    3 => ['index' => ['error' => 'x']],
                    default => [$pick(['index', 'create', 'update', 'delete', 0]) => ['status' => $status]],
                };
            }
            $this->assertParity(ElasticSearchDriver::class, static fn () => ElasticSearchDriver::summarizeElasticBulkResponseItems($items), [$items]);

            $attempts = [];
            for ($n = mt_rand(0, 5); $n > 0; $n--) {
                $attempts[] = mt_rand(0, 9) === 0 ? $scalar() : array_filter([
                    'driver' => $pick(['manticore', 'elasticsearch']),
                    'ok' => $pick([true, false, false, 1, 'true', null]),
                    'latency_ms' => mt_rand(0, 5) === 0 ? $pick([self::randomInt(), '12', 1.5, -7]) : mt_rand(0, 2_000),
                ], static fn (): bool => mt_rand(0, 7) !== 0);
            }
            $budget = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(0, 3_000);
            $this->assertParity(SearchService::class, static fn () => SearchService::summarizeSearchFallbackTelemetry($attempts, $budget), [$attempts, $budget]);

            $now = mt_rand(1_700_000_000, 1_700_000_200);
            $opened = $now - mt_rand(-20, 120);
            if (mt_rand(0, 3) === 0) {
                [$now, $opened] = mt_rand(0, 1) === 0 ? [self::randomInt(), self::randomInt()] : [$now, self::randomInt()];
            }
            $circuit = [
                mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-1, 8),
                mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-1, 8),
                $opened,
                $now,
                mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-5, 90),
            ];
            $this->assertParity(SearchFailureTracker::class, static fn () => SearchFailureTracker::evaluateCircuitStateWindow(...$circuit), $circuit);

            $rows = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $rows[] = $scalar();

                    continue;
                }
                $row = [];
                foreach (['id', 'categories_id', 'imdbid', 'tmdbid', 'tvdb', 'videos_id', 'anidbid', 'size'] as $key) {
                    if (mt_rand(0, 3) !== 0) {
                        $row[$key] = $key === 'id' && mt_rand(0, 2) !== 0 ? mt_rand(1, 1_000) : $identifier();
                    }
                }
                if (mt_rand(0, 4) !== 0) {
                    $row['searchname'] = mt_rand(0, 5) === 0 ? $scalar() : self::randomBytes(10);
                }
                $rows[] = $row;
            }
            $this->assertParity(ReleaseIndexProjection::class, static fn () => ReleaseIndexProjection::summarizeHydrationBatchCompleteness($rows), [$rows]);
        }
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['2', '1', '1', '9', '0', '1'], SearchService::evaluateW405Native(1, ['100', '5', '1', 'id', '5', '', '0']));
        $this->assertSame(['3', '1', '0', '0', '0', '1', '0'], SearchService::evaluateW405Native(2, ['201', '502', '']));
        $this->assertSame(['2', '0', '1', '30', '0', '1'], SearchService::evaluateW405Native(3, ['100', '0', '10', '1', '20']));
        $this->assertSame(['open', '0', '50', '0'], SearchService::evaluateW405Native(4, ['3', '3', '10', '20', '60']));
        $this->assertSame(['1', '1', '1', '0', '1', '0'], SearchService::evaluateW405Native(5, ['1', 'x', '2', '', '', '', '', '', '0']));
        $this->assertNull(SearchService::evaluateW405Native(-1, ['x']));
        $this->assertNull(SearchService::evaluateW405Native(0, ['x']));
        $this->assertNull(SearchService::evaluateW405Native(6, ['x']));
        $this->assertNull(SearchService::evaluateW405Native(3, ['+5']));
        $this->assertNull(SearchService::evaluateW405Native(5, ['1']));
    }

    public function test_native_helpers_fall_back_to_php(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useW405RustFfi(true);
        }
        (new ReflectionClass(SearchService::class))->setStaticPropertyValue('w405Ffi', false);

        $this->assertNull(SearchService::evaluateW405Native(2, ['201']));
        $this->assertSame(1, ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch([['id' => 1]])['valid_id_count']);
        $this->assertSame(1, ElasticSearchDriver::summarizeElasticBulkResponseItems([['index' => ['status' => 409]]])['conflict_count']);
        $this->assertSame(0, SearchService::summarizeSearchFallbackTelemetry([['ok' => true]])['winning_index']);
        $this->assertSame('open', SearchFailureTracker::evaluateCircuitStateWindow(5, 5, 100, 110)['state']);
        $this->assertSame(1, ReleaseIndexProjection::summarizeHydrationBatchCompleteness([['id' => 1, 'searchname' => 'a']])['with_searchname']);

        $missing = RustPipelineLibrary::resolveDomain('search_w405', SearchService::W405_FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['1500', '0', '10', '1', '20']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w40_manticore_elastic_search_service_circuit($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(3, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = SearchService::evaluateW405Native(3, ['1500', '0', '10', '1', '20']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Tiny capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(3, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(3, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(3, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Overlapping input/output buffers decline without writing.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, $valid, strlen($valid));
        $this->assertSame(-1, (int) $ffi->nntmux_w40_manticore_elastic_search_service_circuit(3, $shared, strlen($valid), $shared, 256)); // @phpstan-ignore method.notFound
        $this->assertSame($valid, FFI::string($shared, strlen($valid)));

        // Null pointers with non-zero lengths, oversized input, malformed packets and unknown modes.
        $this->assertSame(-1, $call(3, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(3, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(3, $valid, 16 * 1024 * 1024 + 1, $small, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(3, '', 0, $small, 8));
        $this->assertSame(-1, $call(3, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(3, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(3, $valid."\0", strlen($valid) + 1, $small, 8));

        // Non-canonical integers and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['10', '1', '1', 'id']));
        $this->assertSame(-1, $callParts(1, ['10', '01', '0']));
        $this->assertSame(-1, $callParts(1, []));
        $this->assertSame(-1, $callParts(2, ['+201']));
        $this->assertSame(-1, $callParts(3, ['10', '1']));
        $this->assertSame(-1, $callParts(3, ['10', 'yes', '1']));
        $this->assertSame(-1, $callParts(4, ['1', '2', '3', '4']));
        $this->assertSame(-1, $callParts(4, ['1', '2', '3', '4', '99999999999999999999']));
        $this->assertSame(-1, $callParts(5, ['1', '', '']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_paired_randomized_benchmark_keeps_php_default(): void
    {
        $this->requireFfi();

        $documents = [
            ['id' => 1, 'name' => 'Some.Release.2024.1080p', 'categories_id' => 2040, 'size' => 4_000_000_000],
            ['id' => 2, 'name' => 'Other.Release.S01E02', 'categories_id' => 5030, 'size' => 900_000_000],
            ['id' => 3, 'name' => 'Album-2024', 'categories_id' => 3010, 'size' => 120_000_000],
        ];
        $items = [['index' => ['status' => 200]], ['index' => ['status' => 201]], ['index' => ['status' => 200]]];
        $attempts = [['driver' => 'manticore', 'ok' => false, 'latency_ms' => 900], ['driver' => 'elasticsearch', 'ok' => true, 'latency_ms' => 120]];
        $rows = [
            ['id' => 1, 'searchname' => 'Some Release', 'categories_id' => 2040, 'imdbid' => '0012345', 'size' => 4_000_000_000],
            ['id' => 2, 'searchname' => 'Other Release', 'categories_id' => 5030, 'tvdb' => 81189, 'size' => 900_000_000],
        ];
        $common = static function () use ($documents, $items, $attempts, $rows): void {
            ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch($documents);
            ElasticSearchDriver::summarizeElasticBulkResponseItems($items);
            SearchService::summarizeSearchFallbackTelemetry($attempts);
            SearchFailureTracker::evaluateCircuitStateWindow(5, 5, 1_700_000_000, 1_700_000_030);
            ReleaseIndexProjection::summarizeHydrationBatchCompleteness($rows);
        };

        $bigDocuments = [];
        $bigItems = [];
        $bigAttempts = [];
        $bigRows = [];
        for ($i = 0; $i < 2000; $i++) {
            $bigDocuments[] = $i % 97 === 0 ? ['name' => 'no id'] : ['id' => $i + 1, 'name' => "Release.{$i}.1080p.WEB", 'searchname' => "Release {$i}", 'categories_id' => 2000 + $i % 60, 'size' => $i * 1_000_003, 'passworded' => $i % 2 === 0];
            $bigItems[] = [($i % 3 === 0 ? 'update' : 'index') => ['status' => [200, 201, 201, 409, 429, 503][$i % 6]]];
            $bigRows[] = ['id' => $i % 113 === 0 ? 0 : $i + 1, 'searchname' => $i % 17 === 0 ? ' ' : "Release {$i}", 'categories_id' => (string) (2000 + $i % 60), 'imdbid' => $i % 5 === 0 ? '0000000' : sprintf('%07d', $i), 'size' => $i % 23 === 0 ? 0 : $i * 1_000_003];
            if ($i < 64) {
                $bigAttempts[] = ['driver' => $i % 2 === 0 ? 'manticore' : 'elasticsearch', 'ok' => $i === 63, 'latency_ms' => ($i * 37) % 900];
            }
        }
        $uncommon = static function () use ($bigDocuments, $bigItems, $bigAttempts, $bigRows): void {
            ManticoreSearchDriver::summarizeManticoreBulkUpsertBatch($bigDocuments, 65_536);
            ElasticSearchDriver::summarizeElasticBulkResponseItems($bigItems);
            SearchService::summarizeSearchFallbackTelemetry($bigAttempts, 1_000);
            SearchFailureTracker::evaluateCircuitStateWindow(PHP_INT_MAX, 3, PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX);
            ReleaseIndexProjection::summarizeHydrationBatchCompleteness($bigRows);
        };

        $setAll = static function (bool $rust): void {
            foreach (self::CLASSES as $class) {
                $class::useW405RustFfi($rust);
            }
        };

        $results = [];
        foreach (['common' => [$common, 20], 'uncommon' => [$uncommon, 2]] as $name => [$workload, $calls]) {
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
                    for ($i = 0; $i < $calls; $i++) {
                        $workload();
                    }
                    $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
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
            $class::resetW405RustFfi();
        }

        fwrite(STDERR, sprintf(
            "\n[Wave 40.5 benchmark] common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus) | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx (PHP %.2fus / Rust %.2fus)\n",
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

        $qualifies = $results['common']['mean'] >= 1.5 && $results['common']['p99'] >= 1.5
            && $results['uncommon']['mean'] >= 1.5 && $results['uncommon']['p99'] >= 1.5;
        foreach (self::CLASSES as $class) {
            if (! $qualifies) {
                $this->assertFalse($class::usesW405RustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesW405RustFfi());
            }
        }
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
        $class::useW405RustFfi(false);
        $php = $plan();
        $class::useW405RustFfi(true);
        $rust = $plan();
        $class::resetW405RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW405RustFfi(false);
        $php = $plan();
        $class::useW405RustFfi(true);
        $rust = $plan();
        $class::resetW405RustFfi();
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
        $ffi = RustPipelineLibrary::resolveDomain('search_w405', SearchService::W405_FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
