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
use App\Support\Par2DescriptorEvidence;
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
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('par2_rename_progress', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
        });
        $schema->create('releases', function (Blueprint $table): void {
            $table->integer('id')->primary();
            foreach (['groups_id', 'categories_id', 'isrenamed', 'iscategorized', 'predb_id', 'proc_par2', 'proc_hash16k', 'rarinnerfilecount', 'videos_id', 'tv_episodes_id', 'tv_lookup_attempts', 'book_lookup_attempts'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['guid', 'name', 'searchname', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid', 'tv_lookup_retry_at', 'book_lookup_retry_at', 'book_name_normalized_at'] as $column) {
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
        (require base_path('database/migrations/2026_10_12_220000_create_par_file_hash_inventories_table.php'))->up();
        (require base_path('database/migrations/2026_10_15_190000_create_par2_descriptor_evidence_table.php'))->up();
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

    #[DataProvider('descriptorBatchSizes')]
    public function test_descriptor_sql_round_trips_are_bounded_by_chunks_and_replay(int $count): void
    {
        $this->seedRelease(42);
        $snapshot = $this->snapshot();
        $inventory = $this->inventory($count);
        $connection = $this->database->getConnection();
        $chunks = (int) ceil($count / 25);
        $connection->enableQueryLog();
        foreach ([false, true] as $replay) {
            $connection->flushQueryLog();
            $this->assertTrue(Par2DescriptorEvidence::remember($inventory, $snapshot));
            $queries = $connection->getQueryLog();
            $reads = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'select * from "par2_descriptor_evidence"')));
            $inserts = array_values(array_filter($queries, static fn (array $query): bool => str_starts_with($query['query'], 'insert into "par2_descriptor_evidence"')));
            $this->assertCount($chunks, $reads);
            $this->assertCount($replay ? 0 : $chunks, $inserts);
            $this->assertCount(3 + ($replay ? 2 : 3) * $chunks, $queries);
            foreach ($reads as $query) {
                $this->assertStringContainsString('"release_guid" = ?', $query['query']);
                $this->assertStringContainsString('HEX(release_guid) = ?', $query['query']);
                $this->assertStringContainsString('"packet_checksum" in (', $query['query']);
                $this->assertLessThanOrEqual(28, count($query['bindings']));
            }
            foreach ($inserts as $query) {
                $this->assertLessThanOrEqual(25 * 13, count($query['bindings']));
            }
        }
        $connection->disableQueryLog();
        $this->assertSame($count, $connection->table('par2_descriptor_evidence')->count());
    }

    /** @return iterable<string, array{int}> */
    public static function descriptorBatchSizes(): iterable
    {
        foreach ([0, 1, 25, 26, 51] as $count) {
            yield (string) $count => [$count];
        }
    }

    #[DataProvider('descriptorAbortCauses')]
    public function test_descriptor_batch_abort_rolls_back_prior_chunks_and_stale_guid_cleanup(string $cause): void
    {
        $this->seedRelease(42);
        $connection = $this->database->getConnection();
        $this->assertTrue(Par2DescriptorEvidence::remember($this->inventory(), $this->snapshot()));
        $before = $connection->table('par2_descriptor_evidence')->get()->all();
        $connection->table('releases')->where('id', 42)->update(['guid' => 'replacement-guid']);
        $snapshot = $this->snapshot();
        $insertSizes = [];
        $owned = true;
        $connection->setEventDispatcher(new Dispatcher);
        $connection->listen(function ($event) use ($connection, $cause, &$insertSizes, &$owned): void {
            if (! str_starts_with($event->sql, 'insert into "par2_descriptor_evidence"')) {
                return;
            }
            $insertSizes[] = count($event->bindings) / 13;
            if (count($insertSizes) === 2) {
                if ($cause === 'guard') {
                    $owned = false;
                } else {
                    $connection->table('releases')->where('id', 42)->update(['name' => 'changed during append']);
                }
            }
        });
        $this->assertFalse(Par2DescriptorEvidence::remember($this->inventory(51), $snapshot, static function () use (&$owned): bool {
            return $owned;
        }));
        $this->assertSame([25, 25], $insertSizes);
        $this->assertEquals($before, $connection->table('par2_descriptor_evidence')->get()->all());
        $this->assertSame($snapshot, $this->snapshot());
    }

    /** @return iterable<string, array{string}> */
    public static function descriptorAbortCauses(): iterable
    {
        yield 'ownership loss' => ['guard'];
        yield 'snapshot change' => ['snapshot'];
    }

    #[DataProvider('descriptorChecksumReuse')]
    public function test_descriptor_checksum_reuse_is_checked_within_and_across_chunks(int $offset, bool $collision): void
    {
        $this->seedRelease(42);
        $connection = $this->database->getConnection();
        $this->assertTrue(Par2DescriptorEvidence::remember($this->inventory(), $this->snapshot()));
        $before = $connection->table('par2_descriptor_evidence')->get()->all();
        $connection->table('releases')->where('id', 42)->update(['guid' => 'replacement-guid']);
        $inventory = $this->inventory(26);
        // Fault injection bypasses accumulator validation to exercise checksum collision defence.
        $packetsProperty = new \ReflectionProperty(Par2PacketAccumulator::class, 'packets');
        $packets = $packetsProperty->getValue($inventory);
        $descriptors = array_keys(array_filter($packets, static fn (string $packet): bool => substr($packet, 48, 16) === Par2Info::PACKET_FILEDESC));
        $first = $descriptors[0];
        $target = $descriptors[$offset];
        $packets[$target] = $collision
            ? substr_replace($packets[$target], substr($packets[$first], 16, 16), 16, 16)
            : $packets[$first];
        $packetsProperty->setValue($inventory, $packets);
        if ($collision) {
            try {
                Par2DescriptorEvidence::remember($inventory, $this->snapshot());
                $this->fail('A reused checksum with different descriptor bytes was accepted');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('checksum identity', $error->getMessage());
            }
            $this->assertEquals($before, $connection->table('par2_descriptor_evidence')->get()->all());
        } else {
            $this->assertTrue(Par2DescriptorEvidence::remember($inventory, $this->snapshot()));
            $this->assertSame(25, $connection->table('par2_descriptor_evidence')->count());
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function descriptorChecksumReuse(): iterable
    {
        yield 'same chunk collision' => [1, true];
        yield 'cross chunk collision' => [25, true];
        yield 'same chunk exact replay' => [1, false];
        yield 'cross chunk exact replay' => [25, false];
    }

    public function test_descriptor_evidence_persists_binary_padded_aliases_without_optional_collection_and_replays(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $inventory = new Par2PacketAccumulator;
        $set = str_repeat('s', 16);
        foreach (['i', 'j'] as $id) {
            $body = str_repeat($id, 16).md5('content', true).md5('prefix', true).pack('V2', 0xFFFFFFFE, 0xFFFFFFFF)."raw\xff\0name \t";
            $inventory->append($this->packet(Par2Info::PACKET_FILEDESC, $body, $set));
        }
        $processor = $this->processor(new Par2Info, false);
        $processor->applyInventoryForRename($inventory, 42, 1, 0, expectedSnapshot: $this->snapshot());
        $processor->applyInventoryForRename($inventory, 42, 1, 0, expectedSnapshot: $this->snapshot());

        $rows = $this->database->getConnection()->table('par2_descriptor_evidence')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $index => $row) {
            $descriptor = $inventory->descriptors()[$index];
            $this->assertSame('synthetic-release-42', $row->release_guid);
            $this->assertSame(bin2hex($set), $row->recovery_set_id);
            $this->assertSame($descriptor['file_id'], $row->file_id);
            $this->assertSame($descriptor['packet_checksum'], $row->packet_checksum);
            $this->assertSame(md5('content'), $row->full_md5);
            $this->assertSame(md5('prefix'), $row->hash16k);
            $this->assertSame('18446744073709551614', $row->file_size);
            $this->assertSame(base64_encode("raw\xff\0name \t\0"), $row->raw_filename_base64);
            $this->assertSame('unknown', $row->classification);
            $this->assertSame(0, $row->classification_version);
            $this->assertSame('unclassified', $row->classification_provenance);
            $this->assertSame(1, $row->descriptor_proof_version);
        }
        $this->assertSame(0, $this->database->getConnection()->table('release_files')->count());
    }

    #[DataProvider('replacementDescriptorGuids')]
    public function test_descriptor_evidence_replaces_stale_guid_and_release_delete_cascades(string $guid): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $processor = $this->processor(new Par2Info);
        $processor->applyInventoryForRename($this->inventory(), 42, 1, 0);
        $this->assertSame(1, $this->database->getConnection()->table('par2_descriptor_evidence')->count());
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['guid' => $guid]);
        $processor->applyInventoryForRename($this->inventory(2), 42, 1, 0);

        $this->assertSame([$guid, $guid], $this->database->getConnection()->table('par2_descriptor_evidence')->pluck('release_guid')->all());
        $this->database->getConnection()->table('releases')->where('id', 42)->delete();
        $this->assertSame(0, $this->database->getConnection()->table('par2_descriptor_evidence')->count());
    }

    /** @return array<string, array{string}> */
    public static function replacementDescriptorGuids(): array
    {
        return ['replacement' => ['replacement-guid'], 'case-only replacement' => ['SYNTHETIC-RELEASE-42']];
    }

    public function test_descriptor_evidence_ownership_loss_rolls_back_append_and_stale_guid_cleanup(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $processor = $this->processor(new Par2Info);
        $processor->applyInventoryForRename($this->inventory(), 42, 1, 0);
        $before = $this->database->getConnection()->table('par2_descriptor_evidence')->get()->all();
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['guid' => 'replacement-guid']);
        $owned = true;
        $this->database->getConnection()->setEventDispatcher(new Dispatcher);
        $this->database->getConnection()->listen(static function ($event) use (&$owned): void {
            if (str_starts_with($event->sql, 'insert into "par2_descriptor_evidence"')) {
                $owned = false;
            }
        });
        $outcome = $processor->applyInventoryForRename($this->inventory(2), 42, 1, 0, static function () use (&$owned): bool {
            return $owned;
        });

        $this->assertFalse($owned);
        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertEquals($before, $this->database->getConnection()->table('par2_descriptor_evidence')->get()->all());
    }

    public function test_descriptor_checksum_identity_collision_fails_without_overwriting_evidence(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $processor = $this->processor(new Par2Info);
        $processor->applyInventoryForRename($this->inventory(), 42, 1, 0);
        $this->assertSame(1, $this->database->getConnection()->table('par2_descriptor_evidence')->count());
        $this->database->getConnection()->table('par2_descriptor_evidence')->update(['raw_filename_base64' => base64_encode('different bytes')]);
        $before = $this->database->getConnection()->table('par2_descriptor_evidence')->get()->all();
        try {
            $processor->applyInventoryForRename($this->inventory(), 42, 1, 0);
            $this->fail('Checksum collision or stored evidence corruption was ignored');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('descriptor', $error->getMessage());
            $this->assertEquals($before, $this->database->getConnection()->table('par2_descriptor_evidence')->get()->all());
        }
    }

    public function test_descriptor_evidence_preserves_names_larger_than_a_mysql_text_column(): void
    {
        $this->seedRelease(42);
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['categories_id' => 2000]);
        $filename = str_repeat("x\xff", 40000)."\0\0";
        $body = str_repeat('i', 16).md5('content', true).md5('prefix', true).pack('V2', 10, 0).$filename;
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->packet(Par2Info::PACKET_FILEDESC, $body, str_repeat('s', 16)));
        $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0);

        $stored = $this->database->getConnection()->table('par2_descriptor_evidence')->value('raw_filename_base64');
        $this->assertGreaterThan(65535, strlen($stored));
        $this->assertSame($filename."\0\0", base64_decode($stored, true));
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

    /** @return array{id:int,guid:string,name:?string,searchname:?string,predb_id:int,isrenamed:int} */
    private function snapshot(): array
    {
        $row = (array) $this->database->getConnection()->table('releases')->where('id', 42)->first();

        return array_intersect_key($row, array_flip(['id', 'guid', 'name', 'searchname', 'predb_id', 'isrenamed']));
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
