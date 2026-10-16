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

final class ReleaseUpdateFilenameGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Search::shouldReceive('supportsReleaseCandidatePaging')->andReturnFalse()->byDefault();
    }

    use Par2RenameTestDatabase;

    public static function guardedWriteCases(): array
    {
        $cases = [];
        foreach (['rename', 'attach', 'completion', 'fallback'] as $route) {
            foreach ([false, true] as $reject) {
                $cases[$route.($reject ? ' postreject' : ' accepted')] = [$route, $reject];
            }
        }

        return $cases;
    }

    #[DataProvider('guardedWriteCases')]
    public function test_guard_admission_and_postwrite_rollback(string $route, bool $reject): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => $route === 'attach' ? $title : 'Synthetic.Input']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $before = DB::table('releases')->where('id', $release->id)->first();
        $syncs = [];
        $calls = 0;
        $guard = static function () use (&$calls, $reject): bool {
            return ++$calls === 1 || ! $reject;
        };
        $updates = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$syncs): void {
            $syncs[] = $id;
        }));
        if ($route === 'rename' || $route === 'fallback') {
            $updates->updateRelease($input, $title, 'PreDB', true, 'Filenames, ', true, false, $route === 'rename' ? 7 : 0, null, $guard);
        } elseif ($route === 'attach') {
            self::assertSame(! $reject, $updates->attachPredbSnapshot($input, 7, $title, null, $guard));
        } else {
            self::assertSame(! $reject, $updates->completeFilenameSnapshot($input, $guard));
        }
        self::assertSame(2, $calls);
        if ($reject) {
            self::assertEquals($before, DB::table('releases')->where('id', $release->id)->first());
            self::assertFalse($updates->matched);
            self::assertFalse($updates->done);
            self::assertNull($updates->lastCommittedIdentity());
            self::assertSame(0, $updates->fixed);
            self::assertSame([], $syncs);
            Event::assertNotDispatched(ReleaseNameFixed::class);
            $property = new \ReflectionProperty($updates, 'admittedIdentity');
            self::assertNull($property->getValue($updates), 'Rejected guard must not retain admitted identity.');
        } elseif ($route === 'completion') {
            self::assertSame(1, (int) $release->fresh()->proc_files);
        } else {
            self::assertTrue($updates->matched);
            self::assertTrue($updates->done);
            self::assertSame($title, $release->fresh()->searchname);
        }
    }

    public function test_repeated_same_link_attachment_is_idempotent(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => $title]);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $updater = $this->updater();
        $calls = 0;
        $guard = static function () use (&$calls): bool {
            $calls++;

            return true;
        };
        self::assertTrue($updater->attachPredbSnapshot($input, 7, $title, null, $guard));
        $after = DB::table('releases')->where('id', $release->id)->first();
        self::assertTrue($updater->attachPredbSnapshot($input, 7, $title, null, $guard));
        self::assertEquals($after, DB::table('releases')->where('id', $release->id)->first());
        self::assertSame(4, $calls);
        self::assertTrue($updater->matched);
        self::assertTrue($updater->done);
        self::assertSame(0, $updater->fixed);
        self::assertNotNull($updater->lastCommittedIdentity());
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_attachment_cannot_replace_a_different_existing_link(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert([['id' => 7, 'title' => $title], ['id' => 8, 'title' => 'Synthetic.Other.Release-GRP']]);
        $release = $this->pendingPar2Release(['searchname' => $title, 'predb_id' => 8]);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $before = DB::table('releases')->where('id', $release->id)->first();
        $updater = $this->updater();
        self::assertFalse($updater->attachPredbSnapshot($input, 7, $title, null, static fn (): bool => true));
        self::assertEquals($before, DB::table('releases')->where('id', $release->id)->first());
        self::assertFalse($updater->matched);
        self::assertFalse($updater->done);
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame(0, $updater->fixed);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_guarded_postcommit_callback_failure_preserves_committed_sql(): void
    {
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        $title = 'Synthetic.Canonical.Release-GRP';
        DB::table('predb')->insert(['id' => 7, 'title' => $title]);
        $release = $this->pendingPar2Release(['searchname' => 'Synthetic.Input']);
        $input = (object) [...$release->getAttributes(), 'releases_id' => $release->id];
        $updater = $this->updater();
        $connection = DB::connection();
        $failure = new \RuntimeException('Synthetic postcommit callback failure.');
        $calls = 0;
        $guard = static function () use (&$calls, $connection, $failure): bool {
            if (++$calls === 1) {
                $connection->afterCommit(static fn () => throw $failure);
            }

            return true;
        };
        $observed = null;
        try {
            $updater->updateRelease($input, $title, 'PreDB', true, 'Filenames, ', true, false, 7, null, $guard);
        } catch (\RuntimeException $exception) {
            $observed = $exception;
        }
        self::assertSame($failure, $observed);
        self::assertSame(2, $calls);
        self::assertSame($title, $release->fresh()->searchname);
        self::assertSame(7, (int) $release->fresh()->predb_id);
        self::assertSame(0, $connection->transactionLevel());
        self::assertFalse($connection->getPdo()->inTransaction());
        $laterCallback = false;
        DB::transaction(static function () use (&$laterCallback): void {
            DB::afterCommit(static function () use (&$laterCallback): void {
                $laterCallback = true;
            });
        });
        self::assertTrue($laterCallback);
        self::assertSame($title, $release->fresh()->searchname);
    }

    private function updater(): ReleaseUpdateService
    {
        return new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
    }
}
