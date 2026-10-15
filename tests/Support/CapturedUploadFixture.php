<?php

namespace Tests\Support;

use App\Enums\NzbImportStatus;
use App\Services\Nzb\NzbImportService;
use App\Services\Nzb\NzbUploadManifestService;
use Illuminate\Support\Facades\DB;

trait CapturedUploadFixture
{
    /** @var array<string, array<string, mixed>> */
    private array $fixtureIssuedCaptures = [];

    private function recordCapturedOutcome(NzbUploadManifestService $manifests, string $path, NzbImportStatus $status, ?int $releaseId, ?string $releaseGuid, ?string $error = null, ?string $candidateIdentity = null): void
    {
        $mp = dirname($path).'/'.NzbUploadManifestService::FILENAME;
        $m = $manifests->read($mp);
        $capture = $this->fixtureIssuedCaptures[$path] ?? null;
        if ($capture === null && (isset($m['input_capture']) || ! is_file($path))) {
            $manifests->recordNzbOutcome($path, $status, $releaseId, $releaseGuid, $error, $candidateIdentity);

            return;
        }
        if ($capture === null && $status === NzbImportStatus::Queued && preg_match('/^[a-f0-9]{40}$/D', $candidateIdentity ?? '') === 1) {
            $old = DB::table('release_verification_candidates')->where('identity', $candidateIdentity)->first();
            if ($old !== null) {
                DB::table('release_verification_candidates')->where('identity', $candidateIdentity)->delete();
            }
            $fingerprint = $this->fixtureFingerprint($candidateIdentity);
            $service = $this->capturedFixtureImporter($fingerprint);
            $reports = [];
            $service->beginImport([$path], resultCallback: static function ($r) use (&$reports) {
                $reports[] = $r;
            });
            $result = $reports[0] ?? null;
            if ($result === null || $result['status'] !== NzbImportStatus::Queued || $result['candidate_identity'] !== $candidateIdentity) {
                throw new \RuntimeException('Fixture real admission did not queue expected identity');
            }
            $capture = $result['input_capture'];
            $this->fixtureIssuedCaptures[$path] = $capture;
            if ($old === null) {
                DB::table('release_verification_candidates')->where('identity', $candidateIdentity)->delete();
            } else {
                DB::table('release_verification_candidates')->where('identity', $candidateIdentity)->update(['status' => $old->status, 'release_id' => $old->release_id, 'verified_at' => $old->verified_at]);
            }
        } elseif ($capture === null && in_array($status, [NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred], true)) {
            $service = $this->capturedFixtureImporter('advisory-fixture');
            if ($status === NzbImportStatus::MetadataIgnored) {
                $seedDirectory = dirname($path).'/.advisory-seed';
                mkdir($seedDirectory, 0700);
                $seedPath = $seedDirectory.'/seed.nzb';
                copy($path, $seedPath);
                $service->beginImport([$seedPath]);
                DB::table('release_verification_candidates')->where('identity', sha1('import:advisory-fixture'))->update(['status' => 'metadata_review']);
                $service->beginImport([$path]); // Actual native enqueue rejects the held candidate.
            } else {
                $budget = config('release_verification.pending_bytes');
                config(['release_verification.pending_bytes' => 0]);
                try {
                    $service->beginImport([$path]);
                } finally {
                    config(['release_verification.pending_bytes' => $budget]);
                }
            }

            return;
        }
        $manifests->recordNzbOutcome($path, $status, $releaseId, $releaseGuid, $error, $candidateIdentity, inputCapture: $capture);
    }

    private function capturedFixtureImporter(string $fingerprint): NzbImportService
    {
        return new class($fingerprint) extends NzbImportService
        {
            public function __construct(private string $fingerprint)
            {
                parent::__construct(['Browser' => true]);
            }

            protected function getAllGroups(): bool
            {
                return true;
            }

            protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
            {
                return $this->insertNZB(['verificationXml' => $nzbXML->asXML(), 'groups_id' => 1, 'useFName' => 'Fixture', 'subject' => 'Fixture', 'from' => 'fixture', 'postDate' => '2026-10-13 12:00:00', 'articleFingerprint' => $this->fingerprint]);
            }
        };
    }

    private function fixtureFingerprint(string $identity): string
    {
        $keys = ['pending', 'unknown', 'verifying', 'complete', 'outer-missing', 'outer-published', 'held-metadata'];
        foreach (['NzbQueuedUploadLifecycleTest', 'ExistingImportPasswordEnrichmentTest'] as $class) {
            $source = file_get_contents(__DIR__.'/../Feature/'.$class.'.php');
            preg_match_all("/sha1\\('import:([^']+)'\\)/", $source, $matches);
            $keys = array_merge($keys, $matches[1]);
        }
        foreach ($keys as $key) {
            if (sha1('import:'.$key) === $identity) {
                return $key;
            }
        }
        throw new \RuntimeException('Fixture fingerprint preimage not specified');
    }

    private function relinquishCapturedSource(NzbUploadManifestService $manifests, string $path): void
    {
        $m = $manifests->read(dirname($path).'/'.NzbUploadManifestService::FILENAME);
        if (! $manifests->deleteCapturedInput($path, $m['accepted_capture'] ?? null)) {
            throw new \RuntimeException('Fixture source was not relinquished by receipt owner');
        }
    }
}
