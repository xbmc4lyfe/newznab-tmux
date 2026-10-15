<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class PredbFulltextRetryQueueTest extends TestCase
{
    public function test_separate_limit_one_commands_reach_a_later_exact_title_and_retry_the_unfinished_title(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $nextTitle = 'Next.Scene.Release.2026-GROUP';
        $database = $this->database();
        $database->insert('INSERT INTO predb VALUES (7, ?, "synthetic", 0, "2020-01-01", "2020-01-01"), (8, ?, "synthetic", 0, "2020-01-01", "2020-01-01")', [$title, $nextTitle]);
        for ($id = 1; $id <= 211; $id++) {
            $name = $id === 211 ? $title : $title.$id;
            $database->insert('INSERT INTO releases (id, name, searchname, fromname, groups_id, categories_id, predb_id, guid) VALUES (?, ?, ?, "poster", 1, 7010, 0, ?)', [$id, $name, $name, 'guid-'.$id]);
        }
        $database->insert('INSERT INTO releases (id, name, searchname, fromname, groups_id, categories_id, predb_id, guid) VALUES (500, ?, ?, "poster", 1, 7010, 0, "guid-500")', [$nextTitle, $nextTitle]);
        $attempted = [];
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchReleases')->never();
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->andReturnUsing(static function (string $queryTitle, int $limit, int $afterId) use ($title, &$attempted): array {
            if ($afterId === 0) {
                $attempted[] = $queryTitle;
            }
            $ids = $queryTitle === $title ? range(1, 211) : [500];
            $remaining = array_values(array_filter($ids, static fn (int $id): bool => $id > $afterId));

            return ['ids' => array_slice($remaining, 0, $limit), 'available' => true, 'has_more' => count($remaining) > $limit];
        });
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())->method('updatePredbCandidate')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 500), $nextTitle, 8, 'synthetic', false)->willReturn(true);
        $updates->expects($this->never())->method('updateRelease');
        $this->bindServices($database, $updates);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])
            ->expectsOutputToContain('incomplete')->assertFailed();
        $this->assertSame(0, (int) $database->scalar('SELECT searched FROM predb WHERE id = 7'));

        $search = Search::getFacadeRoot();
        $previousApplication = $this->app;
        $this->refreshApplication();
        $this->assertNotSame($previousApplication, $this->app);
        $this->configureDatabase($database);
        Search::swap($search);
        $this->bindServices($database, $updates);
        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();
        $this->assertSame([$title, $nextTitle], $attempted);
        $this->assertSame(0, (int) $database->scalar('SELECT searched FROM predb WHERE id = 7'));
        $this->assertSame(1, (int) $database->scalar('SELECT searched FROM predb WHERE id = 8'));

        $this->bindServices($database, $updates);
        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertFailed();
        $this->assertSame([$title, $nextTitle, $title], $attempted);
        $this->assertSame(0, (int) $database->scalar('SELECT searched FROM predb WHERE id = 7'));
    }

    public function test_same_shard_reservations_are_disjoint_and_new_imports_cannot_prevent_retry_wrap(): void
    {
        $database = $this->database();
        $this->insertPredb($database, [1, 2, 3, 4]);

        $first = (new NameFixingQueryService($database))->reservePredbBatch(1, 1, 2);
        $this->insertPredb($database, [5, 6]);
        $second = (new NameFixingQueryService($database))->reservePredbBatch(1, 1, 2);
        $third = (new NameFixingQueryService($database))->reservePredbBatch(1, 1, 2);

        $this->assertSame([1, 2], array_column($first, 'predb_id'));
        $this->assertSame([3, 4], array_column($second, 'predb_id'));
        $this->assertSame([1, 2], array_column($third, 'predb_id'));
        $this->assertSame(6, (int) $database->scalar('SELECT COUNT(*) FROM predb WHERE searched = 0'));
    }

    public function test_worker_shards_keep_independent_retry_positions(): void
    {
        $database = $this->database();
        $this->insertPredb($database, [1, 2, 3, 4]);
        $queries = new NameFixingQueryService($database);

        $this->assertSame([2], array_column($queries->reservePredbBatch(1, 2, 1), 'predb_id'));
        $this->assertSame([1], array_column($queries->reservePredbBatch(2, 2, 1), 'predb_id'));
        $this->assertSame([4], array_column($queries->reservePredbBatch(1, 2, 1), 'predb_id'));
        $this->assertSame([3], array_column($queries->reservePredbBatch(2, 2, 1), 'predb_id'));
        $this->assertSame([2], array_column($queries->reservePredbBatch(1, 2, 1), 'predb_id'));
    }

    public function test_a_busy_shard_fails_without_reserving_or_marking_a_title(): void
    {
        $database = $this->database();
        $this->insertPredb($database, [1, 2]);
        $lock = Cache::store('database')->lock('predb:fulltext:v1:1:0:lock', 60);
        $this->assertTrue($lock->get());

        try {
            (new NameFixingQueryService($database))->reservePredbBatch(1, 1, 1);
            $this->fail('A concurrent reservation must wait for the shard owner.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Another worker', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame([1], array_column((new NameFixingQueryService($database))->reservePredbBatch(1, 1, 1), 'predb_id'));
        $this->assertSame(2, (int) $database->scalar('SELECT COUNT(*) FROM predb WHERE searched = 0'));
    }

    public function test_reservations_preserve_title_and_date_eligibility_and_empty_queues_resume_after_import(): void
    {
        $database = $this->database();
        $this->travelTo('2026-10-11 12:00:00');
        $database->insert('INSERT INTO predb VALUES (1, "Old.Scene.Title-GRP", "test", 0, "2026-10-09", "2026-10-11"),
            (2, "Undated.Scene.Title-GRP", "test", 0, NULL, "2026-10-09"),
            (3, "Recent.Scene.Title-GRP", "test", 0, "2026-10-11", "2026-10-09"),
            (4, "Recently.Imported.Title-GRP", "test", 0, NULL, "2026-10-11"),
            (5, "Already.Searched.Title-GRP", "test", 1, "2026-10-09", "2026-10-09"),
            (6, "too-short", "test", 0, "2026-10-09", "2026-10-09"),
            (7, "Title.With A.Space-GRP", "test", 0, "2026-10-09", "2026-10-09")');
        $queries = new NameFixingQueryService($database);

        $rows = $queries->reservePredbBatch(1, 1, 20);

        $this->assertSame([1, 2], array_column($rows, 'predb_id'));
        $database->update('UPDATE predb SET searched = 1 WHERE id IN (1, 2)');
        $this->assertSame([], $queries->reservePredbBatch(1, 1, 20));
        $this->insertPredb($database, [8]);
        $this->assertSame([8], array_column($queries->reservePredbBatch(1, 1, 20), 'predb_id'));
    }

    public function test_backend_failure_aborts_without_marking_reserved_rows_and_they_retry_on_wrap(): void
    {
        $database = $this->database();
        $this->insertPredb($database, [1, 2]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        Search::shouldReceive('isAvailable')->once()->andReturnFalse();
        Search::shouldReceive('searchReleases')->never();
        $this->bindServices($database, $updates);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 2])->assertFailed();

        $this->assertSame(2, (int) $database->scalar('SELECT COUNT(*) FROM predb WHERE searched = 0'));
        $this->assertSame([1, 2], array_column((new NameFixingQueryService($database))->reservePredbBatch(1, 1, 2), 'predb_id'));
    }

    public function test_missing_persistent_cache_tables_fail_instead_of_repeating_the_oldest_title(): void
    {
        $database = $this->database();
        $this->insertPredb($database, [1]);
        $database->statement('DROP TABLE cache_locks');
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        Search::shouldReceive('isAvailable')->never();
        $this->bindServices($database, $updates);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])
            ->expectsOutputToContain('cache_locks')->assertFailed();

        $this->assertDatabaseHas('predb', ['id' => 1, 'searched' => 0]);
    }

    /** @param list<int> $ids */
    private function insertPredb(SQLiteConnection $database, array $ids): void
    {
        foreach ($ids as $id) {
            $database->insert('INSERT INTO predb VALUES (?, ?, "synthetic", 0, "2020-01-01", "2020-01-01")', [$id, 'Scene.Release.Title.2026-GROUP'.$id]);
        }
    }

    private function bindServices(SQLiteConnection $database, ReleaseUpdateService $updates): void
    {
        $queries = new NameFixingQueryService($database);
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
    }

    private function database(): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE releases (additional_pack_evidence TEXT DEFAULT NULL, id INTEGER PRIMARY KEY, name TEXT, searchname TEXT, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, guid TEXT)');
        $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, source TEXT, searched INTEGER, predate TEXT, imported_at TEXT)');
        $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
        $pdo->exec('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
        $pdo->sqliteCreateFunction('regexp', static fn (string $pattern, string $subject): int => (int) preg_match('~'.$pattern.'~', $subject), 2);
        $pdo->sqliteCreateFunction('MOD', static fn (int $value, int $divisor): int => $value % $divisor, 2);
        $database = new class($pdo) extends SQLiteConnection
        {
            /**
             * @param  string  $query
             * @param  array<int|string, mixed>  $bindings
             * @param  array<int, mixed>  $fetchUsing
             * @return list<object>
             */
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                $query = str_replace("ESCAPE '\\\\'", "ESCAPE '\\'", $query);

                return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
            }
        };
        $this->configureDatabase($database);

        return $database;
    }

    private function configureDatabase(SQLiteConnection $database): void
    {
        config(['database.default' => 'retry_queue', 'database.connections.retry_queue' => ['driver' => 'retry_queue']]);
        DB::extend('retry_queue', static fn (): SQLiteConnection => $database);
    }
}
