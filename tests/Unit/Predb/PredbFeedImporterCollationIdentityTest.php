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
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic identities exercise database collation; these are not observed affected releases. */
final class PredbFeedImporterCollationIdentityTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    private ?DispatcherContract $previousDispatcher;

    private object $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        Model::unsetEventDispatcher();
        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new Repository);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $this->database = new Capsule($app);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $app->instance('db', $this->database->getDatabaseManager());
        $pdo = PDO::connect('sqlite::memory:');
        $this->assertInstanceOf(Sqlite::class, $pdo);
        $this->database->getConnection()->setPdo($pdo);
        $pdo->createCollation('mysql_unicode_ci', static function (string $left, string $right): int {
            $fold = static fn (string $title): string => rtrim(mb_strtolower(str_replace(['é', 'É', 'ß', 'ẞ', "\u{00a0}"], ['e', 'E', 'ss', 'SS', ' '], $title), 'UTF-8'), ' ');

            return strcmp($fold($left), $fold($right));
        });
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT COLLATE mysql_unicode_ci NOT NULL UNIQUE, source TEXT NOT NULL DEFAULT "", category TEXT NULL, size TEXT NULL, files TEXT NULL, predate TEXT NULL, nuked INTEGER NOT NULL DEFAULT 0, nukereason TEXT NULL, filename TEXT NOT NULL DEFAULT "", searched INTEGER NOT NULL DEFAULT 0)');
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
        \Mockery::close();
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

    #[DataProvider('updateModes')]
    public function test_distinct_accent_literal_cannot_enrich_nuke_or_index_a_collation_candidate(bool $dryRun, bool $enrichOnly): void
    {
        $this->seedCanonical();
        $before = (array) DB::table('predb')->first();
        $entry = new PredbFeedEntry(title: 'Café.Movie.2026-GRP', source: 'synthetic-other', category: $enrichOnly ? null : 'WRONG', size: '999MB', files: '99', nuked: $enrichOnly ? Predb::PRE_NONUKE : Predb::PRE_NUKED, nukeReason: $enrichOnly ? null : 'different.release', enrichOnly: $enrichOnly);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([$entry], $dryRun));
        $this->assertSame($before, (array) DB::table('predb')->first());
        $this->assertSame([], $this->search->documents);
    }

    /** @return array<string, array{bool, bool}> */
    public static function updateModes(): array
    {
        return ['real PRE' => [false, false], 'real INFO' => [false, true], 'preview PRE' => [true, false], 'preview INFO' => [true, true]];
    }

    public function test_distinct_alias_without_details_does_not_reindex_a_nonexact_existing_row(): void
    {
        $this->seedCanonical();

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([new PredbFeedEntry(title: 'Café.Movie.2026-GRP', source: 'synthetic-other')]));
        $this->assertSame([], $this->search->documents);
    }

    #[DataProvider('previewModes')]
    public function test_case_only_identity_keeps_valid_metadata_updates_and_repeated_skip_counts(bool $dryRun): void
    {
        $this->seedCanonical();
        $importer = new PredbFeedImporter;
        $entry = new PredbFeedEntry(title: " \tcAFE.mOVIE.2026-grp\r\n", source: 'synthetic-valid', category: 'RIGHT', size: '10MB', files: '2', nuked: Predb::PRE_NUKED, nukeReason: 'valid.update');

        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0], $importer->import([$entry], $dryRun));
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $importer->import([$entry], $dryRun));
        $this->assertSame('Cafe.Movie.2026-GRP', DB::table('predb')->value('title'));
        $this->assertSame($dryRun ? null : 'RIGHT', DB::table('predb')->value('category'));
        $this->assertSame($dryRun ? 0 : Predb::PRE_NUKED, DB::table('predb')->value('nuked'));
        $this->assertCount($dryRun ? 0 : 2, $this->search->documents);
    }

    /** @return array<string, array{bool}> */
    public static function previewModes(): array
    {
        return ['real' => [false], 'preview' => [true]];
    }

    public function test_rejected_accent_preview_cannot_poison_later_canonical_shadow_metadata(): void
    {
        $this->seedCanonical();
        $before = (array) DB::table('predb')->first();
        $importer = new PredbFeedImporter;

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $importer->import([new PredbFeedEntry(title: 'Café.Movie.2026-GRP', source: 'wrong', category: 'WRONG', nuked: Predb::PRE_NUKED, nukeReason: 'wrong')], true));
        $entry = new PredbFeedEntry(title: 'Cafe.Movie.2026-GRP', source: 'right', category: 'RIGHT');
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0], $importer->import([$entry], true));
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $importer->import([$entry], true));
        $this->assertSame($before, (array) DB::table('predb')->first());
        $this->assertSame([], $this->search->documents);
    }

    #[DataProvider('shadowSpellings')]
    public function test_new_shadow_counts_match_real_uniqueness_without_transliteration_aliases(bool $dryRun, string $first, string $second, bool $sameDatabaseIdentity): void
    {
        $importer = new PredbFeedImporter;
        $this->assertSame(['inserted' => 1, 'updated' => 0, 'skipped' => 0], $importer->import([new PredbFeedEntry(title: $first, source: 'first', category: 'FIRST')], $dryRun));
        $this->assertSame($sameDatabaseIdentity ? ['inserted' => 0, 'updated' => 0, 'skipped' => 1] : ['inserted' => 1, 'updated' => 0, 'skipped' => 0], $importer->import([new PredbFeedEntry(title: $second, source: 'second', category: 'SECOND')], $dryRun));
        $this->assertSame(['inserted' => 0, 'updated' => 1, 'skipped' => 0], $importer->import([new PredbFeedEntry(title: $first, source: 'first', size: '10MB')], $dryRun));
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], $importer->import([new PredbFeedEntry(title: $second, source: 'second', category: 'SECOND')], $dryRun));
        $this->assertSame($dryRun ? 0 : ($sameDatabaseIdentity ? 1 : 2), DB::table('predb')->count());
        if ($dryRun) {
            $this->assertSame([], $this->search->documents);
        } else {
            $this->assertSame('FIRST', DB::table('predb')->where('title', $first)->value('category'));
        }
    }

    /** @return array<string, array{bool, string, string, bool}> */
    public static function shadowSpellings(): array
    {
        return [
            'real accent collision' => [false, 'Cafe.Movie-GRP', 'Café.Movie-GRP', true],
            'preview accent collision' => [true, 'Cafe.Movie-GRP', 'Café.Movie-GRP', true],
            'real Latin versus Greek' => [false, 'Alpha.Movie-GRP', 'Αlpha.Movie-GRP', false],
            'preview Latin versus Greek' => [true, 'Alpha.Movie-GRP', 'Αlpha.Movie-GRP', false],
            'real expansion collision' => [false, 'Strauss.Movie-GRP', 'Strauß.Movie-GRP', true],
            'preview expansion collision' => [true, 'Strauss.Movie-GRP', 'Strauß.Movie-GRP', true],
            'real trailing NBSP collision' => [false, 'Space.Movie-GRP', "Space.Movie-GRP\u{00a0}", true],
            'preview trailing NBSP collision' => [true, 'Space.Movie-GRP', "Space.Movie-GRP\u{00a0}", true],
            'real full expanding weights' => [false, str_repeat('ß', 254).'A', str_repeat('ß', 254).'B', false],
            'preview full expanding weights' => [true, str_repeat('ß', 254).'A', str_repeat('ß', 254).'B', false],
        ];
    }

    #[DataProvider('raceIdentities')]
    public function test_unique_index_race_fallback_revalidates_the_literal_identity(string $title, bool $sameLiteral): void
    {
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $inserted = false;
        $connection->listen(function (QueryExecuted $query) use ($title, &$inserted): void {
            if (! $inserted && str_starts_with(strtolower($query->sql), 'select') && in_array($title, $query->bindings, true)) {
                $inserted = true;
                $this->seedCanonical();
            }
        });

        $result = (new PredbFeedImporter)->import([new PredbFeedEntry(title: $title, source: 'incoming', category: 'RIGHT', nuked: Predb::PRE_NUKED, nukeReason: 'valid.only.for.same.literal')]);

        $this->assertTrue($inserted);
        $this->assertSame($sameLiteral ? ['inserted' => 0, 'updated' => 1, 'skipped' => 0] : ['inserted' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertSame(1, DB::table('predb')->count());
        $this->assertSame($sameLiteral ? 'RIGHT' : null, DB::table('predb')->value('category'));
        $this->assertSame($sameLiteral ? Predb::PRE_NUKED : 0, DB::table('predb')->value('nuked'));
        $this->assertCount($sameLiteral ? 1 : 0, $this->search->documents);
    }

    /** @return array<string, array{string, bool}> */
    public static function raceIdentities(): array
    {
        return ['nonexact accent' => ['Café.Movie.2026-GRP', false], 'valid case-only' => ['cAFE.mOVIE.2026-grp', true]];
    }

    #[DataProvider('mutationModes')]
    public function test_title_replacement_after_lookup_cannot_receive_metadata_or_index(bool $withChanges): void
    {
        $this->seedCanonical();
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $replaced = false;
        $connection->listen(function (QueryExecuted $query) use (&$replaced): void {
            if (! $replaced && str_starts_with(strtolower($query->sql), 'select') && in_array('Cafe.Movie.2026-GRP', $query->bindings, true)) {
                $replaced = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'Café.Movie.2026-GRP']);
            }
        });
        $entry = new PredbFeedEntry(title: 'Cafe.Movie.2026-GRP', source: 'incoming', category: $withChanges ? 'WRONG' : null, size: $withChanges ? '999MB' : null, files: $withChanges ? '99' : null, predate: $withChanges ? new CarbonImmutable('2026-01-01') : null, nuked: $withChanges ? Predb::PRE_NUKED : Predb::PRE_NONUKE, nukeReason: $withChanges ? 'wrong' : null);

        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([$entry]));
        $this->assertTrue($replaced);
        $row = DB::table('predb')->where('id', 7)->first();
        $this->assertSame('Café.Movie.2026-GRP', $row->title);
        foreach (['category', 'size', 'files', 'predate', 'nukereason'] as $field) {
            $this->assertNull($row->{$field});
        }
        $this->assertSame(0, $row->nuked);
        $this->assertSame([], $this->search->documents);
    }

    #[DataProvider('replacementActions')]
    public function test_title_replacement_or_deletion_between_updates_blocks_remaining_fields_and_index(bool $delete): void
    {
        $this->seedCanonical();
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $changed = false;
        $connection->listen(function (QueryExecuted $query) use (&$changed, $delete): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'update') && in_array('RIGHT', $query->bindings, true)) {
                $changed = true;
                $row = DB::table('predb')->where('id', 7);
                if ($delete) {
                    $row->delete();
                } else {
                    $row->update(['title' => 'Café.Movie.2026-GRP']);
                }
            }
        });

        $entry = new PredbFeedEntry(title: 'Cafe.Movie.2026-GRP', source: 'incoming', category: 'RIGHT', size: '999MB', files: '99', predate: new CarbonImmutable('2026-01-01'), nuked: Predb::PRE_NUKED, nukeReason: 'wrong.for.replacement');
        $this->assertSame(['inserted' => 0, 'updated' => 0, 'skipped' => 1], (new PredbFeedImporter)->import([$entry]));
        $this->assertTrue($changed);
        $row = DB::table('predb')->where('id', 7)->first();
        if ($delete) {
            $this->assertNull($row);
        } else {
            $this->assertNotNull($row);
            $this->assertSame('RIGHT', $row->category);
            foreach (['size', 'files', 'predate', 'nukereason'] as $field) {
                $this->assertNull($row->{$field});
            }
            $this->assertSame(0, $row->nuked);
        }
        $this->assertSame([], $this->search->documents);
    }

    /** @return array<string, array{bool}> */
    public static function replacementActions(): array
    {
        return ['replaced' => [false], 'deleted' => [true]];
    }

    /** @return array<string, array{bool}> */
    public static function mutationModes(): array
    {
        return ['metadata and nuke' => [true], 'idempotent index' => [false]];
    }

    #[DataProvider('mysqlDrivers')]
    public function test_unsupported_mysql_collation_fails_before_preview_lookup_or_shadow_changes(string $driver): void
    {
        $connection = \Mockery::mock(MySqlConnection::class.'[select]', [$this->database->getConnection()->getPdo(), '', '', ['driver' => $driver]]);
        $connection->shouldReceive('select')->once()->andReturn([
            (object) ['title' => ' ', 'weight_key' => '0020', 'title_collation' => 'utf8mb4_bin'],
            (object) ['title' => 'Synthetic.Movie-GRP', 'weight_key' => '1234', 'title_collation' => 'utf8mb4_bin'],
        ]);
        $app = Container::getInstance();
        $this->assertInstanceOf(Application::class, $app);
        $app->instance('db', new DatabaseManager($app, new ConnectionFactory($app)));
        $manager = $app->make('db');
        $manager->extend('synthetic', static fn () => $connection);
        $app->make('config')->set('database.default', 'synthetic');
        $app->make('config')->set('database.connections.synthetic', ['driver' => 'synthetic']);
        Facade::clearResolvedInstance('db');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified utf8mb4_unicode_ci');
        try {
            (new PredbFeedImporter)->import([new PredbFeedEntry(title: 'Synthetic.Movie-GRP', source: 'synthetic')], true);
        } finally {
            $app->make('config')->set('database.default', 'default');
        }
    }

    /** @return array<string, array{string}> */
    public static function mysqlDrivers(): array
    {
        return ['MySQL' => ['mysql'], 'MariaDB' => ['mariadb']];
    }

    private function seedCanonical(): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => 'Cafe.Movie.2026-GRP', 'source' => 'trusted-existing']);
    }
}
