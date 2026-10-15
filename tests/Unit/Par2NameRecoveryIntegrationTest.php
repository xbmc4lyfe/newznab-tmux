<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Par2RenameOutcome;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Par2FileList;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Support\ReleasePackEvidence;
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

final class Par2NameRecoveryIntegrationTest extends TestCase
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
            $table->integer('nzbstatus')->default(NzbService::NZB_NONE);
            $table->json('additional_pack_evidence')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
            foreach (['groups_id', 'categories_id', 'isrenamed', 'iscategorized', 'predb_id', 'proc_par2', 'proc_hash16k', 'rarinnerfilecount', 'videos_id', 'tv_episodes_id', 'tv_lookup_attempts', 'book_lookup_attempts'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['guid', 'name', 'searchname', 'imdbid', 'musicinfo_id', 'consoleinfo_id', 'bookinfo_id', 'anidbid', 'tv_lookup_retry_at', 'book_lookup_retry_at', 'book_name_normalized_at'] as $column) {
                $table->string($column)->nullable();
            }
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
        });
        $schema->create('predb', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title')->unique();
        });
        $schema->create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $schema->create('par_hashes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });
        $container->instance('db.schema', $schema);
        (require base_path('database/migrations/2026_10_12_010315_create_par_file_hash_evidence_tables.php'))->up();
        (require base_path('database/migrations/2026_10_12_220000_create_par_file_hash_inventories_table.php'))->up();
        (require base_path('database/migrations/2026_10_15_190000_create_par2_descriptor_evidence_table.php'))->up();
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

    public function test_synthetic_par2_recovers_a_hashed_release_before_exact_catalogue_linking(): void
    {
        $this->seedRelease(42);
        $info = new Par2Info;
        $processor = $this->processor($info);

        $this->assertTrue($processor->parseFromMessage('synthetic@example.test', 42, 1, $this->transport(self::TITLE), 0));
        $recovered = $this->database->getConnection()->table('releases')->where('id', 42)->first();
        $this->assertNotNull($recovered);
        $this->assertSame(self::TITLE, $recovered->searchname);
        $this->assertSame(1, $recovered->isrenamed);
        $this->assertSame(1, $recovered->proc_par2);
        $this->assertSame(0, $recovered->predb_id);
        $files = array_values((new Par2FileList)->getFiles($info));
        $this->assertSame(self::LENGTH, $files[0]['size']);
        $this->assertSame(self::TITLE.'.mkv', $files[0]['name']);

        $this->database->getConnection()->table('predb')->insert([
            ['id' => 7, 'title' => str_replace('.2010.', '.2011.', self::TITLE)],
            ['id' => 8, 'title' => str_replace('-faBR', '-OTHER', self::TITLE)],
        ]);
        $this->checkPreWithoutOutput();
        $this->assertSame(0, $this->predbId(42));

        $this->database->getConnection()->table('predb')->insert(['id' => 9, 'title' => self::TITLE]);
        $this->checkPreWithoutOutput();
        $this->assertSame(9, $this->predbId(42));
        $this->assertSame(self::TITLE, $this->database->getConnection()->table('releases')->where('id', 42)->value('searchname'));
    }

    public function test_exact_catalogue_linker_preserves_an_existing_link_after_par2_recovery(): void
    {
        $this->seedRelease(42);
        $processor = $this->processor(new Par2Info);
        $this->assertTrue($processor->parseFromMessage('synthetic@example.test', 42, 1, $this->transport(self::TITLE), 0));
        $this->database->getConnection()->table('releases')->where('id', 42)->update(['predb_id' => 88]);
        $this->database->getConnection()->table('predb')->insert(['id' => 9, 'title' => self::TITLE]);

        $this->checkPreWithoutOutput();

        $this->assertSame(88, $this->predbId(42));
    }

    public function test_resumed_metadata_inventory_recovers_the_literal_large_release_name(): void
    {
        $this->seedRelease(42);
        $data = $this->syntheticMetadata(self::TITLE.'.mkv');
        $firstLength = unpack('V', substr($data, 8, 4))[1];
        $inventory = new Par2PacketAccumulator;
        $inventory->append(substr($data, 0, $firstLength));
        $processor = $this->processor(new Par2Info);

        $this->assertSame(Par2RenameOutcome::Retryable, $processor->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true));
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 42)->value('proc_par2'));
        $this->assertSame(0, $this->database->getConnection()->table('par_file_hashes')->count());
        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
        $resumed = Par2PacketAccumulator::fromState($inventory->exportState());
        $resumed->append(substr($data, $firstLength));
        $this->assertTrue($resumed->isComplete());
        $this->assertSame(Par2RenameOutcome::Renamed, $processor->applyInventoryForRename($resumed, 42, 1, 0, static fn (): bool => true));
        $this->assertSame(self::TITLE, $this->database->getConnection()->table('releases')->where('id', 42)->value('searchname'));
        $files = array_values(array_values($resumed->filesBySet())[0]);
        $this->assertSame(self::LENGTH, $files[0]['size']);
        $stored = $this->database->getConnection()->table('par_file_hashes')->first();
        $this->assertNotNull($stored);
        $this->assertSame('synthetic-release-42', $stored->release_guid);
        $this->assertSame((string) self::LENGTH, $stored->file_size);
        $this->assertSame($files[0]['hash'], $stored->full_md5);
        $this->assertSame($files[0]['hash_16K'], $stored->hash16k);
        $this->assertSame(0, $this->database->getConnection()->table('par2_hash_retry')->count());
    }

    public function test_inventory_application_requires_current_ownership(): void
    {
        $this->seedRelease(42);
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->syntheticMetadata(self::TITLE.'.mkv'));
        $this->assertSame(Par2RenameOutcome::Deferred, $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => false));
        $this->assertSame(0, $this->predbId(42));
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 42)->value('isrenamed'));
    }

    #[DataProvider('inventorySnapshotChanges')]
    public function test_inventory_does_not_apply_to_a_changed_release_snapshot(string $column, int|string|null $value): void
    {
        $this->seedRelease(42);
        $row = (array) $this->database->getConnection()->table('releases')->where('id', 42)->first();
        $snapshot = ['id' => 42, 'guid' => $row['guid'], 'name' => $row['name'], 'searchname' => $row['searchname']];
        $this->database->getConnection()->table('releases')->where('id', 42)->update([$column => $value]);
        $inventory = new Par2PacketAccumulator;
        $inventory->append($this->syntheticMetadata(self::TITLE.'.mkv'));

        $outcome = $this->processor(new Par2Info)->applyInventoryForRename($inventory, 42, 1, 0, static fn (): bool => true, expectedSnapshot: $snapshot);

        $this->assertSame(Par2RenameOutcome::Deferred, $outcome);
        $this->assertSame($value, $this->database->getConnection()->table('releases')->where('id', 42)->value($column));
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount'));
        $this->assertSame(0, $this->database->getConnection()->table('par_file_hashes')->count());
    }

    /** @return array<string, array{string, int|string|null}> */
    public static function inventorySnapshotChanges(): array
    {
        return [
            'different GUID' => ['guid', 'replacement-guid'],
            'case-only subject' => ['name', '7AE60EE3D802BFD4388B0F2C6E982E7A'],
            'different searchname' => ['searchname', 'Other.Release.2026-GROUP'],
            'null searchname' => ['searchname', null],
            'already linked' => ['predb_id', 99],
            'already renamed' => ['isrenamed', 1],
            'already exhausted' => ['proc_par2', 1],
        ];
    }

    private function seedRelease(int $id): void
    {
        $this->database->getConnection()->table('releases')->insert([
            'id' => $id, 'guid' => 'synthetic-release-'.$id, 'groups_id' => 1, 'categories_id' => Category::OTHER_MISC,
            'nzbstatus' => NzbService::NZB_ADDED,
            'additional_pack_evidence' => ReleasePackEvidence::tombstone((object) ['id' => $id, 'guid' => 'synthetic-release-'.$id]),
            'name' => '7ae60ee3d802bfd4388b0f2c6e982e7a',
            'searchname' => '7ae60ee3d802bfd4388b0f2c6e982e7a',
            'postdate' => '2026-10-11 00:00:00',
        ]);
    }

    private function processor(Par2Info $info): Par2Processor
    {
        $category = $this->createStub(CategorizationService::class);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $releaseId): void {});
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);

        return new Par2Processor(new NameFixingService($updates), $info, false, false);
    }

    private function transport(string $title): NNTPService
    {
        $nntp = $this->getMockBuilder(NNTPService::class)->disableOriginalConstructor()
            ->onlyMethods(['getMessages'])->getMock();
        $nntp->expects($this->once())->method('getMessages')->willReturn($this->syntheticMetadata($title.'.mkv'));

        return $nntp;
    }

    private function checkPreWithoutOutput(): void
    {
        ob_start();
        try {
            Predb::checkPre();
        } finally {
            ob_end_clean();
        }
    }

    private function predbId(int $releaseId): int
    {
        return (int) $this->database->getConnection()->table('releases')->where('id', $releaseId)->value('predb_id');
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
