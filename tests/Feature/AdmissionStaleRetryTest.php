<?php

namespace Tests\Feature;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbUploadManifestService;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\ManifestStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CapturedUploadFixture;

class AdmissionStaleRetryTest extends ReleaseVerificationCandidatesTest
{
    use CapturedUploadFixture;

    public function test_delete_requested_queued_input_remains_available_after_admission_revision_changes(): void
    {
        [$svc, $path, $candidate] = $this->staleAdmission(true);
        $reports = [];
        $this->capturedFixtureImporter('compatibility-owned')->beginImport([$path], delete: true, resultCallback: static function (array $report) use (&$reports): void {
            $reports[] = $report;
        });
        self::assertSame('queued', $reports[0]['status']->value);
        self::assertSame((int) $candidate->version, $reports[0]['candidate_admission']['version']);
        self::assertSame('nzb_imported', $svc->reconcilePending(dirname($path).'/'.NzbUploadManifestService::FILENAME)['state']);
        self::assertFileExists($path);
        self::assertFileExists(dirname($path).'/info.nfo');
    }

    public function test_owned_passwordless_predecessor_automatically_retries_stale_admission(): void
    {
        [$svc, $path, $b] = $this->staleAdmission();
        $manifestPath = dirname($path).'/'.NzbUploadManifestService::FILENAME;
        $oldCapture = $svc->read($manifestPath)['accepted_capture'];
        $reports = [];
        $this->capturedFixtureImporter('compatibility-owned')->beginImport([$path], resultCallback: static function ($r) use (&$reports) {
            $reports[] = $r;
        });
        self::assertSame('queued', $reports[0]['status']->value);
        self::assertSame((int) $b->version, $reports[0]['candidate_admission']['version']);
        $latest = $svc->read($manifestPath);
        self::assertArrayNotHasKey('stale_admission_retry', $latest);
        $svc->recordNzbOutcome($path, NzbImportStatus::MetadataIgnored, null, null, inputCapture: $oldCapture);
        self::assertSame($latest, $svc->read($manifestPath));
        $resolved = $svc->reconcilePending($manifestPath);
        self::assertSame('nzb_imported', $resolved['state']);
        self::assertArrayNotHasKey('stale_admission_retry', $resolved);
    }

    public function test_authoritative_ignored_result_ends_stale_retry_and_old_callbacks_cannot_restore_it(): void
    {
        [$svc, $path] = $this->staleAdmission();
        $old = $svc->finalizeInput($path, file_get_contents($path), $svc->reserveInput($path));
        $current = $svc->finalizeInput($path, file_get_contents($path), $svc->reserveInput($path));
        $mp = dirname($path).'/'.NzbUploadManifestService::FILENAME;
        $before = $svc->read($mp);
        $svc->recordNzbOutcome($path, NzbImportStatus::MetadataIgnored, null, null, inputCapture: $old);
        self::assertSame($before, $svc->read($mp));
        $svc->recordNzbOutcome($path, NzbImportStatus::MetadataIgnored, null, null, inputCapture: $current);
        self::assertSame('metadata_review', $svc->read($mp)['state']);
        self::assertArrayNotHasKey('stale_admission_retry', $svc->read($mp));
        self::assertFalse($svc->shouldImportNzb($path));
        $svc->recordNzbOutcome($path, NzbImportStatus::MetadataDeferred, null, null, inputCapture: $old);
        self::assertFalse($svc->shouldImportNzb($path));
        self::assertFileExists($path);
        self::assertFileExists(dirname($path).'/info.nfo');
    }

    public function test_authoritative_deferred_result_clears_stale_reason_and_remains_retryable(): void
    {
        [$svc, $path] = $this->staleAdmission();
        $capture = $svc->finalizeInput($path, file_get_contents($path), $svc->reserveInput($path));
        $svc->recordNzbOutcome($path, NzbImportStatus::MetadataDeferred, null, null, inputCapture: $capture);
        $m = $svc->read(dirname($path).'/'.NzbUploadManifestService::FILENAME);
        self::assertSame('metadata_deferred', $m['state']);
        self::assertArrayNotHasKey('stale_admission_retry', $m);
        self::assertTrue($svc->shouldImportNzb($path));
    }

    /** @return array{NzbUploadManifestService, string, object} */
    private function staleAdmission(bool $delete = false): array
    {
        Schema::create('releases', function (Blueprint $t) {
            $t->id();
            $t->string('guid');
            $t->integer('nzbstatus');
        });
        $dir = config('release_verification.storage_path').'/A';
        app('files')->ensureDirectoryExists($dir);
        $path = $dir.'/incoming.nzb';
        file_put_contents($path, '<nzb><file poster="fixture" date="1" subject="fixture.bin (1/1)"><groups><group>alt.test</group></groups><segments><segment bytes="5" number="1">compatibility@example.test</segment></segments></file></nzb>');
        file_put_contents($dir.'/info.nfo', 'fixture companion');
        $svc = app(NzbUploadManifestService::class);
        $svc->create($dir, 'compat-A', 'incoming.nzb', 'info.nfo', 'fixture companion');
        $reports = [];
        $this->capturedFixtureImporter('compatibility-owned')->beginImport([$path], delete: $delete, resultCallback: static function ($r) use (&$reports) {
            $reports[] = $r;
        });
        self::assertSame('queued', $reports[0]['status']->value);
        $repo = app(CandidateRepository::class);
        $a = DB::table('release_verification_candidates')->sole();
        $m = app(ManifestStore::class)->read($a->manifest_path);
        $m['passwords'] = ['compatible assertion'];
        self::assertNotNull($repo->enqueueWithAdmission($m));
        $b = DB::table('release_verification_candidates')->sole();
        self::assertGreaterThan($a->version, $b->version);
        DB::table('releases')->insert(['id' => 7, 'guid' => 'fixture-guid', 'nzbstatus' => 1]);
        DB::table('release_verification_candidates')->where('id', $b->id)->update(['status' => 'complete', 'release_id' => 7, 'verified_at' => now(), 'manifest_path' => '', 'manifest_bytes' => 0]);
        $resolved = $svc->reconcilePending($dir.'/'.NzbUploadManifestService::FILENAME);
        self::assertSame('metadata_review', $resolved['state']);
        self::assertNull($resolved['release_id']);
        self::assertFileExists($path);
        self::assertTrue($svc->shouldImportNzb($path));

        return [$svc, $path, $b];
    }
}
