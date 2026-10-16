<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Facades\Search;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

/** SQLite call-order contract only; does not demonstrate physical row locks. */
final class ReleaseUpdateFilenameOrderingTest extends TestCase
{
    use Par2RenameTestDatabase;

    public static function positiveRoutes(): array
    {
        return ['rename' => [false], 'attach' => [true]];
    }

    #[DataProvider('positiveRoutes')]
    public function test_initial_guard_precedes_canonical_read_and_final_guard_is_last(bool $attach): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse();
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $sourceBefore = (array) DB::table('predb')->where('id', 7)->first();
        $release = $this->pendingPar2Release(['searchname' => $attach ? $title : 'Synthetic.Input']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $trace = [];
        $guardCalls = 0;
        $record = true;
        $syncs = [];
        $guard = static function () use (&$trace, &$guardCalls): bool {
            $trace[] = 'guard:'.++$guardCalls;

            return true;
        };
        DB::listen(static function ($event) use (&$trace, &$record): void {
            if (! $record) {
                return;
            }
            $sql = strtolower($event->sql);
            // This independent canonical-row read is distinct from lookup helper joins.
            if ($sql === 'select * from "predb" where "id" = ? limit 1' && $event->bindings === [7]) {
                $trace[] = 'canonical';
            } elseif (str_starts_with($sql, 'update "releases"')) {
                $trace[] = 'release-write';
            }
        });
        $updates = new ReleaseUpdateService(
            $this->createStub(CategorizationService::class),
            searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$syncs): void {
                $syncs[] = $id;
            }),
        );
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            if ($attach) {
                self::assertTrue($updates->attachPredbSnapshot($input, 7, $title, null, $guard));
            } else {
                $updates->updateRelease($input, $title, 'PreDB', true, 'Filenames, ', true, false, 7, null, $guard);
            }
            $record = false;
            self::assertSame(['guard:1', 'canonical', 'release-write', 'canonical', 'guard:2'], $trace);
            self::assertSame(2, $guardCalls);
            self::assertSame(1, $connection->transactionLevel(), 'The caller retains its transaction.');
            self::assertTrue($connection->getPdo()->inTransaction());
            self::assertTrue($updates->matched);
            self::assertSame(7, (int) $release->fresh()->predb_id);
            self::assertSame($title, $release->fresh()->searchname);
            self::assertSame($sourceBefore, (array) DB::table('predb')->where('id', 7)->first());
            self::assertSame([], $syncs, 'Search publication waits for caller commit.');
            Event::assertNotDispatched(ReleaseNameFixed::class);
            $connection->commit();
        } finally {
            $record = false;
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
        self::assertSame($attach ? [] : [$release->id], $syncs);
        if ($attach) {
            Event::assertNotDispatched(ReleaseNameFixed::class);
        } else {
            Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
        }
    }
}
