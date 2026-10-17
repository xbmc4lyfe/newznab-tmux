<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NzbImportStatus;
use App\Services\NfoService;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PDO;
use Psr\Log\LoggerInterface;
use Tests\Support\CapturedUploadFixture;
use Tests\TestCase;

final class NfoImportServiceTest extends TestCase
{
    use CapturedUploadFixture;

    private string $databasePath;

    private string $uploadFolder;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-nfo-import-test.sqlite';
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
            'nntmux.tmp_unrar_path' => sys_get_temp_dir().'/nntmux-nfo-import-tmp',
        ]);
        DB::purge();
        DB::reconnect();

        Schema::dropAllTables();
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid')->unique();
            $table->integer('nfostatus')->default(-1);
        });
        Schema::create('release_nfos', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->binary('nfo')->nullable();
        });

        $this->uploadFolder = sys_get_temp_dir().'/nntmux-nfo-import-'.bin2hex(random_bytes(6));
        (new Filesystem)->makeDirectory($this->uploadFolder, 0775, true);
        config(['release_verification.required' => true, 'nntmux.release_dedupe_enabled' => true, 'nntmux.echocli' => false,
            'release_verification.storage_path' => $this->uploadFolder.'/verification']);
        (require database_path('migrations/2026_10_11_154028_create_release_verification_candidates_table.php'))->up();
        (require database_path('migrations/2026_10_17_220000_add_header_check_pending_to_release_verification_candidates.php'))->up();
        (require database_path('migrations/2026_10_15_034000_create_nfo_cleanup_commits_table.php'))->up();
        Schema::create('usenet_groups', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        DB::table('usenet_groups')->insert(['id' => 1, 'name' => 'alt.test']);

    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->uploadFolder);
        (new Filesystem)->deleteDirectory(sys_get_temp_dir().'/nntmux-nfo-import-tmp');

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_it_replaces_an_existing_nfo_for_the_manifest_release(): void
    {
        $guid = '11111111-1111-4111-8111-111111111111';
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'nfostatus' => -1]);
        DB::table('release_nfos')->insert([
            'releases_id' => 1,
            'nfo' => "\x1f\x8b\x08\x00".gzcompress('old information'),
        ]);

        [$manifestPath, $nfoPath, $manifests] = $this->makeResolvedUpload($guid);
        $service = new NfoImportService(new Filesystem, $manifests, new NfoService);

        $result = $service->importManifest($manifestPath);

        $this->assertSame('imported', $result['status']);
        $stored = DB::table('release_nfos')->where('releases_id', 1)->value('nfo');
        $this->assertIsString($stored);
        $this->assertSame('new release information', gzuncompress(substr($stored, 4)));
        $this->assertSame(1, DB::table('releases')->where('id', 1)->value('nfostatus'));
        $this->assertSame(NzbUploadManifestService::STATE_NFO_IMPORTED, $manifests->read($manifestPath)['state']);
        $this->assertFileExists($nfoPath);
    }

    public function test_nfo_import_log_channel_is_available(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, Log::channel('nfo_import'));
    }

    public function test_it_deletes_a_successfully_imported_nfo_when_requested(): void
    {
        $guid = '22222222-2222-4222-8222-222222222222';
        DB::table('releases')->insert(['id' => 1, 'guid' => $guid, 'nfostatus' => -1]);
        [$manifestPath, $nfoPath, $manifests] = $this->makeResolvedUpload($guid);

        $result = (new NfoImportService(new Filesystem, $manifests, new NfoService))
            ->importManifest($manifestPath, delete: true);

        $this->assertSame('imported', $result['status']);
        $this->assertFileDoesNotExist($nfoPath);
        $this->assertFileExists($manifestPath);
    }

    public function test_duplicate_nzb_manifest_is_not_linked_and_can_delete_its_nfo(): void
    {
        $filesystem = new Filesystem;
        $manifests = new NzbUploadManifestService($filesystem);
        $directory = $this->uploadFolder.'/duplicate';
        $filesystem->makeDirectory($directory, 0775, true);
        $filesystem->put($directory.'/Release.nzb', '<nzb><file subject="Fixture"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@local.test</segment></segments></file></nzb>');
        $filesystem->put($directory.'/different.nfo', 'duplicate release information');
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $manifests->create($directory, 'duplicate', 'Release.nzb', 'different.nfo', 'duplicate release information');
        (new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Duplicate;
            }
        })->beginImport([$directory.'/Release.nzb']);

        $result = (new NfoImportService($filesystem, $manifests, new NfoService))
            ->importManifest($manifestPath, deleteFailed: true);

        $this->assertSame('skipped', $result['status']);
        $this->assertFileDoesNotExist($directory.'/different.nfo');
        $this->assertSame(NzbUploadManifestService::STATE_NZB_DUPLICATE, $manifests->read($manifestPath)['state']);
    }

    public function test_it_rejects_a_manifest_path_that_escapes_the_upload_directory(): void
    {
        $filesystem = new Filesystem;
        $directory = $this->uploadFolder.'/unsafe';
        $filesystem->makeDirectory($directory, 0775, true);
        $outsidePath = $this->uploadFolder.'/outside.nfo';
        $filesystem->put($outsidePath, 'outside information');
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $filesystem->put($manifestPath, json_encode([
            'version' => 1,
            'upload_id' => 'unsafe',
            'state' => NzbUploadManifestService::STATE_NZB_IMPORTED,
            'nzb' => ['filename' => 'Release.nzb'],
            'nfo' => ['filename' => '../outside.nfo'],
            'release_id' => 1,
            'release_guid' => 'unsafe-guid',
        ], JSON_THROW_ON_ERROR));

        $manifests = new NzbUploadManifestService($filesystem);
        $result = (new NfoImportService($filesystem, $manifests, new NfoService))
            ->importManifest($manifestPath, deleteFailed: true);

        $this->assertSame('failed', $result['status']);
        $this->assertFileExists($outsidePath);
    }

    /**
     * @return array{string,string,NzbUploadManifestService}
     */
    private function makeResolvedUpload(string $guid): array
    {
        $filesystem = new Filesystem;
        $manifests = new NzbUploadManifestService($filesystem);
        $directory = $this->uploadFolder.'/resolved';
        $filesystem->makeDirectory($directory, 0775, true);
        $nzbPath = $directory.'/Release.nzb';
        $nfoPath = $directory.'/different.nfo';
        $filesystem->put($nzbPath, '<nzb><file subject="Fixture"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">fixture@local.test</segment></segments></file></nzb>');
        $filesystem->put($nfoPath, 'new release information');
        $manifestPath = $directory.'/'.NzbUploadManifestService::FILENAME;
        $manifests->create($directory, 'resolved', 'Release.nzb', 'different.nfo', 'new release information');
        $this->capturedFixtureImporter('nfo-resolved')->beginImport([$nzbPath]);
        DB::table('release_verification_candidates')->where('identity', sha1('import:nfo-resolved'))->update(['status' => 'complete', 'release_id' => 1, 'verified_at' => now()]);

        return [$manifestPath, $nfoPath, $manifests];
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
