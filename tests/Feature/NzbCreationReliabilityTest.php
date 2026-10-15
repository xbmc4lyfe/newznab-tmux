<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Search;
use App\Jobs\CollectReleaseFingerprintMetadata;
use App\Models\Release;
use App\Services\AdditionalProcessing\AdditionalCandidateQuery;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionCleanupService;
use App\Services\NfoService;
use App\Services\Nzb\NzbArticleFingerprint;
use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Services\Nzb\NzbCreationCandidateQuery;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\RustArticleFingerprintWorker;
use App\Services\ReleaseCreationService;
use App\Services\ReleaseImageService;
use App\Services\ReleaseProcessingService;
use App\Services\Releases\ReleaseManagementService;
use App\Services\Search\Contracts\BulkReleaseIndexUpdater;
use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\SearchService;
use App\Support\Data\NzbCreationResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

class NzbCreationReliabilityTest extends TestCase
{
    private string $databasePath = '';

    private string $tempNzbPath = '';

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = tempnam(sys_get_temp_dir(), 'nntmux-reliability-');

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
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('.nntmux-validation-no-env');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['release_verification.required' => false]);

        $this->tempNzbPath = sys_get_temp_dir().'/nntmux-nzb-reliability-'.uniqid('', true);
        mkdir($this->tempNzbPath, 0777, true);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databasePath]);
        config(['nntmux_settings.path_to_nzbs' => $this->tempNzbPath]);
        config(['logging.default' => 'single', 'logging.channels.single.path' => $this->tempNzbPath.'/laravel.log',
            'logging.channels.nzb_creation.path' => $this->tempNzbPath.'/nzb_creation.log']);
        DB::purge();
        DB::reconnect();
        DB::connection()->getPdo()->sqliteCreateFunction('UNIX_TIMESTAMP', static fn (?string $value): int => strtotime((string) $value));

        $this->createSchema();
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->bindNativeFingerprint();
    }

    protected function tearDown(): void
    {
        NzbCreationCandidateQuery::flushCapabilityCache();
        $this->deleteDirectory($this->tempNzbPath);
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_published_nzb_creation_defers_without_touching_the_artifact_or_pack_epoch(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $evidence = $this->packEnvelope(1, str_repeat('a', 36));
        DB::table('releases')->where('id', 1)->update(['nzbstatus' => NzbService::NZB_ADDED, 'additional_pack_evidence' => $evidence]);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'published winner');
        $before = (array) DB::table('releases')->first();
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $result = $nzb->createNzbForRelease($release);

        self::assertTrue($result->isDeferred());
        self::assertSame('published winner', file_get_contents($path));
        self::assertSame($before, (array) DB::table('releases')->first());
        self::assertSame(1, DB::table('collections')->count());
        Bus::assertNothingDispatched();
    }

    public function test_nzb_creation_rechecks_publication_and_claim_before_the_final_swap(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            protected function openGzipFile(string $path): mixed
            {
                DB::table('releases')->where('id', 1)->update(['nzbstatus' => NzbService::NZB_ADDED,
                    'nzb_creation_claim_token' => 'new-owner', 'nzb_creation_claimed_at' => now()]);

                return parent::openGzipFile($path);
            }
        };
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'published by the other owner');
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $result = $nzb->createNzbForRelease($release);

        self::assertTrue($result->isDeferred());
        self::assertSame('published by the other owner', file_get_contents($path));
        self::assertSame('new-owner', DB::table('releases')->value('nzb_creation_claim_token'));
        self::assertSame(1, DB::table('collections')->count());
        self::assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
    }

    /** @return array<string, array{bool}> */
    public static function passwordCheckModes(): array
    {
        return ['password checks enabled' => [true], 'password checks disabled' => [false]];
    }

    #[DataProvider('passwordCheckModes')]
    public function test_none_publication_rotates_its_epoch_and_retires_only_its_old_derived_evidence(bool $checkPasswords): void
    {
        config(['nntmux_settings.check_passworded_rars' => $checkPasswords]);
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $before = $this->packEnvelope(1, str_repeat('a', 36));
        $this->insertRelease(2, 'b');
        DB::table('releases')->whereIn('id', [1, 2])->update($this->completedProcessingState());
        DB::table('releases')->where('id', 1)->update(['additional_pack_evidence' => $before]);
        $otherBefore = (array) DB::table('releases')->where('id', 2)->first();
        foreach ($this->derivedEvidenceTables() as $table) {
            DB::table($table)->insert([['releases_id' => 1], ['releases_id' => 2]]);
        }
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $result = $nzb->createNzbForRelease($release);

        self::assertTrue($result->success);
        $this->assertProcessingState(1, reset: true, checkPasswords: $checkPasswords);
        self::assertSame($otherBefore, (array) DB::table('releases')->where('id', 2)->first());
        $after = json_decode((string) DB::table('releases')->value('additional_pack_evidence'), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($after['state']);
        self::assertNotSame(str_repeat('a', 64), $after['epoch']);
        foreach ($this->derivedEvidenceTables() as $table) {
            self::assertSame([2], DB::table($table)->pluck('releases_id')->all(), $table);
        }
    }

    public function test_nzb_database_failure_after_install_keeps_admission_closed_and_the_old_epoch(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $before = $this->packEnvelope(1, str_repeat('a', 36));
        $this->insertRelease(2, 'b');
        DB::table('releases')->whereIn('id', [1, 2])->update($this->completedProcessingState());
        DB::table('releases')->where('id', 1)->update(['additional_pack_evidence' => $before]);
        $otherBefore = (array) DB::table('releases')->where('id', 2)->first();
        DB::unprepared("CREATE TRIGGER reject_publication BEFORE UPDATE OF nzbstatus ON releases WHEN NEW.nzbstatus = 1 BEGIN SELECT RAISE(ABORT, 'forced publication failure'); END");
        foreach ($this->derivedEvidenceTables() as $table) {
            DB::table($table)->insert([['releases_id' => 1], ['releases_id' => 2]]);
        }
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $result = $nzb->createNzbForRelease($release);

        self::assertFalse($result->success);
        $this->assertProcessingState(1, reset: false);
        self::assertSame($otherBefore, (array) DB::table('releases')->where('id', 2)->first());
        self::assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->value('nzbstatus'));
        self::assertSame($before, DB::table('releases')->value('additional_pack_evidence'));
        self::assertSame(1, DB::table('collections')->count());
        self::assertStringContainsString('part01@example.test', $nzb->readNzbContents($release->guid));
        foreach ($this->derivedEvidenceTables() as $table) {
            self::assertSame([1, 2], DB::table($table)->orderBy('releases_id')->pluck('releases_id')->all(), $table);
        }
        Bus::assertNothingDispatched();
    }

    public function test_lost_nzb_admission_never_deletes_a_published_winner_at_the_retry_limit(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a', attempts: 100);
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new class(app(CollectionCleanupService::class)) extends NzbService
        {
            public function createNzbForRelease(Release $release): NzbCreationResult
            {
                DB::table('releases')->where('id', $release->id)->update(['nzbstatus' => NzbService::NZB_ADDED]);

                return parent::createNzbForRelease($release);
            }
        };
        $service = (new ReleaseProcessingService(nzb: $nzb, releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class)))->setEchoCLI(false);

        self::assertSame(0, $service->createNZBs(null));
        self::assertSame(1, DB::table('releases')->count());
        self::assertSame(NzbService::NZB_ADDED, (int) DB::table('releases')->value('nzbstatus'));
        self::assertSame(100, (int) DB::table('release_nzb_creation_failures')->value('attempts'));
        self::assertSame(1, DB::table('collections')->count());
    }

    /** @return array<string, array{0: string}> */
    public static function publicationObservationChanges(): array
    {
        return [
            'exact GUID changed' => ['guid'],
            'name changed' => ['name'],
            'search name changed' => ['searchname'],
            'content epoch changed' => ['additional_pack_evidence'],
            'creation owner changed' => ['nzb_creation_claim_token'],
            'creation timestamp changed' => ['nzb_creation_claimed_at'],
        ];
    }

    #[DataProvider('publicationObservationChanges')]
    public function test_none_creation_defers_when_its_original_publication_observation_changes(string $column): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a', claimedAt: now());
        $this->insertWritableCbp(200, 2000, 1);
        $evidence = $this->packEnvelope(1, str_repeat('a', 36));
        DB::table('releases')->where('id', 1)->update(['additional_pack_evidence' => $evidence]);
        foreach ($this->derivedEvidenceTables() as $table) {
            DB::table($table)->insert([['releases_id' => 1], ['releases_id' => 2]]);
        }
        $value = match ($column) {
            'guid' => str_repeat('b', 36),
            'name', 'searchname' => 'Changed.Release',
            'additional_pack_evidence' => str_replace(str_repeat('a', 64), str_repeat('b', 64), $evidence),
            'nzb_creation_claim_token' => 'new-owner',
            'nzb_creation_claimed_at' => now()->addSecond()->format('Y-m-d H:i:s'),
            default => throw new \InvalidArgumentException('Unknown publication observation column.'),
        };
        $nzb = new class(app(CollectionCleanupService::class), $column, $value) extends NzbService
        {
            public function __construct(CollectionCleanupService $cleanup, private readonly string $column, private readonly string $value)
            {
                parent::__construct($cleanup);
            }

            protected function openGzipFile(string $path): mixed
            {
                DB::table('releases')->where('id', 1)->update([$this->column => $this->value]);

                return parent::openGzipFile($path);
            }
        };
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'unpublished artifact');
        $before = (array) DB::table('releases')->first();
        $before[$column] = $value;
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $result = $nzb->createNzbForRelease($release);

        self::assertTrue($result->isDeferred());
        self::assertSame('unpublished artifact', file_get_contents($path));
        self::assertSame($before, (array) DB::table('releases')->first());
        foreach ($this->derivedEvidenceTables() as $table) {
            self::assertSame([1, 2], DB::table($table)->orderBy('releases_id')->pluck('releases_id')->all(), $table);
        }
        self::assertSame(1, DB::table('collections')->count());
        self::assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
        Bus::assertNothingDispatched();
    }

    public function test_expired_unchanged_creation_claim_defers_without_changing_custody(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a', claimedAt: now());
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'unpublished artifact');
        $before = (array) DB::table('releases')->first();
        $release = Release::query()->findOrFail(1);
        $this->travel(1)->days();

        try {
            $result = $nzb->createNzbForRelease($release);

            self::assertTrue($result->isDeferred());
            self::assertSame($before, (array) DB::table('releases')->first());
            self::assertSame('unpublished artifact', file_get_contents($path));
            self::assertSame(1, DB::table('collections')->count());
            Bus::assertNothingDispatched();
        } finally {
            $this->travelBack();
        }
    }

    public function test_missing_creation_projection_defers_without_recapturing_the_observation(): void
    {
        Bus::fake();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $before = (array) DB::table('releases')->first();
        $release = Release::query()->firstOrFail(['id', 'guid', 'name', 'nzbstatus']);

        $result = (new NzbService(app(CollectionCleanupService::class)))->createNzbForRelease($release);

        self::assertTrue($result->isDeferred());
        self::assertSame($before, (array) DB::table('releases')->first());
        self::assertSame(1, DB::table('collections')->count());
        Bus::assertNothingDispatched();
    }

    public function test_claimed_none_creation_carries_the_publication_observation_through_the_coordinator(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $service = (new ReleaseProcessingService(nzb: $nzb, releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class)))->setEchoCLI(false);

        self::assertSame(1, $service->createNZBs(null));

        $published = DB::table('releases')->first();
        self::assertSame(NzbService::NZB_ADDED, (int) $published->nzbstatus);
        self::assertNull($published->nzb_creation_claim_token);
        self::assertNull($published->nzb_creation_claimed_at);
        $evidence = json_decode($published->additional_pack_evidence, true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($evidence['state']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['epoch']);
        self::assertStringContainsString('part01@example.test', $nzb->readNzbContents($published->guid));
        self::assertSame(0, DB::table('collections')->count());
        Bus::assertDispatchedTimes(CollectReleaseFingerprintMetadata::class, 1);
    }

    private function packEnvelope(int $id, string $guid): string
    {
        return json_encode(['v' => 1, 'identity_sha256' => hash('sha256', json_encode([(string) $id, $guid], JSON_THROW_ON_ERROR)),
            'epoch' => str_repeat('a', 64), 'state' => 'confirmed'], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: string, 1: int, 2?: bool}> */
    public static function failedCreationWinnerRaces(): array
    {
        return [
            'stream failure at retry limit' => ['stream', 100],
            'stream failure below retry limit' => ['stream', 0],
            'invalid CBP after initial admission' => ['cbp', 100],
            'caller exception at retry limit' => ['exception', 100],
            'caller exception below retry limit' => ['exception', 0],
            'epoch changed at retry limit with unchanged claim' => ['stream', 100, false],
            'epoch changed below retry limit with unchanged claim' => ['stream', 0, false],
        ];
    }

    #[DataProvider('failedCreationWinnerRaces')]
    public function test_failed_creation_accounting_preserves_a_winner_published_after_initial_admission(string $mode, int $attempts, bool $publishAdded = true): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        Search::shouldReceive('deleteRelease')->andReturnNull();
        Log::partialMock();
        Log::shouldReceive('channel')->andReturn(new RecordingNzbCreationLogger);
        $this->insertRelease(1, 'a', attempts: $attempts);
        $this->insertWritableCbp(200, 2000, 1);
        if ($mode === 'cbp') {
            DB::table('parts')->update(['partnumber' => null]);
        }
        foreach ($this->derivedEvidenceTables() as $table) {
            DB::table($table)->insert([['releases_id' => 1], ['releases_id' => 2]]);
        }
        $path = (new NzbService(app(CollectionCleanupService::class)))->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'unpublished artifact');
        $winner = null;
        $publish = function () use (&$winner, $path, $publishAdded): void {
            $evidence = str_replace(str_repeat('a', 64), str_repeat('b', 64), $this->packEnvelope(1, str_repeat('a', 36)));
            $updates = ['additional_pack_evidence' => $evidence];
            if ($publishAdded) {
                $updates += ['nzbstatus' => NzbService::NZB_ADDED, 'nzb_creation_claim_token' => 'winner-owner',
                    'nzb_creation_claimed_at' => now()->format('Y-m-d H:i:s')];
            }
            DB::table('releases')->where('id', 1)->update($updates);
            file_put_contents($path, 'published winner');
            $winner = ['release' => (array) DB::table('releases')->first(),
                'failure' => (array) DB::table('release_nzb_creation_failures')->first()];
        };
        if ($mode === 'cbp') {
            $published = false;
            DB::listen(static function (QueryExecuted $event) use ($publish, &$published): void {
                if (! $published && str_contains($event->sql, 'UNIX_TIMESTAMP(collections.date)')) {
                    $published = true;
                    $publish();
                }
            });
        }
        $nzb = new class(app(CollectionCleanupService::class), $publish, $mode) extends NzbService
        {
            public function __construct(CollectionCleanupService $cleanup, private readonly \Closure $publish, private readonly string $mode)
            {
                parent::__construct($cleanup);
            }

            public function createNzbForRelease(Release $release): NzbCreationResult
            {
                $result = parent::createNzbForRelease($release);
                if ($this->mode === 'exception') {
                    throw new \RuntimeException('Forced caller failure after the writer lost admission.');
                }

                return $result;
            }

            protected function openGzipFile(string $path): mixed
            {
                ($this->publish)();

                return false;
            }
        };
        $images = new ReleaseImageService;
        $images->audSavePath = $images->imgSavePath = $images->jpgSavePath = $images->vidSavePath = $this->tempNzbPath.'/images/';
        mkdir($images->imgSavePath);
        $preview = $images->imgSavePath.str_repeat('a', 36).'_thumb.jpg';
        file_put_contents($preview, 'winner preview');
        $service = (new ReleaseProcessingService(nzb: $nzb, releaseManagement: new ReleaseManagementService,
            releaseImage: $images, collectionCleanupService: app(CollectionCleanupService::class)))->setEchoCLI(false);

        self::assertSame(0, $service->createNZBs(null));

        self::assertNotNull($winner, 'The competing publication must occur after initial writer admission.');
        self::assertSame(1, DB::table('releases')->count());
        self::assertSame($winner['release'], (array) DB::table('releases')->first());
        self::assertSame($winner['failure'], (array) DB::table('release_nzb_creation_failures')->first());
        self::assertFileExists($path);
        self::assertSame('published winner', file_get_contents($path));
        self::assertSame('winner preview', file_get_contents($preview));
        foreach (['collections', 'binaries', 'parts'] as $table) {
            self::assertSame(1, DB::table($table)->count(), $table);
        }
        foreach ($this->derivedEvidenceTables() as $table) {
            self::assertSame([1, 2], DB::table($table)->orderBy('releases_id')->pluck('releases_id')->all(), $table);
        }
        Bus::assertNothingDispatched();
    }

    /** @return array<string, array{0: bool}> */
    public static function finalClaimChanges(): array
    {
        return ['same token renewed timestamp' => [false], 'unchanged expired claim' => [true]];
    }

    #[DataProvider('finalClaimChanges')]
    public function test_coordinator_claim_completion_preserves_a_changed_or_expired_owner(bool $expire): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $expected = null;
        $change = function () use ($expire, &$expected): void {
            if ($expire) {
                $this->travel(1)->days();
            } else {
                DB::table('releases')->where('id', 1)->update(['nzb_creation_claimed_at' => now()->addSecond()->format('Y-m-d H:i:s')]);
            }
            $expected = (array) DB::table('releases')->first();
        };
        $nzb = new class(app(CollectionCleanupService::class), $change) extends NzbService
        {
            public function __construct(CollectionCleanupService $cleanup, private readonly \Closure $change)
            {
                parent::__construct($cleanup);
            }

            protected function openGzipFile(string $path): mixed
            {
                ($this->change)();

                return parent::openGzipFile($path);
            }
        };
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'unpublished artifact');
        $service = (new ReleaseProcessingService(nzb: $nzb, releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class)))->setEchoCLI(false);

        try {
            self::assertSame(0, $service->createNZBs(null));
            self::assertNotNull($expected);
            self::assertSame($expected, (array) DB::table('releases')->first());
            self::assertSame(0, DB::table('release_nzb_creation_failures')->count());
            self::assertSame('unpublished artifact', file_get_contents($path));
            self::assertSame(1, DB::table('collections')->count());
            Bus::assertNothingDispatched();
        } finally {
            $this->travelBack();
        }
    }

    /** @return array<string, int|string> */
    private function completedProcessingState(): array
    {
        return ['nfostatus' => 1, 'haspreview' => 1, 'passwordstatus' => 0,
            'proc_nfo' => 1, 'proc_files' => 1, 'proc_par2' => 1, 'proc_hash16k' => 1,
            'proc_crc32' => 1, 'proc_srr' => 1, 'proc_uid' => 1, 'rarinnerfilecount' => 9,
            'pp_timeout_count' => 3, 'additional_pp_claim_token' => 'old-generation',
            'additional_pp_claimed_at' => '2099-01-01 00:00:00', 'archive_retry_at' => '2099-01-01 00:00:00',
            'proc_pp' => 1, 'jpgstatus' => 1, 'videostatus' => 1, 'audiostatus' => 1, 'movieinfo_id' => 42];
    }

    private function assertProcessingState(int $id, bool $reset, bool $checkPasswords = true): void
    {
        $expected = $this->completedProcessingState();
        if ($reset) {
            $expected = ['nfostatus' => -1, 'haspreview' => -1, 'passwordstatus' => $checkPasswords ? -1 : 0,
                'proc_nfo' => 0, 'proc_files' => 0, 'proc_par2' => 0, 'proc_hash16k' => 0,
                'proc_crc32' => 0, 'proc_srr' => 0, 'proc_uid' => 0, 'rarinnerfilecount' => 0,
                'pp_timeout_count' => 0, 'additional_pp_claim_token' => null,
                'additional_pp_claimed_at' => null, 'archive_retry_at' => null] + $expected;
        }
        $release = DB::table('releases')->where('id', $id)->first();
        foreach ($expected as $column => $value) {
            self::assertSame($value, is_int($value) ? (int) $release->$column : $release->$column, $column);
        }
        if ($reset) {
            self::assertTrue(AdditionalCandidateQuery::baseBuilder(minSizeBytes: 0, maxSizeBytes: 0)->where('r.id', $id)->exists());
            $nfo = app(NfoService::class);
            foreach (['minSize' => 0, 'maxSize' => 0, 'maxRetries' => -8] as $property => $value) {
                (new \ReflectionProperty(NfoService::class, $property))->setValue($nfo, $value);
            }
            $nfoQuery = new \ReflectionMethod(NfoService::class, 'buildNfoProcessingQuery');
            self::assertTrue($nfoQuery->invoke($nfo, '', '')->where('id', $id)->exists());
        }
    }

    private function derivedEvidenceTables(): array
    {
        return ['release_files', 'release_nfos', 'media_infos', 'par_hashes', 'par_file_hashes', 'par_file_hash_inventories', 'par2_hash_retry', 'par2_rename_progress', 'par2_descriptor_evidence'];
    }

    public function test_experimental_worker_death_preserves_custody(): void
    {
        $this->runtimeCustodyProbe('death');
    }

    public function test_experimental_worker_truncated_digest_preserves_custody(): void
    {
        $this->runtimeCustodyProbe('truncated');
    }

    private function runtimeCustodyProbe(string $mode): void
    {
        Bus::fake();
        Event::fake(['eloquent.updated: '.Release::class]);
        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertWritableCbp(200, 2000, 1);
        DB::table('releases')->where('id', 1)->update(['article_fingerprint' => str_repeat('f', 40)]);
        $worker = $this->tempNzbPath.'/worker';
        copy(__DIR__.'/../Fixtures/runtime-'.$mode.'.php', $worker);
        chmod($worker, 0700);
        $this->app->bind(NzbArticleFingerprint::class, static function () use ($worker): NzbArticleFingerprint {
            $experimentalWorker = new RustArticleFingerprintWorker($worker);
            $experimentalWorker->add('part01@example.test');
            $experimentalWorker->value();
            throw new \LogicException('Expected an experimental worker transport failure.');
        });
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $path = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($path, 'prior-artifact');
        $service = (new ReleaseProcessingService(nzb: $nzb, releaseManagement: new DatabaseOnlyReleaseManagementService, collectionCleanupService: app(CollectionCleanupService::class)))->setEchoCLI(false);
        $releaseBefore = (array) DB::table('releases')->first();
        $failureBefore = (array) DB::table('release_nzb_creation_failures')->first();
        $added = $service->createNZBs(null);
        $this->assertSame(0, $added, 'Runtime backend failure must defer publication and preserve custody');
        $this->assertSame('prior-artifact', file_get_contents($path));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
        $this->assertSame($releaseBefore, (array) DB::table('releases')->first());
        $this->assertSame($failureBefore, (array) DB::table('release_nzb_creation_failures')->first());
        for ($pass = 0; $pass < 3; $pass++) {
            $this->assertSame(0, $service->createNZBs(null));
            $this->assertSame($releaseBefore, (array) DB::table('releases')->first());
            $this->assertSame($failureBefore, (array) DB::table('release_nzb_creation_failures')->first());
            $this->assertSame('prior-artifact', file_get_contents($path));
            $this->assertSame(1, DB::table('collections')->count());
            $this->assertSame(1, DB::table('binaries')->count());
            $this->assertSame(1, DB::table('parts')->count());
        }
        Bus::assertNothingDispatched();
    }

    public function test_backend_restoration_replays_actual_writer_after_four_deferrals(): void
    {
        Bus::fake();
        Event::fake(['eloquent.updated: '.Release::class]);
        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertWritableCbp(200, 2000, 1);
        DB::table('releases')->where('id', 1)->update(['article_fingerprint' => str_repeat('f', 40)]);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $priorPath = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($priorPath, 'prior-artifact');
        $failureBefore = (array) DB::table('release_nzb_creation_failures')->first();
        $releaseBefore = (array) DB::table('releases')->first();
        $cbpBefore = [];
        foreach (['collections', 'binaries', 'parts'] as $table) {
            $cbpBefore[$table] = DB::table($table)->get()->toJson();
        }
        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);
        $this->bindUnavailableFingerprint();
        for ($pass = 0; $pass < 4; $pass++) {
            $this->assertSame(0, $service->createNZBs(null));
            $this->assertSame($failureBefore, (array) DB::table('release_nzb_creation_failures')->first());
            $this->assertSame($releaseBefore, (array) DB::table('releases')->first());
            foreach ($cbpBefore as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson());
            }
            $this->assertSame('prior-artifact', file_get_contents($priorPath));
            $this->assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
        }
        Bus::assertNothingDispatched();
        Event::assertNotDispatched('eloquent.updated: '.Release::class);
        $this->bindNativeFingerprint();
        try {
            $this->assertSame(1, $service->createNZBs(null));
            $xml = unzipGzipFile($priorPath);
            $this->assertIsString($xml);
            $this->assertNotSame('prior-artifact', file_get_contents($priorPath));
            $this->assertStringContainsString('part01@example.test', $xml);
            $this->assertSame('8f4bbce41949cf99526152819587abc71f7b64ec', DB::table('releases')->value('article_fingerprint'));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', DB::table('releases')->value('article_fingerprint'));
            $this->assertNotSame(str_repeat('f', 40), DB::table('releases')->value('article_fingerprint'));
            $this->assertSame(NzbService::NZB_ADDED, (int) DB::table('releases')->value('nzbstatus'));
            $this->assertSame(1, DB::table('releases')->count());
            $this->assertNull(DB::table('releases')->value('nzb_creation_claim_token'));
            $this->assertNull(DB::table('releases')->value('nzb_creation_claimed_at'));
            foreach (['collections', 'binaries', 'parts', 'release_nzb_creation_failures'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
            $this->assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
            Event::assertDispatchedTimes('eloquent.updated: '.Release::class, 1);
            Bus::assertDispatched(CollectReleaseFingerprintMetadata::class, fn ($job) => (new \ReflectionProperty($job, 'releaseId'))->getValue($job) === 1 && $job->connection === 'release-fingerprint' && $job->queue === 'release-fingerprint');
            Bus::assertDispatchedTimes(CollectReleaseFingerprintMetadata::class, 1);
            $this->assertSame(0, $service->createNZBs(null));
            Bus::assertDispatchedTimes(CollectReleaseFingerprintMetadata::class, 1);
        } finally {
            $this->app->forgetInstance(NzbArticleFingerprint::class);
        }
    }

    public function test_native_stream_failure_preserves_prior_identity_artifact_and_cbp_without_retry_penalty(): void
    {
        $this->assertBackendDeferralPreservesCustody(2, nativeIoFailure: true);
    }

    public function test_generic_constructor_failure_remains_transient_and_consumes_retry(): void
    {
        Bus::fake();
        $this->app->bind(NzbArticleFingerprint::class, static function (): NzbArticleFingerprint {
            throw new \RuntimeException('Rust article fingerprint is unavailable.');
        });
        $this->insertRelease(1, 'a');
        $this->insertWritableCbp(200, 2000, 1);
        $nzb = new NzbService(app(CollectionCleanupService::class));
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        $result = $nzb->createNzbForRelease($release);
        $this->assertTrue($result->isTransientFailure());
        $this->assertFalse($result->isDeferred());
        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);
        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(1, (int) DB::table('release_nzb_creation_failures')->value('attempts'));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertNull(DB::table('releases')->value('nzb_creation_claim_token'));
        $this->assertNull(DB::table('releases')->value('nzb_creation_claimed_at'));
        $this->assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
        Bus::assertNothingDispatched();
    }

    public function test_malformed_collections_remain_deterministic_when_backend_is_unavailable(): void
    {
        $this->bindUnavailableFingerprint();
        $this->insertRelease(1, 'a');
        $release = Release::query()->findOrFail(1);
        $result = (new NzbService(app(CollectionCleanupService::class)))->createNzbForRelease($release);
        $this->assertTrue($result->isDeterministicFailure());
        $this->assertFalse($result->isDeferred());
        $this->assertStringContainsString('no collections', $result->reason);
    }

    public function test_backend_unavailable_defers_actual_writer_without_accounting_or_custody_changes(): void
    {
        $this->assertBackendDeferralPreservesCustody(2);
    }

    public function test_backend_unavailable_does_not_create_a_retry_record_for_a_fresh_release(): void
    {
        $this->assertBackendDeferralPreservesCustody(0);
    }

    private function bindNativeFingerprint(): void
    {
        $this->app->bind(NzbArticleFingerprint::class, static fn (): NzbArticleFingerprint => new NzbArticleFingerprint);
    }

    private function bindUnavailableFingerprint(): void
    {
        $this->app->bind(NzbArticleFingerprint::class, static fn (): NzbArticleFingerprint => throw new NzbArticleFingerprintUnavailable('Injected unavailable fingerprint runtime.'));
    }

    private function assertBackendDeferralPreservesCustody(int $attempts, bool $nativeIoFailure = false): void
    {
        if ($nativeIoFailure) {
            $this->app->bind(NzbArticleFingerprint::class, static fn (): NzbArticleFingerprint => new NzbArticleFingerprint(temporaryStreamFactory: static fn () => false));
        } else {
            $this->bindUnavailableFingerprint();
        }
        Bus::fake();
        $cleanup = \Mockery::mock(CollectionCleanupService::class)->makePartial();
        $cleanup->shouldNotReceive('deleteCollectionsAndDescendants');
        $management = \Mockery::mock(DatabaseOnlyReleaseManagementService::class)->makePartial();
        $management->shouldNotReceive('deleteSingleWithService');
        $this->insertRelease(1, 'a', attempts: $attempts);
        $this->insertWritableCbp(200, 2000, 1);
        DB::table('releases')->where('id', 1)->update(['article_fingerprint' => str_repeat('f', 40)]);
        $nzb = new NzbService($cleanup);
        $priorPath = $nzb->getNzbPath(str_repeat('a', 36), createIfNotExist: true);
        file_put_contents($priorPath, 'prior-artifact');
        $failureBefore = (array) DB::table('release_nzb_creation_failures')->first();
        $releaseBefore = (array) DB::table('releases')->first();
        $cbpBefore = [];
        foreach (['collections', 'binaries', 'parts'] as $table) {
            $cbpBefore[$table] = DB::table($table)->get()->toJson();
        }
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        $result = $nzb->createNzbForRelease($release);
        $this->assertTrue($result->isDeferred());
        $this->assertFalse($result->isTransientFailure());
        $this->assertFalse($result->isDeterministicFailure());
        $service = (new ReleaseProcessingService(
            nzb: $nzb,
            releaseManagement: $management,
            collectionCleanupService: $cleanup,
        ))->setEchoCLI(false);
        for ($pass = 0; $pass < 4; $pass++) {
            $this->assertSame(0, $service->createNZBs(null));
            $this->assertSame($failureBefore, (array) DB::table('release_nzb_creation_failures')->first());
            foreach ($cbpBefore as $table => $rows) {
                $this->assertSame($rows, DB::table($table)->get()->toJson());
            }
            $this->assertSame($releaseBefore, (array) DB::table('releases')->first());
            $this->assertSame('prior-artifact', file_get_contents($priorPath));
            $this->assertSame(str_repeat('f', 40), DB::table('releases')->value('article_fingerprint'));
            $this->assertSame(0, (int) DB::table('releases')->value('nzbstatus'));
            $this->assertNull(DB::table('releases')->value('nzb_creation_claim_token'));
            $this->assertNull(DB::table('releases')->value('nzb_creation_claimed_at'));
            $this->assertSame([], $nzb->findStaleTemporaryNzbPaths(0));
        }
        Bus::assertNothingDispatched();
    }

    public function test_required_verification_does_not_claim_legacy_header_only_nzb_backlog(): void
    {
        $this->insertRelease(1, 'v');
        config(['release_verification.required' => true]);

        $this->assertSame(0, NzbCreationCandidateQuery::baseBuilder()->count());
        $this->assertCount(0, NzbCreationCandidateQuery::claimBatch(null, 10, 'verification-required', ['id']));
        $this->assertSame(0, $this->releaseProcessingService(NzbCreationResult::success('unused', []))->createNZBs(null));
        $release = DB::table('releases')->where('id', 1)->first();
        $this->assertSame(NzbService::NZB_NONE, $release->nzbstatus);
        $this->assertNull($release->nzb_creation_claim_token);
        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
    }

    public function test_required_verification_rejects_direct_header_only_writer_without_destroying_inputs(): void
    {
        $this->insertRelease(1, 'v');
        $this->insertWritableCbp(200, 2000, 1);
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);
        config(['release_verification.required' => true]);
        $nzb = new NzbService(app(CollectionCleanupService::class));

        $result = $nzb->createNzbForRelease($release);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('verification', $result->reason);
        $this->assertFalse($nzb->writeNzbForReleaseId($release));
        $this->assertFileDoesNotExist($nzb->getNzbPath($release->guid));
        $this->assertSame(NzbService::NZB_NONE, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame(1, DB::table('binaries')->count());
        $this->assertSame(1, DB::table('parts')->count());
    }

    public function test_a_release_pass_indexes_each_new_release_once_in_bulk(): void
    {
        DB::statement('CREATE TABLE search_index_failures (id INTEGER PRIMARY KEY AUTOINCREMENT, release_id INTEGER UNIQUE, operation VARCHAR(32), attempts INTEGER DEFAULT 0, last_error TEXT NULL, next_attempt_at DATETIME NULL, resolved_at DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)');
        $bulkCalls = [];
        $singleCalls = [];
        $driver = $this->createStubForIntersectionOfInterfaces([SearchDriverInterface::class, BulkReleaseIndexUpdater::class]);
        $driver->method('updateReleases')->willReturnCallback(static function (array $releaseIds) use (&$bulkCalls): array {
            $bulkCalls[] = $releaseIds;

            return [];
        });
        $driver->method('updateRelease')->willReturnCallback(static function (int|string $releaseId) use (&$singleCalls): void {
            $singleCalls[] = (int) $releaseId;
        });
        config(['search.default' => 'fake']);
        $search = new SearchService($this->app);
        $search->extend('fake', static fn (): SearchDriverInterface => $driver);
        $this->app->instance(SearchService::class, $search);
        Search::swap($search);

        $this->insertRelease(1, 'a');
        $this->insertCbp(200, 2000, 1);
        $service = (new ReleaseProcessingService(
            nzb: new IndexingNzbCreationService(NzbCreationResult::success('/tmp/a.nzb.gz', [200])),
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            releaseCreationService: new IndexingReleaseCreationService([1]),
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        [$created, $nzbs] = $service->createReleasesAndNzbs(null);

        $this->assertSame(1, $created->added);
        $this->assertSame(1, $nzbs);
        $this->assertSame([[1]], $bulkCalls, 'Indexed once for creation and NZB together.');
        $this->assertSame([], $singleCalls);
        $this->assertSame(0, DB::table('search_index_failures')->count());
    }

    public function test_preferred_candidates_keep_claim_guards_group_scope_and_total_budget(): void
    {
        $this->insertRelease(1, 'a', claimedAt: now());
        $this->insertRelease(2, 'b', postdate: '2026-07-13 10:00:00');
        $this->insertRelease(3, 'c', postdate: '2026-07-13 09:00:00');
        $this->insertRelease(4, 'd', postdate: '2026-07-13 08:00:00');
        DB::table('releases')->where('id', 3)->update(['groups_id' => 2]);
        $claimed = NzbCreationCandidateQuery::claimBatch(1, 2, 'preferred-token', ['id'], [1, 3, 4]);
        $this->assertSame([4, 2], $claimed->pluck('id')->all());
        $this->assertSame('claimed', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
        $this->assertNull(DB::table('releases')->where('id', 3)->value('nzb_creation_claim_token'));
        $this->assertSame('preferred-token', DB::table('releases')->where('id', 4)->value('nzb_creation_claim_token'));
    }

    public function test_candidate_query_skips_active_claims_and_recovers_stale_claims(): void
    {
        $this->insertRelease(1, 'a', claimedAt: now(), postdate: '2026-07-13 10:00:00');
        $this->insertRelease(2, 'b', claimedAt: now()->subSeconds(301), postdate: '2026-07-13 09:00:00');
        $this->insertRelease(3, 'c', postdate: '2026-07-13 08:00:00');

        $claimed = NzbCreationCandidateQuery::claimBatch(null, 10, 'token-one', ['id']);

        $this->assertSame([2, 3], $claimed->pluck('id')->all());
        $this->assertSame('token-one', DB::table('releases')->where('id', 2)->value('nzb_creation_claim_token'));
        $this->assertSame('claimed', DB::table('releases')->where('id', 1)->value('nzb_creation_claim_token'));
    }

    public function test_deterministic_creation_failure_deletes_release_and_cbp_rows(): void
    {
        $this->insertRelease(1, 'a');
        $this->insertCbp(200, 2000, 1);

        $service = $this->releaseProcessingService(
            NzbCreationResult::deterministic('Collection has invalid data.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
    }

    public function test_transient_creation_failure_records_retry_before_threshold(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')
            ->once()
            ->with('nzb_creation')
            ->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 0);
        $this->insertCbp(200, 2000, 1);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Temporary filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(1, DB::table('releases')->count());
        $this->assertSame(1, (int) DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('attempts'));
        $this->assertSame('Temporary filesystem failure.', DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('last_error'));
        $this->assertNotNull(DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('created_at'));
        $this->assertNotNull(DB::table('release_nzb_creation_failures')->where('releases_id', 1)->value('updated_at'));
        $this->assertNull(DB::table('releases')->where('id', 1)->value('nzb_creation_claimed_at'));
        $this->assertSame(1, DB::table('collections')->count());
        $this->assertSame('NZB creation failed; release will be retried', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['release_id']);
        $this->assertSame(str_repeat('a', 36), $nzbCreationLogger->warnings[0]['context']['guid']);
        $this->assertSame(NzbCreationResult::FAILURE_TRANSIENT, $nzbCreationLogger->warnings[0]['context']['failure_type']);
        $this->assertSame('Temporary filesystem failure.', $nzbCreationLogger->warnings[0]['context']['reason']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['next_attempt']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['max_attempts']);
    }

    public function test_third_transient_creation_failure_deletes_release_and_cbp_rows(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')
            ->once()
            ->with('nzb_creation')
            ->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertCbp(200, 2000, 1);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Repeated filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame(0, DB::table('collections')->count());
        $this->assertSame(0, DB::table('binaries')->count());
        $this->assertSame(0, DB::table('parts')->count());
        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
        $this->assertSame('Deleting release after NZB creation failure', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(1, $nzbCreationLogger->warnings[0]['context']['release_id']);
        $this->assertSame(str_repeat('a', 36), $nzbCreationLogger->warnings[0]['context']['guid']);
        $this->assertSame(NzbCreationResult::FAILURE_TRANSIENT, $nzbCreationLogger->warnings[0]['context']['failure_type']);
        $this->assertSame('Repeated filesystem failure.', $nzbCreationLogger->warnings[0]['context']['reason']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['attempt']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['max_attempts']);
    }

    public function test_repeated_transient_failure_increments_attempts_and_touches_updated_at(): void
    {
        Log::partialMock();
        Log::shouldReceive('channel')->once()->with('nzb_creation')->andReturn(new RecordingNzbCreationLogger);

        $this->insertRelease(1, 'a');
        $this->insertCbp(200, 2000, 1);
        DB::table('release_nzb_creation_failures')->insert([
            'releases_id' => 1,
            'attempts' => 1,
            'last_error' => 'Earlier failure.',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $service = $this->releaseProcessingService(
            NzbCreationResult::transient('Another filesystem failure.', [200])
        );

        $this->assertSame(0, $service->createNZBs(null));

        $failure = DB::table('release_nzb_creation_failures')->where('releases_id', 1)->first();

        $this->assertSame(2, (int) $failure->attempts);
        $this->assertSame('Another filesystem failure.', $failure->last_error);
        $this->assertSame('2026-01-01 00:00:00', $failure->created_at);
        $this->assertNotSame('2026-01-01 00:00:00', $failure->updated_at);
    }

    public function test_attempt_cap_holds_when_the_failure_relation_was_dropped(): void
    {
        Log::partialMock();
        $nzbCreationLogger = new RecordingNzbCreationLogger;
        Log::shouldReceive('channel')->once()->with('nzb_creation')->andReturn($nzbCreationLogger);

        $this->insertRelease(1, 'a', attempts: 2);
        $this->insertCbp(200, 2000, 1);

        $service = (new ReleaseProcessingService(
            nzb: new RelationDroppingNzbService(NzbCreationResult::transient('Repeated filesystem failure.', [200])),
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);

        $this->assertSame(0, $service->createNZBs(null));
        $this->assertSame(0, DB::table('releases')->count());
        $this->assertSame('Deleting release after NZB creation failure', $nzbCreationLogger->warnings[0]['message']);
        $this->assertSame(3, $nzbCreationLogger->warnings[0]['context']['attempt']);
    }

    public function test_writer_classifies_final_rename_failure_as_transient_without_final_file(): void
    {
        $this->insertRelease(1, 'd');
        $this->insertWritableCbp(200, 2000, 1);
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $nzb = new RenameFailingNzbService(app(CollectionCleanupService::class));
        $result = $nzb->createNzbForRelease($release);

        $this->assertFalse($result->success);
        $this->assertTrue($result->isTransientFailure());
        $this->assertStringContainsString('move temporary NZB into place', $result->reason);
        $this->assertFileDoesNotExist($nzb->getNzbPath($release->guid));
        $this->assertSame(0, (int) DB::table('releases')->where('id', 1)->value('nzbstatus'));
    }

    public function test_writer_streams_multiple_keyset_pages_in_segment_order(): void
    {
        Bus::fake();
        Search::shouldReceive('updateRelease')->andReturnNull();
        $this->insertRelease(1, 'h');
        DB::table('release_nzb_creation_failures')->insert([
            'releases_id' => 1,
            'attempts' => 1,
            'last_error' => 'Earlier failure.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertWritableCbp(200, 2000, 1);
        DB::table('parts')->where('binaries_id', 2000)->delete();
        DB::table('parts')->insert([
            ['binaries_id' => 2000, 'messageid' => '<part02@example.test>', 'partnumber' => 2, 'size' => 200],
            ['binaries_id' => 2000, 'messageid' => '<part01@example.test>', 'partnumber' => 1, 'size' => 100],
            ['binaries_id' => 2000, 'messageid' => '<part03@example.test>', 'partnumber' => 3, 'size' => 300],
        ]);
        $release = Release::query()->findOrFail(1);
        $release->setRelation('category', (object) ['title' => 'Misc', 'parent' => (object) ['title' => 'Other']]);

        $nzb = new NzbService(app(CollectionCleanupService::class), new BinariesConfig(nzbStreamRows: 2));
        $result = $nzb->createNzbForRelease($release);

        $this->assertTrue($result->success, $result->reason);
        $xml = unzipGzipFile((string) $result->path);
        $this->assertIsString($xml);
        $this->assertLessThan(strpos($xml, 'part02@example.test'), strpos($xml, 'part01@example.test'));
        $this->assertLessThan(strpos($xml, 'part03@example.test'), strpos($xml, 'part02@example.test'));
        $this->assertStringContainsString('<group>alt.test</group>', $xml);
        $this->assertSame(0, DB::table('release_nzb_creation_failures')->count());
        $this->assertSame('0dc4cde0ceec4711ebb2fee0206ae1b83990a6d9', DB::table('releases')->where('id', 1)->value('article_fingerprint'));
    }

    public function test_stale_temporary_nzb_cleanup_deletes_only_old_temp_files(): void
    {
        $directory = $this->tempNzbPath.'/a';
        mkdir($directory, 0777, true);

        $oldTemporary = $directory.'/'.str_repeat('e', 36).'.nzb.gz.tmp.123.'.str_repeat('a', 12);
        $recentTemporary = $directory.'/'.str_repeat('f', 36).'.nzb.gz.tmp.456.'.str_repeat('b', 12);
        $finalNzb = $directory.'/'.str_repeat('g', 36).'.nzb.gz';

        file_put_contents($oldTemporary, 'old');
        file_put_contents($recentTemporary, 'recent');
        file_put_contents($finalNzb, 'final');
        touch($oldTemporary, time() - 7200);
        touch($recentTemporary, time() - 60);
        touch($finalNzb, time() - 7200);

        $nzb = new NzbService(app(CollectionCleanupService::class));

        $this->assertSame([$oldTemporary], $nzb->findStaleTemporaryNzbPaths(3600));
        $this->assertSame(1, $nzb->cleanupStaleTemporaryNzbs(3600));
        $this->assertFileDoesNotExist($oldTemporary);
        $this->assertFileExists($recentTemporary);
        $this->assertFileExists($finalNzb);
    }

    private function releaseProcessingService(NzbCreationResult $result): ReleaseProcessingService
    {
        return (new ReleaseProcessingService(
            nzb: new FakeNzbCreationService($result),
            releaseManagement: new DatabaseOnlyReleaseManagementService,
            collectionCleanupService: app(CollectionCleanupService::class),
        ))->setEchoCLI(false);
    }

    private function insertRelease(
        int $id,
        string $leftguid,
        int $attempts = 0,
        ?\DateTimeInterface $claimedAt = null,
        string $postdate = '2026-07-13 00:00:00',
    ): void {
        DB::table('releases')->insert([
            'id' => $id,
            'guid' => str_pad($leftguid, 36, $leftguid),
            'leftguid' => $leftguid,
            'name' => 'Release.'.$id,
            'searchname' => 'Release.'.$id,
            'groups_id' => 1,
            'categories_id' => 1,
            'postdate' => $postdate,
            'nzbstatus' => NzbService::NZB_NONE,
            'nzb_creation_claimed_at' => $claimedAt?->format('Y-m-d H:i:s'),
            'nzb_creation_claim_token' => $claimedAt === null ? null : 'claimed',
        ]);

        if ($attempts > 0) {
            DB::table('release_nzb_creation_failures')->insert([
                'releases_id' => $id,
                'attempts' => $attempts,
                'last_error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertCbp(int $collectionId, int $binaryId, int $releaseId): void
    {
        DB::table('collections')->insert([
            'id' => $collectionId,
            'releases_id' => $releaseId,
        ]);
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'collections_id' => $collectionId,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $binaryId,
        ]);
    }

    private function insertWritableCbp(int $collectionId, int $binaryId, int $releaseId): void
    {
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);
        DB::table('collections')->insert([
            'id' => $collectionId,
            'releases_id' => $releaseId,
            'fromname' => 'poster@example.test',
            'date' => '2026-07-13 10:00:00',
            'xref' => 'alt.test:12345',
            'groups_id' => 1,
        ]);
        DB::table('binaries')->insert([
            'id' => $binaryId,
            'collections_id' => $collectionId,
            'name' => 'Example.Release.part01.rar yEnc',
            'totalparts' => 1,
        ]);
        DB::table('parts')->insert([
            'binaries_id' => $binaryId,
            'messageid' => '<part01@example.test>',
            'partnumber' => 1,
            'size' => 100,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([...$this->derivedEvidenceTables(), 'parts', 'binaries', 'collections', 'release_nzb_creation_failures', 'releases', 'categories', 'root_categories', 'usenet_groups'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
        DB::statement('CREATE TABLE root_categories (id INTEGER PRIMARY KEY, title VARCHAR(255), status INTEGER DEFAULT 1, disablepreview INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE categories (id INTEGER PRIMARY KEY, title VARCHAR(255), disablepreview INTEGER DEFAULT 0, root_categories_id INTEGER NULL)');
        DB::statement('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            guid VARCHAR(64),
            leftguid VARCHAR(1),
            name VARCHAR(255),
            searchname VARCHAR(255),
            groups_id INTEGER,
            categories_id INTEGER,
            postdate DATETIME NULL,
            nzbstatus INTEGER,
            nzb_creation_claimed_at DATETIME NULL,
            nzb_creation_claim_token VARCHAR(64) NULL,
            article_fingerprint CHAR(40) NULL,
            additional_pack_evidence TEXT NULL,
            nfostatus INTEGER DEFAULT -1,
            haspreview INTEGER DEFAULT -1,
            passwordstatus INTEGER DEFAULT -1,
            size INTEGER DEFAULT 2000000,
            additional_pp_claimed_at DATETIME NULL,
            additional_pp_claim_token VARCHAR(64) NULL,
            archive_retry_at DATETIME NULL,
            proc_nfo INTEGER DEFAULT 0,
            proc_files INTEGER DEFAULT 0,
            proc_par2 INTEGER DEFAULT 0,
            proc_hash16k INTEGER DEFAULT 0,
            proc_crc32 INTEGER DEFAULT 0,
            proc_srr INTEGER DEFAULT 0,
            proc_uid INTEGER DEFAULT 0,
            rarinnerfilecount INTEGER DEFAULT 0,
            pp_timeout_count INTEGER DEFAULT 0,
            proc_pp INTEGER DEFAULT 0,
            jpgstatus INTEGER DEFAULT 0,
            videostatus INTEGER DEFAULT 0,
            audiostatus INTEGER DEFAULT 0,
            movieinfo_id INTEGER DEFAULT 0
        )');
        DB::statement('CREATE TABLE release_nzb_creation_failures (
            releases_id INTEGER PRIMARY KEY,
            attempts INTEGER DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (releases_id) REFERENCES releases(id) ON DELETE CASCADE
        )');
        DB::statement('CREATE TABLE usenet_groups (id INTEGER PRIMARY KEY, name VARCHAR(255))');
        DB::statement('CREATE TABLE collections (
            id INTEGER PRIMARY KEY,
            releases_id INTEGER NULL,
            fromname VARCHAR(255) NULL,
            date DATETIME NULL,
            xref TEXT NULL,
            groups_id INTEGER NULL
        )');
        DB::statement('CREATE TABLE binaries (
            id INTEGER PRIMARY KEY,
            collections_id INTEGER,
            name VARCHAR(255) NULL,
            totalparts INTEGER NULL
        )');
        DB::statement('CREATE TABLE parts (
            binaries_id INTEGER,
            messageid VARCHAR(255) NULL,
            partnumber INTEGER NULL,
            size INTEGER NULL
        )');
        foreach ($this->derivedEvidenceTables() as $table) {
            DB::statement('CREATE TABLE '.$table.' (releases_id INTEGER PRIMARY KEY)');
        }
        DB::table('root_categories')->insert(['id' => 1, 'title' => 'Other']);
        DB::table('categories')->insert(['id' => 1, 'title' => 'Misc', 'root_categories_id' => 1]);
    }

    private function deleteDirectory(string $path): void
    {
        if ($path === '' || ! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
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
}

class FakeNzbCreationService extends NzbService
{
    public function __construct(private readonly NzbCreationResult $result)
    {
        parent::__construct(app(CollectionCleanupService::class));
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        return $this->result;
    }
}

/**
 * Indexes the release as ReleaseObserver does when an NZB is written.
 */
class IndexingNzbCreationService extends FakeNzbCreationService
{
    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        Search::updateRelease($release->id);

        return parent::createNzbForRelease($release);
    }
}

/**
 * Indexes the releases as Release::insertRelease() does after creating them.
 */
class IndexingReleaseCreationService extends ReleaseCreationService
{
    /**
     * @param  list<int>  $releaseIds
     */
    public function __construct(private readonly array $releaseIds) {}

    /**
     * @return array{added: int, dupes: int}
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI, ?array $allowedIds = null): array
    {
        foreach ($this->releaseIds as $releaseId) {
            Search::updateRelease($releaseId);
        }

        return ['added' => count($this->releaseIds), 'dupes' => 0];
    }
}

class RelationDroppingNzbService extends FakeNzbCreationService
{
    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        $release->unsetRelation('nzbCreationFailure');

        return parent::createNzbForRelease($release);
    }
}

class DatabaseOnlyReleaseManagementService extends ReleaseManagementService
{
    /**
     * @param  array<string, mixed>  $identifiers
     */
    public function deleteSingleWithService(array $identifiers, NzbService $nzb, ReleaseImageService $releaseImage): void
    {
        Release::query()->where('guid', $identifiers['g'])->delete();
    }
}

class RenameFailingNzbService extends NzbService
{
    protected function moveTemporaryNzbIntoPlace(string $temporaryPath, string $finalPath): bool
    {
        return false;
    }
}

class RecordingNzbCreationLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->warnings[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
