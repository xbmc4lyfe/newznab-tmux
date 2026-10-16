<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Release;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\NzbService;
use App\Services\Par2Processor;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class Par2ProcessorStateTest extends TestCase
{
    private Capsule $database;

    private ?ConnectionResolverInterface $previousResolver;

    private Container $previousContainer;

    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Release::getConnectionResolver();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Application(dirname(__DIR__, 2));
        $container->instance('config', new Repository);
        Container::setInstance($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $this->database->getConnection()->setTransactionManager(new DatabaseTransactionsManager);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $schema = $this->database->getConnection()->getSchemaBuilder();
        $schema->create('par2_rename_progress', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
        });
        $schema->create('releases', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('groups_id');
            $table->integer('categories_id');
            $table->string('guid');
            $table->string('name');
            $table->string('searchname');
            $table->dateTime('postdate')->nullable();
            $table->integer('isrenamed')->default(0);
            $table->integer('predb_id')->default(0);
            $table->integer('proc_par2')->default(0);
            $table->integer('rarinnerfilecount')->default(0);
            $table->integer('nzbstatus')->default(NzbService::NZB_NONE);
            $table->json('additional_pack_evidence')->nullable();
            $table->string('additional_pp_claim_token')->nullable();
            $table->timestamp('additional_pp_claimed_at')->nullable();
        });
        $schema->create('predb', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('title');
        });
        $schema->create('usenet_groups', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('name');
        });
        $schema->create('release_files', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('name');
            $table->integer('size');
            $table->primary(['releases_id', 'name']);
        });
        $schema->create('release_archive_naming_evidence', function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
            $table->string('release_guid', 64);
            $table->string('source_sha256', 64);
            $table->integer('proof_version');
            $table->string('scope', 32);
            $table->text('episode_files');
            $table->dateTime('updated_at');
        });
        $this->database->getConnection()->table('usenet_groups')->insert([
            'id' => 1, 'name' => 'alt.binaries.test',
        ]);
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

    public function test_a_previous_predb_match_does_not_make_unrelated_par2_files_succeed(): void
    {
        $sceneName = 'Example.Show.S01E01.1080p.WEB-DL.x264-GRP';
        $this->database->getConnection()->table('predb')->insert(['id' => 99, 'title' => $sceneName]);
        $this->database->getConnection()->table('releases')->insert([
            ['id' => 123, 'nzbstatus' => NzbService::NZB_ADDED, 'guid' => 'synthetic-release-123', 'groups_id' => 1, 'categories_id' => Category::OTHER_MISC,
                'name' => $sceneName, 'searchname' => $sceneName],
            ['id' => 456, 'nzbstatus' => NzbService::NZB_ADDED, 'guid' => 'synthetic-release-456', 'groups_id' => 1, 'categories_id' => Category::OTHER_MISC,
                'name' => 'unrelated-hash', 'searchname' => 'unrelated-hash'],
        ]);

        $updates = $this->getMockBuilder(ReleaseUpdateService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['checkPreDbMatch', 'updateSingleColumn'])->getMock();
        $updates->expects($this->exactly(2))->method('checkPreDbMatch')
            ->willReturnOnConsecutiveCalls(['id' => 99, 'title' => $sceneName], null);
        $updates->expects($this->never())->method('updateSingleColumn');

        $nameFixing = (new ReflectionClass(NameFixingService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(NameFixingService::class, 'updateService'))->setValue($nameFixing, $updates);
        (new ReflectionProperty(NameFixingService::class, 'fileExtractor'))->setValue($nameFixing, new FileNameExtractor);

        $par2 = $this->createMock(Par2Info::class);
        $par2->expects($this->exactly(2))->method('setData')->willReturn(true);
        $par2->expects($this->exactly(2))->method('getFileList')->willReturnOnConsecutiveCalls(
            [['name' => $sceneName.'.mkv']],
            [['name' => '7ae60ee3d802bfd4388b0f2c6e982e7a.rar']]
        );
        $nntp = $this->getMockBuilder(NNTPService::class)
            ->disableOriginalConstructor()->onlyMethods(['getMessages'])->getMock();
        $nntp->expects($this->exactly(2))->method('getMessages')->willReturn('mock-par2-body');
        $processor = new Par2Processor($nameFixing, $par2, false, false);

        $this->assertTrue($processor->parseFromMessage('first@example', 123, 1, $nntp, 0));
        $this->assertSame(99, $this->database->getConnection()->table('releases')->where('id', 123)->value('predb_id'));
        $this->assertFalse($processor->parseFromMessage('unrelated@example', 456, 1, $nntp, 0));
        $this->assertFalse($updates->matched);
        $this->assertSame(0, $this->database->getConnection()->table('releases')->where('id', 456)->value('predb_id'));
    }

    #[DataProvider('par2CollectionModes')]
    public function test_existing_par2_file_enriches_hash_only_when_collection_is_enabled(bool $enabled, int $hashCount): void
    {
        $schema = $this->database->getConnection()->getSchemaBuilder();

        $schema->create('par_hashes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('hash', 32);
            $table->primary(['releases_id', 'hash']);
        });
        $this->database->getConnection()->table('releases')->insert([
            'id' => 123, 'nzbstatus' => NzbService::NZB_ADDED, 'guid' => 'synthetic-release-123', 'groups_id' => 1, 'categories_id' => 2000,
            'name' => 'known-release', 'searchname' => 'known-release',
            'postdate' => '2026-10-11 00:00:00', 'rarinnerfilecount' => 7,
        ]);
        $this->database->getConnection()->table('release_files')->insert([
            'releases_id' => 123, 'name' => 'existing.mkv', 'size' => 1024,
        ]);
        $hash = '1234567890abcdef1234567890abcdef';
        $par2 = $this->createMock(Par2Info::class);
        $par2->expects($this->once())->method('setData')->willReturn(true);
        $par2->expects($this->once())->method('getFileList')->willReturn([
            ['name' => 'existing.mkv', 'size' => 1024, 'hash_16K' => $hash, 'blocks' => 1],
        ]);
        $nameFixing = $this->createMock(NameFixingService::class);
        $nameFixing->expects($this->never())->method('checkName');
        $nntp = $this->getMockBuilder(NNTPService::class)
            ->disableOriginalConstructor()->onlyMethods(['getMessages'])->getMock();
        $nntp->expects($this->once())->method('getMessages')->willReturn('mock-par2-body');

        $this->assertTrue((new Par2Processor($nameFixing, $par2, $enabled, false))->parseFromMessage('par2@example', 123, 1, $nntp, 0));

        $this->assertSame(1, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(7 + ($enabled ? 0 : 1), $this->database->getConnection()->table('releases')->value('rarinnerfilecount'));
        $this->assertSame($hashCount, $this->database->getConnection()->table('par_hashes')->count());
        if ($enabled) {
            $this->assertSame($hash, $this->database->getConnection()->table('par_hashes')->value('hash'));
        }
    }

    /** @return array<string, array{bool, int}> */
    public static function par2CollectionModes(): array
    {
        return ['enabled' => [true, 1], 'disabled' => [false, 0]];
    }
}
