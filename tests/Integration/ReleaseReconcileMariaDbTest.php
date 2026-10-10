<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Enums\CollectionFileCheckStatus;
use App\Services\Binaries\BinariesConfig;
use App\Services\ReleaseProcessingService;
use App\Support\Data\ProcessReleasesSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the MariaDB reconcile SQL (the SQLite tests take a separate code path).
 * Point CBP_INTEGRATION_DB_* at a scratch database to run it.
 */
final class ReleaseReconcileMariaDbTest extends TestCase
{
    public function createApplication()
    {
        $database = getenv('CBP_INTEGRATION_DB_DATABASE');
        if ($database === false || $database === '') {
            return parent::createApplication();
        }

        foreach ([
            'DB_CONNECTION' => 'mariadb',
            'DB_DATABASE' => $database,
            'DB_HOST' => (string) (getenv('CBP_INTEGRATION_DB_HOST') ?: 'mariadb'),
            'DB_USERNAME' => (string) getenv('CBP_INTEGRATION_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('CBP_INTEGRATION_DB_PASSWORD'),
        ] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! \in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MariaDB/MySQL integration test.');
        }

        $this->dropTables();
        DB::statement('CREATE TABLE collections (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, groups_id INT UNSIGNED NOT NULL DEFAULT 1,
            totalfiles INT UNSIGNED NOT NULL DEFAULT 0, filecheck TINYINT NOT NULL DEFAULT 0,
            filesize BIGINT UNSIGNED NOT NULL DEFAULT 0, dateadded DATETIME NULL,
            added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE binaries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collections_id INT UNSIGNED NOT NULL,
            totalparts INT UNSIGNED NOT NULL DEFAULT 0, currentparts INT UNSIGNED NOT NULL DEFAULT 0,
            partcheck TINYINT NOT NULL DEFAULT 0, partsize BIGINT UNSIGNED NOT NULL DEFAULT 0,
            KEY ix_binaries_collection (collections_id)
        ) ENGINE=InnoDB');
        DB::statement('CREATE TABLE parts (
            binaries_id BIGINT UNSIGNED NOT NULL, partnumber INT UNSIGNED NOT NULL, size INT UNSIGNED NOT NULL,
            PRIMARY KEY (binaries_id, partnumber)
        ) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if (\in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->dropTables();
        }
        parent::tearDown();
    }

    public function test_recounts_binaries_and_marks_ready_and_stale_collections(): void
    {
        $old = now()->subHours(3);
        $complete = $this->collection(totalFiles: 2, dateAdded: $old);
        $this->binary($complete, totalParts: 2, partSizes: [100, 200]);
        $this->binary($complete, totalParts: 1, partSizes: [50]);

        $stale = $this->collection(totalFiles: 5, dateAdded: $old);
        $this->binary($stale, totalParts: 4, partSizes: [7]);
        $this->binary($stale, totalParts: 4, partSizes: [8]);

        $completeParts = $this->collection(totalFiles: 3, filecheck: CollectionFileCheckStatus::CompleteParts->value);
        $this->binary($completeParts, totalParts: 2, partSizes: [10]);
        $staleCounts = $this->binary($completeParts, totalParts: 1, partSizes: [], currentParts: 9);

        $recent = $this->collection(totalFiles: 1);
        $this->binary($recent, totalParts: 1, partSizes: [99]);

        $this->reconcile();

        $this->assertCollection($complete, filecheck: CollectionFileCheckStatus::CompleteParts->value, totalFiles: 2, filesize: 350);
        $this->assertCollection($stale, filecheck: CollectionFileCheckStatus::CompleteParts->value, totalFiles: 2, filesize: 15);
        $this->assertCollection($completeParts, filecheck: CollectionFileCheckStatus::CompleteParts->value, totalFiles: 3, filesize: 10);
        $this->assertCollection($recent, filecheck: CollectionFileCheckStatus::Default->value, totalFiles: 1, filesize: 0);
        $binary = DB::table('binaries')->where('id', $staleCounts)->first();
        $this->assertSame([0, 0, 0], [(int) $binary->currentparts, (int) $binary->partsize, (int) $binary->partcheck], 'A binary without parts is reset.');
        $this->assertSame(0, (int) DB::table('binaries')->where('collections_id', $recent)->value('currentparts'), 'Collections inside the delay are left to header storage.');
    }

    public function test_refreshes_every_binary_when_a_page_spans_several_statements(): void
    {
        $collections = [];
        foreach (range(1, 3) as $ignored) {
            $collection = $this->collection(totalFiles: 150, dateAdded: now()->subHours(3));
            foreach (range(1, 150) as $file) {
                $this->binary($collection, totalParts: 1, partSizes: [$file]);
            }
            $collections[] = $collection;
        }

        $this->reconcile();

        $this->assertSame(450, DB::table('binaries')->where('partcheck', 1)->where('currentparts', 1)->count());
        foreach ($collections as $collection) {
            $this->assertCollection($collection, filecheck: CollectionFileCheckStatus::CompleteParts->value, totalFiles: 150, filesize: 11325);
        }
    }

    private function reconcile(): void
    {
        $reflection = new \ReflectionClass(ReleaseProcessingService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('settings')->setValue($service, new ProcessReleasesSettings(collectionDelayTime: 2));
        $reflection->getProperty('binariesConfig')->setValue($service, new BinariesConfig(reconcileBatchSize: 500));
        $reflection->getMethod('reconcileIncompleteCollections')->invoke($service, null);
    }

    private function collection(int $totalFiles, ?\DateTimeInterface $dateAdded = null, int $filecheck = 0): int
    {
        return (int) DB::table('collections')->insertGetId([
            'totalfiles' => $totalFiles,
            'filecheck' => $filecheck,
            'dateadded' => $dateAdded ?? now(),
            'added' => $dateAdded ?? now(),
        ]);
    }

    /**
     * @param  list<int>  $partSizes
     */
    private function binary(int $collectionId, int $totalParts, array $partSizes, int $currentParts = 0): int
    {
        $binaryId = (int) DB::table('binaries')->insertGetId([
            'collections_id' => $collectionId,
            'totalparts' => $totalParts,
            'currentparts' => $currentParts,
            'partsize' => $currentParts * 10,
        ]);
        foreach ($partSizes as $index => $size) {
            DB::table('parts')->insert(['binaries_id' => $binaryId, 'partnumber' => $index + 1, 'size' => $size]);
        }

        return $binaryId;
    }

    private function assertCollection(int $collectionId, int $filecheck, int $totalFiles, int $filesize): void
    {
        $collection = DB::table('collections')->where('id', $collectionId)->first();
        $this->assertSame(
            ['filecheck' => $filecheck, 'totalfiles' => $totalFiles, 'filesize' => $filesize],
            ['filecheck' => (int) $collection->filecheck, 'totalfiles' => (int) $collection->totalfiles, 'filesize' => (int) $collection->filesize],
        );
    }

    private function dropTables(): void
    {
        foreach (['parts', 'binaries', 'collections'] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
    }
}
