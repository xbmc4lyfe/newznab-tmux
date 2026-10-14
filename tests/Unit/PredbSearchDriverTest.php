<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\NntmuxOffsetWorker;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
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
