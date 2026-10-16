<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityRebuild;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameSourceDiagnosticTest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        Schema::table('predb', static function (Blueprint $table): void {
            $table->string('filename')->default('');
        });
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        config(['tmux.fix_names.cache_store' => 'array', 'nntmux.echocli' => false]);
        Cache::store('array')->flush();
        Event::fake([ReleaseNameFixed::class]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredbFilenameCandidates')->never();
    }

    private function certify(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }

    public static function diagnosticSources(): array
    {
        return [
            'minimal schema without source' => [false, null, ''],
            'ordinary source' => [true, 'SyntheticFeed', 'SyntheticFeed'],
            'null source' => [true, null, ''],
            'empty source' => [true, '', ''],
            'embedded NUL' => [true, "Synthetic\0Feed", ''],
            'fifty characters' => [true, str_repeat('x', 50), str_repeat('x', 50)],
            'fifty one characters' => [true, str_repeat('x', 51), ''],
            'control bytes' => [true, "Synthetic\nFeed", ''],
            'oversized source' => [true, str_repeat('x', 201), ''],
            'invalid UTF8' => [true, "\xff", ''],
            '200 byte fifty scalar value boundary' => [true, str_repeat("\u{1F600}", 50), str_repeat("\u{1F600}", 50)],
            '204 byte fifty one scalar values' => [true, str_repeat("\u{1F600}", 51), ''],
            '201 bytes valid UTF8 prefix' => [true, str_repeat("\u{1F600}", 50).'x', ''],
            'transport truncation splits UTF8' => [true, str_repeat("\u{1F600}", 49).str_repeat('x', 4)."\u{1F600}", ''],
            'combining sequence fifty scalars' => [true, str_repeat("a\u{0301}", 25), str_repeat("a\u{0301}", 25)],
            'combining sequence fifty one scalars' => [true, str_repeat("a\u{0301}", 25).'x', ''],
            'bidi format control' => [true, "Synthetic\u{202E}Feed", ''],
            'private use category' => [true, "Synthetic\u{E000}Feed", ''],
            'unassigned category' => [true, "Synthetic\u{0378}Feed", ''],
            'DEL control' => [true, "Synthetic\u{007F}Feed", ''],
            'diagnostic read throws' => [true, 'SyntheticFeed', '', true],
        ];
    }

    #[DataProvider('diagnosticSources')]
    public function test_optional_source_only_enriches_the_display_method(bool $hasSource, ?string $source, string $expected, bool $readFails = false): void
    {
        if ($hasSource) {
            Schema::table('predb', static function (Blueprint $table): void {
                $table->text('source')->nullable();
            });
        }
        config(['nntmux.echocli' => true]);
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $filename = $title.'.mkv';
        $row = ['id' => 7, 'title' => $title, 'filename' => ''];
        if ($hasSource) {
            $row['source'] = $source;
        }
        DB::table('predb')->insert($row);
        $release = $this->pendingPar2Release(['searchname' => 'Unresolved.Synthetic.2026-GRP']);
        $release->releases_id = $release->id;
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $this->certify();
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updater = new class($category, searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {})) extends ReleaseUpdateService {

            public ?string $displayMethod = null;

            public function echoReleaseInfo(object $release, string $newName, array $determinedCategory, string $type, string $method): void
            {
                $this->displayMethod = $method;
            }
        };
        $service = new NameFixingService($updater);
        $connection = DB::connection();
        $callbacksProperty = new \ReflectionProperty($connection, 'beforeExecutingCallbacks');
        $originalCallbacks = $callbacksProperty->getValue($connection);
        $schemaObserved = false;
        $failureLevels = [];
        if ($readFails) {
            $connection->beforeExecuting(static function (string $sql) use ($connection, &$schemaObserved, &$failureLevels): void {
                if (str_contains($sql, "pragma_table_xinfo('predb'")) {
                    $schemaObserved = true;
                }
                if (str_contains($sql, 'AS diagnostic_source')) {
                    self::assertTrue($schemaObserved, 'Optional column detection must finish before the injected failure.');
                    $failureLevels[] = $connection->transactionLevel();
                    throw new \RuntimeException('Synthetic optional diagnostic read failure.');
                }
            });
        }
        try {
            self::assertSame(1, $service->matchPreDbFileNames($release, [$filename], true, true, true));
        } finally {
            $callbacksProperty->setValue($connection, $originalCallbacks);
        }
        if ($readFails) {
            self::assertSame([0], $failureLevels, 'The optional read must fail before opening the identity write transaction.');
        }
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
        self::assertTrue($updater->matched);
        self::assertTrue($updater->done);
        self::assertNotNull($updater->lastCommittedIdentity());
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame(7, (int) $release->fresh()->predb_id);
        self::assertSame('file matched source: '.$expected, $updater->displayMethod);
    }
}
