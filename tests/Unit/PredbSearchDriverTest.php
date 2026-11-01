<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Manticoresearch\Client;
use Manticoresearch\Request;
use Manticoresearch\Response;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class PredbSearchDriverTest extends TestCase
{
    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('config', new Repository(['app' => ['debug' => false], 'search' => ['index_generation' => 'test']]));
        $container->instance('cache', new CacheRepository(new ArrayStore));
        $container->instance('log', new NullLogger);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function backends(): array
    {
        return ['manticore' => ['manticore']];
    }

    #[DataProvider('repairBulkResponses')]
    public function test_insert_only_repair_requires_a_complete_bulk_acknowledgement(array $response, bool $accepted): void
    {
        $client = new class($response) extends Client
        {
            public function __construct(private array $response) {}

            public function request(Request $request, array $params = [], string $retryReason = ''): Response
            {
                return new Response($this->response);
            }
        };
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'predb_rt']]);
        $driver->manticoreSearch = $client;

        self::assertSame($accepted ? ['success' => 2, 'errors' => 0] : ['success' => 0, 'errors' => 2],
            $driver->bulkInsertPredb([['id' => 17, 'title' => 'A'], ['id' => 91, 'title' => 'B']], false));
    }

    public static function repairBulkResponses(): array
    {
        $acknowledgement = ['items' => [['bulk' => ['table' => 'predb_rt', '_id' => 17, 'created' => 2,
            'deleted' => 0, 'updated' => 0, 'result' => 'created', 'status' => 201]]],
            'errors' => false, 'error' => '', 'current_line' => 3, 'skipped_lines' => 0];
        $cases = ['complete' => [$acknowledgement, true], 'empty' => [[], false],
            'missing acknowledgement' => [['errors' => false], false]];
        foreach (['errors' => true, 'error' => 'duplicate ID', 'warning' => 'partial write', 'skipped_lines' => 1] as $key => $value) {
            $cases[$key] = [array_replace($acknowledgement, [$key => $value]), false];
        }
        foreach (['table' => 'wrong_table', 'created' => 1, 'deleted' => 1, 'updated' => 1, 'result' => 'updated', 'status' => 500] as $key => $value) {
            $response = $acknowledgement;
            $response['items'][0]['bulk'][$key] = $value;
            $cases[$key] = [$response, false];
        }

        return $cases;
    }

    #[DataProvider('backends')]
    public function test_exact_title_has_priority_and_uses_document_id(string $backend): void
    {
        $name = 'MÖVIE.Name-(2026)!|*GROUP';
        $queries = [];
        $driver = $this->driver($backend, [[['_id' => '42', '_source' => ['id' => 999, 'title' => mb_strtolower($name), 'filename' => 'other']]]], $queries);
        self::assertSame(42, $driver->matchPredbExact($name)['id']);
        self::assertCount(1, $queries);
        $this->assertExactQuery($backend, $queries[0], 'title', $name);
    }

    #[DataProvider('backends')]
    public function test_exact_filename_is_used_after_title_miss(string $backend): void
    {
        $name = 'file.Name-GROUP';
        $queries = [];
        $driver = $this->driver($backend, [[], [['_id' => 7, '_source' => ['title' => 'Canonical.Scene-GROUP', 'filename' => strtoupper($name), 'source' => 'srrdb']]]], $queries);
        self::assertSame(['id' => 7, 'title' => 'Canonical.Scene-GROUP', 'filename' => strtoupper($name), 'source' => 'srrdb'], $driver->matchPredbExact($name));
        self::assertCount(2, $queries);
        $this->assertExactQuery($backend, $queries[1], 'filename', $name);
    }

    #[DataProvider('backends')]
    public function test_partial_or_fuzzy_hits_and_missing_ids_are_rejected(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [
            [['_id' => 7, '_source' => ['title' => 'Release.Name-GROUP.Extra']]],
            [['_id' => 0, '_source' => ['title' => 'Canonical.Name-GROUP', 'filename' => 'Release.Name-GROUP']]],
        ], $queries);
        self::assertNull($driver->matchPredbExact('Release.Name-GROUP'));
    }

    #[DataProvider('backends')]
    public function test_empty_input_does_not_query_backend(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [], $queries);
        self::assertNull($driver->matchPredbExact('  '));
        self::assertSame([], $queries);
    }

    #[DataProvider('backends')]
    public function test_no_match_is_not_cached(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [[], [], [], []], $queries);
        self::assertNull($driver->matchPredbExact('Release.Name-GROUP'));
        self::assertNull($driver->matchPredbExact('Release.Name-GROUP'));
        self::assertCount(4, $queries);
    }

    #[DataProvider('backends')]
    public function test_failure_is_logged_and_next_lookup_can_succeed(string $backend): void
    {
        Log::shouldReceive('warning')->once()->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'exact lookup failed') && $context['index'] === 'custom_pre' && $context['exception_class'] === RuntimeException::class);
        $queries = [];
        $driver = $this->driver($backend, [new RuntimeException('offline'), [['_id' => 7, '_source' => ['title' => 'Release.Name-GROUP']]]], $queries);
        self::assertNull($driver->matchPredbExact('Release.Name-GROUP'));
        self::assertSame(7, $driver->matchPredbExact('Release.Name-GROUP')['id']);
        self::assertCount(2, $queries);
    }

    public function test_manticore_broad_search_restores_authoritative_ids(): void
    {
        $driver = new class(['indexes' => ['predb' => 'custom_pre']]) extends ManticoreSearchDriver
        {
            public function searchIndexes(string $rt_index, ?string $searchString, array $column = [], array $searchArray = [], int $limit = 1000): array
            {
                return ['id' => [7], 'data' => [['id' => 999, 'title' => 'Release.Name-GROUP']]];
            }
        };
        self::assertSame(7, $driver->searchPredb('Release.Name-GROUP')[0]['id']);
    }

    /**
     * @param  list<array<int, array<string, mixed>>|RuntimeException>  $responses
     * @param  list<array<string, mixed>>  $queries
     */
    private function driver(string $backend, array $responses, array &$queries): ManticoreSearchDriver
    {
        $client = $this->createStub(Client::class);
        $client->method('search')->willReturnCallback(function (array $request) use (&$responses, &$queries): Response {
            $queries[] = $request['body'];
            $hits = array_shift($responses);
            if ($hits instanceof RuntimeException) {
                throw $hits;
            }
            self::assertIsArray($hits);
            $response = $this->createStub(Response::class);
            $response->method('getResponse')->willReturn(['hits' => ['hits' => $hits, 'total' => count($hits)]]);

            return $response;
        });
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->manticoreSearch = $client;

        return $driver;
    }

    /** @param array<string, mixed> $request */
    private function assertExactQuery(string $backend, array $request, string $field, string $name): void
    {
        self::assertSame('custom_pre', $request['table']);
        $queryString = $request['query']['bool']['must'][0]['query_string'] ?? $request['query']['query_string'] ?? '';
        self::assertStringStartsWith('@'.$field.' "^', $queryString);
        self::assertStringEndsWith('$"', $queryString);
        if (str_contains($name, '|*')) {
            self::assertStringContainsString('\\|\\*', $queryString);
        }
        self::assertArrayNotHasKey('options', $request);
        self::assertSame(5, $request['limit']);
        self::assertArrayNotHasKey('sort', $request);
    }

    #[DataProvider('backends')]
    public function test_filename_nominations_bypass_backend_cache_and_observe_late_competitor(string $backend): void
    {
        $first = [['_id' => 7, '_source' => ['title' => 'Canonical.Release-GRP', 'filename' => 'Alias.Release-GRP']]];
        $second = [...$first, ['_id' => 8, '_source' => ['title' => 'Competing.Release-GRP', 'filename' => 'Alias.Release-GRP']]];
        $queries = [];
        $third = [['_id' => 9, '_source' => ['title' => 'New.Mapping-GRP', 'filename' => 'Alias.Release-GRP']]];
        $driver = $this->filenameDriver($backend, [$this->completeFilenameResponse($backend, $first), $this->completeFilenameResponse($backend, $second), $this->completeFilenameResponse($backend, $third)], $queries);
        // Populate the real generic backend cache, then mutate the synthetic SDK response.
        self::assertSame(7, $driver->searchPredb('Alias.Release-GRP')[0]['id']);
        self::assertCount(2, $driver->searchPredbFilenameCandidates('Alias.Release-GRP'));
        self::assertSame(9, $driver->searchPredbFilenameCandidates('Alias.Release-GRP')[0]['id']);
        self::assertCount(3, $queries, 'Every filename observation requires a fresh backend response');
        foreach ($queries as $query) {
            self::assertSame(1000, $query['limit']);
        }
    }

    public static function incompleteFilenameResponses(): array
    {
        $cases = [];
        foreach (['manticore'] as $backend) {
            foreach (['missing metadata', 'lower bound', 'truncated', 'full window', 'timeout', 'warning', 'error', 'invalid identity', 'transport error'] as $failure) {
                $cases[$backend.' '.$failure] = [$backend, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('incompleteFilenameResponses')]
    public function test_filename_nominations_fail_closed_without_positive_completeness(string $backend, string $failure): void
    {
        $rows = [['_id' => 7, '_source' => ['title' => 'Canonical.Release-GRP']]];
        if ($failure === 'full window') {
            $rows = array_fill(0, 1000, $rows[0]);
        }
        $response = $this->completeFilenameResponse($backend, $rows);
        switch ($failure) {
            case 'missing metadata':
                unset($response['hits']['total_relation']);
                break;
            case 'lower bound':
                $response['hits']['total_relation'] = 'gte';
                break;
            case 'truncated':
                $response['hits']['total'] = 1001;
                break;
            case 'timeout': $response['timed_out'] = true;
                break;
            case 'warning': $response['warning'] = 'partial response';
                break;
            case 'error': $response['error'] = 'partial response';
                break;
            case 'invalid identity': $response['hits']['hits'][0]['_id'] = 0;
                break;
            case 'transport error': $response = new RuntimeException('synthetic unavailable');
                break;
        }
        $queries = [];
        $driver = $this->filenameDriver($backend, [$response], $queries);
        self::assertNull($driver->searchPredbFilenameCandidates('Canonical.Release-GRP'));
        self::assertCount(1, $queries);
    }

    #[DataProvider('backends')]
    public function test_filename_nominations_distinguish_proven_empty_from_unknown(string $backend): void
    {
        $queries = [];
        $driver = $this->filenameDriver($backend, [$this->completeFilenameResponse($backend, [])], $queries);
        self::assertSame([], $driver->searchPredbFilenameCandidates('Missing.Release-GRP'));
    }

    public function test_filename_lookup_is_unknown_for_an_unsupported_custom_driver(): void
    {
        $container = Container::getInstance();
        $container->make('config')->set('search.default', 'fixture');
        $search = new SearchService($container);
        $driver = $this->createStub(SearchDriverInterface::class);
        $search->extend('fixture', static fn () => $driver);
        self::assertNull($search->searchPredbFilenameCandidates('Canonical.Release-GRP'));
    }

    private function completeFilenameResponse(string $backend, array $rows): array
    {
        return ['timed_out' => false, '_shards' => ['total' => 1, 'successful' => 1, 'failed' => 0], 'hits' => [
            'hits' => $rows,
            'total' => count($rows),
            'total_relation' => 'eq',
        ]];
    }

    private function filenameDriver(string $backend, array $responses, array &$queries): ManticoreSearchDriver
    {
        $client = $this->createStub(Client::class);
        $client->method('search')->willReturnCallback(function (array $request) use (&$responses, &$queries): Response {
            $queries[] = $request['body'];
            $response = array_shift($responses);
            if ($response instanceof RuntimeException) {
                throw $response;
            }

            return new Response($response);
        });
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->manticoreSearch = $client;

        return $driver;
    }

    public static function rawRows(): array
    {
        $cases = [];
        $good = ['_id' => '7', '_source' => ['title' => 'Canonical.Release-GRP']];
        foreach (['manticore'] as $backend) {
            foreach ([false, 7.9, '7tail', '9223372036854775808', true, [], (object) ['x' => 1]] as $id) {
                $row = $good;
                $row['_id'] = $id;
                $cases[$backend.' id '.get_debug_type($id).' '.json_encode($id)] = [$backend, [$row], false];
            }
            foreach (['title', 'filename', 'source'] as $field) {
                foreach ([[], ['Alias.Release-GRP'], (object) ['x' => 1], true, false, 17, 17.2] as $value) {
                    $row = $good;
                    $row['_source'][$field] = $value;
                    $cases[$backend.' '.$field.' '.json_encode($value)] = [$backend, [$row], false];
                }
            }
            foreach (['title' => 255, 'filename' => 255, 'source' => 50] as $field => $maximum) {
                foreach (['ascii' => 'a', 'four-byte' => "\u{1F3B5}"] as $encoding => $character) {
                    foreach ([0, 1] as $overflow) {
                        $row = $good;
                        $row['_source'][$field] = str_repeat($character, $maximum + $overflow);
                        $cases[$backend.' bounded '.$field.' '.$encoding.' '.$overflow] = [$backend, [$good, $row], $overflow === 0];
                    }
                }
            }
            foreach (['title', 'filename'] as $field) {
                foreach (["bad\0identity", "bad\nidentity", "bad\x7fidentity", "bad\u{200B}identity", "bad\u{0085}identity", "bad\u{E000}identity"] as $index => $value) {
                    $row = $good;
                    $row['_source'][$field] = $value;
                    $cases[$backend.' malformed identity '.$field.' '.$index] = [$backend, [$good, $row], false];
                }
            }
            foreach (['bad/title', 'bad\\title', 'bad|title'] as $index => $value) {
                $row = $good;
                $row['_source']['title'] = $value;
                $cases[$backend.' forbidden title separator '.$index] = [$backend, [$good, $row], false];
            }
            foreach (['/directory/Alias.Release-GRP.rar', 'C:\\directory\\Alias.Release-GRP.rar', '<<< NZBSPLIT >>> Alias.Release-GRP.rar yEnc (1/2)'] as $index => $value) {
                $row = $good;
                $row['_source']['filename'] = $value;
                $cases[$backend.' valid filename path or trailer '.$index] = [$backend, [$row], true];
            }
            $row = $good;
            $row['_source']['filename'] = "/bad\u{200B}directory/Alias.Release-GRP.rar";
            $cases[$backend.' malformed discarded directory'] = [$backend, [$good, $row], false];
            $cases[$backend.' missing optional fields'] = [$backend, [$good], true];
            $cases[$backend.' null optional fields'] = [$backend, [['_id' => '7', '_source' => ['title' => 'Canonical.Release-GRP', 'filename' => null, 'source' => null]]], true];
            $cases[$backend.' leading zero decimal wire id'] = [$backend, [['_id' => '07', '_source' => ['title' => 'Canonical.Release-GRP']]], true];
            $cases[$backend.' decimal wire id'] = [$backend, [$good], true];
            $cases[$backend.' associative hit collection'] = [$backend, ['first' => $good], false];
            $cases[$backend.' duplicate identity'] = [$backend, [$good, $good], true];
        }

        return $cases;
    }

    #[DataProvider('rawRows')]
    public function test_raw_filename_projection_is_complete_only_for_valid_wire_rows(string $backend, array $rows, bool $valid): void
    {
        $queries = [];
        $driver = $this->filenameDriver($backend, [$this->completeFilenameResponse($backend, $rows)], $queries);
        $result = $driver->searchPredbFilenameCandidates('Alias.Release-GRP');
        if ($valid) {
            // Expected wire normalization is explicit; do not use the production projector as its own oracle.
            $expected = array_map(static fn (array $row): array => [
                'id' => (int) $row['_id'],
                'title' => $row['_source']['title'],
                'filename' => $row['_source']['filename'] ?? '',
                'source' => $row['_source']['source'] ?? '',
            ], $rows);
            self::assertSame($expected, $result);
        } else {
            self::assertNull($result, json_encode($result));
        }
    }
}
