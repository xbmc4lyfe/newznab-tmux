<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class ReleaseUpdateParentSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse()->byDefault();
    }

    use Par2RenameTestDatabase;

    public static function staleReleaseSnapshots(): array
    {
        $cases = [];
        foreach (['rename', 'attach', 'fulltext attach'] as $route) {
            foreach (['missing parent', 'reused id', 'guid byte case', 'name byte case', 'searchname byte case', 'null name becomes empty', 'lost additional claim'] as $mutation) {
                $cases[$route.' / '.$mutation] = [$route, $mutation];
            }
        }

        return $cases;
    }

    #[DataProvider('staleReleaseSnapshots')]
    public function test_stale_release_snapshot_stops_before_catalogue_access(string $route, string $mutation): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['guid' => 'SyntheticGuidABC', 'name' => $mutation === 'null name becomes empty' ? null : 'Synthetic.Original', 'searchname' => $route === 'rename' ? 'Synthetic.Input' : $title]);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        if ($mutation === 'lost additional claim') {
            $stamp = now()->format('Y-m-d H:i:s');
            DB::table('releases')->where('id', $release->id)->update(['additional_pp_claim_token' => 'original-claim', 'additional_pp_claimed_at' => $stamp]);
            $input->observed_additional_pp_claim = ['token' => 'original-claim', 'claimed_at' => $stamp];
        }
        if ($mutation === 'missing parent') {
            DB::table('releases')->where('id', $release->id)->delete();
        } elseif ($mutation === 'reused id') {
            $replacement = (array) DB::table('releases')->where('id', $release->id)->first();
            DB::table('releases')->where('id', $release->id)->delete();
            $replacement['guid'] = 'ReplacementGuid';
            DB::table('releases')->insert($replacement);
        } else {
            $change = match ($mutation) {
                'guid byte case' => ['guid' => 'syntheticGuidABC'],'name byte case' => ['name' => 'synthetic.Original'],'searchname byte case' => ['searchname' => strtolower((string) $input->searchname)],'null name becomes empty' => ['name' => ''],'lost additional claim' => ['additional_pp_claim_token' => 'replacement-claim']
            };
            DB::table('releases')->where('id', $release->id)->update($change);
        }
        $before = DB::table('releases')->where('id', $release->id)->first();
        $catalogueReads = 0;
        $syncs = [];
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$syncs): void {
            $syncs[] = $id;
        }));
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $isolated = clone $original;
        $connection->setEventDispatcher($isolated);
        $isolated->listen(QueryExecuted::class, static function ($event) use (&$catalogueReads): void {
            if (str_starts_with(strtolower($event->sql), 'select ') && str_contains(strtolower($event->sql), 'from "predb"')) {
                $catalogueReads++;
            }
        });
        try {
            if ($route === 'rename') {
                $updates->updateRelease($input, $title, 'PreDB', true, 'PAR2, ', true, false, 7);
            } elseif ($route === 'attach') {
                self::assertFalse($updates->attachPredbSnapshot($input, 7, $title));
            } else {
                self::assertFalse($updates->updatePredbCandidate($input, $title, 7, 'synthetic'));
            }
        } finally {
            $connection->setEventDispatcher($original);
        }
        self::assertFalse($updates->matched);
        self::assertFalse($updates->done);
        self::assertNull($updates->lastCommittedIdentity());
        self::assertEquals($before, DB::table('releases')->where('id', $release->id)->first());
        self::assertSame([], $syncs);
        Event::assertNotDispatched(ReleaseNameFixed::class);
        self::assertSame(0, $catalogueReads, 'A stale or absent release must stop before catalogue locking.');
    }

    public static function releaseUpdateRoutes(): array
    {
        return [['rename'], ['attach'], ['fulltext attach']];
    }

    #[DataProvider('releaseUpdateRoutes')]
    public function test_parent_is_read_before_catalogue_without_changing_canonical_identity(string $route): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => $route === 'rename' ? 'Synthetic.Input' : $title]);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $isolated = clone $original;
        $connection->setEventDispatcher($isolated);
        $reads = [];
        $isolated->listen(QueryExecuted::class, static function ($event) use (&$reads): void {
            $sql = strtolower($event->sql);
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from "predb"')) {
                $reads[] = 'P';
            } elseif (str_starts_with($sql, 'select ') && str_contains($sql, 'from "releases"')) {
                $reads[] = 'R';
            }
        });
        try {
            if ($route === 'rename') {
                $updates->updateRelease($input, $title, 'PreDB', true, 'PAR2, ', true, false, 7);
            } elseif ($route === 'attach') {
                self::assertTrue($updates->attachPredbSnapshot($input, 7, $title));
            } else {
                self::assertTrue($updates->updatePredbCandidate($input, $title, 7, 'synthetic'));
            }
        } finally {
            $connection->setEventDispatcher($original);
        }
        self::assertSame(7, (int) $release->fresh()->predb_id);
        self::assertSame($title, $release->fresh()->searchname);
        if ($route !== 'rename') {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        }
        $parent = array_search('R', $reads, true);
        $catalogue = array_search('P', $reads, true);
        self::assertNotFalse($parent);
        self::assertNotFalse($catalogue);
        self::assertLessThan($catalogue, $parent, 'Parent snapshot must be acquired before catalogue identity.');
    }
}
