<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Exceptions\IncompletePredbMatchException;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class PredbFulltextMonotonicLinkedPagingTest extends TestCase
{
    private const TITLE = 'Valid.Scene.Release.2026-GROUP';

    #[DataProvider('linkedEvidence')]
    public function test_link_confirmation_stops_only_after_a_canonical_hit(int $hitId, string $searchname, int $expectedQueries, bool $expectedMatched): void
    {
        $this->database('Unrelated.Subject', 'Unrelated.Searchname', self::TITLE);
        DB::table('releases')->delete();
        if ($hitId > 0) {
            DB::table('releases')->insert(['id' => $hitId, 'name' => self::TITLE, 'searchname' => $searchname, 'predb_id' => 7]);
        }
        $before = DB::table('releases')->get()->map(static fn (object $row): array => (array) $row)->all();
        $this->pages(10);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $matched = false;
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));
        $method = new ReflectionMethod($service, 'pagedLiteralPredbCandidates');

        $matches = $method->invokeArgs($service, [self::TITLE, 7, &$matched]);

        $queries = array_column(DB::connection()->getQueryLog(), 'query');
        $this->assertSame([], $matches);
        $this->assertSame($expectedMatched, $matched);
        $this->assertCount(10, array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'SELECT r.id AS releases_id')));
        $this->assertCount($expectedQueries, array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'SELECT r.searchname FROM releases r')));
        $this->assertSame($before, DB::table('releases')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame([], array_values(array_filter($queries, static fn (string $query): bool => preg_match('/^(update|insert|delete)/i', $query) === 1)));
    }

    public static function linkedEvidence(): array
    {
        return [
            'first page canonical link' => [1, self::TITLE, 1, true],
            'late canonical link on page eight' => [148, self::TITLE, 8, true],
            'no linked hit' => [0, '', 10, false],
            'linked literal mismatch' => [1, self::TITLE.'.Different', 10, false],
            'case only canonical link' => [1, strtoupper(self::TITLE), 1, true],
        ];
    }

    public function test_an_early_match_does_not_hide_an_incomplete_scan(): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        $this->pages(10, true);
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));
        $method = new ReflectionMethod($service, 'pagedLiteralPredbCandidates');
        $matched = false;
        try {
            $method->invokeArgs($service, [self::TITLE, 7, &$matched]);
            $this->fail('A canonical link cannot complete a truncated enumeration.');
        } catch (IncompletePredbMatchException $exception) {
            $this->assertStringContainsString('210-candidate scan budget', $exception->getMessage());
            $this->assertTrue($matched);
        }
    }

    public function test_an_early_link_does_not_skip_later_literal_candidates(): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        DB::table('releases')->insert(['id' => 190, 'guid' => 'later-guid', 'name' => self::TITLE, 'searchname' => 'Old.Name', 'predb_id' => 0]);
        $this->pages(10);
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));
        $method = new ReflectionMethod($service, 'pagedLiteralPredbCandidates');
        $matched = false;

        $matches = $method->invokeArgs($service, [self::TITLE, 7, &$matched]);

        $this->assertTrue($matched);
        $this->assertSame([190], array_column($matches, 'releases_id'));
        $this->assertDatabaseHas('releases', ['id' => 190, 'searchname' => 'Old.Name', 'predb_id' => 0]);
    }

    public function test_an_early_link_preserves_the_sixteen_literal_candidate_flood_cap(): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        for ($id = 22; $id < 38; $id++) {
            DB::table('releases')->insert(['id' => $id, 'guid' => 'candidate-'.$id, 'name' => self::TITLE, 'searchname' => 'Old.Name', 'predb_id' => 0]);
        }
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        $this->pages(2, true);
        $before = DB::table('releases')->get()->map(static fn (object $row): array => (array) $row)->all();
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));

        $result = $service->matchPredbFulltextWithCompletion((object) ['title' => self::TITLE, 'predb_id' => 7]);

        $this->assertSame(['updated' => -1, 'matched' => true, 'retry' => false], $result);
        $this->assertSame($before, DB::table('releases')->get()->map(static fn (object $row): array => (array) $row)->all());
        $this->assertSame(0, $service->getUpdateService()->fixed);
    }

    public function test_link_detection_uses_current_write_pdo_before_the_first_hit(): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        $read = new PDO('sqlite::memory:');
        $read->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT, searchname TEXT, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER)');
        $read->sqliteCreateFunction('like', static fn (): int => 0, 3);
        DB::connection()->setReadPdo($read);
        $this->pages(10);
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));
        $method = new ReflectionMethod($service, 'pagedLiteralPredbCandidates');
        $matched = false;

        $matches = $method->invokeArgs($service, [self::TITLE, 7, &$matched]);

        $this->assertSame([], $matches);
        $this->assertTrue($matched, 'The stale read connection has no link; canonical evidence must come from the write PDO.');
    }

    #[DataProvider('conflictingWrites')]
    public function test_an_early_link_preserves_current_candidate_write_guards(string $column, string|int $value, bool $retry): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        DB::table('releases')->insert(['id' => 2, 'guid' => 'original-candidate', 'name' => self::TITLE, 'searchname' => 'Old.Name', 'predb_id' => 0]);
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        for ($page = 0; $page < 10; $page++) {
            Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, $page * 21)
                ->andReturnUsing(static function () use ($page, $column, $value): array {
                    if ($page === 9) {
                        DB::table('releases')->where('id', 2)->update([$column => $value]);
                    }

                    return ['available' => true, 'ids' => range($page * 21 + 1, ($page + 1) * 21), 'has_more' => $page < 9];
                });
        }
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));

        $result = $service->matchPredbFulltextWithCompletion((object) ['title' => self::TITLE, 'predb_id' => 7]);

        $this->assertSame(['updated' => 0, 'matched' => true, 'retry' => $retry], $result);
        $this->assertDatabaseHas('releases', ['id' => 2, $column => $value, 'searchname' => 'Old.Name', 'videos_id' => 81]);
        $this->assertFalse($service->getUpdateService()->matched);
        $this->assertSame(0, $service->getUpdateService()->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function conflictingWrites(): array
    {
        return [
            'literal subject changed after confirmation' => ['name', strtoupper(self::TITLE), true],
            'release identity replaced after confirmation' => ['guid', 'replacement-candidate', true],
            'foreign established link after confirmation' => ['predb_id', 999, false],
        ];
    }

    public function test_an_early_link_does_not_hide_invalid_page_boundaries(): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, 0)
            ->andReturn(['available' => true, 'ids' => range(1, 21), 'has_more' => true]);
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, 21)
            ->andReturn(['available' => true, 'ids' => [21], 'has_more' => false]);
        $service = new NameFixingService(queries: new NameFixingQueryService(DB::connection()));
        $method = new ReflectionMethod($service, 'pagedLiteralPredbCandidates');
        $matched = false;
        $this->expectException(IncompletePredbMatchException::class);
        $this->expectExceptionMessage('candidate IDs did not advance in order');

        $method->invokeArgs($service, [self::TITLE, 7, &$matched]);
    }

    #[DataProvider('laterWrites')]
    public function test_command_completes_and_preserves_later_candidate_effects(string $searchname, bool $rename): void
    {
        $this->database(self::TITLE, self::TITLE, self::TITLE);
        $this->travelTo('2026-10-11 12:00:00');
        $pdo = DB::connection()->getPdo();
        $pdo->exec('ALTER TABLE predb ADD COLUMN source TEXT');
        $pdo->exec('ALTER TABLE predb ADD COLUMN searched INTEGER DEFAULT 0');
        $pdo->exec('ALTER TABLE predb ADD COLUMN predate TEXT');
        $pdo->exec('ALTER TABLE predb ADD COLUMN imported_at TEXT');
        $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
        $pdo->exec('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
        $pdo->sqliteCreateFunction('regexp', static fn (string $pattern, string $subject): int => (int) preg_match('~'.$pattern.'~', $subject), 2);
        $pdo->sqliteCreateFunction('MOD', static fn (int $value, int $divisor): int => $value % $divisor, 2);
        DB::table('predb')->where('id', 7)->update(['source' => 'synthetic', 'predate' => '2020-01-01', 'imported_at' => '2020-01-01']);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7]);
        DB::table('releases')->insert(['id' => 190, 'guid' => 'later-guid', 'name' => self::TITLE, 'searchname' => $searchname, 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = new ReleaseUpdateService(
            category: $this->createStub(CategorizationService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$synchronized): void {
                $synchronized[] = $id;
            })
        );
        $queries = new NameFixingQueryService(DB::connection());
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        $this->pages(10);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertDatabaseHas('releases', ['id' => 190, 'predb_id' => 7, 'searchname' => self::TITLE]);
        $this->assertTrue($updates->matched);
        $this->assertSame($rename ? 1 : 0, $updates->fixed);
        $this->assertSame($rename ? [190] : [], $synchronized);
        if ($rename) {
            Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        } else {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    public static function laterWrites(): array
    {
        return ['attachment' => [self::TITLE, false], 'rename' => ['Old.Name', true]];
    }

    private function pages(int $count, bool $incomplete = false): void
    {
        for ($page = 0; $page < $count; $page++) {
            Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, $page * 21)
                ->andReturn(['available' => true, 'ids' => range($page * 21 + 1, ($page + 1) * 21), 'has_more' => $incomplete || $page < $count - 1]);
        }
    }

    private function database(string $name, string $searchname, ?string $canonicalTitle = null): void
    {
        config(['nntmux.echocli' => false]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->sqliteCreateFunction('like', static function (string $pattern, string $subject, string $escape): int {
            $regex = '';
            $escaped = false;
            foreach (mb_str_split($pattern) as $character) {
                if (! $escaped && $character === substr($escape, 0, 1)) {
                    $escaped = true;

                    continue;
                }
                $regex .= $escaped ? preg_quote($character, '~') : match ($character) {
                    '%' => '.*',
                    '_' => '.',
                    default => preg_quote($character, '~'),
                };
                $escaped = false;
            }

            return (int) preg_match('~^'.$regex.'$~ius', $subject);
        }, 3);
        DB::connection()->setPdo($pdo);
        DB::connection()->setReadPdo($pdo);
        $pdo->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, videos_id INTEGER DEFAULT 81, tv_episodes_id INTEGER DEFAULT 82, tv_lookup_attempts INTEGER DEFAULT 0, tv_lookup_retry_at TEXT, imdbid TEXT DEFAULT "tt-preserved", musicinfo_id TEXT DEFAULT "83", consoleinfo_id TEXT DEFAULT "84", bookinfo_id TEXT DEFAULT "85", book_lookup_attempts INTEGER DEFAULT 3, book_lookup_retry_at TEXT DEFAULT "2026-10-01", book_name_normalized_at TEXT DEFAULT "2026-10-01", anidbid TEXT DEFAULT "86", isrenamed INTEGER DEFAULT 0, iscategorized INTEGER DEFAULT 0)');
        (require base_path('database/migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT)');
        DB::table('predb')->insert(['id' => 7, 'title' => $canonicalTitle ?? $name]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'original-guid', 'name' => $name, 'searchname' => $searchname, 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
    }
}
