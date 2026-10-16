<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Search;
use App\Services\IRCScraper;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic IRC bot lines exercise real parsing/SQL without constructing a network client. */
final class LegacyIrcIdentityTest extends TestCase
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
        $app = new Application(dirname(__DIR__, 2));
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
            $fold = static fn (string $title): string => mb_strtolower(str_replace(['é', 'É', 'ß', 'ẞ', "\u{00a0}"], ['e', 'E', 'ss', 'SS', ' '], $title), 'UTF-8');

            return strcmp($fold($left), $fold($right));
        });
        $pdo->createFunction('FROM_UNIXTIME', static fn (int $timestamp): string => gmdate('Y-m-d H:i:s', $timestamp));
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT COLLATE mysql_unicode_ci NOT NULL UNIQUE, source TEXT NOT NULL DEFAULT "", category TEXT NULL, size TEXT NULL, files TEXT NULL, predate TEXT NULL, nuked INTEGER NOT NULL DEFAULT 0, nukereason TEXT NULL, filename TEXT NOT NULL DEFAULT "", searched INTEGER NOT NULL DEFAULT 0)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, searchname TEXT, predb_id INTEGER)');
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
        parent::tearDown();
    }

    #[DataProvider('distinctAliases')]
    public function test_distinct_identity_cannot_replace_title_metadata_filename_nuke_or_prior_links(string $canonical, string $incoming): void
    {
        $this->seed($canonical);
        $before = (array) DB::table('predb')->first();
        $release = (array) DB::table('releases')->first();

        (new LegacyIrcIdentityHarness)->ingest($this->line($incoming));

        $this->assertSame($before, (array) DB::table('predb')->first());
        $this->assertSame($release, (array) DB::table('releases')->first());
        $this->assertSame([], $this->search->documents);
    }

    /** @return array<string, array{string,string}> */
    public static function distinctAliases(): array
    {
        return [
            'accent' => ['Cafe.Movie.2026-GRP', 'Café.Movie.2026-GRP'],
            'sharp s' => ['Strasse.Movie.2026-GRP', 'Straße.Movie.2026-GRP'],
            'NBSP' => ['Some Movie.2026-GRP', "Some\u{00a0}Movie.2026-GRP"],
        ];
    }

    public function test_case_only_update_keeps_legitimate_metadata_and_indexing(): void
    {
        $this->seed('Cafe.Movie.2026-GRP');

        (new LegacyIrcIdentityHarness)->ingest($this->line('cAFE.mOVIE.2026-grp'));

        $row = (array) DB::table('predb')->first();
        $this->assertSame('999MB', $row['size']);
        $this->assertSame('99', $row['files']);
        $this->assertSame('trusted', $row['source']);
        $this->assertSame('TV', $row['category']);
        $this->assertSame('Other.Filename.mkv', $row['filename']);
        $this->assertSame(2, $row['nuked']);
        $this->assertSame('different.release', $row['nukereason']);
        $this->assertSame('2026-10-10 10:00:00', $row['predate']);
        $this->assertCount(1, $this->search->documents);
        $this->assertSame($row['title'], $this->search->documents[0]['title']);
        $this->assertSame(7, DB::table('releases')->value('predb_id'));
    }

    public function test_normal_new_identity_is_inserted_and_indexed(): void
    {
        (new LegacyIrcIdentityHarness)->ingest($this->line('New.Movie.2026-GRP'));

        $this->assertSame('New.Movie.2026-GRP', DB::table('predb')->value('title'));
        $this->assertSame('Other.Filename.mkv', DB::table('predb')->value('filename'));
        $this->assertCount(1, $this->search->documents);
    }

    public function test_a_row_replaced_after_the_identity_lookup_cannot_be_enriched_or_reindexed(): void
    {
        $this->seed('Cafe.Movie.2026-GRP');
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $changed = false;
        $afterReplacement = null;
        $connection->listen(static function (QueryExecuted $query) use (&$changed, &$afterReplacement): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && in_array('Cafe.Movie.2026-GRP', $query->bindings, true)) {
                $changed = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'Café.Movie.2026-GRP']);
                $afterReplacement = (array) DB::table('predb')->first();
            }
        });

        (new LegacyIrcIdentityHarness)->ingest($this->line('Cafe.Movie.2026-GRP'));

        $this->assertTrue($changed);
        $this->assertSame($afterReplacement, (array) DB::table('predb')->first());
        $this->assertSame([], $this->search->documents);
    }

    public function test_case_only_identity_change_after_lookup_skips_the_stale_update_and_index_write(): void
    {
        $this->seed('Cafe.Movie.2026-GRP');
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $changed = false;
        $afterReplacement = null;
        $connection->listen(static function (QueryExecuted $query) use (&$changed, &$afterReplacement): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && in_array('Cafe.Movie.2026-GRP', $query->bindings, true)) {
                $changed = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'cAFE.Movie.2026-GRP']);
                $afterReplacement = (array) DB::table('predb')->first();
            }
        });

        (new LegacyIrcIdentityHarness)->ingest($this->line('Cafe.Movie.2026-GRP'));

        $this->assertTrue($changed);
        $this->assertSame($afterReplacement, (array) DB::table('predb')->first());
        $this->assertSame([], $this->search->documents);
    }

    public function test_deleted_row_replacement_cannot_receive_the_stale_update_or_index_write(): void
    {
        $this->seed('Cafe.Movie.2026-GRP');
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $changed = false;
        $replacement = null;
        $connection->listen(static function (QueryExecuted $query) use (&$changed, &$replacement): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && in_array('Cafe.Movie.2026-GRP', $query->bindings, true)) {
                $changed = true;
                DB::table('predb')->where('id', 7)->delete();
                DB::table('predb')->insert(['id' => 8, 'title' => 'Cafe.Movie.2026-GRP', 'source' => 'replacement']);
                $replacement = (array) DB::table('predb')->first();
            }
        });

        (new LegacyIrcIdentityHarness)->ingest($this->line('Cafe.Movie.2026-GRP'));

        $this->assertTrue($changed);
        $this->assertSame($replacement, (array) DB::table('predb')->first());
        $this->assertSame([], $this->search->documents);
        $this->assertSame(7, DB::table('releases')->value('predb_id'));
    }

    private function seed(string $title): void
    {
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'source' => 'trusted', 'category' => 'TV', 'size' => '10MB', 'files' => '2', 'predate' => '2026-01-01 00:00:00', 'filename' => 'Canonical.Filename.mkv']);
        DB::table('releases')->insert(['id' => 42, 'searchname' => $title, 'predb_id' => 7]);
    }

    private function line(string $title): string
    {
        return 'UPD: [DT: 2026-10-10 10:00:00] [TT: '.$title.'] [SC: synthetic] [CT: WRONG] [RQ: N/A] [SZ: 999MB] [FL: 99] [FN: Other.Filename.mkv] [NUKED: different.release]';
    }
}

final class LegacyIrcIdentityHarness extends IRCScraper
{
    public function __construct()
    {
        $this->_silent = true;
        $this->_debug = false;
        $this->_ignoredChannels = [];
        $this->_resetPreVariables();
    }

    public function ingest(string $line): void
    {
        $this->_channelData = ['message' => $line];
        $this->processChannelMessages();
    }
}
