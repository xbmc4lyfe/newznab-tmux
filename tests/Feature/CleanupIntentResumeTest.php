<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Exceptions\NzbCustodyCollision;
use App\Services\NfoService;
use App\Services\Nzb\DirectorySyncBridge;
use App\Services\Nzb\NativeManifestIoProbe;
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

    public static function rawSourceEncodings(): array
    {
        return ['XML' => [false], 'gzip' => [true]];
    }

    #[DataProvider('rawSourceEncodings')]
    public function test_raw_verified_source_is_retained_without_generation_custody(bool $gzip): void
    {
        [$source, $mp] = $this->upload(false);
        unlink($mp);
        if ($gzip) {
            file_put_contents($source.'.gz', gzencode(file_get_contents($source)));
            unlink($source);
            $source .= '.gz';
        }
        Schema::table('releases', static fn (Blueprint $table) => $table->integer('nzbstatus')->default(1));
        $filesystem = new class($source) extends Filesystem
        {
            public int $probes = 0;

            public bool $admitted = false;

            public function __construct(private string $source) {}

            public function delete($paths)
            {
                if ($paths === $this->source) {
                    $this->probes++;
                    $lock = fopen(config('release_verification.storage_path').'/reset.lock', 'c');
                    try {
                        if (flock($lock, LOCK_EX | LOCK_NB)) {
                            $this->admitted = true;
                            DB::table('release_verification_candidates')->delete();
                            flock($lock, LOCK_UN);
                        }
                    } finally {
                        fclose($lock);
                    }
                }

                if ($paths === $this->source) {
                    file_put_contents($this->source, 'replacement immediately before raw unlink');
                }

                return parent::delete($paths);
            }
        };
        app()->instance(NzbUploadManifestService::class, new NzbUploadManifestService($filesystem));
        $this->capturedFixtureImporter('raw-retirement-reset')->beginImport([$source], delete: true,
            resultCallback: static function (array $outcome): void {
                self::assertNull($outcome['input_capture']);
                self::assertSame(NzbImportStatus::Queued, $outcome['status']);
                DB::table('releases')->insert(['id' => 7, 'guid' => 'raw-retirement-guid']);
                DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
            });
        self::assertFileExists($source, 'Raw publication cannot authorize deleting an unowned pathname generation.');
        self::assertSame(0, $filesystem->probes);
        self::assertFalse($filesystem->admitted);
        self::assertSame(1, DB::table('release_verification_candidates')->count());
    }

    public static function rawRetirementHolds(): array
    {
        return ['unpublished' => ['unpublished'], 'reset' => ['reset'], 'replacement' => ['replacement'],
            'ambient transaction' => ['ambient'], 'changed admission' => ['revision']];
    }

    #[DataProvider('rawRetirementHolds')]
    public function test_raw_queued_source_remains_when_current_publication_cannot_authorize_retirement(string $mode): void
    {
        [$source, $mp] = $this->upload(false);
        unlink($mp);
        Schema::table('releases', static fn (Blueprint $table) => $table->integer('nzbstatus')->default(1));
        $root = config('release_verification.storage_path');
        config(['release_verification.storage_path' => $root.'/verification-state']);
        try {
            $this->capturedFixtureImporter('raw-retirement-hold')->beginImport([$source], delete: true,
                resultCallback: static function (array $outcome) use ($mode, $source): void {
                    self::assertSame(NzbImportStatus::Queued, $outcome['status']);
                    if ($mode === 'unpublished') {
                        return;
                    }
                    DB::table('releases')->insert(['id' => 7, 'guid' => 'raw-hold-guid']);
                    DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
                    if ($mode === 'reset') {
                        app(VerificationResetService::class)->run(static function (): void {});
                    } elseif ($mode === 'replacement') {
                        file_put_contents($source, 'independent replacement');
                    } elseif ($mode === 'ambient') {
                        DB::beginTransaction();
                    } elseif ($mode === 'revision') {
                        DB::table('release_verification_candidates')->increment('version');
                    }
                });
            self::assertFileExists($source);
            if ($mode === 'replacement') {
                self::assertSame('independent replacement', file_get_contents($source));
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            config(['release_verification.storage_path' => $root]);
        }
    }

    public static function legacyPairedSchedules(): array
    {
        $cases = [];
        foreach (['staged', 'nzb_failed', 'nzb_pending', 'nzb_imported'] as $state) {
            foreach ([false, true] as $delete) {
                foreach ([false, true] as $deleteFailed) {
                    $cases[$state.' '.(int) $delete.(int) $deleteFailed] = [$state, $delete, $deleteFailed];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('legacyPairedSchedules')]
    public function test_legacy_pairs_require_restaging_before_new_admission(string $state, bool $delete, bool $deleteFailed): void
    {
        [$source, $mp, $uploads] = $this->upload(true);
        $legacy = $uploads->read($mp);
        $legacy['version'] = 1;
        $legacy['state'] = $state;
        unset($legacy['nfo_capture']);
        file_put_contents($mp, json_encode($legacy, JSON_THROW_ON_ERROR));
        $this->capturedFixtureImporter('legacy-paired-hold')->beginImport([$source], delete: $delete, deleteFailed: $deleteFailed);
        $result = $uploads->read($mp);
        self::assertSame(NzbUploadManifestService::STATE_METADATA_REVIEW, $result['state']);
        self::assertStringContainsString('restag', $result['last_error']);
        self::assertFileExists($source);
        self::assertFileExists(dirname($source).'/info.nfo');
        self::assertArrayNotHasKey('accepted_capture', $result);
        self::assertArrayNotHasKey('nfo_capture', $result);
        self::assertArrayNotHasKey('input_reservation', $result);
        self::assertSame(0, DB::table('release_verification_candidates')->count());
    }

    public function test_uncaptured_declared_companion_cannot_be_retired_as_a_source_only_upload(): void
    {
        [$source, $mp, $uploads] = $this->upload(true);
        $manifest = $uploads->read($mp);
        unset($manifest['nfo_capture']);
        // Reproduce the old faulty upgrade as a real admission of this capture shape.
        $manifest['nfo'] = null;
        file_put_contents($mp, json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->capturedFixtureImporter('uncaptured-companion')->beginImport([$source], delete: true);
        $manifest = $uploads->read($mp);
        $manifest['nfo'] = ['filename' => 'info.nfo'];
        file_put_contents($mp, json_encode($manifest, JSON_THROW_ON_ERROR));
        DB::table('releases')->insert(['id' => 7, 'guid' => 'uncaptured-companion-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        $uploads->reconcilePending($mp);
        self::assertFileExists($source);
        self::assertFileExists(dirname($source).'/info.nfo');
        self::assertArrayNotHasKey('relinquished_attempt', $uploads->read($mp));
    }

    public static function custodyMoveRaces(): array
    {
        return ['vacant original' => [false, ''], 'third generation at original' => [true, ''],
            'recovery sync interruption' => [false, 'recovery_parent'], 'file sync retry' => [false, 'recovery_file'],
            'occupied recovery name' => [true, 'occupied']];
    }

    #[DataProvider('custodyMoveRaces')]
    public function test_generation_swapped_at_move_is_rescued_without_overwriting_newer_input(bool $thirdGeneration, string $fault): void
    {
        [$source, $mp] = $this->upload(false);
        $filesystem = new class($source, $thirdGeneration, $fault) extends Filesystem
        {
            public function __construct(private string $source, private bool $thirdGeneration, private string $fault) {}

            public function move($path, $target)
            {
                if ($path !== $this->source) {
                    return parent::move($path, $target);
                }
                file_put_contents($path.'.new', 'displaced producer generation');
                rename($path.'.new', $path);
                $moved = parent::move($path, $target);
                if ($this->thirdGeneration) {
                    file_put_contents($path, 'third producer generation');
                }
                if ($this->fault === 'recovery_parent') {
                    DirectorySyncBridge::$fail = 'recovery_parent';
                } elseif ($this->fault === 'recovery_file') {
                    NativeManifestIoProbe::$failRecoverySyncs = 2;
                    NativeManifestIoProbe::$recoverySyncs = 0;
                } elseif ($this->fault === 'occupied') {
                    $intent = json_decode(file_get_contents(dirname($target).'/intent.json'), true);
                    file_put_contents($path.'.recovered-'.hash('sha256', $intent['receipt']['attempt']), 'unrelated recovery-name occupant');
                }

                return $moved;
            }
        };
        $uploads = new NzbUploadManifestService($filesystem);
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
        try {
            $importer->beginImport([$source], deleteFailed: true);
        } catch (NzbCustodyCollision) {
            // Explicit review is the intended outcome; never certify old retirement.
        } catch (\RuntimeException $error) {
            self::assertContains($fault, ['recovery_parent', 'recovery_file']);
            self::assertStringContainsString('durability', $error->getMessage());
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        if ($fault === 'recovery_file') {
            try {
                $uploads->reconcilePending($mp);
                self::fail('An existing recovery copy still requires a successful file sync.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('durability', $error->getMessage());
            } finally {
                NativeManifestIoProbe::$failRecoverySyncs = 0;
            }
            self::assertSame(2, NativeManifestIoProbe::$recoverySyncs);
        }
        if (in_array($fault, ['recovery_parent', 'recovery_file'], true)) {
            self::assertArrayNotHasKey('custody_collision', $uploads->read($mp));
            self::assertArrayHasKey('delete_input_pending', $uploads->read($mp));
            try {
                $uploads->reconcilePending($mp);
            } catch (NzbCustodyCollision) {
                // Resume completes rescue under the same old attempt.
            }
        }
        if ($thirdGeneration) {
            self::assertSame('third producer generation', file_get_contents($source));
        } else {
            self::assertFileDoesNotExist($source);
        }
        $result = $uploads->read($mp);
        self::assertSame(NzbUploadManifestService::STATE_METADATA_REVIEW, $result['state']);
        self::assertArrayHasKey('custody_collision', $result);
        $rescued = dirname($source).'/'.($result['custody_collision']['recovered_filename'] ?? $result['custody_collision']['private_directory'].'/payload');
        self::assertSame('displaced producer generation', file_get_contents($rescued));
        if ($fault === 'occupied') {
            $fallback = $source.'.recovered-'.hash('sha256', $result['custody_collision']['attempt']);
            self::assertSame('unrelated recovery-name occupant', file_get_contents($fallback));
        }
        self::assertArrayNotHasKey('relinquished_attempt', $result);
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
        $uploads->reconcilePending($mp);
        self::assertSame('displaced producer generation', file_get_contents($rescued));
        self::assertSame(1, count(glob(dirname($source).'/.custody-*/payload')));
        self::assertSame([], glob(dirname($source).'/.custody-*/complete.json'));
        if ($result['custody_collision']['recovered_filename'] !== null) {
            file_put_contents($rescued, 'producer later overwrite');
            $private = dirname($source).'/'.$result['custody_collision']['private_directory'].'/payload';
            self::assertSame('displaced producer generation', file_get_contents($private), 'Public recovery must not expose the retained evidence inode.');
        }
        $uploads->recordNzbOutcome($source, NzbImportStatus::Failed, null, null, inputCapture: $result['outcome_capture']);
        self::assertSame($result, $uploads->read($mp));
    }

    public function test_source_only_legacy_manifest_can_still_take_fresh_admission(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $legacy = $uploads->read($mp);
        $legacy['version'] = 1;
        file_put_contents($mp, json_encode($legacy, JSON_THROW_ON_ERROR));
        $this->capturedFixtureImporter('legacy-source-only')->beginImport([$source], delete: true);
        $result = $uploads->read($mp);
        self::assertSame(2, $result['version']);
        self::assertSame(NzbUploadManifestService::STATE_NZB_PENDING, $result['state']);
        self::assertSame(2, $result['accepted_capture']['protocol']);
        self::assertSame(1, DB::table('release_verification_candidates')->count());
        self::assertFileExists($source);
    }

    public function test_uncaptured_pair_does_not_reinterpret_a_pending_private_source(): void
    {
        [$source, $mp, $uploads] = $this->upload(false);
        $this->capturedFixtureImporter('legacy-private-hold')->beginImport([$source], delete: true);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'legacy-private-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        DirectorySyncBridge::$fail = 'rename_custody';
        try {
            $uploads->reconcilePending($mp);
        } finally {
            DirectorySyncBridge::$fail = '';
        }
        self::assertFileDoesNotExist($source);
        $private = glob(dirname($source).'/.custody-*/payload');
        self::assertCount(1, $private);
        $original = file_get_contents($private[0]);
        $manifest = $uploads->read($mp);
        $manifest['nfo'] = ['filename' => 'info.nfo'];
        file_put_contents($mp, json_encode($manifest, JSON_THROW_ON_ERROR));
        file_put_contents($source, 'replacement public NZB');
        file_put_contents(dirname($source).'/info.nfo', 'replacement public NFO');
        self::assertSame(['deferred' => true], $uploads->reserveInput($source));
        self::assertSame($manifest, $uploads->read($mp));
        self::assertSame($original, file_get_contents($private[0]));
        self::assertSame('replacement public NZB', file_get_contents($source));
        self::assertSame('replacement public NFO', file_get_contents(dirname($source).'/info.nfo'));
    }

    public function test_waiting_reconciler_rechecks_collision_after_taking_manifest_lock(): void
    {
        [$source, $mp] = $this->upload(false);
        $this->capturedFixtureImporter('waiting-collision')->beginImport([$source]);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'waiting-collision-guid']);
        DB::table('release_verification_candidates')->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now()]);
        $filesystem = new class($mp) extends Filesystem
        {
            private bool $armed = true;

            public function __construct(private string $manifestPath) {}

            public function get($path, $lock = false)
            {
                $observed = parent::get($path, $lock);
                if ($this->armed && $path === $this->manifestPath) {
                    $this->armed = false;
                    // Another writer commits the hold after this reader's first observation.
                    $held = json_decode($observed, true);
                    $held['state'] = NzbUploadManifestService::STATE_METADATA_REVIEW;
                    $held['custody_collision'] = ['attempt' => 'synthetic concurrent hold'];
                    file_put_contents($path, json_encode($held, JSON_THROW_ON_ERROR));
                }

                return $observed;
            }
        };
        $uploads = new NzbUploadManifestService($filesystem);
        self::assertSame(NzbUploadManifestService::STATE_METADATA_REVIEW, $uploads->reconcilePending($mp)['state']);
        self::assertSame(NzbUploadManifestService::STATE_METADATA_REVIEW, $uploads->read($mp)['state']);
        self::assertFileExists($source);
    }

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
