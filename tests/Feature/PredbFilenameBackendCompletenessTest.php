<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Search\Drivers\ElasticSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use Elastic\Elasticsearch\Client as ElasticClient;
use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Manticoresearch\Client;
use Manticoresearch\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameBackendCompletenessTest extends TestCase
{
    use Par2RenameTestDatabase;

    private ?ElasticClient $previousElasticClient = null;

    private bool $replacedElasticClient = false;

    protected function tearDown(): void
    {
        try {
            if ($this->replacedElasticClient) {
                (new ReflectionProperty(ElasticSearchDriver::class, 'client'))->setValue(null, $this->previousElasticClient);
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function scenarios(): array
    {
        $cases = [];
        foreach (['manticore', 'elasticsearch'] as $backend) {
            foreach (['prefile', 'list', 'files'] as $caller) {
                foreach ([false, true] as $alias) {
                    foreach (['complete', 'full window', 'truncated', 'error', 'late competitor', 'new mapping', 'current row changed'] as $scenario) {
                        $cases[$backend.' '.$caller.' '.($alias ? 'alias ' : 'canonical ').$scenario] = [$backend, $caller, $alias, $scenario];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_actual_backend_and_filename_caller_fail_closed_and_observe_current_nominations(string $backend, string $caller, bool $alias, string $scenario): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Canonical.Release.2026-GRP';
        $stem = $alias ? 'Synthetic.Filename.Alias-GRP' : $title;
        $filename = $stem.'.mkv';
        $rows = $scenario === 'new mapping' ? [] : [['_id' => 7, '_source' => ['title' => $title, 'filename' => $alias ? $stem : '']]];
        if ($rows !== []) {
            DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $alias ? $stem : '']);
        }
        $calls = 0;
        $response = function () use (&$rows, &$calls, $backend, $scenario): array {
            $calls++;
            if ($scenario === 'error') {
                throw new RuntimeException('synthetic backend failure');
            }
            $hits = $scenario === 'full window' ? array_fill(0, 1000, $rows[0]) : $rows;
            $total = $scenario === 'truncated' ? 1001 : count($hits);

            return ['timed_out' => false, '_shards' => ['total' => 1, 'successful' => 1, 'failed' => 0], 'hits' => [
                'hits' => $hits, 'total_relation' => 'eq',
                'total' => $backend === 'manticore' ? $total : ['value' => $total, 'relation' => 'eq'],
            ]];
        };
        if ($backend === 'manticore') {
            $client = $this->createMock(Client::class);
            $client->method('search')->willReturnCallback(static fn (): Response => new Response($response()));
            $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
            $driver->manticoreSearch = $client;
        } else {
            $http = $this->createMock(ClientInterface::class);
            $http->method('sendRequest')->willReturnCallback(static fn (RequestInterface $request): HttpResponse => new HttpResponse(200,
                ['X-Elastic-Product' => 'Elasticsearch', 'Content-Type' => 'application/json'], json_encode($response(), JSON_THROW_ON_ERROR)));
            $client = ClientBuilder::create()->setHosts(['http://localhost:9200'])->setHttpClient($http)->build();
            $clientProperty = new ReflectionProperty(ElasticSearchDriver::class, 'client');
            $this->previousElasticClient = $clientProperty->getValue();
            $this->replacedElasticClient = true;
            $clientProperty->setValue(null, $client);
            $driver = new ElasticSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        }
        $search = new class($this->app) extends SearchService
        {
            public function supportsReleaseCandidatePaging(): bool
            {
                return false;
            }
        };
        $search->extend('fixture', static fn () => $driver);
        config(['search.default' => 'fixture']);
        Search::swap($search);
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects($caller === 'files' && $scenario === 'new mapping' ? self::once() : self::never())->method('extractFromFile')->willReturn(null);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        $iterations = in_array($scenario, ['late competitor', 'new mapping'], true) ? 2 : 1;
        for ($step = 0; $step < $iterations; $step++) {
            if ($step === 1) {
                $id = $scenario === 'late competitor' ? 8 : 7;
                $competingTitle = $alias ? 'Other.Canonical.2026-GRP' : $title;
                $newTitle = $scenario === 'late competitor' ? $competingTitle : $title;
                DB::table('predb')->insert(['id' => $id, 'title' => $newTitle, 'filename' => $alias ? $stem : '']);
                $rows[] = ['_id' => $id, '_source' => ['title' => $newTitle, 'filename' => $alias ? $stem : '']];
            }
            if ($scenario === 'current row changed') {
                DB::table('predb')->where('id', 7)->update($alias ? ['filename' => 'Other.Alias-GRP'] : ['title' => 'Other.Canonical.2026-GRP']);
            }
            $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
            if ($caller === 'files') {
                DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } elseif ($caller === 'prefile') {
                DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
                $service->getPreFileNames(['prefile', '1']);
                // Keep the next public batch focused on the next observation.
                DB::table('release_files')->where('releases_id', $release->id)->delete();
            } else {
                $release->releases_id = $release->id;
                $service->matchPreDbFileNames($release, [$filename], true, true, false);
            }
            $accepted = $scenario === 'complete' || ($scenario === 'late competitor' && $step === 0) || ($scenario === 'new mapping' && $step === 1);
            self::assertSame($accepted ? 7 : 0, $release->fresh()->predb_id);
            self::assertSame($title, $release->fresh()->searchname, 'Incomplete/error nominations must not fall through to legacy rename');
        }
        self::assertSame($iterations, $calls);
    }

    public static function rawManticoreResponses(): array
    {
        $cases = [];
        foreach (['prefile', 'list', 'files'] as $caller) {
            foreach (['unique', 'ambiguous', 'malformed UTF-8'] as $scenario) {
                $cases[$caller.' '.$scenario] = [$caller, $scenario];
            }
        }

        return $cases;
    }

    #[DataProvider('rawManticoreResponses')]
    public function test_raw_manticore_json_never_erases_a_unicode_competitor(string $caller, string $scenario): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Kelvin.Release-GRP';
        $competitor = 'Exact.Kelvin.Release-GRP';
        DB::table('predb')->insert([
            ['id' => 7, 'title' => $title, 'filename' => ''],
            ['id' => 8, 'title' => $competitor, 'filename' => ''],
        ]);
        $rows = [['_id' => 7, '_source' => ['title' => $title]]];
        if ($scenario !== 'unique') {
            $rows[] = ['_id' => 8, '_source' => ['title' => $competitor, 'source' => 'INVALID_BYTE_MARKER']];
        }
        $wire = json_encode(['timed_out' => false, 'hits' => [
            'total' => count($rows), 'total_relation' => 'eq', 'hits' => $rows,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if ($scenario === 'malformed UTF-8') {
            $wire = str_replace('INVALID_BYTE_MARKER', "\xFF", $wire);
        }
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('search')->willReturnCallback(
            // Preserve actual SDK response decoding and the exact request option that
            // controls its lossy fallback; array responses bypass that behavior.
            static fn (array $params): Response => (new Response($wire, 200))->setTransportInfo(['body' => $params['body']])
        );
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->manticoreSearch = $client;
        $search = new class($this->app) extends SearchService
        {
            public function supportsReleaseCandidatePaging(): bool
            {
                return false;
            }
        };
        $search->extend('fixture', static fn () => $driver);
        config(['search.default' => 'fixture']);
        Search::swap($search);
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile');
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $filename = $title.'.mkv';
        if ($caller === 'files') {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'prefile') {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $release->releases_id = $release->id;
            $service->matchPreDbFileNames($release, [$filename], true, true, false);
        }
        self::assertSame($scenario === 'unique' ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
    }
}
