<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityRebuild;
use App\Support\Par2RenameProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbAuthoritativeIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse()->byDefault();
    }

    use Par2RenameTestDatabase;

    /** @return array<string, array{string, string, bool}> */
    public static function seasonFilenameMatches(): array
    {
        return [
            'season cannot become one episode' => ['Example.Show.S01.1080p-GRP', 'Example.Show.S01E01.1080p-GRP', false],
            'year season cannot become one episode' => ['Example.Show.S2024.1080p-GRP', 'Example.Show.S2024E01.1080p-GRP', false],
            'episode filename can identify a canonical season' => ['Example.Show.S01.1080p-GRP', 'Example.Show.Season.1.COMPLETE.1080p-GRP', true],
            'episode can retain episode identity' => ['Example.Show.S01E01.720p-GRP', 'Example.Show.S01E01.1080p-GRP', true],
            'movie can acquire canonical identity' => ['Example.Movie.2026.720p-GRP', 'Example.Movie.2026.1080p-GRP', true],
        ];
    }

    #[DataProvider('seasonFilenameMatches')]
    public function test_prefile_handler_preserves_season_scope_of_canonical_identity(string $searchname, string $title, bool $accepted): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        $filename = 'Example.Show.S01E01.1080p-GRP.mkv';
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $filename]);
        $release = $this->pendingPar2Release(['searchname' => $searchname]);
        self::assertTrue(Par2RenameProgress::save($release->id, 'manifest', 1, []));
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $updates = $this->updater();
        $this->certifyFilenameSqlFixture();

        (new NameFixingService($updates))->getPreFileNames(['prefiles', 1]);

        self::assertSame($accepted ? $title : $searchname, $release->fresh()->searchname);
        self::assertSame($accepted ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($accepted, $updates->matched);
        self::assertSame($accepted ? 1 : 0, $updates->fixed);
        if ($accepted) {
            self::assertNull(Par2RenameProgress::get($release->id));
            self::assertSame($title, $updates->lastCommittedIdentity()['after']['searchname'] ?? null);
            Event::assertDispatched(ReleaseNameFixed::class);
        } else {
            self::assertNotNull(Par2RenameProgress::get($release->id));
            self::assertNull($updates->lastCommittedIdentity());
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    public function test_confirmed_predb_preview_does_not_offer_season_to_episode_rename(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        $title = 'Example.Show.S01E01.1080p-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => 'Example.Show.S01.1080p-GRP']);
        $updates = $this->updater();

        $updates->updateRelease($release, $title, 'PreDB', false, 'PreDB file match, ', true, false, 7);

        self::assertFalse($updates->matched);
        self::assertSame(0, $updates->fixed);
        self::assertNull($updates->lastCommittedIdentity());
        self::assertSame('Example.Show.S01.1080p-GRP', $release->fresh()->searchname);
        self::assertSame(0, $release->fresh()->predb_id);
    }

    /** @return array<string, array{string}> */
    public static function paths(): array
    {
        return ['rename' => ['Hashed.Title'], 'same-name attach' => ['Exact.Release.2026-GRP']];
    }

    #[DataProvider('paths')]
    public function test_stale_backend_identity_cannot_link_replacement_predb(string $searchname): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $searchname]);
        self::assertTrue(Par2RenameProgress::save($release->id, 'manifest', 1, []));
        DB::table('predb')->insert(['id' => 7, 'title' => 'Different.Release.2025-OTHER']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'relsize' => 5000, 'filename' => $title.'.mkv']);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates);
        $this->certifyFilenameSqlFixture();
        $result = $service->matchPreDbFiles($input, true, true, false);
        self::assertSame('Different.Release.2025-OTHER', DB::table('predb')->where('id', 7)->value('title'));
        self::assertSame(0, $release->fresh()->predb_id, 'Committed link points at a different canonical title');
        self::assertSame(0, $result);
        self::assertSame($searchname, $release->fresh()->searchname);
        self::assertNotNull(Par2RenameProgress::get($release->id));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_current_filename_relationship_is_required_for_mapped_titles(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        $title = 'Canonical.Release.2026-GRP';
        $filename = 'publisher-file-name';
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => 'different-file-name.mkv']);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'filename' => $filename.'.mkv']);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $updates = $this->updater();
        $this->certifyFilenameSqlFixture();
        self::assertSame(0, (new NameFixingService($updates))->matchPreDbFiles($input, true, true, false));
        self::assertSame(0, $release->fresh()->predb_id);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        DB::table('predb')->where('id', 7)->update(['filename' => 'Folder/PUBLISHER-FILE-NAME.MKV']);
        $this->certifyFilenameSqlFixture();
        self::assertSame(1, (new NameFixingService($updates))->matchPreDbFiles($input, true, true, false));
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
    }

    /** @return array<string, array{string|null}> */
    public static function identityChanges(): array
    {
        return ['different title' => ['Other.Release.2025-OTHER'], 'accent alias' => ['Cafe.Release.2026-GRP'], 'decomposed alias' => ["Cafe\u{0301}.Release.2026-GRP"], 'deleted' => [null]];
    }

    #[DataProvider('identityChanges')]
    public function test_write_boundary_predb_change_rolls_back_release_and_publication(?string $replacement): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Café.Release.2026-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $syncs = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$syncs): void {
            $syncs[] = $id;
        });
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$changed, $replacement): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                if ($replacement === null) {
                    DB::table('predb')->where('id', 7)->delete();
                } else {
                    DB::table('predb')->where('id', 7)->update(['title' => $replacement]);
                }
            }
        });
        $updates->updateRelease($input, $title, 'PreDB', true, 'PAR2, ', true, false, 7);
        self::assertTrue($changed);
        self::assertFalse($updates->matched);
        self::assertSame(0, $updates->fixed);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        self::assertSame([], $syncs);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_case_equivalent_authoritative_title_remains_valid(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Café.Release.2026-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => mb_strtoupper($title, 'UTF-8')]);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $updates = $this->updater();
        $updates->updateRelease($input, $title, 'PreDB', true, 'PAR2, ', true, false, 7);
        self::assertTrue($updates->matched);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame(mb_strtoupper($title, 'UTF-8'), $release->fresh()->searchname);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->newName === mb_strtoupper($title, 'UTF-8'));
    }

    public function test_same_name_attachment_rolls_back_after_predb_identity_changes(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => $title]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'Other.Release.2025-GRP']);
            }
        });
        $updates = $this->updater();
        self::assertFalse($updates->attachPredbSnapshot($input, 7));
        self::assertTrue($changed);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertFalse($updates->matched);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string}> */
    public static function fulltextPaths(): array
    {
        return ['same title' => ['Exact.Release.2026-GRP'], 'different title' => ['Hashed.Title']];
    }

    #[DataProvider('fulltextPaths')]
    public function test_fulltext_candidate_requires_current_canonical_identity(string $searchname): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $searchname]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $updates = $this->updater();
        self::assertFalse($updates->updatePredbCandidate($input, $title, 7, 'stale-index'));
        DB::table('predb')->insert(['id' => 7, 'title' => 'Different.Release.2025-OTHER']);
        self::assertFalse($updates->updatePredbCandidate($input, $title, 7, 'stale-index'));
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($searchname, $release->fresh()->searchname);
        self::assertSame(0, $updates->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_mapped_filename_change_at_write_boundary_rolls_back(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        $title = 'Exact.Release.2026-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => 'publisher-file.mkv']);
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$changed): void {
            if (! $changed && str_starts_with($query, 'update "releases"')) {
                $changed = true;
                DB::table('predb')->where('id', 7)->update(['filename' => 'replacement-file.mkv']);
            }
        });
        $updates = $this->updater();
        $updates->updateRelease($input, $title, 'PreDB', true, 'Filenames, ', true, false, 7, 'publisher-file');
        self::assertTrue($changed);
        self::assertFalse($updates->matched);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string|null, string|null, bool}> */
    public static function previewEvidence(): array
    {
        return [
            'missing row' => [null, null, false],
            'wrong canonical title' => ['Other.Release.2025-OTHER', 'publisher-file.mkv', false],
            'wrong filename relationship' => ['Exact.Release.2026-GRP', 'other-file.mkv', false],
            'current authoritative mapping' => ['Exact.Release.2026-GRP', 'Folder/PUBLISHER-FILE.MKV', true],
        ];
    }

    #[DataProvider('previewEvidence')]
    public function test_preview_requires_current_authoritative_evidence(?string $currentTitle, ?string $currentFilename, bool $valid): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE predb ADD COLUMN filename TEXT');
        if ($currentTitle !== null) {
            DB::table('predb')->insert(['id' => 7, 'title' => $currentTitle, 'filename' => $currentFilename]);
        }
        $release = $this->pendingPar2Release(['searchname' => 'Hashed.Title']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id, 'filename' => 'publisher-file.mkv']);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $this->certifyFilenameSqlFixture();
        $writes = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$writes): void {
            if (preg_match('/^(?:update|insert|delete)/i', $query) === 1) {
                $writes++;
            }
        });
        $updates = $this->updater();
        if ($currentTitle !== null && $currentTitle !== 'Exact.Release.2026-GRP') {
            self::assertNull($updates->currentPredbTitle(7, 'Exact.Release.2026-GRP', 'publisher-file.mkv'), 'A stale claimed canonical title remains rejected independently of current alias nomination.');
        }
        $currentAliasOwner = $currentTitle !== null && $currentFilename === 'publisher-file.mkv';
        self::assertSame($valid || $currentAliasOwner ? 1 : 0, (new NameFixingService($updates))->matchPreDbFiles($input, false, true, false));
        self::assertSame(0, $writes);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Hashed.Title', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_fulltext_attachment_captures_caller_identity_before_authoritative_read(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        $title = 'Exact.Release.2026-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => $title]);
        $replacement = $this->pendingPar2Release(['searchname' => $title]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
        $changed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$changed, $input, $replacement): void {
            if (! $changed && str_starts_with($query, 'select * from "predb"')) {
                $changed = true;
                foreach ($replacement->getAttributes() as $key => $value) {
                    $input->{$key} = $value;
                }
                $input->releases_id = $replacement->id;
            }
        });
        self::assertTrue($this->updater()->updatePredbCandidate($input, $title, 7, 'authoritative'));
        self::assertTrue($changed);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame(0, $replacement->fresh()->predb_id);
    }

    private function updater(): ReleaseUpdateService
    {
        return new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
    }

    /** Explicit migration from legacy nullable/TEXT fixture, only for SQL filename routes. */
    private function certifyFilenameSqlFixture(): void
    {
        if (! Schema::hasTable('predb_identity_state')) {
            $columns = array_column(DB::select('PRAGMA table_info(predb)'), 'name');
            $filename = in_array('filename', $columns, true) ? "COALESCE(filename,'')" : "''";
            DB::statement('ALTER TABLE predb RENAME TO legacy_predb_fixture');
            DB::statement("CREATE TABLE predb (id INTEGER PRIMARY KEY NOT NULL, title varchar NOT NULL, filename varchar NOT NULL DEFAULT '', source varchar, size varchar, category varchar, nuked INTEGER DEFAULT 0)");
            DB::statement("INSERT INTO predb(id,title,filename) SELECT id,title,$filename FROM legacy_predb_fixture");
            foreach (['source', 'size', 'category', 'nuked'] as $column) {
                if (in_array($column, $columns, true)) {
                    DB::statement('UPDATE predb SET '.$column.'=(SELECT '.$column.' FROM legacy_predb_fixture old WHERE old.id=predb.id)');
                }
            }
            DB::statement('DROP TABLE legacy_predb_fixture');
            (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        }
        if (! Schema::hasTable('release_files')) {
            DB::statement('CREATE TABLE release_files (releases_id INTEGER NOT NULL,name varchar NOT NULL,crc32 varchar)');
        }
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }
}
