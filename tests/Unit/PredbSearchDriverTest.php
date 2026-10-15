<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxOffsetWorker;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use App\Support\PredbSearchDocument;
use Elastic\Elasticsearch\Client as ElasticClient;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response as HttpResponse;
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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
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
        (new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]))->resetConnection();
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function backends(): array
    {
        return ['manticore' => ['manticore'], 'elasticsearch' => ['elasticsearch']];
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

    public function test_elasticsearch_broad_search_restores_authoritative_ids(): void
    {
        $queries = [];
        $driver = $this->driver('elasticsearch', [[['_id' => 7, '_source' => ['id' => 999, 'title' => 'Release.Name-GROUP']]]], $queries);
        $results = (new ReflectionMethod($driver, 'executeSearch'))->invoke($driver, ['index' => 'custom_pre', 'body' => ['query' => ['match_all' => (object) []]]], true);

        self::assertSame(7, $results[0]['id']);
    }

    public function test_elasticsearch_writes_exact_fields_for_single_update_and_bulk_paths(): void
    {
        $row = ['id' => 7, 'title' => 'MÖVIE.Name-GROUP', 'filename' => 'FILE.Name.mkv', 'source' => 'srrdb'];
        $expected = PredbSearchDocument::forElasticsearch($row);
        $requests = [];
        $client = $this->elasticClient(function (RequestInterface $request) use (&$requests): HttpResponse {
            $requests[] = $request;

            return $this->elasticResponse([]);
        });
        $this->setElasticClient($client);
        $driver = new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->insertPredb($row);
        $driver->updatePreDb($row);
        self::assertSame(['success' => 1, 'errors' => 0], $driver->bulkInsertPredb([$row]));
        self::assertCount(3, $requests);
        self::assertSame('/custom_pre/_doc/7', $requests[0]->getUri()->getPath());
        self::assertSame($expected, json_decode((string) $requests[0]->getBody(), true));
        self::assertSame('/custom_pre/_update/7', $requests[1]->getUri()->getPath());
        self::assertSame(['doc' => $expected, 'doc_as_upsert' => true], json_decode((string) $requests[1]->getBody(), true));
        $bulk = array_map(static fn (string $line): array => json_decode($line, true), explode("\n", trim((string) $requests[2]->getBody())));
        self::assertSame([['index' => ['_index' => 'custom_pre', '_id' => 7]], $expected], $bulk);
        self::assertSame('mövie.name-group', $expected['title_exact']);
        self::assertSame('file.name.mkv', $expected['filename_exact']);
    }

    public function test_offset_population_uses_exact_fields_only_for_elasticsearch(): void
    {
        $worker = new NntmuxOffsetWorker;
        $method = new ReflectionMethod($worker, 'getTransformer');
        $row = (object) ['id' => 7, 'title' => 'Scene.Title-GROUP', 'filename' => 'File.Name', 'source' => 'srrdb'];
        self::assertSame(PredbSearchDocument::forElasticsearch((array) $row), $method->invoke($worker, 'elastic', 'predb')($row));
        self::assertSame((array) $row, $method->invoke($worker, 'manticore', 'predb')($row));
    }

    /**
     * @param  list<array<int, array<string, mixed>>|RuntimeException>  $responses
     * @param  list<array<string, mixed>>  $queries
     */
    private function driver(string $backend, array $responses, array &$queries): ManticoreSearchDriver|ElasticSearchDriver
    {
        if ($backend === 'manticore') {
            $client = $this->createMock(Client::class);
            $client->method('search')->willReturnCallback(function (array $request) use (&$responses, &$queries): Response {
                $queries[] = $request['body'];
                $hits = array_shift($responses);
                if ($hits instanceof RuntimeException) {
                    throw $hits;
                }
                self::assertIsArray($hits);
                $response = $this->createMock(Response::class);
                $response->method('getResponse')->willReturn(['hits' => ['hits' => $hits, 'total' => count($hits)]]);

                return $response;
            });
            $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
            $driver->manticoreSearch = $client;

            return $driver;
        }
        $client = $this->elasticClient(function (RequestInterface $request) use (&$responses, &$queries): HttpResponse {
            self::assertSame('/custom_pre/_search', $request->getUri()->getPath());
            $queries[] = ['index' => 'custom_pre', 'body' => json_decode((string) $request->getBody(), true)];
            $hits = array_shift($responses);
            if ($hits instanceof RuntimeException) {
                throw $hits;
            }
            self::assertIsArray($hits);

            return $this->elasticResponse(['hits' => ['hits' => $hits, 'total' => ['value' => count($hits)]]]);
        });
        $this->setElasticClient($client);

        return new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
    }

    /** @param array<string, mixed> $request */
    private function assertExactQuery(string $backend, array $request, string $field, string $name): void
    {
        if ($backend === 'manticore') {
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
        } else {
            self::assertSame('custom_pre', $request['index']);
            self::assertSame(['term' => [$field.'_exact' => mb_strtolower($name)]], $request['body']['query']);
            self::assertSame(1, $request['body']['size']);
        }
    }

    public function test_projection_aware_public_search_feeds_real_selector(): void
    {
        $query = 'Synthetic.Archive-GROUP';
        $document = ['id' => 999, 'title' => 'Canonical.Release-GROUP', 'filename' => $query.'.mkv', 'source' => 'synthetic-fixture'];
        $requests = [];
        $client = $this->elasticClient(function (RequestInterface $request) use (&$requests, $document): HttpResponse {
            self::assertSame('/custom_pre/_search', $request->getUri()->getPath());
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $body;
            self::assertIsArray($body['_source']);
            // Enforce the actual Elasticsearch source projection rather than returning the full fixture.
            $projected = array_intersect_key($document, array_flip($body['_source']));

            return $this->elasticResponse(['hits' => ['hits' => [['_id' => '7', '_source' => $projected]], 'total' => ['value' => 1, 'relation' => 'eq']]]);
        });
        $this->setElasticClient($client);
        $driver = new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $hits = $driver->searchPredb($query);
        self::assertCount(1, $requests);
        $selector = new PredbMatchSelector;
        $selected = $selector->selectBestMatch($query, $hits);
        self::assertSame(['id' => 7, 'title' => $document['title'], 'filename' => $document['filename'], 'source' => $document['source']], $selected);
        self::assertSame(['title', 'filename', 'source'], $requests[0]['_source']);
        self::assertNull($selector->selectBestMatch('Synthetic_Archive-GROUP', $hits));
    }

    public function test_projection_aware_public_search_rejects_punctuation_near_filename(): void
    {
        $query = 'Synthetic.Archive-GROUP';
        $document = ['id' => 999, 'title' => 'Canonical.Release-GROUP', 'filename' => 'Synthetic_Archive-GROUP.mkv', 'source' => 'synthetic-fixture'];
        $requests = [];
        $client = $this->elasticClient(function (RequestInterface $request) use (&$requests, $document): HttpResponse {
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $body;
            $projected = array_intersect_key($document, array_flip($body['_source']));

            return $this->elasticResponse(['hits' => ['hits' => [['_id' => '7', '_source' => $projected]], 'total' => ['value' => 1, 'relation' => 'eq']]]);
        });
        $this->setElasticClient($client);
        $driver = new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $hits = $driver->searchPredb($query);
        self::assertCount(1, $requests);
        self::assertSame(7, $hits[0]['id']);
        self::assertSame($document['filename'], $hits[0]['filename']);
        self::assertNull((new PredbMatchSelector)->selectBestMatch($query, $hits));
    }

    public function test_public_predb_search_skips_stale_projection_cache_and_caches_canonical_results(): void
    {
        $query = 'Synthetic.Archive-GROUP';
        $driver = new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $keywords = (new ReflectionMethod($driver, 'sanitizeSearchTerms'))->invoke($driver, $query);
        $keyMethod = new ReflectionMethod($driver, 'buildCacheKey');
        $oldKey = $keyMethod->invoke($driver, 'predb_search_v2', [$keywords]);
        $newKey = $keyMethod->invoke($driver, 'predb_search_v3', [$keywords]);
        $cache = Container::getInstance()->make('cache');
        $stale = [['id' => 7, 'title' => '', 'filename' => '', 'source' => '']];
        $cache->put($oldKey, $stale, 300);
        $cache->put('unrelated-fixture-key', ['keep' => true], 300);
        $document = ['id' => 999, 'title' => 'Canonical.Release-GROUP', 'filename' => $query.'.mkv', 'source' => 'synthetic-fixture'];
        $requests = [];
        $this->setElasticClient($this->elasticClient(function (RequestInterface $request) use (&$requests, $document): HttpResponse {
            self::assertSame('/custom_pre/_search', $request->getUri()->getPath());
            $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $requests[] = $body;
            self::assertSame(['title', 'filename', 'source'], $body['_source']);
            $projected = array_intersect_key($document, array_flip($body['_source']));

            return $this->elasticResponse(['hits' => ['hits' => [['_id' => '7', '_source' => $projected]], 'total' => ['value' => 1, 'relation' => 'eq']]]);
        }));
        $hits = $driver->searchPredb($query);
        self::assertCount(1, $requests, 'Stale v2 cache must not suppress the fresh projected request');
        $expected = ['id' => 7, 'title' => $document['title'], 'filename' => $document['filename'], 'source' => $document['source']];
        self::assertSame([$expected], $hits);
        self::assertSame($expected, (new PredbMatchSelector)->selectBestMatch($query, $hits));
        self::assertSame([$expected], $cache->get($newKey));
        self::assertSame($hits, $driver->searchPredb($query));
        self::assertCount(1, $requests, 'Second lookup must reuse v3 results');
        self::assertSame($stale, $cache->get($oldKey));
        self::assertSame(['keep' => true], $cache->get('unrelated-fixture-key'));
    }

    #[DataProvider('backends')]
    public function test_filename_nominations_bypass_backend_cache_and_observe_late_competitor(string $backend): void
    {
        $first = [['_id' => 7, '_source' => ['title' => 'Canonical.Release-GRP', 'filename' => 'Alias.Release-GRP']]];
        $second = [...$first, ['_id' => 8, '_source' => ['title' => 'Competing.Release-GRP', 'filename' => 'Alias.Release-GRP']]];
        $queries = [];
        $third = [['_id' => 9, '_source' => ['title' => 'New.Mapping-GRP', 'filename' => 'Alias.Release-GRP']]];
        $driver = $this->filenameDriver($backend, [$this->completeFilenameResponse($backend, $first), $this->completeFilenameResponse($backend, $second), $this->completeFilenameResponse($backend, $third)], $queries, true);
        // Populate the real generic backend cache, then mutate the synthetic SDK response.
        self::assertSame(7, $driver->searchPredb('Alias.Release-GRP')[0]['id']);
        self::assertCount(2, $driver->searchPredbFilenameCandidates('Alias.Release-GRP'));
        self::assertSame(9, $driver->searchPredbFilenameCandidates('Alias.Release-GRP')[0]['id']);
        self::assertCount(3, $queries, 'Every filename observation requires a fresh backend response');
        foreach ($queries as $query) {
            self::assertSame(1000, $query['limit'] ?? $query['size']);
            if ($backend === 'elasticsearch') {
                self::assertTrue($query['track_total_hits']);
            }
        }
    }

    public static function incompleteFilenameResponses(): array
    {
        $cases = [];
        foreach (['manticore', 'elasticsearch'] as $backend) {
            foreach (['missing metadata', 'lower bound', 'truncated', 'full window', 'timeout', 'warning', 'error', 'invalid identity', 'transport error'] as $failure) {
                $cases[$backend.' '.$failure] = [$backend, $failure];
            }
        }
        $cases['elasticsearch failed shard'] = ['elasticsearch', 'failed shard'];
        $cases['elasticsearch zero shards'] = ['elasticsearch', 'zero shards'];
        $cases['elasticsearch cross cluster'] = ['elasticsearch', 'cross cluster'];
        $cases['elasticsearch early termination'] = ['elasticsearch', 'early termination'];

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
                if ($backend === 'elasticsearch') {
                    unset($response['hits']['total']['relation']);
                }
                break;
            case 'lower bound':
                if ($backend === 'manticore') {
                    $response['hits']['total_relation'] = 'gte';
                } else {
                    $response['hits']['total']['relation'] = 'gte';
                }
                break;
            case 'truncated':
                if ($backend === 'manticore') {
                    $response['hits']['total'] = 1001;
                } else {
                    $response['hits']['total']['value'] = 1001;
                }
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
            case 'zero shards':
                $response['_shards'] = ['total' => 0, 'successful' => 0, 'failed' => 0];
                break;
            case 'cross cluster':
                $response['_clusters'] = ['total' => 2, 'successful' => 1, 'skipped' => 1];
                break;
            case 'failed shard': $response['_shards']['failed'] = 1;
                break;
            case 'early termination': $response['terminated_early'] = true;
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
            'total' => $backend === 'manticore' ? count($rows) : ['value' => count($rows), 'relation' => 'eq'],
            'total_relation' => 'eq',
        ]];
    }

    private function filenameDriver(string $backend, array $responses, array &$queries, bool $genericFirst = false): ManticoreSearchDriver|ElasticSearchDriver
    {
        if ($backend === 'manticore') {
            $client = $this->createMock(Client::class);
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
        $this->setElasticClient($this->elasticClient(function (RequestInterface $request) use (&$responses, &$queries, $genericFirst): HttpResponse {
            parse_str($request->getUri()->getQuery(), $parameters);
            if (! $genericFirst || $queries !== []) {
                self::assertSame('false', $parameters['allow_partial_search_results']);
                self::assertSame('false', $parameters['allow_no_indices']);
                self::assertSame('false', $parameters['ignore_unavailable']);
            }
            $queries[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $response = array_shift($responses);
            if ($response instanceof RuntimeException) {
                throw $response;
            }

            return $this->elasticResponse($response);
        }));

        return new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
    }

    public static function rawRows(): array
    {
        $cases = [];
        $good = ['_id' => '7', '_source' => ['title' => 'Canonical.Release-GRP']];
        foreach (['manticore', 'elasticsearch'] as $backend) {
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
            self::assertNotNull($result);
        } else {
            self::assertNull($result, json_encode($result));
        }
    }

    private function setElasticClient(ElasticClient $client): void
    {
        (new ReflectionProperty(ElasticSearchDriver::class, 'client'))->setValue(null, $client);
        (new ReflectionProperty(ElasticSearchDriver::class, 'availabilityCache'))->setValue(null, true);
        (new ReflectionProperty(ElasticSearchDriver::class, 'availabilityCacheTime'))->setValue(null, time());
    }

    /** @param array<string, mixed> $body */
    private function elasticResponse(array $body): HttpResponse
    {
        return new HttpResponse(200, ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function elasticClient(callable $handler): ElasticClient
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback($handler);

        return ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient($http)->build();
    }
}
