<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\NzbImportStatus;
use App\Exceptions\NfoPayloadRejected;
use App\Exceptions\NzbCustodyCollision;
use App\Models\Release;
use App\Models\ReleaseNfo;
use App\Services\NfoService;
use App\Services\Releases\Verification\VerificationLedger;
use App\Services\Releases\Verification\VerificationResetService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SplFileInfo;

final class NzbUploadManifestService
{
    public const FILENAME = 'nntmux-upload.json';

    public const STATE_STAGED = 'staged';

    public const STATE_NZB_IMPORTED = 'nzb_imported';

    public const STATE_NZB_DUPLICATE = 'nzb_duplicate';

    public const STATE_NZB_FAILED = 'nzb_failed';

    public const STATE_NZB_PENDING = 'nzb_pending';

    public const STATE_METADATA_REVIEW = 'metadata_review';

    public const STATE_METADATA_DEFERRED = 'metadata_deferred';

    public const STATE_NZB_REJECTED = 'nzb_rejected';

    public const STATE_NFO_IMPORTED = 'nfo_imported';

    public const STATE_NFO_FAILED = 'nfo_failed';

    public function __construct(private readonly Filesystem $filesystem) {}

    /**
     * @return array<string, mixed>
     */
    public function create(string $directory, string $uploadId, string $nzbFilename, ?string $nfoFilename, ?string $nfoContent = null): array
    {
        $timestamp = now()->toIso8601String();
        $manifest = [
            'version' => 2,
            'upload_id' => $uploadId,
            'state' => self::STATE_STAGED,
            'nzb' => ['filename' => $nzbFilename],
            'nfo' => $nfoFilename === null ? null : ['filename' => $nfoFilename],
            'release_id' => null,
            'release_guid' => null,
            'candidate_identity' => null,
            'last_error' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if ($nfoFilename !== null && $nfoContent !== null) {
            $path = $directory.DIRECTORY_SEPARATOR.$nfoFilename;
            $before = $this->sourceIdentity($path);
            if ($before === null || ! hash_equals(hash('sha256', $nfoContent), $this->currentFileDigest($path))
                || $before !== $this->sourceIdentity($path)) {
                throw new RuntimeException('Companion staging custody does not match provided bytes');
            }
            $manifest['nfo_capture'] = ['protocol' => 1, 'attempt' => bin2hex(random_bytes(16)), 'upload_id' => $uploadId, 'filename' => $nfoFilename,
                'digest' => hash('sha256', $nfoContent), 'bytes' => strlen($nfoContent), 'source_identity' => $before];
        }

        $this->write($directory.DIRECTORY_SEPARATOR.self::FILENAME, $manifest);

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     *
     * @phpstan-impure
     */
    public function read(string $manifestPath): array
    {
        if (! $this->filesystem->isFile($manifestPath)) {
            throw new RuntimeException("Upload manifest does not exist: {$manifestPath}");
        }

        $contents = $this->filesystem->get($manifestPath);
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException("Invalid upload manifest JSON: {$manifestPath}", previous: $exception);
        }

        if (! is_array($manifest)
            || ! in_array($manifest['version'] ?? null, [1, 2], true)
            || ! is_string($manifest['upload_id'] ?? null)
            || ! is_string($manifest['state'] ?? null)
            || ! is_array($manifest['nzb'] ?? null)
            || ! is_string($manifest['nzb']['filename'] ?? null)) {
            throw new RuntimeException("Invalid upload manifest structure: {$manifestPath}");
        }

        $this->assertSafeFilename($manifest['nzb']['filename'], 'nzb');
        if (($manifest['nfo'] ?? null) !== null) {
            if (! is_array($manifest['nfo']) || ! is_string($manifest['nfo']['filename'] ?? null)) {
                throw new RuntimeException("Invalid NFO manifest entry: {$manifestPath}");
            }
            $this->assertSafeFilename($manifest['nfo']['filename'], 'nfo');
        }

        if ($manifest['version'] === 1 || (isset($manifest['accepted_capture']) && ($manifest['accepted_capture']['protocol'] ?? null) !== 2)) {
            unset($manifest['accepted_capture'], $manifest['input_capture'], $manifest['input_reservation'], $manifest['relinquished_attempt'], $manifest['nfo_capture'], $manifest['outcome_capture'], $manifest['outcome_status']);
        }

        return $manifest;
    }

    /**
     * @return list<string>
     */
    public function findManifests(string $folder): array
    {
        if (! $this->filesystem->isDirectory($folder)) {
            return [];
        }

        return array_values(array_map(
            static fn (SplFileInfo $file): string => $file->getPathname(),
            array_filter(
                $this->filesystem->allFiles($folder),
                static fn (SplFileInfo $file): bool => $file->getFilename() === self::FILENAME
            )
        ));
    }

    /** Current-path validation only; captured parser bytes remain authoritative. */
    private function currentFileDigest(string $path): string
    {
        // Preserve custom Filesystem get() seams used by injected failures/races.
        if (get_class($this->filesystem) !== Filesystem::class) {
            return hash('sha256', $this->filesystem->get($path));
        }
        if (! $this->filesystem->isFile($path)) {
            throw new FileNotFoundException("File does not exist at path {$path}.");
        }
        $digest = $this->filesystem->hash($path, 'sha256');
        if (! is_string($digest)) {
            throw new RuntimeException('Unable to read current payload digest');
        }

        return $digest;
    }

    private function sourceIdentity(string $path): ?string
    {
        clearstatcache(true, $path);
        $s = @lstat($path);

        return is_array($s) && ($s['mode'] & 0170000) === 0100000
            ? json_encode(array_intersect_key($s, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime'])), JSON_THROW_ON_ERROR) : null;
    }

    /** @return array<string, mixed>|null */
    public function reserveInput(string $path): ?array
    {
        $mp = dirname($path).DIRECTORY_SEPARATOR.self::FILENAME;
        if (! $this->filesystem->isFile($mp)) {
            return null;
        }

        return $this->withManifestLock($mp, function () use ($mp, $path): array {
            $m = $this->read($mp);
            if (isset($m['custody_collision'])) {
                return ['deferred' => true];
            }
            if ($m['nzb']['filename'] !== basename($path)) {
                throw new RuntimeException('Input filename mismatch');
            }
            if (isset($m['delete_input_pending']) || isset($m['delete_terminal_nfo']) || ! empty($m['custody_gc'])) {
                return ['deferred' => true];
            }
            if (in_array($m['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
                && (isset($m['delete_nzb_after_nfo']) || isset($m['delete_nzb_after_publication']) || isset($m['delete_nfo_after_import']))) {
                // Do not replace the captured operation while its owned cleanup is pending.
                // The ordinary reconciliation/companion loop retries that operation first.
                return ['deferred' => true];
            }
            if (is_array($m['nfo'] ?? null) && ! is_array($m['nfo_capture'] ?? null)) {
                // Historical identifiers cannot establish which companion bytes belong
                // to a fresh admission. Restage both inputs through the normal v2 path.
                $m['legacy_provenance'] ??= array_intersect_key($m, array_flip(['version', 'state', 'candidate_identity', 'release_id', 'release_guid']));
                $m['state'] = self::STATE_METADATA_REVIEW;
                $m['last_error'] = 'Uncaptured paired companion; restaging both inputs is required.';
                $this->write($mp, $m);

                return ['deferred' => true];
            }
            if ($m['version'] === 1) {
                $m['legacy_provenance'] ??= array_intersect_key($m, array_flip(['version', 'state', 'candidate_identity', 'release_id', 'release_guid']));
                $m['version'] = 2;
            }
            $accepted = $m['accepted_capture'] ?? null;
            if (is_array($accepted) && ($accepted['protocol'] ?? null) === 2
                && in_array($m['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
                && $this->sourceIdentity($path) === ($accepted['source_identity'] ?? null)
                && ($accepted['companion_attempt'] ?? null) === ($m['nfo_capture']['attempt'] ?? null)) {
                // A replay of the same owned generation must not orphan its accepted companion.
                $reservation = $accepted;
                unset($reservation['digest']);
                $m['input_reservation'] = $reservation;
                $m['input_capture'] = $accepted;
                $this->write($mp, $m);

                return $reservation;
            }
            $r = ['protocol' => 2, 'attempt' => bin2hex(random_bytes(16)), 'upload_id' => $m['upload_id'], 'filename' => basename($path), 'source_identity' => $this->sourceIdentity($path), 'companion_attempt' => $m['nfo_capture']['attempt'] ?? null];
            $m['input_reservation'] = $r;
            $this->write($mp, $m);

            return $r;
        });
    }

    /**
     * @param  array<string, mixed>|null  $reservation
     * @return array<string, mixed>|null
     */
    public function finalizeInput(string $path, string $xml, ?array $reservation): ?array
    {
        if ($reservation === null) {
            return null;
        }
        $mp = dirname($path).DIRECTORY_SEPARATOR.self::FILENAME;

        return $this->withManifestLock($mp, function () use ($mp, $path, $xml, $reservation): ?array {
            $m = $this->read($mp);
            if (isset($m['custody_collision'])) {
                return null;
            }
            if (($m['input_reservation'] ?? null) !== $reservation || $reservation['source_identity'] === null
                || $this->sourceIdentity($path) !== $reservation['source_identity']) {
                return null;
            }
            // Exact parser bytes establish this attempt's digest; current bytes only invalidate it.
            if (! hash_equals(hash('sha256', $xml), $this->currentFileDigest($path))
                || $this->sourceIdentity($path) !== $reservation['source_identity']) {
                return null;
            }
            $r = $reservation + ['digest' => hash('sha256', $xml)];
            $m['input_capture'] = $r;
            $this->write($mp, $m);

            return $r;
        });
    }

    /** @param array<string, mixed>|null $capture */
    public function deleteCapturedInput(string $path, ?array $capture, bool $retainForCompanion = false): bool
    {
        $mp = dirname($path).DIRECTORY_SEPARATOR.self::FILENAME;
        if (! $this->filesystem->isFile($mp)) {
            return ! $retainForCompanion && $capture === null && $this->filesystem->delete($path);
        }

        $deleted = $this->withManifestLock($mp, function () use ($mp, $path, $capture, $retainForCompanion): bool {
            $m = $this->read($mp);
            if (isset($m['custody_collision'])) {
                return false;
            }
            if ($capture === null || ($m['input_reservation']['attempt'] ?? null) !== $capture['attempt']
                || (($m['accepted_capture'] ?? null) !== $capture && (($m['outcome_capture'] ?? null) !== $capture
                    || ! in_array($m['outcome_status'] ?? null, ['failed', 'blacklisted', 'nogroup', 'duplicate'], true)))
                || basename($path) !== $m['nzb']['filename']
                || (($m['delete_input_pending'] ?? null) !== $capture && ! $this->captureMatches($path, $capture))) {
                return false;
            }

            if ($retainForCompanion) {
                // Metadata enrichment may change admission revision before publication.
                // Reconciliation may already have advanced this upload. Always retire
                // queued captures through the fenced publication or companion path.
                $field = is_array($m['nfo'] ?? null) || is_array($m['nfo_capture'] ?? null) ? 'delete_nzb_after_nfo' : 'delete_nzb_after_publication';
                $m[$field] = array_intersect_key($capture, array_flip(['source_identity', 'digest']));
                $this->write($mp, $m);

                return false;
            }

            $m['delete_input_pending'] = $capture;
            $this->write($mp, $m);
            if (! $this->quarantineDelete($path, $capture)) {
                return false;
            }
            $m['relinquished_attempt'] = $capture['attempt'];
            unset($m['delete_input_pending']);
            $this->rememberCustodyGc($m, $path, $capture);
            $this->write($mp, $m);
            $this->drainCustodyGc($mp, $m);

            return true;
        });
        if (! $deleted && $retainForCompanion && $capture !== null) {
            $this->reconcilePending($mp);
            $deleted = ($this->read($mp)['relinquished_attempt'] ?? null) === ($capture['attempt'] ?? null);
        }

        return $deleted;
    }

    /** @param array<string, mixed> $manifest */
    private function acceptedInputValid(string $manifestPath, array $manifest): bool
    {
        $accepted = $manifest['accepted_capture'] ?? null;
        if ($accepted === null) {
            return false;
        }
        if (is_string($manifest['candidate_identity'] ?? null)) {
            $a = $manifest['accepted_admission'] ?? null;
            if (! is_array($a) || ! DB::table('release_verification_candidates')
                ->where('id', $a['candidate_id'] ?? null)->where('identity', $manifest['candidate_identity'])
                ->where('version', $a['version'] ?? null)->where('manifest_hash', $a['manifest_hash'] ?? null)->exists()) {
                return false;
            }
        }
        $source = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nzb']['filename'];
        // Custody belongs to the captured generation, independently of a new original path.
        if (($manifest['relinquished_attempt'] ?? null) === $accepted['attempt']
            || $this->capturedCustodyPayload($source, $accepted) !== null) {
            return true;
        }
        $expected = ['protocol' => 3, 'source' => basename($source), 'receipt' => $accepted, 'commit_binding' => null];
        $directory = $this->custodyDirectory($source, $accepted);
        if (isset($manifest['delete_nzb_after_publication'])
            && $this->readCustodyIntent($source, $accepted) === $expected
            && $this->custodyMarkerMatches($directory.'/moved.json', $expected)
            && ! file_exists($directory.'/payload') && ! is_link($directory.'/payload')) {
            return true;
        }

        return $this->sourceIdentity($source) === $accepted['source_identity']
            && hash_equals($accepted['digest'], $this->currentFileDigest($source))
            && $this->sourceIdentity($source) === $accepted['source_identity'];
    }

    /**
     * @param  array<string, mixed>|null  $inputCapture
     * @param  array{candidate_id: int, identity: string, version: int, manifest_hash: string}|null  $candidateAdmission
     */
    public function recordNzbOutcome(
        string $nzbPath,
        NzbImportStatus $status,
        ?int $releaseId,
        ?string $releaseGuid,
        ?string $error = null,
        ?string $candidateIdentity = null,
        ?array $inputCapture = null,
        ?array $candidateAdmission = null,
    ): void {
        $manifestPath = dirname($nzbPath).DIRECTORY_SEPARATOR.self::FILENAME;
        if (! $this->filesystem->isFile($manifestPath)) {
            return;
        }

        $this->withManifestLock($manifestPath, function () use ($manifestPath, $nzbPath, $status, $releaseId, $releaseGuid, $error, $candidateIdentity, $inputCapture, $candidateAdmission): void {
            $manifest = $this->read($manifestPath);
            if (isset($manifest['custody_collision'])) {
                return;
            }
            if (! hash_equals($manifest['nzb']['filename'], basename($nzbPath))) {
                throw new RuntimeException("NZB path does not match its upload manifest: {$nzbPath}");
            }

            if ($status === NzbImportStatus::Queued && ($candidateIdentity === null || preg_match('/\A[0-9a-f]{40}\z/', $candidateIdentity) !== 1)) {
                throw new RuntimeException('Queued upload has no valid verification candidate identity.');
            }
            if ($manifest['version'] !== 2 || $inputCapture === null || ($inputCapture['protocol'] ?? null) !== 2 || ! isset($manifest['input_reservation'])) {
                return;
            }
            if (isset($manifest['input_reservation'])) {
                if (($manifest['input_capture'] ?? null) !== $inputCapture
                    || ($manifest['input_reservation']['attempt'] ?? null) !== $inputCapture['attempt']) {
                    return;
                }
            }
            if ($status === NzbImportStatus::Inserted
                && ($manifest['outcome_status'] ?? null) === $status->value
                && ($manifest['outcome_capture'] ?? null) === $inputCapture
                && ($manifest['accepted_capture'] ?? null) === $inputCapture
                && ($manifest['release_id'] ?? null) === $releaseId
                && ($manifest['release_guid'] ?? null) === $releaseGuid
                && ($manifest['candidate_identity'] ?? null) === null
                && ! array_key_exists('accepted_admission', $manifest)
                && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)) {
                // Notifications cannot replay an accepted outcome over newer companion work.
                return;
            }
            if ($status === NzbImportStatus::Duplicate
                && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
                && ($manifest['accepted_capture'] ?? null) === $inputCapture
                && ($manifest['outcome_status'] ?? null) === NzbImportStatus::Inserted->value
                && $this->acceptedInputValid($manifestPath, $manifest)
                && $this->nfoCandidateCurrent($manifest)) {
                return;
            }
            if (($manifest['accepted_capture'] ?? null) === $inputCapture
                && is_array($manifest['nfo_capture'] ?? null)
                && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
                && (DB::transactionLevel() !== 0 || $this->nfoCleanupCommitted($manifest))) {
                // A same-generation notification cannot replace historical receipt authority
                // with current candidate state. Ambient receipt authority is unknown.
                return;
            }
            $staleAdmissionRetry = ($manifest['stale_admission_retry'] ?? false) === true;
            if (in_array($status, [NzbImportStatus::Queued, NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred], true)) {
                unset($manifest['stale_admission_retry']);
            }
            $manifest['outcome_capture'] = $inputCapture;
            $manifest['outcome_status'] = $status->value;
            if (in_array($status, [NzbImportStatus::Queued, NzbImportStatus::Inserted], true)) {
                $manifest['accepted_capture'] = $inputCapture;
                if ($status === NzbImportStatus::Queued && $candidateAdmission !== null) {
                    $manifest['accepted_admission'] = $candidateAdmission;
                }
            }
            if ($status === NzbImportStatus::Inserted) {
                if ($releaseId === null || $releaseGuid === null || $releaseGuid === '') {
                    throw new RuntimeException("Inserted NZB has no release identity: {$nzbPath}");
                }

                $manifest['candidate_identity'] = null;
                unset($manifest['accepted_admission']);
                $manifest['state'] = self::STATE_NZB_IMPORTED;
                $manifest['release_id'] = $releaseId;
                $manifest['release_guid'] = $releaseGuid;
                $manifest['last_error'] = null;
            } elseif ($status === NzbImportStatus::Queued) {
                if ($candidateIdentity === null || preg_match('/\A[0-9a-f]{40}\z/', $candidateIdentity) !== 1) {
                    throw new RuntimeException('Queued upload has no valid verification candidate identity.');
                }
                if (($manifest['candidate_identity'] ?? null) === $candidateIdentity
                    && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED, self::STATE_NZB_REJECTED], true)) {
                    if ($candidateAdmission === null) {
                        return;
                    }
                    if ($this->nfoCandidateCurrent($manifest) && $this->acceptedInputValid($manifestPath, $manifest)) {
                        $manifest['updated_at'] = now()->toIso8601String();
                        $this->write($manifestPath, $manifest);

                        return;
                    }
                }
                $manifest['candidate_identity'] = $candidateIdentity;
                $manifest['state'] = self::STATE_NZB_PENDING;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;
                $manifest['last_error'] = null;
            } elseif (in_array($status, [NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred], true)) {
                // A concurrent import may already own reconciliation or have completed this upload.
                // A late advisory metadata outcome must not discard that durable identity.
                if ((! $staleAdmissionRetry && $this->canReconcileCandidate($manifest))
                    || in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED, self::STATE_NZB_REJECTED, self::STATE_NZB_DUPLICATE], true)) {
                    return;
                }
                $manifest['state'] = $status === NzbImportStatus::MetadataIgnored
                    ? self::STATE_METADATA_REVIEW
                    : self::STATE_METADATA_DEFERRED;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;
                $manifest['candidate_identity'] = null;
                $manifest['last_error'] = $error ?? "NZB import ended with status {$status->value}";
            } elseif ($status === NzbImportStatus::Duplicate) {
                $manifest['state'] = self::STATE_NZB_DUPLICATE;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;
                $manifest['last_error'] = $error ?? 'NZB import detected an existing duplicate release';
            } else {
                $manifest['state'] = self::STATE_NZB_FAILED;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;
                $manifest['last_error'] = $error ?? "NZB import ended with status {$status->value}";
            }

            $manifest['updated_at'] = now()->toIso8601String();
            $this->write($manifestPath, $manifest);
        });
    }

    public function shouldImportNzb(string $nzbPath): bool
    {
        $manifestPath = dirname($nzbPath).DIRECTORY_SEPARATOR.self::FILENAME;
        if (! $this->filesystem->isFile($manifestPath)) {
            return true;
        }

        $manifest = $this->read($manifestPath);
        if (! hash_equals($manifest['nzb']['filename'], basename($nzbPath))) {
            throw new RuntimeException("NZB path does not match its upload manifest: {$nzbPath}");
        }

        $manifest = $this->reconcilePending($manifestPath);

        return in_array($manifest['state'], [self::STATE_STAGED, self::STATE_NZB_FAILED, self::STATE_METADATA_DEFERRED], true)
            || ($manifest['state'] === self::STATE_METADATA_REVIEW && ($manifest['stale_admission_retry'] ?? false) === true);
    }

    /**
     * Resolve a queued upload using its recorded article identity, even after the NZB was deleted.
     *
     * @return array<string, mixed>
     */
    public function reconcilePending(string $manifestPath): array
    {
        $manifest = $this->read($manifestPath);
        if (isset($manifest['custody_collision'])) {
            return $manifest;
        }
        if (! empty($manifest['custody_gc'])) {
            $this->withManifestLock($manifestPath, function () use ($manifestPath): void {
                $current = $this->read($manifestPath);
                $this->drainCustodyGc($manifestPath, $current);
            });
            $manifest = $this->read($manifestPath);
        }
        if (is_array($manifest['delete_input_pending'] ?? null)) {
            $this->deleteCapturedInput(dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nzb']['filename'], $manifest['delete_input_pending']);
        }
        if (isset($manifest['delete_terminal_nfo'])) {
            $this->deleteTerminalCompanion($manifestPath);
        }
        $manifest = $this->read($manifestPath);
        if (in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
            && $this->resumeCommittedCompanion($manifestPath)) {
            return $this->read($manifestPath);
        }
        if (! isset($manifest['accepted_capture']) && ($this->canReconcileCandidate($manifest)
            || in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_FAILED], true))) {
            return $this->withManifestLock($manifestPath, function () use ($manifestPath): array {
                $m = $this->read($manifestPath);
                if (isset($m['custody_collision'])) {
                    return $m;
                }
                if (! isset($m['accepted_capture'])) {
                    $m['legacy_provenance'] ??= array_intersect_key($m, array_flip(['version', 'state', 'candidate_identity', 'release_id', 'release_guid']));
                    $m['state'] = self::STATE_METADATA_REVIEW;
                    $m['release_id'] = null;
                    $m['release_guid'] = null;
                    $m['last_error'] = 'Unknown legacy admission provenance; authoritative migration required.';
                    $this->write($manifestPath, $m);
                }

                return $m;
            });
        }
        if (! $this->canReconcileCandidate($manifest) && ! isset($manifest['accepted_capture'])) {
            return $manifest;
        }

        return $this->withManifestLock($manifestPath, function () use ($manifestPath): array {
            $manifest = $this->read($manifestPath);
            if (isset($manifest['custody_collision'])) {
                return $manifest;
            }
            if (is_array($manifest['nfo_capture'] ?? null)
                && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)) {
                if (DB::transactionLevel() !== 0) {
                    // Historical receipt authority is unknown inside an ambient transaction.
                    return $manifest;
                }
                if ($this->resumeCommittedCompanionLocked($manifestPath, $manifest)) {
                    return $manifest;
                }
            }
            $accepted = $manifest['accepted_capture'] ?? null;
            $source = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nzb']['filename'];
            if ($accepted !== null && in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_FAILED], true)
                && ! $this->acceptedInputValid($manifestPath, $manifest)) {
                $manifest['state'] = self::STATE_METADATA_REVIEW;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;
                $this->write($manifestPath, $manifest);

                return $manifest;
            }
            if (! $this->canReconcileCandidate($manifest)) {
                if ($manifest['state'] === self::STATE_NZB_IMPORTED) {
                    $this->finishInputDeletion($manifestPath, $manifest, publicationOnly: true);
                }

                return $manifest;
            }
            app(VerificationResetService::class)->withAdmission(function () use (&$manifest, $manifestPath): void {
                $resolved = DB::transaction(fn (): array => $this->resolveCandidate($manifest, $manifestPath), 5);
                if (DB::transactionLevel() > 0) {
                    DB::afterCommit(fn () => $this->reconcilePending($manifestPath));

                    return;
                }
                if ($resolved !== $manifest) {
                    $this->write($manifestPath, $resolved);
                    $manifest = $resolved;
                }
            });
            if ($manifest['state'] === self::STATE_NZB_IMPORTED) {
                $this->finishInputDeletion($manifestPath, $manifest, publicationOnly: true);
            }

            return $manifest;
        });
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function canReconcileCandidate(array $manifest): bool
    {
        if ($manifest['state'] === self::STATE_NZB_PENDING) {
            return true;
        }

        $identity = $manifest['candidate_identity'] ?? null;

        return in_array($manifest['state'], [self::STATE_METADATA_REVIEW, self::STATE_METADATA_DEFERRED], true)
            && is_string($identity)
            && preg_match('/\A[0-9a-f]{40}\z/', $identity) === 1;
    }

    /**
     * Resolve current admission state while holding the admission locks (the candidate row, or
     * the legacy capacity ledger when manifests are files).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function resolveCandidate(array $manifest, string $manifestPath): array
    {
        $identity = $manifest['candidate_identity'] ?? null;
        if (! is_string($identity) || preg_match('/\A[0-9a-f]{40}\z/', $identity) !== 1) {
            throw new RuntimeException('Pending upload has no valid verification candidate identity.');
        }
        app(VerificationLedger::class)->enter('Verification capacity state is unavailable; upload reconciliation deferred.');
        $candidate = DB::table('release_verification_candidates as candidates')
            ->leftJoin('releases as published', 'published.id', '=', 'candidates.release_id')
            ->where('candidates.identity', $identity)->lockForUpdate()
            ->first(['candidates.id', 'candidates.version', 'candidates.manifest_hash', 'candidates.status', 'candidates.release_id', 'candidates.verified_at', 'published.guid']);
        if ($candidate !== null && in_array($candidate->status, ['complete', 'repaired', 'sampled'], true) && ! $this->admissionMatches($manifest, $candidate)) {
            $manifest['state'] = self::STATE_METADATA_REVIEW;
            $manifest['release_id'] = null;
            $manifest['release_guid'] = null;
            // Retry admission against the current tuple; only admission can decide compatibility.
            $manifest['stale_admission_retry'] = true;
            $manifest['last_error'] = 'Accepted candidate admission revision no longer matches publication.';

            return $manifest;
        }
        if ($candidate !== null && in_array($candidate->status, ['metadata_deferred', 'metadata_expired'], true)) {
            $manifest['state'] = self::STATE_METADATA_DEFERRED;
            $manifest['release_id'] = null;
            $manifest['release_guid'] = null;
            $manifest['last_error'] = 'Download verification candidate awaits metadata capacity; retained source may retry.';
            $manifest['updated_at'] = now()->toIso8601String();

            return $manifest;
        }
        if ($candidate !== null && $candidate->status === 'metadata_review') {
            unset($manifest['stale_admission_retry']);
            $manifest['state'] = self::STATE_METADATA_REVIEW;
            $manifest['release_id'] = null;
            $manifest['release_guid'] = null;
            $manifest['last_error'] = 'Download verification candidate is held for metadata review.';
            $manifest['updated_at'] = now()->toIso8601String();

            return $manifest;
        }
        if ($candidate !== null && ! in_array($candidate->status, ['complete', 'repaired', 'sampled', 'expired', 'unrepairable'], true)) {
            return $manifest;
        }
        if ($candidate !== null && in_array($candidate->status, ['complete', 'repaired', 'sampled'], true) && $candidate->verified_at !== null
            && (int) $candidate->release_id > 0 && is_string($candidate->guid) && $candidate->guid !== '') {
            $accepted = $manifest['accepted_capture'] ?? null;
            $source = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nzb']['filename'];
            if ($accepted !== null && ! $this->acceptedInputValid($manifestPath, $manifest)) {
                $manifest['state'] = self::STATE_METADATA_REVIEW;
                $manifest['release_id'] = null;
                $manifest['release_guid'] = null;

                return $manifest;
            }
            $manifest['state'] = self::STATE_NZB_IMPORTED;
            $manifest['release_id'] = (int) $candidate->release_id;
            $manifest['release_guid'] = $candidate->guid;
            $manifest['last_error'] = null;
        } else {
            $manifest['state'] = self::STATE_NZB_REJECTED;
            $manifest['release_id'] = null;
            $manifest['release_guid'] = null;
            $manifest['last_error'] = match ($candidate->status ?? null) {
                null => 'Download verification candidate no longer exists.',
                'expired' => 'Download verification expired before publication.',
                'unrepairable' => 'Download verification found the payload unrepairable.',
                default => 'Verified publication has no current release identity.',
            };
        }
        $manifest['updated_at'] = now()->toIso8601String();

        return $manifest;
    }

    /** Only the actual final SQL writer may mint a commit witness. */
    public function consumeCompanion(string $manifestPath, NfoService $store, bool $delete): bool
    {
        return $this->withManifestLock($manifestPath, function () use ($manifestPath, $store, $delete): bool {
            $m = $this->read($manifestPath);
            if (isset($m['custody_collision'])) {
                throw new RuntimeException('Custody collision requires explicit restaging.');
            }
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('Companion consumption requires its own primary transaction');
            }
            if ($this->resumeCommittedCompanionLocked($manifestPath, $m, $delete)) {
                return true;
            }
            $receipt = $m['nfo_capture'] ?? null;
            $accepted = $m['accepted_capture'] ?? null;
            if (! is_array($receipt) || ($receipt['protocol'] ?? null) !== 1 || ! is_array($accepted) || ($accepted['companion_attempt'] ?? null) !== $receipt['attempt']
                || ($m['input_capture'] ?? null) !== $accepted
                || ($m['input_reservation']['attempt'] ?? null) !== $accepted['attempt']
                || ! in_array($m['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_FAILED], true)
                || ! $this->acceptedInputValid($manifestPath, $m)) {
                $m['state'] = self::STATE_METADATA_REVIEW;
                $m['release_id'] = null;
                $m['release_guid'] = null;
                $m['last_error'] = 'Unknown companion generation or admitted input';
                $this->write($manifestPath, $m);
                throw new RuntimeException('Unknown companion generation or admitted input');
            }
            if ($delete) {
                $m['delete_nfo_after_import'] = $receipt['attempt'];
                $this->write($manifestPath, $m);
            }
            $path = $this->resolvePayloadPath($manifestPath, $receipt['filename'], 'nfo');
            $validate = function () use ($path, $receipt, $manifestPath, &$m): void {
                if (! $this->acceptedInputValid($manifestPath, $m)) {
                    throw new RuntimeException('Admitted NZB source generation changed');
                }
                if ($this->sourceIdentity($path) !== $receipt['source_identity']
                    || ! hash_equals($receipt['digest'], $this->currentFileDigest($path))
                    || $this->sourceIdentity($path) !== $receipt['source_identity']) {
                    throw new RuntimeException('Companion generation changed');
                }
            };
            try {
                $validate();
                $body = $this->filesystem->get($path);
                if (! hash_equals($receipt['digest'], hash('sha256', $body))) {
                    throw new RuntimeException('Companion body does not match staged bytes');
                }
                $validate();
                if ($body === '' || strlen($body) > 65535) {
                    throw new NfoPayloadRejected('Captured companion is empty or exceeds the NFO size limit');
                }
                // A sequential restart may resume only the exact already committed operation.
                // Neither matching payload bytes nor a filesystem marker supplies authority.
                $existingReceipt = DB::table('nfo_cleanup_commits')
                    ->where('binding', $this->nfoCommitBinding($m))->exists();
                if ($existingReceipt) {
                    if (! $this->nfoCleanupCommitted($m)) {
                        throw new RuntimeException('Existing NFO receipt does not match committed operation');
                    }
                } else {
                    DB::transaction(function () use ($store, $m, $body, $validate): void {
                        if (! $this->nfoCandidateCurrent($m)) {
                            throw new RuntimeException('NFO candidate publication changed');
                        }
                        if (! DB::table('releases')->where('id', $m['release_id'])->where('guid', $m['release_guid'])->lockForUpdate()->exists()) {
                            throw new RuntimeException('Release identity changed before companion store');
                        }
                        if ((new ReleaseNfo)->getConnection()->getPdo() !== DB::getPdo()
                            || (new Release)->getConnection()->getPdo() !== DB::getPdo()) {
                            throw new RuntimeException('NFO storage must share the receipt transaction connection');
                        }
                        $store->storeNfoContentWithCommit((int) $m['release_id'], $body, function () use ($m, $body, $validate): void {
                            $validate();
                            // SQL authority is created with the actual NFO write.
                            $stored = DB::table('release_nfos')->where('releases_id', $m['release_id'])->value('nfo');
                            $expected = "\x1f\x8b\x08\x00".gzcompress($body);
                            if (! is_string($stored) || ! hash_equals($expected, $stored)) {
                                throw new RuntimeException('NFO writer did not persist the admitted bytes');
                            }
                            DB::table('nfo_cleanup_commits')->insert([
                                'binding' => $this->nfoCommitBinding($m),
                                'release_id' => $m['release_id'],
                                'release_guid' => $m['release_guid'],
                                'candidate_identity' => $m['candidate_identity'],
                                'stored_digest' => hash('sha256', $stored),
                            ]);
                            $validate();
                        });
                    });
                }
                $validate();
                $m['state'] = self::STATE_NFO_IMPORTED;
                $m['last_error'] = null;
                $m['updated_at'] = now()->toIso8601String();
                $this->write($manifestPath, $m);
                $this->finishInputDeletion($manifestPath, $m);
                if (! $this->finishCompanionDeletion($manifestPath, $m)) {
                    throw new RuntimeException('Companion cleanup held in private custody');
                }

                return true;
            } catch (\Throwable $e) {
                $m['state'] = self::STATE_NFO_FAILED;
                $m['nfo_failure_kind'] = $e instanceof NfoPayloadRejected ? 'invalid_payload' : 'retryable';
                $m['last_error'] = $e->getMessage();
                $m['updated_at'] = now()->toIso8601String();
                $this->write($manifestPath, $m);
                throw $e;
            }
        });
    }

    public function resumeCommittedCompanion(string $manifestPath, bool $delete = false): bool
    {
        return $this->withManifestLock($manifestPath, function () use ($manifestPath, $delete): bool {
            $manifest = $this->read($manifestPath);
            if (isset($manifest['custody_collision'])) {
                return false;
            }

            return $this->resumeCommittedCompanionLocked($manifestPath, $manifest, $delete);
        });
    }

    /** @param array<string, mixed> $manifest */
    private function resumeCommittedCompanionLocked(string $manifestPath, array &$manifest, bool $delete = false): bool
    {
        $this->drainCustodyGc($manifestPath, $manifest);
        if (! in_array($manifest['state'], [self::STATE_NZB_IMPORTED, self::STATE_NFO_IMPORTED, self::STATE_NFO_FAILED], true)
            || ! $this->nfoCleanupCommitted($manifest)) {
            return false;
        }
        if ($delete && ($manifest['nfo_relinquished_attempt'] ?? null) !== $manifest['nfo_capture']['attempt']) {
            $manifest['delete_nfo_after_import'] = $manifest['nfo_capture']['attempt'];
        }
        $manifest['state'] = self::STATE_NFO_IMPORTED;
        $manifest['last_error'] = null;
        $this->write($manifestPath, $manifest);
        $this->finishInputDeletion($manifestPath, $manifest);
        $this->finishCompanionDeletion($manifestPath, $manifest);

        return true;
    }

    /** @param array<string, mixed> $manifest */
    private function finishCompanionDeletion(string $manifestPath, array &$manifest): bool
    {
        return $this->withRetirementAdmission(function () use ($manifestPath, &$manifest): bool {
            if (isset($manifest['custody_collision'])) {
                return false;
            }
            $receipt = $manifest['nfo_capture'] ?? null;
            if (! is_array($receipt) || ($manifest['delete_nfo_after_import'] ?? null) !== $receipt['attempt']) {
                return true;
            }
            if (! $this->nfoCleanupCommitted($manifest)) {
                return false;
            }
            $path = dirname($manifestPath).DIRECTORY_SEPARATOR.$receipt['filename'];
            if (! $this->quarantineDelete($path, $receipt, $this->nfoCommitBinding($manifest))) {
                return false;
            }
            $manifest['nfo_relinquished_attempt'] = $receipt['attempt'];
            unset($manifest['delete_nfo_after_import']);
            $this->rememberCustodyGc($manifest, $path, $receipt, $this->nfoCommitBinding($manifest));
            $this->write($manifestPath, $manifest);
            $this->drainCustodyGc($manifestPath, $manifest);

            return true;
        });
    }

    /**
     * Authorize and retire only committed evidence while excluding destructive reset.
     *
     * @param  callable(): bool  $retire
     */
    private function withRetirementAdmission(callable $retire): bool
    {
        if (DB::transactionLevel() !== 0) {
            return false;
        }
        $retired = false;
        app(VerificationResetService::class)->withAdmission(function () use ($retire, &$retired): void {
            $retired = $retire();
        });

        return $retired;
    }

    public function cleanupImportedSource(string $manifestPath): void
    {
        $this->withManifestLock($manifestPath, function () use ($manifestPath): void {
            $manifest = $this->read($manifestPath);
            if ($manifest['state'] === self::STATE_NFO_IMPORTED) {
                $this->finishInputDeletion($manifestPath, $manifest);
            }
        });
    }

    /** @param array<string, mixed> $manifest */
    private function finishInputDeletion(string $manifestPath, array &$manifest, bool $publicationOnly = false): void
    {
        if (DB::transactionLevel() !== 0) {
            return;
        }
        app(VerificationResetService::class)->withAdmission(function () use ($manifestPath, &$manifest, $publicationOnly): void {
            if (isset($manifest['custody_collision'])) {
                return;
            }
            $field = $publicationOnly ? 'delete_nzb_after_publication' : 'delete_nzb_after_nfo';
            $requested = $manifest[$field] ?? null;
            $accepted = $manifest['accepted_capture'] ?? null;
            if (! is_array($requested) || ! is_array($accepted)
                || $requested !== array_intersect_key($accepted, array_flip(['source_identity', 'digest']))) {
                return;
            }
            if ($publicationOnly) {
                if ($manifest['state'] !== self::STATE_NZB_IMPORTED || is_array($manifest['nfo'] ?? null) || is_array($manifest['nfo_capture'] ?? null)
                    || ! $this->nfoCandidateCurrent($manifest)
                    || ! DB::table('releases')->where('id', $manifest['release_id'])->where('guid', $manifest['release_guid'])->exists()) {
                    return;
                }
            } elseif (! $this->nfoCleanupCommitted($manifest)) {
                return;
            }
            $source = dirname($manifestPath).DIRECTORY_SEPARATOR.$manifest['nzb']['filename'];
            if (! $this->quarantineDelete($source, $accepted, $publicationOnly ? null : $this->nfoCommitBinding($manifest))) {
                return;
            }
            $manifest['relinquished_attempt'] = $accepted['attempt'];
            unset($manifest[$field]);
            $this->rememberCustodyGc($manifest, $source, $accepted, $publicationOnly ? null : $this->nfoCommitBinding($manifest));
            $this->write($manifestPath, $manifest);
            $this->drainCustodyGc($manifestPath, $manifest);
        });
    }

    /** All captured generation and admission fields participate in the digest. */

    /** @param array<string, mixed> $manifest */
    private function nfoCommitBinding(array $manifest): string
    {
        return hash('sha256', json_encode([
            'protocol' => 2,
            'operation' => 'nfo-store-cleanup',
            'upload_id' => $manifest['upload_id'],
            'accepted' => $manifest['accepted_capture'],
            'accepted_admission' => $manifest['accepted_admission'] ?? null,
            'companion' => $manifest['nfo_capture'],
            'candidate_identity' => $manifest['candidate_identity'],
            'release_id' => $manifest['release_id'],
            'release_guid' => $manifest['release_guid'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $manifest */
    private function nfoCandidateCurrent(array $manifest): bool
    {
        $admission = $manifest['accepted_admission'] ?? null;
        if (! is_array($admission)) {
            // Direct publication has no verification candidate. Its actual importer outcome
            // and captured origin authorize only the recorded live release, never verification.
            return ! array_key_exists('accepted_admission', $manifest)
                && ($manifest['candidate_identity'] ?? null) === null
                && ($manifest['outcome_status'] ?? null) === NzbImportStatus::Inserted->value
                && is_array($manifest['accepted_capture'] ?? null)
                && ($manifest['outcome_capture'] ?? null) === $manifest['accepted_capture']
                && DB::table('releases')->where('id', $manifest['release_id'])
                    ->where('guid', $manifest['release_guid'])->lockForUpdate()->exists();
        }

        return DB::table('release_verification_candidates')
            ->where('id', $admission['candidate_id'])
            ->where('version', $admission['version'])
            ->where('manifest_hash', $admission['manifest_hash'])
            ->where('identity', $manifest['candidate_identity'])
            ->where('release_id', $manifest['release_id'])
            ->whereIn('status', ['complete', 'repaired', 'sampled'])
            ->whereNotNull('verified_at')->lockForUpdate()->exists();
    }

    /** @param array<string, mixed> $manifest */
    private function admissionMatches(array $manifest, ?object $candidate): bool
    {
        $a = $manifest['accepted_admission'] ?? null;

        return is_array($a) && $candidate !== null
            && ($a['identity'] ?? null) === ($manifest['candidate_identity'] ?? null)
            && ($a['candidate_id'] ?? null) === (int) $candidate->id
            && ($a['version'] ?? null) === (int) $candidate->version
            && is_string($a['manifest_hash'] ?? null) && hash_equals($a['manifest_hash'], (string) $candidate->manifest_hash);
    }

    /** A filesystem journal never supplies admission. Requires the primary committed row. */
    /** @param array<string, mixed> $manifest */
    public function nfoCleanupCommitted(array $manifest): bool
    {
        if (DB::transactionLevel() !== 0 || ! is_array($manifest['accepted_capture'] ?? null)
            || ($manifest['accepted_capture']['protocol'] ?? null) !== 2
            || ! is_array($manifest['nfo_capture'] ?? null)
            || ! array_key_exists('candidate_identity', $manifest)
            || ($manifest['candidate_identity'] !== null && ! is_string($manifest['candidate_identity']))
            || ! is_int($manifest['release_id'] ?? null)
            || ! is_string($manifest['release_guid'] ?? null)) {
            return false;
        }
        if (! DB::connection()->getSchemaBuilder()->hasTable('nfo_cleanup_commits')) {
            return false;
        }
        $row = DB::table('nfo_cleanup_commits')->where('binding', $this->nfoCommitBinding($manifest))->first();
        if ($row === null || (int) $row->release_id !== $manifest['release_id']
            || $row->release_guid !== $manifest['release_guid']
            || $row->candidate_identity !== $manifest['candidate_identity']
            || ! DB::table('releases')->where('id', $manifest['release_id'])->where('guid', $manifest['release_guid'])->exists()) {
            return false;
        }

        // Another admitted origin may legitimately replace the selected NFO later.
        // This immutable receipt proves this origin's earlier committed storage.
        return is_string($row->stored_digest) && preg_match('/\A[0-9a-f]{64}\z/', $row->stored_digest) === 1;
    }

    public function deleteTerminalCompanion(string $manifestPath): bool
    {
        return $this->withManifestLock($manifestPath, function () use ($manifestPath): bool {
            $m = $this->read($manifestPath);
            $cleanup = function () use ($manifestPath, $m): bool {
                if (isset($m['custody_collision'])) {
                    return false;
                }
                $receipt = $m['nfo_capture'] ?? null;
                $outcome = $m['outcome_capture'] ?? $m['accepted_capture'] ?? null;
                $failedCompanion = $m['state'] === self::STATE_NFO_FAILED
                    && ($m['nfo_failure_kind'] ?? null) === 'invalid_payload'
                    && is_array($m['accepted_capture'] ?? null)
                    && $outcome === $m['accepted_capture']
                    && $this->acceptedInputValid($manifestPath, $m)
                    && $this->nfoCandidateCurrent($m)
                    && ! $this->nfoCleanupCommitted($m);
                if (! is_array($receipt) || ($receipt['protocol'] ?? null) !== 1 || ! is_array($outcome) || ($m['input_reservation']['attempt'] ?? null) !== $outcome['attempt'] || ($outcome['companion_attempt'] ?? null) !== $receipt['attempt']
                    || (! in_array($m['outcome_status'] ?? null, ['failed', 'blacklisted', 'nogroup', 'duplicate'], true)
                        && ! (isset($m['accepted_capture']) && $m['state'] === self::STATE_NZB_REJECTED) && ! $failedCompanion)) {
                    return false;
                }
                $path = dirname($manifestPath).DIRECTORY_SEPARATOR.$receipt['filename'];
                if (($m['delete_terminal_nfo'] ?? null) !== $receipt && ! $this->captureMatches($path, $receipt)) {
                    return false;
                }

                $m['delete_terminal_nfo'] = $receipt;
                $this->write($manifestPath, $m);
                if (! $this->quarantineDelete($path, $receipt)) {
                    return false;
                }
                unset($m['delete_terminal_nfo']);
                if ($failedCompanion) {
                    $m['nfo_failed_relinquished_attempt'] = $receipt['attempt'];
                    unset($m['delete_nfo_after_import']);
                }
                $this->rememberCustodyGc($m, $path, $receipt);
                $this->write($manifestPath, $m);
                $this->drainCustodyGc($manifestPath, $m);

                return true;
            };

            // Captured terminal NZB outcomes are filesystem authority. Only failed
            // companion cleanup consults live SQL and needs reset admission fencing.
            return $m['state'] === self::STATE_NFO_FAILED
                ? $this->withRetirementAdmission($cleanup)
                : $cleanup();
        });
    }

    /**
     * Record cleanup ownership in the same durable manifest update as completion.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $receipt
     */
    private function rememberCustodyGc(array &$manifest, string $source, array $receipt, ?string $binding = null): void
    {
        $manifest['custody_gc'][basename($this->custodyDirectory($source, $receipt))] = [
            'filename' => basename($source), 'receipt' => $receipt, 'commit_binding' => $binding,
        ];
    }

    /** Reclaim only owned journal files; persisted GC resumes partial deletion without scanning.
     * @param  array<string, mixed>  $manifest
     */
    private function drainCustodyGc(string $manifestPath, array &$manifest): void
    {
        if (! is_array($manifest['custody_gc'] ?? null) || $manifest['custody_gc'] === []) {
            return;
        }
        // A previous manifest rename may have become visible before its parent sync
        // failed. Establish durable GC authority anew before deleting recovery proof.
        $this->write($manifestPath, $manifest);
        foreach ($manifest['custody_gc'] as $key => $entry) {
            if (! is_array($entry) || ! is_string($entry['filename'] ?? null) || ! is_array($entry['receipt'] ?? null)) {
                continue;
            }
            $receipt = $entry['receipt'];
            $filename = $entry['filename'];
            $owners = $filename === $manifest['nzb']['filename']
                ? [$manifest['accepted_capture'] ?? null, $manifest['outcome_capture'] ?? null]
                : ($filename === ($manifest['nfo']['filename'] ?? null) ? [$manifest['nfo_capture'] ?? null] : []);
            if (! in_array($receipt, $owners, true)) {
                continue;
            }
            $source = dirname($manifestPath).DIRECTORY_SEPARATOR.$filename;
            $directory = $this->custodyDirectory($source, $receipt);
            if ($key !== basename($directory) || is_link($directory)) {
                continue;
            }
            $expected = ['protocol' => 3, 'source' => $filename, 'receipt' => $receipt, 'commit_binding' => $entry['commit_binding'] ?? null];
            if (file_exists($directory)) {
                $stat = lstat($directory);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0777) !== 0700
                    || file_exists($directory.'/payload') || is_link($directory.'/payload')) {
                    continue;
                }
                foreach (['intent.json', 'moved.json', 'complete.json'] as $name) {
                    $path = $directory.'/'.$name;
                    if (file_exists($path) || is_link($path)) {
                        if (is_link($path) || ! is_file($path) || filesize($path) > 16384
                            || ! $this->custodyMarkerMatches($path, $expected) || ! $this->filesystem->delete($path)) {
                            continue 2;
                        }
                    }
                }
                if (! DirectorySyncBridge::sync($directory, 'custody_gc_files') || ! @rmdir($directory)) {
                    continue;
                }
            }
            if (! DirectorySyncBridge::sync(dirname($source), 'custody_gc_parent')) {
                continue;
            }
            unset($manifest['custody_gc'][$key]);
            if ($manifest['custody_gc'] === []) {
                unset($manifest['custody_gc']);
            }
            $this->write($manifestPath, $manifest);
        }
    }

    /**
     * Caller holds the stable manifest lock.
     * Writers are confined to the original pathname, not open descriptors,
     * its parent directory, or this unpredictable private attempt directory.
     * Validated intents resume only their exact captured operation after interruption.
     * Directory synchronization must succeed before custody can advance.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function quarantineDelete(string $source, array $receipt, ?string $commitBinding = null): bool
    {
        $attempt = $this->custodyDirectory($source, $receipt);
        if (! is_dir($attempt) && ! mkdir($attempt, 0700)) {
            return false;
        }
        $stat = lstat($attempt);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0777) !== 0700
            || ! DirectorySyncBridge::sync(dirname($source), 'mkdir_parent')) {
            return false;
        }
        $expected = ['protocol' => 3, 'source' => basename($source), 'receipt' => $receipt, 'commit_binding' => $commitBinding];
        $intentPath = $attempt.'/intent.json';
        $intent = $this->readCustodyIntent($source, $receipt);
        if ($intent === null) {
            if (! $this->captureMatches($source, $receipt) || file_exists($attempt.'/payload')
                || is_link($attempt.'/payload') || file_exists($attempt.'/complete.json') || is_link($intentPath)) {
                return false;
            }
            // An interrupted pre-move journal write can be replaced while the exact
            // source is still owned and no custody transition has happened.
            $this->write($intentPath, $expected);
            $intent = $this->readCustodyIntent($source, $receipt);
        }
        if ($intent !== $expected || ! DirectorySyncBridge::sync($attempt, 'intent_dir')) {
            return false;
        }
        $private = $attempt.'/payload';
        $complete = $attempt.'/complete.json';
        if (is_link($complete)) {
            return false;
        }
        if (is_file($complete)
            && json_decode($this->filesystem->get($complete), true) === $expected
            && ! file_exists($private) && ! is_link($private)) {
            return DirectorySyncBridge::sync($attempt, 'complete_dir');
        }
        if (! file_exists($private) && ! is_link($private) && $this->captureMatches($source, $receipt)) {
            if (! $this->filesystem->move($source, $private)) {
                return false;
            }
        }
        if (! DirectorySyncBridge::sync($attempt, 'rename_custody') || ! DirectorySyncBridge::sync(dirname($source), 'rename_parent')) {
            return false;
        }
        $moved = $attempt.'/moved.json';
        if (file_exists($private) || is_link($private)) {
            if (! $this->captureMatches($private, $receipt, renamed: true)) {
                $this->rescueForeignCustody($source, $private, $receipt);
            }
            if (is_link($moved)) {
                return false;
            }
            // Persist owned relocation before unlink. After unlink, this exact checkpoint
            // distinguishes completed custody from an intent whose move never happened.
            if (! $this->custodyMarkerMatches($moved, $expected)) {
                $this->write($moved, $expected);
            }
            if (! DirectorySyncBridge::sync($attempt, 'moved_dir') || ! $this->filesystem->delete($private)) {
                return false;
            }
        } elseif (! $this->custodyMarkerMatches($moved, $expected)) {
            // No proof that this generation reached private custody: retain uncertainty.
            return false;
        }
        // A newly arrived original pathname is independent and is never removed here.
        if (! DirectorySyncBridge::sync($attempt, 'unlink_dir')) {
            return false;
        }
        $this->write($complete, $expected);

        return DirectorySyncBridge::sync($attempt, 'complete_dir');
    }

    /**
     * Publish at most one bounded independent recovery copy, never a writable alias
     * of retained evidence. Unknown/oversized bytes stay in private review custody.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function rescueForeignCustody(string $source, string $private, array $receipt): never
    {
        $entry = @lstat($private);
        $recovered = null;
        $limit = 16_777_216;
        if (is_array($entry) && ($entry['mode'] & 0170000) === 0100000 && $entry['size'] <= $limit) {
            $identity = $this->sourceIdentity($private);
            $digest = $this->currentFileDigest($private);
            $destination = $source.'.recovered-'.hash('sha256', $receipt['attempt']);
            $mask = umask(0077);
            try {
                $output = @fopen($destination, 'x+b');
            } finally {
                umask($mask);
            }
            if ($output !== false) {
                $input = @fopen($private, 'rb');
                try {
                    if ($input !== false) {
                        stream_copy_to_stream($input, $output, $limit);
                    }
                    if (! fflush($output) || ! fsync($output)) {
                        throw new RuntimeException('Rescued custody copy durability is uncertain; retry required.');
                    }
                } finally {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    fclose($output);
                }
            }
            clearstatcache(true, $destination);
            $delivered = @lstat($destination);
            $deliveryIdentity = $this->sourceIdentity($destination);
            if (is_array($delivered) && ($delivered['mode'] & 0170000) === 0100000
                && $delivered['size'] === $entry['size'] && $delivered['ino'] !== $entry['ino']
                && $identity === $this->sourceIdentity($private)
                && hash_equals($digest, $this->currentFileDigest($destination))
                && $deliveryIdentity === $this->sourceIdentity($destination)) {
                // A prior copy may have reached its full length before file fsync failed.
                // Re-establish file durability on every retry, not just directory entry durability.
                $handle = @fopen($destination, 'rb');
                $synced = false;
                if ($handle !== false) {
                    try {
                        $opened = fstat($handle);
                        $synced = is_array($opened) && $opened['dev'] === $delivered['dev'] && $opened['ino'] === $delivered['ino']
                            && fsync($handle) && $deliveryIdentity === $this->sourceIdentity($destination);
                    } finally {
                        if (! fclose($handle)) {
                            $synced = false;
                        }
                    }
                }
                if (! $synced) {
                    throw new RuntimeException('Rescued custody copy durability is uncertain; retry required.');
                }
                if (! DirectorySyncBridge::sync(dirname($source), 'recovery_parent')) {
                    throw new RuntimeException('Rescued custody destination durability is uncertain; retry required.');
                }
                $recovered = basename($destination);
            }
        }

        throw new NzbCustodyCollision($receipt['attempt'], $recovered, basename(dirname($private)));
    }

    /** @param array<string, mixed> $expected */
    private function custodyMarkerMatches(string $path, array $expected): bool
    {
        return ! is_link($path) && is_file($path)
            && json_decode($this->filesystem->get($path), true) === $expected;
    }

    /** @param array<string, mixed> $receipt */
    private function custodyDirectory(string $source, array $receipt): string
    {
        // The captured attempt is a private random nonce; this stable locator is unguessable.
        return dirname($source).'/.custody-'.hash('sha256', json_encode([basename($source), $receipt], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>|null
     */
    private function readCustodyIntent(string $source, array $receipt): ?array
    {
        $directory = $this->custodyDirectory($source, $receipt);
        $path = $directory.'/intent.json';
        if (is_link($directory) || is_link($path) || ! is_file($path)) {
            return null;
        }
        $intent = json_decode($this->filesystem->get($path), true);

        return is_array($intent) && ($intent['protocol'] ?? null) === 3
            && ($intent['source'] ?? null) === basename($source) && ($intent['receipt'] ?? null) === $receipt ? $intent : null;
    }

    /** @param array<string, mixed> $receipt */
    private function capturedCustodyPayload(string $source, array $receipt): ?string
    {
        if ($this->readCustodyIntent($source, $receipt) === null) {
            return null;
        }
        $private = $this->custodyDirectory($source, $receipt).'/payload';

        return $this->captureMatches($private, $receipt, renamed: true) ? $private : null;
    }

    /** @param array<string, mixed> $receipt */
    private function captureMatches(string $path, array $receipt, bool $renamed = false): bool
    {
        $identity = $this->sourceIdentity($path);
        if ($identity === null || ! is_string($receipt['source_identity'] ?? null) || ! is_string($receipt['digest'] ?? null)) {
            return false;
        }
        $expected = json_decode($receipt['source_identity'], true);
        $actual = json_decode($identity, true);
        if ($renamed && is_array($expected) && is_array($actual)) {
            // A same-filesystem rename may update ctime, but not inode, mode or bytes.
            unset($expected['ctime'], $actual['ctime']);
        }

        return $expected === $actual && hash_equals($receipt['digest'], $this->currentFileDigest($path))
            && $this->sourceIdentity($path) === $identity;
    }

    /**
     * Resolve a payload filename without allowing a manifest to escape its upload directory.
     */
    public function resolvePayloadPath(string $manifestPath, string $filename, string $extension): string
    {
        $this->assertSafeFilename($filename, $extension);

        $directory = realpath(dirname($manifestPath));
        if ($directory === false) {
            throw new RuntimeException("Upload directory does not exist: {$manifestPath}");
        }

        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || dirname($realPath) !== $directory) {
            throw new RuntimeException("Upload payload is missing or outside its manifest directory: {$filename}");
        }

        return $realPath;
    }

    /**
     * @template TValue
     *
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    private function withManifestLock(string $manifestPath, callable $callback): mixed
    {
        $lock = fopen(dirname($manifestPath).DIRECTORY_SEPARATOR.'.nntmux-upload.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot lock upload manifest.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock upload manifest.');
            }

            try {
                return $callback();
            } catch (NzbCustodyCollision $collision) {
                // The callback may have recorded a retryable failure while unwinding.
                // Override it under the same manifest lock with an explicit review hold.
                $manifest = $this->read($manifestPath);
                $manifest['state'] = self::STATE_METADATA_REVIEW;
                $manifest['custody_collision'] = ['attempt' => $collision->attempt,
                    'recovered_filename' => $collision->recoveredFilename, 'private_directory' => $collision->privateDirectory];
                $manifest['last_error'] = $collision->getMessage();
                foreach (['delete_input_pending', 'delete_terminal_nfo'] as $field) {
                    if (($manifest[$field]['attempt'] ?? null) === $collision->attempt) {
                        unset($manifest[$field]);
                    }
                }
                if (($manifest['delete_nfo_after_import'] ?? null) === $collision->attempt) {
                    unset($manifest['delete_nfo_after_import']);
                }
                $this->write($manifestPath, $manifest);
                throw $collision;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function write(string $manifestPath, array $manifest): void
    {
        try {
            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ).PHP_EOL;
        } catch (\JsonException $exception) {
            throw new RuntimeException('Failed to encode upload manifest', previous: $exception);
        }

        // Same-directory exclusive staging prevents cross-device rename and temp clobber.
        $temporaryPath = $manifestPath.'.tmp.'.bin2hex(random_bytes(16));
        $previousMask = umask(0077);
        try {
            $stream = fopen($temporaryPath, 'x+b');
        } finally {
            umask($previousMask);
        }
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively stage upload manifest.');
        }
        try {
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Cannot protect staged upload manifest.');
            }
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($stream, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write staged upload manifest.');
                }
                $offset += $written;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Cannot flush staged upload manifest.');
            }
        } finally {
            if (! fclose($stream)) {
                throw new RuntimeException('Cannot close staged upload manifest.');
            }
        }
        // A failed stage remains private for diagnosis; never sweep unrelated files.
        if (! rename($temporaryPath, $manifestPath)) {
            throw new RuntimeException('Cannot atomically replace upload manifest.');
        }
        if (! DirectorySyncBridge::sync(dirname($manifestPath), 'manifest_parent')) {
            // Rename may already be visible; callers must treat this as uncertain.
            throw new RuntimeException('Upload manifest replacement durability is uncertain.');
        }
    }

    private function assertSafeFilename(string $filename, string $extension): void
    {
        if ($filename === ''
            || $filename === '.'
            || $filename === '..'
            || basename($filename) !== $filename
            || str_contains($filename, "\0")
            || strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== $extension
            || trim((string) pathinfo($filename, PATHINFO_FILENAME)) === '') {
            throw new RuntimeException("Unsafe {$extension} filename in upload manifest");
        }
    }
}
