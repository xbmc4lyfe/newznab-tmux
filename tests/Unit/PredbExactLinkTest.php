<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Predb;
use App\Models\Release;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use PDO;
use Pdo\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Termwind\Termwind;

final class PredbExactLinkTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private ?ConnectionResolverInterface $previousResolver;

    private OutputInterface $previousRenderer;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousResolver = Release::getConnectionResolver();
        $this->previousRenderer = Termwind::getRenderer();
        $this->output = new BufferedOutput;
        Termwind::renderUsing($this->output);

        $container = new Container;
        $container->instance('config', new Repository(['nntmux' => ['echocli' => false]]));
        Container::setInstance($container);

        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $connection = $this->database->getConnection();
        $pdo = PDO::connect('sqlite::memory:');
        $this->assertInstanceOf(Sqlite::class, $pdo);
        $connection->setPdo($pdo);
        $pdo->createCollation('mysql_unicode_ci', static function (string $left, string $right): int {
            $left = mb_strtolower(str_replace(['é', 'É'], ['e', 'E'], $left), 'UTF-8');
            $right = mb_strtolower(str_replace(['é', 'É'], ['e', 'E'], $right), 'UTF-8');

            return strcmp($left, $right);
        });
        $connection->statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT COLLATE mysql_unicode_ci NOT NULL, searched INTEGER NOT NULL DEFAULT 0)');
        $connection->statement('CREATE UNIQUE INDEX ix_predb_title ON predb(title)');
        $connection->statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, searchname TEXT COLLATE mysql_unicode_ci NOT NULL, predb_id INTEGER NOT NULL DEFAULT 0, isrenamed INTEGER NOT NULL DEFAULT 0, adddate TEXT)');
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Release::setConnectionResolver($this->previousResolver);
        } else {
            Release::unsetConnectionResolver();
        }
        $this->database->getConnection()->disconnect();
        Container::setInstance($this->previousContainer);
        Termwind::renderUsing($this->previousRenderer);
        parent::tearDown();
    }

    #[DataProvider('exactTitles')]
    public function test_links_exact_case_insensitive_title_for_recovered_release(string $predbTitle, string $releaseTitle): void
    {
        $this->seedTitles($predbTitle, $releaseTitle);

        Predb::checkPre();

        $this->assertSame(7, $this->linkedPredbId());
        $this->assertSame($releaseTitle, $this->database->getConnection()->table('releases')->where('id', 42)->value('searchname'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function exactTitles(): array
    {
        return [
            'ascii case' => ['Leon.1994.1080p.BluRay.x264-GROUP', 'leon.1994.1080p.bluray.x264-group'],
            'accented case' => ['Léon.1994.1080p.BluRay.x264-GROUP', 'LÉON.1994.1080p.BLURAY.X264-group'],
        ];
    }

    public function test_rejects_accent_difference_after_sql_collation_returns_candidate(): void
    {
        $this->seedTitles('Léon.1994.1080p.BluRay.x264-GROUP', 'Leon.1994.1080p.BluRay.x264-GROUP');
        $this->assertSame(1, $this->database->getConnection()->table('predb')
            ->join('releases', 'predb.title', '=', 'releases.searchname')->count());

        Predb::checkPre();

        $this->assertSame(0, $this->linkedPredbId());
    }

    #[DataProvider('differentTitles')]
    public function test_does_not_link_a_different_canonical_title(string $predbTitle, string $releaseTitle): void
    {
        $this->seedTitles($predbTitle, $releaseTitle);

        Predb::checkPre();

        $this->assertSame(0, $this->linkedPredbId());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function differentTitles(): array
    {
        return [
            'year difference' => ['Leon.1994.1080p.BluRay.x264-GROUP', 'Leon.1995.1080p.BluRay.x264-GROUP'],
            'episode difference' => ['Example.Show.S01E02.1080p.WEB-DL.x264-GROUP', 'Example.Show.S01E03.1080p.WEB-DL.x264-GROUP'],
            'group difference' => ['Leon.1994.1080p.BluRay.x264-GROUP', 'Leon.1994.1080p.BluRay.x264-OTHER'],
        ];
    }

    public function test_preserves_existing_predb_link(): void
    {
        $this->seedTitles('Leon.1994.1080p.BluRay.x264-GROUP', 'Leon.1994.1080p.BluRay.x264-GROUP', 88);

        Predb::checkPre();

        $this->assertSame(88, $this->linkedPredbId());
    }

    #[DataProvider('changedReleaseTitles')]
    public function test_does_not_attach_snapshot_title_after_release_name_changes(string $changedTitle): void
    {
        $this->seedTitles('Leon.1994.1080p.BluRay.x264-GROUP', 'Leon.1994.1080p.BluRay.x264-GROUP');
        config(['nntmux.echocli' => true]);
        $connection = $this->database->getConnection();
        $connection->setEventDispatcher(new Dispatcher);
        $changed = false;
        $connection->listen(function (QueryExecuted $query) use ($changedTitle, &$changed): void {
            if (! $changed && str_contains($query->sql, 'join "releases"')) {
                $changed = true;
                $this->database->getConnection()->table('releases')->where('id', 42)
                    ->update(['searchname' => $changedTitle]);
            }
        });

        ob_start();
        try {
            Predb::checkPre();
        } finally {
            ob_end_clean();
        }

        $this->assertTrue($changed);
        $this->assertSame($changedTitle, $connection->table('releases')->where('id', 42)->value('searchname'));
        $this->assertSame(0, $this->linkedPredbId());
        $this->assertStringContainsString('Matched 0 PreDB titles to release search names.', $this->output->fetch());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changedReleaseTitles(): array
    {
        return [
            'different movie' => ['Other.Movie.2005.1080p.BluRay.x264-GROUP'],
            'accent-only change' => ['Léon.1994.1080p.BluRay.x264-GROUP'],
        ];
    }

    private function seedTitles(string $predbTitle, string $releaseTitle, int $predbId = 0): void
    {
        $this->database->getConnection()->table('predb')->insert([
            'id' => 7, 'title' => $predbTitle, 'searched' => -1,
        ]);
        $this->database->getConnection()->table('releases')->insert([
            'id' => 42, 'searchname' => $releaseTitle, 'predb_id' => $predbId,
            'isrenamed' => 1, 'adddate' => '2020-01-01 00:00:00',
        ]);
    }

    private function linkedPredbId(): int
    {
        return (int) $this->database->getConnection()->table('releases')->where('id', 42)->value('predb_id');
    }
}
