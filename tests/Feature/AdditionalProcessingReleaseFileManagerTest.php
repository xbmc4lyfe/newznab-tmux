<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\AdditionalProcessing\AdditionalWorkPlanner;
use App\Services\AdditionalProcessing\ArchiveExtractionService;
use App\Services\AdditionalProcessing\ConsoleOutputService;
use App\Services\AdditionalProcessing\DTO\DownloadMetrics;
use App\Services\AdditionalProcessing\Enums\DownloadKind;
use App\Services\AdditionalProcessing\Enums\ProcessingOutcome;
use App\Services\AdditionalProcessing\MediaExtractionService;
use App\Services\AdditionalProcessing\NzbContentParser;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\ReleaseFilesArchiveFallback;
use App\Services\AdditionalProcessing\ReleaseProcessor;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\AdditionalProcessing\UsenetDownloadService;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionCleanupService;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use App\Services\Yenc\YencPart;
use App\Support\ReleaseArchiveNamingEvidence;
use dariusiii\rarinfo\Par2Info;
use dariusiii\rarinfo\RarInfo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Fixtures\MappingArticleNntpHarness;
use Tests\Fixtures\YencArticles;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\BuildsArchiveFixtures;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

class AdditionalProcessingReleaseFileManagerTest extends TestCase
{
    use BuildsArchiveFixtures;
    use CreatesProcessingConfiguration;

    private string $databasePath;

    private string $sourceDirectory;

    private ?\Closure $filenameDiscoveryMutation = null;

    /** @var list<string> */
    private array $par2HashFixtureFiles = [];

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-release-file-manager-test-'.bin2hex(random_bytes(8)).'.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
        ]);

        DB::purge();
        DB::reconnect();

        $this->createSchema();
        $this->sourceDirectory = sys_get_temp_dir().'/manager-source-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->sourceDirectory);
        config(['nntmux_settings.path_to_nzbs' => $this->sourceDirectory.'/']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        foreach ($this->par2HashFixtureFiles as $fixture) {
            unlink($fixture);
        }

        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        File::deleteDirectory($this->sourceDirectory);
        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_nzbsplit_rejected_write_does_not_report_success_or_mutate_context(): void
    {
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'WinterOlympics2026__NZBSPLIT__0456f274737cea074abd86a89144cc7b__NZBSPLIT__Winter_Olympic_Games_Milano_Cortina_2026_Closing_Ceremony_1080p25_WEB-DL_(MultiAudio).7z.065';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        DB::table('releases')->where('id', 1)->update(['guid' => 'replacement-guid', 'searchname' => 'Replacement.Identity']);
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $renamed = $this->makeFullPar2NamingManager($sync)->processReleaseNameFromNzbContents([['title' => $wrapped]], $context);
        self::assertSame(['renamed' => false, 'context' => $wrapped, 'database' => 'Replacement.Identity', 'sync' => []], [
            'renamed' => $renamed, 'context' => $context->release->searchname,
            'database' => DB::table('releases')->where('id', 1)->value('searchname'), 'sync' => $published,
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function producerKinds(): iterable
    {
        foreach (['nzbsplit', 'rar', 'par2'] as $kind) {
            yield $kind => [$kind];
        }
    }

    #[DataProvider('producerKinds')]
    public function test_lost_claim_does_not_publish_a_producer_rename(string $kind): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'replacement-owner']);
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        if ($kind === 'nzbsplit') {
            $manager->processReleaseNameFromNzbContents([['title' => $wrapped]], $context);
        } elseif ($kind === 'rar') {
            $manager->processReleaseNameFromRar(['file_list' => [['name' => $title.'.mkv']]], $context);
        } else {
            $manager->processPar2File($this->fullPar2Fixture(2, names: [$title, 'second.bin']), $context, new Par2Info);
        }
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();
        self::assertSame(['database' => $wrapped, 'sync' => [], 'claim' => 'replacement-owner'], [
            'database' => DB::table('releases')->where('id', 1)->value('searchname'), 'sync' => $published,
            'claim' => DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'),

        ]);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return iterable<string, array{string, string}> */
    public static function producerClaimSqlRaces(): iterable
    {
        foreach (['nzbsplit', 'rar', 'par2'] as $kind) {
            foreach (['additional_pp_claim_token', 'additional_pp_claimed_at'] as $column) {
                yield $kind.' '.$column => [$kind, $column];
            }
        }
    }

    #[DataProvider('producerClaimSqlRaces')]
    public function test_claim_replacement_at_naming_sql_cannot_publish_or_finalize(string $kind, string $column): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $replacement = $column === 'additional_pp_claim_token' ? 'replacement-owner' : now()->addMinute()->toDateTimeString();
        $fired = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$fired, $column, $replacement): void {
            if ($fired || ! str_starts_with(strtolower($query), 'update "releases"') || ! str_contains($query, '"searchname"')) {
                return;
            }
            $fired = true;
            DB::connection()->getPdo()->prepare('UPDATE releases SET '.$column.' = ? WHERE id = 1')->execute([$replacement]);
        });
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        $this->attemptProducerNaming($kind, $manager, $context, $title, $wrapped);
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        self::assertTrue($fired);
        self::assertSame($wrapped, DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame($replacement, DB::table('releases')->where('id', 1)->value($column));
        self::assertSame([], $published);
        self::assertSame(0, DB::table('release_files')->count());
        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertArrayNotHasKey('observed_additional_pp_claim', $context->release->getAttributes());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('producerKinds')]
    public function test_claim_loss_after_committed_producer_name_preserves_the_new_owner(string $kind): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        $this->attemptProducerNaming($kind, $manager, $context, $title, $wrapped);
        $committed = DB::table('releases')->where('id', 1)->value('searchname');
        self::assertNotSame($wrapped, $committed);
        self::assertSame($committed, $context->fullPar2EvidenceSnapshot()['searchname']);
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'replacement-owner']);
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();
        self::assertSame('replacement-owner', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        self::assertSame($committed, DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame([], $published);
        self::assertTrue($context->fullPar2EvidenceDeferred);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public function test_outer_rollback_never_advances_producer_context_or_publishes(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        DB::beginTransaction();
        try {
            self::assertFalse($this->makeFullPar2NamingManager($sync)->processReleaseNameFromNzbContents([['title' => $wrapped]], $context));
            self::assertSame($wrapped, $context->release->searchname);
            self::assertSame($wrapped, $context->fullPar2EvidenceSnapshot()['searchname']);
            self::assertNotSame($wrapped, DB::table('releases')->where('id', 1)->value('searchname'));
        } finally {
            DB::rollBack();
        }
        self::assertSame($wrapped, DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame($wrapped, $context->release->searchname);
        self::assertSame([], $published);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('producerKinds')]
    public function test_outer_commit_advances_producer_context_only_after_commit(string $kind): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence($title);
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        DB::beginTransaction();
        $this->attemptProducerNaming($kind, $this->makeFullPar2NamingManager($sync), $context, $title, $wrapped);
        self::assertSame($wrapped, $context->release->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        $committed = DB::table('releases')->where('id', 1)->value('searchname');
        self::assertNotSame($wrapped, $committed);
        DB::commit();
        self::assertSame($committed, $context->release->searchname);
        self::assertSame($committed, $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertFalse($context->fullPar2EvidenceDeferred);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public function test_reset_after_committed_name_preserves_foreign_claim_and_rejects_legacy_metadata(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        self::assertTrue($manager->processReleaseNameFromNzbContents([['title' => $wrapped]], $context));
        $context->resetCounters();
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'replacement-owner']);
        self::assertTrue($manager->addFileInfo(['name' => '.hidden.mkv', 'size' => 123], $context, 'nfo|par2'));
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();
        self::assertSame('replacement-owner', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        self::assertSame([], $published);
        self::assertSame(0, DB::table('release_files')->count());
        self::assertTrue($context->fullPar2EvidenceDeferred);
    }

    public function test_outer_replacement_does_not_advance_producer_context(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        DB::beginTransaction();
        self::assertFalse($manager->processReleaseNameFromNzbContents([['title' => $wrapped]], $context));
        DB::table('releases')->where('id', 1)->update(['guid' => 'replacement-guid', 'name' => 'replacement-name', 'searchname' => 'replacement-search']);
        DB::commit();
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();
        self::assertSame($wrapped, $context->release->searchname);
        self::assertSame($wrapped, $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertSame('replacement-search', DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame([], $published);
    }

    public function test_reset_prevents_late_producer_identity_advance(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $claim = $context->producerNamingClaim;
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        DB::beginTransaction();
        $this->makeFullPar2NamingManager($sync)->processReleaseNameFromNzbContents([['title' => $wrapped]], $context);
        self::assertTrue($context->producerNamingAttempted);
        $context->resetCounters();
        DB::commit();
        self::assertTrue($context->producerNamingAttempted);
        self::assertSame($claim, $context->producerNamingClaim);
        self::assertSame($wrapped, $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertSame($wrapped, $context->release->searchname);
    }

    public function test_legacy_context_without_claim_columns_can_apply_an_attested_name(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        Schema::table('releases', static function (Blueprint $table): void {
            $table->dropColumn(['additional_pp_claim_token', 'additional_pp_claimed_at']);
        });
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $wrapped]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        self::assertNull($context->producerNamingClaim);
        self::assertTrue($this->makeFullPar2NamingManager($sync)->processReleaseNameFromNzbContents([['title' => $wrapped]], $context));
        self::assertSame($title, $context->release->searchname);
        self::assertFalse($context->producerNamingAttempted);
    }

    public function test_same_name_producer_noop_does_not_defer_or_publish(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        $wrapped = 'TEST__NZBSPLIT__1234567890abcdef__NZBSPLIT__'.$title.'.mkv';
        DB::table('releases')->where('id', 1)->update(['name' => $wrapped, 'searchname' => $title]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        self::assertFalse($this->makeFullPar2NamingManager($sync)->processReleaseNameFromNzbContents([['title' => $wrapped]], $context));
        self::assertSame($title, $context->release->searchname);
        self::assertFalse($context->fullPar2EvidenceDeferred);
        self::assertSame([], $published);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_deferred_archive_filename_cannot_rename_after_the_additional_claim_is_replaced(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'replacement-owner']);
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);

        $manager->matchFileNamesToPreDb([['name' => $title.'.mkv']], $context);
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        self::assertSame('Example', DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        self::assertSame('replacement-owner', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        self::assertSame('Example', $context->release->searchname);
        self::assertSame('Example', $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertArrayNotHasKey('filename', $context->release->getAttributes());
        self::assertArrayNotHasKey('observed_additional_pp_claim', $context->release->getAttributes());
        self::assertSame([], $published);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_deferred_archive_filename_advances_committed_identity_before_metadata_finalization(): void
    {
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);

        $manager->matchFileNamesToPreDb([['name' => $title.'.mkv']], $context);

        self::assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame($title, $context->release->searchname);
        self::assertSame($title, $context->fullPar2EvidenceSnapshot()['searchname']);
        self::assertSame('Example', $context->par2EvidenceSnapshot['searchname']);
        self::assertArrayNotHasKey('filename', $context->release->getAttributes());
        self::assertArrayNotHasKey('observed_additional_pp_claim', $context->release->getAttributes());
        self::assertTrue($manager->addFileInfo(['name' => '.hidden.mkv', 'size' => 123], $context, 'nfo|par2'));
        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        self::assertFalse($context->fullPar2EvidenceDeferred);
        self::assertSame(1, DB::table('release_files')->count());
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        self::assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        self::assertSame([1], $published);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    private function attemptProducerNaming(string $kind, ReleaseFileManager $manager, ReleaseProcessingContext $context, string $title, string $wrapped): void
    {
        if ($kind === 'nzbsplit') {
            $manager->processReleaseNameFromNzbContents([['title' => $wrapped]], $context);
        } elseif ($kind === 'rar') {
            $manager->processReleaseNameFromRar(['file_list' => [['name' => $title.'.mkv']]], $context);
        } else {
            $manager->processPar2File($this->fullPar2Fixture(2, names: [$title, 'second.bin']), $context, new Par2Info);
        }
    }

    public function test_linked_par2_donor_retains_all_full_hashes_beyond_collection_limit(): void
    {
        $this->prepareFullPar2Evidence();
        $path = $this->fullPar2Fixture(130);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        $this->assertTrue($manager->processPar2File($path, $context, new Par2Info));
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $manager->finalizeRelease($context, false);

        $this->assertSame(130, DB::table('par_file_hashes')->count());
        $this->assertSame(11, DB::table('release_files')->count());
        $this->assertSame(11, (int) DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertSame(7, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_hash16k'));
    }

    public function test_disabled_par2_file_collection_still_retains_independent_content_evidence(): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => false]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        $manager->processPar2File($this->fullPar2Fixture(20), $context, new Par2Info);
        $manager->finalizeRelease($context, false);

        $this->assertSame(20, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
    }

    public function test_full_par2_donor_sizes_preserve_adjacent_unsigned_values(): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => false]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        $manager->processPar2File($this->fullPar2Fixture(2, [pack('V2', 0, 2147483648), pack('V2', 1, 2147483648)]), $context, new Par2Info);
        $manager->finalizeRelease($context, false);

        $this->assertSame(['9223372036854775808', '9223372036854775809'], DB::table('par_file_hashes')->orderBy('id')->pluck('file_size')->all());
    }

    public function test_long_valid_par2_descriptor_keeps_exact_unsigned_size(): void
    {
        $this->prepareFullPar2Evidence();
        $path = $this->fullPar2Fixture(1, [pack('V2', 1, 2147483648)], [str_repeat('directory/', 2000).'file.mkv']);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => false]);
        Search::shouldReceive('updateRelease')->once()->with(1);

        $this->assertTrue($manager->processPar2File($path, $context, new Par2Info));
        $manager->finalizeRelease($context, false);

        $this->assertSame('9223372036854775809', DB::table('par_file_hashes')->value('file_size'));
        $this->assertSame(md5('full-0'), DB::table('par_file_hashes')->value('full_md5'));
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(7, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
    }

    public function test_changed_long_descriptor_cannot_supply_an_unattested_unsigned_size(): void
    {
        $this->prepareFullPar2Evidence();
        $path = $this->fullPar2Fixture(1, [pack('V2', 1, 2147483648)], [str_repeat('directory/', 2000).'file.mkv']);
        $info = new Par2Info;
        $this->assertTrue($info->open($path));
        $bytes = file_get_contents($path);
        file_put_contents($path, substr_replace($bytes, pack('V2', 2, 2147483648), 112, 8));
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => false]);
        (new ReflectionMethod($manager, 'queueFullPar2Evidence'))->invoke($manager, $path, $context, $info);
        Search::shouldReceive('updateRelease')->once()->with(1);
        $manager->finalizeRelease($context, false);

        $this->assertSame('', DB::table('par_file_hashes')->value('file_size'));
        $this->assertSame(md5('full-0'), DB::table('par_file_hashes')->value('full_md5'));
    }

    public function test_lost_additional_claim_refuses_queued_hashes_files_and_publication(): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManager();
        $manager->processPar2File($this->fullPar2Fixture(2), $context, new Par2Info);
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'new-owner']);
        Search::shouldReceive('updateRelease')->never();
        $manager->finalizeRelease($context, false);

        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame('new-owner', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    public function test_same_guid_name_reset_defers_all_queued_par2_metadata(): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $manager->processPar2File($this->fullPar2Fixture(2), $context, new Par2Info);
        DB::table('releases')->where('id', 1)->update(['searchname' => 'replacement']);
        Search::shouldReceive('updateRelease')->never();
        $manager->finalizeRelease($context, false);

        $this->assertTrue($context->fullPar2EvidenceDeferred);
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame('replacement', DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertSame('token', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    public function test_expired_additional_claim_does_not_admit_full_par2_evidence(): void
    {
        $this->prepareFullPar2Evidence();
        DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => AdditionalCandidateQuery::claimStaleBefore()->subSecond()]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManager();
        $manager->processPar2File($this->fullPar2Fixture(2), $context, new Par2Info);
        Search::shouldReceive('updateRelease')->never();
        $manager->finalizeRelease($context, false);

        $this->assertTrue($context->fullPar2EvidenceDeferred);
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame('token', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    public function test_finalize_rollback_reverts_full_hashes_files_and_status_together(): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $manager->processPar2File($this->fullPar2Fixture(2), $context, new Par2Info);
        Search::shouldReceive('updateRelease')->never();
        $failOnce = true;
        DB::listen(function (QueryExecuted $query) use (&$failOnce): void {
            if ($failOnce && str_starts_with($query->sql, 'update "releases"')) {
                $failOnce = false;
                $this->assertSame(2, DB::table('par_file_hashes')->count());
                $this->assertSame(2, DB::table('release_files')->count());
                throw new \RuntimeException('forced-finalization-rollback');
            }
        });
        try {
            $manager->finalizeRelease($context, false);
            $this->fail('The finalization must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced-finalization-rollback', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, DB::table('par_hashes')->count());
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
        $this->assertSame('token', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    public function test_own_par2_canonical_name_and_metadata_commit_in_one_cycle(): void
    {
        $title = 'ROSALÍA.Album.2026-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        Search::shouldReceive('updateRelease')->once()->with(1);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);

        $this->assertTrue($manager->processPar2File($this->fullPar2Fixture(2, names: [$title, 'second.bin']), $context, new Par2Info));
        $this->assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertSame(7, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame($title, $context->fullPar2EvidenceSnapshot()['searchname']);
        $this->assertSame('Example', $context->par2EvidenceSnapshot['searchname']);

        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        $this->assertSame(2, DB::table('par_file_hashes')->count());
        $this->assertSame(2, DB::table('release_files')->count());
        $this->assertFalse($context->fullPar2EvidenceDeferred);
        $this->assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    #[DataProvider('fullPar2OwnershipMutationPoints')]
    public function test_full_par2_write_boundary_ownership_loss_rolls_back_without_publication(string $point): void
    {
        $this->prepareFullPar2Evidence();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $manager->processPar2File($this->fullPar2Fixture(2), $context, new Par2Info);
        Search::shouldReceive('updateRelease')->never();
        $fired = false;
        $mutate = function (string $sql) use ($point, $context, &$fired): void {
            $target = $point === 'file insert'
                ? str_contains($sql, 'insert or ignore into "release_files"')
                : str_starts_with($sql, 'update "releases"') && str_contains($sql, 'additional_pp_claim_token');
            if ($fired || ! $target) {
                return;
            }
            $fired = true;
            if ($point === 'caller alias') {
                $context->release->id = 2;
            } elseif ($point === 'claim timestamp') {
                DB::table('releases')->where('id', 1)->update(['additional_pp_claimed_at' => '2000-01-01 00:00:00']);
            } else {
                DB::table('releases')->where('id', 1)->update(['guid' => 'GUID-1', 'additional_pp_claim_token' => 'replacement-owner']);
            }
        };
        if ($point === 'claim timestamp') {
            DB::connection()->beforeExecuting($mutate);
        } else {
            DB::listen(static fn (QueryExecuted $event) => $mutate($event->sql));
        }

        try {
            $manager->finalizeRelease($context, false);
            $this->fail('Ownership loss must abort the metadata transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Additional-processing', $exception->getMessage());
        }

        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, DB::table('par_hashes')->count());
        $this->assertSame('guid-1', DB::table('releases')->where('id', 1)->value('guid'));
        $this->assertSame('token', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('haspreview'));
    }

    /** @return array<string, array{string}> */
    public static function fullPar2OwnershipMutationPoints(): array
    {
        return [
            'metadata insert' => ['file insert'],
            'terminal update' => ['terminal update'],
            'terminal timestamp predicate' => ['claim timestamp'],
            'terminal caller ID' => ['caller alias'],
        ];
    }

    #[DataProvider('fullPar2ExternalIdentityChanges')]
    public function test_external_identity_change_after_own_name_commit_discards_pending_sync(string $field, string|int $value): void
    {
        $title = 'ROSALÍA.Album.2026-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector);
        $sync->beginReleaseScope(1);
        $manager = $this->makeFullPar2NamingManager($sync);
        Search::shouldReceive('updateRelease')->never();
        $manager->processPar2File($this->fullPar2Fixture(2, names: [$title, 'second.bin']), $context, new Par2Info);
        $this->assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
        if ($field === 'caller_id') {
            $context->release->id = $value;
        } else {
            DB::table('releases')->where('id', 1)->update([$field => $value]);
        }

        $manager->finalizeRelease($context, false);
        $sync->finishReleaseScope();

        $this->assertTrue($context->fullPar2EvidenceDeferred);
        $this->assertSame(0, DB::table('par_file_hashes')->count());
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(7, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame($field === 'searchname' ? $value : $title, DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertSame($field === 'additional_pp_claim_token' ? $value : 'token', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    /** @return array<string, array{string, string|int}> */
    public static function fullPar2ExternalIdentityChanges(): array
    {
        return [
            'same GUID changed name' => ['searchname', 'External.Replacement'],
            'GUID case is identity' => ['guid', 'GUID-1'],
            'new claim owner' => ['additional_pp_claim_token', 'replacement-owner'],
            'mutable caller ID' => ['caller_id', 2],
        ];
    }

    public function test_context_advances_only_exact_attested_identity_and_retains_original_claim(): void
    {
        $release = new Release;
        $release->setRawAttributes(['id' => 1, 'guid' => 'aBcD', 'name' => 'original', 'searchname' => 'obfuscated', 'additional_pp_claim_token' => 'owner']);
        $context = new ReleaseProcessingContext($release);
        $before = ['id' => 1, 'guid' => 'aBcD', 'name' => 'original', 'searchname' => 'obfuscated'];
        $after = [...$before, 'searchname' => 'ROSALÍA.Album.2026-GRP'];
        foreach (['id' => 2, 'guid' => 'ABCD', 'name' => 'Original', 'searchname' => 'Obfuscated'] as $field => $value) {
            $this->assertFalse($context->advanceFullPar2EvidenceIdentity(['before' => [...$before, $field => $value], 'after' => $after]));
        }
        $this->assertFalse($context->advanceFullPar2EvidenceIdentity(['before' => $before, 'after' => [...$after, 'id' => 2]]));
        $this->assertFalse($context->advanceFullPar2EvidenceIdentity(['before' => $before, 'after' => [...$after, 'guid' => 'ABCD']]));
        $this->assertTrue($context->advanceFullPar2EvidenceIdentity(['before' => $before, 'after' => $after]));
        $this->assertSame([...$after, 'additional_pp_claim_token' => 'owner'], $context->fullPar2EvidenceSnapshot());
        $this->assertSame('obfuscated', $context->par2EvidenceSnapshot['searchname']);
        $this->assertFalse($context->advanceFullPar2EvidenceIdentity(['before' => $before, 'after' => $after]));
    }

    #[DataProvider('archiveLiteralIdentityCases')]
    public function test_archive_predb_discovery_requires_literal_unicode_identity(bool $nested, string $kind, int $expectedId): void
    {
        $title = 'Saturday.Night.Live.S52E03.Shane.Gillis.Rosalía.October.10.2026.1080p.HULU.WEB-DL.DDP5.1.H.264-None';
        $this->prepareFullPar2NamingEvidence($title);
        DB::statement('DROP TABLE predb');
        DB::connection()->getPdo()->sqliteCreateCollation('ACCENTLESS', static fn (string $left, string $right): int => strcmp(
            str_replace(['í', "i\u{0301}"], 'i', mb_strtolower($left)),
            str_replace(['í', "i\u{0301}"], 'i', mb_strtolower($right)),
        ));
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT COLLATE ACCENTLESS)');
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $filename = match ($kind) {
            'accent' => str_replace('Rosalía', 'Rosalia', $title),
            'decomposed' => str_replace('í', "i\u{0301}", $title),
            'case' => mb_strtoupper($title),
            default => throw new \InvalidArgumentException('Unknown archive filename identity case.'),
        };
        $summary = ['file_list' => [['name' => $filename.'.mkv']]];
        if ($nested) {
            $summary = ['file_list' => [['name' => 'opaque']], 'archives' => ['opaque' => $summary]];
        }
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $this->makeFullPar2NamingManager($sync)->processReleaseNameFromRar($summary, $context);

        $this->assertSame($expectedId, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        if ($expectedId === 7) {
            $this->assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
            $this->assertSame($title, $context->fullPar2EvidenceSnapshot()['searchname']);
        }
    }

    /** @return array<string, array{bool, string, int}> */
    public static function archiveLiteralIdentityCases(): array
    {
        return [
            'direct accent alias' => [false, 'accent', 0],
            'nested accent alias' => [true, 'accent', 0],
            'direct decomposed alias' => [false, 'decomposed', 0],
            'nested decomposed alias' => [true, 'decomposed', 0],
            'direct Unicode case' => [false, 'case', 7],
            'nested Unicode case' => [true, 'case', 7],
        ];
    }

    private function prepareFullPar2NamingEvidence(string $title): void
    {
        $this->prepareFullPar2Evidence();
        Schema::table('releases', function (Blueprint $table): void {
            foreach (['videos_id', 'tv_episodes_id', 'book_lookup_attempts', 'iscategorized', 'proc_par2'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'book_lookup_retry_at', 'book_name_normalized_at', 'anidbid'] as $column) {
                $table->string($column)->nullable();
            }
        });
        Schema::create('predb', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('title');
        });
        (require database_path('migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        DB::table('releases')->where('id', 1)->update(['predb_id' => 0, 'isrenamed' => 0, 'proc_pp' => 0, 'categories_id' => Category::OTHER_HASHED]);
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
    }

    private function makeFullPar2NamingManager(ReleaseSearchSyncCoordinator $sync, ?\Closure $observeFilenameCheck = null): ReleaseFileManager
    {
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $cleaner = $this->filenameDiscoveryCleaner();
        $nameFixing = $observeFilenameCheck === null
            ? new NameFixingService($updater, fileNameCleaner: $cleaner)
            : new class($updater, $cleaner, $observeFilenameCheck) extends NameFixingService
            {
                public function __construct(ReleaseUpdateService $updates, FileNameCleaner $fileNameCleaner, private readonly \Closure $observe)
                {
                    parent::__construct($updates, fileNameCleaner: $fileNameCleaner);
                }

                public function matchPreDbFileNames(object $release, array $files, bool $echo, bool $nameStatus, bool $show, ?array $persistedNames = null): int
                {
                    if (! isset($release->filename) || $release->filename === '') {
                        $release->filename = $files[0] ?? '';
                    }
                    ($this->observe)($release);

                    return parent::matchPreDbFileNames($release, $files, $echo, $nameStatus, $show, $persistedNames);
                }
            };

        return new ReleaseFileManager(
            $this->makeConfig(['renamePar2' => true, 'echoCLI' => true, 'addPAR2Files' => true]),
            new ReleaseImageService,
            new NfoService,
            new TestNzbService,
            $nameFixing,
            $updater,
            searchSyncCoordinator: $sync,
        );
    }

    private function prepareFullPar2Evidence(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dateTime('postdate')->nullable();
            $table->integer('proc_pp')->default(1);
            $table->integer('proc_hash16k')->default(1);
        });
        (require database_path('migrations/2026_10_12_010315_create_par_file_hash_evidence_tables.php'))->up();
        (require database_path('migrations/2026_10_12_220000_create_par_file_hash_inventories_table.php'))->up();
        DB::table('releases')->insert(array_replace($this->releaseRow(), [
            'postdate' => '2026-10-11 00:00:00', 'predb_id' => 7, 'isrenamed' => 1,
        ]));
    }

    /**
     * @param  list<string>  $rawSizes
     * @param  list<string>  $names
     */
    private function fullPar2Fixture(int $count, array $rawSizes = [], array $names = []): string
    {
        $bytes = '';
        $set = str_repeat('s', 16);
        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? 'file-'.$i.'.mkv';
            $size = $rawSizes[$i] ?? pack('V2', 123 + $i, 3);
            $prefix = md5('prefix-'.$i, true);
            $body = md5($prefix.$size.$name, true).md5('full-'.$i, true).$prefix.$size.$name;
            $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
            $type = Par2Info::PACKET_FILEDESC;
            $bytes .= "PAR2\0PKT".pack('V2', 64 + strlen($body), 0).md5($set.$type.$body, true).$set.$type.$body;
        }
        $path = tempnam(sys_get_temp_dir(), 'nntmux-full-par2-');
        $this->assertNotFalse($path);
        file_put_contents($path, $bytes);
        $this->par2HashFixtureFiles[] = $path;

        return $path;
    }

    #[DataProvider('literalDelimiterFilenameCases')]
    public function test_add_file_info_keeps_one_literal_filename_as_one_observation(bool $literalDelimiter, string $tail, int $expected, string $queryTail = ''): void
    {
        $title = 'Exact.Release.2026-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        DB::table('releases')->where('id', 1)->update(['searchname' => $title]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        $discoveredQueries = [];
        $this->filenameDiscoveryMutation = static function (string $query) use (&$discoveredQueries, $title): array {
            $discoveredQueries[] = $query;

            return [['id' => 7, 'title' => $title]];
        };
        Search::shouldReceive('updateRelease')->andReturnNull();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $manager = $this->makeFullPar2NamingManager($sync);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $originalAttributes = $context->release->getAttributes();
        $originalClaim = $context->producerNamingClaim;
        $filename = ($literalDelimiter ? 'Auxiliary.Asset||' : '').$title.$tail;

        self::assertTrue($manager->addFileInfo(['name' => $filename, 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));

        self::assertSame($expected, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        self::assertSame($title, $context->release->searchname);
        self::assertArrayNotHasKey('filename', $context->release->getAttributes());
        self::assertArrayNotHasKey('observed_additional_pp_claim', $context->release->getAttributes());
        self::assertCount(1, $context->pendingReleaseFiles);
        self::assertSame([($literalDelimiter ? 'Auxiliary.Asset||' : '').$title.$queryTail], $discoveredQueries);
        self::assertSame($originalAttributes, $context->release->getAttributes());
        self::assertSame($originalClaim, $context->producerNamingClaim);
        self::assertSame($originalAttributes['additional_pp_claim_token'], DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
        self::assertSame($originalAttributes['additional_pp_claimed_at'], DB::table('releases')->where('id', 1)->value('additional_pp_claimed_at'));
    }

    public static function literalDelimiterFilenameCases(): array
    {
        return [
            'literal delimiter' => [true, '.mkv', 0],
            'normal singleton control' => [false, '.mkv', 7],
            'erased group substring' => [false, 'disc1.mkv', 0, 'disc1'],
            'double payload suffix' => [false, '.flac.mkv', 0, '.flac'],
        ];
    }

    public function test_release_file_rows_are_deduped_and_flushed_once_at_finalize(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $nameFixing = new CountingNameFixingService;

        $manager = $this->makeManager($nameFixing);
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
            'pass' => 0,
            'crc32' => 'ABC123',
        ], $context, '\\.(?:par2|sfv|nzb)'));
        $this->assertFalse($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
            'pass' => 0,
            'crc32' => 'ABC123',
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, $nameFixing->matchPreDbFilesCalls);
        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claimed_at'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    /** @var array<string, mixed>|null */
    private static ?array $mappedArchiveFlowBaseline = null;

    #[DataProvider('mappedArticleIntegrityCases')]
    public function test_mapped_archive_download_persists_the_scene_filename_and_wakes_the_real_filename_fixer(bool $withChecksum): void
    {
        $observed = $this->runMappedArchiveParserToFilenameConsumerFlow($withChecksum);

        self::assertSame(
            $withChecksum ? ['BODY <index>', 'BODY <a>', 'BODY <b>', 'BODY <c>'] : ['BODY <index>', 'BODY <a>', 'BODY <a>', 'BODY <b>', 'BODY <c>'],
            $observed['commands'],
        );
        self::assertSame($withChecksum ? 17000 : 0, $observed['bytesReused']);

        $outcomeSummary = [
            'archiveBytes' => $observed['archiveBytes'],
            'persistedFiles' => $observed['persistedFiles'],
            'finalIdentity' => $observed['finalIdentity'],
            'outcome' => $observed['outcome'],
        ];
        if (self::$mappedArchiveFlowBaseline === null) {
            self::$mappedArchiveFlowBaseline = $outcomeSummary;
        } else {
            self::assertSame(self::$mappedArchiveFlowBaseline, $outcomeSummary);
            self::$mappedArchiveFlowBaseline = null;
        }
    }

    /**
     * @return array{
     *     archiveBytes: list<string>,
     *     persistedFiles: list<array<string, mixed>>,
     *     finalIdentity: array<string, mixed>,
     *     outcome: ProcessingOutcome,
     *     commands: list<string>,
     *     bytesReused: int
     * }
     */
    private function runMappedArchiveParserToFilenameConsumerFlow(bool $withChecksum): array
    {
        $this->prepareFilenameNaming();
        Schema::dropIfExists('usenet_groups');
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'group']);
        DB::table('releases')->where('id', 1)->update(['nfostatus' => 1]);
        $title = 'Example.Movie.2026.1080p.WEB-DL.x264-GRP';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        Search::shouldReceive('updateRelease')->twice()->with(1);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());
        $queries = new NameFixingQueryService;
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $config = $this->makeConfig(['par2MediaMapping' => true, 'par2VerifyFiles' => 1, 'processPasswords' => true]);
        $archive = $this->rar($title.'.mkv', str_repeat('CONTENT', 3000));
        $index = $this->par2Index([
            ['release.part01.rar', strlen($archive), $archive],
            ['release.part02.rar', strlen($archive), str_repeat('OTHER', 5000)],
        ]);
        $partBytes = (int) ceil(strlen($archive) * 1.03 / 3);
        $nzbPath = tempnam(sys_get_temp_dir(), 'nntmux-mapped-nzb-').'.nzb.gz';
        $this->par2HashFixtureFiles[] = $nzbPath;
        $nzbXml = '<?xml version="1.0" encoding="UTF-8"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
            .'<file poster="p" date="1" subject="&quot;release.par2&quot; yEnc (1/1)"><groups><group>group</group></groups><segments><segment bytes="'.strlen($index).'" number="1">index</segment></segments></file>'
            .'<file poster="p" date="1" subject="&quot;opaque1&quot; yEnc (1/3)"><groups><group>group</group></groups><segments>'
            .'<segment bytes="'.$partBytes.'" number="1">a</segment><segment bytes="'.$partBytes.'" number="2">b</segment><segment bytes="'.$partBytes.'" number="3">c</segment>'
            .'</segments></file>'
            .'<file poster="p" date="1" subject="&quot;opaque2&quot; yEnc (1/3)"><groups><group>group</group></groups><segments>'
            .'<segment bytes="'.$partBytes.'" number="1">d</segment><segment bytes="'.$partBytes.'" number="2">e</segment><segment bytes="'.$partBytes.'" number="3">f</segment>'
            .'</segments></file></nzb>';
        file_put_contents($nzbPath, gzencode($nzbXml));
        $nzbService = new class($nzbPath) extends TestNzbService
        {
            public function __construct(private readonly string $fixturePath)
            {
                parent::__construct();
            }

            public function nzbPath(string $releaseGuid = ''): string|false
            {
                return $releaseGuid === 'guid-1' ? $this->fixturePath : false;
            }
        };
        $a = YencArticles::part(substr($archive, 0, 17000), 'opaque', strlen($archive), 1, 1, 3);
        $mapping = $withChecksum ? $a : preg_replace('/ pcrc32=[0-9a-f]+/', '', $a);
        $wire = MappingArticleNntpHarness::body($index, 'release.par2').MappingArticleNntpHarness::rawBody($mapping);
        if (! $withChecksum) {
            $wire .= MappingArticleNntpHarness::rawBody($a);
        }
        $wire .= MappingArticleNntpHarness::rawBody(YencArticles::part(substr($archive, 17000, 2000), 'opaque', strlen($archive), 2, 17001, 3))
            .MappingArticleNntpHarness::rawBody(YencArticles::part(substr($archive, 19000), 'opaque', strlen($archive), 3, 19001, 3));
        $nntp = new MappingArticleNntpHarness($wire);
        $download = new UsenetDownloadService($config, $nntp);
        $sources = new NzbSourceGuard($nzbService);
        $this->app->instance(NzbSourceGuard::class, $sources);
        $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
        $manager = new ReleaseFileManager($config, new ReleaseImageService, new NfoService, $nzbService, $fixer);
        $archiveService = new class($config) extends ArchiveExtractionService
        {
            /**
             * @var list<string>
             */
            public array $capturedBytes = [];

            public function processCompressedData(string $compressedData, ReleaseProcessingContext $context, string $tmpPath): array
            {
                $this->capturedBytes[] = $compressedData;

                return parent::processCompressedData($compressedData, $context, $tmpPath);
            }
        };
        /** @var ConsoleOutputService&MockInterface $output */
        $output = Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing();
        $processor = new ReleaseProcessor(
            $config,
            new NzbContentParser($nzbService, new NzbParserService),
            new AdditionalWorkPlanner($config),
            $archiveService,
            $this->createStub(MediaExtractionService::class),
            $download,
            $manager,
            $this->createStub(ReleaseFilesArchiveFallback::class),
            new TempWorkspaceService,
            $output,
        );
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $mainTmp = sys_get_temp_dir().'/nntmux-mapped-flow-'.uniqid('', true).'/';
        File::ensureDirectoryExists($mainTmp);
        try {
            $result = $processor->process($context, $mainTmp);
        } finally {
            File::deleteDirectory($mainTmp);
        }

        self::assertSame([$archive], $archiveService->capturedBytes);
        self::assertSame('release.part01.rar', $context->mediaPlan?->mappedFiles[0]->file->name);
        self::assertSame($title.'.mkv', DB::table('release_files')->where('name', $title.'.mkv')->value('name'));
        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertCount(1, $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        $release = Release::query()->findOrFail(1);
        self::assertSame($title, $release->searchname);
        self::assertSame(1, (int) $release->isrenamed);
        self::assertSame(1, (int) $release->proc_files);

        return [
            'archiveBytes' => $archiveService->capturedBytes,
            'persistedFiles' => array_map(
                static fn (object $row): array => [
                    'name' => $row->name,
                    'size' => (int) $row->size,
                    'crc32' => (string) $row->crc32,
                    'passworded' => (int) $row->passworded,
                ],
                DB::table('release_files')->where('releases_id', 1)->orderBy('name')->get()->all(),
            ),
            'finalIdentity' => [
                'guid' => $release->guid,
                'name' => $release->name,
                'searchname' => $release->searchname,
                'isrenamed' => (int) $release->isrenamed,
                'predb_id' => (int) $release->predb_id,
                'proc_files' => (int) $release->proc_files,
                'rarinnerfilecount' => (int) $release->rarinnerfilecount,
                'passwordstatus' => (int) $release->passwordstatus,
            ],
            'outcome' => $result->outcome,
            'commands' => $nntp->sentCommands(),
            'bytesReused' => (int) ($result->downloadMetrics->bytesReused ?? 0),
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function mappedArticleIntegrityCases(): array
    {
        return ['retained CRC-valid primary article' => [true], 'checksumless mapping control' => [false]];
    }

    public function test_late_archive_filename_reopens_the_real_filename_pass_and_recovers_its_exact_title(): void
    {
        $this->prepareFilenameNaming();
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        Search::shouldReceive('updateRelease')->twice()->with(1);
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class));
        $fixer = new NameFixingService($updater, fileNameCleaner: $this->filenameDiscoveryCleaner());
        $queries = new NameFixingQueryService;
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $manager = $this->makeManager($fixer);
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        self::assertTrue($manager->addFileInfo(['name' => $title.'.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        $manager->finalizeRelease($context, false);

        self::assertCount(1, $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        $release = Release::query()->findOrFail(1);
        self::assertSame($title, $release->searchname);
        self::assertSame(1, (int) $release->isrenamed);
        self::assertSame(0, (int) $release->predb_id);
        self::assertSame(1, (int) $release->proc_files);
    }

    #[DataProvider('filenamePassHandlers')]
    public function test_an_old_filename_pass_cannot_complete_after_the_producer_adds_a_new_filename(string $handler): void
    {
        $this->prepareFilenameNaming();
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        self::assertTrue($manager->addFileInfo(['name' => $title.'.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        $inserted = false;
        Search::shouldReceive('updateRelease')->twice()->with(1);
        $this->filenameDiscoveryMutation = function () use (&$inserted, $manager, $context): array {
            self::assertSame(0, DB::transactionLevel(), 'Filename discovery must not hold the completion lock.');
            if (! $inserted) {
                $inserted = true;
                $manager->finalizeRelease($context, false);
            }

            return [];
        };
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        if ($handler === 'files') {
            $fixer->fixNamesWithFiles(0, true, 1, true, false);
        } else {
            $input = (new NameFixingQueryService)->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1)[0];
            $input->textstring = str_repeat('a', 32).'.bin';
            $fixer->checkName($input, true, 'Filenames, ', true, false);
        }

        self::assertTrue($inserted);
        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertCount(1, (new NameFixingQueryService)->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        self::assertSame($title, DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('isrenamed'));
    }

    /** @return array<string, array{string}> */
    public static function filenamePassHandlers(): array
    {
        return ['ordinary batch' => ['files'], 'legacy direct check' => ['checkName']];
    }

    #[DataProvider('directFilenameInventories')]
    public function test_direct_filename_completion_requires_the_persisted_inventory_to_be_fully_inspected(int $fileCount): void
    {
        $this->prepareFilenameNaming();
        $name = str_repeat('a', 32).'.bin';
        for ($index = 0; $index < $fileCount; $index++) {
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => $index === 0 ? $name : str_repeat('b', 32).'.bin']);
        }
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());
        $input = Release::query()->findOrFail(1);
        $input->releases_id = 1;
        $input->textstring = $name;

        $fixer->checkName($input, true, 'Filenames, ', true, false);

        self::assertSame($fileCount === 1 ? 1 : 0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
    }

    /** @return array<string, array{int}> */
    public static function directFilenameInventories(): array
    {
        return ['unpersisted external text' => [0], 'inspected singleton' => [1], 'uninspected sibling' => [2]];
    }

    #[DataProvider('renamedFallbackCategories')]
    public function test_a_stable_no_match_filename_pass_completes_an_eligible_renamed_fallback(int $category): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['isrenamed' => 1, 'categories_id' => $category]);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        $queries = new NameFixingQueryService;
        self::assertCount(1, $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('isrenamed'));
        self::assertSame('Example', DB::table('releases')->where('id', 1)->value('searchname'));
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
    }

    #[DataProvider('renamedFallbackCategories')]
    public function test_filename_completion_preserves_a_renamed_fallback_reclassified_during_discovery(int $category): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['isrenamed' => 1, 'categories_id' => $category]);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        $this->filenameDiscoveryMutation = function (): array {
            DB::table('releases')->where('id', 1)->update(['categories_id' => Category::MOVIE_HD]);

            return [];
        };
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame(Category::MOVIE_HD, (int) DB::table('releases')->where('id', 1)->value('categories_id'));
    }

    /** @return array<string, array{int}> */
    public static function renamedFallbackCategories(): array
    {
        return ['OTHER_MISC' => [Category::OTHER_MISC], 'OTHER_HASHED' => [Category::OTHER_HASHED]];
    }

    #[DataProvider('renamedResolvedFilenameHandlers')]
    public function test_stable_renamed_resolved_filename_completion_preserves_explicit_caller_modes(string $handler): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['isrenamed' => 1, 'categories_id' => Category::MOVIE_HD]);
        $name = str_repeat('a', 32).'.bin';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        $queries = new NameFixingQueryService;
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 0));
        $candidates = $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 3);
        self::assertCount(1, $candidates);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        if ($handler === 'categories3') {
            $fixer->fixNamesWithFiles(0, true, 3, true, false);
        } else {
            $input = $candidates[0];
            $input->textstring = $name;
            $fixer->checkName($input, true, 'Filenames, ', true, false);
        }

        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('isrenamed'));
        self::assertSame(Category::MOVIE_HD, (int) DB::table('releases')->where('id', 1)->value('categories_id'));
    }

    /** @return array<string, array{string}> */
    public static function renamedResolvedFilenameHandlers(): array
    {
        return ['category 3 batch' => ['categories3'], 'direct persisted singleton' => ['checkName']];
    }

    #[DataProvider('directFilenamePredbStates')]
    public function test_direct_filename_completion_preserves_the_exact_observed_predb_link(int $observedPredb, int $currentPredb, bool $completed): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['predb_id' => $observedPredb]);
        $name = str_repeat('a', 32).'.bin';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        $input = Release::query()->findOrFail(1);
        $input->releases_id = 1;
        $input->textstring = $name;
        $this->filenameDiscoveryMutation = function () use ($currentPredb): array {
            DB::table('releases')->where('id', 1)->update(['predb_id' => $currentPredb]);

            return [];
        };
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->checkName($input, true, 'Filenames, ', true, false);

        self::assertSame($completed ? 1 : 0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($currentPredb, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
    }

    /** @return array<string, array{int, int, bool}> */
    public static function directFilenamePredbStates(): array
    {
        return [
            'stable unlinked singleton' => [0, 0, true],
            'stable linked singleton' => [7, 7, true],
            'new link during discovery' => [0, 7, false],
            'link replacement during discovery' => [7, 8, false],
        ];
    }

    public function test_generic_snapshot_status_updates_remain_restricted_to_unlinked_releases(): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['predb_id' => 7, 'proc_nfo' => 0]);
        $input = Release::query()->findOrFail(1);
        $input->releases_id = 1;
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class));

        self::assertFalse($updater->updateSnapshotColumn($input, 'proc_nfo', NameFixingService::PROC_NFO_DONE));

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_nfo'));
    }

    #[DataProvider('filenameCompletionIdentityChanges')]
    public function test_filename_completion_preserves_a_changed_release_identity(string $column, string|int $value): void
    {
        $this->prepareFilenameNaming();
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        $this->filenameDiscoveryMutation = function () use ($column, $value): array {
            DB::table('releases')->where('id', 1)->update([$column => $value]);

            return [];
        };
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($value, DB::table('releases')->where('id', 1)->value($column));
    }

    /** @return array<string, array{string, string|int}> */
    public static function filenameCompletionIdentityChanges(): array
    {
        return [
            'GUID replacement' => ['guid', 'new-guid'],
            'subject reset' => ['name', 'New.Subject'],
            'searchname reset' => ['searchname', 'New.Searchname'],
            'already renamed' => ['isrenamed', 1],
            'PreDB linked' => ['predb_id', 7],
        ];
    }

    #[DataProvider('filenameWakeupScenarios')]
    public function test_only_new_rows_on_an_unrenamed_unlinked_release_reopen_filename_processing(
        bool $renamed, bool $linked, bool $duplicate,
    ): void {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['proc_files' => 1, 'isrenamed' => (int) $renamed, 'predb_id' => $linked ? 7 : 0]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $name = 'Example.Movie.2026.1080p.BluRay.x264-TARGET.mkv';
        self::assertTrue($manager->addFileInfo(['name' => $name, 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        if ($duplicate) {
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        }

        $manager->finalizeRelease($context, false);

        self::assertSame($renamed || $linked || $duplicate ? 1 : 0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame(1, DB::table('release_files')->count());
        self::assertSame(! $duplicate, $context->releaseFilesChanged);
    }

    /** @return array<string, array{bool, bool, bool}> */
    public static function filenameWakeupScenarios(): array
    {
        return [
            'new filename' => [false, false, false],
            'already renamed' => [true, false, false],
            'PreDB linked' => [false, true, false],
            'duplicate-only concurrent replay' => [false, false, true],
        ];
    }

    #[DataProvider('lateFilenameOwnershipChanges')]
    public function test_late_filename_admission_preserves_a_lost_claim_or_replaced_identity(string $column, string $value): void
    {
        $this->prepareFilenameNaming();
        DB::table('releases')->where('id', 1)->update(['proc_files' => 1]);
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        self::assertTrue($manager->addFileInfo(['name' => 'Example.Movie.2026.1080p.BluRay.x264-TARGET.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        DB::table('releases')->where('id', 1)->update([$column => $value]);
        Search::shouldReceive('updateRelease')->never();

        $manager->finalizeRelease($context, false);

        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($value, DB::table('releases')->where('id', 1)->value($column));
    }

    /** @return array<string, array{string, string}> */
    public static function lateFilenameOwnershipChanges(): array
    {
        return [
            'GUID replacement' => ['guid', 'new-guid'],
            'subject reset' => ['name', 'New.Subject'],
            'searchname reset' => ['searchname', 'New.Searchname'],
            'claim replacement' => ['additional_pp_claim_token', 'new-owner'],
            'claim expiry' => ['additional_pp_claimed_at', '2000-01-01 00:00:00'],
        ];
    }

    #[DataProvider('xxxFilenameInterleavings')]
    public function test_xxx_filename_completion_compares_the_full_observed_inventory(bool $addLateFile): void
    {
        $this->prepareFilenameNaming();
        DB::table('release_files')->insert([
            ['releases_id' => 1, 'name' => 'SDPORN.bin'],
            ['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin'],
        ]);
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        self::assertTrue($manager->addFileInfo(['name' => 'Example.Movie.2026.1080p.BluRay.x264-TARGET.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        Search::shouldReceive('updateRelease')->times($addLateFile ? 1 : 0)->with(1);
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use ($addLateFile, &$inserted, $manager, $context): void {
            if ($addLateFile && ! $inserted && str_contains($query->sql, 'SELECT rf.releases_id')) {
                $inserted = true;
                self::assertSame(0, DB::transactionLevel());
                $manager->finalizeRelease($context, false);
            }
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->fixXXXNamesWithFiles(0, true, 1, true, false);

        self::assertSame($addLateFile ? 0 : 1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($addLateFile ? 3 : 2, DB::table('release_files')->count());
        self::assertSame($addLateFile, $inserted);
    }

    /** @return array<string, array{bool}> */
    public static function xxxFilenameInterleavings(): array
    {
        return ['unchanged mixed inventory completes' => [false], 'late file preserves wake-up' => [true]];
    }

    public function test_filename_completion_requires_the_exact_bytes_not_just_the_same_row_count(): void
    {
        $this->prepareFilenameNaming();
        $name = str_repeat('a', 32).'.bin';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        $this->filenameDiscoveryMutation = function () use ($name): array {
            DB::table('release_files')->where('releases_id', 1)->update(['name' => strtoupper($name)]);

            return [];
        };
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)), fileNameCleaner: $this->filenameDiscoveryCleaner());

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
    }

    private function installFilenameSqlIdentity(): void
    {
        if (! Schema::hasColumn('predb', 'filename')) {
            DB::statement("ALTER TABLE predb ADD COLUMN filename varchar NOT NULL DEFAULT ''");
        }
        if (! Schema::hasTable('predb_identity_projection_state')) {
            (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        }
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
        $this->rebuildFilenameSqlIdentity();
    }

    private function rebuildFilenameSqlIdentity(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $chunk = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $chunk['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    private function filenameDiscoveryCleaner(): FileNameCleaner
    {
        $actual = new FileNameCleaner;
        $cleaner = self::getStubBuilder(FileNameCleaner::class)->enableOriginalConstructor()->onlyMethods(['cleanForFilenameDiscovery'])->getStub();
        $cleaner->method('cleanForFilenameDiscovery')->willReturnCallback(function (OriginalFilenameEvidence $evidence) use ($actual): string|false {
            $query = $actual->cleanForFilenameDiscovery($evidence);
            if ($this->filenameDiscoveryMutation !== null) {
                $mutation = $this->filenameDiscoveryMutation;
                $this->filenameDiscoveryMutation = null;
                $mutation($query);
            }

            return $query;
        });

        return $cleaner;
    }

    private function prepareFilenameNaming(): void
    {
        $this->prepareFullPar2NamingEvidence('Unrelated.Title-GRP');
        Schema::table('releases', function (Blueprint $table): void {
            $table->string('fromname')->default('');
            foreach (['proc_nfo', 'proc_uid', 'proc_srr', 'proc_crc32'] as $column) {
                $table->integer($column)->default(1);
            }
        });
        $this->installFilenameSqlIdentity();
    }

    public function test_season_pack_files_are_not_matched_to_predb_one_by_one(): void
    {
        DB::table('releases')->insert(['searchname' => 'Show.S01.1080p.WEB-DL-GRP'] + $this->releaseRow());
        Search::shouldReceive('updateRelease')->with(1);
        $nameFixing = new CountingNameFixingService;
        $manager = $this->makeManager($nameFixing);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $context->seasonPack = true;

        $manager->addFileInfo(['name' => 'Show.S01E01.1080p.WEB-DL-GRP.mkv', 'size' => 2048], $context, '\\.sfv');
        $manager->processReleaseNameFromRar(['file_list' => [['name' => 'Show.S01E10.1080p.WEB-DL-GRP.mkv']]], $context);
        $manager->finalizeRelease($context, false);

        $this->assertSame(0, $nameFixing->matchPreDbFilesCalls);
        $this->assertSame('Show.S01.1080p.WEB-DL-GRP', DB::table('releases')->where('id', 1)->value('searchname'));
    }

    public function test_season_pack_lists_every_episode_while_other_releases_keep_eleven_files(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        DB::table('releases')->insert(['id' => 2, 'guid' => 'guid-2'] + $this->releaseRow());
        Search::shouldReceive('updateRelease')->twice();
        $manager = $this->makeManager();

        $pack = $this->sourceContext(Release::query()->findOrFail(1));
        $pack->seasonPack = true;
        $other = $this->sourceContext(Release::query()->findOrFail(2));
        for ($episode = 1; $episode <= 25; $episode++) {
            $name = sprintf('Show.S01E%02d.1080p.WEB-DL-GRP.mkv', $episode);
            $manager->addFileInfo(['name' => $name, 'size' => 2048], $pack, '\\.sfv');
            $manager->addFileInfo(['name' => $name, 'size' => 2048], $other, '\\.sfv');
        }
        $manager->finalizeRelease($pack, false);
        $manager->finalizeRelease($other, false);

        $this->assertSame(25, DB::table('release_files')->where('releases_id', 1)->count());
        $this->assertSame(11, DB::table('release_files')->where('releases_id', 2)->count());
    }

    public function test_a_season_pack_is_never_renamed_to_one_of_its_episodes(): void
    {
        DB::table('releases')->insert(['searchname' => 'Show.S01.1080p.WEB-DL-GRP'] + $this->releaseRow());
        $service = new ReleaseUpdateService;

        $service->updateRelease(DB::table('releases')->where('id', 1)->first(), 'Show.S01E01.1080p.WEB-DL-GRP', 'test', true, 'Filenames, ', true, false);

        $this->assertFalse($service->matched);
        $this->assertSame(0, $service->fixed);
        $this->assertSame('Show.S01.1080p.WEB-DL-GRP', DB::table('releases')->where('id', 1)->value('searchname'));
    }

    public function test_finalization_flushes_rows_and_synchronizes_search_immediately(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 2048,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertSame([], $context->pendingReleaseFiles);
    }

    public function test_database_statements_are_measured_inside_an_active_release_scope(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        $collector = app(PersistenceMetricsCollector::class);

        $collector->beginReleaseScope(1);
        DB::table('releases')->where('id', 1)->value('guid');
        $metrics = $collector->finishReleaseScope();

        $this->assertSame(1, $metrics->databaseStatements);
        $this->assertGreaterThanOrEqual(0.0, $metrics->databaseMilliseconds);
    }

    public function test_queued_par_hashes_flush_with_release_files(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');
        $queue->invoke(
            $manager,
            $context,
            1,
            'Example.Movie.2026.par2',
            512,
            1_788_600_000,
            0,
            '1234567890abcdef1234567890abcdef'
        );

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('par_hashes')->count());
    }

    public function test_existing_file_gains_a_hash_without_extra_file_count_or_search_updates(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'existing.mkv', 'size' => 1024]);
        $search = Search::spy();
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');
        $hash = '1234567890abcdef1234567890abcdef';

        $this->assertFalse($queue->invoke($manager, $context, 1, 'existing.mkv', 1024, 0, 0, strtoupper($hash)));
        $this->assertFalse($queue->invoke($manager, $context, 1, 'existing.mkv', 1024, 0, 0, $hash));
        $this->assertSame([$hash => ['releases_id' => 1, 'hash' => $hash]], $context->pendingParHashes);
        $this->assertSame([], $context->pendingReleaseFiles);
        $this->assertSame(0, $context->addedFileInfo);
        $search->shouldNotHaveReceived('updateRelease');

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('par_hashes')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $this->assertFalse($context->releaseFilesChanged);
        $search->shouldHaveReceived('updateRelease')->once()->with(1);
    }

    public function test_a_pending_file_gains_a_hash_without_being_queued_twice(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        $search = Search::spy();
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');
        $hash = '1234567890abcdef1234567890abcdef';

        $this->assertTrue($queue->invoke($manager, $context, 1, 'pending.mkv', 1024, 0, 0));
        $this->assertFalse($queue->invoke($manager, $context, 1, 'pending.mkv', 1024, 0, 0, $hash));
        $this->assertFalse($queue->invoke($manager, $context, 1, 'pending.mkv', 1024, 0, 0, $hash));
        $this->assertSame(1, $context->addedFileInfo);
        $this->assertCount(1, $context->pendingReleaseFiles);
        $this->assertSame([$hash => ['releases_id' => 1, 'hash' => $hash]], $context->pendingParHashes);
        $search->shouldNotHaveReceived('updateRelease');

        $manager->finalizeRelease($context, false);

        $this->assertSame(1, DB::table('release_files')->count());
        $this->assertSame(1, DB::table('par_hashes')->count());
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('rarinnerfilecount'));
        $search->shouldHaveReceived('updateRelease')->once()->with(1);
    }

    public function test_hash_collection_rejects_other_release_ids(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->never();
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');

        $this->assertFalse($queue->invoke($manager, $context, 2, 'wrong-release.mkv', 1024, 0, 0, md5('valid')));
        $this->assertSame([], $context->pendingReleaseFiles);
        $this->assertSame([], $context->pendingParHashes);
    }

    public function test_hash_collection_rejects_non_hex_values_without_losing_file_metadata(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->never();
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => true]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');

        $this->assertTrue($queue->invoke($manager, $context, 1, 'invalid-hash.mkv', 1024, 0, 0, str_repeat('z', 32)));
        $this->assertSame([], $context->pendingParHashes);
        $this->assertCount(1, $context->pendingReleaseFiles);
    }

    public function test_disabled_par2_collection_keeps_file_metadata_without_hashes(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->never();
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, configOverrides: ['addPAR2Files' => false]);
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $queue = new ReflectionMethod(ReleaseFileManager::class, 'queueReleaseFile');

        $this->assertTrue($queue->invoke($manager, $context, 1, 'file.mkv', 1024, 0, 0, md5('valid')));
        $this->assertSame([], $context->pendingParHashes);
        $this->assertCount(1, $context->pendingReleaseFiles);
    }

    public function test_par2_naming_state_does_not_skip_a_valid_descriptor_on_the_next_release(): void
    {
        Schema::table('releases', function (Blueprint $table): void {
            $table->dateTime('postdate')->nullable();
            $table->integer('proc_pp')->default(0);
            $table->integer('proc_par2')->default(0);
        });
        Schema::create('predb', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
        });
        (require database_path('migrations/2026_10_11_215821_create_par2_rename_progress_table.php'))->up();
        $firstTitle = 'First.Movie.2026.1080p.WEB-DL.x264-GRP';
        $secondTitle = 'Recovered.Movie.2026.1080p.WEB-DL.x264-GRP';
        DB::table('predb')->insert([
            ['id' => 7, 'title' => $firstTitle], ['id' => 8, 'title' => $secondTitle],
        ]);
        DB::table('releases')->insert([
            array_replace($this->releaseRow(), ['searchname' => $firstTitle, 'postdate' => '2026-10-11 00:00:00']),
            array_replace($this->releaseRow(), ['id' => 2, 'guid' => 'guid-2', 'searchname' => 'second-obfuscated-release', 'postdate' => '2026-10-11 00:00:00']),
        ]);
        Search::shouldReceive('updateRelease')->never();

        $updates = $this->getMockBuilder(ReleaseUpdateService::class)->disableOriginalConstructor()
            ->onlyMethods(['performDatabaseUpdate'])->getMock();
        (new ReflectionProperty(ReleaseUpdateService::class, 'fileNameCleaner'))->setValue($updates, new FileNameCleaner);
        (new ReflectionProperty(ReleaseUpdateService::class, 'echoOutput'))->setValue($updates, false);
        $updates->expects($this->once())->method('performDatabaseUpdate')->willReturnCallback(
            static function (object $release, string $title, string $type, bool $nameStatus, int $predbId): bool {
                DB::table('releases')->where('id', $release->releases_id)
                    ->update(['searchname' => $title, 'predb_id' => $predbId, 'isrenamed' => 1]);

                return true;
            }
        );
        $nameFixing = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NameFixingService::class, 'updateService'))->setValue($nameFixing, $updates);
        (new ReflectionProperty(NameFixingService::class, 'fileExtractor'))->setValue($nameFixing, new FileNameExtractor);
        $manager = $this->makeManagerWithImageService(new ReleaseImageService, $nameFixing, [
            'renamePar2' => true, 'echoCLI' => true, 'addPAR2Files' => false,
        ]);
        $par2 = $this->createMock(Par2Info::class);
        $par2->expects($this->exactly(2))->method('open')->willReturn(true);
        $par2->expects($this->exactly(2))->method('getFileList')->willReturnOnConsecutiveCalls(
            [['name' => $firstTitle, 'size' => 1024]],
            [['name' => '7ae60ee3d802bfd4388b0f2c6e982e7a.rar', 'size' => 512], ['name' => $secondTitle, 'size' => 1024]]
        );
        $par2->method('getPackets')->willReturn(false);

        $firstContext = $this->sourceContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->processPar2File('first.par2', $firstContext, $par2));
        $this->assertTrue($updates->matched);
        $this->assertSame(7, DB::table('releases')->where('id', 1)->value('predb_id'));

        $secondContext = $this->sourceContext(Release::query()->findOrFail(2));
        $this->assertTrue($manager->processPar2File('second.par2', $secondContext, $par2));

        $this->assertSame($secondTitle, $secondContext->release->getAttribute('textstring'));
        $this->assertSame($secondTitle, DB::table('releases')->where('id', 2)->value('searchname'));
        $this->assertSame(8, DB::table('releases')->where('id', 2)->value('predb_id'));
    }

    public function test_failed_finalization_rolls_back_buffered_rows_and_keeps_them_for_retry(): void
    {
        DB::table('releases')->insert([...$this->releaseRow(), 'proc_files' => 1]);

        Search::shouldReceive('updateRelease')->never();

        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        DB::unprepared("CREATE TRIGGER fail_release_finalize BEFORE UPDATE ON releases BEGIN SELECT RAISE(ABORT, 'forced finalization failure'); END");

        try {
            $manager->finalizeRelease($context, false);
            $this->fail('Finalization should have failed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced finalization failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_release_finalize');
        }

        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        $this->assertArrayHasKey('Example.Movie.2026.mkv', $context->pendingReleaseFiles);
    }

    public function test_float_release_file_size_is_normalized_before_queueing(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        $this->assertTrue($manager->addFileInfo([
            'name' => 'Example.Movie.2026.mkv',
            'size' => 1024.0,
            'date' => 1_788_600_000,
        ], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertSame(1024, DB::table('release_files')->value('size'));
    }

    public function test_encrypted_file_marks_release_passworded(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        $manager = $this->makeManager();

        // RarInfo and ZipInfo report encrypted files as 'pass' => 1, not true.
        foreach ([1, true] as $pass) {
            $context = $this->sourceContext(Release::query()->findOrFail(1));

            $this->assertFalse($manager->addFileInfo([
                'name' => 'Example.Show.S01E01.mkv',
                'size' => 1024,
                'pass' => $pass,
            ], $context, '\\.(?:par2|sfv|nzb)'));

            $this->assertTrue($context->releaseHasPassword);
            $this->assertSame(ReleaseBrowseService::PASSWD_RAR, $context->passwordStatus);
        }
    }

    public function test_a_listed_7z_finalizes_with_no_password_and_its_file_count(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->once()->with(1);

        $archive = $this->sevenZip('Example.Show.S01E01.mkv', str_repeat('x', 100));
        $archiveService = new ArchiveExtractionService($this->makeConfig());
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $context->nzbHasCompressedFile = true;

        $joined = $archiveService->withSevenZipEndHeader(substr($archive, 0, 40), substr($archive, -80));
        $this->assertIsString($joined);
        $result = $archiveService->processCompressedData($joined, $context, sys_get_temp_dir().'/');
        foreach ($result['files'] as $file) {
            $manager->addFileInfo($file, $context, '\\.(?:par2|sfv|nzb)');
        }
        $manager->finalizeRelease($context, true);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(ReleaseBrowseService::PASSWD_NONE, (int) $release->passwordstatus);
        $this->assertSame(1, (int) $release->rarinnerfilecount);
        $this->assertSame('Example.Show.S01E01.mkv', DB::table('release_files')->value('name'));
    }

    public function test_an_unreadable_archive_is_queued_for_one_retry_after_the_delay(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->twice()->with(1);
        config(['nntmux.archive_retry_delay' => 3600]);
        $this->freezeSecond();

        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $context->nzbHasCompressedFile = true;
        $context->archiveInspectionAttempted = true;
        $manager->finalizeRelease($context, true);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $release->passwordstatus);
        $this->assertSame(-1, (int) $release->haspreview);
        $this->assertSame(now()->addHour()->toDateTimeString(), (string) $release->archive_retry_at);
        $this->assertNull($release->additional_pp_claimed_at);

        $this->travel(2)->hours();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $context->nzbHasCompressedFile = true;
        $context->archiveInspectionAttempted = true;
        $manager->finalizeRelease($context, true);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $release->passwordstatus);
        $this->assertSame(0, (int) $release->haspreview);
        $this->assertSame(now()->subHour()->toDateTimeString(), (string) $release->archive_retry_at);
    }

    #[DataProvider('archiveRetryScenarios')]
    public function test_an_unreadable_archive_is_retried_only_when_a_later_attempt_could_read_it(
        bool $inspectionAttempted,
        bool $listingUndecodable,
        bool $retryable,
        bool $expectRetry,
    ): void {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->once()->with(1);

        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $context->nzbHasCompressedFile = true;
        $context->archiveInspectionAttempted = $inspectionAttempted;
        $context->archiveListingUndecodable = $listingUndecodable;
        $context->archiveRetryable = $retryable;
        $this->makeManager()->finalizeRelease($context, true);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $release->passwordstatus);
        $this->assertSame($expectRetry ? -1 : 0, (int) $release->haspreview);
        $this->assertSame($expectRetry, $release->archive_retry_at !== null);
    }

    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function archiveRetryScenarios(): array
    {
        return [
            'inspection skipped (book flood or downloads disabled)' => [false, false, false, false],
            'only a compressed 7z header' => [true, true, false, false],
            'compressed 7z header and another archive missing articles' => [true, true, true, true],
        ];
    }

    #[DataProvider('probedArchiveScenarios')]
    public function test_probed_archives_finalize_from_the_bounded_download_or_remain_unknown(
        string $type,
        bool $encrypted,
        ?string $failure,
    ): void {
        DB::table('releases')->insert([...$this->releaseRow(), 'nfostatus' => 1]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        config(['nntmux.archive_retry_delay' => 86400]);
        $this->freezeSecond();

        $config = $this->makeConfig(['processPasswords' => true, 'maximumRarSegments' => 3]);
        $archive = $type === 'rar'
            ? $this->rar('readme.txt', str_repeat('A', 100)).substr($this->rar('Movie.2026.1080p.mkv', str_repeat('B', 100), $encrypted), 20)
            : $this->zip('readme.txt', str_repeat('A', 100)).$this->zip('Movie.2026.1080p.mkv', str_repeat('B', 100), $encrypted);
        /** @var NzbContentParser&MockInterface $parser */
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'sourceDigest' => $this->fixtureSourceSnapshot(Release::query()->findOrFail(1))['digest'],
            'contents' => [[
                'title' => '"obfuscated" yEnc',
                'segments' => ['<probe>', '<second>', '<third>', '<ignored>'],
                'filecount' => 1,
            ]],
        ]);
        /** @var UsenetDownloadService&MockInterface $download */
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('beginReleaseScope')->once();
        $download->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $download->shouldReceive('download')->once()->with(DownloadKind::Compressed, ['<probe>'], '', 1)
            ->andReturn(['success' => true, 'data' => substr($archive, 0, 80), 'groupUnavailable' => false, 'error' => null]);
        $download->shouldReceive('download')->once()->with(DownloadKind::Compressed, ['<second>'], '', 1)
            ->andReturn(['success' => true, 'data' => substr($archive, 80, 80), 'groupUnavailable' => false, 'error' => null]);
        $download->shouldReceive('download')->once()->with(DownloadKind::Compressed, ['<third>'], '', 1)
            ->andReturn([
                'success' => $failure === null,
                'data' => $failure === null ? substr($archive, 160) : null,
                'groupUnavailable' => $failure === 'group-unavailable',
                'error' => $failure,
            ]);
        /** @var TempWorkspaceService&MockInterface $workspace */
        $workspace = Mockery::mock(TempWorkspaceService::class);
        $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn('/tmp/probed-archive/');
        $workspace->shouldReceive('listFiles')->andReturn([]);
        $workspace->shouldReceive('clearDirectory')->once()->with('/tmp/probed-archive/', false);
        /** @var MediaExtractionService&MockInterface $media */
        $media = Mockery::mock(MediaExtractionService::class);
        /** @var ReleaseFilesArchiveFallback&MockInterface $fallback */
        $fallback = Mockery::mock(ReleaseFilesArchiveFallback::class);
        /** @var ConsoleOutputService&MockInterface $output */
        $output = Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing();
        $processor = new ReleaseProcessor(
            $config,
            $parser,
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config),
            $media,
            $download,
            $this->makeManager(),
            $fallback,
            $workspace,
            $output,
        );
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        $result = $processor->process($context, '/tmp/');

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertTrue($context->nzbHasCompressedFile);
        $this->assertSame($failure === 'group-unavailable', $context->groupUnavailable);
        if ($failure !== null) {
            $this->assertSame(-1, (int) $release->passwordstatus);
            $this->assertSame(-1, (int) $release->haspreview);
            $this->assertSame(now()->addDay()->toDateTimeString(), $release->archive_retry_at);
            $this->assertSame(0, DB::table('release_files')->count());
        } else {
            $this->assertSame($encrypted ? ReleaseBrowseService::PASSWD_RAR : ReleaseBrowseService::PASSWD_NONE, (int) $release->passwordstatus);
            $this->assertSame(0, (int) $release->haspreview);
            $this->assertNull($release->archive_retry_at);
            $this->assertSame($encrypted ? 1 : 2, DB::table('release_files')->count());
            $this->assertSame($encrypted, $result->outcome === ProcessingOutcome::Passworded);
        }
    }

    /**
     * @return array<string, array{string, bool, string|null}>
     */
    public static function probedArchiveScenarios(): array
    {
        return [
            'plain RAR' => ['rar', false, null],
            'encrypted RAR entry' => ['rar', true, null],
            'RAR continuation unavailable' => ['rar', false, 'missing-article'],
            'RAR group unavailable' => ['rar', false, 'group-unavailable'],
            'plain ZIP' => ['zip', false, null],
            'encrypted ZIP entry' => ['zip', true, null],
            'ZIP continuation unavailable' => ['zip', false, 'missing-article'],
            'ZIP group unavailable' => ['zip', false, 'group-unavailable'],
        ];
    }

    #[DataProvider('compressedCandidatePayloads')]
    public function test_plain_media_behind_an_archive_name_finalizes_with_no_password(?string $data, string $expected): void
    {
        $data ??= $this->rar('Movie.2026.1080p.mkv', str_repeat('B', 100));
        DB::table('releases')->insert([...$this->releaseRow(), 'nfostatus' => 1]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        config(['nntmux.archive_retry_delay' => 86400]);
        $this->freezeSecond();

        $config = $this->makeConfig(['processPasswords' => true]);
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'sourceDigest' => $this->fixtureSourceSnapshot(Release::query()->findOrFail(1))['digest'],
            'contents' => [['title' => '"Movie.2026.part01.rar" yEnc (1/50)', 'segments' => ['<v1>']]],
        ]);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('beginReleaseScope')->once();
        $download->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        $download->shouldReceive('download')->once()
            ->with(DownloadKind::Compressed, ['<v1>'], '', 1, '"Movie.2026.part01.rar" yEnc (1/50)')
            ->andReturn(['success' => true, 'data' => $data, 'groupUnavailable' => false, 'error' => null]);
        $tmpPath = sys_get_temp_dir().'/media-payload-'.uniqid().'/';
        File::ensureDirectoryExists($tmpPath);
        $workspace = Mockery::mock(TempWorkspaceService::class);
        $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $workspace->shouldReceive('listFiles')->andReturn([]);
        $workspace->shouldReceive('clearDirectory')->once()->with($tmpPath, false)
            ->andReturnUsing(static fn () => File::deleteDirectory($tmpPath));
        $processor = new ReleaseProcessor(
            $config,
            $parser,
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config),
            Mockery::mock(MediaExtractionService::class),
            $download,
            $this->makeManager(),
            Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(),
            $workspace,
            Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(),
        );
        $context = $this->sourceContext(Release::query()->findOrFail(1));

        $processor->process($context, '/tmp/');

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($expected === 'archive' ? 1 : 0, DB::table('release_files')->count());
        if ($expected === 'unknown') {
            $this->assertSame(-1, (int) $release->passwordstatus);
            $this->assertSame(-1, (int) $release->haspreview);
            $this->assertSame(now()->addDay()->toDateTimeString(), $release->archive_retry_at);
        } else {
            $this->assertSame(ReleaseBrowseService::PASSWD_NONE, (int) $release->passwordstatus);
            $this->assertSame(0, (int) $release->haspreview);
            $this->assertNull($release->archive_retry_at);
        }
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function compressedCandidatePayloads(): array
    {
        $pad = static fn (string $head): string => str_pad($head, 512, "\x5A");
        $ts = str_repeat("\x47".str_repeat("\x10", 187), 3);

        return [
            'Matroska' => [$pad("\x1A\x45\xDF\xA3\x01\x00\x00\x00"), 'media'],
            'MP4' => [$pad("\x00\x00\x00\x20ftypmp42"), 'media'],
            'MOV' => [$pad("\x00\x00\x00\x14ftypqt  "), 'media'],
            'AVI' => [$pad('RIFF'."\x00\x10\x00\x00".'AVI LIST'), 'media'],
            'MPEG-TS' => [$ts, 'media'],
            'MPEG-PS' => [$pad("\x00\x00\x01\xBA\x44"), 'media'],
            'FLV' => [$pad("FLV\x01\x05\x00\x00\x00\x09"), 'media'],
            'Ogg' => [$pad("OggS\x00\x02"), 'media'],
            'RAR' => [null, 'archive'],
            'unknown data' => [$pad("\x8E\x13\x7F\x02random"), 'unknown'],
        ];
    }

    /**
     * @param  list<array{string, string, string|false|null}>  $files  title, message-id and its data (false: download fails,
     *                                                                 null: not downloaded, 'encrypted rar'/'encrypted zip'/'encrypted 7z': such an archive)
     */
    #[DataProvider('mediaBesideOtherCandidates')]
    public function test_plain_media_only_finalizes_when_no_other_archive_stayed_unread(array $files, int $status): void
    {
        $files = array_map(fn (array $file): array => [$file[0], $file[1], match ($file[2]) {
            'encrypted rar' => $this->rar('Movie.2026.1080p.mkv', str_repeat('x', 100), encryptedFile: true),
            'encrypted zip' => $this->zip('Movie.2026.1080p.mkv', str_repeat('x', 100), encryptedFile: true),
            'encrypted 7z' => $this->sevenZip('Movie.2026.1080p.mkv', str_repeat('x', 100), encryptedHeader: true),
            default => $file[2],
        }], $files);
        DB::table('releases')->insert([...$this->releaseRow(), 'nfostatus' => 1]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        config(['nntmux.archive_retry_delay' => 86400]);
        $this->freezeSecond();

        $config = $this->makeConfig(['processPasswords' => true, 'maximumRarPasswordChecks' => 2]);
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'sourceDigest' => $this->fixtureSourceSnapshot(Release::query()->findOrFail(1))['digest'],
            'contents' => array_map(static fn (array $file): array => ['title' => $file[0], 'segments' => [$file[1]]], $files),
        ]);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('beginReleaseScope')->once();
        $download->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        foreach ($files as [$title, $messageId, $data]) {
            if ($data === null) {
                continue;
            }
            $download->shouldReceive('download')->once()
                ->with(DownloadKind::Compressed, [$messageId], '', 1, $title)
                ->andReturn(['success' => $data !== false, 'data' => $data === false ? null : $data, 'groupUnavailable' => false, 'error' => null]);
        }
        $tmpPath = sys_get_temp_dir().'/media-payload-'.uniqid().'/';
        File::ensureDirectoryExists($tmpPath);
        $workspace = Mockery::mock(TempWorkspaceService::class);
        $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $workspace->shouldReceive('listFiles')->andReturn([]);
        $workspace->shouldReceive('clearDirectory')->once()->with($tmpPath, false)
            ->andReturnUsing(static fn () => File::deleteDirectory($tmpPath));
        $processor = new ReleaseProcessor(
            $config,
            $parser,
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config),
            Mockery::mock(MediaExtractionService::class)->shouldIgnoreMissing(false),
            $download,
            $this->makeManager(),
            Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(),
            $workspace,
            Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(),
        );

        $processor->process($this->sourceContext(Release::query()->findOrFail(1)), '/tmp/');

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($status, (int) $release->passwordstatus);
        $this->assertSame($status === -1 ? now()->addDay()->toDateTimeString() : null, $release->archive_retry_at);
    }

    /**
     * @return array<string, array{list<array{string, string, string|false|null}>, int}>
     */
    public static function mediaBesideOtherCandidates(): array
    {
        $mkv = str_pad("\x1A\x45\xDF\xA3\x01\x00\x00\x00", 512, "\x5A");
        $media = ['"Movie.2026.part01.rar" yEnc (1/50)', '<v1>', $mkv];
        $spaced = ['First Movie.rar yEnc (1/50)', '<v1>', $mkv];

        $unreadable = str_pad("\x8E\x13\x7F\x02", 512, "\x5A");
        $none = ReleaseBrowseService::PASSWD_NONE;
        $passworded = ReleaseBrowseService::PASSWD_RAR;

        return [
            'an unreadable archive beside it' => [[$media, ['"Extras.2026.rar" yEnc (1/3)', '<x1>', $unreadable]], -1],
            'a missing archive beside it' => [[$media, ['"Extras.2026.rar" yEnc (1/3)', '<x1>', false]], -1],
            'later volumes of the same file' => [[$media, ['"Movie.2026.part02.rar" yEnc (1/50)', '<v2>', null]], $none],
            'unquoted later volumes of the same file' => [[
                ['Movie.2026.part01.rar yEnc (1/50)', '<v1>', $mkv],
                ['Movie.2026.part02.rar yEnc (1/50)', '<v2>', null],
                ['[03/50] - Movie.2026.part03.rar yEnc', '<v3>', null],
            ], $none],
            'a split video piece and its later pieces' => [[['"Show.S01E01.mkv.001" yEnc (1/80)', '<s1>', $mkv], ['"Show.S01E01.mkv.002" yEnc (1/80)', '<s2>', null]], $none],
            'an unquoted split video piece and its later pieces' => [[['Show.S01E01.mkv.001 yEnc (1/80)', '<s1>', $mkv], ['Show.S01E01.mkv.002 yEnc (1/80)', '<s2>', null]], $none],
            'an encrypted same-stem ZIP beside it' => [[$media, ['"Movie.2026.zip" yEnc (1/3)', '<z1>', 'encrypted zip']], $passworded],
            'an encrypted same-stem 7z beside it' => [[$media, ['"Movie.2026.7z" yEnc (1/3)', '<z1>', 'encrypted 7z']], $passworded],
            'an unreadable same-stem ZIP beside it' => [[$media, ['"Movie.2026.zip" yEnc (1/3)', '<z1>', $unreadable]], -1],
            'an unreadable same-stem 7z beside it' => [[$media, ['"Movie.2026.7z.001" yEnc (1/3)', '<z1>', $unreadable]], -1],
            'an encrypted same-stem ZIP beside a split video' => [[
                ['"Movie.2026.mkv.001" yEnc (1/80)', '<s1>', $mkv],
                ['"Movie.2026.mkv.zip" yEnc (1/3)', '<z1>', 'encrypted zip'],
            ], $passworded],
            'an encrypted archive with another spaced name beside it' => [[$spaced, ['Second Movie.rar yEnc (1/50)', '<x1>', 'encrypted rar']], $passworded],
            'an unreadable archive with another spaced name beside it' => [[$spaced, ['Second Movie.rar yEnc (1/50)', '<x1>', $unreadable]], -1],
            'a missing archive with another spaced name beside it' => [[$spaced, ['Second Movie.rar yEnc (1/50)', '<x1>', false]], -1],
            'spaced later volumes of the same file' => [[
                ['First Movie.part01.rar yEnc (1/50)', '<v1>', $mkv],
                ['First Movie.part02.rar yEnc (1/50)', '<v2>', null],
                ['[03/50] - First Movie.part03.rar yEnc', '<v3>', null],
                ['"First Movie.part04.rar" yEnc (1/50)', '<v4>', null],
            ], $none],
        ];
    }

    #[DataProvider('incompleteArchiveNamingCases')]
    public function test_partial_archive_evidence_only_defers_episode_naming(string $kind, bool $episode): void
    {
        $title = $episode ? 'Show.S01E01.1080p.WEB-DL-GRP' : 'Movie.2026.1080p.WEB-DL-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $manager = $this->makeFullPar2NamingManager($sync);
        $dir = sys_get_temp_dir().'/'.uniqid('7z-naming-', true);
        mkdir($dir);
        try {
            $script = "#!/bin/sh\nprintf 'Path = ".$title.".mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n"
                ."printf 'Path = Show.S01E02\\001.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            file_put_contents($dir.'/7z', $script);
            chmod($dir.'/7z', 0755);
            $config = $this->makeConfig(['processPasswords' => true, 'sevenZipPath' => $dir.'/7z']);
            $planner = new AdditionalWorkPlanner($config);
            $context = $this->sourceContext(Release::query()->findOrFail(1));
            $context->tmpPath = $dir.'/';
            $context->nzbHasCompressedFile = true;
            $context->archiveInspectionAttempted = true;
            $contents = [['title' => '"a1b2c3d4.'.($kind === '7z' ? '7z' : 'rar').'" yEnc (1/1)', 'segments' => ['<archive>']]];
            if ($kind === 'truncated rar with extras') {
                $contents[] = ['title' => '"e5f6a7b8.rar" yEnc (1/1)', 'segments' => ['<extras>']];
            }
            $context->workPlan = $planner->plan($contents, '');
            $processor = new ReleaseProcessor(
                $config, Mockery::mock(NzbContentParser::class), $planner, new ArchiveExtractionService($config),
                Mockery::mock(MediaExtractionService::class), Mockery::mock(UsenetDownloadService::class), $manager,
                Mockery::mock(ReleaseFilesArchiveFallback::class), Mockery::mock(TempWorkspaceService::class),
                Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(),
            );
            $archive = $kind === '7z'
                ? $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true)
                : $this->rar($title.'.mkv', str_repeat('x', 1000));
            if ($kind === 'complete rar' || $kind === 'continuing rar') {
                $end = pack('Cvv', RarInfo::BLOCK_ENDARC, $kind === 'continuing rar' ? 1 : 0, 7);
                $archive .= pack('v', crc32($end) & 0xFFFF).$end;
            } elseif ($kind === 'complete rar5') {
                $name = $title.'.mkv';
                $main = "\x03\x01\x00\x00";
                $file = "\x02\x02\x0a\x00\x0a\x00\x00\x00".chr(strlen($name)).$name;
                $file = chr(strlen($file)).$file;
                $end = "\x03\x05\x00\x00";
                $archive = "Rar!\x1a\x07\x01\x00".pack('V', crc32($main)).$main
                    .pack('V', crc32($file)).$file.str_repeat('x', 10).pack('V', crc32($end)).$end;
            } elseif ($kind === 'payload boundary' || $kind === 'partial next header') {
                $prefixLength = strlen($archive);
                $archive .= substr($this->rar('Show.S01E02.1080p.WEB-DL-GRP.mkv', str_repeat('y', 100)), 20);
                $archive = substr($archive, 0, $prefixLength + ($kind === 'partial next header' ? 3 : 0));
            }
            if (str_starts_with($kind, 'truncated rar')) {
                // The full archive has a second episode; the downloaded prefix ends in E01's payload.
                $archive .= substr($this->rar('Show.S01E02.1080p.WEB-DL-GRP.mkv', str_repeat('y', 100)), 20);
                $archive = substr($archive, 0, strlen($archive) - 1100);
                $listing = (new ArchiveExtractionService($config))->processCompressedData($archive, $context, $dir.'/');
                $this->assertSame([$title.'.mkv'], array_column($listing['files'], 'name'));
                $this->assertGreaterThan(strlen($archive), $listing['files'][0]['next_offset']);
            }
            if ($kind === 'payload boundary' || $kind === 'partial next header' || str_starts_with($kind, 'complete rar')) {
                $reader = new RarInfo;
                $this->assertTrue($reader->setData($archive, true));
                $this->assertSame([$title.'.mkv'], array_column($reader->getFileList(), 'name'));
                $blocks = $reader->getBlocks(false);
                $last = end($blocks);
                if (str_starts_with($kind, 'complete rar')) {
                    $this->assertContains($last['head_type'], [RarInfo::BLOCK_ENDARC, RarInfo::R50_BLOCK_ENDARC]);
                    $this->assertFalse($last['more_volumes']);
                    $this->assertSame(strlen($archive), $last['next_offset']);
                } else {
                    $this->assertSame(RarInfo::BLOCK_FILE, $last['head_type']);
                    $this->assertSame(strlen($archive) - ($kind === 'partial next header' ? 3 : 0), $last['next_offset']);
                }
            }
            $readSets = [];
            $handler = new ReflectionMethod($processor, 'processArchiveData');

            $handler->invokeArgs($processor, [$context, $archive, [], false, 'a1b2c3d4', $context->workPlan->archiveCandidates[0], &$readSets]);
            if ($kind === 'truncated rar with extras') {
                $handler->invokeArgs($processor, [$context, $this->rar('extras.txt', 'extra'), [], false, 'e5f6a7b8.rar', $context->workPlan->archiveCandidates[1], &$readSets]);
            }
            (new ReflectionMethod($processor, 'runDeferredArchiveNameChecks'))->invoke($processor, $context, $readSets);
            $manager->finalizeRelease($context, true);

            $deferred = $episode && ! str_starts_with($kind, 'complete rar');
            $this->assertSame($deferred ? 'Example' : $title, DB::table('releases')->where('id', 1)->value('searchname'));
            $this->assertSame($deferred ? 0 : 7, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
            $this->assertSame($deferred ? 'Example' : $title, $context->release->searchname);
            $this->assertTrue(DB::table('release_files')->where('releases_id', 1)->where('name', $title.'.mkv')->exists());
            if ($kind === '7z') {
                $this->assertTrue($context->archiveListingIncomplete);
                $this->assertSame(-1, (int) DB::table('releases')->where('id', 1)->value('passwordstatus'));
            }
            if ($deferred) {
                Event::assertNotDispatched(ReleaseNameFixed::class);
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @return array<string, array{string, bool, 2?: string}> */
    public static function finalizedArchiveFilenameCases(): array
    {
        return [
            'confirmed two-episode compressed 7z' => ['pack 7z', false],
            'incomplete single-episode compressed 7z' => ['partial 7z', false],
            'incomplete single-episode rar' => ['partial rar', false],
            'complete single-episode compressed 7z' => ['complete 7z', true],
            'complete single-episode rar' => ['complete rar', true],
            'complete movie rar' => ['movie rar', true],
            'unattested single-episode zip' => ['partial zip', false],
            'unattested movie zip' => ['movie zip', true],
            'complete subset beside unread second archive' => ['subset 7z', false],
            'same-source partial then complete listing' => ['recovered 7z', true],
            'merged distinct archive files' => ['merged 7z', false],
            'known archive beside unknown file' => ['unknown 7z', false],
            'legacy first scan beside unread opaque archive' => ['first scan partial rar', false],
            'legacy first scan complete source' => ['first scan complete rar', true],
            'complete archive containing two different shows' => ['mixed shows 7z', false],
            'complete archive containing a pack plus another show' => ['mixed show pack 7z', false],
            'one episode with two encode variants' => ['episode variants 7z', true],
            'complete movie miss does not repeat a failed filename lookup' => ['movie miss rar', false],
            'complete episode miss does not repeat a failed filename lookup' => ['episode miss rar', false],
            'opaque filename mapped to an episode keeps same-cycle naming' => ['opaque episode rar', true],
            'terminal continuation runs only after outer commit' => ['first scan complete rar', true, 'commit'],
            'terminal continuation is discarded by outer rollback' => ['first scan complete rar', true, 'rollback'],
            'terminal continuation rejects successor claim' => ['first scan complete rar', true, 'claim'],
            'terminal continuation rejects successor source with same filename' => ['first scan complete rar', true, 'source'],
        ];
    }

    #[DataProvider('finalizedArchiveFilenameCases')]
    public function test_finalized_archive_scope_survives_the_real_prefile_consumer(string $kind, bool $accepted, string $terminalAction = 'normal'): void
    {
        $title = str_starts_with($kind, 'movie') ? 'Movie.2026.1080p.WEB-DL-GRP' : 'Show.S01E01.1080p.WEB-DL-GRP';
        $lookupControl = in_array($kind, ['movie miss rar', 'episode miss rar', 'opaque episode rar'], true);
        $lookupMiss = in_array($kind, ['movie miss rar', 'episode miss rar'], true);
        $firstScan = str_starts_with($kind, 'first scan') || $lookupControl;
        $mixedShows = str_starts_with($kind, 'mixed show');
        if ($mixedShows) {
            $title = 'ShowA.S01E01.1080p.WEB-DL-GRP';
        }
        $opaque = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $this->prepareFullPar2NamingEvidence($title);
        Schema::table('releases', static function (Blueprint $table): void {
            $table->string('fromname')->default('fixture');
        });
        $this->installFilenameSqlIdentity();
        if ($kind === 'opaque episode rar') {
            DB::table('predb')->where('id', 7)->update(['filename' => 'abcdef12.mkv']);
        }
        if (! $firstScan || $lookupMiss) {
            DB::table('predb')->where('id', 7)->delete();
        }
        $this->rebuildFilenameSqlIdentity();
        DB::table('releases')->where('id', 1)->update(['name' => $opaque, 'searchname' => $opaque, 'nfostatus' => 1]);
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$published): void {
            $published[] = $id;
        });
        $filenameChecks = [];
        $observe = $lookupControl ? static function (object $entry) use (&$filenameChecks): void {
            $filenameChecks[] = ['name' => $entry->filename, 'terminal' => isset($entry->observed_archive_source_digest)];
        } : null;
        $manager = $this->makeFullPar2NamingManager($sync, $observe);
        $dir = sys_get_temp_dir().'/'.uniqid('finalized-archive-scope-', true);
        mkdir($dir);
        try {
            config(['nntmux_settings.path_to_nzbs' => $dir.'/nzbs/']);
            $nzb = new TestNzbService;
            $sources = new NzbSourceGuard($nzb);
            $this->app->instance(NzbSourceGuard::class, $sources);
            $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
            $script = "#!/bin/sh\nprintf 'Path = ".$title.".mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            if ($kind === 'pack 7z') {
                $script .= "printf 'Path = Show.S01E02.1080p.WEB-DL-GRP.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            } elseif ($kind === 'episode variants 7z') {
                $script .= "printf 'Path = Show.S01E01.720p.WEB-DL-GRP.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            } elseif ($mixedShows) {
                if ($kind === 'mixed show pack 7z') {
                    $script .= "printf 'Path = ShowA.S01E02.1080p.WEB-DL-GRP.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
                }
                $script .= "printf 'Path = ShowB.S01E02.1080p.WEB-DL-GRP.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            } elseif (in_array($kind, ['partial 7z', 'recovered 7z'], true)) {
                $script .= "printf 'Path = Show.S01E02\\001.mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n";
            }
            file_put_contents($dir.'/7z', $script);
            chmod($dir.'/7z', 0755);
            $config = $this->makeConfig(['processPasswords' => true, 'sevenZipPath' => $dir.'/7z', 'maximumRarSegments' => $kind === 'merged 7z' ? 1 : 3, ...($firstScan ? ['archiveProbeFiles' => 0] : [])]);
            $planner = new AdditionalWorkPlanner($config);
            $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
            $context->tmpPath = $dir.'/';
            $context->nzbHasCompressedFile = true;
            $context->archiveInspectionAttempted = true;
            $isSevenZip = str_ends_with($kind, '7z');
            $isZip = str_ends_with($kind, ' zip');
            $subject = '"'.$opaque.'.'.($isSevenZip ? '7z' : ($isZip ? 'zip' : 'rar')).'" yEnc (1/1)';
            $source = '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'
                .'<file poster="fixture" date="1" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'
                .'<groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="100" number="1">archive</segment></segments></file></nzb>';
            if (in_array($kind, ['subset 7z', 'first scan partial rar'], true)) {
                $source = str_replace('</nzb>', '<file poster="fixture" date="1" subject="e5f60718'.($kind === 'first scan partial rar' ? '' : '.7z').' yEnc (1/1)"><groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="100" number="1">unread</segment></segments></file></nzb>', $source);
            }
            if (in_array($kind, ['merged 7z', 'unknown 7z'], true)) {
                $otherSubject = $kind === 'merged 7z' ? str_replace('(1/1)', '(2/2)', $subject) : '"deadbeef" yEnc (1/1)';
                $source = str_replace('</nzb>', '<file poster="fixture" date="1" subject="'.htmlspecialchars($otherSubject, ENT_QUOTES | ENT_XML1).'"><groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="100" number="1">unread</segment></segments></file></nzb>', $source);
                $rawFiles = (new NzbParserService)->parseNzbFileList($source, ['no-file-key' => false, 'strip-count' => true]);
                $this->assertSame($kind === 'merged 7z' ? [2] : [1, 1], array_column(array_values($rawFiles), 'filecount'));
            }
            file_put_contents($nzb->getNzbPath($context->release->guid, createIfNotExist: true), gzencode($source));
            $listedName = $kind === 'opaque episode rar' ? 'abcdef12.mkv' : $title.'.mkv';
            $archive = $isSevenZip
                ? $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true)
                : ($isZip ? $this->zip($title.'.mkv', str_repeat('x', 100)) : $this->rar($listedName, str_repeat('x', 100)));
            if (! $isSevenZip && ! $isZip && ($accepted || $firstScan)) {
                $end = pack('Cvv', RarInfo::BLOCK_ENDARC, 0, 7);
                $archive .= pack('v', crc32($end) & 0xFFFF).$end;
            }
            $downloads = Mockery::mock(UsenetDownloadService::class);
            $attempts = $kind === 'recovered 7z' ? 2 : 1;
            $downloads->shouldReceive('beginReleaseScope')->times($attempts)->andReturnNull();
            $downloads->shouldReceive('finishReleaseScope')->times($attempts)->andReturn(new DownloadMetrics);
            $success = ['success' => true, 'data' => $archive, 'groupUnavailable' => false, 'error' => null];
            if ($kind === 'subset 7z') {
                $downloads->shouldReceive('download')->twice()->andReturn($success, [
                    'success' => false, 'data' => null, 'groupUnavailable' => false, 'error' => 'fixture unread second archive',
                ]);
            } else {
                $downloads->shouldReceive('download')->times($attempts)
                    ->withArgs(static fn (DownloadKind $downloadKind, array $ids): bool => $downloadKind === DownloadKind::Compressed && $ids === ['archive'])
                    ->andReturn($success);
            }
            $workspace = Mockery::mock(TempWorkspaceService::class);
            $workspace->shouldReceive('createReleaseTempFolder')->times($attempts)->andReturn($dir.'/');
            $workspace->shouldReceive('listFiles')->andReturn([]);
            $workspace->shouldReceive('clearDirectory')->times($attempts)->with($dir.'/', false)->andReturnNull();
            $processor = new ReleaseProcessor(
                $config, new NzbContentParser($nzb, new NzbParserService), $planner, new ArchiveExtractionService($config),
                Mockery::mock(MediaExtractionService::class), $downloads, $manager,
                Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(), $workspace,
                Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(), $sync,
            );
            if ($firstScan) {
                $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
                $this->assertSame(0, DB::table('release_files')->count());
            }
            $successorObserved = false;
            $replacementSource = str_replace('>archive</segment>', '>replacement</segment>', $source);
            if ($terminalAction !== 'normal') {
                DB::beginTransaction();
                DB::afterCommit(function () use ($terminalAction, $sources, $nzb, $replacementSource, $archive, $config, $dir, $title, &$successorObserved): void {
                    $successorObserved = true;
                    if ($terminalAction === 'claim') {
                        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'successor-owner']);
                    } elseif ($terminalAction === 'source') {
                        DB::transaction(function () use ($sources, $nzb, $replacementSource, $archive, $config, $dir, $title): void {
                            $current = Release::query()->where('id', 1)->lockForUpdate()->firstOrFail();
                            $temporary = $dir.'/replacement.nzb.gz';
                            file_put_contents($temporary, gzencode($replacementSource));
                            $receipt = $sources->install($current, $temporary, $nzb->getNzbPath($current->guid));
                            DB::afterRollBack($receipt->rollback(...));
                            DB::afterCommit($receipt->close(...));
                            $listed = (new ArchiveExtractionService($config))->processCompressedData($archive, new ReleaseProcessingContext($current), $dir.'/');
                            $this->assertTrue($listed['success']);
                            $this->assertSame([$title.'.mkv'], array_column($listed['files'], 'name'));
                            $this->assertTrue(app(ReleaseArchiveNamingEvidence::class)->recordLocked($current, $receipt->digest, 'complete_single', array_column($listed['files'], 'name'), true));
                        });
                    }
                });
            }
            $result = $processor->process($context, $dir.'/');
            if ($kind === 'recovered 7z') {
                $this->assertFalse($context->archiveNamingInspectionComplete);
                $this->assertSame('incomplete', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
                file_put_contents($dir.'/7z', "#!/bin/sh\nprintf 'Path = ".$title.".mkv\\nSize = 100\\nEncrypted = -\\n\\n'\n");
                $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
                $result = $processor->process($context, $dir.'/');
            }
            $this->assertSame($kind === 'recovered 7z' ? ProcessingOutcome::NoUsefulArtifacts : ProcessingOutcome::Completed, $result->outcome);
            $this->assertSame(NzbSourceGuard::digest($source), $context->nzbSourceDigest);
            if ($lookupControl) {
                $this->assertSame('complete_single', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
                $this->assertTrue(DB::table('release_files')->where('releases_id', 1)->where('name', $listedName)->exists());
                $this->assertSame($accepted ? $title : $opaque, DB::table('releases')->where('id', 1)->value('searchname'));
                $this->assertSame($accepted ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
                $this->assertCount($accepted ? 2 : 1, $filenameChecks, 'Only a real archive-proof-deferred canonical episode lookup may be repeated after finalization.');
                $this->assertSame([['name' => $listedName, 'terminal' => false], ...($accepted ? [['name' => $listedName, 'terminal' => true]] : [])], $filenameChecks);
                if ($accepted) {
                    Event::assertDispatched(ReleaseNameFixed::class);
                } else {
                    Event::assertNotDispatched(ReleaseNameFixed::class);
                }

                return;
            }
            if ($terminalAction !== 'normal') {
                $this->assertFalse($successorObserved);
                $this->assertSame($opaque, DB::table('releases')->where('id', 1)->value('searchname'), 'Producer naming must wait for the outer transaction to commit.');
                $this->assertTrue(DB::table('release_files')->where('releases_id', 1)->where('name', $title.'.mkv')->exists());
                Event::assertNotDispatched(ReleaseNameFixed::class);
                if ($terminalAction === 'rollback') {
                    DB::rollBack();
                    $this->assertFalse($successorObserved);
                    $this->assertSame(0, DB::table('release_files')->count());
                    $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
                } else {
                    DB::commit();
                    $this->assertTrue($successorObserved);
                    $this->assertSame('complete_single', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
                }
                $renamed = $terminalAction === 'commit';
                $this->assertSame($renamed ? $title : $opaque, DB::table('releases')->where('id', 1)->value('searchname'));
                $this->assertSame($renamed ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
                if ($terminalAction === 'claim') {
                    $this->assertSame('successor-owner', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
                } elseif ($terminalAction === 'source') {
                    $this->assertSame(NzbSourceGuard::digest($replacementSource), DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('source_sha256'));
                    $this->assertNotSame($context->nzbSourceDigest, $sources->snapshot($context->release->guid)['digest']);
                    $this->assertTrue(DB::table('release_files')->where('releases_id', 1)->where('name', $title.'.mkv')->exists());
                }
                if ($renamed) {
                    Event::assertDispatched(ReleaseNameFixed::class);
                } else {
                    Event::assertNotDispatched(ReleaseNameFixed::class);
                }

                return;
            }
            if ($firstScan) {
                $this->assertCount(1, $context->workPlan?->archiveCandidates ?? []);
                $this->assertCount($accepted ? 1 : 2, $context->nzbSourceFiles ?? []);
                $this->assertTrue(DB::table('release_files')->where('releases_id', 1)->where('name', $title.'.mkv')->exists());
                $this->assertSame($accepted ? 'complete_single' : 'incomplete', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
                $this->assertSame($accepted ? $title : $opaque, DB::table('releases')->where('id', 1)->value('searchname'), 'First-scan PreDB must not rename from one complete archive while another source remains unread.');
                $this->assertSame($accepted ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
                if ($accepted) {
                    Event::assertDispatched(ReleaseNameFixed::class);
                } else {
                    Event::assertNotDispatched(ReleaseNameFixed::class);
                }

                return;
            }
            if (! $mixedShows && ! in_array($kind, ['merged 7z', 'unknown 7z'], true)) {
                $this->assertSame(in_array($kind, ['pack 7z', 'complete 7z', 'complete rar', 'movie rar', 'recovered 7z', 'episode variants 7z'], true), $context->archiveNamingInspectionComplete);
                $this->assertSame(! $accepted, $context->defersArchiveNameChecks());
                $this->assertSame($kind === 'pack 7z' ? 'multi_episode'
                    : (in_array($kind, ['complete 7z', 'complete rar', 'movie rar', 'recovered 7z', 'episode variants 7z'], true) ? 'complete_single' : 'incomplete'),
                    DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
            }

            $before = DB::table('releases')->where('id', 1)->first();
            $this->assertSame($opaque, $before->searchname, 'The fixture must reach the ordinary consumer without an earlier rename.');
            $this->assertSame(0, (int) $before->predb_id);
            $this->assertSame(0, (int) $before->isrenamed);
            $this->assertSame(NameFixingService::PROC_FILES_NONE, (int) $before->proc_files);
            $inventory = DB::table('release_files')->where('releases_id', 1)->orderBy('name')->get()->map(static fn (object $file): array => (array) $file)->all();
            $this->assertContains($title.'.mkv', array_column($inventory, 'name'));
            $this->assertCount($kind === 'mixed show pack 7z' ? 3 : ((in_array($kind, ['pack 7z', 'episode variants 7z'], true) || $mixedShows) ? 2 : 1), $inventory);
            Event::assertNotDispatched(ReleaseNameFixed::class);
            DB::table('predb')->insert(['id' => 7, 'title' => $title]);
            $this->rebuildFilenameSqlIdentity();
            $consumerUpdates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
            // A new service has no processing context: this is the real persisted-file consumer.
            (new NameFixingService($consumerUpdates))->getPreFileNames(['prefiles', 1]);

            $after = DB::table('releases')->where('id', 1)->first();
            $this->assertSame($inventory, DB::table('release_files')->where('releases_id', 1)->orderBy('name')->get()->map(static fn (object $file): array => (array) $file)->all(), 'Scope protection must preserve all stored file evidence.');
            $this->assertSame($accepted ? $title : $opaque, $after->searchname, 'The real second-stage consumer must respect finalized archive scope.');
            if (in_array($kind, ['merged 7z', 'unknown 7z'], true)) {
                $this->assertFalse($context->archiveNamingInspectionComplete);
                $this->assertSame('incomplete', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
            }
            $this->assertSame($accepted ? 7 : 0, (int) $after->predb_id);
            $this->assertSame($accepted, $consumerUpdates->matched);
            if ($accepted) {
                Event::assertDispatched(ReleaseNameFixed::class);
            } else {
                Event::assertNotDispatched(ReleaseNameFixed::class);
            }
        } finally {
            if ($terminalAction !== 'normal') {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
            File::deleteDirectory($dir);
        }
    }

    /** @return iterable<string, array{list<string>, array<string, string>, bool, bool}> */
    public static function unresolvedArchivePayloadCases(): iterable
    {
        $e01 = 'Show.S01E01.1080p.WEB-DL-GRP';
        $e02 = 'Show.S01E02.1080p.WEB-DL-GRP';
        $opaque = 'abcdef12.mkv';
        $otherOpaque = 'fedcba34.mkv';
        foreach (['same-cycle' => true, 'later consumer' => false] as $phase => $firstScan) {
            yield 'named E01 then opaque E02 '.$phase => [[$e01.'.mkv', $opaque], [$opaque => $e02], false, $firstScan];
            yield 'opaque E02 then named E01 '.$phase => [[$opaque, $e01.'.mkv'], [$opaque => $e02], false, $firstScan];
            yield 'two opaque episode siblings '.$phase => [[$opaque, $otherOpaque], [$opaque => $e01, $otherOpaque => $e02], false, $firstScan];
            yield 'two extensionless opaque siblings '.$phase => [['abcdefgh', 'hgfedcba'], ['abcdefgh' => $e01, 'hgfedcba' => $e02], false, $firstScan];
            yield 'two unknown-suffix opaque siblings '.$phase => [['abcdef12.blob', 'fedcba34.blob'], ['abcdef12.blob' => $e01, 'fedcba34.blob' => $e02], false, $firstScan];
            yield 'extensionless opaque singleton '.$phase => [['abcdefgh'], ['abcdefgh' => $e01], true, $firstScan];
            yield 'unknown-suffix opaque singleton '.$phase => [['abcdef12.blob'], ['abcdef12.blob' => $e01], true, $firstScan];
            yield 'named E01 and opaque same E01 '.$phase => [[$e01.'.mkv', $opaque], [$opaque => $e01], true, $firstScan];
            yield 'opaque singleton '.$phase => [[$opaque], [$opaque => $e01], true, $firstScan];
            yield 'opaque main with ancillary and sample files '.$phase => [
                [$opaque, 'readme.txt', 'subtitles.srt', 'Sample/Show.S01E02.sample.mkv', 'Show.S01E02.proof.mkv'],
                [$opaque => $e01], true, $firstScan,
            ];
        }
        foreach (['same-cycle' => true, 'later consumer' => false] as $phase => $firstScan) {
            foreach (['S01E01E02', 'S01E01.E02', 'S01E01-E03', 'S01E01-03', '1x01-02', '1x01x02', '1x01-1x02', 'S01E01.S02E02'] as $tokens) {
                $combined = 'Show.'.$tokens.'.1080p.WEB-DL-GRP';
                yield 'admission token combined witness '.$tokens.' '.$phase => [[$e01.'.mkv', $combined.'.mkv'], [$e01.'.mkv' => $e01], false, $firstScan];
                yield 'admission token combined canonical '.$tokens.' '.$phase => [[$e01.'.mkv'], [$e01.'.mkv' => $combined], false, $firstScan];
            }
            foreach (['S01', 'Season.1', 'Complete.Season', 'Complete.Series'] as $tokens) {
                $pack = 'Show.'.$tokens.'.1080p.WEB-DL-GRP.mkv';
                yield 'admission token pack witness '.$tokens.' '.$phase => [[$pack], [$pack => $e01], false, $firstScan];
            }
            $pack = 'Show.S01.1080p.WEB-DL-GRP.mkv';
            yield 'admission token episode beside season pack '.$phase => [[$e01.'.mkv', $pack], [$pack => $e01], false, $firstScan];
            $coexisting = 'Show.S01E01.Complete.Season.1080p.WEB-DL-GRP.mkv';
            yield 'admission token episode and pack coexist '.$phase => [[$coexisting], [$coexisting => $e01], false, $firstScan];
            foreach ([
                'simple x' => ['Show.1x01.1080p.WEB-DL-GRP.mkv', $e01],
                'year season' => ['Show.S2024E01.1080p.WEB-DL-GRP.mkv', 'Show.S2024E01.1080p.WEB-DL-GRP'],
                'dotted year season' => ['Show.S2024.E01.1080p.WEB-DL-GRP.mkv', 'Show.S2024.E01.1080p.WEB-DL-GRP'],
                'hyphen before resolution' => ['Show.S01E01-1080p.WEB-DL-GRP.mkv', $e01],
                'numeric episode title' => ['Show.S01E01.24.1080p.WEB-DL-GRP.mkv', $e01],
            ] as $label => [$filename, $canonical]) {
                yield 'admission token positive '.$label.' '.$phase => [[$filename], [$filename => $canonical], true, $firstScan];
            }
            yield 'admission token sample and proof stay ancillary '.$phase => [
                [$e01.'.mkv', 'Show.S01E01E02.sample.mkv', 'Show.S01E01.Complete.Season.proof.mkv', 'readme.txt'],
                [$e01.'.mkv' => $e01], true, $firstScan,
            ];
        }
        foreach (['same-cycle' => true, 'later consumer' => false] as $phase => $firstScan) {
            foreach (['S01E01', 'S01.E01', 'S2024E01', '1x01'] as $notation) {
                foreach (['x264', 'x265'] as $codec) {
                    $title = 'Show.'.$notation.'.1080p.WEB.'.$codec.'-GRP';
                    yield 'codec regression named '.$notation.' '.$codec.' '.$phase => [[$title.'.mkv'], [$title.'.mkv' => $title], true, $firstScan];
                    yield 'codec regression opaque '.$notation.' '.$codec.' '.$phase => [[$opaque], [$opaque => $title], true, $firstScan];
                }
            }
            foreach ([
                'h264 audio and bitdepth' => 'Show.S01E01.1080p.WEB.H264.DDP5.1.8bit-GRP',
                'h265 audio and bitdepth' => 'Show.S01E01.2160p.WEB.H265.EAC3.10bit-GRP',
                'hevc quality' => 'Show.S01E01.2160p.UHD.HEVC.HDR10-GRP',
                'avc quality' => 'Show.S01E01.720p.BluRay.AVC.DTS5.1-GRP',
                'uppercase dot codec' => 'Show.S01E01.1080p.WEB.X264.DDP5.1-GRP',
                'hyphen codec boundary' => 'Show.S01E01-1080p-WEB-x265-GRP',
            ] as $label => $title) {
                yield 'codec regression release info '.$label.' '.$phase => [[$title.'.mkv'], [$title.'.mkv' => $title], true, $firstScan];
            }
            foreach (['x02', 'x264E02', 'x265x02', 'x264.E02', 'x265.E02', 'x264.S02', 'x265.Complete.Season'] as $continuation) {
                $ambiguous = 'Show.S01E01.1080p.WEB.'.$continuation.'-GRP';
                yield 'codec regression unsafe witness '.$continuation.' '.$phase => [[$ambiguous.'.mkv'], [$ambiguous.'.mkv' => $e01], false, $firstScan];
                yield 'codec regression unsafe canonical '.$continuation.' '.$phase => [[$opaque], [$opaque => $ambiguous], false, $firstScan];
            }
            yield 'codec regression immediate x continuation '.$phase => [[$opaque], [$opaque => 'Show.1x01x02.1080p.WEB.x264-GRP'], false, $firstScan];
        }
        yield 'same episode encode variants' => [[$e01.'.mkv', 'Show.S01E01.720p.WEB-DL-GRP.mkv'], [$e01.'.mkv' => $e01], true, true];
        yield 'ordinary movie with an unresolved video sibling' => [
            ['Movie.2026.1080p.WEB-DL-GRP.mkv', $opaque],
            ['Movie.2026.1080p.WEB-DL-GRP.mkv' => 'Movie.2026.1080p.WEB-DL-GRP'], true, true,
        ];
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $mappings
     */
    #[DataProvider('unresolvedArchivePayloadCases')]
    public function test_real_archive_consumer_requires_one_resolved_main_episode_identity(array $names, array $mappings, bool $accepted, bool $firstScan): void
    {
        $canonicalTitle = array_values($mappings)[0];
        $this->prepareFullPar2NamingEvidence($canonicalTitle);
        Schema::table('releases', static function (Blueprint $table): void {
            $table->string('fromname')->default('fixture');
        });
        $this->installFilenameSqlIdentity();
        DB::table('predb')->delete();
        if ($firstScan) {
            $id = 7;
            foreach ($mappings as $filename => $title) {
                DB::table('predb')->insert(['id' => $id++, 'title' => $title, 'filename' => $filename]);
            }
        }
        $this->rebuildFilenameSqlIdentity();
        $opaqueRelease = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        DB::table('releases')->where('id', 1)->update(['name' => $opaqueRelease, 'searchname' => $opaqueRelease, 'nfostatus' => 1]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $manager = $this->makeFullPar2NamingManager($sync);
        $directory = sys_get_temp_dir().'/resolved-main-episode-'.bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            config(['nntmux_settings.path_to_nzbs' => $directory.'/nzbs/']);
            $nzb = new TestNzbService;
            $sources = new NzbSourceGuard($nzb);
            $this->app->instance(NzbSourceGuard::class, $sources);
            $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
            $script = "#!/bin/sh\n";
            foreach ($names as $name) {
                $record = 'Path = '.$name."\nSize = 100\nEncrypted = -\n\n";
                $script .= 'printf %s '.escapeshellarg($record)."\n";
            }
            file_put_contents($directory.'/7z', $script);
            chmod($directory.'/7z', 0755);
            $source = '<nzb><file poster="fixture" date="1" subject="&quot;deadbeef.7z&quot; yEnc (1/1)">'
                .'<groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="100" number="1">archive</segment></segments></file></nzb>';
            $release = Release::query()->findOrFail(1);
            file_put_contents($nzb->getNzbPath($release->guid, createIfNotExist: true), gzencode($source));
            $context = new ReleaseProcessingContext($release);
            $context->tmpPath = $directory.'/';
            $archive = $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true);
            $config = $this->makeConfig(['processPasswords' => true, 'sevenZipPath' => $directory.'/7z']);
            $downloads = Mockery::mock(UsenetDownloadService::class);
            $downloads->shouldReceive('beginReleaseScope')->once()->andReturnNull();
            $downloads->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
            $downloads->shouldReceive('download')->once()->withArgs(static fn (DownloadKind $kind, array $ids): bool => $kind === DownloadKind::Compressed && $ids === ['archive'])
                ->andReturn(['success' => true, 'data' => $archive, 'groupUnavailable' => false, 'error' => null]);
            $workspace = Mockery::mock(TempWorkspaceService::class);
            $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($directory.'/');
            $workspace->shouldReceive('listFiles')->andReturn([]);
            $workspace->shouldReceive('clearDirectory')->once()->with($directory.'/', false)->andReturnNull();
            $processor = new ReleaseProcessor(
                $config, new NzbContentParser($nzb, new NzbParserService), new AdditionalWorkPlanner($config), new ArchiveExtractionService($config),
                Mockery::mock(MediaExtractionService::class), $downloads, $manager,
                Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(), $workspace,
                Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(), $sync,
            );
            $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
            $result = $processor->process($context, $directory.'/');
            $this->assertSame(ProcessingOutcome::Completed, $result->outcome);
            if (in_array('Show.1x01x02.1080p.WEB-DL-GRP.mkv', $names, true)) {
                $this->assertFalse($context->archiveListingIncomplete, 'The decoded raw listing is complete even though its episode identity is ambiguous.');
                $this->assertFalse($context->archiveNamingInspectionComplete);
                $this->assertTrue($context->archiveNameEvidenceIncomplete);
            } else {
                $this->assertTrue($context->archiveNamingInspectionComplete, 'All source files and decoded archive entries must actually have been inspected.');
            }
            $this->assertSame(NzbSourceGuard::digest($source), $context->nzbSourceDigest);
            $this->assertSame($context->nzbSourceDigest, DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('source_sha256'));
            if ($accepted && $canonicalTitle !== 'Movie.2026.1080p.WEB-DL-GRP') {
                $this->assertSame('complete_single', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
                $this->assertTrue(DB::transaction(fn (): bool => app(ReleaseArchiveNamingEvidence::class)->recordLocked(
                    Release::query()->findOrFail(1), $context->nzbSourceDigest, 'complete_single', $names, true
                )));
                $this->assertSame('complete_single', DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'), 'Repeated inspection of the same source must preserve complete_single.');
            }
            $inventory = DB::table('release_files')->where('releases_id', 1)->orderBy('name')->get()->map(static fn (object $file): array => (array) $file)->all();
            $expectedNames = $names;
            sort($expectedNames, SORT_STRING);
            $this->assertSame($expectedNames, array_column($inventory, 'name'), 'Every payload and ancillary row must remain in inventory.');
            if (! $firstScan) {
                $this->assertSame($opaqueRelease, DB::table('releases')->where('id', 1)->value('searchname'));
                Event::assertNotDispatched(ReleaseNameFixed::class);
                $id = 7;
                foreach ($mappings as $filename => $title) {
                    DB::table('predb')->insert(['id' => $id++, 'title' => $title, 'filename' => $filename]);
                }
                $this->rebuildFilenameSqlIdentity();
                $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
                (new NameFixingService($updates))->getPreFileNames(['prefiles', 1]);
                $this->assertSame($accepted, $updates->matched);
            }
            $this->assertSame($inventory, DB::table('release_files')->where('releases_id', 1)->orderBy('name')->get()->map(static fn (object $file): array => (array) $file)->all());
            $this->assertSame($accepted ? $canonicalTitle : $opaqueRelease, DB::table('releases')->where('id', 1)->value('searchname'), 'Episode admission must account for every main video, including the validated candidate identity.');
            $this->assertSame($accepted ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
            if ($accepted) {
                Event::assertDispatched(ReleaseNameFixed::class);
            } else {
                Event::assertNotDispatched(ReleaseNameFixed::class);
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function legacyEpisodeAdmissionCases(): array
    {
        return [
            'seasonless Episode token is not a complete identity' => ['Show.Episode.5.1080p.WEB-DL-GRP', false],
            'seasonless EP token is not a complete identity' => ['Show.EP05.1080p.WEB-DL-GRP', false],
            'historical simple episode keeps legacy compatibility' => ['Show.S01E01.1080p.WEB-DL-GRP', true],
            'historical x264 episode keeps legacy compatibility' => ['Show.S01E01.1080p.WEB.x264-GRP', true],
            'historical x265 episode keeps legacy compatibility' => ['Show.S01E01.1080p.WEB.x265-GRP', true],
            'historical x02 continuation is not a singleton' => ['Show.S01E01.1080p.WEB.x02-GRP', false],
        ];
    }

    #[DataProvider('legacyEpisodeAdmissionCases')]
    public function test_legacy_consumer_requires_a_single_show_and_season_episode_identity(string $title, bool $accepted): void
    {
        $this->prepareFullPar2NamingEvidence($title);
        Schema::table('releases', static function (Blueprint $table): void {
            $table->string('fromname')->default('fixture');
        });
        $this->installFilenameSqlIdentity();
        $opaque = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        DB::table('releases')->where('id', 1)->update([
            'name' => $opaque, 'searchname' => $opaque,
            'additional_pp_claim_token' => null, 'additional_pp_claimed_at' => null,
        ]);
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $title.'.mkv', 'size' => 100]);
        $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
        $inventory = DB::table('release_files')->get()->map(static fn (object $file): array => (array) $file)->all();
        $sources = new NzbSourceGuard(new TestNzbService);
        $this->app->instance(NzbSourceGuard::class, $sources);
        $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);

        (new NameFixingService($updates))->getPreFileNames(['prefiles', 1]);

        $this->assertSame($accepted, $updates->matched);
        $this->assertSame($accepted ? $title : $opaque, DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertSame($accepted ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
        $this->assertSame($inventory, DB::table('release_files')->get()->map(static fn (object $file): array => (array) $file)->all());
        if ($accepted) {
            Event::assertDispatched(ReleaseNameFixed::class);
        } else {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    public function test_archive_proof_deferral_is_reset_between_real_filename_attempts(): void
    {
        $title = 'Show.S01E01.1080p.WEB-DL-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        DB::table('predb')->where('id', 7)->update(['filename' => 'abcdef12.mkv']);
        $this->rebuildFilenameSqlIdentity();
        $release = Release::query()->findOrFail(1);
        $context = $this->sourceContext($release);
        $sourceDigest = $context->nzbSourceDigest;
        $this->assertNotNull($sourceDigest);
        DB::transaction(static function () use ($sourceDigest): void {
            $current = DB::table('releases')->where('id', 1)->lockForUpdate()->first();
            app(ReleaseArchiveNamingEvidence::class)->sourceReplacedLocked($current, $sourceDigest);
        });
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class));
        $names = new NameFixingService($updates);
        $observed = DB::table('releases')->where('id', 1)->first();
        $entry = clone $release;
        $entry->setAttribute('observed_additional_pp_claim', ['token' => $observed->additional_pp_claim_token, 'claimed_at' => $observed->additional_pp_claimed_at]);
        $entry->setAttribute('filename', 'abcdef12.mkv');

        $this->assertSame(0, $names->matchPreDbFiles($entry, true, true, true));
        $this->assertTrue($names->wasEpisodeNamingDeferredByArchiveProof());
        $entry->setAttribute('filename', 'Different.Movie.2026.1080p.mkv');
        $this->assertSame(0, $names->matchPreDbFiles($entry, true, true, true));
        $this->assertFalse($names->wasEpisodeNamingDeferredByArchiveProof(), 'A later actual miss must not inherit a prior canonical proof rejection.');
        $entry->setAttribute('filename', 'abcdef12.mkv');
        $this->assertSame(0, $names->matchPreDbFiles($entry, true, true, true));
        $this->assertTrue($names->wasEpisodeNamingDeferredByArchiveProof());
        $names->reset();
        $this->assertFalse($names->wasEpisodeNamingDeferredByArchiveProof());
        $this->assertSame(0, $names->matchPreDbFiles($entry, true, true, true));
        $this->assertTrue($names->wasEpisodeNamingDeferredByArchiveProof());
        $updates->updateRelease($entry, '', 'fixture invalid candidate', true, '', true, true);
        $this->assertFalse($names->wasEpisodeNamingDeferredByArchiveProof(), 'A new update attempt clears the per-attempt reason before validation.');
        DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'successor-owner']);
        $this->assertSame(0, $names->matchPreDbFiles($entry, true, true, true));
        $this->assertFalse($names->wasEpisodeNamingDeferredByArchiveProof(), 'Stale claim rejection is not an archive-proof deferral.');
        $this->assertSame('Example', DB::table('releases')->where('id', 1)->value('searchname'));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string, int, bool}> */
    public static function directBodyNamingCases(): array
    {
        return [
            'actual single episode body' => ['episode', 1, true],
            'actual movie body' => ['movie', 1, true],
            'actual two episode bodies' => ['episode', 2, false],
            'cached bytes without yenc header' => ['cached', 1, false],
            'actual header does not match nzb name' => ['different name', 1, false],
            'archive bytes disguised as video' => ['archive', 1, false],
            'third source was not fetched' => ['episode', 3, false],
        ];
    }

    #[DataProvider('directBodyNamingCases')]
    public function test_existing_direct_media_fetches_prove_only_their_actual_current_file_names(string $kind, int $count, bool $accepted): void
    {
        $title = $kind === 'movie' ? 'Movie.2026.1080p.WEB-DL-GRP' : 'Show.S01E01.1080p.WEB-DL-GRP';
        $this->prepareFullPar2NamingEvidence($title);
        Schema::table('releases', static function (Blueprint $table): void {
            $table->string('fromname')->default('fixture');
        });
        $this->installFilenameSqlIdentity();
        $opaque = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        DB::table('releases')->where('id', 1)->update(['name' => $opaque, 'searchname' => $opaque, 'nfostatus' => 1]);
        DB::table('predb')->delete();
        $this->rebuildFilenameSqlIdentity();
        $dir = sys_get_temp_dir().'/'.uniqid('direct-body-scope-', true);
        mkdir($dir);
        try {
            config(['nntmux_settings.path_to_nzbs' => $dir.'/nzbs/']);
            $nzb = new TestNzbService;
            $sources = new NzbSourceGuard($nzb);
            $this->app->instance(NzbSourceGuard::class, $sources);
            $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
            $names = [];
            $source = '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">';
            for ($index = 0; $index < $count; $index++) {
                $name = $index === 0 ? $title.'.mkv' : 'Show.S01E0'.($index + 1).'.1080p.WEB-DL-GRP.mkv';
                $names['video-'.$index] = $name;
                $subject = '"'.$name.'" yEnc (1/1)';
                $source .= '<file poster="fixture" date="1" subject="'.htmlspecialchars($subject, ENT_QUOTES | ENT_XML1).'">'
                    .'<groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="128" number="1">video-'.$index.'</segment></segments></file>';
            }
            $source .= '</nzb>';
            $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
            file_put_contents($nzb->getNzbPath($context->release->guid, createIfNotExist: true), gzencode($source));
            $config = $this->makeConfig(['processThumbnails' => true, 'mediaMainVideoFallback' => true,
                'mediaMainVideoMinBytes' => 1, 'mediaSampleMaxBytes' => 512]);
            $downloads = Mockery::mock(UsenetDownloadService::class);
            $downloads->shouldReceive('beginReleaseScope')->once()->andReturnNull();
            $downloads->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
            $downloads->shouldReceive('download')->never();
            $fetched = [];
            $downloads->shouldReceive('downloadArticle')->times(min(2, $count))->andReturnUsing(
                function (DownloadKind $downloadKind, string $id) use (&$fetched, $names, $kind): array {
                    $this->assertSame(DownloadKind::MediaSource, $downloadKind);
                    $fetched[] = $id;
                    $data = $kind === 'archive' ? $this->rar('Hidden.S01E02.mkv', str_repeat('x', 100)) : "\x1a\x45\xdf\xa3".str_repeat('x', 100);

                    return ['success' => true, 'data' => $data,
                        'part' => $kind === 'cached' ? null : new YencPart($kind === 'different name' ? 'Other.S01E01.mkv' : $names[$id], max(128, strlen($data)), 1, 1, strlen($data)),
                        'groupUnavailable' => false, 'error' => null];
                }
            );
            $media = Mockery::mock(MediaExtractionService::class);
            $media->shouldReceive('getSample')->times(min(2, $count))->andReturnFalse();
            $workspace = Mockery::mock(TempWorkspaceService::class);
            $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($dir.'/');
            $workspace->shouldReceive('listFiles')->andReturn([]);
            $workspace->shouldReceive('clearDirectory')->once()->with($dir.'/', false)->andReturnNull();
            $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
            $processor = new ReleaseProcessor($config, new NzbContentParser($nzb, new NzbParserService),
                new AdditionalWorkPlanner($config), new ArchiveExtractionService($config), $media, $downloads,
                $this->makeFullPar2NamingManager($sync), Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(),
                $workspace, Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(), $sync);
            $processor->process($context, $dir.'/');
            $this->assertSame(array_slice(array_keys($names), 0, 2), $fetched, 'Admission must use only the already planned media fetches.');
            $this->assertSame($count <= 2 && in_array($kind, ['episode', 'movie'], true), $context->archiveNamingInspectionComplete);
            $expectedScope = $count > 1 ? 'multi_episode' : ($accepted ? 'complete_single' : 'incomplete');
            $this->assertSame($expectedScope, DB::table('release_archive_naming_evidence')->where('releases_id', 1)->value('scope'));
            $stored = DB::table('release_files')->where('releases_id', 1)->orderBy('name')->pluck('name')->all();
            $this->assertCount(in_array($kind, ['episode', 'movie'], true) ? min(2, $count) : 0, $stored);
            DB::table('predb')->insert(['id' => 7, 'title' => $title]);
            $this->rebuildFilenameSqlIdentity();
            $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
            (new NameFixingService($updates))->getPreFileNames(['prefiles', 1]);
            $this->assertSame($accepted ? $title : $opaque, DB::table('releases')->where('id', 1)->value('searchname'));
            $this->assertSame($accepted ? 7 : 0, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
            $this->assertSame($stored, DB::table('release_files')->where('releases_id', 1)->orderBy('name')->pluck('name')->all());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function sharedArchiveAdmissionCases(): iterable
    {
        foreach (['generic', 'generic preview', 'media', 'preview', 'attach', 'same-name candidate', 'same-name preview'] as $path) {
            yield $path.' incomplete' => [$path, 'incomplete', false];
            yield $path.' complete' => [$path, 'complete', true];
        }
        foreach (['generic', 'media'] as $path) {
            yield $path.' retained old filename' => [$path, 'replacement old', false];
            yield $path.' current replacement filename' => [$path, 'replacement current', true];
            yield $path.' identical decoded recompression' => [$path, 'recompressed', true];
        }
    }

    #[DataProvider('sharedArchiveAdmissionCases')]
    public function test_shared_rename_admission_requires_current_archive_scope_and_filename(string $path, string $proof, bool $accepted): void
    {
        $oldTitle = 'Show.S01E01.1080p.WEB.x264-GRP';
        $currentTitle = 'Show.S01E03.1080p.WEB.x265-GRP';
        $title = $proof === 'replacement current' ? $currentTitle : $oldTitle;
        $sameName = in_array($path, ['attach', 'same-name candidate', 'same-name preview'], true);
        $preview = in_array($path, ['preview', 'generic preview', 'same-name preview'], true);
        $this->prepareFullPar2NamingEvidence($title);
        $this->installFilenameSqlIdentity();
        DB::table('releases')->where('id', 1)->update(['searchname' => $sameName ? $title : 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);
        $dir = sys_get_temp_dir().'/'.uniqid('shared-archive-admission-', true);
        mkdir($dir);
        try {
            config(['nntmux_settings.path_to_nzbs' => $dir.'/nzbs/']);
            $nzb = new TestNzbService;
            $sources = new NzbSourceGuard($nzb);
            $evidence = new ReleaseArchiveNamingEvidence($sources);
            $this->app->instance(NzbSourceGuard::class, $sources);
            $this->app->instance(ReleaseArchiveNamingEvidence::class, $evidence);
            $release = Release::query()->findOrFail(1);
            $release->releases_id = $release->id;
            $release->filename = $title.'.mkv';
            $source = '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><file poster="fixture" date="1" subject="opaque.rar"><groups><group>alt.binaries.fixture</group></groups><segments><segment bytes="100" number="1">old-source</segment></segments></file></nzb>';
            $sourcePath = $nzb->getNzbPath($release->guid, createIfNotExist: true);
            $originalCompressed = gzencode($source, 1);
            file_put_contents($sourcePath, $originalCompressed);
            $digest = NzbSourceGuard::digest($source);
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => $oldTitle.'.mkv', 'size' => 100]);
            $this->assertTrue(DB::transaction(fn (): bool => $evidence->recordLocked($release, $digest,
                $proof === 'incomplete' ? 'incomplete' : 'complete_single', [$oldTitle.'.mkv'], $proof !== 'incomplete')));
            if (str_starts_with($proof, 'replacement') || $proof === 'recompressed') {
                $nextSource = $proof === 'recompressed' ? $source : str_replace('old-source', 'new-source', $source);
                $temporary = $dir.'/replacement.nzb.gz';
                $nextCompressed = gzencode($nextSource, 9);
                file_put_contents($temporary, $nextCompressed);
                $receipt = DB::transaction(fn () => $sources->install($release, $temporary, $sourcePath, $digest));
                $receipt->close();
                if ($proof === 'recompressed') {
                    $this->assertNotSame($originalCompressed, $nextCompressed);
                    $this->assertSame($digest, $sources->snapshot($release->guid)['digest']);
                } else {
                    DB::table('release_files')->insert(['releases_id' => 1, 'name' => $currentTitle.'.mkv', 'size' => 100]);
                    $this->assertTrue(DB::transaction(fn (): bool => $evidence->recordLocked($release,
                        NzbSourceGuard::digest($nextSource), 'complete_single', [$currentTitle.'.mkv'], true)));
                }
            }
            $before = (array) DB::table('releases')->where('id', 1)->first();
            $files = DB::table('release_files')->where('releases_id', 1)->orderBy('name')->pluck('name')->all();
            $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
            $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
            if ($path === 'generic') {
                $updates->updateRelease($release, $title, 'Fixture filename', true, 'Filenames, ', true, false);
                $result = $updates->matched;
            } elseif ($path === 'generic preview') {
                $updates->updateRelease($release, $title, 'Fixture filename', false, 'Filenames, ', true, false);
                $result = $updates->matched;
            } elseif ($path === 'media') {
                $result = $updates->updateMediaSnapshot($release, $title, Category::TV_HD);
            } elseif ($preview) {
                $result = (new NameFixingService($updates))->matchPreDbFiles($release, false, true, false) === 1;
            } elseif ($path === 'attach') {
                $result = $updates->attachPredbSnapshot($release, 7, $title, $title);
            } else {
                $result = $updates->updatePredbCandidate($release, $title, 7, 'fixture');
            }
            $this->assertSame($accepted, $result);
            $this->assertSame($accepted, $updates->matched);
            $after = (array) DB::table('releases')->where('id', 1)->first();
            $this->assertSame($files, DB::table('release_files')->where('releases_id', 1)->orderBy('name')->pluck('name')->all());
            if (! $accepted || $preview) {
                $this->assertSame($before, $after, 'Rejected or preview admission must not mutate release state.');
                $this->assertNull($updates->lastCommittedIdentity());
                Event::assertNotDispatched(ReleaseNameFixed::class);
            } else {
                $this->assertSame($title, $after['searchname']);
                $this->assertSame($sameName ? 7 : 0, (int) $after['predb_id']);
                $this->assertSame($title, $updates->lastCommittedIdentity()['after']['searchname'] ?? null);
            }
            if (! $accepted) {
                $this->assertSame(0, $updates->fixed);
                $this->assertSame(0, $updates->checked);
                $this->assertSame(0, (int) $after['predb_id']);
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function incompleteArchiveNamingCases(): array
    {
        return [
            'incomplete 7z episode' => ['7z', true],
            'incomplete 7z movie' => ['7z', false],
            'truncated rar episode' => ['truncated rar', true],
            'truncated rar movie' => ['truncated rar', false],
            'truncated rar followed by extras' => ['truncated rar with extras', true],
            'complete rar episode' => ['complete rar', true],
            'complete rar movie' => ['complete rar', false],
            'exact payload boundary episode' => ['payload boundary', true],
            'exact payload boundary movie' => ['payload boundary', false],
            'partial next header episode' => ['partial next header', true],
            'partial next header movie' => ['partial next header', false],
            'complete rar5 episode' => ['complete rar5', true],
            'complete rar5 movie' => ['complete rar5', false],
            'rar terminal requests another volume' => ['continuing rar', true],
        ];
    }

    #[DataProvider('sevenZipListings')]
    public function test_a_cut_off_7z_listing_leaves_the_password_status_unknown(string $script, int $passwordStatus, array $names = []): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->once()->with(1);

        $dir = sys_get_temp_dir().'/'.uniqid('7z-listing-', true);
        mkdir($dir);
        try {
            file_put_contents($dir.'/7z', "#!/bin/sh\n".$script."\n");
            chmod($dir.'/7z', 0755);
            $archiveService = new ArchiveExtractionService($this->makeConfig(['sevenZipPath' => $dir.'/7z']));
            $manager = $this->makeManager();
            $context = $this->sourceContext(Release::query()->findOrFail(1));
            $context->nzbHasCompressedFile = true;

            $archive = $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true);
            $result = $archiveService->listEncodedSevenZip($archive, null, $context, $dir.'/');
            $this->assertIsArray($result);
            foreach ($result['files'] as $file) {
                if ($context->releaseHasPassword) {
                    break;
                }
                $manager->addFileInfo($file, $context, '\\.(?:par2|sfv|nzb)');
            }
            $manager->finalizeRelease($context, true);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($passwordStatus, (int) $release->passwordstatus);
        $this->assertSame(0, (int) $release->haspreview);
        $this->assertNull($release->archive_retry_at);
        $this->assertGreaterThan(0, DB::table('release_files')->count());
        foreach ($names as $name) {
            $this->assertTrue(DB::table('release_files')->where('name', $name)->exists(), $name);
        }
    }

    /**
     * @return array<string, array{0: string, 1: int, 2?: list<string>}>
     */
    public static function sevenZipListings(): array
    {
        $plain = static fn (int $count, string $pad = ''): string => 'i=0; while [ $i -lt '.$count.' ]; do '
            .'printf \'Path = %d'.$pad.'.txt\\nSize = 0\\nEncrypted = -\\n\\n\' $i; i=$((i+1)); done; ';
        $encrypted = 'printf \'Path = Movie.2026.1080p.mkv\\nSize = 100\\nEncrypted = +\\n\\n\'; ';

        return [
            'encrypted entry past the entry cap' => [
                $plain(ArchiveExtractionService::SEVENZIP_LISTING_MAX_ENTRIES).$encrypted,
                -1,
            ],
            'encrypted entry past the output cap' => [
                $plain(300, str_repeat('a', 20000)).$encrypted,
                -1,
            ],
            'encrypted entry before the cap' => [
                $plain(5).$encrypted.$plain(2000),
                ReleaseBrowseService::PASSWD_RAR,
            ],
            'complete listing without encryption' => [
                $plain(5),
                ReleaseBrowseService::PASSWD_NONE,
            ],
            'CRLF listing of names containing byte 0x85' => [
                'printf \'Path = \303\205land.mkv\r\nSize = 10\r\nEncrypted = -\r\n\r\n\'; '
                    .'printf \'Path = Movie.\344\271\205.mkv\r\nSize = 13\r\nEncrypted = -\r\n\r\n\'; ',
                ReleaseBrowseService::PASSWD_NONE,
                ['Åland.mkv', 'Movie.久.mkv'],
            ],
            'encrypted entry behind a control character' => [
                $plain(5).'printf \'Path = Movie\001.mkv\nSize = 100\nEncrypted = +\n\n\'; ',
                -1,
            ],
            'encrypted entry behind a duplicate property' => [
                $plain(5).'printf \'Path = a.txt\nPath = Movie.mkv\nSize = 100\nEncrypted = +\n\n\'; ',
                -1,
            ],
        ];
    }

    #[DataProvider('sevenZipListingsBesideMedia')]
    public function test_plain_media_beside_a_7z_listed_by_7_zip_follows_the_listing(string $title, int $entries, int $passwordStatus): void
    {
        DB::table('releases')->insert([...$this->releaseRow(), 'nfostatus' => 1]);
        Search::shouldReceive('updateRelease')->once()->with(1);
        $this->freezeSecond();

        $tmpPath = sys_get_temp_dir().'/media-7z-'.uniqid().'/';
        File::ensureDirectoryExists($tmpPath);
        $client = $tmpPath.'7z';
        file_put_contents($client, "#!/bin/sh\ni=0; while [ \$i -lt {$entries} ]; do printf 'Path = %d.txt\\nSize = 0\\nEncrypted = -\\n\\n' \$i; i=\$((i+1)); done\n");
        chmod($client, 0755);

        $config = $this->makeConfig(['processPasswords' => true, 'sevenZipPath' => $client]);
        $media = ['"Movie.2026.part01.rar" yEnc (1/50)', '<v1>', str_pad("\x1A\x45\xDF\xA3\x01\x00\x00\x00", 512, "\x5A")];
        $sevenZip = [$title, '<x1>', $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true)];
        $parser = Mockery::mock(NzbContentParser::class);
        $parser->shouldReceive('parseNzb')->once()->with('guid-1')->andReturn([
            'error' => null,
            'sourceDigest' => $this->fixtureSourceSnapshot(Release::query()->findOrFail(1))['digest'],
            'contents' => array_map(static fn (array $file): array => ['title' => $file[0], 'segments' => [$file[1]]], [$media, $sevenZip]),
        ]);
        $download = Mockery::mock(UsenetDownloadService::class);
        $download->shouldReceive('beginReleaseScope')->once();
        $download->shouldReceive('finishReleaseScope')->once()->andReturn(new DownloadMetrics);
        foreach ([$media, $sevenZip] as [$title, $messageId, $data]) {
            $download->shouldReceive('download')->once()
                ->with(DownloadKind::Compressed, [$messageId], '', 1, $title)
                ->andReturn(['success' => true, 'data' => $data, 'groupUnavailable' => false, 'error' => null]);
        }
        $workspace = Mockery::mock(TempWorkspaceService::class);
        $workspace->shouldReceive('createReleaseTempFolder')->once()->andReturn($tmpPath);
        $workspace->shouldReceive('listFiles')->andReturn([]);
        $workspace->shouldReceive('clearDirectory')->once()->with($tmpPath, false)
            ->andReturnUsing(static fn () => File::deleteDirectory($tmpPath));
        $processor = new ReleaseProcessor(
            $config,
            $parser,
            new AdditionalWorkPlanner($config),
            new ArchiveExtractionService($config),
            Mockery::mock(MediaExtractionService::class)->shouldIgnoreMissing(false),
            $download,
            $this->makeManager(),
            Mockery::mock(ReleaseFilesArchiveFallback::class)->shouldIgnoreMissing(),
            $workspace,
            Mockery::mock(ConsoleOutputService::class)->shouldIgnoreMissing(),
        );

        $processor->process($this->sourceContext(Release::query()->findOrFail(1)), '/tmp/');

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($passwordStatus, (int) $release->passwordstatus);
        $this->assertSame(0, (int) $release->haspreview);
        $this->assertNull($release->archive_retry_at);
        $this->assertGreaterThan(0, DB::table('release_files')->count());
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function sevenZipListingsBesideMedia(): array
    {
        return [
            'complete listing' => ['"Extras.2026.7z" yEnc (1/1)', 3, ReleaseBrowseService::PASSWD_NONE],
            'cut-off listing' => ['"Extras.2026.7z" yEnc (1/1)', ArchiveExtractionService::SEVENZIP_LISTING_MAX_ENTRIES + 1, -1],
            'complete listing of a same-stem 7z' => ['Movie.2026.7z yEnc (1/1)', 3, ReleaseBrowseService::PASSWD_NONE],
            'cut-off listing of a same-stem 7z' => ['"Movie.2026.7z" yEnc (1/1)', ArchiveExtractionService::SEVENZIP_LISTING_MAX_ENTRIES + 1, -1],
        ];
    }

    /**
     * @param  list<string>  $listings  7-Zip client output for each 7z archive of the release, listed in order
     */
    #[DataProvider('sevenZipListingsWithoutReadableRecords')]
    public function test_a_7z_listing_without_one_readable_record_still_leaves_the_password_status_unknown(
        array $listings,
        bool $hasReleaseFile,
        int $passwordStatus,
        bool $retried,
    ): void {
        DB::table('releases')->insert($this->releaseRow());
        if ($hasReleaseFile) {
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'Extras/readme.txt', 'size' => 10]);
        }
        Search::shouldReceive('updateRelease')->once()->with(1);
        config(['nntmux.archive_retry_delay' => 86400]);
        $this->freezeSecond();

        $dir = sys_get_temp_dir().'/'.uniqid('7z-listing-', true);
        mkdir($dir);
        try {
            $manager = $this->makeManager();
            $context = $this->sourceContext(Release::query()->findOrFail(1));
            $context->nzbHasCompressedFile = true;
            $context->archiveInspectionAttempted = true;
            $archive = $this->sevenZip('ignored.mkv', str_repeat('x', 100), compressedHeader: true);

            foreach ($listings as $index => $listing) {
                $client = $dir.'/7z-'.$index;
                file_put_contents($client, "#!/bin/sh\n".$listing."\n");
                chmod($client, 0755);
                $result = (new ArchiveExtractionService($this->makeConfig(['sevenZipPath' => $client])))
                    ->listEncodedSevenZip($archive, null, $context, $dir.'/');
                foreach ($result['files'] ?? [] as $file) {
                    if ($context->releaseHasPassword) {
                        break;
                    }
                    $manager->addFileInfo($file, $context, '\\.(?:par2|sfv|nzb)');
                }
            }
            $manager->finalizeRelease($context, true);
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame($passwordStatus, (int) $release->passwordstatus);
        $this->assertSame($retried ? -1 : 0, (int) $release->haspreview);
        $this->assertSame($retried ? now()->addDay()->toDateTimeString() : null, $release->archive_retry_at);
    }

    /**
     * @return array<string, array{list<string>, bool, int, bool}>
     */
    public static function sevenZipListingsWithoutReadableRecords(): array
    {
        $controlCharacter = 'printf \'Path = Movie\001.mkv\nSize = 100\nEncrypted = +\n\n\'';
        $duplicateProperty = 'printf \'Path = a.txt\nPath = Movie.mkv\nSize = 100\nEncrypted = +\n\n\'';
        $plain = 'printf \'Path = Extras/info.txt\nSize = 4\nEncrypted = -\n\n\'';
        $encrypted = 'printf \'Path = Movie.2026.1080p.mkv\nSize = 100\nEncrypted = +\n\n\'';

        return [
            'control character beside a stored file' => [[$controlCharacter], true, -1, false],
            'duplicate property beside a stored file' => [[$duplicateProperty], true, -1, false],
            'control character, then a complete listing' => [[$controlCharacter, $plain], false, -1, false],
            'duplicate property, then a complete listing' => [[$duplicateProperty, $plain], false, -1, false],
            'complete listing, then a control character' => [[$plain, $controlCharacter], false, -1, false],
            'control character, then an encrypted entry' => [[$controlCharacter, $encrypted], false, ReleaseBrowseService::PASSWD_RAR, false],
            'control character and no file at all' => [[$controlCharacter], false, -1, true],
            'empty listing beside a stored file' => [[''], true, ReleaseBrowseService::PASSWD_NONE, false],
        ];
    }

    public function test_invalid_release_file_sizes_are_rejected(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Log::shouldReceive('warning')
            ->times(4)
            ->with('Skipping release file with invalid size metadata.', Mockery::type('array'));

        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        $invalidSizes = [-1, INF, PHP_INT_MAX + 1.0, 'not-numeric'];

        foreach ($invalidSizes as $index => $invalidSize) {
            $this->assertFalse($manager->addFileInfo([
                'name' => "Invalid.Size.{$index}.mkv",
                'size' => $invalidSize,
            ], $context, '\\.(?:par2|sfv|nzb)'));
        }

        $this->assertSame(0, $context->totalFileInfo);
        $this->assertSame(0, $context->addedFileInfo);
    }

    public function test_finalize_recognizes_webp_preview_and_sample_without_moving_them(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->once()->with(1);

        $imageService = new ReleaseImageService;
        $preview = $imageService->imgSavePath.'guid-1_thumb.webp';
        $sample = $imageService->jpgSavePath.'guid-1_thumb.webp';
        File::ensureDirectoryExists(dirname($preview));
        File::ensureDirectoryExists(dirname($sample));
        File::put($preview, 'preview');
        File::put($sample, 'sample');

        try {
            $manager = $this->makeManagerWithImageService($imageService);
            $context = $this->sourceContext(Release::query()->findOrFail(1));

            $manager->finalizeRelease($context, false);

            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('haspreview'));
            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('jpgstatus'));
        } finally {
            File::delete([$preview, $sample]);
        }
    }

    public function test_finalization_without_a_source_snapshot_preserves_inventory_status_and_publication(): void
    {
        DB::table('releases')->insert($this->releaseRow());
        Search::shouldReceive('updateRelease')->never();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $manager = $this->makeManager();
        $before = (array) DB::table('releases')->where('id', 1)->first();
        $this->assertTrue($manager->addFileInfo(['name' => 'Movie.2026.mkv', 'size' => 100], $context, '\\.(?:par2|sfv|nzb)'));

        $manager->finalizeRelease($context, false);

        $this->assertTrue($context->fullPar2EvidenceDeferred);
        $this->assertSame(0, DB::table('release_files')->count());
        $this->assertSame(0, DB::table('release_archive_naming_evidence')->count());
        $this->assertSame($before, (array) DB::table('releases')->where('id', 1)->first());
    }

    private function sourceContext(object $release): ReleaseProcessingContext
    {
        $snapshot = $this->fixtureSourceSnapshot($release);
        $context = new ReleaseProcessingContext($release);
        $context->nzbSourceDigest = $snapshot['digest'];

        return $context;
    }

    /** @return array{guid: string, path: string, contents: string, digest: string} */
    private function fixtureSourceSnapshot(object $release): array
    {
        $nzb = new TestNzbService;
        $path = $nzb->getNzbPath($release->guid, createIfNotExist: true);
        if (! File::isFile($path)) {
            $xml = '<nzb><file subject="fixture.bin yEnc (1/1)"><groups><group>alt.fixture</group></groups>'
                .'<segments><segment bytes="1" number="1">fixture-source@article</segment></segments></file></nzb>';
            File::put($path, gzencode($xml));
        }
        $sources = new NzbSourceGuard($nzb);
        $this->app->instance(NzbSourceGuard::class, $sources);
        $snapshot = $sources->snapshot($release->guid);
        $this->assertNotNull($snapshot);

        return $snapshot;
    }

    private function makeManager(?NameFixingService $nameFixing = null): ReleaseFileManager
    {
        return $this->makeManagerWithImageService(new ReleaseImageService, $nameFixing);
    }

    /**
     * @param  array<string, mixed>  $configOverrides
     */
    private function makeManagerWithImageService(
        ReleaseImageService $imageService,
        ?NameFixingService $nameFixing = null,
        array $configOverrides = [],
    ): ReleaseFileManager {
        return new ReleaseFileManager(
            $this->makeConfig($configOverrides),
            $imageService,
            new NfoService,
            new TestNzbService,
            $nameFixing ?? new CountingNameFixingService
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseRow(): array
    {
        return [
            'id' => 1,
            'guid' => 'guid-1',
            'name' => 'Example',
            'searchname' => 'Example',
            'size' => 1024,
            'groups_id' => 1,
            'nfostatus' => -1,
            'categories_id' => 10,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'rarinnerfilecount' => 0,
            'pp_timeout_count' => 0,
            'additional_pp_claimed_at' => now(),
            'additional_pp_claim_token' => 'token',
        ];
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {

        Schema::dropIfExists('release_archive_naming_evidence');
        Schema::dropIfExists('par_hashes');
        Schema::dropIfExists('release_files');
        Schema::dropIfExists('releases');

        Schema::create('releases', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
            $table->string('name')->default('');
            $table->string('searchname')->default('');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('groups_id')->default(0);
            $table->integer('nfostatus')->default(0);
            $table->integer('categories_id')->default(10);
            $table->integer('passwordstatus')->default(-1);
            $table->integer('haspreview')->default(-1);
            $table->integer('jpgstatus')->default(0);
            $table->integer('videostatus')->default(0);
            $table->integer('nzbstatus')->default(1);
            $table->integer('rarinnerfilecount')->default(0);
            $table->integer('pp_timeout_count')->default(0);
            $table->integer('proc_files')->default(0);
            $table->integer('predb_id')->default(0);
            $table->integer('isrenamed')->default(0);
            $table->integer('tv_lookup_attempts')->default(0);
            $table->timestamp('additional_pp_claimed_at')->nullable();
            $table->string('additional_pp_claim_token', 64)->nullable();
            $table->timestamp('archive_retry_at')->nullable();
            $table->timestamp('tv_lookup_retry_at')->nullable();
        });

        Schema::create('release_files', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('crc32')->default('');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('passworded')->default(false);
            $table->primary(['releases_id', 'name']);
        });

        Schema::create('par_hashes', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });
        (require database_path('migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
    }
}

class CountingNameFixingService extends NameFixingService
{
    public int $matchPreDbFilesCalls = 0;

    public function matchPreDbFileNames(object $release, array $files, bool $echo, bool $nameStatus, bool $show, ?array $persistedNames = null): int
    {
        $this->matchPreDbFilesCalls++;

        return 0;
    }
}

class TestNzbService extends NzbService
{
    public function __construct()
    {
        parent::__construct(new CollectionCleanupService);
    }
}
