<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Facades\Search;
use App\Models\Release;
use App\Models\ReleaseFile;
use App\Services\Search\SearchService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseFilePar2HashTest extends TestCase
{
    private const string HASH = '6f5902ac237024bdd0c176cb93063dc4';

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
        $container->instance('config', new Repository);
        $container->instance('date', new DateFactory);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        $this->database = new Capsule($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        $connection = $this->database->getConnection();
        $connection->setTransactionManager(new DatabaseTransactionsManager);
        $schema = $connection->getSchemaBuilder();
        $schema->create('releases', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('rarinnerfilecount')->default(0);
        });
        $schema->create('release_files', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('name');
            $table->integer('size');
            $table->string('created_at');
            $table->integer('updated_at');
            $table->integer('passworded');
            $table->string('crc32')->default('');
            $table->primary(['releases_id', 'name']);
        });
        $schema->create('par_hashes', function (Blueprint $table): void {
            $table->integer('releases_id');
            $table->string('hash');
            $table->primary(['releases_id', 'hash']);
        });
        $connection->table('releases')->insert(['id' => 42, 'rarinnerfilecount' => 7]);
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

    public function test_existing_filename_retains_new_par2_hash_without_changing_file_or_count(): void
    {
        $this->expectSearchUpdates(0);
        $this->seedExistingFile();
        $before = $this->database->getConnection()->table('release_files')->first();

        $inserted = ReleaseFile::addReleaseFiles(42, 'movie.mkv', 999, '2026-10-11 00:00:00', 1, self::HASH, '87654321');

        $this->assertSame(0, $inserted);
        $this->assertSame(1, $this->database->getConnection()->table('release_files')->count());
        $this->assertEquals($before, $this->database->getConnection()->table('release_files')->first());
        $this->assertSame(7, $this->database->getConnection()->table('releases')->where('id', 42)->value('rarinnerfilecount'));
        $this->assertSame(self::HASH, $this->database->getConnection()->table('par_hashes')->where('releases_id', 42)->value('hash'));
    }

    public function test_repeated_existing_filename_hash_is_idempotent(): void
    {
        $this->expectSearchUpdates(0);
        $this->seedExistingFile();

        $first = ReleaseFile::addReleaseFiles(42, 'movie.mkv', 123, '2020-01-01 00:00:00', 0, self::HASH);
        $second = ReleaseFile::addReleaseFiles(42, 'movie.mkv', 123, '2020-01-01 00:00:00', 0, self::HASH);

        $this->assertSame([0, 0], [$first, $second]);

        $this->assertSame(1, $this->database->getConnection()->table('par_hashes')->where('releases_id', 42)->count());
        $this->assertSame(1, $this->database->getConnection()->table('release_files')->count());
    }

    #[DataProvider('invalidHashes')]
    public function test_invalid_hash_is_not_saved_for_new_file(string $hash): void
    {
        $this->expectSearchUpdates(1);

        $this->assertSame(1, ReleaseFile::addReleaseFiles(42, 'movie.mkv', 123, '2020-01-01 00:00:00', 0, $hash));

        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidHashes(): array
    {
        return [
            'empty' => [''],
            'too short' => ['6f5902ac237024bdd0c176cb93063dc'],
            'too long' => ['6f5902ac237024bdd0c176cb93063dc40'],
            'not hexadecimal' => ['zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'],
        ];
    }

    public function test_unknown_release_cannot_create_file_or_hash(): void
    {
        $this->expectSearchUpdates(0);

        $this->assertSame(0, ReleaseFile::addReleaseFiles(999, 'movie.mkv', 123, '2020-01-01 00:00:00', 0, self::HASH));

        $this->assertSame(0, $this->database->getConnection()->table('release_files')->count());
        $this->assertSame(0, $this->database->getConnection()->table('par_hashes')->count());
    }

    public function test_new_file_keeps_hash_and_existing_insertion_contract(): void
    {
        $this->expectSearchUpdates(1);

        $this->assertSame(1, ReleaseFile::addReleaseFiles(42, 'movie.mkv', 123, '2020-01-01 00:00:00', 1, self::HASH, '1234abcd'));

        $file = $this->database->getConnection()->table('release_files')->first();
        $this->assertNotNull($file);
        $this->assertSame('movie.mkv', $file->name);
        $this->assertSame(123, $file->size);
        $this->assertSame('2020-01-01 00:00:00', $file->created_at);
        $this->assertIsInt($file->updated_at);
        $this->assertSame(1, $file->passworded);
        $this->assertSame('1234abcd', $file->crc32);
        $this->assertSame(self::HASH, $this->database->getConnection()->table('par_hashes')->value('hash'));
    }

    private function seedExistingFile(): void
    {
        $this->database->getConnection()->table('release_files')->insert([
            'releases_id' => 42, 'name' => 'movie.mkv', 'size' => 123,
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => 1577836800,
            'passworded' => 0, 'crc32' => '1234abcd',
        ]);
    }

    private function expectSearchUpdates(int $count): void
    {
        $search = $this->getMockBuilder(SearchService::class)->disableOriginalConstructor()
            ->onlyMethods(['updateRelease'])->getMock();
        $search->expects($this->exactly($count))->method('updateRelease')->with(42);
        Search::swap($search);
    }
}
