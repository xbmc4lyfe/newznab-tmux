<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameRowsTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function rows(): array
    {
        return [
            'one literal row' => [['Auxiliary.Asset||Exact.Release.2026-GRP.mkv'], false],
            'two independent rows' => [['Auxiliary.Asset', 'Exact.Release.2026-GRP.mkv'], true],
            'single normal row' => [['Exact.Release.2026-GRP.mkv'], true],
            'show preserves filename diagnostic' => [['Exact.Release.2026-GRP.mkv'], true, true],
        ];
    }

    #[DataProvider('rows')]
    public function test_persisted_filename_row_boundaries_survive_public_prefile_caller(array $names, bool $matches, bool $show = false): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => $show]);
        if ($show) {
            DB::statement('CREATE TABLE categories (id INTEGER, title TEXT)');
        }
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $show ? 'Original.Release.2026-GRP' : $title, 'predb_id' => 0]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $before = DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all();
        $discoveredQueries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturnUsing(static function (string $query) use (&$discoveredQueries, $title): array {
            $discoveredQueries[] = $query;

            return [['id' => 7, 'title' => $title]];
        });
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $category = $this->createStub(CategorizationService::class);
        $category->method('determineCategory')->willReturn(['categories_id' => 8000]);
        $updates = new ReleaseUpdateService($category, searchSyncCoordinator: $sync);
        $queries = new NameFixingQueryService;
        self::assertCount(count($names), $queries->fileRows([$release->id]));
        $service = new NameFixingService($updates, queries: $queries);
        ob_start();
        try {
            $service->getPreFileNames($show ? ['prefile', '1', 'show'] : ['prefile', '1']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if ($show) {
            self::assertStringContainsString('Filename:', $output);
        }
        self::assertSame($before, DB::table('release_files')->where('releases_id', $release->id)->pluck('name')->all());
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id, json_encode(['rows' => count($names), 'discovered_queries' => $discoveredQueries]));
        self::assertSame($title, $release->fresh()->searchname);
        if (! $matches) {
            self::assertCount(1, $discoveredQueries);
            self::assertSame('Auxiliary.Asset '.$title, $discoveredQueries[0]);
        }
    }

    public static function mixedRows(): array
    {
        return ['literal first' => [false], 'independent first' => [true]];
    }

    #[DataProvider('mixedRows')]
    public function test_mixed_persisted_rows_attach_only_the_independent_filename(bool $reverse): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $suffixTitle = 'Exact.Release.2026-GRP';
        $trueTitle = 'True.Title-GRP';
        $release = $this->pendingPar2Release(['searchname' => $trueTitle, 'predb_id' => 0]);
        DB::table('predb')->insert([
            ['id' => 7, 'title' => $suffixTitle],
            ['id' => 8, 'title' => $trueTitle],
        ]);
        $names = ['Auxiliary.Asset||'.$suffixTitle.'.mkv', $trueTitle.'.mkv'];
        if ($reverse) {
            $names = array_reverse($names);
        }
        foreach ($names as $name) {
            DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $name]);
        }
        $before = DB::table('release_files')->where('releases_id', $release->id)->orderBy('id')->pluck('name')->all();
        $discoveredQueries = [];
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->andReturnUsing(static function (string $query) use (&$discoveredQueries, $suffixTitle, $trueTitle): array {
            $discoveredQueries[] = $query;

            return [['id' => 7, 'title' => $suffixTitle], ['id' => 8, 'title' => $trueTitle]];
        });
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $queries = new NameFixingQueryService;
        self::assertCount(2, $queries->fileRows([$release->id]));
        (new NameFixingService($updates, queries: $queries))->getPreFileNames(['prefile', '1']);

        self::assertNotContains($suffixTitle, $discoveredQueries, json_encode(['predb_id' => $release->fresh()->predb_id, 'searchname' => $release->fresh()->searchname]));
        self::assertContains('Auxiliary.Asset '.$suffixTitle, $discoveredQueries);
        self::assertContains($trueTitle, $discoveredQueries);
        self::assertSame(8, $release->fresh()->predb_id);
        self::assertSame($trueTitle, $release->fresh()->searchname);
        self::assertSame($before, DB::table('release_files')->where('releases_id', $release->id)->orderBy('id')->pluck('name')->all());
    }

    public static function admissions(): array
    {
        return ['current clone and claim' => [false, false, true], 'stale clone' => [true, false, false], 'stale claim' => [false, true, false]];
    }

    #[DataProvider('admissions')]
    public function test_list_path_preserves_cloned_release_snapshot_and_observed_claim(bool $staleSnapshot, bool $staleClaim, bool $matches): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::statement('ALTER TABLE releases ADD COLUMN additional_pp_claim_token TEXT');
        DB::statement('ALTER TABLE releases ADD COLUMN additional_pp_claimed_at TEXT');
        $title = 'Exact.Release.2026-GRP';
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
        $stamp = now()->format('Y-m-d H:i:s');
        DB::table('releases')->where('id', $release->id)->update(['additional_pp_claim_token' => 'claim-token', 'additional_pp_claimed_at' => $stamp]);
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $entry = clone $release;
        $entry->releases_id = $release->id;
        $entry->setAttribute('observed_additional_pp_claim', ['token' => $staleClaim ? 'expired-token' : 'claim-token', 'claimed_at' => $stamp]);
        if ($staleSnapshot) {
            DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement-guid']);
        }
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Search::shouldReceive('searchPredb')->once()->andReturn([['id' => 7, 'title' => $title]]);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        self::assertSame($matches ? 1 : 0, (new NameFixingService($updates))->matchPreDbFileNames($entry, [$title.'.mkv'], true, true, false));
        self::assertSame($matches ? 7 : 0, $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertArrayNotHasKey('observed_additional_pp_claim', $release->getAttributes());
        self::assertSame($matches, $updates->lastCommittedIdentity() !== null);
    }
}
