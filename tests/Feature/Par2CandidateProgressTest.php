<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\NntmuxResetDb;
use App\Console\Commands\NntmuxResetTruncate;
use App\Models\Release;
use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Verification\VerificationResetService;
use App\Support\Par2CandidateProgress;
use App\Support\Par2RenameProgress;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;
use UnexpectedValueException;

final class Par2CandidateProgressTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const MANIFEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const CANDIDATE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SECOND = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function test_restores_exact_state_after_new_helper_and_accumulator_instances(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $saved = $this->save($release, $state);

        $loaded = (new Par2CandidateProgress)->load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);

        self::assertTrue($saved);
        self::assertSame($state, $loaded?->accumulatorState);
        self::assertSame(strlen(json_encode($state, JSON_THROW_ON_ERROR)), $loaded?->storedBytes);
        self::assertSame(2, $loaded?->nextSegment);
        self::assertSame([0], $loaded?->retrySegments);
        self::assertFalse(Par2PacketAccumulator::fromState($loaded->accumulatorState)->isComplete());
        self::assertSame(self::MANIFEST, Par2RenameProgress::get($release->id)['signature']);
    }

    public function test_exact_cap_is_shared_by_all_candidates_and_overflow_persists_zero_byte_block(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $bytes = strlen(json_encode($state, JSON_THROW_ON_ERROR));
        self::assertTrue($this->save($release, $state, cap: $bytes));

        self::assertTrue($this->save($release, $state, candidate: self::SECOND, cap: $bytes));
        $blocked = Par2CandidateProgress::load($release->id, self::MANIFEST, self::SECOND, 4, $bytes);

        self::assertSame('state_limit', $blocked?->blockedReason);
        self::assertNull($blocked?->accumulatorState);
        self::assertSame(0, $blocked?->storedBytes);
        self::assertSame($bytes, (int) DB::table('par2_rename_candidate_progress')->sum('stored_bytes'));
        self::assertTrue($this->save($release, $state, candidate: self::SECOND, cap: 10000));
        self::assertSame('state_limit', Par2CandidateProgress::load($release->id, self::MANIFEST, self::SECOND, 4, 10000)?->blockedReason);
    }

    public function test_state_replacement_counts_only_other_candidates(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $bytes = strlen(json_encode($state, JSON_THROW_ON_ERROR));
        self::assertTrue($this->save($release, $state, cap: $bytes * 2));
        self::assertTrue($this->save($release, $state, candidate: self::SECOND, cap: $bytes * 2));

        self::assertTrue($this->save($release, $state, cap: $bytes * 2));

        self::assertSame($bytes * 2, (int) DB::table('par2_rename_candidate_progress')->sum('stored_bytes'));
        self::assertNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, $bytes * 2)?->blockedReason);
    }

    public function test_changed_later_segment_manifest_invalidates_every_candidate_and_block(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $manifest = [['number' => 1, 'id' => 'a', 'bytes' => 12], ['number' => 2, 'id' => 'b', 'bytes' => 34]];
        $before = hash('sha256', serialize($manifest));
        $this->save($release, $this->state(), manifest: $before);
        $this->save($release, $this->state(), candidate: self::SECOND, manifest: $before, cap: strlen(json_encode($this->state(), JSON_THROW_ON_ERROR)));
        $manifest[1]['id'] = 'changed';
        $after = hash('sha256', serialize($manifest));
        self::assertNull(Par2CandidateProgress::load($release->id, $after, self::CANDIDATE, 4, 10000));

        self::assertTrue($this->save($release, $this->state(), manifest: $after));

        self::assertNull(Par2CandidateProgress::load($release->id, $after, self::SECOND, 4, 10000));
        self::assertSame($after, Par2RenameProgress::get($release->id)['signature']);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
    }

    #[DataProvider('snapshotChanges')]
    public function test_refuses_stale_pending_snapshot(string $column, mixed $value): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release(['name' => 'Original', 'searchname' => 'Original']);
        DB::table('releases')->where('id', $release->id)->update([$column => $value]);

        self::assertFalse($this->save($release, $this->state()));

        self::assertSame(0, DB::table('par2_rename_progress')->count());
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function snapshotChanges(): iterable
    {
        yield 'guid' => ['guid', 'replacement'];
        yield 'case-only name' => ['name', 'original'];
        yield 'accent-only searchname' => ['searchname', 'Óriginal'];
        yield 'linked' => ['predb_id', 123];
        yield 'renamed' => ['isrenamed', 1];
        yield 'processed' => ['proc_par2', 1];
    }

    public function test_claim_loss_after_child_write_rolls_back_parent_and_child_and_can_retry(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $owned = true;
        DB::listen(static function (QueryExecuted $query) use (&$owned): void {
            if (str_starts_with($query->sql, 'insert into "par2_rename_candidate_progress"')) {
                $owned = false;
            }
        });

        self::assertFalse($this->save($release, $this->state(), guard: static function () use (&$owned): bool {
            return $owned;
        }));
        self::assertSame(0, DB::table('par2_rename_progress')->count());
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertTrue($this->save($release, $this->state()));
        self::assertNotNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
    }

    public function test_snapshot_change_after_child_write_rolls_back_checkpoint(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        DB::listen(static function (QueryExecuted $query) use ($release): void {
            if (str_starts_with($query->sql, 'insert into "par2_rename_candidate_progress"')) {
                DB::table('releases')->where('id', $release->id)->update(['predb_id' => 23]);
            }
        });

        self::assertFalse($this->save($release, $this->state()));

        self::assertSame(0, DB::table('par2_rename_progress')->count());
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    #[DataProvider('corruptRows')]
    public function test_corrupt_loaded_checkpoint_is_explicit_failure_not_absence(string $column, mixed $value): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        DB::table('par2_rename_candidate_progress')->update([$column => $value]);
        $this->expectException(UnexpectedValueException::class);

        Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function corruptRows(): iterable
    {
        yield 'invalid JSON' => ['accumulator_state', '{'];
        yield 'invalid base64' => ['accumulator_state', '{"version":1,"packets":[],"tail":"!","skip_remaining":0}'];
        yield 'invalid packet' => ['accumulator_state', '{"version":1,"packets":["YWJj"],"tail":"","skip_remaining":0}'];
        yield 'wrong version' => ['accumulator_state', '{"version":2,"packets":[],"tail":"","skip_remaining":0}'];
        yield 'bad byte count' => ['stored_bytes', 0];
        yield 'negative cursor' => ['next_segment', -1];
        yield 'past-end cursor' => ['next_segment', 5];
        yield 'out-of-range retry' => ['retry_segments', '[4]'];
        yield 'duplicate retry' => ['retry_segments', '[0,0]'];
        yield 'string retry' => ['retry_segments', '["0"]'];
        yield 'negative decoded offset' => ['decoded_offset', -1];
        yield 'noncanonical identity' => ['article_identity', '{"name_base64":"eA","size":4,"part":1}'];
        yield 'unblocked null state' => ['accumulator_state', null];
    }

    public function test_invalid_saved_state_is_rejected_before_any_write(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        try {
            $this->save($release, ['version' => 1, 'packets' => [], 'tail' => '!', 'skip_remaining' => 0]);
            self::fail('Invalid base64 must be rejected');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('par2_rename_progress')->count());
        }
    }

    public function test_forget_and_release_deletion_cascade_to_children(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        Par2RenameProgress::forget($release->id);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        $this->save($release, $this->state());

        DB::table('releases')->where('id', $release->id)->delete();

        self::assertSame(0, DB::table('par2_rename_progress')->count());
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_guarded_delete_removes_only_selected_candidate(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $this->save($release, $this->state(), candidate: self::SECOND);
        self::assertFalse(Par2CandidateProgress::deleteCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, static fn (): bool => false));

        self::assertTrue(Par2CandidateProgress::deleteCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, static fn (): bool => true));

        self::assertNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
        self::assertNotNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::SECOND, 4, 10000));
    }

    public function test_byte_exact_yenc_identity_and_decoded_offset_survive_restart_and_count_towards_cap(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $identity = ['name_base64' => base64_encode("file-\xff.par2"), 'size' => 5000000000, 'part' => 2];
        $bytes = strlen(json_encode($state, JSON_THROW_ON_ERROR)) + strlen(json_encode($identity, JSON_THROW_ON_ERROR));

        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 2, [], $state, 4, $bytes, static fn (): bool => true, decodedOffset: 123456, articleIdentity: $identity));
        $loaded = Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, $bytes);

        self::assertSame(123456, $loaded?->decodedOffset);
        self::assertSame($identity, $loaded?->articleIdentity);
        self::assertSame($bytes, $loaded?->storedBytes);
        self::assertTrue($this->save($release, $state, candidate: self::SECOND, cap: $bytes));
        self::assertSame('state_limit', Par2CandidateProgress::load($release->id, self::MANIFEST, self::SECOND, 4, $bytes)?->blockedReason);
    }

    public function test_identity_alone_can_push_state_over_cap_without_leaving_identity_in_tombstone(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $identity = ['name_base64' => base64_encode('file.par2'), 'size' => 100, 'part' => 1];
        $cap = strlen(json_encode($state, JSON_THROW_ON_ERROR));

        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 1, [], $state, 4, $cap, static fn (): bool => true, decodedOffset: 4, articleIdentity: $identity));
        $loaded = Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, $cap);

        self::assertSame('state_limit', $loaded?->blockedReason);
        self::assertNull($loaded?->accumulatorState);
        self::assertNull($loaded?->articleIdentity);
        self::assertSame(0, $loaded?->storedBytes);
    }

    public function test_legacy_manifest_change_cascades_away_candidate_buffers(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());

        self::assertTrue(Par2RenameProgress::save($release->id, 'replacement', 2, []));

        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame('replacement', Par2RenameProgress::get($release->id)['signature']);
    }

    public function test_full_reset_explicitly_clears_candidate_buffers_with_foreign_keys_disabled(): void
    {
        $this->candidateSchema();
        $this->groupResetSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $connection = DB::connection();
        DB::swap(\Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnUsing(static function (string $sql) use ($connection): bool {
            if (str_contains($sql, 'FOREIGN_KEY_CHECKS = 0')) {
                return $connection->statement('PRAGMA foreign_keys = OFF');
            }
            foreach (['par2_rename_candidate_progress', 'par2_rename_progress', 'releases'] as $table) {
                if ($sql === 'TRUNCATE TABLE '.$table) {
                    self::assertSame(0, (int) $connection->selectOne('PRAGMA foreign_keys')->foreign_keys);
                    $connection->table($table)->delete();
                }
            }
            if ($sql === 'TRUNCATE TABLE releases') {
                throw new RuntimeException('Stop before unrelated reset side effects');
            }

            return true;
        });
        /** @var NntmuxResetDb&MockInterface $command */
        $command = \Mockery::mock(NntmuxResetDb::class)->makePartial();
        $command->shouldReceive('confirm')->once()->andReturnTrue();
        $command->shouldReceive('info');
        $command->shouldReceive('error')->once();
        $this->app['env'] = 'local';
        $verificationRoot = sys_get_temp_dir().'/nntmux-verification-reset-'.bin2hex(random_bytes(8));
        config(['release_verification.storage_path' => $verificationRoot]);
        try {
            self::assertSame(NntmuxResetDb::FAILURE, $command->handle(app(VerificationResetService::class)));
            self::assertSame(0, $connection->table('par2_rename_candidate_progress')->count());
            self::assertSame(0, $connection->table('par2_rename_progress')->count());
        } finally {
            $this->app['env'] = 'testing';
            $connection->statement('PRAGMA foreign_keys = ON');
            $this->removeVerificationFixture($verificationRoot);
        }
    }

    public function test_reset_truncate_deletes_only_children_of_removed_releases(): void
    {
        $this->candidateSchema();
        $this->groupResetSchema();
        $removed = $this->pendingPar2Release(['nzbstatus' => 0]);
        $retained = $this->pendingPar2Release(['nzbstatus' => 1]);
        $this->save($removed, $this->state());
        $this->save($retained, $this->state());
        $connection = DB::connection();
        DB::swap(\Mockery::mock(DB::getFacadeRoot())->makePartial());
        DB::shouldReceive('statement')->andReturnUsing(static function (string $sql) use ($connection): bool {
            if (str_contains($sql, 'FOREIGN_KEY_CHECKS = 0')) {
                return $connection->statement('PRAGMA foreign_keys = OFF');
            }
            if (str_contains($sql, 'FOREIGN_KEY_CHECKS = 1')) {
                return $connection->statement('PRAGMA foreign_keys = ON');
            }

            return true;
        });
        /** @var NntmuxResetTruncate&MockInterface $command */
        $command = \Mockery::mock(NntmuxResetTruncate::class)->makePartial();
        $command->shouldReceive('info');
        $verificationRoot = sys_get_temp_dir().'/nntmux-verification-reset-'.bin2hex(random_bytes(8));
        config(['release_verification.storage_path' => $verificationRoot]);
        try {
            self::assertSame(NntmuxResetTruncate::SUCCESS, $command->handle(app(VerificationResetService::class)));
        } finally {
            $this->removeVerificationFixture($verificationRoot);
        }

        self::assertNull(Par2CandidateProgress::load($removed->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
        self::assertNotNull(Par2CandidateProgress::load($retained->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
    }

    public function test_ownership_loss_during_manifest_replacement_restores_previous_children(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $owned = true;
        DB::listen(static function (QueryExecuted $query) use (&$owned): void {
            if (str_starts_with($query->sql, 'insert into "par2_rename_candidate_progress"')) {
                $owned = false;
            }
        });

        self::assertFalse($this->save($release, $this->state(), manifest: self::SECOND, guard: static function () use (&$owned): bool {
            return $owned;
        }));

        self::assertSame(self::MANIFEST, Par2RenameProgress::get($release->id)['signature']);
        self::assertNotNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
    }

    public function test_database_exception_rolls_back_new_parent_and_retry_succeeds(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $failed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$failed): void {
            if (! $failed && str_starts_with($query, 'insert into "par2_rename_candidate_progress"')) {
                $failed = true;
                throw new RuntimeException('Simulated checkpoint write failure');
            }
        });
        try {
            $this->save($release, $this->state());
            self::fail('Expected write failure');
        } catch (RuntimeException $exception) {
            self::assertSame('Simulated checkpoint write failure', $exception->getMessage());
            self::assertSame(0, DB::table('par2_rename_progress')->count());
        }

        self::assertTrue($this->save($release, $this->state()));

        self::assertNotNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
    }

    public function test_delete_rolls_back_when_claim_is_lost_after_write(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $owned = true;
        DB::listen(static function (QueryExecuted $query) use (&$owned): void {
            if (str_starts_with($query->sql, 'delete from "par2_rename_candidate_progress"')) {
                $owned = false;
            }
        });

        self::assertFalse(Par2CandidateProgress::deleteCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, static function () use (&$owned): bool {
            return $owned;
        }));

        self::assertNotNull(Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000));
    }

    public function test_load_rejects_aggregate_excess_even_when_individual_rows_fit(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $state = $this->state();
        $this->save($release, $state);
        $this->save($release, $state, candidate: self::SECOND);
        $this->expectException(UnexpectedValueException::class);

        Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, strlen(json_encode($state, JSON_THROW_ON_ERROR)));
    }

    #[DataProvider('invalidSavedCursors')]
    public function test_invalid_cursor_or_retry_cannot_create_a_checkpoint(int $cursor, array $retry): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        try {
            Par2CandidateProgress::save($this->snapshot($release), self::MANIFEST, self::CANDIDATE, $cursor, $retry, $this->state(), 4, 10000, static fn (): bool => true);
            self::fail('Expected invalid cursor rejection');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('par2_rename_progress')->count());
        }
    }

    /** @return iterable<string, array{int, array<mixed>}> */
    public static function invalidSavedCursors(): iterable
    {
        yield 'negative' => [-1, []];
        yield 'past manifest' => [5, []];
        yield 'retry not yet attempted' => [2, [2]];
        yield 'boolean retry' => [2, [true]];
        yield 'duplicate retries' => [2, [0, 0]];
    }

    public function test_null_state_requires_block_and_cannot_smuggle_identity_bytes(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->expectException(InvalidArgumentException::class);

        Par2CandidateProgress::save($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 0, [], null, 4, 10000, static fn (): bool => true, 'state_limit', 0, ['name_base64' => 'eA==', 'size' => 4, 'part' => 1]);
    }

    public function test_loaded_unsigned_offset_outside_php_integer_range_is_not_clamped(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        DB::statement('UPDATE par2_rename_candidate_progress SET decoded_offset = CAST(? AS BLOB)', ['9223372036854775808']);
        $this->expectException(UnexpectedValueException::class);

        Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);
    }

    public function test_loaded_packet_object_cannot_masquerade_as_an_empty_packet_list(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $state = $this->state();
        $state['packets'] = new \stdClass;
        DB::table('par2_rename_candidate_progress')->update(['accumulator_state' => json_encode($state, JSON_THROW_ON_ERROR)]);
        $this->expectException(UnexpectedValueException::class);

        Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);
    }

    public function test_database_json_normalization_preserves_packet_identity_and_canonical_accounting(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append(file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2')));
        $state = $accumulator->exportState();
        $identity = ['name_base64' => base64_encode("legacy-\xff.par2"), 'size' => 12884902011, 'part' => 2];
        self::assertTrue(Par2CandidateProgress::save($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 2, [], $state, 4, 100000, static fn (): bool => true, decodedOffset: 12345, articleIdentity: $identity));
        DB::table('par2_rename_candidate_progress')->update([
            'accumulator_state' => json_encode(array_reverse($state, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'article_identity' => json_encode(array_reverse($identity, true), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        ]);
        DB::statement('UPDATE par2_rename_candidate_progress SET decoded_offset = CAST(? AS BLOB), next_segment = CAST(? AS BLOB), stored_bytes = CAST(? AS BLOB)', ['12345', '2', (string) ($accumulator->stateBytes() + strlen(json_encode($identity, JSON_THROW_ON_ERROR)))]);
        $loaded = Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 100000);
        self::assertSame($state, $loaded?->accumulatorState);
        self::assertSame($identity, $loaded?->articleIdentity);
        self::assertSame(12345, $loaded?->decodedOffset);
        self::assertSame($accumulator->filesBySet(), Par2PacketAccumulator::fromState($loaded->accumulatorState)->filesBySet());
        self::assertSame($accumulator->isComplete(), Par2PacketAccumulator::fromState($loaded->accumulatorState)->isComplete());
    }

    public function test_corrupt_candidate_can_be_replaced_with_a_durable_noncomplete_tombstone(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        DB::table('par2_rename_candidate_progress')->update(['accumulator_state' => '{broken', 'next_segment' => 999999, 'stored_bytes' => 999999]);
        try {
            Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);
            self::fail('Corrupt state must not look absent.');
        } catch (UnexpectedValueException) {
            self::assertTrue(Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, 10000, static fn (): bool => true));
        }
        $loaded = Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000);
        self::assertSame('malformed_state', $loaded?->blockedReason);
        self::assertNull($loaded?->accumulatorState);
        self::assertNull($loaded?->articleIdentity);
        self::assertSame(0, $loaded?->storedBytes);
        self::assertSame(0, $loaded?->decodedOffset);
        self::assertSame(0, $loaded?->nextSegment);
        self::assertSame([], $loaded?->retrySegments);
        self::assertTrue($this->save($release, $this->state()));
        self::assertSame('malformed_state', Par2CandidateProgress::load($release->id, self::MANIFEST, self::CANDIDATE, 4, 10000)?->blockedReason);
    }

    public function test_corrupt_block_refuses_absent_or_valid_current_candidate(): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        self::assertFalse(Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, 10000, static fn (): bool => true));
        $this->save($release, $this->state());
        $before = DB::table('par2_rename_candidate_progress')->first();
        self::assertFalse(Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, 10000, static fn (): bool => true));
        self::assertEquals($before, DB::table('par2_rename_candidate_progress')->first());
    }

    #[DataProvider('corruptBlockSiblingCases')]
    public function test_corrupt_block_validates_siblings_and_aggregate(string $case): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        $this->save($release, $this->state(), candidate: self::SECOND);
        DB::table('par2_rename_candidate_progress')->where('candidate_signature', self::CANDIDATE)->update(['accumulator_state' => '{broken']);
        $bytes = strlen(json_encode($this->state(), JSON_THROW_ON_ERROR));
        if ($case === 'corrupt') {
            DB::table('par2_rename_candidate_progress')->where('candidate_signature', self::SECOND)->update(['retry_segments' => '{}']);
        }
        $cap = $case === 'over_cap' ? $bytes - 1 : $bytes;
        if ($case !== 'boundary') {
            try {
                Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, $cap, static fn (): bool => true);
                self::fail('Invalid sibling state must prevent replacement.');
            } catch (UnexpectedValueException) {
                self::assertSame('{broken', DB::table('par2_rename_candidate_progress')->where('candidate_signature', self::CANDIDATE)->value('accumulator_state'));
            }
        } else {
            self::assertTrue(Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, $cap, static fn (): bool => true));
            self::assertSame($this->state(), Par2CandidateProgress::load($release->id, self::MANIFEST, self::SECOND, 4, $cap)?->accumulatorState);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function corruptBlockSiblingCases(): iterable
    {
        foreach (['corrupt', 'over_cap', 'boundary'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('corruptBlockRaces')]
    public function test_corrupt_block_rolls_back_on_claim_snapshot_or_manifest_race(string $race): void
    {
        $this->candidateSchema();
        $release = $this->pendingPar2Release();
        $this->save($release, $this->state());
        DB::table('par2_rename_candidate_progress')->update(['accumulator_state' => '{broken']);
        $owned = true;
        DB::listen(static function (QueryExecuted $query) use ($release, $race, &$owned): void {
            if (str_starts_with($query->sql, 'update "par2_rename_candidate_progress"')) {
                if ($race === 'claim') {
                    $owned = false;
                } elseif ($race === 'snapshot') {
                    DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement']);
                } else {
                    DB::table('par2_rename_progress')->where('releases_id', $release->id)->update(['signature' => self::SECOND]);
                }
            }
        });
        self::assertFalse(Par2CandidateProgress::blockCorruptCandidate($this->snapshot($release), self::MANIFEST, self::CANDIDATE, 4, 10000, static function () use (&$owned): bool {
            return $owned;
        }));
        self::assertSame('{broken', DB::table('par2_rename_candidate_progress')->value('accumulator_state'));
        self::assertSame(self::MANIFEST, DB::table('par2_rename_progress')->value('signature'));
        self::assertSame($release->guid, DB::table('releases')->where('id', $release->id)->value('guid'));
    }

    /** @return iterable<string, array{string}> */
    public static function corruptBlockRaces(): iterable
    {
        foreach (['claim', 'snapshot', 'manifest'] as $case) {
            yield $case => [$case];
        }
    }

    private function groupResetSchema(): void
    {
        Schema::table('usenet_groups', static function (Blueprint $table): void {
            foreach (['first_record', 'last_record'] as $column) {
                $table->integer($column)->default(0);
            }
            foreach (['first_record_postdate', 'last_record_postdate', 'last_updated'] as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    private function removeVerificationFixture(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                $this->removeVerificationFixture($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($path);
    }

    private function candidateSchema(): void
    {
        $paths = glob(base_path('database/migrations/*_create_par2_rename_candidate_progress_table.php'));
        self::assertCount(1, $paths);
        if (! Schema::hasTable('par2_rename_candidate_progress')) {
            (require $paths[0])->up();
        }
    }

    /** @return array{version: 1, packets: list<string>, tail: string, skip_remaining: int} */
    private function state(): array
    {
        $accumulator = new Par2PacketAccumulator;
        $accumulator->append('PAR2');

        return $accumulator->exportState();
    }

    /** @return array{id: int, guid: string, name: ?string, searchname: ?string} */
    private function snapshot(Release $release): array
    {
        return ['id' => $release->id, 'guid' => $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
    }

    /** @param array<string, mixed> $state */
    private function save(Release $release, array $state, string $candidate = self::CANDIDATE, string $manifest = self::MANIFEST, int $cap = 10000, ?callable $guard = null): bool
    {
        return Par2CandidateProgress::save($this->snapshot($release), $manifest, $candidate, 2, [0], $state, 4, $cap, $guard ?? static fn (): bool => true);
    }
}
