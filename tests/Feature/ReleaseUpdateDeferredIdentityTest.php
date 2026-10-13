<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\Par2PacketAccumulator;
use App\Support\Par2CandidateProgress;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class ReleaseUpdateDeferredIdentityTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const TITLE = 'Exact.Release.2026-GROUP';

    /** @var list<int> */
    private array $synchronized = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        DB::statement('PRAGMA foreign_keys = ON');
        Event::fake([ReleaseNameFixed::class]);
        config(['nntmux.echocli' => false]);
    }

    #[DataProvider('missingFields')]
    public function test_partial_legacy_object_captures_actual_identity_and_cleans_completed_buffers(string $missing): void
    {
        $release = $this->pendingPar2Release(['name' => 'Actual subject', 'searchname' => 'Old.Title']);
        $this->saveCheckpoint($release);
        $input = $this->legacyInput($release);
        unset($input->{$missing});
        $expected = $this->snapshot($release);
        $expected['searchname'] = self::TITLE;

        $this->rename($input);

        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame([$release->id], $this->synchronized);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->expectedSnapshot === $expected);
    }

    /** @return iterable<string, array{string}> */
    public static function missingFields(): iterable
    {
        yield 'missing GUID' => ['guid'];
        yield 'missing subject' => ['name'];
    }

    #[DataProvider('resetIdentities')]
    public function test_partial_legacy_cleanup_preserves_new_pending_buffers_before_outer_commit(bool $replacement): void
    {
        $release = $this->pendingPar2Release(['name' => 'Actual subject', 'searchname' => 'Old.Title']);
        $input = $this->legacyInput($release);
        unset($input->guid);
        DB::beginTransaction();
        $this->rename($input);
        $reset = ['isrenamed' => 0, 'proc_par2' => 0, 'predb_id' => 0];
        if ($replacement) {
            $reset += ['guid' => 'Replacement', 'name' => 'Replacement subject', 'searchname' => 'Replacement title'];
        }
        DB::table('releases')->where('id', $release->id)->update($reset);
        $this->saveCheckpoint($release->fresh());
        Event::assertNotDispatched(ReleaseNameFixed::class);

        DB::commit();

        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(0, $release->fresh()->isrenamed);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    /** @return iterable<string, array{bool}> */
    public static function resetIdentities(): iterable
    {
        yield 'same identity pending again' => [false];
        yield 'replacement at same ID' => [true];
    }

    public function test_rollback_suppresses_partial_legacy_cleanup_and_publication(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Old.Title']);
        $this->saveCheckpoint($release);
        $input = $this->legacyInput($release);
        unset($input->guid);
        DB::beginTransaction();
        $this->rename($input);

        DB::rollBack();

        self::assertSame('Old.Title', $release->fresh()->searchname);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame([], $this->synchronized);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    #[DataProvider('mutationQueries')]
    public function test_query_time_alias_mutation_cannot_redirect_callbacks_or_change_event_fields(string $queryPrefix): void
    {
        $release = $this->pendingPar2Release(['name' => 'Actual subject', 'searchname' => 'Old.Title']);
        $other = $this->pendingPar2Release(['searchname' => 'Other.Title']);
        $this->saveCheckpoint($release);
        $this->saveCheckpoint($other);
        $input = $this->legacyInput($release);
        unset($input->guid);
        $expected = $this->snapshot($release);
        $expected['searchname'] = self::TITLE;
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use ($input, $other, $queryPrefix, &$changed): void {
            if (str_starts_with($query->sql, $queryPrefix) && ! $changed) {
                $changed = true;
                $input->releases_id = $other->id;
                $input->searchname = 'Mutated alias';
                $input->categories_id = 999;
                $input->groups_id = 99;
                $input->fromname = 'Mutated poster';
            }
        });

        $this->rename($input);

        self::assertTrue($changed);
        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame('Other.Title', $other->fresh()->searchname);
        self::assertSame([$other->id], DB::table('par2_rename_candidate_progress')->pluck('releases_id')->all());
        self::assertSame([$release->id], $this->synchronized);
        Event::assertDispatched(ReleaseNameFixed::class, static fn (ReleaseNameFixed $event): bool => $event->expectedSnapshot === $expected
            && $event->releaseId === $release->id && $event->oldName === 'Old.Title'
            && $event->oldCategoryId === $release->categories_id && $event->groupId === $release->groups_id
            && $event->poster === (string) $release->fromname);
    }

    /** @return iterable<string, array{string}> */
    public static function mutationQueries(): iterable
    {
        yield 'locked read' => ['select * from "releases"'];
        yield 'release update' => ['update "releases"'];
    }

    public function test_unavailable_actual_guid_leaves_existing_buffers_intact(): void
    {
        $release = $this->pendingPar2Release(['searchname' => 'Old.Title']);
        $this->saveCheckpoint($release);
        $input = $this->legacyInput($release);
        unset($input->guid);
        DB::table('releases')->where('id', $release->id)->update(['guid' => null]);

        $this->rename($input);

        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    private function rename(object $input): void
    {
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, function (int $id): void {
            $this->synchronized[] = $id;
        });
        $updater = new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: $sync);
        $updater->updateRelease($input, self::TITLE, 'preDB: Match', true, 'PAR2, ', true, false);
    }

    private function legacyInput(Release $release): object
    {
        return (object) array_merge($release->getAttributes(), ['releases_id' => $release->id]);
    }

    private function saveCheckpoint(Release $release): void
    {
        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release), str_repeat('a', 64), str_repeat('b', 64), 0, [], (new Par2PacketAccumulator)->exportState(), 1, 10000, static fn (): bool => true));
    }

    /** @return array{id:int,guid:string,name:?string,searchname:?string} */
    private function snapshot(Release $release): array
    {
        return ['id' => $release->id, 'guid' => $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
    }
}
