<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbFulltextCompletionPagingBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', config('database.default'));
        $connections = config('database.connections');
        $this->assertSame('sqlite', $connections['testing']['driver']);
        $this->assertSame(':memory:', $connections['testing']['database']);
        foreach (['url', 'host', 'unix_socket'] as $key) {
            $this->assertEmpty($connections['testing'][$key] ?? null);
        }
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME));
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnTrue()->byDefault();
    }

    private const TITLE = 'Valid.Scene.Release.2026-GROUP';

    #[DataProvider('winningPaths')]
    public function test_a_concurrent_canonical_winner_completes_without_duplicate_rename_effects(string $searchname, bool $rename): void
    {
        $database = $this->database();
        $this->release(1, $searchname);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $loser = $this->updater($synchronized);
        $winner = $this->updater($synchronized);
        $snapshot = (object) array_merge((array) $database->table('releases')->find(1), ['releases_id' => 1]);
        $changed = false;
        DB::listen(function (QueryExecuted $event) use ($database, $winner, $snapshot, &$changed): void {
            if (! $changed && $event->connection === $database && str_starts_with($event->sql, 'SELECT r.id AS releases_id')) {
                $changed = true;
                $this->assertSame(0, $database->transactionLevel());
                $this->assertFalse($database->getPdo()->inTransaction());
                $this->assertTrue($winner->updatePredbCandidate($snapshot, self::TITLE, 7, 'synthetic'));
                $this->assertSame(0, $database->transactionLevel());
                $this->assertFalse($database->getPdo()->inTransaction());
            }
        });
        $this->bindServices($database, $loser, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertTrue($changed);
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'searchname' => self::TITLE]);
        $this->assertSame(0, $loser->fixed);
        $this->assertFalse($loser->matched);
        $this->assertSame($rename ? 1 : 0, $winner->fixed);
        $this->assertSame($rename ? [1] : [], $synchronized);
        if ($rename) {
            Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        } else {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function winningPaths(): array
    {
        return ['attachment' => [self::TITLE, false], 'rename' => ['Old.Name', true]];
    }

    public function test_a_canonical_winner_before_confirmation_still_completes_as_matched(): void
    {
        $database = $this->database();
        $this->release(1, self::TITLE);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $winner = $this->updater($synchronized);
        $snapshot = (object) array_merge((array) $database->table('releases')->find(1), ['releases_id' => 1]);
        $changed = false;
        $database->beforeExecuting(function (string $query) use ($winner, $snapshot, &$changed): void {
            if (! $changed && str_starts_with($query, 'SELECT r.id AS releases_id')) {
                $changed = true;
                $this->assertTrue($winner->updatePredbCandidate($snapshot, self::TITLE, 7, 'synthetic'));
            }
        });
        $this->bindServices($database, $updates, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertTrue($changed);
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'searchname' => self::TITLE]);
        $this->assertFalse($updates->matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('undiscoveredLinks')]
    public function test_current_links_missing_from_discovery_require_canonical_identity(int $predbId, string $searchname, int $searched): void
    {
        $database = $this->database();
        $this->release(1, $searchname);
        $database->table('releases')->where('id', 1)->update(['predb_id' => $predbId]);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $this->bindServices($database, $updates, []);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => $searched]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => $predbId, 'searchname' => $searchname, 'videos_id' => 81]);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{int, string, int}> */
    public static function undiscoveredLinks(): array
    {
        return [
            'canonical intended link' => [7, self::TITLE, 1],
            'case-only canonical intended link' => [7, strtoupper(self::TITLE), 1],
            'intended link without canonical name' => [7, 'Old.Name', -1],
            'unrelated canonical link' => [999, self::TITLE, -1],
        ];
    }

    public function test_exhausted_link_confirmation_cap_remains_honestly_retryable(): void
    {
        $database = $this->database();
        for ($id = 1; $id <= 18; $id++) {
            $this->release($id, $id === 18 ? self::TITLE : 'Old.Name');
            $database->table('releases')->where('id', $id)->update(['predb_id' => 7]);
        }
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $this->bindServices($database, $updates, []);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertFailed();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 0]);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('eligibleMutations')]
    public function test_changed_but_literal_unlinked_candidates_remain_retryable(string $column, string $value): void
    {
        $database = $this->database();
        $this->release(1, self::TITLE);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        $database->beforeExecuting(static function (string $query) use ($database, $column, $value, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                $database->table('releases')->where('id', 1)->update([$column => $value]);
            }
        });
        $this->bindServices($database, $updates, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])
            ->expectsOutputToContain('incomplete')->assertFailed();

        $this->assertTrue($changed);
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 0]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 0, $column => $value, 'videos_id' => 81]);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, string}> */
    public static function eligibleMutations(): array
    {
        return [
            'case-only subject' => ['name', strtoupper(self::TITLE)],
            'wrapped subject' => ['name', '[1/2] "'.self::TITLE.'.part01.rar" yEnc'],
            'case-only searchname' => ['searchname', strtoupper(self::TITLE)],
            'replaced identity' => ['guid', 'replacement-guid'],
        ];
    }

    public function test_partial_success_does_not_hide_a_retryable_candidate(): void
    {
        $database = $this->database();
        $this->release(1, 'Old.Name');
        $this->release(2, 'Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $attempts = 0;
        $database->beforeExecuting(static function (string $query) use ($database, &$attempts): void {
            if (str_starts_with($query, 'update "releases"') && ++$attempts === 2) {
                $database->table('releases')->where('id', 2)->update(['name' => strtoupper(self::TITLE)]);
            }
        });
        $this->bindServices($database, $updates, [1, 2]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])
            ->expectsOutputToContain('incomplete')->assertFailed();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 0]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'searchname' => self::TITLE]);
        $this->assertDatabaseHas('releases', ['id' => 2, 'predb_id' => 0, 'searchname' => 'Old.Name', 'videos_id' => 81]);
        $this->assertSame(1, $updates->fixed);
        $this->assertSame([1], $synchronized);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    #[DataProvider('irrelevantMutations')]
    public function test_deleted_irrelevant_and_established_candidates_finish_without_effects(string $change): void
    {
        $database = $this->database();
        $this->release(1, 'Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        $database->beforeExecuting(static function (string $query) use ($database, $change, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                if ($change === 'deleted') {
                    $database->table('releases')->where('id', 1)->delete();
                } elseif ($change === 'irrelevant') {
                    $database->table('releases')->where('id', 1)->update(['name' => 'Other.Scene.Release.2026-GROUP']);
                } else {
                    $database->table('releases')->where('id', 1)->update(['predb_id' => $change === 'unrelated link' ? 999 : 7]);
                }
            }
        });
        $this->bindServices($database, $updates, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertTrue($changed);
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => -1]);
        if ($change === 'deleted') {
            $this->assertDatabaseMissing('releases', ['id' => 1]);
        } else {
            $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => $change === 'irrelevant' ? 0 : ($change === 'unrelated link' ? 999 : 7), 'searchname' => 'Old.Name', 'videos_id' => 81]);
        }
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string}> */
    public static function irrelevantMutations(): array
    {
        return [
            'deleted' => ['deleted'],
            'literal evidence removed' => ['irrelevant'],
            'unrelated established link' => ['unrelated link'],
            'same intended ID but noncanonical name' => ['noncanonical link'],
        ];
    }

    #[DataProvider('staleCompletionResults')]
    public function test_stale_negative_completions_do_not_overwrite_a_concurrent_positive_completion(int $candidates): void
    {
        $database = $this->database();
        for ($id = 1; $id <= $candidates; $id++) {
            $this->release($id, self::TITLE);
        }
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        $database->beforeExecuting(static function (string $query) use ($database, &$changed): void {
            if (! $changed && str_starts_with($query, 'UPDATE predb')) {
                $changed = true;
                $database->table('predb')->where('id', 7)->update(['searched' => 1]);
            }
        });
        $this->bindServices($database, $updates, $candidates === 0 ? [] : range(1, $candidates));

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{int}> */
    public static function staleCompletionResults(): array
    {
        return ['no-match completion' => [0], 'ambiguous completion' => [16]];
    }

    public function test_a_changed_eligible_title_retries_on_wrap_and_then_completes(): void
    {
        $database = $this->database();
        $this->release(1, 'Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        $database->beforeExecuting(static function (string $query) use ($database, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                $database->table('releases')->where('id', 1)->update(['name' => strtoupper(self::TITLE)]);
            }
        });
        $this->bindServices($database, $updates, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertFailed();
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 0]);
        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'searchname' => self::TITLE]);
        $this->assertSame(1, $updates->fixed);
        $this->assertSame([1], $synchronized);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    #[DataProvider('invalidTitles')]
    public function test_unsupported_canonical_titles_finish_without_retry_or_rename(string $title): void
    {
        $database = $this->database();
        $database->table('predb')->where('id', 7)->update(['title' => $title]);
        $this->release(1, 'Old.Name');
        $database->table('releases')->where('id', 1)->update(['name' => $title]);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $this->bindServices($database, $updates, [1]);

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => -1]);
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 0, 'searchname' => 'Old.Name', 'videos_id' => 81]);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string}> */
    public static function invalidTitles(): array
    {
        return [
            'overlength' => ['Valid.Scene.Release.'.str_repeat('x', 250).'-GROUP'],
            'control character' => [self::TITLE.chr(1)],
        ];
    }

    #[DataProvider('literalThresholds')]
    public function test_actual_command_unsaturated_literal_threshold(int $count, int $linked, int $searched): void
    {
        $database = $this->database();
        for ($id = 1; $id <= $count; $id++) {
            $this->release($id, self::TITLE);
        }
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $this->bindServices($database, $updates, range(1, $count));
        $before = $database->table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertSame($linked, $database->table('releases')->where('predb_id', 7)->count());
        $this->assertSame($searched, (int) $database->table('predb')->where('id', 7)->value('searched'));
        $after = $database->table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $expected = array_map(static fn (array $row): array => $linked ? array_replace($row, ['predb_id' => 7]) : $row, $before);
        $this->assertSame($expected, $after);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{int, int, int}> */
    public static function literalThresholds(): array
    {
        return ['fifteen literals attach' => [15, 15, 1], 'sixteen literals flood' => [16, 0, -6]];
    }

    #[DataProvider('scanEnds')]
    public function test_actual_command_exact_210_candidate_boundary(bool $hasMore): void
    {
        $database = $this->database();
        for ($id = 1; $id <= 210; $id++) {
            $this->release($id, $id === 1 ? 'Old.Name' : self::TITLE.$id);
            if ($id > 1) {
                $database->table('releases')->where('id', $id)->update(['name' => self::TITLE.$id]);
            }
        }
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchReleases')->never();
        $cursors = [];
        Search::shouldReceive('searchReleaseCandidatesPage')->andReturnUsing(function (string $title, int $limit, int $cursor) use ($hasMore, &$cursors, $database): array {
            $this->assertSame(self::TITLE, $title);
            $this->assertSame(21, $limit);
            $this->assertSame(0, $database->table('releases')->where('predb_id', 7)->count(), 'No write before complete discovery.');
            $cursors[] = $cursor;

            return ['ids' => range($cursor + 1, $cursor + 21), 'available' => true, 'has_more' => $cursor < 189 || $hasMore];
        });
        $queries = new NameFixingQueryService($database);
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
        $before = $database->table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $command = $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1]);
        if ($hasMore) {
            $command->expectsOutputToContain('incomplete')->assertFailed()->run();
            $this->assertSame($before, $database->table('releases')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } else {
            $command->assertSuccessful()->run();
            $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'searchname' => self::TITLE, 'isrenamed' => 1]);
            Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        }
        $this->assertSame(range(0, 189, 21), $cursors);
        $this->assertSame($hasMore ? 0 : 1, (int) $database->table('predb')->where('id', 7)->value('searched'));
        $this->assertSame($hasMore ? 0 : 1, $database->table('releases')->where('predb_id', 7)->count());
        $this->assertSame($hasMore ? [] : [1], $synchronized);
        $this->assertSame($hasMore ? 0 : 1, $updates->fixed);
    }

    /** @return array<string, array{bool}> */
    public static function scanEnds(): array
    {
        return ['exactly complete' => [false], 'more explicitly remains' => [true]];
    }

    #[DataProvider('unsupportedLinks')]
    public function test_unsupported_driver_preserves_mapped_discovery_and_existing_completion(int $existingLink): void
    {
        $database = $this->database();
        $this->release(1, self::TITLE);
        $database->table('releases')->where('id', 1)->update(['predb_id' => $existingLink]);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->with(['name' => self::TITLE, 'searchname' => self::TITLE], 21)->andReturn([1]);
        Search::shouldReceive('searchReleaseCandidatesPage')->never();
        $queries = new NameFixingQueryService($database);
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
        $before = (array) $database->table('releases')->where('id', 1)->first();

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 1])->assertSuccessful();

        $this->assertSame(array_replace($before, ['predb_id' => 7]), (array) $database->table('releases')->where('id', 1)->first());
        $this->assertDatabaseHas('predb', ['id' => 7, 'searched' => 1]);
        $this->assertSame([], $synchronized);
        $this->assertSame(0, $updates->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{int}> */
    public static function unsupportedLinks(): array
    {
        return ['new attachment' => [0], 'existing canonical link' => [7]];
    }

    /** @param list<int> $ids */
    private function bindServices(SQLiteConnection $database, ReleaseUpdateService $updates, array $ids): void
    {
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchReleases')->never();
        Search::shouldReceive('searchReleaseCandidatesPage')->andReturnUsing(static function (string $title, int $limit, int $cursor) use ($ids): array {
            $remaining = array_values(array_filter($ids, static fn (int $id): bool => $id > $cursor));

            return ['ids' => array_slice($remaining, 0, $limit), 'available' => true, 'has_more' => count($remaining) > $limit];
        });
        $queries = new NameFixingQueryService($database);
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
    }

    /** @param list<int> $synchronized */
    private function updater(array &$synchronized): ReleaseUpdateService
    {
        return new ReleaseUpdateService(
            category: $this->createStub(CategorizationService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$synchronized): void {
                $synchronized[] = $id;
            })
        );
    }

    private function release(int $id, string $searchname): void
    {
        DB::table('releases')->insert(['id' => $id, 'guid' => 'guid-'.$id, 'name' => self::TITLE, 'searchname' => $searchname, 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
    }

    private function database(): SQLiteConnection
    {
        config(['nntmux.echocli' => false]);
        $this->travelTo('2026-10-11 12:00:00');
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, videos_id INTEGER DEFAULT 81, tv_episodes_id INTEGER DEFAULT 82, tv_lookup_attempts INTEGER DEFAULT 0, tv_lookup_retry_at TEXT, imdbid TEXT DEFAULT "tt-preserved", musicinfo_id TEXT DEFAULT "83", consoleinfo_id TEXT DEFAULT "84", bookinfo_id TEXT DEFAULT "85", book_lookup_attempts INTEGER DEFAULT 3, book_lookup_retry_at TEXT DEFAULT "2026-10-01", book_name_normalized_at TEXT DEFAULT "2026-10-01", anidbid TEXT DEFAULT "86", isrenamed INTEGER DEFAULT 0, iscategorized INTEGER DEFAULT 0)');
        $pdo->exec('CREATE INDEX ix_releases_predb_id ON releases (predb_id)');
        $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, source TEXT, searched INTEGER, predate TEXT, imported_at TEXT)');
        $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
        $pdo->exec('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
        $pdo->sqliteCreateFunction('regexp', static fn (string $pattern, string $subject): int => (int) preg_match('~'.$pattern.'~', $subject), 2);
        $pdo->sqliteCreateFunction('MOD', static fn (int $value, int $divisor): int => $value % $divisor, 2);
        $database = new class($pdo, ':memory:', '', ['driver' => 'sqlite', 'name' => 'completion']) extends SQLiteConnection
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
        config(['database.default' => 'completion', 'database.connections.completion' => ['driver' => 'completion']]);
        DB::extend('completion', static fn (): SQLiteConnection => $database);
        (require base_path('database/migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        $database->table('predb')->insert(['id' => 7, 'title' => self::TITLE, 'source' => 'synthetic', 'searched' => 0, 'predate' => '2020-01-01', 'imported_at' => '2020-01-01']);

        return $database;
    }
}
