<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Console\Commands\PredbImportFeed;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\PredbFeedSourceFactory;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PredbLatestCatalogueHistoryTest extends TestCase
{
    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $app = new Application(dirname(__DIR__, 3));
        $config = require __DIR__.'/../../../config/predb_feeds.php';
        $app->instance('config', new Repository(['predb_feeds' => $config]));
        $app->instance('log', new NullLogger);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        Http::swap((new Factory)->preventStrayRequests());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('catalogueHistoryCases')]
    public function latest_catalogues_never_claim_history_from_a_listing_cutoff(int $pageSize, string $since, bool $staleFirst, int $expectedCount): void
    {
        config(['predb_feeds.page_size' => $pageSize]);
        $xml = (string) file_get_contents(__DIR__.'/../../Fixtures/predb/scnlog.xml');
        if ($staleFirst) {
            $xml = (string) preg_replace('~<pubDate>[^<]+</pubDate>~', '<pubDate>Sat, 10 Oct 2026 21:00:00 +0000</pubDate>', $xml, 1);
        }
        Http::fake(['https://scnlog.me/feed/' => Http::response($xml)]);

        [$status, $rows, $titles] = $this->executeCommand('scnlog', $since);

        $this->assertSame(0, $status);
        $this->assertCount($expectedCount, $titles);
        $this->assertSame('incomplete: history paging is disabled for this source', $rows[0][4]);
        $this->assertSame('Naza_x_JUL-Pas_Dproblemes-SINGLE-WEB-FR-2026-OND', $titles[0]);
        Http::assertSentCount(1);
    }

    /** @return array<string, array{int, string, bool, int}> */
    public static function catalogueHistoryCases(): array
    {
        return [
            'cap hides newer dated catalogue rows' => [1, '2026-10-11', true, 1],
            'full latest page still has no history paging' => [100, '2026-10-12', false, 5],
        ];
    }

    #[Test]
    public function an_ordinary_paged_pre_feed_can_still_reach_its_cutoff(): void
    {
        Http::fake(['https://predb.club/api/v1/*' => Http::response(['data' => ['rows' => [['name' => 'Exact.Release.2026-GRP', 'preAt' => 1791589642]]]])]);

        [$status, $rows, $titles] = $this->executeCommand('predb_club', '2026-10-12');

        $this->assertSame(0, $status);
        $this->assertSame('ok', $rows[0][4]);
        $this->assertSame(['Exact.Release.2026-GRP'], $titles);
        Http::assertSentCount(1);
    }

    /** @return array{int, array<int, array<int, int|string>>, list<string>} */
    private function executeCommand(string $source, string $since): array
    {
        $importer = new class extends PredbFeedImporter
        {
            /** @var list<string> */
            public array $titles = [];

            public function import(iterable $entries, bool $dryRun = false): array
            {
                $count = 0;
                foreach ($entries as $entry) {
                    $this->titles[] = $entry->title;
                    $count++;
                }

                return ['inserted' => $count, 'updated' => 0, 'skipped' => 0];
            }
        };
        $command = new class($source, $since) extends PredbImportFeed
        {
            /** @var array<int, array<int, int|string>> */
            public array $rows = [];

            public function __construct(private readonly string $source, private readonly string $since)
            {
                parent::__construct();
            }

            /** @return list<string>|bool|int|string|null */
            public function option($key = null)
            {
                return ['source' => [$this->source], 'since' => $this->since, 'max-pages' => 100, 'dry-run' => true, 'quiet' => false][$key] ?? null;
            }

            /**
             * @param  array<string>  $headers
             * @param  array<int, array<int, int|string>>|Arrayable<int, array<int, int|string>>  $rows
             * @param  array<string>  $columnStyles
             */
            public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = [])
            {
                $this->rows = $rows instanceof Arrayable ? $rows->toArray() : $rows;
            }

            public function comment($string, $verbosity = null) {}
        };
        $status = $command->handle(new PredbFeedSourceFactory, $importer);

        return [$status, $command->rows, $importer->titles];
    }
}
