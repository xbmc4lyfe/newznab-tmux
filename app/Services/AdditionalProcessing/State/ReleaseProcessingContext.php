<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Models\Release;
use App\Services\AdditionalProcessing\DTO\AdditionalWorkPlan;
use App\Services\AdditionalProcessing\DTO\ArchivedMediaEntry;
use App\Services\AdditionalProcessing\DTO\DownloadedArchive;
use App\Services\AdditionalProcessing\DTO\MediaSourcePlan;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Mutable context object that holds the processing state for a single release.
 * Passed between services during processing to share state.
 *
 * NOTE: Intentionally NOT a `spatie/laravel-data` Data object. This class is
 * mutated heavily during the additional-processing pipeline (counters, found
 * flags, accumulating message IDs) and holds an Eloquent {@see Release} model,
 * neither of which fits the immutable, serialisable design of `Data` DTOs.
 */
class ReleaseProcessingContext
{
    // Caller-level FFI benchmarks must qualify every workload before adoption.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    private const int MAX_RETAINED_ARCHIVES = 2;

    private const int MAX_RETAINED_ARCHIVE_BYTES = 16_777_216;

    private const int MAX_ARCHIVE_FILE_NAMES = 256;

    private const int MAX_NAMING_WITNESS_BYTES = 32768;

    // The release being processed
    public Release $release;

    // Processing state flags
    public bool $foundVideo = false;

    public bool $foundMediaInfo = false;

    public bool $foundAudioInfo = false;

    public bool $foundAudioSample = false;

    public bool $foundJPGSample = false;

    public bool $foundSample = false;

    public bool $foundPAR2Info = false;

    // Password state
    public int $passwordStatus = 0;

    public bool $releaseHasPassword = false;

    /** An archive listing was cut off or had unreadable records, so some entries were never checked for a password. */
    public bool $archiveListingIncomplete = false;

    // NFO state
    public bool $releaseHasNoNFO = false;

    // Group state
    public string $releaseGroupName = '';

    public bool $groupUnavailable = false;

    // NZB state
    public bool $nzbHasCompressedFile = false;

    /** Full decoded source generation captured before processing. */
    public ?string $nzbSourceDigest = null;

    /** @var list<array{name: string, firstMessageId: string}>|null Full raw XML inventory, never proof alone. */
    public ?array $nzbSourceFiles = null;

    /** @var array<string, string> Complete typed archive listings keyed by source first-message digest. */
    public array $archiveSourceWitnesses = [];

    /** @var list<array{name: string, firstMessageId: string}>|null Full raw XML nominations, never proof alone. */
    public ?array $directVideoFiles = null;

    /** @var array<string, array{name: string, size: int, digest: string}> Actual typed first-article observations. */
    public array $directVideoWitnesses = [];

    /** Every archive set in this source was inspected without omissions. */
    public bool $archiveNamingInspectionComplete = false;

    /** @var array<string, true> Exact filenames observed in this attempt, including duplicate inventory. */
    public array $archiveNamingWitnesses = [];

    /** @var array<string, bool> Attempted witness filenames; true only for archive-proof deferral. */
    public array $archiveFilenameNamingAttempts = [];

    /** Actual observed filenames were omitted due to invalid encoding/size or admission caps. */
    public bool $namingWitnessesOmitted = false;

    private int $namingWitnessBytes = 0;

    /**
     * Archive inspection ran for this release (not skipped for a book flood or disabled downloads).
     */
    public bool $archiveInspectionAttempted = false;

    /**
     * An archive was read but its file list can't be decoded here (a compressed 7z header),
     * so retrying the release later won't produce one.
     */
    public bool $archiveListingUndecodable = false;

    /**
     * An archive attempt failed in a way a later pass may not: missing articles,
     * an unavailable group, or data that couldn't be read yet.
     */
    public bool $archiveRetryable = false;

    /**
     * Volume sets of compressed candidates whose data turned out to be plain media,
     * which has no password.
     *
     * @var array<string, true>
     */
    public array $mediaVolumeSets = [];

    // Archive data was found outside the planned archive candidates (e.g. by the probe).
    public bool $unplannedArchiveSeen = false;

    /**
     * @var list<array<string, mixed>>
     */
    public array $nzbContents = [];

    public ?AdditionalWorkPlan $workPlan = null;

    /** The release is a whole season (by name, NZB file names or listed archive contents). */
    public bool $seasonPack = false;

    /** One episode was listed from a release with several archive sets; the rest of the season may follow. */
    public bool $seasonPackProbable = false;

    /** Archive naming evidence is partial, omitted, or cannot be classified safely. */
    public bool $archiveNameEvidenceIncomplete = false;

    /** @var list<string> names listed from every archive read for this release */
    public array $archiveFileNames = [];

    /** @var array<int, array<string, mixed>>|null files of the first archive listed while names were deferred */
    public ?array $firstArchiveFiles = null;

    /** @var array<string, mixed>|null summary of the last archive read in reverse while names were deferred */
    public ?array $reverseArchiveSummary = null;

    /** Real names the PAR2 index revealed for obfuscated files. */
    public ?MediaSourcePlan $mediaPlan = null;

    /** Network bytes left for finding preview sources. */
    public ?MediaByteBudget $mediaBudget = null;

    /**
     * Starts of videos stored in downloaded archive volumes, samples first.
     *
     * @var list<ArchivedMediaEntry>
     */
    public array $archivedMedia = [];

    /**
     * @var list<DownloadedArchive>
     */
    public array $downloadedArchives = [];

    private int $retainedArchiveBytes = 0;

    // Message IDs for downloading
    /**
     * @var list<string>
     */
    public array $sampleMessageIDs = [];

    /**
     * @var list<string>
     */
    public array $jpgMessageIDs = [];

    /**
     * @var list<string>|string
     */
    public string|array $mediaInfoMessageIDs = [];

    /**
     * @var list<string>|string
     */
    public string|array $audioInfoMessageIDs = [];

    /**
     * @var list<string>
     */
    public array $rarFileMessageIDs = [];

    public string $audioInfoExtension = '';

    // File info counters
    public int $addedFileInfo = 0;

    public int $totalFileInfo = 0;

    public int $compressedFilesChecked = 0;

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $pendingReleaseFiles = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $pendingParHashes = [];

    /** @var array<string, array{full_md5: string, hash16k: string, size: ?string}> */
    public array $pendingFullParHashes = [];

    /** @var array{id: int, guid: string, name: ?string, searchname: ?string, additional_pp_claim_token: ?string} */
    public readonly array $par2EvidenceSnapshot;

    public bool $fullPar2EvidenceDeferred = false;

    /** @var array{token: ?string, claimed_at: ?string}|null */
    public readonly ?array $producerNamingClaim;

    /** Naming admission requires claim checks for the lifetime of this context. */
    public bool $producerNamingAttempted = false;

    public int $producerNamingGeneration = 0;

    public bool $audioMetadataCommitted = false;

    public int $audioMetadataAdmissionGeneration = 0;

    /** @var array{id: int, guid: string, name: ?string, searchname: ?string, additional_pp_claim_token: ?string} */
    private array $currentPar2EvidenceSnapshot;

    /**
     * @var array<string, true>|null
     */
    public ?array $existingReleaseFileNames = null;

    public bool $releaseFilesChanged = false;

    // Temp path for this release
    public string $tmpPath = '';

    // Timeout tracking
    public float $startTime = 0;

    public function __construct(Release $release)
    {
        $this->release = $release;
        $attributes = $release->getAttributes();
        $this->producerNamingClaim = array_key_exists('additional_pp_claim_token', $attributes)
            && array_key_exists('additional_pp_claimed_at', $attributes)
            ? ['token' => is_string($attributes['additional_pp_claim_token']) ? $attributes['additional_pp_claim_token'] : null,
                'claimed_at' => is_string($attributes['additional_pp_claimed_at']) ? $attributes['additional_pp_claimed_at'] : null]
            : null;
        $this->par2EvidenceSnapshot = [
            'id' => (int) $release->id,
            'guid' => (string) $release->guid,
            'name' => is_string($attributes['name'] ?? null) ? $attributes['name'] : null,
            'searchname' => is_string($attributes['searchname'] ?? null) ? $attributes['searchname'] : null,
            'additional_pp_claim_token' => is_string($attributes['additional_pp_claim_token'] ?? null) ? $attributes['additional_pp_claim_token'] : null,
        ];
        $this->currentPar2EvidenceSnapshot = $this->par2EvidenceSnapshot;
        $this->startTime = hrtime(true);
    }

    /** @return array{id: int, guid: string, name: ?string, searchname: ?string, additional_pp_claim_token: ?string} */
    public function fullPar2EvidenceSnapshot(): array
    {
        return $this->currentPar2EvidenceSnapshot;
    }

    /**
     * Advance only through an identity transition attested by the guarded writer.
     *
     * @param  array{before: array{id: int, guid: string, name: ?string, searchname: ?string}, after: array{id: int, guid: string, name: ?string, searchname: ?string}}|null  $proof
     */
    public function advanceFullPar2EvidenceIdentity(?array $proof): bool
    {
        if ($proof === null || (int) $this->release->id !== $this->par2EvidenceSnapshot['id']
            || (string) $this->release->guid !== $this->par2EvidenceSnapshot['guid']) {
            return false;
        }
        foreach (['id', 'guid', 'name', 'searchname'] as $column) {
            if ($proof['before'][$column] !== $this->currentPar2EvidenceSnapshot[$column]) {
                return false;
            }
        }
        if ($proof['after']['id'] !== $this->par2EvidenceSnapshot['id'] || $proof['after']['guid'] !== $this->par2EvidenceSnapshot['guid']) {
            return false;
        }
        $this->currentPar2EvidenceSnapshot = [...$proof['after'], 'additional_pp_claim_token' => $this->par2EvidenceSnapshot['additional_pp_claim_token']];
        $this->release->name = $proof['after']['name'];
        $this->release->searchname = $proof['after']['searchname'];

        return true;
    }

    /**
     * Initialize processing state based on configuration flags.
     * Sets "found" flags to true if processing is disabled (to skip those steps).
     */
    public function initializeFromConfig(
        bool $processVideo,
        bool $processMediaInfo,
        bool $processAudioInfo,
        bool $processAudioSample,
        bool $processJPGSample,
        bool $processThumbnails
    ): void {
        $this->foundVideo = ! $processVideo;
        $this->foundMediaInfo = ! $processMediaInfo;
        $this->foundAudioInfo = ! $processAudioInfo;
        $this->foundAudioSample = ! $processAudioSample;
        $this->foundJPGSample = ! $processJPGSample;
        $this->foundSample = ! $processThumbnails;
        $this->foundPAR2Info = false;
    }

    /**
     * Reset message ID arrays for a fresh processing run.
     */
    public function resetMessageIDs(): void
    {
        $this->sampleMessageIDs = [];
        $this->jpgMessageIDs = [];
        $this->mediaInfoMessageIDs = [];
        $this->audioInfoMessageIDs = [];
        $this->rarFileMessageIDs = [];
        $this->audioInfoExtension = '';
    }

    /**
     * Reset file counters.
     */
    public function resetCounters(): void
    {
        $this->addedFileInfo = 0;
        $this->totalFileInfo = 0;
        $this->compressedFilesChecked = 0;
        $this->pendingReleaseFiles = [];
        $this->pendingParHashes = [];
        $this->pendingFullParHashes = [];
        $this->fullPar2EvidenceDeferred = false;
        $this->producerNamingGeneration++;
        $this->audioMetadataCommitted = false;
        $this->audioMetadataAdmissionGeneration++;
        $this->existingReleaseFileNames = null;
        $this->releaseFilesChanged = false;
    }

    /**
     * Full reset for processing a new release.
     */
    public function reset(): void
    {
        $this->passwordStatus = 0;
        $this->releaseHasPassword = false;
        $this->archiveListingIncomplete = false;
        $this->nzbHasCompressedFile = false;
        $this->archiveInspectionAttempted = false;
        $this->archiveListingUndecodable = false;
        $this->archiveRetryable = false;
        $this->mediaVolumeSets = [];
        $this->unplannedArchiveSeen = false;
        $this->groupUnavailable = false;
        $this->workPlan = null;
        $this->seasonPack = false;
        $this->seasonPackProbable = false;
        $this->archiveNameEvidenceIncomplete = false;
        $this->archiveFileNames = [];
        $this->archiveNamingWitnesses = [];
        $this->archiveFilenameNamingAttempts = [];
        $this->namingWitnessBytes = 0;
        $this->namingWitnessesOmitted = false;
        $this->nzbSourceDigest = null;
        $this->nzbSourceFiles = null;
        $this->archiveSourceWitnesses = [];
        $this->directVideoFiles = null;
        $this->directVideoWitnesses = [];
        $this->archiveNamingInspectionComplete = false;
        $this->firstArchiveFiles = null;
        $this->reverseArchiveSummary = null;
        $this->mediaPlan = null;
        $this->mediaBudget = null;
        $this->archivedMedia = [];
        $this->releaseDownloadedArchives();
        $this->resetMessageIDs();
        $this->resetCounters();
    }

    /**
     * Retain only small, immediately reusable archive payloads for this release.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public function rememberDownloadedArchive(string $title, string $data, array $files): bool
    {
        $byteSize = strlen($data);
        if ($byteSize === 0
            || count($this->downloadedArchives) >= self::MAX_RETAINED_ARCHIVES
            || ($this->retainedArchiveBytes + $byteSize) > self::MAX_RETAINED_ARCHIVE_BYTES
        ) {
            return false;
        }

        $archive = new DownloadedArchive($title, $data, $files);
        $this->downloadedArchives[] = $archive;
        $this->retainedArchiveBytes += $archive->byteSize();

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function rememberArchiveFileNames(array $files): void
    {
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if (! $this->rememberNamingWitness($name)) {
                return;
            }
            if (! in_array($name, $this->archiveFileNames, true)) {
                $this->archiveFileNames[] = $name;
            }
        }
    }

    public function rememberArchiveFilenameNamingAttempt(string $name, bool $proofDeferred): void
    {
        // Reuse the witness count/byte bounds; no separate unbounded name set.
        if (isset($this->archiveNamingWitnesses[$name])) {
            $this->archiveFilenameNamingAttempts[$name] = $proofDeferred;
        }
    }

    /** Admit names once, before callers retain them for naming or inventory. */
    public function rememberNamingWitness(string $name): bool
    {
        return self::$useRustFfi ? $this->rememberNamingWitnessNative($name) : $this->rememberNamingWitnessPhp($name);
    }

    /** @phpstan-impure */
    public function rememberNamingWitnessNative(string $name): bool
    {
        return $this->admitNamingWitness($name, true);
    }

    /** @phpstan-impure */
    public function rememberNamingWitnessPhp(string $name): bool
    {
        return $this->admitNamingWitness($name, false);
    }

    public function validateNamingWitnessPhp(string $name): bool
    {
        return $name !== '' && strlen($name) <= 4096 && preg_match('//u', $name) === 1
            && count($this->archiveNamingWitnesses) < self::MAX_ARCHIVE_FILE_NAMES
            && $this->namingWitnessBytes + strlen($name) <= self::MAX_NAMING_WITNESS_BYTES;
    }

    public function validateNamingWitnessNative(string $name): ?bool
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('additional',
                'int32_t nntmux_additional_validate_naming_witness(const char *input, size_t len, size_t count, size_t total);');
            if ($ffi === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $valid = $ffi->nntmux_additional_validate_naming_witness($name, strlen($name), count($this->archiveNamingWitnesses), $this->namingWitnessBytes);

            return match ($valid) {
                0 => false, 1 => true, default => null
            };
        } catch (Throwable) {
            return null;
        }
    }

    private function admitNamingWitness(string $name, bool $native): bool
    {
        if ($this->namingWitnessesOmitted) {
            return false;
        }
        if (isset($this->archiveNamingWitnesses[$name])) {
            return true;
        }
        $valid = $native ? ($this->validateNamingWitnessNative($name) ?? $this->validateNamingWitnessPhp($name)) : $this->validateNamingWitnessPhp($name);
        if (! $valid) {
            $this->namingWitnessesOmitted = true;
            $this->archiveNameEvidenceIncomplete = true;

            return false;
        }
        $this->archiveNamingWitnesses[$name] = true;
        $this->namingWitnessBytes += strlen($name);

        return true;
    }

    /**
     * Season packs read several archive sets, and no inner file name (one episode)
     * may rename the release while they are read.
     */
    public function defersArchiveNameChecks(): bool
    {
        return $this->seasonPack || $this->seasonPackProbable || $this->archiveNameEvidenceIncomplete;
    }

    /**
     * Keep at most two archived videos, so memory stays bounded; a sample displaces a main video.
     */
    public function rememberArchivedMedia(ArchivedMediaEntry $entry): bool
    {
        foreach ($this->archivedMedia as $known) {
            if ($known->name === $entry->name) {
                return false;
            }
        }

        if (count($this->archivedMedia) >= self::MAX_RETAINED_ARCHIVES) {
            $replace = $entry->isSample ? array_search(false, array_map(static fn (ArchivedMediaEntry $known): bool => $known->isSample, $this->archivedMedia), true) : false;
            if ($replace === false) {
                return false;
            }
            unset($this->archivedMedia[$replace]);
        }

        $this->archivedMedia[] = $entry;
        usort($this->archivedMedia, static fn (ArchivedMediaEntry $a, ArchivedMediaEntry $b): int => $b->isSample <=> $a->isSample);

        return true;
    }

    public function releaseDownloadedArchives(): void
    {
        $this->downloadedArchives = [];
        $this->retainedArchiveBytes = 0;
    }

    public function duplicateMessageIdCount(): int
    {
        return $this->workPlan === null ? 0 : $this->workPlan->duplicateMessageIdCount;
    }

    /**
     * @return list<string>
     */
    public function unsupportedReasons(): array
    {
        return $this->workPlan === null ? [] : $this->workPlan->unsupportedReasons;
    }

    /**
     * Mark a processing artifact as found.
     */
    public function markFound(string $type): void
    {
        match ($type) {
            'video' => $this->foundVideo = true,
            'mediaInfo' => $this->foundMediaInfo = true,
            'audioInfo' => $this->foundAudioInfo = true,
            'audioSample' => $this->foundAudioSample = true,
            'jpgSample' => $this->foundJPGSample = true,
            'sample' => $this->foundSample = true,
            'par2Info' => $this->foundPAR2Info = true,
            default => null,
        };
    }

    /**
     * True when every compressed candidate was proven to be plain media; an unread or
     * unreadable archive anywhere else in the release keeps its password status unknown.
     */
    public function compressedFilesAreMedia(): bool
    {
        return self::$useRustFfi ? ($this->compressedFilesAreMediaNative() ?? $this->compressedFilesAreMediaPhp()) : $this->compressedFilesAreMediaPhp();
    }

    public function compressedFilesAreMediaNative(): ?bool
    {
        $files = [['is_media' => $this->mediaVolumeSets !== [] && ! $this->unplannedArchiveSeen]];
        foreach ($this->workPlan->archiveCandidates ?? [] as $candidate) {
            $files[] = ['is_media' => isset($this->mediaVolumeSets[$candidate->volumeSet()])];
        }
        $result = self::nativeJson($files);

        return is_bool($result) ? $result : null;
    }

    public function compressedFilesAreMediaPhp(): bool
    {
        if ($this->mediaVolumeSets === [] || $this->unplannedArchiveSeen) {
            return false;
        }

        foreach ($this->workPlan->archiveCandidates ?? [] as $candidate) {
            if (! isset($this->mediaVolumeSets[$candidate->volumeSet()])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if more media processing is needed.
     */
    public function needsMediaProcessing(): bool
    {
        return ! $this->foundVideo
            || ! $this->foundMediaInfo
            || ! $this->foundAudioInfo
            || ! $this->foundAudioSample;
    }

    /**
     * Check if any sample is still needed.
     */
    public function needsSample(): bool
    {
        return ! $this->foundSample || ! $this->foundJPGSample;
    }

    /**
     * Check if the release has exceeded the processing timeout.
     */
    public function isTimedOut(int $timeoutSeconds): bool
    {
        if ($timeoutSeconds <= 0) {
            return false;
        }

        return (hrtime(true) - $this->startTime) / 1e9 > $timeoutSeconds;
    }

    /**
     * Get elapsed processing time in seconds.
     */
    public function getElapsedSeconds(): int
    {
        return (int) ((hrtime(true) - $this->startTime) / 1e9);
    }

    /** @param array<mixed> $input */
    private static function nativeJson(array $input): mixed
    {
        try {
            $ffi = RustPipelineLibrary::resolveDomain('additional',
                'ssize_t nntmux_additional_compressed_files_are_media_json(const char *input, size_t len, char *out, size_t cap);');
            if ($ffi === null) {
                return null;
            }
            $json = json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, strlen($json)), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (domain C ABI)
            $written = $ffi->nntmux_additional_compressed_files_are_media_json($json, strlen($json), $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (domain C ABI)
                $written = $ffi->nntmux_additional_compressed_files_are_media_json($json, strlen($json), $scratch, $cap);
            }
            if ($written < 0 || $written > $cap) {
                return null;
            }

            return json_decode(FFI::string($scratch, $written), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
