<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Models\Release;
use App\Services\Predb\PredbIdentityLookup;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use ReflectionClass;

trait CertifiedPredbSqlFixture
{
    private Capsule $database;

    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private ?Dispatcher $previousDispatcher;

    private function setUpCertifiedSqlFixture(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Release::getConnectionResolver();
        $this->previousDispatcher = Release::getEventDispatcher();
        $container = new Application(dirname((new ReflectionClass(PredbIdentityLookup::class))->getFileName(), 4));
        $container->instance('config', new Repository);
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->bind('db.schema', fn () => $this->database->getConnection()->getSchemaBuilder());
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        DB::statement('CREATE TABLE par2_rename_progress (releases_id INTEGER PRIMARY KEY)');
        DB::statement("CREATE TABLE predb (id INTEGER PRIMARY KEY NOT NULL, title VARCHAR NOT NULL, filename VARCHAR NOT NULL DEFAULT '')");
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, predb_id INTEGER DEFAULT 0, fromname TEXT, groups_id INTEGER DEFAULT 1, categories_id INTEGER DEFAULT 10, size INTEGER DEFAULT 5000, nfostatus INTEGER DEFAULT 1, isrenamed INTEGER DEFAULT 0, proc_nfo INTEGER DEFAULT 0, proc_files INTEGER DEFAULT 0, proc_par2 INTEGER DEFAULT 0, proc_uid INTEGER DEFAULT 0, proc_srr INTEGER DEFAULT 0, proc_hash16k INTEGER DEFAULT 0, proc_crc32 INTEGER DEFAULT 0, additional_pack_evidence TEXT NULL)');
        DB::statement('CREATE TABLE release_files (releases_id INTEGER, name TEXT, crc32 TEXT)');
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
    }

    private function tearDownCertifiedSqlFixture(): void
    {
        try {
            $this->database->getConnection()->disconnect();
        } finally {
            if ($this->previousResolver !== null) {
                Release::setConnectionResolver($this->previousResolver);
            } else {
                Release::unsetConnectionResolver();
            }
            if ($this->previousDispatcher === null) {
                Release::unsetEventDispatcher();
            } else {
                Release::setEventDispatcher($this->previousDispatcher);
            }
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->previousFacadeApplication);
            Container::setInstance($this->previousContainer);
        }
    }

    private function certifySqlFixture(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }
}
