<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use App\Services\Search\Support\SearchFailureTracker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Manticoresearch\Client;
use Manticoresearch\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

final class ReleaseCandidatePagingTest extends TestCase
{
    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application;
        $container->instance('config', new Repository(['search' => ['default' => 'legacy'], 'app' => ['debug' => false]]));
        $container->instance('cache', new CacheRepository(new ArrayStore));
        $container->instance('log', new NullLogger);
        $container->instance(SearchFailureTracker::class, new SearchFailureTracker);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCache'))->setValue(null, null);
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCacheTime'))->setValue(null, null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    /** @return array<string,array{string}> */
    public static function backends(): array
    {
        return ['manticore' => ['manticore']];
    }

    #[DataProvider('backends')]
    public function test_keyset_pages_recover_the_twenty_second_candidate_without_consuming_lookahead(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$this->response(range(1, 22)), $this->response([22])], $queries);

        $first = $driver->searchReleaseCandidatesPage('Movie.Release.2026-WEB-GRP', 21);
        $second = $driver->searchReleaseCandidatesPage('Movie.Release.2026-WEB-GRP', 21, 21);

        $this->assertSame(['ids' => range(1, 21), 'available' => true, 'has_more' => true], $first);
        $this->assertSame(['ids' => [22], 'available' => true, 'has_more' => false], $second);
        $this->assertCount(2, $queries);
        foreach ($queries as $index => $query) {
            $this->assertSame('custom_releases', $query['table']);
            $this->assertSame(22, $query['limit']);
            $this->assertSame([['id' => 'asc']], $query['sort']);
            $this->assertSame(['range' => ['id' => ['gt' => $index === 0 ? 0 : 21]]], $query['query']['bool']['must'][1]);
            $this->assertArrayNotHasKey('offset', $query);
            $this->assertArrayNotHasKey('from', $query);
        }
    }

    #[DataProvider('backends')]
    public function test_either_field_contains_the_whole_literal_query_with_no_fuzzy_or_query_operators(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$this->response([7])], $queries);

        $this->assertSame([7], $driver->searchReleaseCandidatesPage("Movie_(It's_2026)!|@name-WEB-GRP", 21)['ids']);

        $query = $queries[0]['query']['bool']['must'][0]['query_string'];
        $this->assertSame('@@relaxed (@name (Movie It s 2026 name WEB GRP) | @searchname (Movie It s 2026 name WEB GRP))', $query);
        $this->assertArrayNotHasKey('fuzzy', $queries[0]['options']);
    }

    #[DataProvider('backends')]
    public function test_exactly_one_full_page_without_lookahead_is_complete(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$this->response(range(1, 21))], $queries);

        $this->assertSame(['ids' => range(1, 21), 'available' => true, 'has_more' => false], $driver->searchReleaseCandidatesPage('Movie.Release-GRP'));
    }

    #[DataProvider('backends')]
    public function test_positive_integer_and_decimal_string_ids_preserve_their_identity(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [['hits' => ['hits' => [['_id' => 7], ['_id' => '42']], 'total' => 2]]], $queries);

        $this->assertSame(['ids' => [7, 42], 'available' => true, 'has_more' => false], $driver->searchReleaseCandidatesPage('Movie.Release-GRP'));
    }

    #[DataProvider('backends')]
    public function test_existing_two_argument_search_keeps_its_id_mapping_and_query_behavior(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$this->response([7])], $queries);

        $ids = $driver->searchReleases(['name' => 'Movie.Release-GRP', 'searchname' => 'Movie.Release-GRP'], 21);

        $this->assertSame(['7'], $ids);
        $this->assertSame(21, $queries[0]['limit']);
        $expression = $queries[0]['query']['bool']['must'][0]['query_string'];
        $this->assertStringContainsString('@(name,searchname)', $expression);
        $this->assertStringContainsString('|', $expression);
    }

    #[DataProvider('backends')]
    public function test_queries_for_different_cursors_are_not_satisfied_from_a_cached_first_page(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$this->response([7]), $this->response([42])], $queries);

        $this->assertSame([7], $driver->searchReleaseCandidatesPage('Movie.Release-GRP', 21)['ids']);
        $this->assertSame([42], $driver->searchReleaseCandidatesPage('Movie.Release-GRP', 21, 7)['ids']);
        $this->assertCount(2, $queries);
    }

    #[DataProvider('backends')]
    public function test_an_unavailable_backend_does_not_emit_a_complete_page_or_make_a_query(string $backend): void
    {
        $queries = [];
        $driver = $this->driver($backend, [], $queries);
        (new ReflectionProperty($driver::class, 'availabilityCache'))->setValue(null, false);

        $this->assertSame(['ids' => [], 'available' => false, 'has_more' => false], $driver->searchReleaseCandidatesPage('Movie.Release-GRP'));
        $this->assertSame([], $queries);
        $this->assertSame(1, app(SearchFailureTracker::class)->count());
    }

    #[DataProvider('invalidDimensions')]
    public function test_invalid_page_dimensions_cannot_make_an_unbounded_query(string $backend, int $limit, int $afterId): void
    {
        $queries = [];
        $driver = $this->driver($backend, [], $queries);

        $this->expectException(\InvalidArgumentException::class);
        $driver->searchReleaseCandidatesPage('Movie.Release-GRP', $limit, $afterId);
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function invalidDimensions(): iterable
    {
        foreach (['manticore'] as $backend) {
            yield $backend.' zero limit' => [$backend, 0, 0];
            yield $backend.' excessive limit' => [$backend, 1001, 0];
            yield $backend.' negative cursor' => [$backend, 21, -1];
        }
    }

    /** @param array<string,mixed>|RuntimeException $response */
    #[DataProvider('backendFailures')]
    public function test_failed_or_partial_backend_responses_cannot_claim_a_complete_empty_page(string $backend, array|RuntimeException $response): void
    {
        $queries = [];
        $driver = $this->driver($backend, [$response], $queries);

        $this->assertSame(['ids' => [], 'available' => false, 'has_more' => false], $driver->searchReleaseCandidatesPage('Movie.Release-GRP'));
        $this->assertSame(1, app(SearchFailureTracker::class)->count());
    }

    /** @return array<string,array{string,array<string,mixed>|RuntimeException}> */
    public static function backendFailures(): array
    {
        $timedOut = ['hits' => ['hits' => [], 'total' => 0], 'timed_out' => true];

        $failures = [
            'manticore transport' => ['manticore', new RuntimeException('synthetic backend failed')],
            'manticore timed out' => ['manticore', $timedOut],
            'manticore malformed response' => ['manticore', []],
            'manticore duplicate IDs' => ['manticore', ['hits' => ['hits' => [['_id' => 7], ['_id' => 7]], 'total' => 2]]],
            'manticore invalid ID' => ['manticore', ['hits' => ['hits' => [['_id' => 'not-an-id']], 'total' => 1]]],
        ];
        foreach (['manticore'] as $backend) {
            foreach ([
                'boolean' => true,
                'float' => 7.0,
                'decimal string' => '7.0',
                'exponent string' => '7e0',
                'whitespace string' => ' 7 ',
                'overflow string' => (string) PHP_INT_MAX.'0',
                'zero' => 0,
                'negative integer' => -7,
            ] as $shape => $id) {
                $failures[$backend.' '.$shape.' ID'] = [$backend, ['hits' => ['hits' => [['_id' => $id]], 'total' => 1]]];
            }
        }

        return $failures;
    }

    public function test_optional_capability_does_not_change_legacy_driver_search_signature_or_id_mapping(): void
    {
        $legacy = $this->createMock(SearchDriverInterface::class);
        $terms = ['name' => 'Movie.Release-GRP', 'searchname' => 'Movie.Release-GRP'];
        $legacy->expects($this->once())->method('searchReleases')->with($terms, 21)->willReturn([7 => 7]);
        $manager = new SearchService(Container::getInstance());
        $manager->extend('legacy', fn () => $legacy);

        $this->assertFalse($manager->supportsReleaseCandidatePaging());
        $this->assertSame(['ids' => [], 'available' => false, 'has_more' => false], $manager->searchReleaseCandidatesPage('Movie.Release-GRP'));
        $this->assertSame([7 => 7], $manager->searchReleases($terms, 21));
    }

    public function test_service_delegates_to_an_optional_paging_driver_without_changing_the_cursor(): void
    {
        $queries = [];
        $driver = $this->driver('manticore', [$this->response([42])], $queries);
        $manager = new SearchService(Container::getInstance());
        $manager->extend('legacy', fn () => $driver);

        $this->assertTrue($manager->supportsReleaseCandidatePaging());
        $this->assertSame(['ids' => [42], 'available' => true, 'has_more' => false], $manager->searchReleaseCandidatesPage('Movie.Release-GRP', 7, 21));
        $this->assertSame(8, $queries[0]['limit']);
    }

    /** @param list<int> $ids
     * @return array<string,mixed>
     */
    private function response(array $ids): array
    {
        return ['hits' => ['hits' => array_map(static fn (int $id): array => ['_id' => (string) $id, '_source' => ['id' => 999]], $ids), 'total' => count($ids)]];
    }

    /**
     * @param  list<array<string,mixed>|RuntimeException>  $responses
     * @param  list<array<string,mixed>>  $queries
     */
    private function driver(string $backend, array $responses, array &$queries): ManticoreSearchDriver
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(count($responses)))->method('search')->willReturnCallback(function (array $request) use (&$responses, &$queries): Response {
            $queries[] = $request['body'];
            $body = array_shift($responses);
            if ($body instanceof RuntimeException) {
                throw $body;
            }
            $response = $this->createStub(Response::class);
            $response->method('getResponse')->willReturn($body);

            return $response;
        });
        $driver = new ManticoreSearchDriver(['indexes' => ['releases' => 'custom_releases']]);
        $driver->manticoreSearch = $client;
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCache'))->setValue(null, true);
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCacheTime'))->setValue(null, time());

        return $driver;
    }
}
