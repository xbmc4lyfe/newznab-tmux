<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Data\NameFixResult;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\PredbMatchSelector;
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

final class PredbFilenamePipePolicyDriftTest extends TestCase
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

    /** @return array{object, NameFixingService, string, string} */
    private function fixture(bool $alias, bool $sameName, ?PredbMatchSelector $selector = null, ?bool $fallback = null): array
    {
        $title = 'Synthetic.Canonical.Release.2026-GRP';
        $filename = ($alias ? 'Synthetic.Upload.Alias-GRP' : $title).'.mkv';
        $release = $this->pendingPar2Release([
            'searchname' => $sameName ? $title : 'Unresolved.Synthetic.2026-GRP',
            'leftguid' => 'a', 'predb_id' => 0, 'proc_files' => 0,
            'proc_uid' => 1, 'proc_crc32' => 1, 'proc_hash16k' => 1,
            'proc_nfo' => 1, 'proc_srr' => 1, 'proc_par2' => 1,
        ]);
        $release->releases_id = $release->id;
        DB::table('predb')->insert(['id' => 7, 'title' => $title,
            'filename' => $alias ? 'Synthetic.Upload.Alias-GRP.rar' : '']);
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
        $extractor = $this->createMock(FileNameExtractor::class);
        if ($fallback === null) {
            $extractor->expects(self::never())->method('extractFromFile');
        } else {
            $extractor->expects(self::once())->method('extractFromFile')->willReturn($fallback
                ? NameFixResult::fromMatch('Synthetic.Fallback.Release.2026-GRP', 'fixture', 'File')
                : null);
        }
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);

        return [$release, new NameFixingService($updates, fileExtractor: $extractor, predbMatchSelector: $selector), $filename, $title];
    }

    private function runCaller(string $caller, object $release, NameFixingService $service, string $filename): void
    {
        if ($caller === 'files') {
            $service->fixNamesWithFiles(0, true, 0, true, false);
        } elseif ($caller === 'standard') {
            $service->processStandardBatch('a', 1, false);
        } elseif ($caller === 'prefile') {
            $service->getPreFileNames(['prefile', '1']);
        } else {
            $service->matchPreDbFileNames($release, [$filename], true, true, false);
        }
    }

    public static function transactionCases(): array
    {
        return [['owned' => false], ['owned' => true]];
    }

    #[DataProvider('transactionCases')]
    public function test_rejection_only_completion_rechecks_runtime_policy_after_write(bool $owned): void
    {
        [$release,$service] = $this->fixture(false, false);
        $original = $release->searchname;
        DB::table('release_files')->where('releases_id', $release->id)->delete();
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Auxiliary.Asset||Exact.Release-GRP.mkv']);
        $this->certify();
        $encoding = mb_internal_encoding();
        $mutated = false;
        if (! $owned) {
            DB::beginTransaction();
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => 'Prior|Ambient.mkv']);
        }
        DB::listen(static function ($event) use (&$mutated): void {
            $sql = strtolower($event->sql);
            if (! $mutated && str_starts_with($sql, 'update ') && str_contains($sql, 'releases')) {
                $mutated = true;
                mb_internal_encoding('ISO-8859-1');
            }
        });
        try {
            $service->fixNamesWithFiles(0, true, 0, true, false);
            self::assertTrue($mutated, 'Expected completion write boundary was not reached.');
            self::assertSame($owned ? 0 : 1, DB::transactionLevel());
            self::assertSame(! $owned, DB::connection()->getPdo()->inTransaction());
            self::assertSame(0, $release->fresh()->proc_files);
            self::assertSame(0, $release->fresh()->predb_id);
            self::assertSame($original, $release->fresh()->searchname);
            self::assertSame($owned ? 0 : 1, DB::table('release_files')->where('releases_id', $release->id)->where('name', 'Prior|Ambient.mkv')->count());
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } finally {
            mb_internal_encoding($encoding);
            if (! $owned && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        self::assertSame(0, DB::transactionLevel());
        self::assertSame(0, DB::table('release_files')->where('releases_id', $release->id)->where('name', 'Prior|Ambient.mkv')->count());
    }
}
