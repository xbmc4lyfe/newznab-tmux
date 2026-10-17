<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\Drivers\ManticoreSearchDriver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Manticoresearch\Client;
use Manticoresearch\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;

final class ManticoreTotalExactnessTest extends TestCase
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

        // Pin the cached availability probe so the driver goes straight to the search call.
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCache'))->setValue(null, true);
        (new ReflectionProperty(ManticoreSearchDriver::class, 'availabilityCacheTime'))->setValue(null, time());
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

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function responses(): array
    {
        return [
            'exact' => [['timed_out' => false, 'hits' => ['total' => 2_700_000, 'total_relation' => 'eq', 'hits' => []]], true],
            'lower bound' => [['timed_out' => false, 'hits' => ['total' => 1000, 'total_relation' => 'gte', 'hits' => []]], false],
            'timed out' => [['timed_out' => true, 'hits' => ['total' => 5, 'total_relation' => 'eq', 'hits' => []]], false],
            'missing timed_out' => [['hits' => ['total' => 5, 'total_relation' => 'eq', 'hits' => []]], false],
            'missing relation' => [['timed_out' => false, 'hits' => ['total' => 5, 'hits' => []]], false],
            'warning' => [['timed_out' => false, 'warning' => 'partial', 'hits' => ['total' => 5, 'total_relation' => 'eq', 'hits' => []]], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('responses')]
    public function test_total_exact_reflects_the_manticore_response(array $payload, bool $expected): void
    {
        $client = $this->createMock(Client::class);
        $client->method('search')->willReturn(new Response(json_encode($payload, JSON_THROW_ON_ERROR), 200));
        $driver = new ManticoreSearchDriver(['indexes' => ['releases' => 'releases_rt']]);
        $driver->manticoreSearch = $client;

        $result = $driver->searchReleasesFiltered(['phrases' => null, 'try_fuzzy' => false], 1);

        self::assertTrue($result['available']);
        self::assertSame($expected, $result['total_exact']);
        self::assertSame($payload['hits']['total'], $result['total']);
    }
}
