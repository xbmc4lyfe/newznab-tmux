<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Facades\Search;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
use App\Services\Predb\Irc\PreAnnounceParser;
use Carbon\CarbonImmutable;
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
use Pdo\Sqlite;
use PHPUnit\Framework\TestCase;

final class OpentrackersRelayImportTest extends TestCase
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
        $pdo = $this->database->getConnection()->getPdo();
        assert($pdo instanceof Sqlite);
        $pdo->createFunction('HEX', static fn ($v) => bin2hex((string) $v));
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

    private function capturedEntry(): PredbFeedEntry
    {
        $d = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/predb/opentrackers-2026-10-11.json'), true, 512, JSON_THROW_ON_ERROR);

        return (new PreAnnounceParser('opentrackers', 'ignored'))->parse($d['records'][0]['message'], CarbonImmutable::parse('2026-10-11 23:52:00', 'UTC'));
    }

    public function test_known_date_and_origin_are_not_overwritten(): void
    {
        $entry = $this->capturedEntry();
        DB::table('predb')->insert(['title' => $entry->title, 'source' => 'original-publisher', 'category' => $entry->category, 'predate' => '2026-10-10 01:00:00', 'nuked' => 0, 'filename' => '']);
        self::assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([$entry]));
        self::assertSame('2026-10-10 01:00:00', DB::table('predb')->value('predate'));
        self::assertSame('original-publisher', DB::table('predb')->value('source'));
    }

    public function test_relay_receipt_never_fills_unknown_predate(): void
    {
        $entry = $this->capturedEntry();
        DB::table('predb')->insert(['title' => $entry->title, 'source' => 'catalogue', 'category' => $entry->category, 'nuked' => 0, 'filename' => '']);
        self::assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([$entry]));
        self::assertNull(DB::table('predb')->value('predate'));
        self::assertSame('catalogue', DB::table('predb')->value('source'));
    }

    public function test_observed_only_entry_keeps_unknown_predate(): void
    {
        $parsed = $this->capturedEntry();
        $entry = $parsed;
        self::assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0], (new PredbFeedImporter)->import([$entry]));
        self::assertNull(DB::table('predb')->value('predate'));
        self::assertSame('opentrackers-relay', DB::table('predb')->value('source'));
        $entry = new PredbFeedEntry(title: $parsed->title, source: 'original-publisher', category: $parsed->category, predate: CarbonImmutable::parse('2026-10-10 01:00:00', 'UTC'));
        self::assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0], (new PredbFeedImporter)->import([$entry]));
        self::assertSame('2026-10-10 01:00:00', DB::table('predb')->value('predate'));
        self::assertSame('opentrackers-relay', DB::table('predb')->value('source'));
    }
}
