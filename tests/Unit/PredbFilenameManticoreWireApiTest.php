<?php

declare(strict_types=1);

namespace Tests\Unit;

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

final class PredbFilenameManticoreWireApiTest extends TestCase
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
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function wireCases(): array
    {
        return [
            'proven empty' => ['empty', false, []],
            'one valid row' => ['unique', false, [7]],
            'unicode competitor first' => ['competitor', true, [8, 7]],
            'unicode competitor last' => ['competitor', false, [7, 8]],
            'malformed filename first' => ['malformed filename', true, null],
            'malformed filename last' => ['malformed filename', false, null],
            'malformed UTF8 first' => ['malformed UTF8', true, null],
            'malformed UTF8 last' => ['malformed UTF8', false, null],
        ];
    }

    #[DataProvider('wireCases')]
    public function test_strict_candidate_api_preserves_exact_wire_rows_or_returns_unknown(string $scenario, bool $first, ?array $ids): void
    {
        $title = 'Exact.Kelvin.Release-GRP';
        $competitor = 'Exact.Kelvin.Release-GRP';
        $good = ['_id' => 7, '_source' => ['title' => $title, 'filename' => '']];
        $other = ['_id' => 8, '_source' => ['title' => $competitor, 'filename' => '', 'source' => 'INVALID_BYTE_MARKER']];
        if ($scenario === 'malformed filename') {
            $other['_source']['filename'] = false;
        }
        $rows = match ($scenario) {
            'empty' => [],'unique' => [$good],default => $first ? [$other, $good] : [$good, $other]
        };
        $wire = json_encode(['timed_out' => false, 'hits' => ['total' => count($rows), 'total_relation' => 'eq', 'hits' => $rows]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($scenario === 'malformed UTF8') {
            $wire = str_replace('INVALID_BYTE_MARKER', "\xFF", $wire);
        }
        $requests = [];
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('search')->willReturnCallback(static function (array $params) use ($wire, &$requests): Response {
            $requests[] = $params;

            // Keep the real SDK decoder and exact request option controlling lossy fallback.
            return (new Response($wire, 200))->setTransportInfo(['body' => $params['body']]);
        });
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->manticoreSearch = $client;
        $result = $driver->searchPredbFilenameCandidates($title);
        self::assertCount(1, $requests);
        self::assertSame(false, $requests[0]['body']['strip_bad_utf8'] ?? null);
        if ($ids === null) {
            self::assertNull($result);

            return;
        }
        self::assertNotNull($result);
        self::assertSame($ids, array_column($result, 'id'));
        foreach ($result as $row) {
            self::assertSame($row['id'] === 7 ? $title : $competitor, $row['title']);
        }
        // A two-row list is transport-complete candidates; SQL uniqueness is a separate API.
    }
}
