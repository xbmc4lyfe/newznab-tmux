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
            $table->unsignedInteger('articles_expected')->nullable();
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
        $nzb->shouldReceive('nzbPathCandidates')->andReturnUsing(fn (string $guid): array => [$this->path()]);
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
            // Read directly: every locked read consults the (missing) committed evidence.
            self::assertSame($old, NzbSourceGuard::digest((string) gzdecode((string) file_get_contents($this->path()))));
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

    public function test_staged_install_keeps_the_committed_source_in_place_until_its_transaction_commits(): void
    {
        $this->writeSource('original');
        $this->commitEvidence('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));
        $during = [];

        $receipt = DB::transaction(function () use ($temporary, &$during): NzbSourceInstallation {
            $receipt = $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5);
            DB::afterRollBack(static fn () => $receipt->rollback());
            DB::afterCommit(static fn () => $receipt->close());
            // What a reader without the release lock (a download, duplicate coverage) sees before commit.
            $during = [gzdecode((string) file_get_contents($this->path())), $this->staged()];

            return $receipt;
        });

        self::assertSame('original', $during[0], 'The never-committed generation is not at the canonical path.');
        self::assertCount(1, $during[1]);
        self::assertMatchesRegularExpression('/fixture-guid\.nzb\.gz\.c5-[0-9a-f]{16}\.next$/', $during[1][0]);
        self::assertSame($this->path(), $receipt->path);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())), 'Swapped in after commit.');
        self::assertSame([], $this->staged());
        self::assertSame(NzbSourceGuard::digest('upgraded'), DB::table('release_archive_naming_evidence')->value('source_sha256'));
    }

    public function test_rolled_back_staged_install_removes_only_its_staged_file(): void
    {
        $this->writeSource('original');
        $this->commitEvidence('original');
        $inode = fileinode($this->path());
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::beginTransaction();
        $receipt = $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5);
        DB::afterRollBack(fn () => $receipt->rollback());
        DB::afterCommit(fn () => $receipt->close());
        self::assertCount(1, $this->staged());
        DB::rollBack();

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())), 'The partial keeps its NZB.');
        self::assertSame($inode, fileinode($this->path()), 'The canonical file was never touched.');
        self::assertSame([], $this->staged());
        self::assertSame(NzbSourceGuard::digest('original'), DB::table('release_archive_naming_evidence')->value('source_sha256'));
    }

    public function test_failed_staged_install_after_the_move_leaves_the_canonical_source(): void
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
            $this->sources->install($this->release, $temporary, $this->path(), move: $move, stageFor: 5);
            self::fail('The identity check must reject the swapped file.');
        } catch (RuntimeException $exception) {
            self::assertSame('Staged NZB installation identity changed.', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], $this->staged());
    }

    public function test_failed_staged_install_before_the_move_leaves_the_canonical_source(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::beginTransaction();
        try {
            $this->sources->install($this->release, $temporary, $this->path(), move: static fn (): bool => false, stageFor: 5);
            self::fail('A failed move must be reported.');
        } catch (RuntimeException $exception) {
            self::assertSame('Unable to move temporary NZB into place.', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], $this->staged());
    }

    public function test_an_install_without_staging_still_replaces_the_canonical_file_in_its_transaction(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/repair.tmp';
        file_put_contents($temporary, gzencode('repaired'));

        DB::transaction(function () use ($temporary): void {
            $receipt = $this->sources->install($this->release, $temporary, $this->path(), NzbSourceGuard::digest('original'));
            DB::afterCommit(static fn () => $receipt->close());
            self::assertSame('repaired', gzdecode((string) file_get_contents($this->path())));
            self::assertNull($receipt->staged);
        });

        self::assertSame('repaired', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], $this->staged());
    }

    public function test_a_same_transaction_reader_never_swaps_in_its_own_uncommitted_generation(): void
    {
        $this->writeSource('original');
        $this->commitEvidence('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        DB::transaction(function () use ($temporary): void {
            $receipt = $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5);
            DB::afterCommit(static fn () => $receipt->close());
            // The evidence this transaction recorded names the staged file, but it has not committed.
            self::assertSame(NzbSourceGuard::digest('original'), $this->sources->snapshot('fixture-guid')['digest'] ?? null);
            self::assertCount(1, $this->staged());
        });

        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
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

    public function test_reconcile_applies_a_committed_generation_left_staged(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');

        self::assertSame('applied', $this->sources->reconcileStaged($staged));

        self::assertFileDoesNotExist($staged);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_deletes_a_staged_generation_whose_upgrade_rolled_back(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('original');

        self::assertSame('deleted', $this->sources->reconcileStaged($staged));

        self::assertFileDoesNotExist($staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_deletes_a_staged_generation_already_in_place(): void
    {
        $this->writeSource('upgraded');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');
        $inode = fileinode($this->path());

        self::assertSame('deleted', $this->sources->reconcileStaged($staged));

        self::assertFileDoesNotExist($staged);
        self::assertSame($inode, fileinode($this->path()));
    }

    public function test_reconcile_keeps_a_staged_generation_matching_neither_source(): void
    {
        $this->writeSource('newer source');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('something else');

        self::assertSame('kept', $this->sources->reconcileStaged($staged));

        self::assertSame('upgraded', gzdecode((string) file_get_contents($staged)));
        self::assertSame('newer source', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_keeps_a_staged_generation_of_a_release_without_committed_evidence(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        self::assertSame(0, DB::table('release_archive_naming_evidence')->count());

        self::assertSame('kept', $this->sources->reconcileStaged($staged));

        self::assertFileExists($staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_deletes_the_staged_generation_of_a_release_that_no_longer_exists(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        DB::table('releases')->where('id', 1)->delete();

        self::assertSame('deleted', $this->sources->reconcileStaged($staged));

        self::assertFileDoesNotExist($staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_dry_run_reports_without_changing_files(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');

        self::assertSame('applied', $this->sources->reconcileStaged($staged, false));
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('original')]);
        self::assertSame('deleted', $this->sources->reconcileStaged($staged, false));

        self::assertSame('upgraded', gzdecode((string) file_get_contents($staged)));
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_reconcile_keeps_an_untagged_staged_file(): void
    {
        $this->writeSource('original');
        $staged = $this->path().'.'.str_repeat('a', 16).'.next';
        file_put_contents($staged, gzencode('upgraded'));
        $this->commitEvidence('upgraded');

        self::assertSame('kept', $this->sources->reconcileStaged($staged));

        self::assertFileExists($staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_close_after_an_uncommitted_install_deletes_its_staged_file(): void
    {
        $this->writeSource('original');
        $this->commitEvidence('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        // The transaction ends without committing (no rollback hook runs), yet close() is still called.
        DB::beginTransaction();
        $receipt = $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5);
        DB::rollBack();
        self::assertCount(1, $this->staged());
        $receipt->close();

        self::assertSame([], $this->staged());
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())), 'Never swapped in.');
    }

    public function test_close_keeps_both_sources_when_evidence_matches_neither(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));
        $receipt = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5));
        DB::table('release_archive_naming_evidence')->update(['source_sha256' => NzbSourceGuard::digest('unrelated')]);

        $receipt->close();

        self::assertCount(1, $this->staged());
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_a_crash_after_commit_leaves_the_committed_generation_staged_for_reconciliation(): void
    {
        $this->writeSource('original');
        $temporary = $this->directory.'/upgrade.tmp';
        file_put_contents($temporary, gzencode('upgraded'));

        // Committed, but the process ends before the after-commit swap.
        $receipt = DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path(), stageFor: 5));
        unset($receipt);
        gc_collect_cycles();

        $staged = $this->staged();
        self::assertCount(1, $staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
        self::assertSame('applied', $this->sources->reconcileStaged($staged[0]));
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
    }

    public function test_naming_inspection_applies_a_committed_staged_generation_before_recording_evidence(): void
    {
        // Complete once upgraded: the counts no longer mark it partial.
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');

        $snapshot = $this->sources->snapshot('fixture-guid', beginNamingInspection: true);

        self::assertNotNull($snapshot);
        self::assertSame(NzbSourceGuard::digest('upgraded'), $snapshot['digest']);
        self::assertFileDoesNotExist($staged);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
        self::assertSame(NzbSourceGuard::digest('upgraded'), DB::table('release_archive_naming_evidence')->value('source_sha256'));
    }

    public function test_naming_inspection_records_nothing_while_a_staged_generation_is_unresolved(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        // The committed source is neither file: the staged generation cannot be settled.
        $this->commitEvidence('another');

        try {
            $this->sources->snapshot('fixture-guid', beginNamingInspection: true);
            self::fail('Inspection must wait for the staged generation to be resolved.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unresolved', $error->getMessage());
        }

        self::assertFileExists($staged);
        self::assertSame('original', gzdecode((string) file_get_contents($this->path())));
        self::assertSame(NzbSourceGuard::digest('another'), DB::table('release_archive_naming_evidence')->value('source_sha256'), 'No evidence was recorded.');
    }

    public function test_naming_inspection_of_a_committed_canonical_source_leaves_a_rolled_back_leftover_to_nzbclean(): void
    {
        $this->writeSource('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('original');

        $snapshot = $this->sources->snapshot('fixture-guid', beginNamingInspection: true);

        self::assertSame(NzbSourceGuard::digest('original'), $snapshot['digest']);
        self::assertFileExists($staged, 'No scan: the canonical file is the committed source.');
    }

    public function test_a_committed_generation_that_cannot_be_put_in_place_is_no_source(): void
    {
        // The older generation is found at another base path; the staged one cannot replace the installation path.
        mkdir($this->directory.'/alternate');
        $alternate = $this->directory.'/alternate/fixture-guid.nzb.gz';
        file_put_contents($alternate, gzencode('original'));
        File::delete($this->path());
        mkdir($this->path());
        $nzb = Mockery::mock(NzbService::class);
        $nzb->shouldReceive('buildNzbPath')->with('fixture-guid', 0, true)->andReturn($this->directory.'/');
        $nzb->shouldReceive('getNzbPath')->with('fixture-guid')->andReturn($this->path());
        $nzb->shouldReceive('nzbPath')->andReturn($alternate);
        $nzb->shouldReceive('nzbPathCandidates')->andReturn([$alternate, $this->path()]);
        $sources = new NzbSourceGuard($nzb);
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');

        self::assertNull(DB::transaction(fn () => $sources->withLockedSource($this->release, static fn (?array $source): ?array => $source)),
            'The replaced generation is not the source.');
        try {
            $sources->snapshot('fixture-guid', beginNamingInspection: true);
            self::fail('Inspection must wait for the committed generation to be put in place.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('unresolved', $error->getMessage());
        }
        self::assertFileExists($staged);
        self::assertSame(NzbSourceGuard::digest('upgraded'), DB::table('release_archive_naming_evidence')->value('source_sha256'));
        rmdir($this->path());
    }

    public function test_evidence_from_a_replaced_generation_cannot_overwrite_a_committed_staged_one(): void
    {
        // An inspection read the original; then an upgrade committed without getting to swap its NZB in.
        $this->writeSource('original');
        $old = NzbSourceGuard::digest('original');
        $staged = $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');

        self::assertFalse($this->record($old, 'complete_single', ['Show.S01E01.mkv'], true));

        self::assertFileDoesNotExist($staged);
        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
        self::assertSame(NzbSourceGuard::digest('upgraded'), DB::table('release_archive_naming_evidence')->value('source_sha256'));
    }

    public function test_a_repair_of_a_replaced_generation_is_refused_once_the_committed_one_is_applied(): void
    {
        $this->writeSource('original');
        $this->stage(5, 'upgraded');
        $this->commitEvidence('upgraded');
        $temporary = $this->directory.'/repair.tmp';
        file_put_contents($temporary, gzencode('repaired original'));

        try {
            DB::transaction(fn () => $this->sources->install($this->release, $temporary, $this->path(), NzbSourceGuard::digest('original')));
            self::fail('The repair compared against a replaced generation.');
        } catch (RuntimeException $exception) {
            self::assertSame('NZB source changed before replacement.', $exception->getMessage());
        }

        self::assertSame('upgraded', gzdecode((string) file_get_contents($this->path())));
        self::assertSame([], $this->staged());
    }

    private function commitEvidence(string $decodedSource): void
    {
        DB::table('release_archive_naming_evidence')->insert([
            'releases_id' => 1,
            'release_guid' => 'fixture-guid',
            'source_sha256' => NzbSourceGuard::digest($decodedSource),
            'proof_version' => ReleaseArchiveNamingEvidence::PROOF_VERSION,
            'scope' => 'unknown',
            'episode_files' => '[]',
            'updated_at' => now(),
        ]);
    }

    private function stage(int $candidateId, string $contents): string
    {
        $staged = $this->path().'.c'.$candidateId.'-'.str_repeat('a', 16).'.next';
        file_put_contents($staged, gzencode($contents));

        return $staged;
    }

    /** @return list<string> */
    private function staged(): array
    {
        return glob($this->path().'.c*-*.next') ?: [];
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
