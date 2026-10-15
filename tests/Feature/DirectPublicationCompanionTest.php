<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\NfoService;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NativeManifestIoProbe;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapturedUploadFixture;

class DirectPublicationCompanionTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public static function resetRetirementSchedules(): array
    {
        $cases = [];
        foreach (['initial', 'replay', 'invalid'] as $mode) {
            foreach (['intent_dir', 'complete_dir', 'manifest_parent', 'custody_gc_parent'] as $boundary) {
                $cases[$mode.' '.$boundary] = [$mode, $boundary];
            }
        }

        return $cases;
    }

    #[DataProvider('resetRetirementSchedules')]
    public function test_reset_is_excluded_through_companion_retirement(string $mode, string $boundary): void
    {
        [$path, $manifests, $service] = $this->directUpload(nfoBody: $mode === 'invalid' ? '' : 'direct companion');
        if ($mode === 'replay') {
            self::assertSame('imported', $service->importManifest($path)['status']);
        }
        $probes = 0;
        $admitted = false;
        $retiring = false;
        DirectorySyncBridge::$afterSync = static function (string $directory, string $point) use ($path, $boundary, &$probes, &$admitted, &$retiring): void {
            if ($point === 'intent_dir') {
                $retiring = true;
            }
            if (! $retiring || $point !== $boundary || ($point === 'manifest_parent' && $directory !== dirname($path))) {
                return;
            }
            $probes++;
            $lock = fopen(config('release_verification.storage_path').'/reset.lock', 'c');
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    $admitted = true;
                    // The full reset removes the committed NFO and its SQL authority.
                    DB::table('nfo_cleanup_commits')->delete();
                    DB::table('release_nfos')->delete();
                    DB::table('releases')->delete();
                    flock($lock, LOCK_UN);
                }
            } finally {
                fclose($lock);
            }
        };
        try {
            $service->importManifest($path, delete: true, deleteFailed: $mode === 'invalid');
        } finally {
            DirectorySyncBridge::$afterSync = null;
        }
        self::assertGreaterThan(0, $probes);
        self::assertFalse($admitted, 'Reset crossed SQL-authorized companion retirement.');
        self::assertSame(1, DB::table('releases')->count());
        self::assertSame($mode === 'invalid' ? 0 : 1, DB::table('nfo_cleanup_commits')->count());
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertSame([], glob(dirname($path).'/.custody-*'));
        $result = $manifests->read($path);
        $field = $mode === 'invalid' ? 'nfo_failed_relinquished_attempt' : 'nfo_relinquished_attempt';
        self::assertSame($result['nfo_capture']['attempt'], $result[$field]);
    }

    public function test_invalid_companion_retirement_defers_inside_an_ambient_transaction(): void
    {
        [$path, $manifests, $service] = $this->directUpload(nfoBody: '');
        self::assertSame('failed', $service->importManifest($path)['status']);
        $before = $manifests->read($path);
        DB::beginTransaction();
        try {
            self::assertFalse($manifests->deleteTerminalCompanion($path));
            self::assertFileExists(dirname($path).'/info.nfo');
            self::assertSame($before, $manifests->read($path));
            self::assertSame([], glob(dirname($path).'/.custody-*'));
        } finally {
            DB::rollBack();
        }
        self::assertTrue($manifests->deleteTerminalCompanion($path));
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
    }

    public static function callbackCompanionStates(): array
    {
        return ['stored' => ['direct companion', 'imported'], 'failed' => ['', 'failed']];
    }

    #[DataProvider('callbackCompanionStates')]
    public function test_replayed_inserted_outcome_does_not_overwrite_companion_progress(string $body, string $status): void
    {
        [$path, $manifests, $service] = $this->directUpload(nfoBody: $body);
        $captured = $manifests->read($path)['accepted_capture'];
        self::assertSame($status, $service->importManifest($path)['status']);
        $progress = $manifests->read($path);
        $writes = 0;
        NativeManifestIoProbe::$beforeRename = static function (string $from, string $to) use ($path, &$writes): void {
            if ($to === $path) {
                $writes++;
            }
        };
        try {
            $manifests->recordNzbOutcome(dirname($path).'/incoming.nzb', NzbImportStatus::Inserted, 7, 'direct-fixture-guid', inputCapture: $captured);
        } finally {
            NativeManifestIoProbe::$beforeRename = null;
        }
        self::assertSame($progress, $manifests->read($path));
        self::assertSame(0, $writes);
    }

    public function test_direct_policy_transition_discards_stale_candidate_authority(): void
    {
        [$path, $manifests, $nfos] = $this->directUpload(true);
        self::assertNull($manifests->read($path)['candidate_identity']);
        self::assertSame('imported', $nfos->importManifest($path, true)['status']);
        self::assertNull(DB::table('nfo_cleanup_commits')->value('candidate_identity'));
        self::assertSame('metadata_deferred', DB::table('release_verification_candidates')->value('status'));
    }

    public function test_direct_publication_stores_and_cleans_its_companion_without_candidate_authority(): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        // Changing policy later must not invent or revoke the recorded direct outcome.
        config(['release_verification.required' => true]);
        self::assertSame('imported', $service->importManifest($path, true)['status']);
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertSame(0, DB::table('release_verification_candidates')->count());
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
        self::assertNull(DB::table('nfo_cleanup_commits')->value('candidate_identity'));
        self::assertTrue($manifests->nfoCleanupCommitted($manifests->read($path)));
    }

    public function test_release_deletion_reclaims_committed_cleanup_receipts(): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        self::assertSame('imported', $service->importManifest($path)['status']);
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
        DB::table('releases')->where('id', 7)->delete();
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertFalse($manifests->nfoCleanupCommitted($manifests->read($path)));
    }

    public static function invalidCompanions(): array
    {
        return ['empty' => [''], 'oversized' => [str_repeat('x', 65536)]];
    }

    #[DataProvider('invalidCompanions')]
    public function test_delete_failed_retires_only_the_captured_invalid_companion(string $body): void
    {
        [$path, $manifests, $service] = $this->directUpload(nfoBody: $body);
        self::assertSame('failed', $service->importManifest($path, deleteFailed: true)['status']);
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertFileExists(dirname($path).'/incoming.nzb');
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertSame(0, DB::table('release_nfos')->count());
        self::assertSame('skipped', $service->importManifest($path)['status']);
        self::assertSame([], glob(dirname($path).'/.custody-*'));
    }

    #[DataProvider('interruptedCleanupBoundaries')]
    public function test_failed_payload_deletion_resumes_without_repeating_delete_failed(string $boundary): void
    {
        [$path, $manifests, $service] = $this->directUpload(nfoBody: '');
        DirectorySyncBridge::$fail = $boundary;
        try {
            self::assertSame('failed', $service->importManifest($path, deleteFailed: true)['status']);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertArrayHasKey('delete_terminal_nfo', $manifests->read($path));
        self::assertSame('skipped', $service->importManifest($path)['status']);
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertFileExists(dirname($path).'/incoming.nzb');
        self::assertSame([], glob(dirname($path).'/.custody-*'));
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
    }

    public static function replacementModes(): array
    {
        return ['absent original' => [false], 'new independent original' => [true]];
    }

    #[DataProvider('replacementModes')]
    public function test_repeated_delete_does_not_recreate_retired_journals(bool $replacement): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        self::assertSame('imported', $service->importManifest($path, true)['status']);
        self::assertSame([], glob(dirname($path).'/.custody-*'));
        if ($replacement) {
            file_put_contents(dirname($path).'/info.nfo', 'independent replacement');
        }
        self::assertSame('skipped', $service->importManifest($path, true)['status']);
        self::assertArrayNotHasKey('delete_nfo_after_import', $manifests->read($path));
        self::assertSame([], glob(dirname($path).'/.custody-*'));
        if ($replacement) {
            self::assertSame('independent replacement', file_get_contents(dirname($path).'/info.nfo'));
        }
    }

    public static function journalGcFaults(): array
    {
        return ['files' => ['custody_gc_files'], 'parent' => ['custody_gc_parent']];
    }

    #[DataProvider('journalGcFaults')]
    public function test_nfo_only_replay_drains_pending_journal_gc(string $boundary): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        DirectorySyncBridge::$fail = $boundary;
        try {
            self::assertSame('imported', $service->importManifest($path, true)['status']);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertArrayHasKey('custody_gc', $manifests->read($path));
        self::assertSame('skipped', $service->importManifest($path)['status']);
        self::assertArrayNotHasKey('custody_gc', $manifests->read($path));
        self::assertSame([], glob(dirname($path).'/.custody-*'));
    }

    public function test_direct_receipt_failure_rolls_back_nfo_and_retains_both_inputs_for_retry(): void
    {
        [$path, , $service] = $this->directUpload();
        DB::unprepared("CREATE TRIGGER fail_direct_receipt BEFORE INSERT ON nfo_cleanup_commits BEGIN SELECT RAISE(ABORT, 'synthetic receipt failure'); END");
        self::assertSame('failed', $service->importManifest($path, true)['status']);
        self::assertSame(0, DB::table('release_nfos')->count());
        self::assertSame(-1, DB::table('releases')->value('nfostatus'));
        self::assertFileExists(dirname($path).'/incoming.nzb');
        self::assertFileExists(dirname($path).'/info.nfo');
        DB::unprepared('DROP TRIGGER fail_direct_receipt');
        self::assertSame('imported', $service->importManifest($path, true)['status']);
    }

    public function test_direct_publication_rejects_replaced_source(): void
    {
        [$path, , $service] = $this->directUpload();
        copy(dirname($path).'/incoming.nzb', dirname($path).'/replacement.nzb');
        rename(dirname($path).'/replacement.nzb', dirname($path).'/incoming.nzb');
        self::assertSame('skipped', $service->importManifest($path, true, true)['status']);
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertFileExists(dirname($path).'/info.nfo');
    }

    public function test_duplicate_replay_preserves_the_same_successful_origin_for_companion_import(): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        $source = dirname($path).'/incoming.nzb';
        $accepted = $manifests->read($path);
        $replay = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Duplicate;
            }
        };
        $replay->beginImport([$source], deleteFailed: true);
        $fields = array_flip(['state', 'release_id', 'release_guid', 'accepted_capture', 'outcome_capture', 'outcome_status']);
        self::assertSame(array_intersect_key($accepted, $fields), array_intersect_key($manifests->read($path), $fields));
        self::assertFileExists(dirname($path).'/info.nfo');
        self::assertSame('imported', $service->importManifest($path, true, true)['status']);
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
    }

    public function test_direct_publication_rejects_reused_release_identity(): void
    {
        [$path, , $service] = $this->directUpload();
        DB::table('releases')->where('id', 7)->update(['guid' => 'replacement-guid']);
        self::assertSame('failed', $service->importManifest($path, true)['status']);
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertFileExists(dirname($path).'/info.nfo');
    }

    public static function replacementWritePoints(): array
    {
        return ['NFO insert' => ['release_nfos'], 'receipt insert' => ['nfo_cleanup_commits']];
    }

    #[DataProvider('replacementWritePoints')]
    public function test_source_replacement_during_nfo_write_rolls_back_receipt_and_nfo(string $table): void
    {
        [$path, , $service] = $this->directUpload();
        $armed = true;
        DB::listen(static function (QueryExecuted $query) use ($path, $table, &$armed): void {
            if ($armed && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, $table)) {
                $armed = false;
                copy(dirname($path).'/incoming.nzb', dirname($path).'/replacement.nzb');
                rename(dirname($path).'/replacement.nzb', dirname($path).'/incoming.nzb');
            }
        });
        try {
            self::assertSame('failed', $service->importManifest($path, true)['status']);
        } finally {
            $armed = false;
        }
        self::assertSame(0, DB::table('nfo_cleanup_commits')->count());
        self::assertSame(0, DB::table('release_nfos')->count());
        self::assertFileExists(dirname($path).'/incoming.nzb');
        self::assertFileExists(dirname($path).'/info.nfo');
    }

    public static function interruptedCleanupBoundaries(): array
    {
        return array_combine(['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir'], array_map(static fn (string $boundary): array => [$boundary], ['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir']));
    }

    #[DataProvider('interruptedCleanupBoundaries')]
    public function test_committed_companion_cleanup_resumes_after_private_move(string $boundary): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        DirectorySyncBridge::$fail = $boundary;
        try {
            self::assertSame('failed', $service->importManifest($path, true)['status']);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
        self::assertSame('imported', $service->importManifest($path)['status']);
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertArrayNotHasKey('delete_nfo_after_import', $manifests->read($path));
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
    }

    #[DataProvider('interruptedCleanupBoundaries')]
    public function test_direct_input_cleanup_resumes_from_manifest(string $boundary): void
    {
        DirectorySyncBridge::$fail = $boundary;
        try {
            [$path, $manifests] = $this->directUpload(deleteInput: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $source = dirname($path).'/incoming.nzb';
        self::assertFileDoesNotExist($source);
        self::assertArrayHasKey('delete_input_pending', $manifests->read($path));
        $result = $manifests->reconcilePending($path);
        self::assertSame(NzbUploadManifestService::STATE_NZB_IMPORTED, $result['state']);
        self::assertArrayNotHasKey('delete_input_pending', $result);
        self::assertSame($result['accepted_capture']['attempt'], $result['relinquished_attempt']);
    }

    #[DataProvider('interruptedCleanupBoundaries')]
    public function test_direct_cleanup_and_paired_nfo_preserve_new_source_generation(string $boundary): void
    {
        DirectorySyncBridge::$fail = $boundary;
        try {
            [$path, $manifests, $nfos] = $this->directUpload(deleteInput: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $source = dirname($path).'/incoming.nzb';
        self::assertFileDoesNotExist($source);
        file_put_contents($source, 'new independent source');
        self::assertSame('imported', $nfos->importManifest($path, true)['status']);
        self::assertSame('new independent source', file_get_contents($source));
        self::assertArrayNotHasKey('delete_input_pending', $manifests->read($path));
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
    }

    public function test_terminal_replay_honors_persisted_companion_deletion_intent(): void
    {
        [$path, $manifests, $service] = $this->directUpload();
        DirectorySyncBridge::$fail = 'mkdir_parent';
        try {
            self::assertSame('failed', $service->importManifest($path, true)['status']);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        // Simulate the durable terminal manifest at the pre-cleanup interruption point.
        // SQL storage and its receipt above are real; no admission/receipt is fabricated.
        $manifest = $manifests->read($path);
        $manifest['state'] = NzbUploadManifestService::STATE_NFO_IMPORTED;
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertSame('skipped', $service->importManifest($path)['status']);
        self::assertFileDoesNotExist(dirname($path).'/info.nfo');
        self::assertSame(1, DB::table('nfo_cleanup_commits')->count());
    }

    /** @return array{string, NzbUploadManifestService, NfoImportService} */
    private function directUpload(bool $priorCandidate = false, bool $deleteInput = false, string $nfoBody = 'direct companion'): array
    {
        Schema::create('releases', static function (Blueprint $table): void {
            $table->id();
            $table->string('guid');
            $table->integer('nfostatus')->default(-1);
        });
        Schema::create('release_nfos', static function (Blueprint $table): void {
            $table->integer('releases_id')->primary();
            $table->binary('nfo');
        });
        (require database_path('migrations/2026_10_15_034000_create_nfo_cleanup_commits_table.php'))->up();
        config(['release_verification.required' => false, 'nntmux.release_dedupe_enabled' => false, 'nntmux.release_dedupe_lock_store' => 'array']);
        $directory = config('release_verification.storage_path').'/direct';
        app('files')->ensureDirectoryExists($directory);
        $source = $directory.'/incoming.nzb';
        file_put_contents($source, '<nzb><file subject="fixture.bin"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">direct@example.test</segment></segments></file></nzb>');
        file_put_contents($directory.'/info.nfo', $nfoBody);
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'direct-fixture', 'incoming.nzb', 'info.nfo', $nfoBody);
        if ($priorCandidate) {
            config(['release_verification.required' => true]);
            $this->capturedFixtureImporter('old-policy')->beginImport([$source]);
            DB::table('release_verification_candidates')->update(['status' => 'metadata_deferred']);
            config(['release_verification.required' => false]);
        }
        // Stub release construction/storage only; exercise the actual public importer outcome and NFO writer.
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                DB::table('releases')->insert(['id' => 7, 'guid' => 'direct-fixture-guid']);
                $this->relId = 7;
                $this->relGuid = 'direct-fixture-guid';

                return NzbImportStatus::Inserted;
            }

            protected function storeImportedNzb(string $nzbString): array
            {
                return [true, null];
            }
        };
        $importer->beginImport([$source], delete: $deleteInput);
        $path = $directory.'/'.NzbUploadManifestService::FILENAME;
        self::assertSame('nzb_imported', $manifests->read($path)['state']);
        self::assertArrayNotHasKey('accepted_admission', $manifests->read($path));

        return [$path, $manifests, new NfoImportService(new Filesystem, $manifests, new NfoService)];
    }
}
