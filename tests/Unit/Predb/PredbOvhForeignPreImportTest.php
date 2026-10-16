<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Facades\Search;
use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Feeds\Sources\PredbOvhSource;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PredbOvhForeignPreImportTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private ?ApplicationContract $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private ?Dispatcher $previousDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository([]));
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $this->database = new Capsule($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $app->instance('db', $this->database->getDatabaseManager());
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT UNIQUE, source TEXT, category TEXT, size TEXT, files TEXT, predate TEXT, nuked INTEGER, nukereason TEXT, filename TEXT)');
        Search::swap(new class
        {
            /** @param array<string, mixed> $document */
            public function updatePreDb(array $document): void {}

            /** @param array<string, mixed> $document */
            public function insertPredb(array $document): void {}
        });
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
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('importCases')]
    public function foreign_events_preserve_status_and_details_in_preview_and_write(int $type, ?int $existingStatus, bool $filled, bool $foreign): void
    {
        $title = 'Synthetic.Identity.Release-TEST';
        $oldDetails = ['category' => 'ORIGINAL', 'size' => '9MB', 'files' => '3', 'predate' => '2020-01-01 00:00:00'];
        if ($existingStatus !== null) {
            DB::table('predb')->insert([
                'id' => 1, 'title' => $title, 'source' => 'original', 'nuked' => $existingStatus,
                'nukereason' => 'original reason', 'filename' => 'original-file',
                ...($filled ? $oldDetails : []),
            ]);
        }
        $before = DB::table('predb')->get()->toArray();
        $entry = (new PredbOvhSource('https://synthetic.invalid/api/v1/'))->parseRows([[
            'id' => '18446744073709551615', 'name' => $title, 'cat' => 'SYNTHETIC',
            'size' => 2048, 'files' => 7, 'preAt' => 1700000000,
            'nuke' => ['preId' => $foreign ? '18446744073709551614' : '18446744073709551615', 'typeId' => $type, 'reason' => 'synthetic reason'],
        ]])[0];
        $importer = new PredbFeedImporter;
        $preview = $importer->import([$entry], true);
        $this->assertEquals($before, DB::table('predb')->get()->toArray(), 'Preview must not mutate storage.');
        $shadowRows = (new \ReflectionProperty(PredbFeedImporter::class, 'dryRunRows'))->getValue($importer);
        $shadow = reset($shadowRows);
        if (! ($foreign && $filled && $existingStatus !== null)) {
            $this->assertInstanceOf(Predb::class, $shadow);
        }
        $written = (new PredbFeedImporter)->import([$entry]);
        $stored = (array) DB::table('predb')->first();
        $this->assertSame($preview, $written);
        foreach (['title', 'source', 'category', 'size', 'files', 'predate', 'nuked', 'nukereason'] as $column) {
            if ($shadow !== false) {
                $this->assertEquals($shadow->getAttribute($column), $stored[$column], 'Preview/write mismatch: '.$column);
            }
        }
        $mapped = [1 => 2, 2 => 1, 3 => 3, 4 => 2, 5 => 1][$type];
        $this->assertSame($foreign ? ($existingStatus ?? 0) : $mapped, (int) $stored['nuked']);
        $this->assertSame($foreign ? ($existingStatus === null ? null : 'original reason') : 'synthetic reason', $stored['nukereason']);
        $expectedDetails = $filled && $existingStatus !== null ? $oldDetails : ['category' => 'SYNTHETIC', 'size' => '2MB', 'files' => '7', 'predate' => '2023-11-14 22:13:20'];
        foreach ($expectedDetails as $column => $value) {
            $this->assertSame($value, $stored[$column]);
        }
        $this->assertSame($existingStatus === null ? 'predb.ovh' : 'original', $stored['source']);
        $this->assertSame($existingStatus === null ? '' : 'original-file', $stored['filename']);
        $expectedOutcome = $existingStatus === null ? 'inserted' : ($foreign && $filled ? 'skipped' : 'updated');
        $this->assertSame(['inserted' => (int) ($expectedOutcome === 'inserted'), 'updated' => (int) ($expectedOutcome === 'updated'), 'skipped' => (int) ($expectedOutcome === 'skipped')], $written);
    }

    /** @return iterable<string, array{int, ?int, bool, bool}> */
    public static function importCases(): iterable
    {
        foreach (range(1, 5) as $type) {
            foreach ([null, 0, 2] as $status) {
                foreach ([false, true] as $filled) {
                    foreach ([false, true] as $foreign) {
                        yield implode('-', [$type, $status === null ? 'new' : $status, (int) $filled, (int) $foreign]) => [$type, $status, $filled, $foreign];
                    }
                }
            }
        }
    }
}
