<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ReleaseNameFixed;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class ReleaseUpdateIdentityAttestationTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const TITLE = 'ROSALÍA.Album.2026-GRP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        config(['nntmux.echocli' => false]);
        Event::fake([ReleaseNameFixed::class]);
        DB::table('predb')->insert(['id' => 7, 'title' => self::TITLE]);
    }

    public function test_committed_canonical_identity_is_available_through_service_and_immutable(): void
    {
        $input = $this->input();
        $updater = $this->updater();
        $service = new NameFixingService($updater);

        $this->rename($updater, $input);

        $proof = $service->lastCommittedIdentity();
        self::assertSame($this->proof(), $proof);
        $input->guid = 'caller mutation';
        $proof['after']['searchname'] = 'consumer mutation';
        self::assertSame($this->proof(), $updater->lastCommittedIdentity());
        self::assertSame(self::TITLE, DB::table('releases')->where('id', 42)->value('searchname'));
    }

    #[DataProvider('commitChoices')]
    public function test_outer_transaction_controls_attestation_visibility(bool $commit): void
    {
        $input = $this->input();
        $updater = $this->updater();
        DB::beginTransaction();
        $this->rename($updater, $input);
        self::assertNull($updater->lastCommittedIdentity());

        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        self::assertSame($commit ? $this->proof() : null, $updater->lastCommittedIdentity());
        self::assertSame($commit ? self::TITLE : 'obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
    }

    /** @return array<string, array{bool}> */
    public static function commitChoices(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    #[DataProvider('sameNamePaths')]
    public function test_same_name_attachment_and_verified_noop_attest_the_locked_identity(string $path): void
    {
        $input = $this->input(self::TITLE);
        $updater = $this->updater();
        if ($path === 'already-linked') {
            DB::table('releases')->where('id', 42)->update(['predb_id' => 7]);
        }

        $accepted = $path === 'fulltext'
            ? $updater->updatePredbCandidate($input, self::TITLE, 7, 'fixture')
            : $updater->attachPredbSnapshot($input, 7);

        self::assertTrue($accepted);
        self::assertSame($this->proof(self::TITLE), $updater->lastCommittedIdentity());
        self::assertSame(7, DB::table('releases')->where('id', 42)->value('predb_id'));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    /** @return array<string, array{string}> */
    public static function sameNamePaths(): array
    {
        return ['attach' => ['attach'], 'already linked' => ['already-linked'], 'fulltext' => ['fulltext']];
    }

    #[DataProvider('clearingActions')]
    public function test_reset_preview_and_rejected_attempt_clear_a_previous_attestation(string $action): void
    {
        $input = $this->input();
        $updater = $this->updater();
        $this->rename($updater, $input);
        self::assertNotNull($updater->lastCommittedIdentity());

        if ($action === 'reset') {
            $updater->reset();
        } elseif ($action === 'preview') {
            $this->rename($updater, $input, false);
        } else {
            $updater->attachPredbSnapshot($input, 999);
        }

        self::assertNull($updater->lastCommittedIdentity());
    }

    /** @return array<string, array{string}> */
    public static function clearingActions(): array
    {
        return ['reset' => ['reset'], 'preview' => ['preview'], 'rejection' => ['rejection']];
    }

    #[DataProvider('resetTimings')]
    public function test_reset_invalidates_queued_attestation_even_after_commit(bool $afterCommit): void
    {
        $input = $this->input();
        $updater = $this->updater();
        DB::beginTransaction();
        if ($afterCommit) {
            DB::afterCommit(static fn () => $updater->reset());
        }
        $this->rename($updater, $input);
        if (! $afterCommit) {
            $updater->reset();
        }

        DB::commit();

        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame(self::TITLE, DB::table('releases')->where('id', 42)->value('searchname'));
    }

    /** @return array<string, array{bool}> */
    public static function resetTimings(): array
    {
        return ['before commit' => [false], 'after commit' => [true]];
    }

    public function test_authoritative_identity_loss_rolls_back_without_attestation(): void
    {
        $input = $this->input();
        $updater = $this->updater();
        $fired = false;
        DB::listen(static function (QueryExecuted $event) use (&$fired): void {
            if (! $fired && str_starts_with($event->sql, 'update "releases"')) {
                $fired = true;
                DB::table('predb')->where('id', 7)->update(['title' => 'Different.Title.2026-OTHER']);
            }
        });

        $this->rename($updater, $input);

        self::assertTrue($fired);
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame('obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_legacy_partial_input_and_query_alias_mutation_use_actual_locked_identity(): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $updater = $this->updater();
        $fired = false;
        DB::listen(static function (QueryExecuted $event) use ($input, &$fired): void {
            if (! $fired && str_starts_with($event->sql, 'update "releases"')) {
                $fired = true;
                $input->releases_id = 999;
                $input->searchname = 'Aliased title';
            }
        });

        $updater->updateRelease($input, 'Exact.Release.2026-GRP', 'Title Match fixture', true, 'PAR2 hash, ', true, false);

        self::assertTrue($fired);
        self::assertSame($this->proof('obfuscated', 'Exact.Release.2026-GRP'), $updater->lastCommittedIdentity());
    }

    public function test_case_only_canonical_resolution_counts_the_actual_committed_rename(): void
    {
        $input = $this->input();
        $updater = $this->updater();

        $this->rename($updater, $input);

        self::assertSame(self::TITLE, DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame(7, DB::table('releases')->where('id', 42)->value('predb_id'));
        self::assertTrue($updater->matched);
        self::assertSame(1, $updater->fixed);
    }

    /** @return iterable<string, array{bool}> */
    public static function commitBoundaryPaths(): iterable
    {
        yield 'confirmed canonical write' => [true];
        yield 'guarded unconfirmed write' => [false];
    }

    #[DataProvider('commitBoundaryPaths')]
    public function test_identity_replaced_before_outer_commit_cannot_publish_an_intermediate_identity_proof(bool $confirmed): void
    {
        $input = $this->input();
        $updater = $this->updater();
        DB::beginTransaction();
        try {
            if ($confirmed) {
                $this->rename($updater, $input);
            } else {
                $updater->updateRelease($input, 'Scene.Movie.2026.1080p.WEB-DL-GRP', 'fixture', true, 'NFO, ', true, false);
            }
            self::assertNull($updater->lastCommittedIdentity());
            DB::table('releases')->where('id', 42)->update([
                'guid' => 'replacement-guid', 'name' => 'Replacement.Subject', 'searchname' => 'Replacement.Identity',
            ]);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $exception;
        }

        $current = DB::table('releases')->where('id', 42)->first();
        self::assertSame(['replacement-guid', 'Replacement.Subject', 'Replacement.Identity'], [$current->guid, $current->name, $current->searchname]);
        self::assertNull($updater->lastCommittedIdentity());
    }

    private function input(string $searchname = 'obfuscated'): object
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'original-guid', 'name' => 'original subject', 'searchname' => $searchname]);

        return (object) array_merge($release->getAttributes(), ['releases_id' => 42]);
    }

    private function updater(): ReleaseUpdateService
    {
        return new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {}));
    }

    private function rename(ReleaseUpdateService $updater, object $input, bool $write = true): void
    {
        $updater->updateRelease($input, 'rosalía.album.2026-grp', 'Title Match fixture', $write, 'PAR2, ', true, false, 7);
    }

    /** @return array{before: array{id: int, guid: string, name: string, searchname: string}, after: array{id: int, guid: string, name: string, searchname: string}} */
    private function proof(string $before = 'obfuscated', string $after = self::TITLE): array
    {
        return ['before' => ['id' => 42, 'guid' => 'original-guid', 'name' => 'original subject', 'searchname' => $before],
            'after' => ['id' => 42, 'guid' => 'original-guid', 'name' => 'original subject', 'searchname' => $after]];
    }
}
