<?php

declare(strict_types=1);

namespace Tests\Unit\Predb;

use App\Facades\Search;
use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Services\Predb\Feeds\PredbFeedImporter;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\Termwind;

final class PredbFeedImporterIdentityTest extends TestCase
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
    #[DataProvider('enrichmentModes')]
    public function overlong_identity_cannot_enrich_or_nuke_an_existing_canonical_prefix(bool $dryRun, bool $enrichOnly): void
    {
        $canonical = $this->canonicalPrefix();
        DB::table('predb')->insert(['id' => 7, 'title' => $canonical, 'source' => 'trusted-existing']);
        $before = (array) DB::table('predb')->where('id', 7)->first();
        $entry = new PredbFeedEntry(title: $canonical.'-OTHER', source: 'different-release', category: 'WRONG', size: '999MB', files: '99', predate: CarbonImmutable::parse('2001-01-01T00:00:00Z'), nuked: Predb::PRE_NUKED, nukeReason: 'different.release', enrichOnly: $enrichOnly);

        $result = (new PredbFeedImporter)->import([$entry], $dryRun);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame($before, (array) DB::table('predb')->where('id', 7)->first());
        $this->assertSame([], $this->search->documents);
    }

    /** @return array<string, array{bool, bool}> */
    public static function enrichmentModes(): array
    {
        return ['catalogue update' => [false, false], 'info-only update' => [false, true], 'catalogue preview' => [true, false], 'info-only preview' => [true, true]];
    }

    #[Test]
    public function overlong_identity_cannot_insert_index_or_exact_link_a_fabricated_prefix(): void
    {
        $canonical = $this->canonicalPrefix();
        DB::table('releases')->insert(['id' => 42, 'searchname' => $canonical, 'predb_id' => 0]);

        $result = (new PredbFeedImporter)->import([new PredbFeedEntry(title: $canonical.'-OTHER', source: 'different-release')]);
        Predb::checkPre();

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, DB::table('predb')->count());
        $this->assertSame(0, DB::table('releases')->where('id', 42)->value('predb_id'));
        $this->assertSame([], $this->search->documents);
    }

    #[Test]
    public function dry_run_skips_overlong_identity_without_aliasing_a_later_valid_prefix(): void
    {
        $canonical = $this->canonicalPrefix();
        $importer = new PredbFeedImporter;

        $rejected = $importer->import([new PredbFeedEntry(title: $canonical.'-OTHER', source: 'different-release')], dryRun: true);
        $accepted = $importer->import([new PredbFeedEntry(title: $canonical, source: 'valid-release')], dryRun: true);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $rejected);
        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0], $accepted);
        $this->assertSame(0, DB::table('predb')->count());
        $this->assertSame([], $this->search->documents);
    }

    #[Test]
    #[DataProvider('multibyteBoundaries')]
    public function multibyte_identity_limit_counts_characters_after_existing_boundary_trim(string $title, bool $accepted): void
    {
        $result = (new PredbFeedImporter)->import([new PredbFeedEntry(title: " \t".$title."\r\n", source: 'valid-release')]);

        $this->assertSame($accepted ? ['inserted' => 1, 'updated' => 0, 'skipped' => 0] : ['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame($accepted ? $title : null, DB::table('predb')->value('title'));
        $this->assertSame($accepted ? [$title] : [], array_column($this->search->documents, 'title'));
    }

    /** @return array<string, array{string, bool}> */
    public static function multibyteBoundaries(): array
    {
        return ['255 characters' => [str_repeat('é', 251).'-GRP', true], '256 characters' => [str_repeat('é', 252).'-GRP', false]];
    }

    #[Test]
    #[DataProvider('existingRows')]
    public function supported_identity_preserves_existing_metadata_field_limits(bool $existing): void
    {
        $title = 'Valid.Metadata-GRP';
        if ($existing) {
            DB::table('predb')->insert(['title' => $title, 'source' => 'trusted-existing']);
        }
        $entry = new PredbFeedEntry(title: " \t".$title."\r\n", source: str_repeat('s', 51), category: str_repeat('c', 256), size: str_repeat('9', 51), files: str_repeat('8', 51), predate: CarbonImmutable::parse('2026-10-11T12:00:00Z'), nuked: Predb::PRE_NUKED, nukeReason: str_repeat('r', 256));

        $result = (new PredbFeedImporter)->import([$entry]);
        $row = (array) DB::table('predb')->first();

        $this->assertSame($existing ? ['inserted' => 0, 'updated' => 1, 'skipped' => 0] : ['inserted' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $this->assertSame($title, $row['title']);
        $this->assertSame($existing ? 'trusted-existing' : str_repeat('s', 50), $row['source']);
        $this->assertSame(str_repeat('c', 255), $row['category']);
        $this->assertSame(str_repeat('9', 50), $row['size']);
        $this->assertSame(str_repeat('8', 50), $row['files']);
        $this->assertSame('2026-10-11 12:00:00', $row['predate']);
        $this->assertSame(Predb::PRE_NUKED, $row['nuked']);
        $this->assertSame(str_repeat('r', 255), $row['nukereason']);
        $this->assertSame([$title], array_column($this->search->documents, 'title'));
    }

    /** @return array<string, array{bool}> */
    public static function existingRows(): array
    {
        return ['new row' => [false], 'existing row' => [true]];
    }

    private function canonicalPrefix(): string
    {
        return 'Valid.Scene.'.str_repeat('A', 237).'-GROUP';
    }
}
