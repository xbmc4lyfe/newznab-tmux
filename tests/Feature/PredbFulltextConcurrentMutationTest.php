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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbFulltextConcurrentMutationTest extends TestCase
{
    private const TITLE = 'Valid.Scene.Release.2026-GROUP';

    #[DataProvider('concurrentChanges')]
    public function test_later_page_changes_do_not_apply_a_retained_candidate(string $initialSearchname, string $change, ?string $column): void
    {
        $this->database($initialSearchname);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn(range(1, 21));
        Search::shouldReceive('supportsReleaseCandidatePaging')->once()->andReturnTrue();
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, 0)
            ->andReturn(['ids' => range(1, 21), 'available' => true, 'has_more' => true]);
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->with(self::TITLE, 21, 21)
            ->andReturnUsing(function () use ($change, $column, &$changed): array {
                $changed = true;
                if ($change === 'delete') {
                    DB::table('releases')->where('id', 1)->delete();
                } elseif ($change === 'replace') {
                    $row = (array) DB::table('releases')->where('id', 1)->first();
                    DB::table('releases')->where('id', 1)->delete();
                    $row['guid'] = 'replacement-guid';
                    DB::table('releases')->insert($row);
                } else {
                    DB::table('releases')->where('id', 1)->update([$column => $change === 'linked' ? 999 : $change]);
                }

                return ['ids' => [22], 'available' => true, 'has_more' => false];
            });
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService(DB::connection()));

        $matched = $service->matchPredbFulltext((object) ['title' => self::TITLE, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertTrue($changed);
        $this->assertSame(0, $matched);
        if ($change === 'delete') {
            $this->assertDatabaseMissing('releases', ['id' => 1]);
        } else {
            $this->assertDatabaseHas('releases', [
                'id' => 1,
                'predb_id' => $change === 'linked' ? 999 : 0,
                'searchname' => $column === 'searchname' ? $change : $initialSearchname,
                'videos_id' => 81,
                'imdbid' => 'tt-preserved',
                'isrenamed' => 0,
            ]);
        }
        $this->assertSame(1, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
        $this->assertFalse($updates->matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function concurrentChanges(): array
    {
        $cases = [];
        foreach (['attach' => self::TITLE, 'rename' => 'Old.Name'] as $path => $searchname) {
            $cases[$path.' established link'] = [$searchname, 'linked', 'predb_id'];
            $cases[$path.' changed searchname'] = [$searchname, 'Other.Scene.Release.2026-GROUP', 'searchname'];
            $cases[$path.' changed subject'] = [$searchname, 'Other.Scene.Release.2026-GROUP', 'name'];
            $cases[$path.' accent-only identity change'] = [$searchname, 'Válid.Scene.Release.2026-GROUP', 'name'];
            $cases[$path.' deleted release'] = [$searchname, 'delete', null];
            $cases[$path.' replaced release with same title'] = [$searchname, 'replace', null];
        }

        return $cases;
    }

    #[DataProvider('writeBoundaryChanges')]
    public function test_atomic_write_rejects_changes_after_confirmation(string $searchname, string $column, string|int $value): void
    {
        $this->database($searchname);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($column, $value, &$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                DB::table('releases')->where('id', 1)->update([$column => $value]);
            }
        });
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([1]);
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService(DB::connection()));

        $matched = $service->matchPredbFulltext((object) ['title' => self::TITLE, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertTrue($changed);
        $this->assertSame(0, $matched);
        $this->assertDatabaseHas('releases', [
            'id' => 1, 'predb_id' => $column === 'predb_id' ? $value : 0,
            'searchname' => $column === 'searchname' ? $value : $searchname,
            'videos_id' => 81, 'imdbid' => 'tt-preserved', 'isrenamed' => 0,
        ]);
        $this->assertSame(1, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
        $this->assertFalse($updates->matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, string, string|int}> */
    public static function writeBoundaryChanges(): array
    {
        $cases = [];
        foreach (['attach' => self::TITLE, 'rename' => 'Old.Name'] as $path => $searchname) {
            $cases[$path.' link immediately before write'] = [$searchname, 'predb_id', 999];
            $cases[$path.' title immediately before write'] = [$searchname, 'searchname', 'Other.Scene.Release.2026-GROUP'];
            $cases[$path.' case-only subject change immediately before write'] = [$searchname, 'name', strtoupper(self::TITLE)];
            $cases[$path.' replacement immediately before write'] = [$searchname, 'guid', 'replacement-guid'];
        }

        return $cases;
    }

    #[DataProvider('ordinaryCandidates')]
    public function test_current_candidates_keep_valid_attachment_and_rename_effects(string $name, string $searchname, bool $renamed): void
    {
        $this->database($searchname, $name);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->once()->andReturn([1]);
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService(DB::connection()));

        $matched = $service->matchPredbFulltext((object) ['title' => self::TITLE, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertSame(1, $matched);
        $this->assertDatabaseHas('releases', [
            'id' => 1, 'predb_id' => 7, 'searchname' => $renamed ? self::TITLE : $searchname,
            'videos_id' => $renamed ? 0 : 81, 'imdbid' => $renamed ? null : 'tt-preserved',
            'isrenamed' => $renamed ? 1 : 0,
        ]);
        $this->assertSame(0, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
        $this->assertTrue($updates->matched);
        $this->assertSame($renamed ? 1 : 0, $updates->fixed);
        $this->assertSame($renamed ? [1] : [], $synchronized);
        if ($renamed) {
            Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->releaseId === 1 && $event->oldName === $searchname && $event->newName === self::TITLE);
        } else {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    #[DataProvider('attachmentTransactions')]
    public function test_generic_attachment_clears_probe_progress_only_after_commit(bool $commit): void
    {
        $this->database(self::TITLE);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $release = DB::table('releases')->select('*', 'id as releases_id')->where('id', 1)->first();
        $this->assertNotNull($release);
        DB::beginTransaction();
        $this->assertTrue($updates->attachPredbSnapshot($release, 7));
        $this->assertSame(1, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }
        $this->assertSame($commit ? 7 : 0, DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame($commit ? 0 : 1, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
    }

    public function test_rename_checkpoint_cleanup_uses_the_committed_release_id(): void
    {
        $this->database('Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        DB::table('par2_rename_progress')->insert(['releases_id' => 2]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $release = DB::table('releases')->select('*', 'id as releases_id')->where('id', 1)->first();
        $this->assertNotNull($release);
        DB::beginTransaction();
        $this->assertTrue($updates->updatePredbCandidate($release, self::TITLE, 7, 'synthetic'));
        $release->releases_id = 2;
        DB::commit();
        $this->assertSame(7, DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame(0, DB::table('par2_rename_progress')->where('releases_id', 1)->count());
        $this->assertSame(1, DB::table('par2_rename_progress')->where('releases_id', 2)->count());
    }

    /** @return array<string, array{bool}> */
    public static function attachmentTransactions(): array
    {
        return ['committed' => [true], 'rolled back' => [false]];
    }

    /** @return array<string, array{string, string, bool}> */
    public static function ordinaryCandidates(): array
    {
        return [
            'exact attachment' => [self::TITLE, self::TITLE, false],
            'case-only attachment' => [self::TITLE, strtoupper(self::TITLE), false],
            'genuine rename' => [self::TITLE, 'Old.Name', true],
            'known technical wrapper rename' => ['[1/2] "'.self::TITLE.'.part01.rar" yEnc', 'Old.Name', true],
        ];
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

    private function database(string $searchname, string $name = self::TITLE): void
    {
        config(['nntmux.echocli' => false]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('like', static function (string $pattern, string $subject, string $escape): int {
            $pattern = str_replace('\\\\', '\\', $pattern);
            $regex = str_replace(['%', '_'], ['.*', '.'], preg_quote($pattern, '~'));

            return preg_match('~^'.$regex.'$~iu', $subject);
        }, 3);
        DB::connection()->setPdo($pdo);
        DB::connection()->setReadPdo($pdo);
        $pdo->exec('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, videos_id INTEGER DEFAULT 81, tv_episodes_id INTEGER DEFAULT 82, imdbid TEXT DEFAULT "tt-preserved", musicinfo_id TEXT DEFAULT "83", consoleinfo_id TEXT DEFAULT "84", bookinfo_id TEXT DEFAULT "85", book_lookup_attempts INTEGER DEFAULT 3, book_lookup_retry_at TEXT DEFAULT "2026-10-01", book_name_normalized_at TEXT DEFAULT "2026-10-01", anidbid TEXT DEFAULT "86", isrenamed INTEGER DEFAULT 0, iscategorized INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE par2_rename_progress (releases_id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT)');
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        DB::table('par2_rename_progress')->insert(['releases_id' => 1]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'original-guid', 'name' => $name, 'searchname' => $searchname, 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
        for ($id = 2; $id <= 22; $id++) {
            DB::table('releases')->insert(['id' => $id, 'guid' => 'guid-'.$id, 'name' => self::TITLE.$id, 'searchname' => 'Old.Name', 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
        }
    }
}
