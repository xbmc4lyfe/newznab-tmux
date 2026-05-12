<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbImportService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class NzbImportServiceTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-nzb-import-test.sqlite';

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
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

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
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_begin_import_uses_specific_messages_and_counts_duplicates_separately(): void
    {
        $duplicateFile = $this->makeNzbFile('duplicate');
        $blacklistedFile = $this->makeNzbFile('blacklisted');
        $noGroupFile = $this->makeNzbFile('nogroup');
        $failedFile = $this->makeNzbFile('failed');

        $service = new class(['Browser' => true], [NzbImportStatus::Duplicate, NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup, NzbImportStatus::Failed]) extends NzbImportService
        {
            /**
             * @param  array<NzbImportStatus>  $statuses
             */
            public function __construct(array $options, private array $statuses)
            {
                parent::__construct($options);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $status = array_shift($this->statuses) ?? NzbImportStatus::Failed;

                match ($status) {
                    NzbImportStatus::Duplicate => $this->echoOut('This release is already in our DB so skipping: duplicate subject'),
                    NzbImportStatus::Blacklisted => $this->echoOut('Subject is blacklisted: blacklisted subject'),
                    NzbImportStatus::NoGroup => $this->echoOut('No group found for missing-group subject (one of alt.test are missing'),
                    default => null,
                };

                return $status;
            }
        };

        $result = $service->beginImport(
            [$duplicateFile, $blacklistedFile, $noGroupFile, $failedFile],
            delete: false,
            deleteFailed: true,
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('This release is already in our DB so skipping: duplicate subject', $result);
        $this->assertStringContainsString('Subject is blacklisted: blacklisted subject', $result);
        $this->assertStringContainsString('No group found for missing-group subject (one of alt.test are missing', $result);
        $this->assertSame(1, substr_count($result, 'ERROR: Failed to insert NZB!'));
        $this->assertStringContainsString('Processed 0 NZBs in ', $result);
        $this->assertStringContainsString('3 NZBs were skipped, 1 were duplicates.', $result);

        $this->assertFileDoesNotExist($duplicateFile);
        $this->assertFileDoesNotExist($blacklistedFile);
        $this->assertFileDoesNotExist($noGroupFile);
        $this->assertFileDoesNotExist($failedFile);
    }

    public function test_begin_import_deletes_duplicate_blacklisted_and_no_group_files_when_delete_is_enabled(): void
    {
        $duplicateFile = $this->makeNzbFile('duplicate-delete');
        $blacklistedFile = $this->makeNzbFile('blacklisted-delete');
        $noGroupFile = $this->makeNzbFile('nogroup-delete');

        $service = new class(['Browser' => true], [NzbImportStatus::Duplicate, NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup]) extends NzbImportService
        {
            /**
             * @param  array<NzbImportStatus>  $statuses
             */
            public function __construct(array $options, private array $statuses)
            {
                parent::__construct($options);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                $status = array_shift($this->statuses) ?? NzbImportStatus::Failed;

                match ($status) {
                    NzbImportStatus::Duplicate => $this->echoOut('This release is already in our DB so skipping: duplicate subject'),
                    NzbImportStatus::Blacklisted => $this->echoOut('Subject is blacklisted: blacklisted subject'),
                    NzbImportStatus::NoGroup => $this->echoOut('No group found for missing-group subject (one of alt.test are missing'),
                    default => null,
                };

                return $status;
            }
        };

        $result = $service->beginImport(
            [$duplicateFile, $blacklistedFile, $noGroupFile],
            delete: true,
            deleteFailed: false,
        );

        $this->assertIsString($result);
        $this->assertStringNotContainsString('ERROR: Failed to insert NZB!', $result);
        $this->assertStringContainsString('2 NZBs were skipped, 1 were duplicates.', $result);

        $this->assertFileDoesNotExist($duplicateFile);
        $this->assertFileDoesNotExist($blacklistedFile);
        $this->assertFileDoesNotExist($noGroupFile);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nzbFilenameProvider(): array
    {
        return [
            'plain .nzb' => ['foo.nzb', 'foo'],
            'plain .nzb.gz' => ['foo.nzb.gz', 'foo'],
            'mkv wrapper' => ['foo.mkv.nzb.gz', 'foo'],
            'uppercase wrapper' => ['bar.MP4.NZB.GZ', 'bar'],
            'release with brackets' => [
                '[DKB] Kami-tachi ni Hirowareta Otoko - S01E07 [1080p][H.265 10bit].mkv.nzb.gz',
                '[DKB] Kami-tachi ni Hirowareta Otoko - S01E07 [1080p][H.265 10bit]',
            ],
            'non-media inner ext stays' => ['release.name.nzb.gz', 'release.name'],
            'no trailing media ext' => ['something.nzb', 'something'],
            'full path input' => ['/tmp/nested/path/Show - 01.mp4.nzb.gz', 'Show - 01'],
        ];
    }

    /**
     * @dataProvider nzbFilenameProvider
     */
    #[DataProvider('nzbFilenameProvider')]
    public function test_derive_release_name_strips_wrapper_and_media_extension(string $input, string $expected): void
    {
        $service = new class(['Browser' => true]) extends NzbImportService
        {
            public function deriveForTest(string $path): string
            {
                return $this->deriveReleaseNameFromNzbPath($path);
            }
        };

        $this->assertSame($expected, $service->deriveForTest($input));
    }

    private function makeNzbFile(string $suffix): string
    {
        $path = sys_get_temp_dir().'/'.$suffix.'-'.bin2hex(random_bytes(5)).'.nzb';
        file_put_contents($path, '<nzb></nzb>');

        return $path;
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
