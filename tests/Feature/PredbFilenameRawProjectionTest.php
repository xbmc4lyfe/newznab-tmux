<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\Extractors\FileNameExtractor;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Predb\PredbIdentityRebuild;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\SearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Manticoresearch\Client;
use Manticoresearch\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class PredbFilenameRawProjectionTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function scenarios(): array
    {
        $cases = [];
        foreach (['manticore'] as $backend) {
            foreach (['prefile', 'list', 'files'] as $caller) {
                foreach ([true] as $alias) {
                    foreach (['malformed first', 'malformed last', 'valid competitor first', 'valid competitor last'] as $scenario) {
                        $cases[$backend.' '.$caller.' '.($alias ? 'alias ' : 'canonical ').$scenario] = [$backend, $caller, $alias, $scenario];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_backend_completeness_and_certified_sql_authority_have_independent_outcomes(string $backend, string $caller, bool $alias, string $scenario): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        DB::statement("ALTER TABLE predb ADD COLUMN filename varchar NOT NULL DEFAULT ''");
        (require database_path('migrations/2026_10_15_150000_create_predb_identity_projection.php'))->up();
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Canonical.Release.2026-GRP';
        $stem = $alias ? 'Synthetic.Filename.Alias-GRP' : $title;
        $filename = $stem.'.mkv';
        $rows = $scenario === 'new mapping' ? [] : [['_id' => 7, '_source' => ['title' => $title, 'filename' => $alias ? $stem : '']]];
        if ($rows !== []) {
            DB::table('predb')->insert(['id' => 7, 'title' => $title, 'filename' => $alias ? $stem : '']);
        }
        DB::table('predb')->insert(['id' => 8, 'title' => 'Other.Canonical.2026-GRP', 'filename' => $stem]);
        $other = ['_id' => '8', '_source' => ['title' => 'Other.Canonical.2026-GRP', 'filename' => str_starts_with($scenario, 'malformed') ? false : $stem]];
        $rows = str_ends_with($scenario, 'first') ? [$other, ...$rows] : [...$rows, $other];
        $calls = 0;
        $response = function () use (&$rows, &$calls, $backend, $scenario): array {
            $calls++;
            if ($scenario === 'error') {
                throw new RuntimeException('synthetic backend failure');
            }
            $hits = $scenario === 'full window' ? array_fill(0, 1000, $rows[0]) : $rows;
            $total = $scenario === 'truncated' ? 1001 : count($hits);

            return ['timed_out' => false, '_shards' => ['total' => 1, 'successful' => 1, 'failed' => 0], 'hits' => [
                'hits' => $hits, 'total_relation' => 'eq',
                'total' => $total,
            ]];
        };
        $client = $this->createStub(Client::class);
        $client->method('search')->willReturnCallback(static fn (): Response => new Response($response()));
        $driver = new ManticoreSearchDriver(['indexes' => ['predb' => 'custom_pre']]);
        $driver->manticoreSearch = $client;
        $search = new class($this->app) extends SearchService
        {
            public function supportsReleaseCandidatePaging(): bool
            {
                return false;
            }
        };
        $search->extend('fixture', static fn () => $driver);
        config(['search.default' => 'fixture']);
        Search::swap($search);
        $extractor = $this->createMock(FileNameExtractor::class);
        $extractor->expects(self::never())->method('extractFromFile')->willReturn(null);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $service = new NameFixingService($updates, fileExtractor: $extractor);
        $iterations = in_array($scenario, ['late competitor', 'new mapping'], true) ? 2 : 1;
        for ($step = 0; $step < $iterations; $step++) {
            if ($step === 1) {
                $id = $scenario === 'late competitor' ? 8 : 7;
                $competingTitle = $alias ? 'Other.Canonical.2026-GRP' : $title;
                $newTitle = $scenario === 'late competitor' ? $competingTitle : $title;
                DB::table('predb')->insert(['id' => $id, 'title' => $newTitle, 'filename' => $alias ? $stem : '']);
                $rows[] = ['_id' => $id, '_source' => ['title' => $newTitle, 'filename' => $alias ? $stem : '']];
            }
            if ($scenario === 'current row changed') {
                DB::table('predb')->where('id', 7)->update($alias ? ['filename' => 'Other.Alias-GRP'] : ['title' => 'Other.Canonical.2026-GRP']);
            }
            $backendCandidates = Search::searchPredbFilenameCandidates($stem);
            if (str_starts_with($scenario, 'malformed')) {
                self::assertNull($backendCandidates);
            } else {
                self::assertCount(2, $backendCandidates);
            }
            $this->certifySqlIdentityFixture();
            $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 0]);
            if ($caller === 'files') {
                DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
                $service->fixNamesWithFiles(0, true, 0, true, false);
            } elseif ($caller === 'prefile') {
                DB::table('release_files')->insert(['releases_id' => $release->id, 'name' => $filename]);
                $service->getPreFileNames(['prefile', '1']);
                // Keep the next public batch focused on the next observation.
                DB::table('release_files')->where('releases_id', $release->id)->delete();
            } else {
                $release->releases_id = $release->id;
                $service->matchPreDbFileNames($release, [$filename], true, true, false);
            }
            $accepted = $scenario === 'complete' || ($scenario === 'late competitor' && $step === 0) || ($scenario === 'new mapping' && $step === 1);
            self::assertSame($accepted ? 7 : 0, $release->fresh()->predb_id);
            self::assertSame($title, $release->fresh()->searchname, 'Backend responses cannot replace authoritative SQL identity.');
        }
        self::assertSame($iterations, $calls);
    }

    private function certifySqlIdentityFixture(): void
    {
        $rebuild = new PredbIdentityRebuild;
        $start = $rebuild->start(DB::connection());
        do {
            $page = $rebuild->chunk(DB::connection(), $start['generation']);
        } while (! $page['exhausted']);
        $rebuild->certify(DB::connection(), $start['generation']);
    }
}
