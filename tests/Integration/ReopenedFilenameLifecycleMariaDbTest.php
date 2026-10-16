<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseFileManager;
use App\Services\AdditionalProcessing\State\ReleaseProcessingContext;
use App\Services\Categorization\CategorizationService;
use App\Services\CollectionCleanupService;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\OriginalFilenameEvidence;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NfoService;
use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\ReleaseImageService;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\AdditionalProcessing\CreatesProcessingConfiguration;

final class ReopenedFilenameLifecycleMariaDbTest extends TestCase
{
    use CreatesProcessingConfiguration;

    private ?PDO $administrator = null;

    private string $database = '';

    private bool $databaseCreated = false;

    private bool $connectionConfigured = false;

    private string $sourceDirectory = '';

    private ?\Closure $filenameDiscoveryMutation = null;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['database.default', 'database.connections.filename_lifecycle_qualification', 'cache.default', 'nntmux.echocli', 'nntmux_settings.path_to_nzbs'] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        $host = (string) (getenv('FILENAME_LIFECYCLE_TEST_HOST') ?: getenv('PAR2_DONOR_TEST_HOST'));
        if ($host === '') {
            $this->markTestSkipped('Set FILENAME_LIFECYCLE_TEST_HOST or PAR2_DONOR_TEST_HOST to an isolated MariaDB test server.');
        }
        try {
            $port = (int) (getenv('FILENAME_LIFECYCLE_TEST_PORT') ?: getenv('PAR2_DONOR_TEST_PORT') ?: 3306);
            $username = (string) (getenv('FILENAME_LIFECYCLE_TEST_USERNAME') ?: getenv('PAR2_DONOR_TEST_USERNAME') ?: 'root');
            $password = (string) (getenv('FILENAME_LIFECYCLE_TEST_PASSWORD') !== false
                ? getenv('FILENAME_LIFECYCLE_TEST_PASSWORD')
                : getenv('PAR2_DONOR_TEST_PASSWORD'));
            $this->administrator = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->database = 'nntmux_filename_lifecycle_'.bin2hex(random_bytes(8));
            $this->administrator->exec('CREATE DATABASE `'.$this->database.'`');
            $this->databaseCreated = true;
            $this->administrator->exec('USE `'.$this->database.'`');
            $this->administrator->exec($this->app->make('files')->get(database_path('schema/mariadb-schema.sql')));
            $this->sourceDirectory = sys_get_temp_dir().'/nntmux-filename-lifecycle-source-'.bin2hex(random_bytes(8));
            File::ensureDirectoryExists($this->sourceDirectory);
            config([
                'database.default' => 'filename_lifecycle_qualification',
                'database.connections.filename_lifecycle_qualification' => [
                    'driver' => 'mysql',
                    'host' => $host,
                    'port' => $port,
                    'database' => $this->database,
                    'username' => $username,
                    'password' => $password,
                    'url' => null,
                    'unix_socket' => '',
                    'prefix' => '',
                    'strict' => true,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
                'cache.default' => 'array',
                'nntmux.echocli' => false,
                'nntmux_settings.path_to_nzbs' => $this->sourceDirectory.'/',
            ]);
            DB::purge('filename_lifecycle_qualification');
            $this->connectionConfigured = true;
            DB::statement('SET SESSION max_statement_time = 60');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
            if (Artisan::call('migrate', ['--database' => 'filename_lifecycle_qualification', '--force' => true, '--no-interaction' => true]) !== 0) {
                throw new RuntimeException('Could not migrate owned filename lifecycle fixture: '.Artisan::output());
            }
            DB::table('root_categories')->insertOrIgnore(['id' => 2000, 'title' => 'Movies']);
            DB::table('categories')->insertOrIgnore([
                ['id' => Category::OTHER_MISC, 'title' => 'Misc', 'root_categories_id' => 2000],
                ['id' => Category::OTHER_HASHED, 'title' => 'Hashed', 'root_categories_id' => 2000],
                ['id' => Category::MOVIE_HD, 'title' => 'HD', 'root_categories_id' => 2000],
            ]);
            DB::table('usenet_groups')->insertOrIgnore(['id' => 1, 'name' => 'alt.binaries.filename.test']);
            DB::table('predb')->insertOrIgnore(['id' => 7, 'title' => 'Unrelated.Title-GRP']);
            $this->rebuildFilenameSqlIdentity();
            Search::shouldReceive('searchPredbFilenameCandidates')->never();
            Event::fake([ReleaseNameFixed::class]);
        } catch (\Throwable $exception) {
            $this->cleanupDatabase();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
            $this->cleanupDatabase();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupDatabase(): void
    {
        try {
            if ($this->databaseCreated && $this->administrator !== null && preg_match('/^nntmux_filename_lifecycle_[a-f0-9]{16}$/D', $this->database) === 1) {
                if ($this->connectionConfigured) {
                    $connection = DB::connection('filename_lifecycle_qualification');
                    while ($connection->transactionLevel() > 0) {
                        $connection->rollBack();
                    }
                }
                DB::purge('filename_lifecycle_qualification');
                $this->administrator->exec('DROP DATABASE `'.$this->database.'`');
                $this->databaseCreated = false;
            }
            if ($this->sourceDirectory !== '') {
                File::deleteDirectory($this->sourceDirectory);
                $this->sourceDirectory = '';
            }
        } finally {
            config($this->originalConfig);
        }
    }

    /** @return iterable<string, array{0: string, 1: bool, 2: bool, 3: bool}> */
    public static function isolationAndWakeupScenarios(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach ([
                'new filename' => [false, false, false],
                'already renamed' => [true, false, false],
                'PreDB linked' => [false, true, false],
                'duplicate-only concurrent replay' => [false, false, true],
            ] as $label => [$renamed, $linked, $duplicate]) {
                yield $isolation.':'.$label => [$isolation, $renamed, $linked, $duplicate];
            }
        }
    }

    #[DataProvider('isolationAndWakeupScenarios')]
    public function test_only_new_rows_on_an_unrenamed_unlinked_release_reopen_filename_processing(
        string $isolation,
        bool $renamed,
        bool $linked,
        bool $duplicate
    ): void {
        $this->isolation($isolation);
        $this->insertRelease([
            'proc_files' => 1,
            'isrenamed' => (int) $renamed,
            'predb_id' => $linked ? 7 : 0,
        ]);
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
        self::assertSame(1, DB::table('release_files')->where('releases_id', 1)->count());
        self::assertSame(! $duplicate, $context->releaseFilesChanged);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function isolationAndOwnershipChanges(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach ([
                'GUID replacement' => ['guid', 'new-guid-0000000000000000000000000000'],
                'subject reset' => ['name', 'New.Subject'],
                'searchname reset' => ['searchname', 'New.Searchname'],
                'claim replacement' => ['additional_pp_claim_token', 'new-owner'],
                'claim expiry' => ['additional_pp_claimed_at', '2000-01-01 00:00:00'],
            ] as $label => [$column, $value]) {
                yield $isolation.':'.$label => [$isolation, $column, $value];
            }
        }
    }

    #[DataProvider('isolationAndOwnershipChanges')]
    public function test_late_filename_admission_preserves_a_lost_claim_or_replaced_identity(
        string $isolation,
        string $column,
        string $value
    ): void {
        $this->isolation($isolation);
        $this->insertRelease(['proc_files' => 1]);
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        self::assertTrue($manager->addFileInfo(['name' => 'Example.Movie.2026.1080p.BluRay.x264-TARGET.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        DB::table('releases')->where('id', 1)->update([$column => $value]);
        Search::shouldReceive('updateRelease')->never();

        $manager->finalizeRelease($context, false);

        self::assertTrue($context->fullPar2EvidenceDeferred);
        self::assertSame(0, DB::table('release_files')->where('releases_id', 1)->count());
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($value, DB::table('releases')->where('id', 1)->value($column));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string|int}> */
    public static function isolationAndCompletionIdentityChanges(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach ([
                'GUID replacement' => ['guid', 'new-guid-0000000000000000000000000000'],
                'subject reset' => ['name', 'New.Subject'],
                'searchname reset' => ['searchname', 'New.Searchname'],
                'already renamed' => ['isrenamed', 1],
                'PreDB linked' => ['predb_id', 7],
            ] as $label => [$column, $value]) {
                yield $isolation.':'.$label => [$isolation, $column, $value];
            }
        }
    }

    #[DataProvider('isolationAndCompletionIdentityChanges')]
    public function test_filename_completion_preserves_a_changed_release_identity(
        string $isolation,
        string $column,
        string|int $value
    ): void {
        $this->isolation($isolation);
        $this->insertRelease();
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => str_repeat('a', 32).'.bin']);
        $this->filenameDiscoveryMutation = function () use ($column, $value): array {
            DB::table('releases')->where('id', 1)->update([$column => $value]);

            return [];
        };
        $fixer = $this->makeFixer();

        $fixer->fixNamesWithFiles(0, true, 1, true, false);

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($value, DB::table('releases')->where('id', 1)->value($column));
    }

    /** @return iterable<string, array{0: string}> */
    public static function isolationLevels(): iterable
    {
        yield 'READ COMMITTED' => ['READ COMMITTED'];
        yield 'REPEATABLE READ' => ['REPEATABLE READ'];
    }

    #[DataProvider('isolationLevels')]
    public function test_finalization_failure_rolls_back_inserted_files_and_reopened_proc_files(string $isolation): void
    {
        $this->isolation($isolation);
        $this->insertRelease(['proc_files' => 1]);
        Search::shouldReceive('updateRelease')->never();
        $manager = $this->makeManager();
        $context = $this->sourceContext(Release::query()->findOrFail(1));
        self::assertTrue($manager->addFileInfo(['name' => 'Example.Movie.2026.1080p.BluRay.x264-TARGET.mkv', 'size' => 1024], $context, '\\.(?:par2|sfv|nzb)'));
        $mutated = false;
        DB::listen(function (QueryExecuted $query) use (&$mutated): void {
            if (! $mutated && str_contains(strtolower($query->sql), 'insert ignore into `release_files`')) {
                $mutated = true;
                DB::table('releases')->where('id', 1)->update(['additional_pp_claim_token' => 'stolen-inside-tx']);
            }
        });

        try {
            $manager->finalizeRelease($context, false);
            self::fail('Expected finalization ownership loss after release_files insert to abort.');
        } catch (RuntimeException $exception) {
            self::assertSame('Additional-processing finalization lost release ownership.', $exception->getMessage());
        }

        self::assertTrue($mutated);
        self::assertSame(0, DB::table('release_files')->where('releases_id', 1)->count());
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame('stolen-inside-tx', DB::table('releases')->where('id', 1)->value('additional_pp_claim_token'));
    }

    #[DataProvider('isolationLevels')]
    public function test_caller_transaction_rollback_restores_pending_proc_files_after_completion(string $isolation): void
    {
        $this->isolation($isolation);
        $this->insertRelease();
        $name = str_repeat('a', 32).'.bin';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        $fixer = $this->makeFixer();

        DB::beginTransaction();
        try {
            $fixer->fixNamesWithFiles(0, true, 1, true, false);
            self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        } finally {
            DB::rollBack();
        }

        self::assertSame(0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        $fixer->fixNamesWithFiles(0, true, 1, true, false);
        self::assertSame(1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function isolationAndXxxInterleavings(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            yield $isolation.':unchanged mixed inventory completes' => [$isolation, false];
            yield $isolation.':late file preserves wake-up' => [$isolation, true];
        }
    }

    #[DataProvider('isolationAndXxxInterleavings')]
    public function test_xxx_filename_completion_compares_the_full_observed_inventory(string $isolation, bool $addLateFile): void
    {
        $this->isolation($isolation);
        $this->insertRelease();
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
        $fixer = $this->makeFixer();

        $fixer->fixXXXNamesWithFiles(0, true, 1, true, false);

        self::assertSame($addLateFile ? 0 : 1, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($addLateFile ? 3 : 2, DB::table('release_files')->where('releases_id', 1)->count());
        self::assertSame($addLateFile, $inserted);
    }

    /** @return iterable<string, array{0: string, 1: int, 2: int, 3: bool}> */
    public static function isolationAndDirectFilenamePredbStates(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach ([
                'stable unlinked singleton' => [0, 0, true],
                'stable linked singleton' => [7, 7, true],
                'new link during discovery' => [0, 7, false],
                'link replacement during discovery' => [7, 8, false],
            ] as $label => [$observedPredb, $currentPredb, $completed]) {
                yield $isolation.':'.$label => [$isolation, $observedPredb, $currentPredb, $completed];
            }
        }
    }

    #[DataProvider('isolationAndDirectFilenamePredbStates')]
    public function test_direct_filename_completion_preserves_the_exact_observed_predb_link(
        string $isolation,
        int $observedPredb,
        int $currentPredb,
        bool $completed
    ): void {
        $this->isolation($isolation);
        $this->insertRelease(['predb_id' => $observedPredb]);
        $name = str_repeat('a', 32).'.bin';
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        $input = Release::query()->findOrFail(1);
        $input->releases_id = 1;
        $input->textstring = $name;
        $this->filenameDiscoveryMutation = function () use ($currentPredb): array {
            DB::table('releases')->where('id', 1)->update(['predb_id' => $currentPredb]);

            return [];
        };
        $fixer = $this->makeFixer();

        $fixer->checkName($input, true, 'Filenames, ', true, false);

        self::assertSame($completed ? 1 : 0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
        self::assertSame($currentPredb, (int) DB::table('releases')->where('id', 1)->value('predb_id'));
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function isolationAndDirectFilenameInventories(): iterable
    {
        foreach (['READ COMMITTED', 'REPEATABLE READ'] as $isolation) {
            foreach ([
                'unpersisted external text' => 0,
                'inspected singleton' => 1,
                'uninspected sibling' => 2,
            ] as $label => $fileCount) {
                yield $isolation.':'.$label => [$isolation, $fileCount];
            }
        }
    }

    #[DataProvider('isolationAndDirectFilenameInventories')]
    public function test_direct_filename_completion_requires_the_persisted_inventory_to_be_fully_inspected(
        string $isolation,
        int $fileCount
    ): void {
        $this->isolation($isolation);
        $this->insertRelease();
        $name = str_repeat('a', 32).'.bin';
        for ($index = 0; $index < $fileCount; $index++) {
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => $index === 0 ? $name : str_repeat('b', 32).'.bin']);
        }
        $fixer = $this->makeFixer();
        $input = Release::query()->findOrFail(1);
        $input->releases_id = 1;
        $input->textstring = $name;

        $fixer->checkName($input, true, 'Filenames, ', true, false);

        self::assertSame($fileCount === 1 ? 1 : 0, (int) DB::table('releases')->where('id', 1)->value('proc_files'));
    }

    private function isolation(string $level): void
    {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$level);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRelease(array $overrides = []): void
    {
        DB::table('releases')->insert(array_replace([
            'id' => 1,
            'guid' => 'abcdef00-0000-0000-0000-000000000001',
            'leftguid' => 'a',
            'name' => 'Example',
            'searchname' => 'Example',
            'fromname' => 'poster@example.com',
            'size' => 1024,
            'groups_id' => 1,
            'nfostatus' => -1,
            'categories_id' => Category::OTHER_HASHED,
            'passwordstatus' => -1,
            'haspreview' => -1,
            'nzbstatus' => 1,
            'rarinnerfilecount' => 0,
            'pp_timeout_count' => 0,
            'proc_pp' => 0,
            'proc_files' => 0,
            'proc_nfo' => 1,
            'proc_uid' => 1,
            'proc_srr' => 1,
            'proc_crc32' => 1,
            'proc_hash16k' => 1,
            'proc_par2' => 0,
            'predb_id' => 0,
            'isrenamed' => 0,
            'postdate' => '2026-10-11 00:00:00',
            'additional_pp_claimed_at' => now()->format('Y-m-d H:i:s'),
            'additional_pp_claim_token' => 'token',
        ], $overrides));
    }

    private function makeFixer(): NameFixingService
    {
        return new NameFixingService(
            new ReleaseUpdateService($this->createStub(CategorizationService::class)),
            fileNameCleaner: $this->filenameDiscoveryCleaner(),
            queries: new NameFixingQueryService(DB::connection()),
        );
    }

    private function makeManager(?NameFixingService $nameFixing = null): ReleaseFileManager
    {
        $nzb = new class extends NzbService
        {
            public function __construct()
            {
                parent::__construct(new CollectionCleanupService);
            }
        };
        $sources = new NzbSourceGuard($nzb);
        $this->app->instance(NzbSourceGuard::class, $sources);
        $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));

        return new ReleaseFileManager(
            $this->makeConfig(),
            new ReleaseImageService,
            new NfoService,
            $nzb,
            $nameFixing ?? new class extends NameFixingService
            {
                public function matchPreDbFileNames(object $release, array $files, bool $echo, bool $nameStatus, bool $show, ?array $persistedNames = null): int
                {
                    return 0;
                }
            }
        );
    }

    private function sourceContext(object $release): ReleaseProcessingContext
    {
        $nzb = new class extends NzbService
        {
            public function __construct()
            {
                parent::__construct(new CollectionCleanupService);
            }
        };
        $path = $nzb->getNzbPath($release->guid, createIfNotExist: true);
        if (! File::isFile($path)) {
            $xml = '<nzb><file subject="fixture.bin yEnc (1/1)"><groups><group>alt.fixture</group></groups>'
                .'<segments><segment bytes="1" number="1">fixture-source@article</segment></segments></file></nzb>';
            File::put($path, gzencode($xml));
        }
        $sources = new NzbSourceGuard($nzb);
        $this->app->instance(NzbSourceGuard::class, $sources);
        $this->app->instance(ReleaseArchiveNamingEvidence::class, new ReleaseArchiveNamingEvidence($sources));
        $snapshot = $sources->snapshot($release->guid);
        self::assertNotNull($snapshot);
        $context = new ReleaseProcessingContext($release);
        $context->nzbSourceDigest = $snapshot['digest'];

        return $context;
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

    private function rebuildFilenameSqlIdentity(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $chunk = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $chunk['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }
}
