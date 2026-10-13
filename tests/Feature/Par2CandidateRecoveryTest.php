<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Facades\Search;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Services\AdditionalProcessing\ReleaseSearchSyncCoordinator;
use App\Services\AdditionalProcessing\State\PersistenceMetricsCollector;
use App\Services\Categorization\CategorizationService;
use App\Services\NameFixing\NameFixingService;
use App\Services\NameFixing\ReleaseUpdateService;
use App\Services\NNTP\BoundedArticleResult;
use App\Services\NNTP\NNTPService;
use App\Services\Nzb\Par2CandidateRecovery;
use App\Services\Nzb\Par2CandidateRecoveryResult;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Services\PostProcessService;
use App\Services\Yenc\YencPart;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class Par2CandidateRecoveryTest extends TestCase
{
    use Par2RenameTestDatabase;

    private const MANIFEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TITLE = 'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        DB::statement('PRAGMA foreign_keys = ON');
        $paths = glob(base_path('database/migrations/*_create_par2_rename_candidate_progress_table.php'));
        self::assertCount(1, $paths);
        if (! Schema::hasTable('par2_rename_candidate_progress')) {
            (require $paths[0])->up();
        }
    }

    public function test_real_main_first_file_resumes_second_article_with_budget_one_and_new_objects(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $first = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);

        self::assertSame(Par2RenameOutcome::Deferred, $first->outcome);
        self::assertSame([1, 1], [$first->probes, $first->articles]);
        self::assertSame(1, (int) DB::table('par2_rename_candidate_progress')->value('next_segment'));
        self::assertSame(strlen($main), (int) DB::table('par2_rename_candidate_progress')->value('decoded_offset'));

        $second = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest)], $post);

        self::assertSame(Par2RenameOutcome::NoMatch, $second->outcome);
        self::assertSame([1, 1], [$second->probes, $second->articles]);
        self::assertCount(10, array_values($applications[array_key_last($applications)]['files'])[0]);
        self::assertTrue($applications[array_key_last($applications)]['terminal']);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    #[DataProvider('packetSplits')]
    public function test_headers_and_bodies_resume_without_refetching_the_committed_prefix(int $split): void
    {
        [$main, $rest] = $this->realFixture();
        $bytes = $main.$rest;
        $release = $this->pendingPar2Release();
        $parts = [substr($bytes, 0, $split), substr($bytes, $split)];
        $candidate = $this->candidate($parts);
        $applications = [];
        $post = $this->postprocessor($applications);

        self::assertSame(Par2RenameOutcome::Deferred, $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($parts[0])], $post)->outcome);
        $resumed = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($parts[1])], $post);

        self::assertSame(Par2RenameOutcome::NoMatch, $resumed->outcome);
        self::assertSame([1, 1], [$resumed->probes, $resumed->articles]);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    /** @return array<string, array{int}> */
    public static function packetSplits(): array
    {
        return ['marker' => [3], 'length' => [12], 'header type' => [52], 'Main body' => [83], 'descriptor body' => [359]];
    }

    public function test_failed_later_article_preserves_cursor_and_exact_inventory_for_retry(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);
        $saved = (array) DB::table('par2_rename_candidate_progress')->first();

        $failed = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::failure('temporary provider failure')], $post);

        self::assertSame(Par2RenameOutcome::Retryable, $failed->outcome);
        self::assertSame([1, 0], [$failed->probes, $failed->articles]);
        $after = (array) DB::table('par2_rename_candidate_progress')->first();
        foreach (['next_segment', 'decoded_offset', 'accumulator_state', 'article_identity'] as $column) {
            self::assertSame($saved[$column], $after[$column]);
        }
        self::assertSame(Par2RenameOutcome::NoMatch, $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest)], $post)->outcome);
    }

    public function test_thrown_transport_failure_counts_the_attempted_article(): void
    {
        [$main] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessageBounded')->once()->andThrow(new RuntimeException('transient transport exception'));
        $applications = [];

        $result = (new Par2CandidateRecovery($nntp, $this->postprocessor($applications)))->recover($this->candidate([$main]), $this->snapshot($release), self::MANIFEST, 1, 0, 1, 10000, 10000, static fn (): bool => true);

        self::assertSame([Par2RenameOutcome::Retryable, 1, 0], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame([], $applications);
    }

    public function test_state_write_exception_after_receipt_keeps_live_probe_and_article_counts(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $failed = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$failed): void {
            if (! $failed && str_starts_with($query, 'insert into "par2_rename_candidate_progress"')) {
                $failed = true;
                throw new RuntimeException('checkpoint write failure');
            }
        });

        $result = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);

        self::assertSame([Par2RenameOutcome::Retryable, 1, 1], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame([], $applications);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(Par2RenameOutcome::Deferred, $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post)->outcome);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->value('next_segment'));
    }

    #[DataProvider('discontinuousParts')]
    public function test_yenc_identity_or_offsets_cannot_join_unrelated_or_gapped_payloads(string $change): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $size = strlen($main.$rest);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main, new YencPart('set.par2', $size, 1, 1, strlen($main)))], $post);
        $part = match ($change) {
            'name' => new YencPart('other.par2', $size, 2, strlen($main) + 1, $size),
            'size' => new YencPart('set.par2', $size + 4, 2, strlen($main) + 1, $size),
            'part' => new YencPart('set.par2', $size, 3, strlen($main) + 1, $size),
            'gap' => new YencPart('set.par2', $size, 2, strlen($main) + 2, $size + 1),
            'overlap' => new YencPart('set.par2', $size, 2, strlen($main), $size - 1),
            'length' => new YencPart('set.par2', $size, 2, strlen($main) + 1, $size - 1),
            'plain' => null,
        };

        $result = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest, $part)], $post);

        self::assertSame(Par2RenameOutcome::Retryable, $result->outcome);
        self::assertSame([1, 1], [$result->probes, $result->articles]);
        self::assertSame(1, (int) DB::table('par2_rename_candidate_progress')->value('next_segment'));
        self::assertNotNull(DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
        $retry = $this->runRecovery($candidate, $release, [], $post);
        self::assertSame([Par2RenameOutcome::Retryable, 0, 0], [$retry->outcome, $retry->probes, $retry->articles]);
    }

    /** @return array<string, array{string}> */
    public static function discontinuousParts(): array
    {
        return array_combine(['name', 'size', 'part', 'gap', 'overlap', 'length', 'plain'], array_map(static fn (string $value): array => [$value], ['name', 'size', 'part', 'gap', 'overlap', 'length', 'plain']));
    }

    public function test_valid_yenc_parts_preserve_byte_exact_identity_and_resume_offset(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $size = strlen($main.$rest);
        $name = "set-\xff.par2";
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main, new YencPart($name, $size, 1, 1, strlen($main)))], $post);
        $identity = json_decode(DB::table('par2_rename_candidate_progress')->value('article_identity'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(base64_encode($name), $identity['name_base64']);

        $result = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest, new YencPart($name, $size, 2, strlen($main) + 1, $size))], $post);

        self::assertSame(Par2RenameOutcome::NoMatch, $result->outcome);
    }

    public function test_future_segment_manifest_change_resets_committed_prefix(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);
        $candidate['segments'][1]['id'] = 'replacement-future-article';

        $result = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post, manifest: str_repeat('b', 64));

        self::assertSame(Par2RenameOutcome::Deferred, $result->outcome);
        self::assertSame(1, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(str_repeat('b', 64), DB::table('par2_rename_progress')->value('signature'));
    }

    #[DataProvider('blockedArticles')]
    public function test_stable_invalid_or_oversized_articles_block_without_repeated_first_fetch(string $kind): void
    {
        $release = $this->pendingPar2Release();
        [$main] = $this->realFixture();
        $candidate = $this->candidate([$main]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $article = match ($kind) {
            'oversized body' => BoundedArticleResult::failure('too large', true),
            'malformed packet' => BoundedArticleResult::success('not a valid PAR2 packet'),
            'invalid yenc name' => BoundedArticleResult::success($main, new YencPart("bad\0.par2", strlen($main), 1, 1, strlen($main))),
        };

        $first = $this->runRecovery($candidate, $release, ['article-1' => $article], $post);
        $retry = $this->runRecovery($candidate, $release, [], $post);

        self::assertSame(Par2RenameOutcome::Retryable, $first->outcome);
        self::assertSame([Par2RenameOutcome::Retryable, 0, 0], [$retry->outcome, $retry->probes, $retry->articles]);
        self::assertNotNull(DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
        self::assertSame(0, $release->fresh()->proc_par2);
    }

    /** @return array<string, array{string}> */
    public static function blockedArticles(): array
    {
        return ['oversized body' => ['oversized body'], 'malformed packet' => ['malformed packet'], 'invalid yenc name' => ['invalid yenc name']];
    }

    #[DataProvider('advertisedByteLimits')]
    public function test_invalid_advertised_bytes_block_before_network_io(int $advertised): void
    {
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate(['payload']);
        $candidate['segments'][0]['bytes'] = $advertised;
        $applications = [];
        $result = $this->runRecovery($candidate, $release, [], $this->postprocessor($applications), maxArticleBytes: 1024);

        self::assertSame([Par2RenameOutcome::Retryable, 0, 0], [$result->outcome, $result->probes, $result->articles]);
        self::assertNotNull(DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
    }

    /** @return array<string, array{int}> */
    public static function advertisedByteLimits(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'over ceiling' => [1025]];
    }

    public function test_state_ceiling_persists_zero_byte_block_and_never_refetches(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $first = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post, maxStateBytes: 60);

        self::assertSame(Par2RenameOutcome::Retryable, $first->outcome);
        self::assertSame('state_limit', DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
        self::assertSame(0, (int) DB::table('par2_rename_candidate_progress')->value('stored_bytes'));
        self::assertSame([Par2RenameOutcome::Retryable, 0, 0], (function (Par2CandidateRecoveryResult $result): array {
            return [$result->outcome, $result->probes, $result->articles];
        })($this->runRecovery($candidate, $release, [], $post, maxStateBytes: 60)));
    }

    public function test_hidden_non_par2_payload_is_no_match_without_metadata_or_checkpoint_writes(): void
    {
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate(['ordinary non-PAR2 data']);
        $candidate['explicit'] = false;
        $processor = Mockery::mock(Par2Processor::class);
        $processor->shouldNotReceive('applyInventoryForRename');
        $post = new PostProcessService(par2Processor: $processor);

        $result = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success('ordinary non-PAR2 data')], $post);

        self::assertSame([Par2RenameOutcome::NoMatch, 1, 1], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(0, DB::table('release_files')->count());
    }

    public function test_claim_loss_after_receipt_cannot_commit_cursor_or_apply_metadata(): void
    {
        [$main] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main]);
        $owned = true;
        $processor = Mockery::mock(Par2Processor::class);
        $processor->shouldNotReceive('applyInventoryForRename');
        $post = new PostProcessService(par2Processor: $processor);
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessageBounded')->once()->andReturnUsing(static function () use (&$owned, $main): BoundedArticleResult {
            $owned = false;

            return BoundedArticleResult::success($main);
        });

        $result = (new Par2CandidateRecovery($nntp, $post))->recover($candidate, $this->snapshot($release), self::MANIFEST, 1, 0, 1, 10000, 10000, static function () use (&$owned): bool {
            return $owned;
        });

        self::assertSame([Par2RenameOutcome::Deferred, 1, 1], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_corrupt_persisted_state_is_quarantined_without_an_article_fetch(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);
        DB::table('par2_rename_candidate_progress')->update(['accumulator_state' => '{"invalid":true}']);

        $result = $this->runRecovery($candidate, $release, [], $post);

        self::assertSame([Par2RenameOutcome::Retryable, 0, 0], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame('malformed_state', DB::table('par2_rename_candidate_progress')->value('blocked_reason'));
        self::assertNull(DB::table('par2_rename_candidate_progress')->value('accumulator_state'));
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->value('stored_bytes'));
    }

    public function test_claim_loss_during_checkpoint_write_rolls_back_without_metadata_application(): void
    {
        [$main] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $owned = true;
        DB::listen(static function (QueryExecuted $query) use (&$owned): void {
            if (str_starts_with($query->sql, 'insert into "par2_rename_candidate_progress"')) {
                $owned = false;
            }
        });
        $processor = Mockery::mock(Par2Processor::class);
        $processor->shouldNotReceive('applyInventoryForRename');
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessageBounded')->once()->andReturn(BoundedArticleResult::success($main));

        $result = (new Par2CandidateRecovery($nntp, new PostProcessService(par2Processor: $processor)))->recover($this->candidate([$main]), $this->snapshot($release), self::MANIFEST, 1, 0, 1, 10000, 10000, static function () use (&$owned): bool {
            return $owned;
        });

        self::assertSame([Par2RenameOutcome::Deferred, 1, 1], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_hidden_payload_does_not_complete_a_release_with_changed_snapshot(): void
    {
        $release = $this->pendingPar2Release();
        $snapshot = $this->snapshot($release);
        $processor = Mockery::mock(Par2Processor::class);
        $processor->shouldNotReceive('applyInventoryForRename');
        $nntp = Mockery::mock(NNTPService::class);
        $nntp->shouldReceive('getMessageBounded')->once()->andReturnUsing(static function () use ($release): BoundedArticleResult {
            DB::table('releases')->where('id', $release->id)->update(['guid' => 'replacement-guid']);

            return BoundedArticleResult::success('ordinary non-PAR2 data');
        });
        $candidate = $this->candidate(['ordinary non-PAR2 data']);
        $candidate['explicit'] = false;

        $result = (new Par2CandidateRecovery($nntp, new PostProcessService(par2Processor: $processor)))->recover($candidate, $snapshot, self::MANIFEST, 1, 0, 1, 10000, 10000, static fn (): bool => true);

        self::assertSame(Par2RenameOutcome::Deferred, $result->outcome);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
        self::assertSame(0, $release->fresh()->proc_par2);
    }

    public function test_candidate_signature_is_canonical_when_input_object_keys_are_reordered(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$main, $rest]);
        $applications = [];
        $post = $this->postprocessor($applications);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);
        $candidate = array_reverse($candidate, true);
        $candidate['segments'] = array_map(static fn (array $segment): array => array_reverse($segment, true), $candidate['segments']);

        $result = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest)], $post);

        self::assertSame([Par2RenameOutcome::NoMatch, 1, 1], [$result->outcome, $result->probes, $result->articles]);
    }

    public function test_real_inventory_replay_keeps_file_count_stable_across_candidates(): void
    {
        [$main, $rest] = $this->realFixture();
        $release = $this->pendingPar2Release(['categories_id' => Category::MOVIE_HD]);
        $candidate = $this->candidate([$main, $rest]);
        $post = $this->realPostprocessor(false);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);
        $first = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest)], $post);
        self::assertSame(Par2RenameOutcome::NoMatch, $first->outcome);
        self::assertSame(10, $release->fresh()->rarinnerfilecount);
        $candidate['subject'] = 'another.par2';

        $second = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main), 'article-2' => BoundedArticleResult::success($rest)], $post, budget: 2);

        self::assertSame(Par2RenameOutcome::NoMatch, $second->outcome);
        self::assertSame(10, $release->fresh()->rarinnerfilecount);
        self::assertSame(0, DB::table('par2_rename_candidate_progress')->count());
    }

    public function test_budget_zero_defers_and_stores_cursor_without_fetch(): void
    {
        [$main] = $this->realFixture();
        $release = $this->pendingPar2Release();
        $applications = [];

        $result = $this->runRecovery($this->candidate([$main]), $release, [], $this->postprocessor($applications), budget: 0);

        self::assertSame([Par2RenameOutcome::Deferred, 0, 0], [$result->outcome, $result->probes, $result->articles]);
        self::assertSame(0, (int) DB::table('par2_rename_candidate_progress')->value('next_segment'));
    }

    public function test_descriptor_only_no_match_cannot_skip_a_future_declared_segment(): void
    {
        [$main, $rest] = $this->realFixture();
        $length = unpack('V', substr($rest, 8, 4))[1];
        $descriptor = substr($rest, 0, $length);
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$descriptor, $main]);
        $applications = [];

        $result = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($descriptor)], $this->postprocessor($applications));

        self::assertSame(Par2RenameOutcome::Deferred, $result->outcome);
        self::assertFalse($applications[0]['terminal']);
    }

    public function test_complete_main_inventory_can_finish_before_huge_recognized_parity_payload(): void
    {
        [$main, $rest] = $this->realFixture();
        $set = substr($main, 32, 16);
        $length = 5 * 1024 * 1024 * 1024 + 64;
        $header = Par2Info::PACKET_MARKER.pack('V2', $length & 0xFFFFFFFF, intdiv($length, 0x100000000)).str_repeat("\0", 16).$set.Par2Info::PACKET_RECOVERY;
        $first = $main.$rest.$header;
        $release = $this->pendingPar2Release();
        $candidate = $this->candidate([$first, 'unfetched parity payload']);
        $applications = [];

        $result = $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($first)], $this->postprocessor($applications));

        self::assertSame([Par2RenameOutcome::NoMatch, 1, 1], [$result->outcome, $result->probes, $result->articles]);
        self::assertTrue($applications[0]['terminal']);
    }

    public function test_real_twelve_gib_metadata_recovers_literal_title_and_exact_predb_link(): void
    {
        $release = $this->pendingPar2Release(['name' => '7ae60ee3d802bfd4388b0f2c6e982e7a', 'searchname' => '7ae60ee3d802bfd4388b0f2c6e982e7a', 'categories_id' => Category::OTHER_MISC]);
        [$main, $rest] = $this->largeMetadata();
        $candidate = $this->candidate([$main, $rest]);
        $post = $this->realPostprocessor(false);
        $this->runRecovery($candidate, $release, ['article-1' => BoundedArticleResult::success($main)], $post);

        $result = $this->runRecovery($candidate, $release, ['article-2' => BoundedArticleResult::success($rest)], $post);

        self::assertSame(Par2RenameOutcome::Renamed, $result->outcome);
        self::assertSame(self::TITLE, $release->fresh()->searchname);
        self::assertSame(1, $release->fresh()->isrenamed);
        DB::table('predb')->insert(['id' => 9, 'title' => self::TITLE]);
        ob_start();
        try {
            Predb::checkPre();
        } finally {
            ob_end_clean();
        }
        self::assertSame(9, $release->fresh()->predb_id);
    }

    /** @param list<string> $parts @return array{subject:string, explicit:bool, segments:list<array{number:int,id:string,bytes:int}>} */
    private function candidate(array $parts): array
    {
        $segments = [];
        foreach ($parts as $index => $body) {
            $segments[] = ['number' => $index + 1, 'id' => 'article-'.($index + 1), 'bytes' => strlen($body)];
        }

        return ['subject' => 'fixture.par2', 'explicit' => true, 'segments' => $segments];
    }

    /** @param array<string,mixed> $candidate @param array<string,BoundedArticleResult> $articles */
    private function runRecovery(array $candidate, Release $release, array $articles, PostProcessService $post, int $budget = 1, int $maxArticleBytes = 10000, int $maxStateBytes = 10000, string $manifest = self::MANIFEST): Par2CandidateRecoveryResult
    {
        $nntp = Mockery::mock(NNTPService::class);
        foreach ($articles as $id => $result) {
            $nntp->shouldReceive('getMessageBounded')->once()->with('alt.binaries.test', $id, $maxArticleBytes, false, null)->andReturn($result);
        }
        if ($articles === []) {
            $nntp->shouldNotReceive('getMessageBounded');
        }

        return (new Par2CandidateRecovery($nntp, $post))->recover($candidate, $this->snapshot($release), $manifest, 1, 0, $budget, $maxArticleBytes, $maxStateBytes, static fn (): bool => true);
    }

    /** @param list<array{files:array,terminal:bool}> $applications */
    private function postprocessor(array &$applications): PostProcessService
    {
        $processor = Mockery::mock(Par2Processor::class);
        $processor->shouldReceive('applyInventoryForRename')->andReturnUsing(static function (Par2PacketAccumulator $inventory, int $id, int $group, int $show, callable $guard, bool $terminal, array $snapshot) use (&$applications): Par2RenameOutcome {
            self::assertSame($id, $snapshot['id']);
            self::assertTrue($guard());
            $applications[] = ['files' => $inventory->filesBySet(), 'terminal' => $terminal];

            return $terminal ? Par2RenameOutcome::NoMatch : Par2RenameOutcome::Retryable;
        });

        return new PostProcessService(par2Processor: $processor);
    }

    private function realPostprocessor(bool $addPar2): PostProcessService
    {
        Search::shouldReceive('updateRelease')->andReturnNull();
        $category = Mockery::mock(CategorizationService::class);
        $sync = new ReleaseSearchSyncCoordinator(new PersistenceMetricsCollector, static function (int $id): void {});
        $processor = new Par2Processor(new NameFixingService(new ReleaseUpdateService($category, searchSyncCoordinator: $sync)), new Par2Info, $addPar2, false);

        return new PostProcessService(par2Processor: $processor);
    }

    /** @return array{id:int,guid:string,name:?string,searchname:?string} */
    private function snapshot(Release $release): array
    {
        return ['id' => $release->id, 'guid' => $release->guid, 'name' => $release->name, 'searchname' => $release->searchname];
    }

    /** @return array{string,string} */
    private function realFixture(): array
    {
        $bytes = file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2'));
        self::assertIsString($bytes);
        $main = $rest = '';
        for ($offset = 0; $offset < strlen($bytes);) {
            $length = unpack('V', substr($bytes, $offset + 8, 4))[1];
            $packet = substr($bytes, $offset, $length);
            if (substr($packet, 48, 16) === Par2Info::PACKET_MAIN) {
                $main .= $packet;
            } else {
                $rest .= $packet;
            }
            $offset += $length;
        }

        return [$main, $rest];
    }

    /** @return array{string,string} */
    private function largeMetadata(): array
    {
        $name = self::TITLE.'.mkv';
        $length = pack('V2', 123, 3);
        $hash16 = md5('first 16K', true);
        $id = md5($hash16.$length.$name, true);
        $main = pack('V3', 16384, 0, 1).$id;
        $set = md5($main, true);
        $descriptor = $id.md5('whole file', true).$hash16.$length.$name;

        return [$this->packet(Par2Info::PACKET_MAIN, $main, $set), $this->packet(Par2Info::PACKET_FILEDESC, $descriptor, $set)];
    }

    private function packet(string $type, string $body, string $set): string
    {
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = $set.$type.$body;

        return Par2Info::PACKET_MARKER.pack('V2', 64 + strlen($body), 0).md5($hashed, true).$hashed;
    }
}
