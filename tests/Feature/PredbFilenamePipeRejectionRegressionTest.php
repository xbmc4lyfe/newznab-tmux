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
use App\Services\NameFixing\FilePrioritizer;
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

final class PredbFilenamePipeRejectionRegressionTest extends TestCase
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

    public static function mixedCases(): array
    {
        $cases = [];
        foreach (['files', 'standard', 'prefile', 'list'] as $caller) {
            foreach ([false, true] as $alias) {
                foreach ([false, true] as $sameName) {
                    foreach ([false, true] as $pipeFirst) {
                        $cases[$caller.($alias ? ' alias' : ' canonical').($sameName ? ' attach' : ' rename').($pipeFirst ? ' pipe first' : ' pipe last')] = [$caller, $alias, $sameName, $pipeFirst];
                    }
                }
            }
        }

        return $cases;
    }

    private function mixedNames(object $release, string $filename, bool $pipeFirst): array
    {
        // Force both actual prioritized orders, not merely insertion orders.
        $pipe = $pipeFirst ? str_repeat('Auxiliary.', 8).'Asset||Exact.Release-GRP.mkv' : 'Auxiliary|Asset.mkv';
        $names = $pipeFirst ? [$pipe, $filename] : [$filename, $pipe];
        DB::table('release_files')->where('releases_id', $release->id)->delete();
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $ordered = (new FilePrioritizer)->prioritizeForMatching($names);
        self::assertSame($pipeFirst ? $pipe : $filename, $ordered[0], 'The intended actual observation order must be exercised.');

        return $names;
    }

    private function runPass(string $caller, object $release, NameFixingService $service, array $names): void
    {
        if ($caller === 'list') {
            $service->matchPreDbFileNames($release, $names, true, true, false);
        } else {
            $this->runCaller($caller, $release, $service, $names[0]);
        }
    }

    #[DataProvider('mixedCases')]
    public function test_narrow_pipe_rejection_does_not_veto_independent_certified_identity(string $caller, bool $alias, bool $sameName, bool $pipeFirst): void
    {
        [$release,$service,$filename,$title] = $this->fixture($alias, $sameName);
        $names = $this->mixedNames($release, $filename, $pipeFirst);
        $this->certify();
        $this->runPass($caller, $release, $service, $names);
        self::assertSame(7, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame($names, DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
        if ($sameName) {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
    }

    public static function unknownCases(): array
    {
        $cases = [];
        foreach (self::mixedCases() as $key => $args) {
            foreach (['unready', 'stale revision'] as $mode) {
                $cases[$key.' '.$mode] = [...$args, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('unknownCases')]
    public function test_pipe_local_rejection_cannot_hide_sql_unknown(string $caller, bool $alias, bool $sameName, bool $pipeFirst, string $mode): void
    {
        [$release,$service,$filename] = $this->fixture($alias, $sameName);
        $original = $release->searchname;
        $names = $this->mixedNames($release, $filename, $pipeFirst);
        if ($mode === 'stale revision') {
            $this->certify();
            DB::table('predb_identity_revisions')->where('predb_id', 7)->update(['projected_revision' => null]);
        }
        $this->runPass($caller, $release, $service, $names);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame(0, $release->fresh()->proc_files);
        self::assertSame($original, $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function pipeOnlyCases(): array
    {
        return [['files'], ['standard'], ['prefile'], ['list']];
    }

    #[DataProvider('pipeOnlyCases')]
    public function test_pipe_only_observation_never_becomes_fallback_permission(string $caller): void
    {
        [$release,$service] = $this->fixture(false, false);
        $original = $release->searchname;
        $names = ['Auxiliary.Asset||Exact.Release-GRP.mkv'];
        DB::table('release_files')->where('releases_id', $release->id)->delete();
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $names[0]]);
        $this->certify();
        $this->runPass($caller, $release, $service, $names);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame($original, $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function completionRoutes(): array
    {
        return [['files'], ['standard'], ['check']];
    }

    #[DataProvider('completionRoutes')]
    public function test_proven_pipe_rejection_can_complete_without_extractor_fallback(string $caller): void
    {
        [$release,$service] = $this->fixture(false, false);
        $name = 'Auxiliary.Asset||Exact.Release-GRP.mkv';
        DB::table('release_files')->where('releases_id', $release->id)->delete();
        DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        $this->certify();
        if ($caller === 'check') {
            $release->textstring = $name;
            self::assertFalse($service->checkName($release, true, 'Filenames, ', true, false));
        } else {
            $this->runPass($caller, $release, $service, [$name]);
        }
        self::assertSame(1, $release->fresh()->proc_files);
        self::assertSame(0, $release->fresh()->predb_id);
        self::assertSame('Unresolved.Synthetic.2026-GRP', $release->fresh()->searchname);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }
}
