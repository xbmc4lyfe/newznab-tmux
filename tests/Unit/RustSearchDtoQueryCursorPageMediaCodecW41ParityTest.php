<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\DTO\ReleaseSearchQuery;
use App\Services\Search\DTO\SearchCursor;
use App\Services\Search\DTO\SearchPage;
use App\Services\Search\MediaSearchService;
use App\Services\Search\SearchCursorCodec;
use App\Support\RustPipelineLibrary;
use FFI;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * Paired randomized benchmark (always runs; `test_paired_randomized_benchmark_gates_rust_default`):
 * per helper, 300 samples per workload, 20 warmup rounds, per-sample random PHP/Rust order, release
 * dylib. The timings include PHP-side packet marshaling, the FFI call and result decoding. See the
 * commit message for the measured run; each run prints its ratios to STDERR.
 *
 * Marshaling and FFI scratch access dominate these summaries, so every helper misses the 1.5x gate
 * and keeps PHP as the default.
 */
class RustSearchDtoQueryCursorPageMediaCodecW41ParityTest extends TestCase
{
    /** @var list<class-string> */
    private const CLASSES = [
        ReleaseSearchQuery::class,
        SearchCursor::class,
        SearchPage::class,
        MediaSearchService::class,
        SearchCursorCodec::class,
    ];

    private const RANDOM_ROUNDS = 2500;

    private const ALPHABET = ['a', 'S', 'c', '0', '1', '9', '-', ' ', "\t", "\0", "\xc3", "\xa9", "\xff", '_'];

    protected function tearDown(): void
    {
        foreach (self::CLASSES as $class) {
            $class::resetW415RustFfi();
        }
        (new ReflectionClass(SearchCursor::class))->setStaticPropertyValue('w415Ffi', null);

        parent::tearDown();
    }

    public function test_defaults_and_toggles(): void
    {
        foreach (self::CLASSES as $class) {
            $default = (new ReflectionClassConstant($class, 'W415_RUST_FFI_DEFAULT'))->getValue();
            $this->assertIsBool($default);
            $this->assertSame($default, $class::usesW415RustFfi(), "{$class} must start at its default");
            $class::useW415RustFfi(true);
            $this->assertTrue($class::usesW415RustFfi());
            $class::useW415RustFfi(false);
            $this->assertFalse($class::usesW415RustFfi());
            $class::useW415RustFfi();
            $this->assertTrue($class::usesW415RustFfi());
            $class::resetW415RustFfi();
            $this->assertSame($default, $class::usesW415RustFfi());
        }

        // Earlier toggles are independent of the Wave 41.5 ones.
        $releaseSearchQuery = ReleaseSearchQuery::usesRustFfi();
        $searchCursor = SearchCursor::usesRustFfi();
        $media = MediaSearchService::$useRustFfi;
        foreach (self::CLASSES as $class) {
            $class::useW415RustFfi(! $class::usesW415RustFfi());
        }
        $this->assertSame($releaseSearchQuery, ReleaseSearchQuery::usesRustFfi());
        $this->assertSame($searchCursor, SearchCursor::usesRustFfi());
        $this->assertSame($media, MediaSearchService::$useRustFfi);

        // Toggling one class leaves the others alone.
        foreach (self::CLASSES as $class) {
            $class::useW415RustFfi(false);
        }
        SearchPage::useW415RustFfi(true);
        $this->assertTrue(SearchPage::usesW415RustFfi());
        $this->assertFalse(SearchCursorCodec::usesW415RustFfi());
        $this->assertFalse(SearchCursor::usesW415RustFfi());
    }

    public function test_sort_spec_batch_examples(): void
    {
        $specs = [
            ['sort_field' => 'size', 'sort_dir' => 'ASC'],
            ['sort_field' => 'bogus', 'sort_dir' => 'desc'],
            [],
            'not a spec',
            ['sort_field' => 'size', 'sort_dir' => 'aSc'],
            ['sort_field' => 'Size', 'sort_dir' => 'asc '],
            ['sort_field' => 7, 'sort_dir' => true],
            ['sort_field' => 'id', 'sort_dir' => null],
        ];
        $this->assertSame(
            ['specs' => 8, 'invalid_specs' => 1, 'known_fields' => 3, 'fallback_fields' => 4, 'asc' => 2, 'desc' => 5, 'distinct_fields' => 3],
            $this->both(ReleaseSearchQuery::class, static fn () => ReleaseSearchQuery::summarizeSortSpecBatch($specs)),
        );
        $this->assertSame(
            ['specs' => 0, 'invalid_specs' => 0, 'known_fields' => 0, 'fallback_fields' => 0, 'asc' => 0, 'desc' => 0, 'distinct_fields' => 0],
            $this->both(ReleaseSearchQuery::class, static fn () => ReleaseSearchQuery::summarizeSortSpecBatch([])),
        );
        $all = array_map(static fn (string $field): array => ['sort_field' => $field, 'sort_dir' => 'asc'],
            ['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id']);
        $this->assertSame(8, $this->both(ReleaseSearchQuery::class, static fn () => ReleaseSearchQuery::summarizeSortSpecBatch($all))['distinct_fields']);

        // The summary agrees with the constructor normalizers.
        foreach ($specs as $spec) {
            if (! is_array($spec) || ! is_string($spec['sort_field'] ?? null) || ! is_string($spec['sort_dir'] ?? null)) {
                continue;
            }
            $query = new ReleaseSearchQuery(sortField: $spec['sort_field'], sortDirection: $spec['sort_dir']);
            $summary = ReleaseSearchQuery::summarizeSortSpecBatch([$spec]);
            $this->assertSame($query->normalizedSortField() === $spec['sort_field'] ? 1 : 0, $summary['known_fields']);
            $this->assertSame($query->normalizedSortDirection() === 'asc' ? 1 : 0, $summary['asc']);
        }
    }

    public function test_cursor_match_batch_examples(): void
    {
        $cursor = static fn (string $query, string $driver, string $generation, int $expires, int $total = 1): SearchCursor => new SearchCursor([1], $total, $query, $driver, $generation, $expires);
        $cursors = [
            $cursor('q', 'manticore', 'g1', 100, 42),
            $cursor('q', 'manticore', 'g1', 99, 500),
            $cursor('x', 'manticore', 'g1', 200, 7),
            $cursor('q', 'elastic', 'g1', 200, 7),
            $cursor('q', 'manticore', 'g2', 200, 7),
            'not a cursor',
            $cursor('q', 'manticore', 'g1', 300, -5),
        ];
        $this->assertSame(
            ['cursors' => 7, 'invalid' => 1, 'valid' => 2, 'expired' => 1, 'query_mismatch' => 1, 'driver_mismatch' => 1, 'generation_mismatch' => 1, 'max_valid_total' => 42, 'earliest_expires_at' => 99],
            $this->both(SearchCursor::class, static fn () => SearchCursor::summarizeCursorMatchBatch($cursors, 'q', 'manticore', 'g1', 100)),
        );
        $this->assertSame(
            ['cursors' => 1, 'invalid' => 1, 'valid' => 0, 'expired' => 0, 'query_mismatch' => 0, 'driver_mismatch' => 0, 'generation_mismatch' => 0, 'max_valid_total' => 0, 'earliest_expires_at' => null],
            $this->both(SearchCursor::class, static fn () => SearchCursor::summarizeCursorMatchBatch([null], '', '', '', PHP_INT_MIN)),
        );
        $extremes = [$cursor("\0", '', "\xff", PHP_INT_MAX, PHP_INT_MAX), $cursor("\0", '', "\xff", PHP_INT_MIN)];
        $this->assertSame(
            ['cursors' => 2, 'invalid' => 0, 'valid' => 1, 'expired' => 1, 'query_mismatch' => 0, 'driver_mismatch' => 0, 'generation_mismatch' => 0, 'max_valid_total' => PHP_INT_MAX, 'earliest_expires_at' => PHP_INT_MIN],
            $this->both(SearchCursor::class, static fn () => SearchCursor::summarizeCursorMatchBatch($extremes, "\0", '', "\xff", PHP_INT_MIN + 1)),
        );

        // Each classification agrees with the per-cursor matchState().
        foreach ($cursors as $item) {
            if ($item instanceof SearchCursor) {
                $summary = SearchCursor::summarizeCursorMatchBatch([$item], 'q', 'manticore', 'g1', 100);
                $this->assertSame(1, $summary[$item->matchState('q', 'manticore', 'g1', 100)]);
            }
        }
    }

    public function test_page_batch_examples(): void
    {
        $pages = [
            new SearchPage([1, 2, 3, -1], 10, false, 'manticore', true, 1.5, [], true),
            new SearchPage([3, 4, PHP_INT_MIN], -4, true, 'elastic', false),
            ['ids' => [1]],
            new SearchPage([], 3, true, 'manticore', true, 0.0, [], true),
        ];
        $this->assertSame(
            ['pages' => 4, 'invalid' => 1, 'available' => 2, 'fuzzy' => 2, 'has_more' => 2, 'id_count' => 7, 'distinct_ids' => 6, 'max_total' => 10, 'distinct_drivers' => 2],
            $this->both(SearchPage::class, static fn () => SearchPage::summarizePageBatch($pages)),
        );
        $this->assertSame(
            ['pages' => 0, 'invalid' => 0, 'available' => 0, 'fuzzy' => 0, 'has_more' => 0, 'id_count' => 0, 'distinct_ids' => 0, 'max_total' => 0, 'distinct_drivers' => 0],
            $this->both(SearchPage::class, static fn () => SearchPage::summarizePageBatch([])),
        );

        // Non-integer and non-list ids stay in PHP.
        $odd = [
            new SearchPage(['5', 5, 1.0, null], PHP_INT_MAX, false, ''), // @phpstan-ignore argument.type
            new SearchPage([2 => 9, 7 => 9], 1, false, '0'), // @phpstan-ignore argument.type
        ];
        $this->assertSame(
            ['pages' => 2, 'invalid' => 0, 'available' => 2, 'fuzzy' => 0, 'has_more' => 0, 'id_count' => 6, 'distinct_ids' => 2, 'max_total' => PHP_INT_MAX, 'distinct_drivers' => 2],
            $this->both(SearchPage::class, static fn () => SearchPage::summarizePageBatch($odd)),
        );
    }

    public function test_external_id_coverage_examples(): void
    {
        $rows = [
            ['imdbid' => '0012345', 'tmdbid' => 0, 'traktid' => null],
            ['imdbid' => '0', 'tmdbid' => '', 'traktid' => true],
            'not a row',
            ['imdbid' => -5, 'tmdbid' => ' 0', 'traktid' => 7],
            ['imdbid' => 0.0, 'tmdbid' => [], 'traktid' => false],
            ['imdbid' => 1.5, 'tmdbid' => ['x'], 'other' => 3],
        ];
        $this->assertSame(
            ['rows' => 6, 'invalid_rows' => 1, 'with_any' => 4, 'max_per_row' => 3, 'field_counts' => ['imdbid' => 3, 'tmdbid' => 2, 'traktid' => 2]],
            $this->both(MediaSearchService::class, static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($rows)),
        );
        $tv = [['tvdb' => 1, 'trakt' => '2', 'tvmaze' => '0', 'tvrage' => 4], ['traktid' => 5], []];
        $this->assertSame(
            ['rows' => 3, 'invalid_rows' => 0, 'with_any' => 1, 'max_per_row' => 3, 'field_counts' => ['tvdb' => 1, 'traktid' => 1, 'tvmaze' => 0, 'tvrage' => 1]],
            $this->both(MediaSearchService::class, static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($tv, 'tv')),
        );
        $this->assertSame(
            ['rows' => 0, 'invalid_rows' => 0, 'with_any' => 0, 'max_per_row' => 0, 'field_counts' => ['tvdb' => 0, 'traktid' => 0, 'tvmaze' => 0, 'tvrage' => 0]],
            $this->both(MediaSearchService::class, static fn () => MediaSearchService::summarizeExternalIdCoverageBatch([], 'Movie')),
        );

        // Coverage agrees with collectExternalIds().
        foreach ($rows as $row) {
            if (is_array($row)) {
                $summary = MediaSearchService::summarizeExternalIdCoverageBatch([$row]);
                $this->assertSame(count(MediaSearchService::collectExternalIdsPhp($row, MediaSearchService::MOVIE_EXTERNAL_ID_FIELDS)), $summary['max_per_row']);
            }
        }
    }

    public function test_payload_batch_examples(): void
    {
        $payloads = [
            ['sort' => [1, 2], 'expires' => 100, 'driver' => 'manticore'],
            ['sort' => [1, 2, 3, 4, 5], 'expires' => 99, 'driver' => 'manticore'],
            ['sort' => 'nope', 'expires' => 500],
            'not a payload',
            ['sort' => [], 'expires' => '500abc', 'driver' => 'elastic'],
            ['sort' => ['a' => 1, 'b' => 2, 'c' => 3], 'expires' => PHP_INT_MAX, 'driver' => ['x']],
            ['sort' => [1], 'expires' => 150.9],
            ['sort' => [1]],
            ['sort' => null, 'expires' => 500],
        ];
        $this->assertSame(
            ['payloads' => 9, 'invalid_shape' => 3, 'expired' => 2, 'accepted' => 4, 'sort_values' => 6, 'max_sort_width' => 3, 'distinct_drivers' => 3],
            $this->both(SearchCursorCodec::class, static fn () => SearchCursorCodec::summarizePayloadBatch($payloads, 100)),
        );
        $this->assertSame(
            ['payloads' => 0, 'invalid_shape' => 0, 'expired' => 0, 'accepted' => 0, 'sort_values' => 0, 'max_sort_width' => 0, 'distinct_drivers' => 0],
            $this->both(SearchCursorCodec::class, static fn () => SearchCursorCodec::summarizePayloadBatch([], PHP_INT_MAX)),
        );
        $this->assertSame(1, $this->both(SearchCursorCodec::class, static fn () => SearchCursorCodec::summarizePayloadBatch([['sort' => [], 'expires' => PHP_INT_MIN]], PHP_INT_MIN))['accepted']);
    }

    public function test_every_mode_is_served_by_rust(): void
    {
        $this->requireFfi();

        $this->assertSame(['2', '1', '1', '0', '1', '0', '1'], SearchCursor::evaluateW415Native(1, ['1', 'id', 'ASC', '0', '', '']));
        $this->assertSame(['1', '0', '1', '0', '0', '0', '0', '9', '5'], SearchCursor::evaluateW415Native(2, ['q', 'd', 'g', '5', '1', 'q', 'd', 'g', '5', '9']));
        $this->assertSame(['1', '0', '1', '0', '1', '2', '1', '4', '1'], SearchCursor::evaluateW415Native(3, ['1', '1', '0', '1', '4', 'm', pack('P*', 3, 3)]));
        $this->assertSame(['1', '0', '1', '1', '1'], SearchCursor::evaluateW415Native(4, ['1', '1', 'i2']));
        $this->assertSame(['1', '0', '0', '1', '2', '2', '1'], SearchCursor::evaluateW415Native(5, ['0', '1', '0', '2', 'm']));
        $this->assertNull(SearchCursor::evaluateW415Native(-1, ['x']));
        $this->assertNull(SearchCursor::evaluateW415Native(0, []));
        $this->assertNull(SearchCursor::evaluateW415Native(6, []));
        $this->assertNull(SearchCursor::evaluateW415Native(1, ['1', 'id']));
        $this->assertNull(SearchCursor::evaluateW415Native(2, ['q', 'd', 'g', '+5']));
        $this->assertNull(SearchCursor::evaluateW415Native(3, ['1', '1', '0', '1', '4', 'm', 'short']));
        $this->assertNull(SearchCursor::evaluateW415Native(4, ['64']));
        $this->assertNull(SearchCursor::evaluateW415Native(4, ['1', '1', 'x']));
        $this->assertNull(SearchCursor::evaluateW415Native(5, ['0', '1', '0', '-2', 'm']));
    }

    public function test_native_helpers_fall_back_to_php_without_library(): void
    {
        foreach (self::CLASSES as $class) {
            $class::useW415RustFfi(true);
        }
        (new ReflectionClass(SearchCursor::class))->setStaticPropertyValue('w415Ffi', false);

        $this->assertNull(SearchCursor::evaluateW415Native(1, []));
        $this->assertSame(1, ReleaseSearchQuery::summarizeSortSpecBatch([['sort_field' => 'size', 'sort_dir' => 'asc']])['asc']);
        $this->assertSame(1, SearchCursor::summarizeCursorMatchBatch([new SearchCursor([], 0, 'q', 'd', 'g', 9)], 'q', 'd', 'g', 1)['valid']);
        $this->assertSame(2, SearchPage::summarizePageBatch([new SearchPage([1, 2], 2, false, 'm')])['distinct_ids']);
        $this->assertSame(1, MediaSearchService::summarizeExternalIdCoverageBatch([['imdbid' => '1']])['with_any']);
        $this->assertSame(1, SearchCursorCodec::summarizePayloadBatch([['sort' => [1], 'expires' => 5]], 1)['accepted']);

        $missing = RustPipelineLibrary::resolveDomain('search_w415', SearchCursor::W415_FFI_CDEF, sys_get_temp_dir().'/nntmux-missing-'.bin2hex(random_bytes(4)).'.dylib');
        $this->assertNull($missing);
    }

    public function test_malformed_inputs_fall_back_to_php(): void
    {
        $this->requireFfi();

        // Packets Rust declines (invalid tags, oversized field sets) still produce the PHP answer.
        $nonList = [new SearchPage([1 => 4, 3 => 4], 1, false, 'm')]; // @phpstan-ignore argument.type
        $this->assertSame(SearchPage::summarizePageBatchPhp($nonList), $this->both(SearchPage::class, static fn () => SearchPage::summarizePageBatch($nonList)));
        $floatIds = [new SearchPage([1.5, 2], 1, false, 'm')]; // @phpstan-ignore argument.type
        $this->assertSame(SearchPage::summarizePageBatchPhp($floatIds), $this->both(SearchPage::class, static fn () => SearchPage::summarizePageBatch($floatIds)));
        $objects = [['imdbid' => new \stdClass, 'tmdbid' => INF, 'traktid' => -0.0]];
        $this->assertSame(
            ['rows' => 1, 'invalid_rows' => 0, 'with_any' => 1, 'max_per_row' => 2, 'field_counts' => ['imdbid' => 1, 'tmdbid' => 1, 'traktid' => 0]],
            $this->both(MediaSearchService::class, static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($objects)),
        );
    }

    public function test_direct_c_abi_declines_invalid_input_and_reports_needed_capacity(): void
    {
        $ffi = $this->requireFfi();

        $valid = self::packet(['1', 'size', 'asc', '1', 'id', 'desc']);
        $buf = $ffi->new('char[1024]');
        $small = $ffi->new('char[8]');
        for ($i = 0; $i < 8; $i++) {
            $small[$i] = 'Z';
        }

        $call = static fn (int $mode, ?string $input, int $len, mixed $out, int $cap): int => (int) $ffi->nntmux_w415_search_dto_query_cursor_page_media_codec($mode, $input, $len, $out, $cap); // @phpstan-ignore method.notFound
        $callParts = static function (int $mode, array $parts) use ($call, $small): int {
            $packet = self::packet($parts);

            return $call($mode, $packet, strlen($packet), $small, 8);
        };

        $written = $call(1, $valid, strlen($valid), $buf, 1024);
        $this->assertGreaterThan(8, $written);
        $expected = SearchCursor::evaluateW415Native(1, ['1', 'size', 'asc', '1', 'id', 'desc']);
        $this->assertNotNull($expected);
        $this->assertSame(self::packet($expected), FFI::string($buf, $written));

        // Short capacity: -required without touching the buffer, then a retry with that capacity succeeds.
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), $small, 8));
        $this->assertSame(-$written, $call(1, $valid, strlen($valid), null, 0));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
        $exact = $ffi->new("char[{$written}]");
        $this->assertSame($written, $call(1, $valid, strlen($valid), $exact, $written));
        $this->assertSame(self::packet($expected), FFI::string($exact, $written));

        // Overlapping input/output buffers decline without writing.
        $shared = $ffi->new('char[256]');
        FFI::memcpy($shared, $valid, strlen($valid));
        $this->assertSame(-1, (int) $ffi->nntmux_w415_search_dto_query_cursor_page_media_codec(1, $shared, strlen($valid), $shared, 256)); // @phpstan-ignore method.notFound
        $this->assertSame($valid, FFI::string($shared, strlen($valid)));

        // Null pointers with non-zero lengths, oversized input, malformed packets and unknown modes.
        $this->assertSame(-1, $call(1, null, 4, $buf, 1024));
        $this->assertSame(-1, $call(1, $valid, strlen($valid), null, 8));
        $this->assertSame(-1, $call(1, $valid, 16 * 1024 * 1024 + 1, $small, 8));
        foreach ([0, 6, 99] as $mode) {
            $this->assertSame(-1, $call($mode, $valid, strlen($valid), $small, 8));
        }
        $this->assertSame(-1, $call(1, '', 0, $small, 8));
        $this->assertSame(-1, $call(1, "\x01\0\0", 3, $small, 8));
        $this->assertSame(-1, $call(1, "\x01\0\0\0\x09\0\0\0", 8, $small, 8));
        $this->assertSame(-1, $call(1, $valid."\0", strlen($valid) + 1, $small, 8));

        // Non-canonical integers, bad flags and wrong field counts.
        $this->assertSame(-1, $callParts(1, ['2', 'id', 'asc']));
        $this->assertSame(-1, $callParts(2, ['q', 'd', 'g']));
        $this->assertSame(-1, $callParts(2, ['q', 'd', 'g', '0', '1', 'q', 'd', 'g', '01', '0']));
        $this->assertSame(-1, $callParts(3, ['1', '1', '1', '1', '1', 'm', '1234567']));
        $this->assertSame(-1, $callParts(3, ['1', 'yes', '1', '1', '1', 'm', '']));
        $this->assertSame(-1, $callParts(4, ['0']));
        $this->assertSame(-1, $callParts(4, ['2', '1', 'z']));
        $this->assertSame(-1, $callParts(4, ['1', '1', 'i 1']));
        $this->assertSame(-1, $callParts(5, []));
        $this->assertSame(-1, $callParts(5, ['0', '1', '1', '-1', '']));
        $this->assertSame(-1, $callParts(5, ['0', '1', '1', (string) PHP_INT_MAX, '', '1', '1', '1', '']));
        $this->assertSame('ZZZZZZZZ', FFI::string($small, 8));
    }

    public function test_randomized_parity_across_helpers(): void
    {
        $this->requireFfi();

        mt_srand(4105);
        $pick = static fn (array $values): mixed => $values[mt_rand(0, \count($values) - 1)];
        $scalar = static fn (): mixed => match (mt_rand(0, 7)) {
            0 => null,
            1 => (bool) mt_rand(0, 1),
            2 => self::randomInt(),
            3 => mt_rand(0, 7) / 4,
            4 => mt_rand(0, 1) === 0 ? [] : ['nested' => mt_rand(0, 2)],
            default => self::randomBytes(8),
        };
        $token = static fn (): string => $pick(['q', 'Q', '', "\0", 'manticore', 'elastic', 'g1', 'g2', "\xff"]);

        for ($i = 0; $i < self::RANDOM_ROUNDS; $i++) {
            $specs = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $specs[] = $scalar();

                    continue;
                }
                $spec = [];
                if (mt_rand(0, 6) !== 0) {
                    $spec['sort_field'] = mt_rand(0, 4) === 0 ? $scalar() : $pick(['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id', 'ID', 'size ', '', 'bogus']);
                }
                if (mt_rand(0, 6) !== 0) {
                    $spec['sort_dir'] = mt_rand(0, 4) === 0 ? $scalar() : $pick(['asc', 'ASC', 'aSc', 'desc', 'DESC', ' asc', 'as', '', "asc\0"]);
                }
                $specs[] = $spec;
            }
            $this->assertParity(ReleaseSearchQuery::class, static fn () => ReleaseSearchQuery::summarizeSortSpecBatch($specs), [$specs]);

            $now = mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(90, 110);
            $cursors = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                $cursors[] = mt_rand(0, 9) === 0
                    ? $scalar()
                    : new SearchCursor([], mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(0, 500), $token(), $token(), $token(), mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(85, 115));
            }
            [$q, $d, $g] = [$token(), $token(), $token()];
            $this->assertParity(SearchCursor::class, static fn () => SearchCursor::summarizeCursorMatchBatch($cursors, $q, $d, $g, $now), [$cursors, $q, $d, $g, $now]);

            $pages = [];
            for ($n = mt_rand(0, 6); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $pages[] = $scalar();

                    continue;
                }
                $ids = [];
                for ($k = mt_rand(0, 12); $k > 0; $k--) {
                    $ids[] = mt_rand(0, 40) === 0 ? $pick(['5', 1.0, null, -0.0]) : (mt_rand(0, 9) === 0 ? self::randomInt() : mt_rand(-2, 30));
                }
                if (mt_rand(0, 30) === 0) {
                    $ids = array_combine(array_map(static fn (int $k): int => $k * 2, array_keys($ids)), $ids);
                }
                $pages[] = new SearchPage($ids, mt_rand(0, 3) === 0 ? self::randomInt() : mt_rand(-5, 200), (bool) mt_rand(0, 1), $token(), (bool) mt_rand(0, 1), 0.0, [], (bool) mt_rand(0, 1));
            }
            $this->assertParity(SearchPage::class, static fn () => SearchPage::summarizePageBatch($pages), [$pages]);

            $type = $pick(['movie', 'tv', 'Movie', '']);
            $rows = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $rows[] = $scalar();

                    continue;
                }
                $row = [];
                foreach (['imdbid', 'tmdbid', 'traktid', 'tvdb', 'trakt', 'tvmaze', 'tvrage', 'other'] as $key) {
                    if (mt_rand(0, 2) !== 0) {
                        $row[$key] = match (mt_rand(0, 5)) {
                            0 => $scalar(),
                            1 => $pick(['0', '', ' ', '00', '0.0', 'tt0012345', "\0"]),
                            2 => (string) mt_rand(0, 99_999),
                            default => mt_rand(0, 3) === 0 ? 0 : mt_rand(-5, 99_999),
                        };
                    }
                }
                $rows[] = $row;
            }
            $this->assertParity(MediaSearchService::class, static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($rows, $type), [$rows, $type]);

            $payloads = [];
            for ($n = mt_rand(0, 8); $n > 0; $n--) {
                if (mt_rand(0, 9) === 0) {
                    $payloads[] = $scalar();

                    continue;
                }
                $payload = [];
                if (mt_rand(0, 6) !== 0) {
                    $payload['sort'] = mt_rand(0, 5) === 0 ? $scalar() : array_fill(0, mt_rand(0, 4), 1);
                }
                if (mt_rand(0, 6) !== 0) {
                    $payload['expires'] = match (mt_rand(0, 5)) {
                        0 => $pick([null, true, false, '100', '100abc', ' 99', 'x', 100.7, 99.2, [], [1]]),
                        1 => self::randomInt(),
                        default => mt_rand(90, 110),
                    };
                }
                if (mt_rand(0, 3) !== 0) {
                    $payload['driver'] = mt_rand(0, 5) === 0 ? $scalar() : $token();
                }
                $payloads[] = $payload;
            }
            $this->assertParity(SearchCursorCodec::class, static fn () => SearchCursorCodec::summarizePayloadBatch($payloads, $now), [$payloads, $now]);
        }
    }

    public function test_paired_randomized_benchmark_gates_rust_default(): void
    {
        $this->requireFfi();

        $specs = [['sort_field' => 'postdate_ts', 'sort_dir' => 'desc'], ['sort_field' => 'size', 'sort_dir' => 'asc']];
        $cursors = [new SearchCursor([100], 5000, 'hash', 'manticore', 'g1', 1_700_000_600)];
        $pages = [new SearchPage(range(1, 50), 5000, false, 'manticore', true, 3.5, [50], true)];
        $rows = [['imdbid' => '0012345', 'tmdbid' => 603, 'traktid' => null], ['imdbid' => '0133093', 'tmdbid' => 0]];
        $payloads = [['sort' => [100], 'total' => 5000, 'query' => 'hash', 'driver' => 'manticore', 'generation' => 'g1', 'expires' => 1_700_000_600]];
        $bigSpecs = [];
        $bigCursors = [];
        $bigPages = [];
        $bigRows = [];
        $bigPayloads = [];
        $fields = ['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id', 'bogus'];
        for ($i = 0; $i < 2000; $i++) {
            $bigSpecs[] = ['sort_field' => $fields[$i % 9], 'sort_dir' => $i % 3 === 0 ? 'ASC' : 'desc'];
            $bigCursors[] = new SearchCursor([$i], $i * 7, $i % 11 === 0 ? 'other' : 'hash', $i % 13 === 0 ? 'elastic' : 'manticore', 'g1', 1_700_000_000 + ($i % 50) - 5);
            if ($i < 40) {
                $bigPages[] = new SearchPage(range($i * 50, $i * 50 + 99), 100_000, $i % 4 === 0, $i % 2 === 0 ? 'manticore' : 'elastic', true, 1.0, [], true);
            }
            $bigRows[] = ['tvdb' => $i % 5 === 0 ? '0' : (string) $i, 'trakt' => $i % 3 === 0 ? 0 : $i, 'tvmaze' => $i % 7 === 0 ? null : $i, 'tvrage' => ''];
            $bigPayloads[] = ['sort' => array_fill(0, $i % 4, $i), 'expires' => 1_700_000_000 + ($i % 20) - 3, 'driver' => $i % 2 === 0 ? 'manticore' : 'elastic'];
        }

        $now = 1_700_000_000;
        $workloads = [
            ReleaseSearchQuery::class => [
                static fn () => ReleaseSearchQuery::summarizeSortSpecBatch($specs),
                static fn () => ReleaseSearchQuery::summarizeSortSpecBatch($bigSpecs),
            ],
            SearchCursor::class => [
                static fn () => SearchCursor::summarizeCursorMatchBatch($cursors, 'hash', 'manticore', 'g1', $now),
                static fn () => SearchCursor::summarizeCursorMatchBatch($bigCursors, 'hash', 'manticore', 'g1', $now),
            ],
            SearchPage::class => [
                static fn () => SearchPage::summarizePageBatch($pages),
                static fn () => SearchPage::summarizePageBatch($bigPages),
            ],
            MediaSearchService::class => [
                static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($rows),
                static fn () => MediaSearchService::summarizeExternalIdCoverageBatch($bigRows, 'tv'),
            ],
            SearchCursorCodec::class => [
                static fn () => SearchCursorCodec::summarizePayloadBatch($payloads, $now),
                static fn () => SearchCursorCodec::summarizePayloadBatch($bigPayloads, $now),
            ],
        ];

        foreach ($workloads as $class => [$common, $uncommon]) {
            $results = [];
            foreach (['common' => [$common, 50], 'uncommon' => [$uncommon, 2]] as $name => [$workload, $calls]) {
                for ($w = 0; $w < 20; $w++) {
                    foreach ([false, true] as $rust) {
                        $class::useW415RustFfi($rust);
                        $this->assertSame($class::usesW415RustFfi(), $rust);
                        $workload();
                    }
                }

                $times = ['php' => [], 'rust' => []];
                for ($s = 0; $s < 300; $s++) {
                    $order = mt_rand(0, 1) === 1 ? [true, false] : [false, true];
                    foreach ($order as $rust) {
                        $class::useW415RustFfi($rust);
                        $t0 = hrtime(true);
                        for ($i = 0; $i < $calls; $i++) {
                            $workload();
                        }
                        $times[$rust ? 'rust' : 'php'][] = (hrtime(true) - $t0) / ($calls * 1000);
                    }
                }
                $class::resetW415RustFfi();

                $results[$name] = [
                    'mean' => self::mean($times['php']) / max(1e-9, self::mean($times['rust'])),
                    'p99' => self::p99($times['php']) / max(1e-9, self::p99($times['rust'])),
                    'php_mean_us' => self::mean($times['php']),
                    'rust_mean_us' => self::mean($times['rust']),
                ];
            }

            fwrite(STDERR, sprintf(
                "\n[Wave 41.5 benchmark] %s: common mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx | uncommon mean %.2fx (PHP %.2fus / Rust %.2fus) p99 %.2fx",
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
                $this->assertFalse($class::usesW415RustFfi(), "{$class} must keep PHP when Rust is < 1.5x");
            } else {
                $this->assertIsBool($class::usesW415RustFfi());
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
        $class::useW415RustFfi(false);
        $php = $plan();
        $class::useW415RustFfi(true);
        $rust = $plan();
        $class::resetW415RustFfi();
        $this->assertSame($php, $rust);

        return $php;
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $args
     */
    private function assertParity(string $class, callable $plan, array $args): void
    {
        $class::useW415RustFfi(false);
        $php = $plan();
        $class::useW415RustFfi(true);
        $rust = $plan();
        $class::resetW415RustFfi();
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
        $ffi = RustPipelineLibrary::resolveDomain('search_w415', SearchCursor::W415_FFI_CDEF, is_string($path) && $path !== '' ? $path : null);
        if ($ffi === null) {
            $this->markTestSkipped('Rust search FFI library is not built or available.');
        }

        return $ffi;
    }
}
