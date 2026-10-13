<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\IncompletePredbMatchException;
use App\Facades\Search;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbFulltextIdentityTest extends TestCase
{
    #[DataProvider('identities')]
    public function test_fulltext_confirmation_requires_a_literal_title_token(string $title, string $candidate, bool $accepted, bool $alreadyNamed = false): void
    {
        $database = $this->database([[42, $candidate, $alreadyNamed ? $candidate : 'Old.Name']]);
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([42]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($accepted ? $this->once() : $this->never())->method('updatePredbCandidate')->with(
            $this->callback(static fn (object $release): bool => (int) $release->releases_id === 42 && (string) $release->searchname === ($alreadyNamed ? $candidate : 'Old.Name')), $title, 7, 'synthetic', false
        )->willReturn(true);
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($database));

        $this->assertSame($accepted ? 1 : 0, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
    }

    /** @return array<string, array{string, string, bool, 3?: bool}> */
    public static function identities(): array
    {
        $title = 'Valid.Scene.Release.2026-GROUP';

        return [
            'exact title' => [$title, $title, true, true],
            'case-only title' => [$title, strtoupper($title), true, true],
            'Unicode case-only title' => ['ÄScene.Movie.Release.2026-GROUP', 'äscene.movie.release.2026-group', true, true],
            'intact quoted subject' => [$title, '[1/2] - "'.$title.'" yEnc', true],
            'bracketed intact token' => [$title, '['.$title.']', true],
            'quoted RAR part' => [$title, '[1/2] - "'.$title.'.part01.rar" yEnc', true],
            'encoded quote contract' => [$title, '[1/2] - #34;'.$title.'.mkv#34; yEnc', true],
            'stacked technical extensions' => [$title, '"'.$title.'.rar.par2"', true],
            'payload extension' => [$title, $title.'.mkv', true],
            'technical word remains canonical' => [$title.'.DTS', '"'.$title.'.DTS.mkv"', true],
            'wrong group suffix' => [$title, $title.'2', false],
            'different title prefix' => [$title, 'Other'.$title, false],
            'apostrophe inside a different title' => [$title, "Other'".$title, false],
            'attached semantic parentheses' => [$title, $title.'(Extended)', false],
            'attached semantic quote text' => [$title, $title."'Extended", false],
            'unknown suffix' => [$title, $title.'.Different', false],
            'extra semantic component before wrapper' => [$title, $title.'.Different.rar', false],
            'technical suffix followed by junk' => [$title, $title.'.mkvJunk', false],
            'wrong year' => [$title, 'Valid.Scene.Release.2025-GROUP', false],
            'wrong group' => [$title, 'Valid.Scene.Release.2026-OTHER', false],
            'accent collation alias' => ['Cafe.Scene.Release.2026-GRP', 'Café.Scene.Release.2026-GRP', false],
            'literal wildcard characters' => ['Valid.Scene_%Release.Movie.2026-GROUP', 'Valid.Scene_%Release.Movie.2026-GROUP', true, true],
            'wildcards cannot replace literal characters' => ['Valid.Scene_%Release.Movie.2026-GROUP', 'Valid.SceneXYRelease.Movie.2026-GROUP', false],
        ];
    }

    public function test_literal_evidence_in_searchname_is_confirmed_even_when_name_is_nonliteral(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([42]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())->method('updatePredbCandidate')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 42), $title, 7, 'synthetic', false)->willReturn(true);
        $updates->expects($this->never())->method('updateRelease');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database([[42, 'Other'.$title, $title]])));

        $this->assertSame(1, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
    }

    public function test_false_prefix_candidates_do_not_hide_a_genuine_exact_title_as_a_flood(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $rows = [];
        for ($id = 1; $id <= 16; $id++) {
            $rows[] = [$id, $title.$id, 'Old.Name'];
        }
        $rows[] = [42, $title, $title];
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([...range(1, 16), 42]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())->method('updatePredbCandidate')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 42), $title, 7, 'synthetic', false)->willReturn(true);
        $updates->expects($this->never())->method('updateRelease');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->assertSame(1, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
    }

    #[DataProvider('fullWindows')]
    public function test_keyset_paging_recovers_the_twenty_second_literal_match_without_partial_writes(bool $visibleExact): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $rows = [];
        for ($id = 1; $id <= 21; $id++) {
            $name = $visibleExact && $id === 1 ? $title : $title.$id;
            $rows[] = [$id, $name, $name];
        }
        $rows[] = [22, $title, $title];
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->with(['name' => $title, 'searchname' => $title], 21)
            ->andReturnUsing(static fn (array $phrases, int $limit): array => array_slice(range(1, 22), 0, $limit));
        $updates = $this->createMock(ReleaseUpdateService::class);
        $cursors = [];
        $this->mockPager(range(1, 22), $cursors);
        $updates->expects($this->exactly($visibleExact ? 2 : 1))->method('updatePredbCandidate')
            ->willReturnCallback(function (object $release, string $title, int $predbId) use ($visibleExact, &$cursors): bool {
                $id = (int) $release->releases_id;
                $this->assertContains($id, $visibleExact ? [1, 22] : [22]);
                $this->assertSame(7, $predbId);
                $this->assertSame([0, 21], $cursors);

                return true;
            });
        $updates->expects($this->never())->method('updateRelease');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->assertSame($visibleExact ? 2 : 1, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
        $this->assertSame([0, 21], $cursors);
    }

    public function test_incomplete_scan_does_not_starve_a_later_exact_command_entry(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $nextTitle = 'Next.Scene.Release.2026-GROUP';
        $rows = [];
        for ($id = 1; $id <= 210; $id++) {
            $rows[] = [$id, $title.$id, 'Old.Name'];
        }
        $rows[] = [211, $title, $title];
        $rows[] = [500, $nextTitle, $nextTitle];
        $database = $this->database($rows);
        $database->statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, source TEXT, searched INTEGER, predate TEXT, imported_at TEXT)');
        $database->statement('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
        $database->statement('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
        Cache::extend('predb-test-database', static fn (): Repository => new Repository(new DatabaseStore($database, 'cache', 'predb-fulltext-test:', 'cache_locks')));
        config(['cache.stores.database' => ['driver' => 'predb-test-database']]);
        $database->insert('INSERT INTO predb VALUES (7, ?, "synthetic", 0, "2020-01-01", "2020-01-01"), (8, ?, "synthetic", 0, "2020-01-01", "2020-01-01")', [$title, $nextTitle]);
        $pdo = $database->getPdo();
        $pdo->sqliteCreateFunction('regexp', static fn (string $pattern, string $subject): int => (int) preg_match('~'.$pattern.'~', $subject), 2);
        $pdo->sqliteCreateFunction('MOD', static fn (int $value, int $divisor): int => $value % $divisor, 2);
        Search::shouldReceive('isAvailable')->andReturnTrue();
        Search::shouldReceive('searchReleases')->andReturnUsing(static fn (array $phrases, int $limit): array => $phrases['name'] === $title ? array_slice(range(1, 211), 0, $limit) : [500]);
        $cursors = [];
        $this->mockPager(range(1, 211), $cursors);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->once())->method('updatePredbCandidate')->with($this->callback(static fn (object $release): bool => (int) $release->releases_id === 500), $nextTitle, 8, 'synthetic', false)->willReturn(true);
        $updates->expects($this->never())->method('updateRelease');
        $queries = new NameFixingQueryService($database);
        $this->app->instance(NameFixingQueryService::class, $queries);
        $this->app->instance(NameFixingService::class, new NameFixingService(updateService: $updates, queries: $queries));
        DB::shouldReceive('update')->once()->with('UPDATE predb SET searched = ? WHERE id = ?', [1, 8])
            ->andReturnUsing(static fn (string $sql, array $bindings): int => $database->update($sql, $bindings));

        $this->artisan('releases:fix-names-group', ['type' => 'predbft', '--limit' => 2])
            ->expectsOutputToContain('incomplete')->assertFailed();
        $this->assertSame(0, (int) $database->scalar('SELECT searched FROM predb WHERE id = 7'));
        $this->assertSame(1, (int) $database->scalar('SELECT searched FROM predb WHERE id = 8'));
        $this->assertSame(range(0, 189, 21), $cursors);
    }

    public function test_scan_budget_exhaustion_does_not_attach_a_visible_partial_match(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $rows = [];
        for ($id = 1; $id <= 210; $id++) {
            $name = $id === 1 ? $title : $title.$id;
            $rows[] = [$id, $name, $name];
        }
        $rows[] = [211, $title, $title];
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        $cursors = [];
        $this->mockPager(range(1, 211), $cursors);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->expectException(IncompletePredbMatchException::class);
        $this->expectExceptionMessage('incomplete');
        try {
            $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']);
        } finally {
            $this->assertSame(range(0, 189, 21), $cursors);
        }
    }

    /** @return array<string, array{bool}> */
    public static function fullWindows(): array
    {
        return ['21 false prefixes hide 22nd exact' => [false], 'one visible exact remains partial' => [true]];
    }

    public function test_sixteen_genuine_literal_matches_still_trigger_the_flood_guard(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 16));
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $rows = array_map(static fn (int $id): array => [$id, $title, 'Old.Name'], range(1, 16));
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->assertSame(-1, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
    }

    public function test_a_genuine_flood_across_candidate_pages_never_partially_links(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        $rows = [];
        for ($id = 1; $id <= 22; $id++) {
            $name = $id <= 15 || $id === 22 ? $title : $title.$id;
            $rows[] = [$id, $name, $name];
        }
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        $cursors = [];
        $this->mockPager(range(1, 22), $cursors);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->assertSame(-1, $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']));
        $this->assertSame([0, 21], $cursors);
    }

    public function test_an_unsupported_pager_preserves_honest_incomplete_matching(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnFalse();
        Search::shouldReceive('searchReleaseCandidatesPage')->never();
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $rows = array_map(static fn (int $id): array => [$id, $title.$id, 'Old.Name'], range(1, 21));
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->expectException(IncompletePredbMatchException::class);
        $this->expectExceptionMessage('does not support');
        $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']);
    }

    public function test_paged_backend_failure_remains_a_global_availability_failure(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->andReturn(['ids' => [], 'available' => false, 'has_more' => false]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $rows = array_map(static fn (int $id): array => [$id, $title.$id, 'Old.Name'], range(1, 21));
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unavailable');
        try {
            $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']);
        } catch (\RuntimeException $exception) {
            $this->assertNotInstanceOf(IncompletePredbMatchException::class, $exception);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $page */
    #[DataProvider('invalidPages')]
    public function test_invalid_page_boundaries_cannot_claim_complete_matching(array $page): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->andReturn($page);
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $rows = array_map(static fn (int $id): array => [$id, $title.$id, 'Old.Name'], range(1, 21));
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->expectException(IncompletePredbMatchException::class);
        $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPages(): array
    {
        return [
            'unordered IDs' => [['ids' => [2, 1], 'available' => true, 'has_more' => false]],
            'duplicate IDs' => [['ids' => [1, 1], 'available' => true, 'has_more' => false]],
            'too many IDs' => [['ids' => range(1, 22), 'available' => true, 'has_more' => false]],
            'empty page claims more' => [['ids' => [], 'available' => true, 'has_more' => true]],
            'short page claims more' => [['ids' => [1], 'available' => true, 'has_more' => true]],
        ];
    }

    public function test_a_repeated_cursor_page_cannot_hide_the_next_exact_match(): void
    {
        $title = 'Valid.Scene.Release.2026-GROUP';
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->twice()->andReturn(
            ['ids' => range(1, 21), 'available' => true, 'has_more' => true],
            ['ids' => [21, 22], 'available' => true, 'has_more' => false]
        );
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects($this->never())->method('updatePredbCandidate');
        $updates->expects($this->never())->method('updateRelease');
        $rows = array_map(static fn (int $id): array => [$id, $title.$id, 'Old.Name'], range(1, 21));
        $rows[] = [22, $title, $title];
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService($this->database($rows)));

        $this->expectException(IncompletePredbMatchException::class);
        $service->matchPredbFulltext((object) ['predb_id' => 7, 'title' => $title, 'source' => 'synthetic']);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $cursors
     */
    private function mockPager(array $ids, array &$cursors): void
    {
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->andReturnUsing(static function (string $title, int $limit, int $afterId) use ($ids, &$cursors): array {
            $cursors[] = $afterId;
            $remaining = array_values(array_filter($ids, static fn (int $id): bool => $id > $afterId));

            return ['ids' => array_slice($remaining, 0, $limit), 'available' => true, 'has_more' => count($remaining) > $limit];
        });
    }

    /** @param list<array{int, string, string}> $rows */
    private function database(array $rows): SQLiteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, name TEXT, searchname TEXT, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, guid TEXT)');
        // This synthetic LIKE models the accent/case equivalence independently observed on MariaDB.
        $pdo->sqliteCreateFunction('like', static function (string $pattern, string $subject, string $escape): int {
            $fold = static fn (string $value): string => mb_strtolower(str_replace(['é', 'É'], ['e', 'E'], $value), 'UTF-8');
            $regex = '';
            $escaped = false;
            foreach (mb_str_split($fold($pattern)) as $character) {
                if (! $escaped && $character === $escape) {
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

            return preg_match('~^'.$regex.'$~us', $fold($subject));
        }, 3);
        $insert = $pdo->prepare('INSERT INTO releases (id, name, searchname, fromname, groups_id, categories_id, predb_id, guid) VALUES (?, ?, ?, "poster", 1, 7010, 0, ?)');
        foreach ($rows as $row) {
            $insert->execute([...$row, 'guid-'.$row[0]]);
        }

        return new class($pdo) extends SQLiteConnection
        {
            /**
             * @param  string  $query
             * @param  array<int|string, mixed>  $bindings
             * @param  array<int, mixed>  $fetchUsing
             * @return list<object>
             */
            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
            {
                // Preserve the real query; adapt MySQL's SQL literal for one escape character to SQLite.
                $query = str_replace("ESCAPE '\\\\'", "ESCAPE '\\'", $query);

                return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
            }
        };
    }
}
