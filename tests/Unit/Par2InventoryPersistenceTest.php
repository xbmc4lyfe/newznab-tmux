<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Par2RenameOutcome;
use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Par2InventoryPersistenceTest extends TestCase
{
    /** Observed catalogue title; the PAR2 bytes below are synthetic metadata, not downloaded media. */
    private const string TITLE = 'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR';

    private const int LENGTH = 12 * 1024 * 1024 * 1024 + 123;

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
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository(['nntmux' => ['echocli' => false]]));
        $container->instance('date', new DateFactory);
        $container->instance('events', new Dispatcher($container));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('par2_rename_progress', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
        });
        $schema->create('releases', function (Blueprint $table): void {
            $table->integer('id')->primary();
            foreach (['groups_id', 'categories_id', 'isrenamed', 'iscategorized', 'predb_id', 'proc_par2', 'proc_hash16k', 'rarinnerfilecount', 'videos_id', 'tv_episodes_id', 'book_lookup_attempts'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['guid', 'name', 'searchname', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid', 'book_lookup_retry_at', 'book_name_normalized_at'] as $column) {
                $table->string($column)->nullable();
            }
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
        });
        $schema->create('release_files', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('name');
            $table->bigInteger('size');
            $table->string('created_at');
            $table->string('updated_at');
            $table->integer('passworded');
            $table->string('crc32');
            $table->unique(['releases_id', 'name']);
        });
        $schema->create('par_hashes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('hash');
            $table->unique(['releases_id', 'hash']);
        });
        $container->instance('db.schema', $schema);
        (require base_path('database/migrations/2026_10_12_010315_create_par_file_hash_evidence_tables.php'))->up();
        Search::swap(new class
        {
            public function updateRelease(int $id): void {}
        });
        $schema->create('predb', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title')->unique();
        });
        $schema->create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $this->database->getConnection()->table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.binaries.test']);
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

    public function test_complete_inventory_replay_does_not_double_count(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->syntheticMetadata(self::TITLE.'.mkv'));
        $processor = $this->processor(new Par2Info);
        $processor->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true);
        $processor->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true);
        $this->assertSame(1, $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount'));
    }

    public function test_snapshot_mutation_after_read_prevents_count(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $row = (array) $this->database->getConnection()->table('releases')->where('id', 42)->first();
        $snapshot = array_intersect_key($row, array_flip(['id', 'guid', 'name', 'searchname']));
        $calls = 0;
        $guard = function () use (&$calls): bool {
            if (++$calls === 2) {
                $this->database->getConnection()->table('releases')->where('id', 42)->update(['guid' => 'replacement-guid']);
            }

            return true;
        };
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->syntheticMetadata(self::TITLE.'.mkv'));
        $outcome = $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0, $guard, expectedSnapshot: $snapshot);
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount'));
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
    }

    public function test_claim_lost_during_count_rolls_back(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $owned = true;
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(function ($event) use (&$owned): void {
            if (str_contains($event->sql, 'update "releases" set "rarinnerfilecount"')) {
                $owned = false;
            }
        });
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->syntheticMetadata(self::TITLE.'.mkv'));
        $outcome = $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0, static function () use (&$owned): bool {
            return $owned;
        });
        $this->assertFalse($owned);
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount'));
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
    }

    #[DataProvider('changedSnapshots')]
    public function test_collection_rejects_snapshot_change_after_initial_read(string $column, int|string|null $value): void
    {
        $this->seedRelease(42);
        $snapshot = $this->snapshot();
        $calls = 0;
        $guard = function () use (&$calls, $column, $value): bool {
            if (++$calls === 2) {
                $this->database->getConnection()->table('releases')->where('id', 42)->update([$column => $value]);
            }

            return true;
        };
        $outcome = $this->processor(new Par2Info, true)->applyInventoryForRename($this->inventory(), 42, 1, 0, $guard, expectedSnapshot: $snapshot);
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame(0, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
        $this->assertSame(0, $this->countFiles());
    }

    /** @return iterable<string, array{string, int|string|null}> */
    public static function changedSnapshots(): iterable
    {
        yield 'guid' => ['guid', 'replacement'];
        yield 'case-only name' => ['name', '7AE60EE3D802BFD4388B0F2C6E982E7A'];
        yield 'searchname' => ['searchname', null];
        yield 'renamed' => ['isrenamed', 1];
        yield 'linked' => ['predb_id', 99];
        yield 'processed' => ['proc_par2', 1];
    }

    public function test_snapshot_change_during_file_insert_rolls_back_all_metadata(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(function ($event): void {
            if (str_starts_with($event->sql, 'insert or ignore into "release_files"')) {
                $this->database->getConnection()->table('releases')->where('id', 42)->update(['predb_id' => 99]);
            }
        });
        $outcome = $this->processor(new Par2Info, true)->applyInventoryForRename($this->inventory(), 42, 1, 0, static fn (): bool => true, expectedSnapshot: $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame(0, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
        $this->assertSame(0, $this->countFiles());
    }

    public function test_uncollected_count_preserves_higher_existing_count(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000, 'rarinnerfilecount' => 12]);
        $this->processor(new Par2Info)->applyInventoryForRename($this->inventory(), 42, 1, 0, static fn (): bool => true);
        $this->assertSame(12, $this->countFiles());
    }

    public function test_all_bounded_metadata_is_collected_before_successful_first_name(): void
    {
        $this->seedRelease(42);
        $outcome = $this->processor(new Par2Info, true)->applyInventoryForRename($this->inventory(3), 42, 1, 0, static fn (): bool => true, expectedSnapshot: $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertSame(3, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(3, $this->countFiles());
        $this->assertSame(self::TITLE, $this->database->getConnection()->table('releases')->where('id', 42)->value('searchname'));
    }

    public function test_collection_is_bounded_and_idempotent_on_replay(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $inventory = $this->inventory(24);
        $processor = $this->processor(new Par2Info, true);
        $processor->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true);
        $processor->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true);
        $this->assertSame(21, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(21, $this->countFiles());
    }

    public function test_incomplete_tail_does_not_prevent_early_name_or_collected_descriptors(): void
    {
        $this->seedRelease(42);
        $inventory = $this->inventory(2);
        $inventory->append('PAR2');
        $this->assertFalse($inventory->isComplete());
        $outcome = $this->processor(new Par2Info, true)->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true, false, $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertSame(2, $this->database->getConnection()->table('release_files')->count());
    }

    public function test_missing_descriptors_do_not_invent_an_uncollected_count(): void
    {
        $this->seedRelease(42);
        $data = $this->syntheticMetadata(self::TITLE.'.mkv');
        $firstLength = unpack('V', substr($data, 8, 4))[1];
        $inventory = new Par2PacketAccumulator;
        $inventory->append(substr($data, 0, $firstLength));
        $outcome = $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true);
        $this->assertSame(Par2RenameOutcome::Retryable, $outcome);
        $this->assertSame(0, $this->countFiles());
    }

    public function test_claim_loss_during_successful_name_rolls_back_name_and_metadata(): void
    {
        $this->seedRelease(42);
        $snapshot = $this->snapshot();
        $owned = true;
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(static function ($event) use (&$owned): void {
            if (str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"searchname"') && str_contains($event->sql, '"isrenamed"')) {
                $owned = false;
            }
        });
        $outcome = $this->processor(new Par2Info, true)->applyInventoryForRename($this->inventory(2), 42, 1, 0, static function () use (&$owned): bool {
            return $owned;
        }, expectedSnapshot: $snapshot);
        $this->assertFalse($owned);
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertSame(0, $this->countFiles());
        $this->assertSame(0, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
    }

    public function test_rolled_back_name_publishes_no_search_sync_or_name_fixed_event(): void
    {
        $this->seedRelease(42);
        $owned = true;
        $synchronized = [];
        $events = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$synchronized): void {
            $synchronized[] = $id;
        });
        Container::getInstance()->make('events')->listen(ReleaseNameFixed::class, static function ($event) use (&$events): void {
            $events[] = $event->releaseId;
        });
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $processor = new Par2Processor(new NameFixingService($updates), new Par2Info, true, false);
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(static function ($event) use (&$owned): void {
            if (str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"searchname"') && str_contains($event->sql, '"isrenamed"')) {
                $owned = false;
            }
        });
        $outcome = $processor->applyInventoryForRename($this->inventory(), 42, 1, 0, static function () use (&$owned): bool {
            return $owned;
        }, expectedSnapshot: $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame([[], []], [$synchronized, $events]);
    }

    public function test_successful_inventory_publishes_name_and_search_only_after_outer_commit(): void
    {
        $this->seedRelease(42);
        $published = [];
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, function (int $id) use (&$published): void {
            $published[] = ['search', $id, $this->database->getConnection()->transactionLevel(), $this->countFiles()];
        });
        Container::getInstance()->make('events')->listen(ReleaseNameFixed::class, function ($event) use (&$published): void {
            $published[] = ['event', $event->releaseId, $this->database->getConnection()->transactionLevel(), $this->countFiles()];
        });
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $processor = new Par2Processor(new NameFixingService($updates), new Par2Info, true, false);
        $outcome = $processor->applyInventoryForRename($this->inventory(3), 42, 1, 0, static fn (): bool => true, expectedSnapshot: $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Renamed, $outcome);
        $this->assertSame([['event', 42, 0, 3], ['search', 42, 0, 3]], $published);
    }

    public function test_rollback_preserves_metadata_committed_by_an_earlier_inventory_call(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $processor = $this->processor(new Par2Info, true);
        $processor->applyInventoryForRename($this->inventory(), 42, 1, 0, static fn (): bool => true);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => Category::OTHER_MISC]);
        $owned = true;
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(static function ($event) use (&$owned): void {
            if (str_starts_with($event->sql, 'update "releases"') && str_contains($event->sql, '"searchname"') && str_contains($event->sql, '"isrenamed"')) {
                $owned = false;
            }
        });
        $outcome = $processor->applyInventoryForRename($this->inventory(2), 42, 1, 0, static function () use (&$owned): bool {
            return $owned;
        }, expectedSnapshot: $this->snapshot());
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame(1, $this->countFiles());
        $this->assertSame(1, $this->database->getConnection()->table('release_files')->count());
    }

    private function inventory(int $files = 1): Par2PacketAccumulator
    {
        $inventory = new Par2PacketAccumulator;
        for ($index = 0; $index < $files; $index++) {
            $inventory->append($this->syntheticMetadata(self::TITLE.($index === 0 ? '' : '.'.$index).'.mkv'));
        }

        return $inventory;
    }

    /** @return array{id:int,guid:string,name:?string,searchname:?string} */
    private function snapshot(): array
    {
        $row = (array) $this->database->getConnection()->table('releases')->where('id', 42)->first();

        return array_intersect_key($row, array_flip(['id', 'guid', 'name', 'searchname']));
    }

    private function countFiles(): int
    {
        return (int) $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount');
    }

    private function seedRelease(int $id): void
    {
        $this->database->getConnection()->table('releases')->insert([
            'id' => $id, 'guid' => 'synthetic-release-'.$id, 'groups_id' => 1, 'categories_id' => Category::OTHER_MISC,
            'name' => '7ae60ee3d802bfd4388b0f2c6e982e7a',
            'searchname' => '7ae60ee3d802bfd4388b0f2c6e982e7a',
            'postdate' => '2026-10-11 00:00:00',
        ]);
    }

    private function processor(Par2Info $info, bool $collect = false): Par2Processor
    {
        $category = $this->createStub(CategorizationService::class);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $releaseId): void {});
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);

        return new Par2Processor(new NameFixingService($updates), $info, $collect, false);
    }

    /** Metadata-only high-word length; the tiny synthetic content is not a real 12 GiB media payload. */
    private function syntheticMetadata(string $filename): string
    {
        $content = 'synthetic metadata fixture';
        $length = pack('V2', self::LENGTH & 0xFFFFFFFF, intdiv(self::LENGTH, 0x100000000));
        $hash16 = md5($content, true);
        $fileId = md5($hash16.$length.$filename, true);
        $main = pack('V2', 16384, 0).pack('V', 1).$fileId;
        $setId = md5($main, true);
        $description = $fileId.md5($content, true).$hash16.$length.$filename;
        $verification = $fileId.md5(str_pad($content, 16384, "\0"), true).pack('V', crc32(str_pad($content, 16384, "\0")));

        return $this->packet(Par2Info::PACKET_MAIN, $main, $setId)
            .$this->packet(Par2Info::PACKET_FILEVER, $verification, $setId)
            .$this->packet(Par2Info::PACKET_FILEDESC, $description, $setId)
            .$this->packet(Par2Info::PACKET_CREATOR, 'Synthetic offline fixture', $setId);
    }

    private function packet(string $type, string $body, string $setId): string
    {
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = $setId.$type.$body;

        return Par2Info::PACKET_MARKER.pack('V2', 64 + strlen($body), 0).md5($hashed, true).$hashed;
    }
}
