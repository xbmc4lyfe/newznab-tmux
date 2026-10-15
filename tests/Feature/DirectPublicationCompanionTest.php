<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\NfoService;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class DirectPublicationCompanionTest extends ReleaseVerificationCandidatesTest
{
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
        self::assertSame('skipped', $service->importManifest($path, true)['status']);
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
        return array_combine(['rename_custody', 'rename_parent', 'unlink_dir', 'complete_dir'], array_map(static fn (string $boundary): array => [$boundary], ['rename_custody', 'rename_parent', 'unlink_dir', 'complete_dir']));
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
    private function directUpload(): array
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
        file_put_contents($directory.'/info.nfo', 'direct companion');
        $manifests = app(NzbUploadManifestService::class);
        $manifests->create($directory, 'direct-fixture', 'incoming.nzb', 'info.nfo', 'direct companion');
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
        $importer->beginImport([$source]);
        $path = $directory.'/'.NzbUploadManifestService::FILENAME;
        self::assertSame('nzb_imported', $manifests->read($path)['state']);
        self::assertArrayNotHasKey('accepted_admission', $manifests->read($path));

        return [$path, $manifests, new NfoImportService(new Filesystem, $manifests, new NfoService)];
    }
}
