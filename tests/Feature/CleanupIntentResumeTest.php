<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\NfoService;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NfoImportService;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Releases\Verification\VerificationResetService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CapturedUploadFixture;

class CleanupIntentResumeTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public static function retirementResetBoundaries(): array
    {
        return ['before move' => ['intent_dir'], 'payload complete' => ['complete_dir'], 'manifest retirement' => ['manifest']];
    }

    public static function reconciledImportSchedules(): array
    {
        return ['source only' => [false, false], 'source after reset' => [false, true],
            'companion before storage' => [true, false], 'companion after reset' => [true, true]];
    }

    #[DataProvider('reconciledImportSchedules')]
    public function test_queued_importer_cannot_bypass_retention_after_concurrent_reconciliation(bool $companion, bool $reset): void
    {
        [$source, $mp, $uploads] = $this->upload($companion);
        $root = config('release_verification.storage_path');
        // Keep the uploader directory outside the real reset's owned verification artifacts.
        config(['release_verification.storage_path' => $root.'/verification-state']);
        try {
            $this->capturedFixtureImporter('reconciled-before-delete')->beginImport([$source], delete: true,
                resultCallback: function (array $outcome) use ($uploads, $mp, $reset): void {
                    self::assertSame(NzbImportStatus::Queued, $outcome['status']);
                    DB::table('releases')->insert(['id' => 7, 'guid' => 'reconciled-retirement-guid']);
                    DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
                    self::assertSame(NzbUploadManifestService::STATE_NZB_IMPORTED, $uploads->reconcilePending($mp)['state']);
                    if ($reset) {
                        app(VerificationResetService::class)->run(static function (): void {});
                    }
                });
            $result = $uploads->read($mp);
            if ($companion || $reset) {
                self::assertFileExists($source);
                self::assertArrayNotHasKey('relinquished_attempt', $result);
                if ($companion) {
                    self::assertFileExists(dirname($mp).'/info.nfo');
                }
            } else {
                self::assertFileDoesNotExist($source);
                self::assertSame($result['accepted_capture']['attempt'], $result['relinquished_attempt']);
            }
        } finally {
            config(['release_verification.storage_path' => $root]);
        }
    }

    #[DataProvider('retirementResetBoundaries')]
    public function test_reset_is_excluded_until_source_retirement_is_durable(string $boundary): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $this->capturedFixtureImporter('retirement-reset')->beginImport([$source], delete: true);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'retirement-reset-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        $attempts = 0;
        $admitted = false;
        $retiring = false;
        $probe = static function () use (&$attempts, &$admitted): void {
            $attempts++;
            $lock = fopen(config('release_verification.storage_path').'/reset.lock', 'c');
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    $admitted = true;
                    // Reproduce the reset's candidate removal without touching uploader files.
                    DB::table('release_verification_candidates')->delete();
                    flock($lock, LOCK_UN);
                }
            } finally {
                fclose($lock);
            }
        };
        DirectorySyncBridge::$afterSync = static function (string $directory, string $point) use ($mp, $boundary, $probe, &$retiring): void {
            if ($point === 'intent_dir') {
                $retiring = true;
            }
            if ($point === $boundary || ($boundary === 'manifest' && $retiring
                && $point === 'manifest_parent' && $directory === dirname($mp))) {
                $probe();
            }
        };
        try {
            $result = $uploads->reconcilePending($mp);
        } finally {
            DirectorySyncBridge::$afterSync = null;
        }
        self::assertGreaterThan(0, $attempts);
        self::assertFalse($admitted, 'Reset must remain excluded through durable retirement.');
        self::assertSame(1, DB::table('release_verification_candidates')->count());
        self::assertSame($result['accepted_capture']['attempt'], $result['relinquished_attempt']);
        self::assertFileDoesNotExist($source);
    }

    public static function boundaries(): array
    {
        return array_combine(['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir'], array_map(static fn ($v) => [$v], ['rename_custody', 'rename_parent', 'moved_dir', 'unlink_dir', 'complete_dir']));
    }

    private function upload(bool $companion): array
    {
        Schema::create('releases', static function (Blueprint $t): void {
            $t->id();
            $t->string('guid');
            $t->integer('nfostatus')->default(-1);
        });
        $dir = config('release_verification.storage_path').'/origin';
        app('files')->ensureDirectoryExists($dir);
        $source = $dir.'/incoming.nzb';
        file_put_contents($source, '<nzb><file subject="fixture.bin"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">intent@example.test</segment></segments></file></nzb>');
        if ($companion) {
            file_put_contents($dir.'/info.nfo', 'synthetic companion');
        }
        $uploads = app(NzbUploadManifestService::class);
        $uploads->create($dir, 'intent-origin', 'incoming.nzb', $companion ? 'info.nfo' : null, $companion ? 'synthetic companion' : null);

        return [$source, $dir.'/'.NzbUploadManifestService::FILENAME, $uploads];
    }

    public static function sourceBoundaries(): array
    {
        $cases = [];
        foreach (self::boundaries() as $name => [$boundary]) {
            foreach ([false, true] as $replacement) {
                $cases[$name.($replacement ? ' replacement' : ' absent')] = [$boundary, $replacement];
            }
        }

        return $cases;
    }

    #[DataProvider('sourceBoundaries')]
    public function test_source_only_publication_resumes_after_private_payload_disappears(string $boundary, bool $replacement): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $this->capturedFixtureImporter('intent-only')->beginImport([$source], delete: true);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'intent-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        DirectorySyncBridge::$fail = $boundary;
        try {
            $uploads->reconcilePending($mp);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        self::assertArrayHasKey('delete_nzb_after_publication', $uploads->read($mp));
        if ($replacement) {
            file_put_contents($source, 'replacement generation');
        }
        $result = $uploads->reconcilePending($mp);
        if ($replacement) {
            self::assertSame('replacement generation', file_get_contents($source));
        }
        self::assertSame(NzbUploadManifestService::STATE_NZB_IMPORTED, $result['state']);
        self::assertSame(7, $result['release_id']);
        self::assertArrayNotHasKey('delete_nzb_after_publication', $result);
        self::assertSame($result['accepted_capture']['attempt'], $result['relinquished_attempt']);
    }

    public function test_replacement_paths_cannot_replace_pending_cleanup_authority(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$fail = 'rename_custody';
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $capture = $uploads->read($mp)['delete_input_pending'];
        file_put_contents($source, 'replacement generation');
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
        self::assertSame($capture, $uploads->read($mp)['delete_input_pending']);
        $result = $uploads->reconcilePending($mp);
        self::assertSame('replacement generation', file_get_contents($source));
        self::assertArrayNotHasKey('delete_input_pending', $result);
        self::assertSame(NzbUploadManifestService::STATE_NZB_FAILED, $result['state']);
    }

    public function test_intent_without_owned_move_checkpoint_cannot_retire_replacement(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$fail = 'unlink_dir';
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        $markers = glob(dirname($source).'/.custody-*/moved.json');
        self::assertCount(1, $markers);
        unlink($markers[0]);
        file_put_contents($source, 'replacement without relocation proof');
        $result = $uploads->reconcilePending($mp);
        self::assertArrayHasKey('delete_input_pending', $result);
        self::assertArrayNotHasKey('relinquished_attempt', $result);
        self::assertSame('replacement without relocation proof', file_get_contents($source));
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
    }

    public static function gcBoundaries(): array
    {
        return ['removed files' => ['custody_gc_files'], 'removed directory' => ['custody_gc_parent']];
    }

    #[DataProvider('gcBoundaries')]
    public function test_gc_sync_failure_keeps_retry_authority_without_retiring_replacement(string $boundary): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$fail = $boundary;
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        self::assertArrayHasKey('custody_gc', $uploads->read($mp));
        file_put_contents($source, 'new source while GC waits');
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
        $result = $uploads->reconcilePending($mp);
        self::assertArrayNotHasKey('custody_gc', $result);
        self::assertSame([], glob(dirname($source).'/.custody-*'));
        self::assertSame('new source while GC waits', file_get_contents($source));
    }

    public function test_gc_requires_durable_manifest_authority_before_removing_journals(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        DirectorySyncBridge::$afterSync = static function (string $directory, string $boundary): void {
            if ($boundary === 'complete_dir') {
                DirectorySyncBridge::$fail = 'manifest_parent';
            }
        };
        try {
            try {
                $importer->beginImport([$source], deleteFailed: true);
                self::fail('Expected uncertain final manifest durability');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('durability', $error->getMessage());
            }
            self::assertArrayHasKey('custody_gc', $uploads->read($mp));
            $journals = glob(dirname($source).'/.custody-*/*.json');
            self::assertCount(3, $journals);
            try {
                $uploads->reconcilePending($mp);
                self::fail('GC must not proceed without durable manifest authority');
            } catch (\RuntimeException) {
                foreach ($journals as $journal) {
                    self::assertFileExists($journal);
                }
            }
        } finally {
            DirectorySyncBridge::$fail = '';
            DirectorySyncBridge::$afterSync = null;
        }
        $result = $uploads->reconcilePending($mp);
        self::assertArrayNotHasKey('custody_gc', $result);
        self::assertSame([], glob(dirname($source).'/.custody-*'));
    }

    public function test_completed_source_journal_is_reclaimed_and_partial_gc_retries(): void
    {
        [$source, $mp] = $this->upload(false);
        $fs = new class extends Filesystem
        {
            public bool $failGc = true;

            public function delete($paths)
            {
                $deleted = parent::delete($paths);
                if ($this->failGc && is_string($paths) && basename($paths) === 'intent.json') {
                    $this->failGc = false;

                    return false;
                }

                return $deleted;
            }
        };
        $uploads = new NzbUploadManifestService($fs);
        app()->instance(NzbUploadManifestService::class, $uploads);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        $importer->beginImport([$source], deleteFailed: true);
        self::assertFileDoesNotExist($source);
        self::assertArrayHasKey('custody_gc', $uploads->read($mp));
        self::assertCount(1, glob(dirname($source).'/.custody-*'));
        file_put_contents($source, 'independent replacement');
        $m = $uploads->reconcilePending($mp);
        self::assertArrayNotHasKey('custody_gc', $m);
        self::assertSame([], glob(dirname($source).'/.custody-*'));
        self::assertSame('independent replacement', file_get_contents($source));
    }

    #[DataProvider('sourceBoundaries')]
    public function test_failed_input_and_companion_resume_from_manifest_without_original_paths(string $boundary, bool $replacement): void
    {
        [$source, $mp, $uploads] = $this->upload(true);
        $importer = new class(['Browser' => true]) extends NzbImportService
        {
            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return NzbImportStatus::Failed;
            }
        };
        $nfos = new NfoImportService(new Filesystem, $uploads, new NfoService);
        DirectorySyncBridge::$fail = $boundary;
        try {
            $importer->beginImport([$source], deleteFailed: true);
            $nfos->importManifest($mp, deleteFailed: true);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        self::assertFileDoesNotExist(dirname($source).'/info.nfo');
        self::assertArrayHasKey('delete_input_pending', $uploads->read($mp));
        self::assertArrayHasKey('delete_terminal_nfo', $uploads->read($mp));
        if ($replacement) {
            file_put_contents($source, 'replacement NZB');
            file_put_contents(dirname($source).'/info.nfo', 'replacement NFO');
        }
        $result = $uploads->reconcilePending($mp);
        self::assertSame(NzbUploadManifestService::STATE_NZB_FAILED, $result['state']);
        self::assertArrayNotHasKey('delete_input_pending', $result);
        self::assertArrayNotHasKey('delete_terminal_nfo', $result);
        self::assertSame($result['outcome_capture']['attempt'], $result['relinquished_attempt']);
        self::assertSame(0, DB::table('releases')->count());
        if ($replacement) {
            self::assertSame('replacement NZB', file_get_contents($source));
            self::assertSame('replacement NFO', file_get_contents(dirname($source).'/info.nfo'));
            self::assertArrayNotHasKey('deferred', $uploads->reserveInput($source));
        }
    }
}
