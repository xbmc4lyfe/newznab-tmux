<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Facades\Search;
use App\Models\Release;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\FileNameCleaner;
use App\Services\NameFixing\FilePrioritizer;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class GenericPredbAttachmentTest extends TestCase
{
    private const TITLE = 'Valid.Scene.Release.2026-GROUP';

    private Capsule $database;

    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Release::getConnectionResolver();
        $container = new Application(dirname(__DIR__, 4));
        $container->instance('config', new Repository);
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $container->instance('db', $this->database->getDatabaseManager());
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        DB::statement('CREATE TABLE par2_rename_progress (releases_id INTEGER PRIMARY KEY)');
        DB::statement('CREATE TABLE predb (id INTEGER PRIMARY KEY, title TEXT, filename TEXT)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, guid TEXT, name TEXT COLLATE NOCASE, searchname TEXT COLLATE NOCASE, predb_id INTEGER DEFAULT 0, fromname TEXT, groups_id INTEGER DEFAULT 1, categories_id INTEGER DEFAULT 10, size INTEGER DEFAULT 5000, nfostatus INTEGER DEFAULT 1, isrenamed INTEGER DEFAULT 0, proc_nfo INTEGER DEFAULT 0, proc_files INTEGER DEFAULT 0, proc_par2 INTEGER DEFAULT 0, proc_uid INTEGER DEFAULT 0, proc_srr INTEGER DEFAULT 0, proc_hash16k INTEGER DEFAULT 0, proc_crc32 INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE release_files (releases_id INTEGER, name TEXT, crc32 TEXT)');
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Release::setConnectionResolver($this->previousResolver);
        } else {
            Release::unsetConnectionResolver();
        }
        $this->database->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[DataProvider('races')]
    public function test_selected_unlinked_snapshot_rejects_a_write_boundary_change(string $change): void
    {
        $this->seed(0);
        $release = (new NameFixingQueryService(DB::connection()))->candidateBatch('files', 0, 1)[0];
        $release->textstring = self::TITLE;
        [$service, $updater] = $this->services();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $query) use ($change, &$changed): void {
            if ($changed || preg_match('/^update [\"`]?releases/iu', $query) !== 1) {
                return;
            }
            $changed = true;
            if ($change === 'delete') {
                DB::table('releases')->where('id', 42)->delete();
            } elseif ($change === 'link') {
                DB::table('releases')->where('id', 42)->update(['predb_id' => 999]);
            } elseif ($change === 'name') {
                DB::table('releases')->where('id', 42)->update(['searchname' => 'Other.Scene.Release.2026-GROUP']);
            } elseif ($change === 'subject') {
                DB::table('releases')->where('id', 42)->update(['name' => strtoupper(self::TITLE)]);
            } elseif ($change === 'case-search') {
                DB::table('releases')->where('id', 42)->update(['searchname' => strtoupper(self::TITLE)]);
            } else {
                DB::table('releases')->where('id', 42)->update(['guid' => 'replacement-guid']);
            }
        });

        $matched = $service->checkName($release, true, 'PAR2, ', true, false);

        $this->assertTrue($changed);
        $this->assertFalse($matched);
        $this->assertFalse($updater->matched);
        $this->assertFalse($updater->done);
        $this->assertSame(0, $updater->fixed);
        if ($change === 'delete') {
            $this->assertSame(0, DB::table('releases')->where('id', 42)->count());
        } else {
            $this->assertSame($change === 'link' ? 999 : 0, DB::table('releases')->where('id', 42)->value('predb_id'));
            if ($change === 'name') {
                $this->assertSame('Other.Scene.Release.2026-GROUP', DB::table('releases')->where('id', 42)->value('searchname'));
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function races(): array
    {
        return ['existing link' => ['link'], 'different current name' => ['name'], 'deleted release' => ['delete'], 'replacement guid' => ['guid'], 'case-only subject change' => ['subject'], 'case-only searchname change' => ['case-search']];
    }

    #[DataProvider('attachmentPaths')]
    public function test_existing_same_link_is_a_legitimate_match_with_no_new_mutation(string $path): void
    {
        $release = $this->seed(7);
        [$service, $updater] = $this->services();
        $before = (array) DB::table('releases')->where('id', 42)->first();
        $writes = 0;
        DB::connection()->beforeExecuting(static function (string $query) use (&$writes): void {
            if (preg_match('/^update [\"`]?releases/iu', $query) === 1) {
                $writes++;
            }
        });

        $matched = $this->runPath($service, $release, $path);

        $this->assertTrue($matched);
        $this->assertTrue($updater->matched);
        $this->assertSame(0, $updater->fixed);
        $this->assertSame($before, (array) DB::table('releases')->where('id', 42)->first());
        $this->assertSame(0, $writes);
    }

    public function test_bulk_candidates_exclude_existing_links_but_include_unlinked_release(): void
    {
        $this->seed(999);
        $queries = new NameFixingQueryService(DB::connection());
        $this->assertSame([], $queries->candidateBatch('files', 0, 1));
        DB::table('releases')->where('id', 42)->update(['predb_id' => 0]);
        $this->assertCount(1, $queries->candidateBatch('files', 0, 1));
    }

    #[DataProvider('attachmentPaths')]
    public function test_every_same_name_path_preserves_an_existing_conflicting_link(string $path): void
    {
        $release = $this->seed(999);
        [$service, $updater] = $this->services();
        $updater->matched = $updater->done = true;

        $matched = $this->runPath($service, $release, $path);

        $this->assertFalse($matched);
        $this->assertFalse($updater->matched);
        $this->assertFalse($updater->done);
        $this->assertSame(0, $updater->fixed);
        $this->assertSame(999, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    #[DataProvider('attachmentPaths')]
    public function test_every_same_name_path_attaches_an_unlinked_unchanged_release(string $path): void
    {
        $release = $this->seed(0);
        [$service, $updater] = $this->services();

        $matched = $this->runPath($service, $release, $path);

        $this->assertTrue($matched);
        $this->assertTrue($updater->matched);
        $this->assertTrue($updater->done);
        $this->assertSame(0, $updater->fixed);
        $this->assertSame(7, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    /** @return array<string, array{string}> */
    public static function attachmentPaths(): array
    {
        return ['PAR2' => ['name'], 'filename' => ['filename'], 'prefile' => ['prefile'], 'donor' => ['donor'], 'legacy ID API' => ['legacy']];
    }

    private function runPath(NameFixingService $service, object $release, string $path): bool
    {
        $release->filename = self::TITLE.'.mkv';
        $release->relsize = 5000;
        if ($path === 'legacy') {
            $updates = (new ReflectionProperty(NameFixingService::class, 'updateService'))->getValue($service);
            $updates->attachPredbId((int) $release->releases_id, 7);

            return $updates->matched;
        }
        if ($path === 'name') {
            return $service->checkName($release, true, 'PAR2, ', true, false);
        }
        if ($path === 'prefile') {
            return $service->matchPreDbFiles($release, true, true, false) === 1;
        }
        if ($path === 'filename') {
            return (new ReflectionMethod(NameFixingService::class, 'preDbFileCheck'))->invoke($service, $release, true, 'Filenames, ', true, false);
        }
        $donor = (object) ['releases_id' => 99, 'relsize' => 5000, 'searchname' => self::TITLE, 'predb_id' => 7];

        return (new ReflectionMethod(NameFixingService::class, 'applyDonorMatch'))->invoke($service, $release, [$donor], 5, 'hashCheck: PAR2', 'PAR2 hash, ', true, true, false);
    }

    public static function numericStems(): array
    {
        return array_map(static fn ($s) => [$s], ['123456', '١٢٣٤٥٦', '１２３４５６', '१२३४५६', '¹²³⁴⁵⁶', 'ⅢⅣⅤⅥ', '١２३⁴Ⅴ', '١٢٣.٤٥٦', '１２３-４５６', '１２３_４５６', '１２３　４５６', '١٢٣٫٤٥٦', '(１２３４５６)']);
    }

    #[DataProvider('numericStems')]
    public function test_numeric_alias_rejected_by_public_revalidation(string $stem): void
    {
        $release = $this->seed(0);
        DB::table('predb')->where('id', 7)->update(['filename' => $stem.'.mkv']);
        [, $updater] = $this->services();
        $attached = $updater->attachPredbSnapshot($release, 7, self::TITLE, $stem);
        self::assertSame(0, (int) DB::table('releases')->where('id', 42)->value('predb_id'));
        self::assertFalse($attached);
        self::assertNull($updater->currentPredbTitle(7, self::TITLE, $stem));
    }

    #[DataProvider('numericStems')]
    public function test_numeric_literal_title_keeps_revalidation_precedence(string $stem): void
    {
        $release = $this->seed(0);
        DB::table('predb')->where('id', 7)->update(['title' => $stem]);
        DB::table('releases')->where('id', 42)->update(['name' => $stem, 'searchname' => $stem]);
        $release->name = $release->searchname = $stem;
        [, $updater] = $this->services();
        self::assertSame($stem, $updater->currentPredbTitle(7, $stem, $stem));
        self::assertTrue($updater->attachPredbSnapshot($release, 7, $stem, $stem));
        self::assertSame(7, (int) DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    public function test_alphanumeric_filename_remains_usable_by_revalidation(): void
    {
        $release = $this->seed(0);
        DB::table('predb')->where('id', 7)->update(['filename' => '第１２話.mkv']);
        [, $updater] = $this->services();
        self::assertSame(self::TITLE, $updater->currentPredbTitle(7, self::TITLE, '第１２話'));
        self::assertTrue($updater->attachPredbSnapshot($release, 7, self::TITLE, '第１２話'));
        self::assertSame(7, (int) DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    private function seed(int $predbId): object
    {
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
        DB::table('releases')->insert(['id' => 42, 'guid' => 'original-guid', 'name' => self::TITLE, 'searchname' => self::TITLE, 'predb_id' => $predbId]);
        DB::table('release_files')->insert(['releases_id' => 42, 'name' => self::TITLE.'.mkv']);
        $release = DB::table('releases')->where('id', 42)->first();
        $release->releases_id = 42;
        $release->textstring = self::TITLE;

        return $release;
    }

    /** @return array{NameFixingService, ReleaseUpdateService} */
    private function services(): array
    {
        $updater = (new ReflectionClass(ReleaseUpdateService::class))->newInstanceWithoutConstructor();
        $service = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ReleaseUpdateService::class, 'fileNameCleaner'))->setValue($updater, new FileNameCleaner);
        (new ReflectionProperty(NameFixingService::class, 'updateService'))->setValue($service, $updater);
        (new ReflectionProperty(NameFixingService::class, 'fileNameCleaner'))->setValue($service, new FileNameCleaner);
        (new ReflectionProperty(NameFixingService::class, 'filePrioritizer'))->setValue($service, new FilePrioritizer);
        (new ReflectionProperty(NameFixingService::class, 'donorMatchSelector'))->setValue($service, new DonorMatchSelector);
        (new ReflectionProperty(NameFixingService::class, 'predbMatchSelector'))->setValue($service, new PredbMatchSelector);
        Search::shouldReceive('searchPredb')->with(self::TITLE)
            ->andReturn([['id' => 7, 'title' => self::TITLE, 'filename' => '']]);
        Search::shouldReceive('searchPredbFilenameCandidates')->with(self::TITLE)
            ->andReturn([['id' => 7, 'title' => self::TITLE, 'filename' => '']]);

        return [$service, $updater];
    }
}
