<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollectionFileCheckStatus;
use App\Enums\NzbImportStatus;
use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Predb;
use App\Services\CollectionCleanupService;
use App\Services\IRCScraper;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbService;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\ReleaseCleaningService;
use App\Services\ReleaseCreationService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Support\PredbSearchDocument;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class PredbMatchingTest extends TestCase
{
    private string $databasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'nntmux-predb-test-');
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->databasePath] as $key => $value) {
            $this->originalEnvironment[$key] = getenv($key);
            $this->environment($key, $value);
        }
        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings VALUES ('categorizeforeign', '0'), ('catwebdl', '0')");
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.required' => false]);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath, 'nntmux.echocli' => false]);
        DB::purge();
        DB::reconnect();
        Cache::flush();
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, filename TEXT, source TEXT, size TEXT, category TEXT, nuked INTEGER DEFAULT 0)');
        DB::table('predb')->insert(['id' => 7, 'title' => 'Canonical.Scene-GROUP', 'filename' => 'File.Name-GROUP', 'source' => 'srrdb']);
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, searchname TEXT, totalpart INTEGER, groups_id INTEGER, adddate TEXT, guid TEXT, leftguid TEXT, postdate TEXT, fromname TEXT, size INTEGER, passwordstatus INTEGER, haspreview INTEGER, categories_id INTEGER, nfostatus INTEGER, nzbstatus INTEGER, isrenamed INTEGER, iscategorized INTEGER, predb_id INTEGER)');
        DB::statement('PRAGMA foreign_keys = ON');
        (require base_path('database/migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name TEXT)');
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unlink($this->databasePath);
        foreach ($this->originalEnvironment as $key => $value) {
            $this->environment($key, $value === false ? null : $value);
        }
    }

    /** @return array<string, array{string}> */
    public static function exactNames(): array
    {
        return ['title' => ['canonical.scene-group'], 'filename' => ['file.name-group']];
    }

    #[DataProvider('exactNames')]
    public function test_match_pre_validates_by_primary_key_only(string $name): void
    {
        Search::shouldReceive('matchPredbExact')->once()->with($name)->andReturn($this->hit());
        DB::enableQueryLog();
        DB::flushQueryLog();

        self::assertSame(['title' => 'Canonical.Scene-GROUP', 'predb_id' => 7], Predb::matchPre($name));
        $queries = DB::getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('where "predb"."id" = ?', $queries[0]['query']);
        self::assertSame([7], $queries[0]['bindings']);
    }

    /** @return array<string, array{int, string}> */
    public static function staleHits(): array
    {
        return [
            'deleted record' => [99, 'Canonical.Scene-GROUP'],
            'changed title and filename' => [7, 'Old.Scene-GROUP'],
            'punctuation mismatch' => [7, 'Canonical Scene-GROUP'],
            'invalid id' => [0, 'Canonical.Scene-GROUP'],
        ];
    }

    #[DataProvider('staleHits')]
    public function test_missing_or_stale_records_are_rejected(int $id, string $name): void
    {
        Search::shouldReceive('matchPredbExact')->once()->andReturn(array_replace($this->hit(), ['id' => $id]));
        self::assertFalse(Predb::matchPre($name));
    }

    public function test_empty_input_and_outage_do_not_query_predb(): void
    {
        Search::shouldReceive('matchPredbExact')->once()->with('Canonical.Scene-GROUP')->andThrow(new RuntimeException('offline'));
        Log::shouldReceive('warning')->once()->with('PreDB exact lookup failed', ['exception_class' => RuntimeException::class]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertFalse(Predb::matchPre('  '));
        self::assertFalse(Predb::matchPre('Canonical.Scene-GROUP'));
        self::assertSame([], DB::getQueryLog());
    }

    /** @return array<string, array{bool, bool}> */
    public static function fileMatches(): array
    {
        return ['rename quiet' => [true, false], 'rename verbose' => [true, true], 'same name quiet' => [false, false], 'same name verbose' => [false, true]];
    }

    #[DataProvider('fileMatches')]
    public function test_filename_matching_preserves_id_independently_of_output(bool $rename, bool $output): void
    {
        config(['nntmux.echocli' => $output]);
        $updates = $this->createMock(ReleaseUpdateService::class);
        if ($rename) {
            $updates->expects(self::once())->method('updateRelease')->with(self::anything(), 'Canonical.Scene-GROUP', 'file matched source: srrdb', true, 'PreDB file match, ', true, false, 7)
                ->willReturnCallback(static function () use ($updates): void {
                    $updates->matched = true;
                });
            $updates->expects(self::never())->method('attachPredbSnapshot');
        } else {
            $updates->expects(self::once())->method('attachPredbSnapshot')->with(self::callback(static fn (object $release): bool => (int) $release->releases_id === 42), 7)->willReturn(true);
            $updates->expects(self::never())->method('updateRelease');
        }
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $service = new NameFixingService(updateService: $updates);
        $this->certifyFilenameSqlFixture();
        self::assertSame(1, $service->matchPreDbFiles((object) ['releases_id' => 42, 'filename' => 'File.Name-GROUP.mkv', 'searchname' => $rename ? 'Old.Title' : 'Canonical.Scene-GROUP'], true, true, false));
    }

    public function test_filename_match_does_not_attach_id_when_writes_are_disabled(): void
    {
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects(self::once())->method('currentPredbTitle')->with(7, 'Canonical.Scene-GROUP', 'File.Name-GROUP.mkv')->willReturn('Canonical.Scene-GROUP');
        $updates->expects(self::never())->method('updateRelease');
        $updates->expects(self::never())->method('attachPredbSnapshot');
        Search::shouldReceive('searchPredbFilenameCandidates')->never();

        $this->certifyFilenameSqlFixture();
        self::assertSame(1, (new NameFixingService(updateService: $updates))->matchPreDbFiles((object) ['releases_id' => 42, 'filename' => 'File.Name-GROUP.mkv', 'searchname' => 'Canonical.Scene-GROUP'], false, true, false));
    }

    #[DataProvider('fileMatches')]
    public function test_filename_match_saves_association_with_real_update_service(bool $rename, bool $output): void
    {
        config(['nntmux.echocli' => $output]);
        foreach (['videos_id', 'tv_episodes_id', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'book_lookup_attempts', 'book_lookup_retry_at', 'book_name_normalized_at', 'tv_lookup_attempts', 'tv_lookup_retry_at', 'anidbid'] as $column) {
            DB::statement('ALTER TABLE releases ADD COLUMN '.$column.' TEXT');
        }
        DB::table('releases')->insert(['id' => 42, 'guid' => 'synthetic-release-42', 'name' => 'posted-subject', 'searchname' => $rename ? 'Old.Title' : 'Canonical.Scene-GROUP', 'predb_id' => 0, 'categories_id' => 7010, 'groups_id' => 1]);
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        Search::shouldReceive('updateRelease')->times($rename ? 1 : 0)->with(42);
        $release = DB::table('releases')->first();
        $release->releases_id = 42;
        $release->filename = 'File.Name-GROUP.mkv';

        $this->certifyFilenameSqlFixture();
        self::assertSame(1, (new NameFixingService)->matchPreDbFiles($release, true, true, false));
        self::assertSame(7, DB::table('releases')->value('predb_id'));
        self::assertSame('Canonical.Scene-GROUP', DB::table('releases')->value('searchname'));
    }

    public function test_invalid_backend_document_and_unknown_sql_owner_cannot_link(): void
    {
        self::assertNull(PredbSearchDocument::filenameCandidate(['_id' => 0, '_source' => $this->hit()]));
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects(self::never())->method('updateRelease');
        $updates->expects(self::never())->method('attachPredbSnapshot');
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $this->certifyFilenameSqlFixture();
        DB::table('predb_identity_revisions')->where('predb_id', 7)->update(['projected_revision' => null]);
        self::assertSame(0, (new NameFixingService(updateService: $updates))->matchPreDbFiles((object) ['releases_id' => 42, 'filename' => 'File.Name-GROUP.mkv', 'searchname' => 'Old.Title'], false, true, false));
    }

    /** @return array<string, array{int, string}> */
    public static function imports(): array
    {
        return ['cleaned filename' => [0, ''], 'selected NZB name' => [0, 'File.Name-GROUP'], 'cleaner id' => [7, '']];
    }

    #[DataProvider('imports')]
    public function test_import_persists_matched_or_cleaner_predb_id(int $cleanerId, string $nzbName): void
    {
        $this->app->instance(NzbService::class, $this->createStub(NzbService::class));
        $cleaner = $this->createMock(ReleaseCleaningService::class);
        $cleaner->expects($nzbName === '' ? self::once() : self::never())->method('releaseCleaner')->willReturn(['cleansubject' => 'File.Name-GROUP', 'predb' => $cleanerId]);
        $service = new NzbImportService;
        (new ReflectionProperty($service, 'releaseCleaner'))->setValue($service, $cleaner);
        Search::shouldReceive('matchPredbExact')->times($cleanerId > 0 ? 0 : 1)->with('File.Name-GROUP')->andReturn($this->hit());
        Search::shouldReceive('updateRelease')->once()->with(1);

        $result = (new ReflectionMethod($service, 'insertNZB'))->invoke($service, [
            'subject' => '"File.Name-GROUP.mkv" yEnc (1/1)', 'from' => 'poster', 'groupName' => 'alt.binaries.test',
            'useFName' => $nzbName, 'totalSize' => 1000, 'totalFiles' => 1, 'groups_id' => 1,
            'postDate' => '2026-10-01 00:00:00', 'nzbCategoryId' => 7010,
        ]);
        self::assertSame(NzbImportStatus::Inserted, $result);
        self::assertSame(7, DB::table('releases')->value('predb_id'));
        self::assertSame($cleanerId > 0 ? 'File.Name-GROUP' : 'Canonical.Scene-GROUP', DB::table('releases')->value('searchname'));
    }

    public function test_import_continues_when_backend_fails(): void
    {
        $this->app->instance(NzbService::class, $this->createStub(NzbService::class));
        Search::shouldReceive('matchPredbExact')->once()->andThrow(new RuntimeException('offline'));
        Search::shouldReceive('updateRelease')->once()->with(1);
        Log::shouldReceive('warning')->once();
        $service = new NzbImportService;

        $result = (new ReflectionMethod($service, 'insertNZB'))->invoke($service, [
            'subject' => 'subject yEnc (1/1)', 'from' => 'poster', 'groupName' => 'alt.binaries.test',
            'useFName' => 'File.Name-GROUP', 'totalSize' => 1000, 'totalFiles' => 1, 'groups_id' => 1,
            'postDate' => '2026-10-01 00:00:00', 'nzbCategoryId' => 7010,
        ]);

        self::assertSame(NzbImportStatus::Inserted, $result);
        self::assertSame(0, DB::table('releases')->value('predb_id'));
        self::assertSame('File.Name-GROUP', DB::table('releases')->value('searchname'));
    }

    /** @return array<string, array{mixed}> */
    public static function cleanerIds(): array
    {
        return ['zero' => [0], 'false' => [false], 'absent' => [null], 'positive' => [7]];
    }

    #[DataProvider('cleanerIds')]
    public function test_creation_matches_zero_or_absent_cleaner_ids_and_preserves_positive_ids(mixed $cleanerId): void
    {
        DB::statement('CREATE TABLE collections (id INTEGER PRIMARY KEY, subject TEXT, fromname TEXT, groups_id INTEGER, filecheck INTEGER, filesize INTEGER, totalfiles INTEGER, date TEXT, xref TEXT, collection_regexes_id INTEGER, releases_id INTEGER)');
        DB::statement('CREATE TABLE release_regexes (releases_id INTEGER, collection_regex_id INTEGER, naming_regex_id INTEGER)');
        DB::statement('CREATE TABLE releases_groups (releases_id INTEGER, groups_id INTEGER)');
        DB::statement('CREATE TABLE category_regexes (id INTEGER, group_regex TEXT, regex TEXT, status INTEGER, ordinal INTEGER, categories_id INTEGER)');
        DB::table('collections')->insert(['id' => 1, 'subject' => 'File.Name-GROUP', 'fromname' => 'poster', 'groups_id' => 1, 'filecheck' => CollectionFileCheckStatus::Sized->value, 'filesize' => 1000, 'totalfiles' => 1, 'date' => '2026-10-01 00:00:00', 'xref' => '', 'collection_regexes_id' => 0]);
        $cleaner = $this->createMock(ReleaseCleaningService::class);
        $meta = ['cleansubject' => 'File.Name-GROUP', 'properlynamed' => false];
        if ($cleanerId !== null) {
            $meta['predb'] = $cleanerId;
        }
        $cleaner->expects(self::once())->method('releaseCleaner')->willReturn($meta);
        Search::shouldReceive('matchPredbExact')->times((int) $cleanerId > 0 ? 0 : 1)->andReturn($this->hit());
        Search::shouldReceive('updateRelease')->once()->with(1);
        $service = new ReleaseCreationService($cleaner, $this->createStub(CollectionCleanupService::class), new ReleaseDuplicateFinder);

        self::assertSame(['added' => 1, 'dupes' => 0], $service->createReleases(null, 10, false));
        self::assertSame(7, DB::table('releases')->value('predb_id'));
    }

    public function test_partial_irc_update_indexes_persisted_filename_and_source(): void
    {
        Search::shouldReceive('updatePreDb')->once()->with($this->hit());
        $scraper = new class extends IRCScraper
        {
            public function __construct()
            {
                $this->_curPre = ['title' => 'Canonical.Scene-GROUP', 'nuked' => 2];
                $this->_oldPre = Predb::query()->find(7, ['id', 'title', 'category', 'size', 'source']);
                $this->_debug = false;
                $this->_silent = true;
            }

            public function updatePre(): void
            {
                $this->_updatePre();
            }

            protected function _doEcho(bool $new = true): void {}
        };
        $scraper->updatePre();
        self::assertSame(2, DB::table('predb')->value('nuked'));
    }

    /** @return array{id: int, title: string, filename: string, source: string} */
    private function hit(): array
    {
        return ['id' => 7, 'title' => 'Canonical.Scene-GROUP', 'filename' => 'File.Name-GROUP', 'source' => 'srrdb'];
    }

    private function environment(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    /** @return array<string, array{bool, bool}> */
    public static function numericPrefixPaths(): array
    {
        return [
            'subject numbered' => [false, true],
            'files numbered' => [true, true],
            'subject unnumbered' => [false, false],
            'files unnumbered' => [true, false],
        ];
    }

    #[DataProvider('numericPrefixPaths')]
    public function test_numeric_prefix_incoming_paths_preserve_canonical_identity(bool $filesPath, bool $numbered): void
    {
        $hits = [
            ['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv', 'source' => 'fixture'],
            ['id' => 92, 'title' => 'Fixture.Canonical.Beta.2026-GRP', 'filename' => 'Fixture.Alias.2026-GRP.mkv', 'source' => 'fixture'],
        ];
        DB::table('predb')->insert($hits);
        $hit = $hits[$numbered ? 0 : 1];
        $evidence = $numbered ? '123.Fixture.Alias.2026-GRP' : 'Fixture.Alias.2026-GRP';
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $updates = $this->getMockBuilder(ReleaseUpdateService::class)->onlyMethods(['updateRelease'])->getMock();
        $updates->expects(self::once())->method('updateRelease')
            ->with(self::anything(), $hit['title'], self::anything(), false, self::anything(), true, false, $hit['id'], $hit['filename'])
            ->willReturnCallback(static function () use ($updates): void {
                $updates->matched = true;
            });
        $service = new NameFixingService(updateService: $updates);
        $release = (object) ['releases_id' => 42, 'filename' => $hit['filename'], 'textstring' => $hit['filename'], 'searchname' => 'Opaque.Fixture'];
        $this->certifyFilenameSqlFixture();
        $result = $filesPath ? $service->matchPreDbFiles($release, false, true, false)
            : (new ReflectionMethod($service, 'preDbFileCheck'))->invoke($service, $release, false, 'fixture', true, false);
        self::assertSame($filesPath ? 1 : true, $result);
    }

    /** @return array<string, array{bool, bool}> */
    public static function numericPrefixAmbiguityPaths(): array
    {
        return [
            'subject conflicting titles' => [false, false],
            'files conflicting titles' => [true, false],
            'subject duplicate IDs' => [false, true],
            'files duplicate IDs' => [true, true],
        ];
    }

    #[DataProvider('numericPrefixAmbiguityPaths')]
    public function test_numeric_prefix_incoming_paths_reject_ambiguous_aliases(bool $filesPath, bool $sameTitle): void
    {
        $hits = [
            ['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv'],
            ['id' => 92, 'title' => $sameTitle ? 'Fixture.Canonical.Alpha.2026-GRP' : 'Fixture.Canonical.Beta.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv'],
        ];
        DB::table('predb')->insert($hits);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $updates = $this->createMock(ReleaseUpdateService::class);
        $updates->expects(self::never())->method('updateRelease');
        $updates->expects(self::never())->method('attachPredbSnapshot');
        $service = new NameFixingService(updateService: $updates);
        $release = (object) ['releases_id' => 42, 'filename' => $hits[0]['filename'], 'textstring' => $hits[0]['filename'], 'searchname' => 'Opaque.Fixture'];
        $this->certifyFilenameSqlFixture();
        $result = $filesPath ? $service->matchPreDbFiles($release, false, true, false)
            : (new ReflectionMethod($service, 'preDbFileCheck'))->invoke($service, $release, false, 'fixture', true, false);
        self::assertSame($filesPath ? 0 : false, $result);
    }

    public function test_numeric_prefix_revalidates_current_canonical_filename_and_rejects_stale_identity(): void
    {
        DB::table('predb')->insert(['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv']);
        $updates = new ReleaseUpdateService;
        self::assertSame('Fixture.Canonical.Alpha.2026-GRP', $updates->currentPredbTitle(91, 'Fixture.Canonical.Alpha.2026-GRP', '123.Fixture.Alias.2026-GRP'));
        self::assertNull($updates->currentPredbTitle(91, 'Fixture.Canonical.Alpha.2026-GRP', 'Fixture.Alias.2026-GRP'));
        DB::table('predb')->where('id', 91)->update(['filename' => 'Fixture.Alias.2026-GRP.mkv']);
        self::assertNull($updates->currentPredbTitle(91, 'Fixture.Canonical.Alpha.2026-GRP', '123.Fixture.Alias.2026-GRP'));
        self::assertSame('Fixture.Canonical.Alpha.2026-GRP', $updates->currentPredbTitle(91, 'Fixture.Canonical.Alpha.2026-GRP', 'Fixture.Alias.2026-GRP'));
    }

    #[DataProvider('numericPrefixPaths')]
    public function test_numeric_prefix_incoming_paths_persist_real_canonical_association(bool $filesPath, bool $numbered): void
    {
        foreach (['videos_id', 'tv_episodes_id', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'book_lookup_attempts', 'book_lookup_retry_at', 'book_name_normalized_at', 'tv_lookup_attempts', 'tv_lookup_retry_at', 'anidbid'] as $column) {
            DB::statement('ALTER TABLE releases ADD COLUMN '.$column.' TEXT');
        }
        $hits = [
            ['id' => 91, 'title' => 'Fixture.Canonical.Alpha.2026-GRP', 'filename' => '123.Fixture.Alias.2026-GRP.mkv', 'source' => 'fixture'],
            ['id' => 92, 'title' => 'Fixture.Canonical.Beta.2026-GRP', 'filename' => 'Fixture.Alias.2026-GRP.mkv', 'source' => 'fixture'],
        ];
        DB::table('predb')->insert($hits);
        DB::table('releases')->insert(['id' => 42, 'guid' => 'numeric-fixture-release-42', 'name' => 'fixture-subject', 'searchname' => 'Opaque.Fixture', 'predb_id' => 0, 'categories_id' => 7010, 'groups_id' => 1]);
        $hit = $hits[$numbered ? 0 : 1];
        $evidence = $numbered ? '123.Fixture.Alias.2026-GRP' : 'Fixture.Alias.2026-GRP';
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        Search::shouldReceive('updateRelease')->once()->with(42);
        $release = DB::table('releases')->first();
        $release->releases_id = 42;
        $release->filename = $release->textstring = $hit['filename'];
        $service = new NameFixingService;
        $this->certifyFilenameSqlFixture();
        $result = $filesPath ? $service->matchPreDbFiles($release, true, true, false)
            : (new ReflectionMethod($service, 'preDbFileCheck'))->invoke($service, $release, true, 'fixture', true, false);
        self::assertSame($filesPath ? 1 : true, $result);
        self::assertSame($hit['id'], DB::table('releases')->value('predb_id'));
        self::assertSame($hit['title'], DB::table('releases')->value('searchname'));
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
