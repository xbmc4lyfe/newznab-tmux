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
use App\Support\ReleasePackEvidence;
use Illuminate\Contracts\Console\Kernel;
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

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->useEnvironmentPath(sys_get_temp_dir())->loadEnvironmentFrom('.nntmux-validation-no-env');
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

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

    #[DataProvider('legacyNullSuccessPaths')]
    public function test_legacy_null_partial_admission_keeps_historical_by_id_success(string $missing): void
    {
        $input = $this->input();
        if ($missing !== 'name') {
            unset($input->guid);
        }
        if ($missing !== 'guid') {
            unset($input->name);
        }
        if ($missing === 'string IDs') {
            $input->id = $input->releases_id = '42';
        }
        $sync = [];
        $updater = $this->legacyUpdater($sync);

        $this->legacyRename($updater, $input);

        self::assertTrue($updater->matched);
        self::assertSame($this->proof('obfuscated', 'Exact.Release.2026-GRP'), $updater->lastCommittedIdentity());
        self::assertSame([42], $sync);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    public static function legacyNullSuccessPaths(): iterable
    {
        foreach (['guid', 'name', 'both', 'string IDs'] as $missing) {
            yield $missing => [$missing];
        }
    }

    #[DataProvider('legacyNullRejections')]
    public function test_legacy_null_partial_admission_rejects_unobserved_or_stale_boundaries(string $scenario): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $current = DB::table('releases')->where('id', 42)->first();
        $tombstone = ReleasePackEvidence::tombstone($current);
        if ($scenario === 'supplied guid') {
            $input->guid = 'stale-guid';
        } elseif ($scenario === 'supplied name') {
            $input->name = 'stale subject';
        } elseif ($scenario === 'supplied searchname') {
            $input->searchname = 'stale title';
        } elseif ($scenario === 'missing JSON') {
            unset($input->additional_pack_evidence);
        } elseif ($scenario === 'captured tombstone') {
            $input->additional_pack_evidence = $tombstone;
        } elseif ($scenario === 'current tombstone') {
            DB::table('releases')->where('id', 42)->update(['additional_pack_evidence' => $tombstone]);
        } elseif (in_array($scenario, ['current probable', 'current confirmed'], true)) {
            $state = substr($scenario, 8);
            DB::table('releases')->where('id', 42)->update(['additional_pack_evidence' => ReleasePackEvidence::merge($current, ReleasePackEvidence::observe($current), $state)]);
        } elseif ($scenario === 'current invalid') {
            DB::table('releases')->where('id', 42)->update(['additional_pack_evidence' => '{"invalid":true}']);
        } elseif ($scenario === 'unpublished') {
            DB::table('releases')->where('id', 42)->update(['nzbstatus' => 0]);
        } elseif ($scenario === 'current token') {
            DB::table('releases')->where('id', 42)->update(['additional_pp_claim_token' => 'owner']);
        } elseif ($scenario === 'current timestamp') {
            DB::table('releases')->where('id', 42)->update(['additional_pp_claimed_at' => now()->toDateTimeString()]);
        } elseif ($scenario === 'expired owner') {
            DB::table('releases')->where('id', 42)->update(['additional_pp_claim_token' => 'old-owner', 'additional_pp_claimed_at' => '2000-01-01 00:00:00']);
        } elseif ($scenario === 'captured token') {
            $input->additional_pp_claim_token = 'old-owner';
        } elseif ($scenario === 'captured timestamp') {
            $input->additional_pp_claimed_at = '2000-01-01 00:00:00';
        } elseif ($scenario === 'linked') {
            DB::table('releases')->where('id', 42)->update(['predb_id' => 7]);
        } elseif ($scenario === 'alias mismatch') {
            $input->id = 43;
        }
        $sync = [];
        $updater = $this->legacyUpdater($sync);

        $this->legacyRename($updater, $input, $scenario === 'confirmed PreDB' ? 7 : 0);

        self::assertFalse($updater->matched);
        self::assertSame('obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame([], $sync);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function legacyNullRejections(): iterable
    {
        foreach (['supplied guid', 'supplied name', 'supplied searchname', 'missing JSON', 'captured tombstone', 'current tombstone', 'current probable', 'current confirmed', 'current invalid', 'unpublished', 'current token', 'current timestamp', 'expired owner', 'captured token', 'captured timestamp', 'linked', 'alias mismatch', 'confirmed PreDB'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    #[DataProvider('legacyExplicitMarkers')]
    public function test_legacy_null_partial_admission_does_not_replace_an_explicit_observation(string $marker, mixed $value): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $input->{$marker} = $value;
        $sync = [];
        $updater = $this->legacyUpdater($sync);

        $this->legacyRename($updater, $input);

        self::assertFalse($updater->matched);
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame('obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame([], $sync);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function legacyExplicitMarkers(): iterable
    {
        foreach (['observed_pack_evidence', 'observed_additional_pp_claim', 'observed_par2_hash_generation', 'observed_isrenamed'] as $marker) {
            yield $marker.' NULL' => [$marker, null];
            yield $marker.' supplied' => [$marker, $marker === 'observed_isrenamed' ? 0 : 'stale'];
        }
    }

    #[DataProvider('legacyInvalidIds')]
    public function test_legacy_null_partial_admission_rejects_noncanonical_ids_before_lookup(mixed $id): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $input->id = $input->releases_id = $id;
        $sync = [];
        $updater = $this->legacyUpdater($sync);
        $lookups = [];
        DB::listen(static function (QueryExecuted $event) use (&$lookups): void {
            if (str_contains($event->sql, 'from "releases"')) {
                $lookups[] = $event->sql;
            }
        });

        $this->legacyRename($updater, $input);

        self::assertSame([], $lookups);
        self::assertFalse($updater->matched);
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame([], $sync);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function legacyInvalidIds(): iterable
    {
        foreach (['leading zero' => '042', 'sign' => '+42', 'leading whitespace' => ' 42', 'trailing whitespace' => '42 ', 'fraction string' => '42.0', 'zero' => 0, 'negative' => -42, 'boolean' => true, 'float' => 42.0, 'overflow' => '9223372036854775808', 'zero string' => '0'] as $label => $id) {
            yield $label => [$id];
        }
    }

    #[DataProvider('legacyWriteRaces')]
    public function test_legacy_null_partial_admission_rolls_back_guarded_write_loss_without_success(string $stage, string $column): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $before = (array) DB::table('releases')->where('id', 42)->first();
        $sync = [];
        $updater = $this->legacyUpdater($sync);
        $replacement = match ($column) {
            'guid' => 'replacement-guid', 'name' => 'replacement subject', 'searchname' => 'Replacement.Title',
            'additional_pack_evidence' => ReleasePackEvidence::tombstone((object) $before),
            'additional_pp_claim_token' => 'new-owner', 'nzbstatus' => 0, 'predb_id' => 7,
        };
        $fired = false;
        DB::listen(static function (QueryExecuted $event) use ($stage, $column, $replacement, &$fired): void {
            $target = $stage === 'before UPDATE'
                ? str_starts_with($event->sql, 'select * from "releases" where "id" = ?')
                : str_starts_with($event->sql, 'update "releases"');
            if (! $fired && $target) {
                $fired = true;
                DB::table('releases')->where('id', 42)->update([$column => $replacement]);
            }
        });

        $this->legacyRename($updater, $input);

        self::assertTrue($fired);
        self::assertFalse($updater->matched);
        self::assertSame($before, (array) DB::table('releases')->where('id', 42)->first());
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame([], $sync);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public static function legacyWriteRaces(): iterable
    {
        foreach (['before UPDATE', 'after UPDATE'] as $stage) {
            foreach (['guid', 'name', 'searchname', 'additional_pack_evidence', 'additional_pp_claim_token', 'nzbstatus', 'predb_id'] as $column) {
                yield $stage.' '.$column => [$stage, $column];
            }
        }
    }

    public function test_legacy_null_partial_admission_propagates_unrelated_database_callback_errors(): void
    {
        $input = $this->input();
        unset($input->guid, $input->name);
        $before = (array) DB::table('releases')->where('id', 42)->first();
        $sync = [];
        $updater = $this->legacyUpdater($sync);
        $failure = new \RuntimeException('unrelated database callback failure');
        DB::listen(static function (QueryExecuted $event) use ($failure): void {
            if (str_starts_with($event->sql, 'update "releases"')) {
                throw $failure;
            }
        });
        $caught = null;
        try {
            $this->legacyRename($updater, $input);
        } catch (\RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught);
        self::assertSame($before, (array) DB::table('releases')->where('id', 42)->first());
        self::assertNull($updater->lastCommittedIdentity());
        self::assertSame([], $sync);
        Event::assertNotDispatched(ReleaseNameFixed::class);
    }

    public function test_legacy_null_cli_subject_cleaner_uses_complete_original_projection_and_real_updater(): void
    {
        $subject = '[1/2] - "Movie.2020.1080p-GRP.mkv"';
        $this->input($subject);
        DB::statement('CREATE TABLE release_naming_regexes (id INTEGER PRIMARY KEY, group_regex VARCHAR(255), regex VARCHAR(255), status INTEGER DEFAULT 1, ordinal INTEGER DEFAULT 0)');
        DB::connection()->getPdo()->sqliteCreateFunction('REGEXP', static fn (?string $pattern, ?string $value): int => preg_match('/'.$pattern.'/i', (string) $value));
        Search::shouldReceive('matchPredbExact')->andReturn(null);
        $sync = [];
        $updater = $this->legacyUpdater($sync);
        $this->app->instance(ReleaseUpdateService::class, $updater);
        $candidateReads = [];
        DB::listen(static function (QueryExecuted $event) use (&$candidateReads): void {
            if (str_contains($event->sql, 'from "releases" as "r"')) {
                $candidateReads[] = $event->sql;
            }
        });

        $this->artisan('releases:clean-subject-names')
            ->expectsOutputToContain('Renamed 1 of 1 releases (0 linked to PreDB).')
            ->assertSuccessful();

        self::assertNotEmpty($candidateReads);
        self::assertStringContainsString('"r"."guid"', $candidateReads[0]);
        self::assertStringContainsString('"r"."additional_pack_evidence"', $candidateReads[0]);
        self::assertSame('Movie.2020.1080p-GRP', DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame('original-guid', DB::table('releases')->where('id', 42)->value('guid'));
        self::assertNull(DB::table('releases')->where('id', 42)->value('additional_pack_evidence'));
        self::assertSame($this->proof($subject, 'Movie.2020.1080p-GRP'), $updater->lastCommittedIdentity());
        self::assertSame([42], $sync);
        Event::assertDispatchedTimes(ReleaseNameFixed::class, 1);
    }

    private function legacyUpdater(array &$sync): ReleaseUpdateService
    {
        return new ReleaseUpdateService($this->createStub(CategorizationService::class), searchSyncCoordinator: new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id) use (&$sync): void {
            $sync[] = $id;
        }));
    }

    private function legacyRename(ReleaseUpdateService $updater, object $input, int $preId = 0): void
    {
        $updater->updateRelease($input, 'Exact.Release.2026-GRP', 'Title Match fixture', true, 'PAR2 hash, ', true, false, $preId);
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
