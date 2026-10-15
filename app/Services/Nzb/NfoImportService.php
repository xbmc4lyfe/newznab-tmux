<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Exceptions\NfoPayloadRejected;
use App\Models\Release;
use App\Services\NfoService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class NfoImportService
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly NzbUploadManifestService $manifests,
        private readonly NfoService $nfoService,
    ) {}

    /**
     * @return array{status:'imported'|'skipped'|'failed',message:string}
     */
    public function importManifest(string $manifestPath, bool $delete = false, bool $deleteFailed = false): array
    {
        $nfoPath = null;

        try {
            $prior = $this->manifests->read($manifestPath);
            if ($this->manifests->resumeCommittedCompanion($manifestPath, $delete)) {
                return ['status' => $prior['state'] === NzbUploadManifestService::STATE_NFO_IMPORTED ? 'skipped' : 'imported',
                    'message' => 'NFO storage was already committed; cleanup retried'];
            }
            $manifest = $this->manifests->reconcilePending($manifestPath);
            $state = $manifest['state'];

            if ($state === NzbUploadManifestService::STATE_NFO_IMPORTED) {
                $this->manifests->cleanupImportedSource($manifestPath);

                return ['status' => 'skipped', 'message' => 'NFO was already imported'];
            }

            if (in_array($state, [
                NzbUploadManifestService::STATE_METADATA_REVIEW,
                NzbUploadManifestService::STATE_METADATA_DEFERRED,
            ], true)) {
                return ['status' => 'skipped', 'message' => "NFO is held for metadata ({$state})"];
            }

            if (in_array($state, [
                NzbUploadManifestService::STATE_NZB_DUPLICATE,
                NzbUploadManifestService::STATE_NZB_FAILED,
                NzbUploadManifestService::STATE_NZB_REJECTED,
            ], true)) {
                if (($deleteFailed || isset($manifest['delete_terminal_nfo'])) && is_array($manifest['nfo'] ?? null)) {
                    try {
                        $this->manifests->deleteTerminalCompanion($manifestPath);
                    } catch (Throwable) {
                        // The terminal NZB outcome remains authoritative even if its NFO is already gone.
                    }
                }

                return ['status' => 'skipped', 'message' => "NFO has no imported NZB release ({$state})"];
            }

            if (! in_array($state, [
                NzbUploadManifestService::STATE_NZB_IMPORTED,
                NzbUploadManifestService::STATE_NFO_FAILED,
            ], true)) {
                return ['status' => 'skipped', 'message' => "NFO is waiting for NZB import ({$state})"];
            }

            if (! is_array($manifest['nfo'] ?? null)) {
                return ['status' => 'skipped', 'message' => 'Upload has no paired NFO'];
            }

            if (($manifest['nfo_failed_relinquished_attempt'] ?? null) === ($manifest['nfo_capture']['attempt'] ?? false)) {
                return ['status' => 'skipped', 'message' => 'Captured failed companion already removed'];
            }

            if (! $this->filesystem->isFile(dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nfo']['filename'])) {
                throw new RuntimeException('Companion input is missing');
            }

            $releaseId = $manifest['release_id'] ?? null;
            $releaseGuid = $manifest['release_guid'] ?? null;
            if (! is_int($releaseId) || $releaseId <= 0 || ! is_string($releaseGuid) || $releaseGuid === '') {
                throw new RuntimeException('Upload manifest has no valid release identity');
            }

            $releaseExists = Release::query()
                ->whereKey($releaseId)
                ->where('guid', $releaseGuid)
                ->exists();
            if (! $releaseExists) {
                throw new RuntimeException('The release recorded by the upload manifest no longer exists');
            }

            $this->manifests->consumeCompanion($manifestPath,
                $this->nfoService, $delete);

            Log::channel('nzb_upload')->info('Paired NFO imported', [
                'upload_id' => $manifest['upload_id'],
                'release_id' => $releaseId,
                'release_guid' => $releaseGuid,
                'filename' => $manifest['nfo']['filename'],
            ]);

            return ['status' => 'imported', 'message' => "Imported {$manifest['nfo']['filename']}"];
        } catch (Throwable $exception) {
            if ($deleteFailed && $exception instanceof NfoPayloadRejected) {
                try {
                    $this->manifests->deleteTerminalCompanion($manifestPath);
                } catch (Throwable) {
                    // Failed cleanup keeps its exact pending operation for reconciliation.
                }
            }
            Log::channel('nzb_upload')->warning('Paired NFO import failed', [
                'manifest' => $manifestPath,
                'error' => $exception->getMessage(),
            ]);

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }
    }
}
