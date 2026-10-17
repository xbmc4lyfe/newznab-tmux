<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Nzb\NzbService;
use App\Services\Nzb\NzbSourceGuard;
use App\Services\Nzb\NzbSourceInstallation;
use App\Support\ReleaseArchiveNamingEvidence;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ReleaseArchiveNamingEvidenceTest extends TestCase
{
    private string $directory;

    private object $release;

    private NzbSourceGuard $sources;

    private ReleaseArchiveNamingEvidence $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/archive-naming-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        config(['nntmux_settings.path_to_nzbs' => $this->directory]);
        Schema::create('releases', static function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('guid');
        });
        Schema::create('release_files', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id');
            $table->string('name');
        });
        (require base_path('database/migrations/2026_10_16_010000_create_release_archive_naming_evidence_table.php'))->up();
        DB::table('releases')->insert(['id' => 1, 'guid' => 'fixture-guid']);
        $this->release = DB::table('releases')->first();
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('buildNzbPath')->with('fixture-guid', 0, true)->andReturn($this->directory.'/');
        $nzb->shouldReceive('getNzbPath')->with('fixture-guid')->andReturn($this->path());
        $nzb->shouldReceive('nzbPath')->andReturnUsing(fn (string $guid): string|false => is_file($this->path()) ? $this->path() : false);
        $this->sources = new NzbSourceGuard($nzb);
        $this->app->instance(NzbSourceGuard::class, $this->sources);
        $this->evidence = new ReleaseArchiveNamingEvidence($this->sources);
        $this->app->instance(ReleaseArchiveNamingEvidence::class, $this->evidence);
        $this->writeSource('generation one');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_incomplete_can_clear_only_with_authoritative_current_generation_and_witness(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'incomplete', ['Show.S01E01.mkv']);
        self::assertFalse($this->allows('Show.S01E01'));
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv']);
        self::assertFalse($this->allows('Show.S01E01'));
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertTrue($this->allows('Show.S01E01'));
        self::assertFalse($this->allows('Show.S01E02'));
        self::assertTrue($this->allows('Movie.2026.1080p'));
    }

    public function test_same_generation_pack_is_monotonic_but_a_new_generation_has_new_witnesses(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'multi_episode', ['Show.S01E01.mkv', 'Show.S01E02.mkv']);
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertFalse($this->allows('Show.S01E01'));
        DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'Show.S01E01.mkv']);
        $this->writeSource('generation two');
        $this->record($this->digest(), 'complete_single', ['Show.S01E03.mkv'], true);
        self::assertFalse($this->allows('Show.S01E01', 'Show.S01E01'));
        self::assertTrue($this->allows('Show.S01E03', 'Show.S01E03'));
        self::assertSame(1, DB::table('release_files')->count());
    }

    public function test_stale_observation_and_rollback_restored_proof_do_not_authorize_replaced_file(): void
    {
        $old = $this->digest();
        $this->record($old, 'complete_single', ['Show.S01E01.mkv'], true);
        $temporary = $this->directory.'/replacement.tmp';
        file_put_contents($temporary, gzencode('generation two'));
        DB::beginTransaction();
        $receipt = $this->sources->install($this->release, $temporary, $this->path(), $old);
        DB::rollBack();
        $receipt->close();
        self::assertFalse($this->record($old, 'complete_single', ['Show.S01E01.mkv'], true));
        self::assertFalse($this->allows('Show.S01E01'));
    }

    public function test_rollback_receipt_does_not_delete_successor_with_identical_bytes(): void
    {
        $temporary = $this->directory.'/first.tmp';
        file_put_contents($temporary, gzencode('same generation'));
        $first = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path()));
        $temporary = $this->directory.'/second.tmp';
        file_put_contents($temporary, gzencode('same generation'));
        $second = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path()));
        $first->rollback();
        $second->close();
        self::assertFileExists($this->path());
        self::assertSame('same generation', gzdecode(file_get_contents($this->path())));
    }

    public function test_historical_multi_episode_catalog_is_not_a_permissive_missing_row(): void
    {
        foreach (['Show.S01E01.mkv', 'Show.S01E02.mkv'] as $name) {
            DB::table('release_files')->insert(['releases_id' => 1, 'name' => $name]);
        }
        self::assertFalse($this->allows('Show.S01E01'));
    }

    public function test_witness_overflow_cannot_be_recorded_as_complete(): void
    {
        $names = array_map(static fn (int $i): string => 'Show.S01E01.'.$i.'.mkv', range(1, 257));
        $this->record($this->digest(), 'complete_single', $names, true);
        self::assertFalse($this->allows('Show.S01E01.1'));
    }

    public function test_missing_schema_is_not_a_legacy_admission(): void
    {
        Schema::drop('release_archive_naming_evidence');
        $this->expectException(QueryException::class);
        $this->allows('Show.S01E01');
    }

    public function test_stale_finalization_never_invokes_inventory_callback(): void
    {
        $old = $this->digest();
        $this->writeSource('replacement');
        $accepted = DB::transaction(fn (): bool => $this->evidence->recordLocked(
            $this->release, $old, 'complete_single', ['Show.S01E01.mkv'], true,
            static function (): void {
                DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'Show.S01E01.mkv']);
            },
        ));
        self::assertFalse($accepted);
        self::assertSame(0, DB::table('release_files')->count());
    }

    public function test_retaining_stronger_pack_evidence_still_persists_current_inventory(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'multi_episode', ['Show.S01E01.mkv', 'Show.S01E02.mkv']);
        $accepted = DB::transaction(fn (): bool => $this->evidence->recordLocked(
            $this->release, $digest, 'incomplete', ['Show.S01E01.mkv'], false,
            static function (): void {
                DB::table('release_files')->insert(['releases_id' => 1, 'name' => 'Show.S01E01.mkv']);
            },
        ));
        self::assertTrue($accepted);
        self::assertSame(1, DB::table('release_files')->count());
        self::assertFalse($this->allows('Show.S01E01'));
    }

    public function test_independent_partial_observations_accumulate_pack_evidence(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'incomplete', ['Show.S01E01.mkv']);
        $this->record($digest, 'incomplete', ['Show.S01E02.mkv']);
        $this->record($digest, 'complete_single', ['Show.S01E02.mkv'], true);
        self::assertSame('multi_episode', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertFalse($this->allows('Show.S01E02'));
    }

    public function test_later_partial_episode_contradicts_same_source_complete_single_proof(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertTrue($this->allows('Show.S01E01'));
        $this->record($digest, 'incomplete', ['Show.S01E02.mkv']);
        self::assertSame('multi_episode', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertFalse($this->allows('Show.S01E01'));
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertFalse($this->allows('Show.S01E01'));
    }

    public function test_witness_cap_does_not_erase_a_later_contradictory_episode(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        $names = array_map(static fn (int $i): string => 'Show.S01E01.Copy'.$i.'.mkv', range(1, 256));
        $names[] = 'Show.S01E02.mkv';
        $this->record($digest, 'incomplete', $names);
        self::assertSame('incomplete', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertFalse($this->allows('Show.S01E01'));
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertTrue($this->allows('Show.S01E01'));
    }

    public function test_producer_omission_invalidates_complete_proof_without_inventing_a_pack(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        DB::transaction(fn (): bool => $this->evidence->recordLocked(
            $this->release, $digest, 'incomplete', ['Show.S01E01.mkv'], false, null, true,
        ));
        self::assertSame('incomplete', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertFalse($this->allows('Show.S01E01'));
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        self::assertTrue($this->allows('Show.S01E01'));
    }

    public function test_ordinary_partial_retry_without_omitted_names_keeps_complete_proof(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'complete_single', ['Show.S01E01.mkv'], true);
        $this->record($digest, 'incomplete', ['Show.S01E01.mkv']);
        self::assertSame('complete_single', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertTrue($this->allows('Show.S01E01'));
    }

    public function test_omitted_names_cannot_clear_a_same_generation_confirmed_pack(): void
    {
        $digest = $this->digest();
        $this->record($digest, 'multi_episode', ['Show.S01E01.mkv', 'Show.S01E02.mkv']);
        DB::transaction(fn (): bool => $this->evidence->recordLocked(
            $this->release, $digest, 'incomplete', ['Show.S01E01.mkv'], false, null, true,
        ));
        self::assertSame('multi_episode', DB::table('release_archive_naming_evidence')->value('scope'));
        self::assertFalse($this->allows('Show.S01E01'));
    }

    public function test_malformed_evidence_never_enters_legacy_compatibility(): void
    {
        $this->record($this->digest(), 'complete_single', ['Show.S01E01.mkv'], true);
        DB::table('release_archive_naming_evidence')->update(['episode_files' => '{}']);
        $this->expectException(RuntimeException::class);
        $this->allows('Show.S01E01');
    }

    public function test_missing_schema_does_not_replace_the_old_source(): void
    {
        $old = $this->digest();
        $temporary = $this->directory.'/replacement.tmp';
        file_put_contents($temporary, gzencode('replacement'));
        Schema::drop('release_archive_naming_evidence');
        try {
            DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path()));
            self::fail('Missing schema must reject installation.');
        } catch (QueryException) {
            self::assertSame($old, $this->digest());
        }
    }

    public function test_receipt_removes_only_its_owned_installation_and_leaves_stable_lock_inode(): void
    {
        $temporary = $this->directory.'/replacement.tmp';
        file_put_contents($temporary, gzencode('replacement'));
        $receipt = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path()));
        $locks = glob($this->directory.'/.archive-source-*.lock');
        self::assertCount(1, $locks);
        $inode = fileinode($locks[0]);
        $receipt->rollback();
        self::assertFileDoesNotExist($this->path());
        self::assertSame($inode, fileinode($locks[0]));
    }

    public function test_rolled_back_preserving_replacement_restores_the_replaced_source(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::beginTransaction();
        $receipt = $this->sources->install($this->release, $temporary, $this->path(), preserve: true);
        DB::afterRollBack(fn () => $receipt->rollback());
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
        DB::rollBack();

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())), 'The partial keeps its NZB.');
        self::assertSame([], glob($this->path().'.*.prev'));
    }

    public function test_failed_preserving_install_after_the_move_restores_the_replaced_source(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));
        // The move succeeds with a file other than the staged one, so the identity check throws afterwards.
        $move = function (string $from, string $to): bool {
            $other = $this->directory.'/other.tmp';
            file_put_contents($other, gzencode('intruder'));

            return rename($other, $to);
        };

        DB::beginTransaction();
        try {
            $this->sources->install($this->release, $temporary, $this->path(), move: $move, preserve: true);
            self::fail('The identity check must reject the swapped file.');
        } catch (RuntimeException $exception) {
            self::assertSame('Staged NZB installation identity changed.', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())), 'The only link to the old NZB is not lost.');
        self::assertSame([], glob($this->path().'.*.prev'));
    }

    public function test_failed_preserving_install_before_the_move_drops_only_its_backup(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::beginTransaction();
        try {
            $this->sources->install($this->release, $temporary, $this->path(), move: static fn (): bool => false, preserve: true);
            self::fail('A failed move must be reported.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to move temporary NZB into place.', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], glob($this->path().'.*.prev'));
    }

    public function test_committed_preserving_replacement_discards_its_backup(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::transaction(function () use ($temporary): void {
            $receipt = $this->sources->install($this->release, $temporary, $this->path(), preserve: true);
            DB::afterCommit(static fn () => $receipt->close());
        });

        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], glob($this->path().'.*.prev'));
    }

    public function test_full_rollback_cleanup_does_not_corrupt_the_next_transaction_callbacks(): void
    {
        DB::beginTransaction();
        $receipt = $this->installation('full rollback');
        DB::afterRollBack(fn () => $receipt->rollback());
        DB::rollBack();
        self::assertFileDoesNotExist($this->path());
        $called = 0;
        DB::transaction(static function () use (&$called): void {
            DB::afterCommit(static function () use (&$called): void {
                $called++;
            });
        });
        self::assertSame(1, $called);
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_nested_rollback_cleanup_preserves_outer_transaction_and_its_callbacks(): void
    {
        $committed = 0;
        DB::beginTransaction();
        DB::afterCommit(static function () use (&$committed): void {
            $committed++;
        });
        DB::beginTransaction();
        $receipt = $this->installation('nested rollback');
        DB::afterRollBack(fn () => $receipt->rollback());
        DB::rollBack();
        self::assertSame(1, DB::transactionLevel());
        self::assertFileDoesNotExist($this->path());
        DB::commit();
        self::assertSame(1, $committed);
    }

    public function test_failed_cleanup_does_not_reenter_rollback_or_damage_following_transactions(): void
    {
        DB::beginTransaction();
        $receipt = $this->installation('cleanup failure');
        $calls = 0;
        DB::afterRollBack(static function () use ($receipt, &$calls): void {
            $calls++;
            $receipt->rollback();
        });
        $moved = $this->directory.'-moved';
        rename($this->directory, $moved);
        try {
            DB::rollBack();
            self::assertSame(1, $calls);
            self::assertSame(0, DB::transactionLevel());
            self::assertFalse(DB::connection()->getPdo()->inTransaction());
            $committed = false;
            DB::transaction(static function () use (&$committed): void {
                DB::afterCommit(static function () use (&$committed): void {
                    $committed = true;
                });
            });
            self::assertTrue($committed);
        } finally {
            rename($moved, $this->directory);
        }
    }

    public function test_cleanup_sql_failure_preserves_rollback_manager_unwind(): void
    {
        DB::beginTransaction();
        $receipt = $this->installation('SQL cleanup failure');
        DB::afterRollBack(static function () use ($receipt): void {
            Schema::drop('releases');
            $receipt->rollback();
        });
        DB::rollBack();
        self::assertSame(0, DB::transactionLevel());
        self::assertFalse(DB::connection()->getPdo()->inTransaction());
        $committed = false;
        DB::transaction(static function () use (&$committed): void {
            DB::afterCommit(static function () use (&$committed): void {
                $committed = true;
            });
        });
        self::assertTrue($committed);
        self::assertFileExists($this->path());
    }

    public function test_deletion_keeps_orphan_guard_and_can_remove_an_undecodable_source(): void
    {
        file_put_contents($this->path(), 'broken gzip');
        self::assertFalse($this->sources->delete('fixture-guid', onlyIfOrphan: true));
        self::assertFileExists($this->path());
        self::assertTrue($this->sources->delete('fixture-guid'));
        file_put_contents($this->path(), 'orphan gzip');
        DB::table('releases')->delete();
        self::assertFalse($this->sources->delete('fixture-guid', onlyIfOrphan: true));
        self::assertFalse($this->sources->deleteOrphan('fixture-guid', $this->path()));
        self::assertFileExists($this->path());
    }

    public function test_audit_anchor_removes_the_committed_deleted_source_and_its_owned_anchor(): void
    {
        $receipt = DB::transaction(function (): array {
            $receipt = $this->sources->captureDeletionAnchor($this->release);
            DB::table('releases')->delete();

            return $receipt;
        });
        self::assertFileExists($receipt['anchor']);
        self::assertTrue($this->sources->deleteAnchoredSource($receipt));
        self::assertFileDoesNotExist($this->path());
        self::assertFileDoesNotExist($receipt['anchor']);
        self::assertTrue($this->sources->deleteAnchoredSource($receipt));
    }

    public function test_audit_anchor_cleanup_survives_service_restart_and_retains_identical_successor(): void
    {
        $receipt = DB::transaction(fn (): array => $this->sources->captureDeletionAnchor($this->release));
        $serialized = json_encode($receipt, JSON_THROW_ON_ERROR);
        $temporary = $this->directory.'/successor.tmp';
        file_put_contents($temporary, file_get_contents($this->path()));
        $successor = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path()));
        $successor->close();
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('buildNzbPath')->with('fixture-guid', 0, true)->andReturn($this->directory.'/');
        $restarted = new NzbSourceGuard($nzb);
        self::assertTrue($restarted->deleteAnchoredSource(json_decode($serialized, true, flags: JSON_THROW_ON_ERROR)));
        self::assertFileExists($this->path());
        self::assertFileDoesNotExist($receipt['anchor']);
    }

    public function test_audit_anchor_rollback_removes_only_the_anchor_and_preserves_source(): void
    {
        DB::beginTransaction();
        $receipt = $this->sources->captureDeletionAnchor($this->release);
        DB::table('releases')->delete();
        DB::rollBack();
        self::assertFileExists($this->path());
        self::assertFileDoesNotExist($receipt['anchor']);
        self::assertSame(1, DB::table('releases')->count());
        self::assertSame(0, DB::transactionLevel());
    }

    public function test_missing_audit_anchor_cannot_authorize_deleting_a_retained_source(): void
    {
        $receipt = DB::transaction(fn (): array => $this->sources->captureDeletionAnchor($this->release));
        unlink($receipt['anchor']);
        self::assertFalse($this->sources->deleteAnchoredSource($receipt));
        self::assertFileExists($this->path());
    }

    public function test_replaced_audit_anchor_is_not_ownership_proof(): void
    {
        $receipt = DB::transaction(fn (): array => $this->sources->captureDeletionAnchor($this->release));
        unlink($receipt['anchor']);
        file_put_contents($receipt['anchor'], 'unrelated anchor');
        self::assertFalse($this->sources->deleteAnchoredSource($receipt));
        self::assertFileExists($this->path());
        self::assertSame('unrelated anchor', file_get_contents($receipt['anchor']));
    }

    public function test_audit_anchor_supports_raw_corrupt_sources_without_decoding(): void
    {
        file_put_contents($this->path(), 'invalid gzip');
        $receipt = DB::transaction(fn (): array => $this->sources->captureDeletionAnchor($this->release));
        self::assertTrue($this->sources->deleteAnchoredSource($receipt));
        self::assertFileDoesNotExist($this->path());
    }

    public function test_absent_at_audit_capture_does_not_authorize_deleting_a_later_source(): void
    {
        unlink($this->path());
        $receipt = DB::transaction(fn (): array => $this->sources->captureDeletionAnchor($this->release));
        self::assertSame('absent', $receipt['kind']);
        self::assertArrayNotHasKey('anchor', $receipt);
        $this->writeSource('a later publisher owns this file');
        self::assertTrue($this->sources->deleteAnchoredSource($receipt));
        self::assertSame('a later publisher owns this file', gzdecode(file_get_contents($this->path())));
    }

    public function test_failed_audit_rollback_registration_removes_only_new_anchor(): void
    {
        $connection = DB::connection();
        $manager = (new \ReflectionProperty($connection, 'transactionsManager'))->getValue($connection);
        DB::beginTransaction();
        $connection->unsetTransactionManager();
        try {
            try {
                $this->sources->captureDeletionAnchor($this->release);
                self::fail('Missing transaction manager must reject the audit receipt.');
            } catch (RuntimeException $exception) {
                self::assertSame('Transactions Manager has not been set.', $exception->getMessage());
            }
            self::assertSame([], glob($this->directory.'/.archive-audit-*.anchor'));
            self::assertFileExists($this->path());
        } finally {
            $connection->setTransactionManager($manager);
            DB::rollBack();
        }
    }

    private function installation(string $contents): NzbSourceInstallation
    {
        $temporary = $this->directory.'/receipt.tmp';
        file_put_contents($temporary, gzencode($contents));

        return $this->sources->install($this->release, $temporary, $this->path());
    }

    private function path(): string
    {
        return $this->directory.'/fixture-guid.nzb.gz';
    }

    private function writeSource(string $contents): void
    {
        file_put_contents($this->path(), gzencode($contents));
    }

    private function digest(): string
    {
        $snapshot = $this->sources->snapshot('fixture-guid');
        self::assertNotNull($snapshot);

        return $snapshot['digest'];
    }

    /** @param list<string> $names */
    private function record(string $digest, string $scope, array $names, bool $complete = false): bool
    {
        return DB::transaction(fn (): bool => $this->evidence->recordLocked($this->release, $digest, $scope, $names, $complete));
    }

    private function allows(string $title, ?string $filename = null): bool
    {
        return DB::transaction(fn (): bool => $this->evidence->allowsEpisodeLocked($this->release, $title, $filename));
    }
}
