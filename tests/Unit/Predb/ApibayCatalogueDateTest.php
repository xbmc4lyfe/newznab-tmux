<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Facades\Search;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\Sources\ApibaySource;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\Termwind;

final class ApibayCatalogueDateTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private ?Dispatcher $previousDispatcher;

    private OutputInterface $previousRenderer;

    private object $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousRenderer = Termwind::getRenderer();
        Termwind::renderUsing(new BufferedOutput);
        Model::unsetEventDispatcher();

        $container = new Application(dirname(__DIR__, 3));
        $container->instance('config', new Repository(['nntmux' => ['echocli' => false]]));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $container->instance('db', $this->database->getDatabaseManager());
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(255) NOT NULL UNIQUE, source TEXT NOT NULL DEFAULT "", category TEXT NULL, size TEXT NULL, files TEXT NULL, predate TEXT NULL, nuked INTEGER NOT NULL DEFAULT 0, nukereason TEXT NULL, filename TEXT NOT NULL DEFAULT "", searched INTEGER NOT NULL DEFAULT 0)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, searchname TEXT, predb_id INTEGER DEFAULT 0, isrenamed INTEGER DEFAULT 1, adddate TEXT)');

        $this->search = new class
        {
            /** @var list<array<string, mixed>> */
            public array $documents = [];

            /** @param array<string, mixed> $document */
            public function insertPredb(array $document): void
            {
                $this->documents[] = $document;
            }

            /** @param array<string, mixed> $document */
            public function updatePreDb(array $document): void
            {
                $this->documents[] = $document;
            }
        };
        Search::swap($this->search);
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher !== null) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        Termwind::renderUsing($this->previousRenderer);
        parent::tearDown();
    }

    #[Test]
    public function catalogue_upload_time_pages_history_without_inserting_or_filling_a_pre_date(): void
    {
        $rows = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/apibay.json'), true, flags: JSON_THROW_ON_ERROR);
        $entries = (new ApibaySource('https://apibay.org/precompiled/data_top100_recent.json'))->parseRows($rows);
        DB::table('predb')->insert(['title' => $entries[1]->title, 'source' => 'trusted-existing']);

        $result = (new PredbFeedImporter)->import($entries);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'skipped' => 0], $result);
        $this->assertSame('2026-10-09 02:39:53', $entries[0]->listedAt?->toDateTimeString());
        $this->assertSame(0, $entries[0]->listedAt->getOffset());
        $this->assertSame($entries[0]->listedAt, $entries[0]->pagingTime());
        $this->assertNull($entries[0]->predate);
        $inserted = (array) DB::table('predb')->where('title', $entries[0]->title)->first();
        $this->assertSame('Some.Show.S01E01.1080p.WEB.h264-GRP', $inserted['title']);
        $this->assertSame('apibay', $inserted['source']);
        $this->assertSame('TV-HD', $inserted['category']);
        $this->assertSame('1024MB', $inserted['size']);
        $this->assertSame('3', $inserted['files']);
        $this->assertNull($inserted['predate']);
        $existing = (array) DB::table('predb')->where('title', $entries[1]->title)->first();
        $this->assertSame('trusted-existing', $existing['source']);
        $this->assertSame('MOVIE-HD', $existing['category']);
        $this->assertSame('500MB', $existing['size']);
        $this->assertNull($existing['files']);
        $this->assertNull($existing['predate']);
        $this->assertSame(['Some.Show.S01E01.1080p.WEB.h264-GRP', 'Some.Movie.2026.720p.BluRay.x264-GRP'], array_column($this->search->documents, 'title'));
    }

    #[Test]
    public function catalogue_upload_time_does_not_replace_a_verified_pre_date(): void
    {
        $entries = (new ApibaySource('https://apibay.org/precompiled/data_top100_recent.json'))->parseRows([
            ['name' => 'Verified.Date-GRP', 'category' => '201', 'added' => '1791513593'],
        ]);
        DB::table('predb')->insert(['title' => 'Verified.Date-GRP', 'source' => 'trusted-existing', 'category' => 'MOVIE', 'predate' => '2026-10-08 12:00:00']);

        $result = (new PredbFeedImporter)->import($entries);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame('2026-10-08 12:00:00', DB::table('predb')->value('predate'));
        $this->assertSame('trusted-existing', DB::table('predb')->value('source'));
    }

    #[Test]
    public function missing_or_invalid_added_time_leaves_both_dates_and_unknown_sizes_null(): void
    {
        $entries = (new ApibaySource('https://apibay.org/precompiled/data_top100_recent.json'))->parseRows([
            ['name' => 'Missing.Date-GRP', 'category' => '201'],
            ['name' => 'Invalid.Date-GRP', 'category' => '205', 'added' => 'unknown', 'size' => '0', 'num_files' => '0'],
        ]);

        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertNull($entry->predate);
            $this->assertNull($entry->listedAt);
            $this->assertNull($entry->pagingTime());
            $this->assertNull($entry->size);
            $this->assertNull($entry->files);
        }
    }
}
