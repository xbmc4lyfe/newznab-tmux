<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\NzbUploadException;
use App\Services\Nzb\NzbUploadStagingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Mockery;
use PDO;
use Tests\TestCase;

final class NzbUploadStagingServiceTest extends TestCase
{
    private string $uploadFolder;

    private string $databasePath;

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-nzb-upload-service-test.sqlite';
        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('innerfileblacklist', '')");

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

        $this->uploadFolder = sys_get_temp_dir().'/nntmux-nzb-upload-'.bin2hex(random_bytes(6));
        config(['nntmux.nzb_upload_folder' => $this->uploadFolder]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->uploadFolder);

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_it_stages_a_matching_nzb_and_nfo_pair(): void
    {
        $result = (new NzbUploadStagingService(new Filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('Release.nfo', 'release information'),
        );

        $this->assertSame('Release', $result['name']);
        $this->assertSame('Release.nzb', $result['files']['nzb']['filename']);
        $this->assertSame('Release.nfo', $result['files']['nfo']['filename']);
        $this->assertFileExists($this->uploadFolder.'/Release.nzb');
        $this->assertFileExists($this->uploadFolder.'/Release.nfo');
    }

    public function test_it_stages_an_nzb_without_an_nfo(): void
    {
        $result = (new NzbUploadStagingService(new Filesystem))->stage($this->nzb('Solo.nzb'));

        $this->assertNull($result['files']['nfo']);
        $this->assertFileExists($this->uploadFolder.'/Solo.nzb');
    }

    public function test_it_rejects_mismatched_pair_names_before_writing(): void
    {
        try {
            (new NzbUploadStagingService(new Filesystem))->stage(
                $this->nzb('Release.nzb'),
                UploadedFile::fake()->createWithContent('Different.nfo', 'release information'),
            );
            $this->fail('Expected a mismatched pair to be rejected.');
        } catch (NzbUploadException $exception) {
            $this->assertSame(400, $exception->status);
        }

        $this->assertDirectoryDoesNotExist($this->uploadFolder);
    }

    public function test_it_rejects_an_invalid_nzb_payload(): void
    {
        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('Invalid NZB payload');

        (new NzbUploadStagingService(new Filesystem))->stage(
            UploadedFile::fake()->createWithContent('Release.nzb', 'not xml'),
        );
    }

    public function test_it_rejects_an_oversized_nfo(): void
    {
        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('NFO upload must not be empty or exceed 65535 bytes');

        (new NzbUploadStagingService(new Filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('Release.nfo', str_repeat('x', 65536)),
        );
    }

    public function test_it_rejects_a_collision_without_writing_either_file(): void
    {
        (new Filesystem)->makeDirectory($this->uploadFolder, 0775, true);
        file_put_contents($this->uploadFolder.'/Release.nfo', 'pending');

        try {
            (new NzbUploadStagingService(new Filesystem))->stage(
                $this->nzb('Release.nzb'),
                UploadedFile::fake()->createWithContent('Release.nfo', 'new information'),
            );
            $this->fail('Expected an existing staged file to be rejected.');
        } catch (NzbUploadException $exception) {
            $this->assertSame(409, $exception->status);
        }

        $this->assertFileDoesNotExist($this->uploadFolder.'/Release.nzb');
        $this->assertSame('pending', file_get_contents($this->uploadFolder.'/Release.nfo'));
    }

    public function test_it_rolls_back_the_nzb_when_the_nfo_write_fails(): void
    {
        $nzbPath = $this->uploadFolder.'/Release.nzb';
        $nfoPath = $this->uploadFolder.'/Release.nfo';
        $filesystem = Mockery::mock(Filesystem::class);
        $filesystem->shouldReceive('isDirectory')->once()->with($this->uploadFolder)->andReturnTrue();
        $filesystem->shouldReceive('exists')->once()->with($nzbPath)->andReturnFalse();
        $filesystem->shouldReceive('exists')->once()->with($nfoPath)->andReturnFalse();
        $filesystem->shouldReceive('put')->once()->with($nzbPath, Mockery::type('string'))->andReturn(100);
        $filesystem->shouldReceive('put')->once()->with($nfoPath, 'release information')->andReturnFalse();
        $filesystem->shouldReceive('exists')->once()->with($nzbPath)->andReturnTrue();
        $filesystem->shouldReceive('delete')->once()->with($nzbPath)->andReturnTrue();

        $this->expectException(NzbUploadException::class);
        $this->expectExceptionMessage('Failed to write Release.nfo to disk');

        (new NzbUploadStagingService($filesystem))->stage(
            $this->nzb('Release.nzb'),
            UploadedFile::fake()->createWithContent('Release.nfo', 'release information'),
        );
    }

    private function nzb(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            '<?xml version="1.0"?><nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"></nzb>',
        );
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
