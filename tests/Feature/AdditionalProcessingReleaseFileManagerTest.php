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
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbParserService;
use App\Services\Nzb\NzbService;
use App\Services\ReleaseImageService;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\TempWorkspaceService;
use dariusiii\rarinfo\Par2Info;
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

    /** @var list<string> */
    private array $par2HashFixtureFiles = [];

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-release-file-manager-test.sqlite';

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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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

    private function makeFullPar2NamingManager(ReleaseSearchSyncCoordinator $sync): ReleaseFileManager
    {
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);

        return new ReleaseFileManager(
            $this->makeConfig(['renamePar2' => true, 'echoCLI' => true, 'addPAR2Files' => true]),
            new ReleaseImageService,
            new NfoService,
            new TestNzbService,
            new NameFixingService($updater),
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

    public function test_release_file_rows_are_deduped_and_flushed_once_at_finalize(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $nameFixing = new CountingNameFixingService;

        $manager = $this->makeManager($nameFixing);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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
        Search::shouldReceive('searchPredb')->andReturn([]);
        Search::shouldReceive('updateRelease')->twice()->with(1);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));
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
        Search::shouldReceive('searchPredb')->andReturn([]);
        Search::shouldReceive('updateRelease')->twice()->with(1);
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class));
        $fixer = new NameFixingService($updater);
        $queries = new NameFixingQueryService;
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_FILES, 0, 1));
        $manager = $this->makeManager($fixer);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $title = 'Example.Movie.2026.1080p.BluRay.x264-TARGET';
        self::assertTrue($manager->addFileInfo(['name' => $title.'.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        $inserted = false;
        Search::shouldReceive('updateRelease')->twice()->with(1);
        Search::shouldReceive('searchPredb')->andReturnUsing(function () use (&$inserted, $manager, $context): array {
            self::assertSame(0, DB::transactionLevel(), 'Filename discovery must not hold the completion lock.');
            if (! $inserted) {
                $inserted = true;
                $manager->finalizeRelease($context, false);
            }

            return [];
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturn([]);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));
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
        Search::shouldReceive('searchPredb')->once()->andReturn([]);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturnUsing(function (): array {
            DB::table('releases')->where('id', 1)->update(['categories_id' => Category::MOVIE_HD]);

            return [];
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturn([]);
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturnUsing(function () use ($currentPredb): array {
            DB::table('releases')->where('id', 1)->update(['predb_id' => $currentPredb]);

            return [];
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturnUsing(function () use ($column, $value): array {
            DB::table('releases')->where('id', 1)->update([$column => $value]);

            return [];
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

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
        Search::shouldReceive('searchPredb')->once()->andReturnUsing(function () use ($name): array {
            DB::table('release_files')->where('releases_id', 1)->update(['name' => strtoupper($name)]);

            return [];
        });
        $fixer = new NameFixingService(new ReleaseUpdateService($this->createStub(CategorizationService::class)));

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
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
    }

    public function test_finalization_flushes_rows_and_synchronizes_search_immediately(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Search::shouldReceive('updateRelease')->once()->with(1);

        $manager = $this->makeManagerWithImageService(new ReleaseImageService);
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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

        $firstContext = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $this->assertTrue($manager->processPar2File('first.par2', $firstContext, $par2));
        $this->assertTrue($updates->matched);
        $this->assertSame(7, DB::table('releases')->where('id', 1)->value('predb_id'));

        $secondContext = new ReleaseProcessingContext(Release::query()->findOrFail(2));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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
            $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
        $context->nzbHasCompressedFile = true;
        $context->archiveInspectionAttempted = true;
        $manager->finalizeRelease($context, true);

        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(-1, (int) $release->passwordstatus);
        $this->assertSame(-1, (int) $release->haspreview);
        $this->assertSame(now()->addHour()->toDateTimeString(), (string) $release->archive_retry_at);
        $this->assertNull($release->additional_pp_claimed_at);

        $this->travel(2)->hours();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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

        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

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

    public function test_invalid_release_file_sizes_are_rejected(): void
    {
        DB::table('releases')->insert($this->releaseRow());

        Log::shouldReceive('warning')
            ->times(4)
            ->with('Skipping release file with invalid size metadata.', Mockery::type('array'));

        $manager = $this->makeManager();
        $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));
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
            $context = new ReleaseProcessingContext(Release::query()->findOrFail(1));

            $manager->finalizeRelease($context, false);

            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('haspreview'));
            $this->assertSame(1, DB::table('releases')->where('id', 1)->value('jpgstatus'));
        } finally {
            File::delete([$preview, $sample]);
        }
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
    }
}

class CountingNameFixingService extends NameFixingService
{
    public int $matchPreDbFilesCalls = 0;

    public function matchPreDbFiles(object $release, bool $echo, bool $nameStatus, bool $show): int
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
