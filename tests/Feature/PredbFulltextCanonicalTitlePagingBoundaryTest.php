<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PredbFulltextCanonicalTitlePagingBoundaryTest extends TestCase
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

    #[DataProvider('canonicalTitles')]
    public function test_wrapped_fulltext_match_writes_the_complete_canonical_title(string $title): void
    {
        $this->database('[1/2] "'.$title.'.part01.rar" yEnc', 'Old.Name', $title);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $service = $this->matcher($updates);

        $matched = $service->matchPredbFulltext((object) ['title' => $title, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertSame(1, $matched);
        $this->assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertDatabaseHas('releases', ['id' => 1, 'predb_id' => 7, 'videos_id' => 0, 'imdbid' => null, 'isrenamed' => 1]);
        $this->assertTrue($updates->matched);
        $this->assertSame(1, $updates->fixed);
        $this->assertSame([1], $synchronized);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->releaseId === 1 && $event->oldName === 'Old.Name' && $event->newName === $title);
    }

    /** @return array<string, array{string}> */
    public static function canonicalTitles(): array
    {
        return [
            'ordinary dotted scene title' => ['Valid.Scene.Release.2026-GROUP'],
            'canonical technical word' => ['Valid.Scene.Release.2026-GROUP.DTS'],
            'canonical archive extension' => ['Valid.Scene.Release.2026-GROUP.rar'],
            'Unicode title and group' => ['Café.ÄScene.Release.2026-GRÜP'],
            'literal underscores' => ['Valid_Scene.Release.2026-GROUP'],
            'literal ampersand' => ['Valid&Scene.Movie.Release.2026-GROUP'],
            'multibyte title at column limit' => [str_repeat('Ä', 230).'.Scene.Release.2026-GROUP'],
        ];
    }

    #[DataProvider('attachments')]
    public function test_exact_and_case_only_fulltext_matches_attach_without_renaming(string $title, string $searchname): void
    {
        $this->database($title, $searchname);
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $service = $this->matcher($updates);
        $before = (array) DB::table('releases')->where('id', 1)->first();

        $matched = $service->matchPredbFulltext((object) ['title' => $title, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertSame(1, $matched);
        $this->assertSame(array_replace($before, ['predb_id' => 7]), (array) DB::table('releases')->where('id', 1)->first());
        $this->assertTrue($updates->matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, string}> */
    public static function attachments(): array
    {
        return [
            'exact canonical archive extension' => ['Valid.Scene.Release.2026-GROUP.rar', 'Valid.Scene.Release.2026-GROUP.rar'],
            'ASCII case only' => ['Valid.Scene.Release.2026-GROUP.rar', 'VALID.SCENE.RELEASE.2026-GROUP.RAR'],
            'Unicode case only' => ['Café.ÄScene.Release.2026-GRÜP', 'CAFÉ.ÄSCENE.RELEASE.2026-GRÜP'],
        ];
    }

    #[DataProvider('invalidTitles')]
    public function test_invalid_canonical_title_is_rejected_before_any_updater_effect(string $title, bool $alreadyNamed): void
    {
        $this->database($title, $alreadyNamed ? $title : 'Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        $before = (array) DB::table('releases')->where('id', 1)->first();
        $release = (object) ($before + ['releases_id' => 1]);

        $matched = $updates->updatePredbCandidate($release, $title, 7, 'synthetic');

        $this->assertFalse($matched);
        $this->assertSame($before, (array) DB::table('releases')->where('id', 1)->first());
        $this->assertFalse($updates->matched);
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, bool}> */
    public static function invalidTitles(): array
    {
        $cases = [];
        foreach (['attach' => true, 'rename' => false] as $path => $alreadyNamed) {
            foreach ([
                'empty' => '',
                'ASCII control' => "Valid.Scene.Release.2026-GROUP\x01",
                'Unicode control' => "Valid.Scene.Release.2026-GROUP\u{0085}",
                'invalid UTF8' => "Valid.Scene.Release.2026-GROUP\xff",
                'over column limit' => str_repeat('A', 256),
            ] as $label => $title) {
                $cases[$path.' '.$label] = [$title, $alreadyNamed];
            }
        }

        return $cases;
    }

    public function test_control_character_fulltext_evidence_does_not_partially_rename_or_attach(): void
    {
        $title = "Valid.Scene.Release.2026-GROUP\x01";
        $this->database($title, 'Old.Name');
        Event::fake([ReleaseNameFixed::class]);
        $synchronized = [];
        $updates = $this->updater($synchronized);
        Search::shouldReceive('isAvailable')->never();
        Search::shouldReceive('searchReleases')->never();
        $service = new NameFixingService(updateService: $updates, queries: new NameFixingQueryService(DB::connection()));
        $before = (array) DB::table('releases')->where('id', 1)->first();

        $matched = $service->matchPredbFulltext((object) ['title' => $title, 'predb_id' => 7, 'source' => 'synthetic']);

        $this->assertSame(0, $matched);
        $this->assertSame($before, (array) DB::table('releases')->where('id', 1)->first());
        $this->assertSame(0, $updates->fixed);
        $this->assertSame([], $synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    private function matcher(ReleaseUpdateService $updates): NameFixingService
    {
        Search::shouldReceive('isAvailable')->once()->andReturnTrue();
        Search::shouldReceive('searchReleases')->never();
        Search::shouldReceive('searchReleaseCandidatesPage')->once()->withAnyArgs()->andReturn(['ids' => [1], 'available' => true, 'has_more' => false]);

        return new NameFixingService(updateService: $updates, queries: new NameFixingQueryService(DB::connection()));
    }

    /** @param list<int> $synchronized */
    private function updater(array &$synchronized): ReleaseUpdateService
    {
        return new ReleaseUpdateService(
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$synchronized): void {
                $synchronized[] = $id;
            })
        );
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
        $pdo->exec('CREATE TABLE releases (additional_pack_evidence TEXT DEFAULT NULL, nzbstatus INTEGER DEFAULT 1, id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, fromname TEXT, groups_id INTEGER, categories_id INTEGER, predb_id INTEGER, videos_id INTEGER DEFAULT 81, tv_episodes_id INTEGER DEFAULT 82, tv_lookup_attempts INTEGER DEFAULT 0, tv_lookup_retry_at TEXT, imdbid TEXT DEFAULT "tt-preserved", musicinfo_id TEXT DEFAULT "83", consoleinfo_id TEXT DEFAULT "84", bookinfo_id TEXT DEFAULT "85", book_lookup_attempts INTEGER DEFAULT 3, book_lookup_retry_at TEXT DEFAULT "2026-10-01", book_name_normalized_at TEXT DEFAULT "2026-10-01", anidbid TEXT DEFAULT "86", isrenamed INTEGER DEFAULT 0, iscategorized INTEGER DEFAULT 0)');
        (require base_path('database/migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        $pdo->exec('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT)');
        DB::table('predb')->insert(['id' => 7, 'title' => $canonicalTitle ?? $name]);
        DB::table('releases')->insert(['id' => 1, 'guid' => 'original-guid', 'name' => $name, 'searchname' => $searchname, 'fromname' => 'poster', 'groups_id' => 1, 'categories_id' => 7010, 'predb_id' => 0]);
    }
}
