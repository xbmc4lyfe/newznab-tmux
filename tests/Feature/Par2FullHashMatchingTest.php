<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Par2RenameOutcome;
use App\Facades\Search;
use App\Models\ReleaseFile;
use App\Services\NameFixing\DonorMatchSelector;
use App\Services\NameFixing\NameFixingQueryService;
use App\Services\NameFixing\NameFixingService;
use App\Services\Par2PacketAccumulator;
use App\Services\Par2Processor;
use App\Support\Par2FileHashEvidence;
use dariusiii\rarinfo\Par2Info;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Par2RenameTestDatabase;
use Tests\TestCase;

final class Par2FullHashMatchingTest extends TestCase
{
    use Par2RenameTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPar2RenameSchema();
        $paths = glob(base_path('database/migrations/*_create_par_file_hash_evidence_tables.php'));
        self::assertCount(1, $paths);
        if (! Schema::hasTable('par_file_hashes')) {
            (require $paths[0])->up();
        }
        Search::shouldReceive('updateRelease')->andReturnNull();
        Schema::table('release_files', static function (Blueprint $table): void {
            $table->bigInteger('size');
            $table->string('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
            $table->boolean('passworded')->default(false);
        });
    }

    public function test_shared_support_tuple_does_not_identify_an_unrelated_release(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Unrelated.Scene.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('shared readme'), md5('shared readme'), 128);
        }
        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_hash_donor_requires_symmetric_inventory_coverage(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('payload one'), md5('prefix one'), 20000);
            $this->remember($id, md5('payload two'), md5('prefix two'), 30000);
        }
        self::assertCount(1, $this->lookup()['donors']);
        $this->remember(42, md5('target-only payload'), md5('target-only prefix'), 40000);
        self::assertSame([], $this->lookup()['donors']);
        DB::table('par_file_hashes')->where('releases_id', 42)->where('full_md5', md5('target-only payload'))->delete();
        $this->remember(20, md5('donor-only payload'), md5('donor-only prefix'), 50000);
        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_duplicate_full_tuple_with_different_prefixes_is_not_independent_evidence(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('one payload'), md5('prefix one'), 20000);
            $this->remember($id, md5('one payload'), md5('prefix two'), 20000);
        }
        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_complete_donor_inventories_with_conflicting_canonical_titles_remain_ambiguous(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'First.Scene.Release.2026-GRP');
        $this->donor(30, 2, 'Second.Scene.Release.2026-GRP');
        foreach ([42, 20, 30] as $id) {
            $this->remember($id, md5('first payload'), md5('first prefix'), 20000);
            $this->remember($id, md5('second payload'), md5('second prefix'), 30000);
        }
        $result = $this->lookup();
        self::assertCount(2, $result['donors']);
        self::assertNull((new DonorMatchSelector)->selectCanonical($result['donors']));
    }

    public function test_actual_parser_and_persistence_keep_full_digest_exact_size_and_requeue_once(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'proc_hash16k' => 1]);
        $file = $this->file(str_repeat('x', 16384).'tail', 'content.mkv');
        self::assertSame(1, ReleaseFile::addReleaseFiles(42, $file['name'], $file['size'], '2026-10-11 00:00:00', 0, $file['hash_16K'], '', $file['hash'], $file['size_decimal'], $this->snapshot(42)));
        $row = DB::table('par_file_hashes')->first();
        self::assertNotNull($row);
        self::assertSame($file['hash'], $row->full_md5);
        self::assertSame((string) $file['size'], $row->file_size);
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
        DB::table('releases')->where('id', 42)->update(['proc_hash16k' => 1]);
        self::assertSame(0, ReleaseFile::addReleaseFiles(42, $file['name'], $file['size'], '2026-10-11 00:00:00', 0, $file['hash_16K'], '', $file['hash'], $file['size_decimal'], $this->snapshot(42)));
        self::assertSame(1, DB::table('par_file_hashes')->count());
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
    }

    public function test_exact_content_lookup_excludes_equal_prefix_different_tail_without_release_size_heuristic(): void
    {
        $prefix = str_repeat('x', 16384);
        $body = $prefix.str_repeat('A', 4096);
        $different = $prefix.str_repeat('B', 4096);
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->remember(42, md5($body), md5($prefix), strlen($body));
        $this->rememberCorroboratingTuple(42);
        foreach ([10 => [$different, 'Different.Scene.Release.2026-GRPB'], 20 => [$body, 'Correct.Scene.Release.2026-GRPA']] as $id => [$content, $title]) {
            $this->donor($id, $id, $title);
            DB::table('releases')->where('id', $id)->update(['size' => strlen($content) * 2]);
            $this->remember($id, md5($content), md5($prefix), strlen($content));
            $this->rememberCorroboratingTuple($id);
        }
        $result = $this->lookup();
        self::assertTrue($result['known']);
        self::assertFalse($result['overflow']);
        self::assertCount(1, $result['donors']);
        self::assertSame(20, (int) $result['donors'][0]->releases_id);
    }

    public function test_shared_file_does_not_link_when_release_inventories_differ(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $shared = ['full' => md5('shared readme'), 'prefix' => md5('shared readme prefix'), 'size' => 100];
        $unique = ['full' => md5('target payload'), 'prefix' => md5('target payload prefix'), 'size' => 200];
        $this->remember(42, $shared['full'], $shared['prefix'], $shared['size']);
        $this->remember(42, $unique['full'], $unique['prefix'], $unique['size']);
        $this->remember(20, $shared['full'], $shared['prefix'], $shared['size']);

        self::assertSame([], $this->lookup()['donors']);

        $this->remember(20, $unique['full'], $unique['prefix'], $unique['size']);
        $result = $this->lookup();
        self::assertCount(1, $result['donors']);
        self::assertSame(20, (int) $result['donors'][0]->releases_id);
    }

    public function test_partial_par2_descriptor_inventories_cannot_nominate_a_donor(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Unrelated.Scene.Release-GRP');
        foreach ([42, 20] as $id) {
            $this->rememberUnmarked($id, md5('shared payload one'), md5('prefix one'), 20000);
            $this->rememberUnmarked($id, md5('shared payload two'), md5('prefix two'), 30000);
        }

        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_upgrade_requires_recertification_of_both_legacy_completeness_markers(): void
    {
        Schema::dropIfExists('par_file_hash_inventories');
        Schema::create('par_file_hash_inventories', static function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('release_guid', 40);
            $table->unsignedBigInteger('evidence_last_id');
            $table->unsignedBigInteger('evidence_count');
        });
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Unrelated.Scene.Release-GRP');
        foreach ([42, 20] as $id) {
            self::assertTrue($this->rememberUnmarked($id, md5('shared payload one'), md5('prefix one'), 20000));
            self::assertTrue($this->rememberUnmarked($id, md5('shared payload two'), md5('prefix two'), 30000));
            $evidence = DB::table('par_file_hashes')->where('releases_id', $id);
            DB::table('par_file_hash_inventories')->insert([
                'releases_id' => $id, 'release_guid' => $this->snapshot($id)['guid'],
                'evidence_last_id' => (clone $evidence)->max('id'), 'evidence_count' => $evidence->count(),
            ]);
        }
        (require database_path('migrations/2026_10_12_220000_create_par_file_hash_inventories_table.php'))->up();

        self::assertSame(2, DB::table('par_file_hash_inventories')->count());
        self::assertSame([], $this->lookup()['donors']);
        self::assertSame([0, 0], DB::table('par_file_hash_inventories')->orderBy('releases_id')->pluck('proof_version')->all());
        self::assertTrue(Par2FileHashEvidence::markInventoryComplete(42, $this->snapshot(42)));
        self::assertSame([], $this->lookup()['donors']);
        self::assertTrue(Par2FileHashEvidence::markInventoryComplete(20, $this->snapshot(20)));
        self::assertCount(1, $this->lookup()['donors']);
    }

    public function test_descriptor_only_packet_stream_cannot_mark_a_complete_inventory(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $inventory = $this->inventory('shared content', 'shared.nfo');
        self::assertTrue($inventory->isComplete());
        self::assertFalse($inventory->hasCompleteMainInventory());

        $processor = new Par2Processor(new NameFixingService, new Par2Info, false, false);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        self::assertSame(1, DB::table('par_file_hashes')->where('releases_id', 42)->count());
        self::assertSame(0, DB::table('par_file_hash_inventories')->where('releases_id', 42)->count());
    }

    public function test_complete_index_does_not_mark_unrelated_partial_evidence_complete(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        self::assertTrue($this->rememberUnmarked(42, md5('other partial set'), md5('other partial prefix'), 40000));
        $inventory = new Par2PacketAccumulator;
        $inventory->append(file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2')));
        self::assertTrue($inventory->hasCompleteMainInventory());

        $processor = new Par2Processor(new NameFixingService, new Par2Info, false, false);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        self::assertGreaterThan(1, DB::table('par_file_hashes')->where('releases_id', 42)->count());
        self::assertSame(0, DB::table('par_file_hash_inventories')->where('releases_id', 42)->count());
    }

    public function test_new_partial_evidence_invalidates_a_previously_complete_inventory(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('payload one'), md5('prefix one'), 20000);
            $this->remember($id, md5('payload two'), md5('prefix two'), 30000);
        }
        self::assertCount(1, $this->lookup()['donors']);

        $this->rememberUnmarked(42, md5('new partial payload'), md5('new partial prefix'), 40000);

        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_all_130_target_keys_are_considered_before_canonical_identity_selection(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->donor(30, 2, 'Scene.Release.2026-GRPB');
        foreach (range(1, 130) as $key) {
            $this->remember(42, md5('full-'.$key), md5('prefix-'.$key), 12900000000 + $key);
            $this->remember($key === 130 ? 30 : 20, md5('full-'.$key), md5('prefix-'.$key), 12900000000 + $key);
        }
        $result = $this->lookup();
        self::assertSame([], $result['donors']);
        DB::table('par_file_hashes')->where('releases_id', 30)->delete();
        $this->remember(20, md5('full-130'), md5('prefix-130'), 12900000130);
        $result = $this->lookup();
        self::assertSame(20, (int) (new DonorMatchSelector)->selectCanonical($result['donors'])->releases_id);
    }

    public function test_distinct_donor_flood_is_explicit_and_not_partial_uniqueness(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->remember(42, md5('full'), md5('prefix'), 12900000000);
        $this->rememberCorroboratingTuple(42);
        foreach (range(100, 356) as $id) {
            $this->donor($id, 1, 'Scene.Release.2026-GRPA');
            $this->remember($id, md5('full'), md5('prefix'), 12900000000);
            $this->rememberCorroboratingTuple($id);
        }
        $result = $this->lookup();
        self::assertTrue($result['overflow']);
        self::assertCount(257, $result['donors']);
    }

    public function test_empty_and_unsupported_size_markers_stay_known_and_do_not_mask_positive_content(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->remember(42, md5(''), md5(''), '0');
        $this->remember(42, md5('unsupported'), md5('prefix'), 9223372036854775808.0);
        self::assertTrue($this->lookup()['known']);
        self::assertFalse($this->lookup()['usable']);
        $this->remember(42, md5('unsupported'), md5('prefix'), 9223372036854775808.0);
        self::assertSame(2, DB::table('par_file_hashes')->count());
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('positive'), md5('positive prefix'), 12900000000);
        $this->remember(20, md5('positive'), md5('positive prefix'), 12900000000);
        $this->rememberCorroboratingTuple(42, 20);
        self::assertFalse($this->lookup()['usable']);
        DB::table('par_file_hashes')->where('releases_id', 42)->where('file_size', '')->delete();
        self::assertTrue(Par2FileHashEvidence::markInventoryComplete(42, $this->snapshot(42)));
        self::assertTrue($this->lookup()['usable']);
        self::assertCount(1, $this->lookup()['donors']);
    }

    public function test_raw_unsigned_sizes_remain_exact_and_adjacent_above_signed_range(): void
    {
        foreach (['9223372036854775808' => [0, 2147483648], '9223372036854775809' => [1, 2147483648], '18446744073709551615' => [4294967295, 4294967295]] as $expected => $words) {
            $file = $this->file('', 'content.mkv', pack('V2', ...$words));
            self::assertSame((string) $expected, $file['size_decimal']);
        }
    }

    public function test_claim_loss_and_outer_rollback_leave_no_evidence_or_requeue(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'proc_hash16k' => 1]);
        $calls = 0;
        self::assertFalse($this->remember(42, md5('full'), md5('prefix'), 12, null, static function () use (&$calls): bool {
            return ++$calls < 2;
        }));
        self::assertSame(0, DB::table('par_file_hashes')->count());
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
        DB::beginTransaction();
        self::assertTrue($this->remember(42, md5('full'), md5('prefix'), 12));
        DB::rollBack();
        self::assertSame(0, DB::table('par_file_hashes')->count());
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
    }

    public function test_donor_link_arrival_and_byte_exact_guid_replacement_change_next_lookup(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(42, 20);
        DB::table('releases')->where('id', 20)->update(['predb_id' => 0]);
        self::assertCount(0, $this->lookup()['donors']);
        DB::table('releases')->where('id', 20)->update(['predb_id' => 1]);
        self::assertCount(1, $this->lookup()['donors']);
        DB::table('releases')->where('id', 20)->update(['guid' => 'DONOR-20']);
        self::assertCount(0, $this->lookup()['donors']);
        DB::table('releases')->where('id', 42)->update(['guid' => 'TARGET']);
        $result = (new NameFixingQueryService(DB::connection()))->fullHashDonorsForRelease(42, 'TARGET');
        self::assertTrue($result['known']);
        self::assertFalse($result['usable']);
    }

    public function test_strong_mismatch_never_falls_back_and_due_retry_can_find_a_later_link(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'size' => 20000, 'searchname' => 'obfuscated']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('target'), md5('shared prefix'), 20000);
        $this->remember(20, md5('different'), md5('shared prefix'), 20000);
        $this->rememberCorroboratingTuple(42, 20);
        DB::table('releases')->where('id', 20)->update(['size' => 20000]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 20000]);
        $service = new NameFixingService;
        $legacy = (new NameFixingQueryService)->hashDonors([md5('shared prefix')]);
        $method = new \ReflectionMethod($service, 'processPar2HashCandidate');
        self::assertFalse($method->invoke($service, $input, [(object) ['hash' => md5('shared prefix')]], $legacy, true, true, false));
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('predb_id'));
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
        self::assertSame(1, DB::table('par2_hash_retry')->where('releases_id', 42)->count());
        DB::table('par_file_hashes')->where('releases_id', 20)->where('full_md5', md5('different'))->delete();
        $this->remember(20, md5('target'), md5('shared prefix'), 20000);
        self::assertFalse($method->invoke($service, $input, [], [], true, true, false));
        DB::table('par2_hash_retry')->where('releases_id', 42)->update(['retry_at' => now()->subSecond()]);
        self::assertTrue($method->invoke($service, $input, [], [], true, true, false));
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('predb_id'));
        self::assertSame('Scene.Release.2026-GRPA', DB::table('releases')->where('id', 42)->value('searchname'));
    }

    public function test_a_later_exact_size_repairs_only_its_unsupported_marker(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->remember(42, md5('target'), md5('prefix'), 9223372036854775808.0);
        $this->remember(42, md5('other'), md5('other prefix'), 9223372036854775808.0);
        self::assertTrue($this->remember(42, md5('target'), md5('prefix'), '9223372036854775808'));
        self::assertSame(1, DB::table('par_file_hashes')->where('file_size', '')->count());
        self::assertSame('9223372036854775808', DB::table('par_file_hashes')->where('full_md5', md5('target'))->value('file_size'));
        self::assertFalse($this->lookup()['usable']);
    }

    public function test_actual_inventory_persists_all_full_hashes_without_collecting_files_and_replay_preserves_count(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'categories_id' => 2000, 'proc_hash16k' => 1, 'postdate' => now()->toDateTimeString()]);
        $inventory = new Par2PacketAccumulator;
        $inventory->append(file_get_contents(base_path('tests/Fixtures/par2/rename-index.par2')));
        $processor = new Par2Processor(new NameFixingService, new Par2Info, false, false);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        self::assertSame(10, DB::table('par_file_hashes')->where('releases_id', 42)->count());
        self::assertSame(1, DB::table('par_file_hash_inventories')->where('releases_id', 42)->count());
        self::assertSame(Par2FileHashEvidence::INVENTORY_PROOF_VERSION, DB::table('par_file_hash_inventories')->where('releases_id', 42)->value('proof_version'));
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame(10, DB::table('releases')->where('id', 42)->value('rarinnerfilecount'));
        DB::table('releases')->where('id', 42)->update(['proc_hash16k' => 1]);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        self::assertSame(10, DB::table('par_file_hashes')->count());
        self::assertSame(10, DB::table('releases')->where('id', 42)->value('rarinnerfilecount'));
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
    }

    public function test_preview_reports_strong_match_without_deleting_due_retry_or_writing_target(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(42, 20);
        DB::table('par2_hash_retry')->insert(['releases_id' => 42, 'release_guid' => 'target', 'retry_at' => now()->subSecond()->toDateTimeString()]);
        $before = DB::table('par2_hash_retry')->first();
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 12]);
        $service = new NameFixingService;
        self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], false, true, false));
        self::assertEquals($before, DB::table('par2_hash_retry')->first());
        self::assertSame('obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    public function test_query_callback_cannot_redirect_hash_evidence_to_another_release(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated']);
        $other = $this->pendingPar2Release(['id' => 43, 'guid' => 'other', 'searchname' => 'other-obfuscated']);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(42, 20);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 12]);
        $fired = false;
        DB::connection()->beforeExecuting(static function (string $query) use ($input, $other, &$fired): void {
            if (! $fired && str_contains($query, 'SELECT DISTINCT r.id AS releases_id')) {
                $fired = true;
                foreach (array_merge($other->getAttributes(), ['releases_id' => 43, 'relsize' => 12]) as $key => $value) {
                    $input->{$key} = $value;
                }
            }
        });
        $service = new NameFixingService;
        self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertTrue($fired);
        self::assertObjectNotHasProperty('observed_par2_hash_generation', $input);
        self::assertObjectNotHasProperty('observed_isrenamed', $input);
        self::assertSame(0, DB::table('releases')->where('id', 43)->value('predb_id'));
        self::assertSame('other-obfuscated', DB::table('releases')->where('id', 43)->value('searchname'));
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    public function test_optional_full_hash_file_api_rejects_wrong_generation_before_any_metadata(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'replacement']);
        $snapshot = $this->snapshot(42);
        $snapshot['guid'] = 'old-generation';
        self::assertSame(0, ReleaseFile::addReleaseFiles(42, 'content.mkv', 12, '2026-10-11 00:00:00', 0, md5('prefix'), '', md5('full'), '12', $snapshot));
        self::assertSame(0, DB::table('release_files')->count());
        self::assertSame(0, DB::table('par_hashes')->count());
        self::assertSame(0, DB::table('par_file_hashes')->count());
        self::assertFalse(Par2FileHashEvidence::remember(42, md5('full'), md5('prefix'), 12));
        self::assertFalse(Par2FileHashEvidence::remember(42, md5('full'), md5('prefix'), 12, ['id' => 42, 'guid' => 'replacement']));
    }

    public function test_unknown_full_hash_keeps_legacy_renamed_misc_admission(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'isrenamed' => 1, 'searchname' => 'obfuscated', 'size' => 20000]);
        $this->donor(20, 1, 'Scene.Release.2026-GRPA');
        DB::table('releases')->where('id', 20)->update(['size' => 20000]);
        $hash = md5('prefix');
        DB::table('par_hashes')->insert(['releases_id' => 20, 'hash' => $hash]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 20000]);
        $service = new NameFixingService;
        self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [(object) ['hash' => $hash]], (new NameFixingQueryService)->hashDonors([$hash]), true, true, false));
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    public function test_cooldown_filters_both_hash_queues_without_masking_another_source(): void
    {
        $attributes = ['guid' => 'target', 'leftguid' => 't', 'nfostatus' => 0, 'passwordstatus' => 0,
            'proc_nfo' => 1, 'proc_files' => 1, 'proc_uid' => 1, 'proc_par2' => 1, 'proc_srr' => 1, 'proc_crc32' => 1, 'proc_hash16k' => 0];
        $this->pendingPar2Release(array_merge($attributes, ['id' => 42]));
        $this->remember(42, md5('full'), md5('prefix'), 12);
        DB::table('par2_hash_retry')->insert(['releases_id' => 42, 'release_guid' => 'target', 'retry_at' => now()->addMinute()->toDateTimeString()]);
        $queries = new NameFixingQueryService;
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 2));
        self::assertSame([], $queries->standardCandidateBatch('t', 10));
        DB::table('releases')->where('id', 42)->update(['proc_files' => 0]);
        self::assertCount(1, $queries->standardCandidateBatch('t', 10));
        DB::table('releases')->where('id', 42)->update(['proc_files' => 1]);
        DB::table('par2_hash_retry')->where('releases_id', 42)->update(['retry_at' => now()->subSecond()->toDateTimeString()]);
        self::assertCount(1, $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 2));
        self::assertCount(1, $queries->standardCandidateBatch('t', 10));
    }

    public function test_lossy_replay_cannot_downgrade_exact_unsigned_evidence(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        self::assertTrue($this->remember(42, md5('target'), md5('prefix'), '9223372036854775809'));
        DB::table('releases')->where('id', 42)->update(['proc_hash16k' => 1]);
        self::assertTrue($this->remember(42, md5('target'), md5('prefix'), 9223372036854775808.0));
        self::assertSame(1, DB::table('par_file_hashes')->count());
        self::assertSame('9223372036854775809', DB::table('par_file_hashes')->value('file_size'));
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
        self::assertTrue($this->lookup()['usable']);
    }

    public function test_evidence_arriving_at_legacy_completion_is_not_lost(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated']);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 12]);
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$fired): void {
            if (! $fired && preg_match('/^update\b.*releases.*proc_hash16k/i', $query) === 1) {
                $fired = true;
                $this->remember(42, md5('new evidence'), md5('prefix'), 12);
            }
        });
        $service = new NameFixingService;
        self::assertFalse((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertTrue($fired);
        self::assertSame(1, DB::table('par_file_hashes')->count());
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('proc_hash16k'));
    }

    public function test_new_strong_evidence_at_cooldown_write_keeps_immediate_requeue(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated']);
        $this->remember(42, md5('old evidence'), md5('prefix'), 12);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 12]);
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$fired): void {
            if (! $fired && str_contains($query, 'insert') && str_contains($query, '"par2_hash_retry"')) {
                $fired = true;
                $this->remember(42, md5('new evidence'), md5('prefix'), 12);
            }
        });
        $service = new NameFixingService;
        self::assertFalse((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertTrue($fired);
        self::assertSame(2, DB::table('par_file_hashes')->count());
        self::assertSame(0, DB::table('par2_hash_retry')->count());
    }

    public function test_validated_12gib_descriptor_links_exact_observed_title_by_content_evidence(): void
    {
        $title = 'Little.Fockers.2010.UHD.BluRay.1080p.DDP.5.1.Atmos.DV.HDR.x265-faBR';
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'categories_id' => 2000, 'searchname' => 'obfuscated']);
        $this->donor(20, 7, $title);
        $length = pack('V2', 123, 3);
        $inventory = $this->inventory('synthetic content hash; no media download', 'obfuscated.mkv', $length);
        $file = array_values(array_values($inventory->filesBySet())[0])[0];
        self::assertSame(12884902011, $file['size']);
        self::assertSame('12884902011', $file['size_decimal']);
        $processor = new Par2Processor(new NameFixingService, new Par2Info, false, false);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        $this->remember(20, $file['hash'], $file['hash_16K'], $file['size_decimal']);
        $this->rememberCorroboratingTuple(42, 20);
        DB::table('releases')->where('id', 20)->update(['size' => 1000]);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 1]);
        $service = new NameFixingService;
        self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertSame($title, DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame(7, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    public function test_equal_filenames_in_separate_sets_keep_both_strong_tuples(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'categories_id' => 2000]);
        $inventory = $this->inventory('first content', 'same.mkv');
        $second = base64_decode($this->inventory('second content', 'same.mkv')->exportState()['packets'][0], true);
        $second = substr_replace($second, md5('different set', true), 32, 16);
        $second = substr_replace($second, md5(substr($second, 32), true), 16, 16);
        $inventory->append($second);
        $processor = new Par2Processor(new NameFixingService, new Par2Info, false, false);
        self::assertSame(Par2RenameOutcome::NoMatch, $processor->applyInventoryForRename($inventory, 42, 1, 0, null, true, $this->snapshot(42)));
        self::assertSame(2, DB::table('par_file_hashes')->count());
        self::assertSame(1, DB::table('releases')->where('id', 42)->value('rarinnerfilecount'));
        self::assertSame(0, DB::table('release_files')->count());
    }

    public function test_novel_strong_evidence_requeues_both_existing_source_hash_statuses_and_categories_override(): void
    {
        $this->donor(20, 7, 'Scene.Release.2026-GRPA');
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(20);
        foreach ([0, 1] as $status) {
            foreach ([2, 3] as $categories) {
                $id = 100 + $status * 10 + $categories;
                $release = $this->pendingPar2Release(['id' => $id, 'guid' => 'target-'.$id, 'isrenamed' => $status, 'searchname' => 'obfuscated', 'proc_hash16k' => 1]);
                $this->remember($id, md5('full'), md5('prefix'), 12);
                $this->rememberCorroboratingTuple($id);
                self::assertSame(0, DB::table('releases')->where('id', $id)->value('proc_hash16k'));
                $queries = new NameFixingQueryService;
                self::assertContains($id, array_map(static fn (object $row): int => (int) $row->releases_id, $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, $categories)));
                $input = (object) array_merge($release->getAttributes(), ['releases_id' => $id, 'relsize' => 12]);
                $service = new NameFixingService;
                self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
                self::assertSame(7, DB::table('releases')->where('id', $id)->value('predb_id'));
            }
        }
    }

    public function test_new_evidence_preserves_linked_identity_and_processing_status(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'predb_id' => 9, 'isrenamed' => 1, 'searchname' => 'Original.Release-GRP', 'proc_hash16k' => 1]);
        $before = DB::table('releases')->where('id', 42)->first();
        self::assertTrue($this->remember(42, md5('full'), md5('prefix'), 12));
        self::assertEquals($before, DB::table('releases')->where('id', 42)->first());
        self::assertSame([], (new NameFixingQueryService)->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 3));
    }

    public function test_foreign_rename_status_change_at_actual_hash_update_is_not_overwritten(): void
    {
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'isrenamed' => 1, 'searchname' => 'obfuscated']);
        $this->donor(20, 7, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(42, 20);
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 12]);
        $fired = false;
        DB::connection()->beforeExecuting(static function (string $query) use (&$fired): void {
            if (! $fired && preg_match('/^update\\b.*releases.*searchname/i', $query) === 1) {
                $fired = true;
                DB::table('releases')->where('id', 42)->update(['isrenamed' => 0]);
            }
        });
        $service = new NameFixingService;
        self::assertFalse((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertTrue($fired);
        self::assertSame(0, DB::table('releases')->where('id', 42)->value('predb_id'));
        self::assertSame('obfuscated', DB::table('releases')->where('id', 42)->value('searchname'));
    }

    public function test_stronger_evidence_preserves_existing_time_and_category_filters(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'isrenamed' => 1, 'categories_id' => 2000, 'adddate' => now()->subDay()->toDateTimeString()]);
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $queries = new NameFixingQueryService;
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 1, 3));
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 1));
        self::assertSame([], $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 2));
        DB::table('releases')->where('id', 42)->update(['proc_hash16k' => 1]);
        self::assertCount(1, $queries->candidateBatch(NameFixingQueryService::SOURCE_HASH, 2, 3));
    }

    public function test_public_strong_hash_handler_rejects_evidence_arriving_at_the_rename_write(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated']);
        $this->donor(20, 7, 'Scene.Release.2026-GRPA');
        $this->remember(42, md5('full'), md5('prefix'), 12);
        $this->remember(20, md5('full'), md5('prefix'), 12);
        $this->rememberCorroboratingTuple(42, 20);
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$fired): void {
            if (! $fired && preg_match('/^update\b.*releases.*searchname/i', $query) === 1) {
                $fired = true;
                self::assertTrue($this->remember(42, md5('later unsupported'), md5('later prefix'), 9223372036854775808.0));
            }
        });

        ob_start();
        try {
            (new NameFixingService)->fixNamesWithParHash(2, true, 2, true, false);
        } finally {
            ob_end_clean();
        }

        self::assertTrue($fired);
        $target = DB::table('releases')->where('id', 42)->first();
        self::assertSame(0, (int) $target->predb_id);
        self::assertSame('obfuscated', $target->searchname);
        self::assertSame(0, (int) $target->proc_hash16k);
        self::assertSame(3, DB::table('par_file_hashes')->where('releases_id', 42)->count());
        self::assertFalse($this->lookup()['usable']);
        self::assertSame(0, DB::table('par2_hash_retry')->count());
    }

    public function test_public_legacy_hash_handler_rejects_first_full_evidence_arriving_at_the_rename_write(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'searchname' => 'obfuscated', 'size' => 20000]);
        $this->donor(20, 7, 'Scene.Release.2026-GRPA');
        DB::table('releases')->where('id', 20)->update(['size' => 20000]);
        $hash = md5('prefix');
        DB::table('par_hashes')->insert([
            ['releases_id' => 42, 'hash' => $hash],
            ['releases_id' => 20, 'hash' => $hash],
        ]);
        $fired = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$fired, $hash): void {
            if (! $fired && preg_match('/^update\b.*releases.*searchname/i', $query) === 1) {
                $fired = true;
                self::assertTrue($this->remember(42, md5('known mismatch'), $hash, 20000));
            }
        });

        ob_start();
        try {
            (new NameFixingService)->fixNamesWithParHash(2, true, 2, true, false);
        } finally {
            ob_end_clean();
        }

        self::assertTrue($fired);
        $target = DB::table('releases')->where('id', 42)->first();
        self::assertSame(0, (int) $target->predb_id);
        self::assertSame('obfuscated', $target->searchname);
        self::assertSame(0, (int) $target->proc_hash16k);
        self::assertSame(1, DB::table('par_file_hashes')->where('releases_id', 42)->count());
        self::assertTrue($this->lookup()['known']);
        self::assertSame([], $this->lookup()['donors']);
        self::assertSame(0, DB::table('par2_hash_retry')->count());
    }

    public function test_anchor_invalid_first_row_fails_closed_for_all_validation_variants(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 7, 'Synthetic.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('first'), md5('prefix first'), '20000');
            $this->remember($id, md5('second'), md5('prefix second'), '30000');
        }
        $first = DB::table('par_file_hashes')->where('releases_id', 42)->orderBy('id')->first();
        foreach ([['release_guid', 'TARGET'], ['full_md5', str_repeat('G', 32)], ['hash16k', strtoupper(md5('prefix first'))], ['file_size', '020000'], ['file_size', '18446744073709551616'], ['file_size', '-1'], ['file_size', ''], ['file_size', '1e3']] as [$field, $bad]) {
            DB::table('par_file_hashes')->where('id', $first->id)->update([$field => $bad]);
            $result = $this->lookup();
            self::assertFalse($result['usable'], $field.':'.$bad);
            self::assertSame([], $result['donors']);
            DB::table('par_file_hashes')->where('id', $first->id)->update([$field => $first->$field]);
        }
    }

    public function test_anchor_skips_zero_first_row_and_keeps_duplicate_positive_evidence(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 7, 'Synthetic.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('empty'), md5('empty'), '0');
            $this->remember($id, md5('first'), md5('prefix first'), '20000');
            $this->remember($id, md5('first'), md5('different prefix'), '20000');
            $this->remember($id, md5('second'), md5('prefix second'), '30000');
        }
        self::assertCount(1, $this->lookup()['donors']);
    }

    public function test_lowest_positive_tuple_missing_from_donor_cannot_be_replaced_by_a_later_shared_tuple(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 7, 'Synthetic.Release.2026-GRP');
        $this->remember(42, md5('first'), md5('prefix first'), '20000');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('second'), md5('prefix second'), '30000');
            $this->remember($id, md5('third'), md5('prefix third'), '40000');
        }
        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_anchor_inventory_counter_and_proof_variants_fail_closed(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 7, 'Synthetic.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            $this->remember($id, md5('first'), md5('prefix first'), '20000');
            $this->remember($id, md5('second'), md5('prefix second'), '30000');
        }
        foreach ([42, 20] as $id) {
            $original = DB::table('par_file_hash_inventories')->where('releases_id', $id)->first();
            foreach ([['release_guid', strtoupper($original->release_guid)], ['proof_version', 0], ['evidence_last_id', 0], ['evidence_count', 1], ['evidence_count', 3]] as [$field, $bad]) {
                DB::table('par_file_hash_inventories')->where('releases_id', $id)->update([$field => $bad]);
                self::assertSame([], $this->lookup()['donors'], $id.':'.$field);
                DB::table('par_file_hash_inventories')->where('releases_id', $id)->update([$field => $original->$field]);
            }
        }
        self::assertCount(1, $this->lookup()['donors']);
    }

    public function test_uint64_maximum_anchor_remains_an_exact_text_tuple(): void
    {
        $this->pendingPar2Release(['id' => 42, 'guid' => 'target']);
        $this->donor(20, 7, 'Synthetic.Release.2026-GRP');
        foreach ([42, 20] as $id) {
            self::assertTrue($this->remember($id, md5('first'), md5('prefix first'), '18446744073709551615'));
            self::assertTrue($this->remember($id, md5('second'), md5('prefix second'), '9223372036854775808'));
        }
        self::assertCount(1, $this->lookup()['donors']);
        DB::table('par_file_hashes')->where('releases_id', 20)->where('file_size', '18446744073709551615')->update(['file_size' => '18446744073709551614']);
        self::assertSame([], $this->lookup()['donors']);
    }

    public function test_complete_128_file_inventory_renames_through_hash_handler(): void
    {
        $title = 'Synthetic.Release.2026-GRP';
        $release = $this->pendingPar2Release(['id' => 42, 'guid' => 'target', 'categories_id' => 2000, 'searchname' => 'obfuscated']);
        $this->donor(20, 7, $title);
        for ($i = 0; $i < 128; $i++) {
            foreach ([42, 20] as $id) {
                self::assertTrue($this->remember($id, md5('payload-'.$i), md5('prefix-'.$i), (string) (20000 + $i)));
            }
        }
        $input = (object) array_merge($release->getAttributes(), ['releases_id' => 42, 'relsize' => 1]);
        $service = new NameFixingService;
        self::assertTrue((new \ReflectionMethod($service, 'processPar2HashCandidate'))->invoke($service, $input, [], [], true, true, false));
        self::assertSame($title, DB::table('releases')->where('id', 42)->value('searchname'));
        self::assertSame(7, DB::table('releases')->where('id', 42)->value('predb_id'));
    }

    /** @return array{id: int, guid: string, name: ?string, searchname: ?string} */
    private function snapshot(int $id): array
    {
        $row = DB::table('releases')->where('id', $id)->first();

        return ['id' => $id, 'guid' => $row->guid, 'name' => $row->name, 'searchname' => $row->searchname];
    }

    /** @param array<string, mixed>|null $snapshot
     * @param  null|callable(): bool  $guard
     */
    private function remember(int $id, string $full, string $prefix, mixed $size, ?array $snapshot = null, ?callable $guard = null): bool
    {
        $expectedSnapshot = $snapshot ?? $this->snapshot($id);
        $remembered = Par2FileHashEvidence::remember($id, $full, $prefix, $size, $expectedSnapshot, $guard);
        if ($remembered) {
            Par2FileHashEvidence::markInventoryComplete($id, $expectedSnapshot, $guard);
        }

        return $remembered;
    }

    private function rememberUnmarked(int $id, string $full, string $prefix, mixed $size): bool
    {
        return Par2FileHashEvidence::remember($id, $full, $prefix, $size, $this->snapshot($id));
    }

    private function rememberCorroboratingTuple(int ...$releaseIds): void
    {
        foreach ($releaseIds as $releaseId) {
            $this->remember($releaseId, md5('independent archive volume'), md5('archive prefix'), 40960);
        }
    }

    private function donor(int $id, int $predb, string $title): void
    {
        $this->pendingPar2Release(['id' => $id, 'guid' => 'donor-'.$id, 'searchname' => $title, 'predb_id' => $predb]);
        DB::table('predb')->insertOrIgnore(['id' => $predb, 'title' => $title]);
    }

    /** @return array{known: bool, usable: bool, overflow: bool, donors: list<object>, generation: string} */
    private function lookup(): array
    {
        return (new NameFixingQueryService(DB::connection()))->fullHashDonorsForRelease(42, 'target');
    }

    /** @return array<string, mixed> */
    private function file(string $data, string $name, ?string $length = null): array
    {
        return array_values(array_values($this->inventory($data, $name, $length)->filesBySet())[0])[0];
    }

    private function inventory(string $data, string $name, ?string $length = null): Par2PacketAccumulator
    {
        $length ??= pack('V2', strlen($data), 0);
        $prefix = md5(substr($data, 0, 16384), true);
        $id = md5($prefix.$length.$name, true);
        $body = $id.md5($data, true).$prefix.$length.$name;
        $body .= str_repeat("\0", (4 - strlen($body) % 4) % 4);
        $hashed = md5('set', true).Par2Info::PACKET_FILEDESC.$body;
        $inventory = new Par2PacketAccumulator;
        $inventory->append(Par2Info::PACKET_MARKER.pack('V2', strlen($body) + 64, 0).md5($hashed, true).$hashed);

        return $inventory;
    }
}
