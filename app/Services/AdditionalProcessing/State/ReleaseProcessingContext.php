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
use FFI\CData;
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

    public const bool W466_RUST_FFI_DEFAULT = false;

    public const string W466_FFI_CDEF = <<<'C'
        ssize_t nntmux_w466_admit_witnesses(const char *names, size_t names_len, size_t name_count, const char *witnesses, size_t witnesses_len, size_t witness_count, const char *listed, size_t listed_len, size_t listed_count, size_t witness_bytes, char *out, size_t cap);
        C;

    private static ?bool $w466RustFfi = null;

    private static FFI|false|null $w466Ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function useW466RustFfi(?bool $enabled): void
    {
        self::$w466RustFfi = $enabled;
    }

    public static function usesW466RustFfi(): bool
    {
        return self::$w466RustFfi ?? self::W466_RUST_FFI_DEFAULT;
    }

    public static function resetW466RustFfi(): void
    {
        self::$w466RustFfi = null;
        self::$w466Ffi = null;
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
        if (! self::usesW466RustFfi() || ! $this->rememberArchiveFileNamesNative($files)) {
            $this->rememberArchiveFileNamesPhp($files);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     */
    public function rememberArchiveFileNamesPhp(array $files): void
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

    /**
     * Admit and list the names in one Rust call; false (state untouched) when PHP must
     * handle the batch: entries that are not arrays, names that are not strings or
     * contain NUL bytes, or an unavailable library.
     *
     * @param  array<int, array<string, mixed>>  $files
     */
    public function rememberArchiveFileNamesNative(array $files): bool
    {
        if ($files === [] || $this->namingWitnessesOmitted) {
            // PHP stops at the first name once omission is recorded.
            return true;
        }
        $ffi = self::$w466Ffi instanceof FFI ? self::$w466Ffi : self::w466Ffi();
        $count = \count($files);
        if ($ffi === null || \count(array_filter($files, 'is_array')) !== $count) {
            return false;
        }
        $names = array_column($files, 'name');
        if (\count($names) !== $count || \count(array_filter($names, 'is_string')) !== $count) {
            return false;
        }
        $joined = implode("\0", $names);
        $witnessCount = \count($this->archiveNamingWitnesses);
        $witnesses = implode("\0", array_keys($this->archiveNamingWitnesses));
        $listedCount = \count($this->archiveFileNames);
        $listed = implode("\0", $this->archiveFileNames);
        if (substr_count($joined, "\0") !== $count - 1
            || substr_count($witnesses, "\0") !== max(0, $witnessCount - 1)
            || substr_count($listed, "\0") !== max(0, $listedCount - 1)) {
            return false;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 21 + 2 * \strlen($joined) + 2, $cap);
            if ($scratch === null) {
                return false;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w466_admit_witnesses($joined, \strlen($joined), $count, $witnesses, \strlen($witnesses), $witnessCount, $listed, \strlen($listed), $listedCount, $this->namingWitnessBytes, $scratch, $cap);
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return false;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w466_admit_witnesses($joined, \strlen($joined), $count, $witnesses, \strlen($witnesses), $witnessCount, $listed, \strlen($listed), $listedCount, $this->namingWitnessBytes, $scratch, $cap);
            }
            if ($written < 21 || $written > $cap) {
                return false;
            }
            $result = FFI::string($scratch, $written);
        } catch (Throwable) {
            return false;
        }
        $header = unpack('Comitted/Pbytes/Vadmitted/Vappended/Vlength', $result);
        if (! \is_array($header) || 21 + $header['length'] > $written) {
            return false;
        }
        $admitted = $header['admitted'] > 0 ? explode("\0", substr($result, 21, $header['length'])) : [];
        $appended = $header['appended'] > 0 ? explode("\0", substr($result, 21 + $header['length'])) : [];
        if (\count($admitted) !== $header['admitted'] || \count($appended) !== $header['appended']) {
            return false;
        }
        $this->archiveNamingWitnesses += array_fill_keys($admitted, true);
        array_push($this->archiveFileNames, ...$appended);
        $this->namingWitnessBytes = $header['bytes'];
        if ($header['omitted'] !== 0) {
            $this->namingWitnessesOmitted = true;
            $this->archiveNameEvidenceIncomplete = true;
        }

        return true;
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

    private static function w466Ffi(): ?FFI
    {
        if (self::$w466Ffi === null) {
            self::$w466Ffi = RustPipelineLibrary::resolveDomain('additional', self::W466_FFI_CDEF) ?? false;
        }

        return self::$w466Ffi === false ? null : self::$w466Ffi;
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

    // Rust is the default only while the paired W55.6 benchmark clears 1.5x on all four metrics.
    private const bool W55_RUST_FFI_DEFAULT = true;

    public const string W55_FFI_CDEF = <<<'C'
        int32_t nntmux_w556_context_summary_batch(const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    public const int W55_CONTEXT_HEADER = 14;

    private const int W55_MAX_ITEMS = 1_048_576;

    private const int W55_COUNT_CAP = 1_048_575;

    private const int W55_SECONDS_CAP = 2_147_483_647;

    private const int W55_TOTAL_CAP = 4_611_686_018_427_387_904;

    /**
     * Summarize the processing state of many release contexts. Columns per context: flags (bit 0
     * found video, 1 media info, 2 audio info, 3 audio sample, 4 JPG sample, 5 sample, 6 PAR2 info, 7
     * passworded, 8 listing incomplete, 9 season pack, 10 probable season pack, 11 name evidence
     * incomplete; higher bits ignored), elapsed nanoseconds (clamped to 0..2^62), timeout seconds
     * (clamped to 0..2^31-1; 0 never times out), added and total file info (clamped to 0..2^20-1);
     * missing values are 0. Needs and deferral mirror needsMediaProcessing(), needsSample() and
     * defersArchiveNameChecks(); a context times out when its elapsed nanoseconds exceed the timeout.
     *
     * Layout: `[0]` count, `[1]` needing media, `[2]` needing a sample, `[3]` timed out, `[4]` deferring
     * archive names, `[5]` passworded, `[6]` listing incomplete, `[7]` needing neither, `[8]` added and
     * `[9]` total file info, `[10]` first longest context (-1 when none) and `[11]` its elapsed seconds,
     * `[12]` missing found flags, `[13]` FNV-1a digest; then per context `missing | media << 7 | sample
     * << 8 | timed_out << 9 | defers << 10 | file_ppm << 11 | seconds << 31` (missing has bit n set when
     * found flag n is not; seconds capped at 2^31-1).
     *
     * @param  array<array-key, int>  $flags
     * @param  array<array-key, int>  $elapsedNanoseconds
     * @param  array<array-key, int>  $timeoutSeconds
     * @param  array<array-key, int>  $addedFileInfo
     * @param  array<array-key, int>  $totalFileInfo
     * @return list<int>
     */
    public static function summarizeContextBatch(array $flags, array $elapsedNanoseconds, array $timeoutSeconds, array $addedFileInfo, array $totalFileInfo): array
    {
        return (self::usesW55RustFfi() ? self::summarizeContextBatchNative($flags, $elapsedNanoseconds, $timeoutSeconds, $addedFileInfo, $totalFileInfo) : null)
            ?? self::summarizeContextBatchPhp($flags, $elapsedNanoseconds, $timeoutSeconds, $addedFileInfo, $totalFileInfo);
    }

    /**
     * @param  array<array-key, int>  $flags
     * @param  array<array-key, int>  $elapsedNanoseconds
     * @param  array<array-key, int>  $timeoutSeconds
     * @param  array<array-key, int>  $addedFileInfo
     * @param  array<array-key, int>  $totalFileInfo
     * @return list<int>
     */
    public static function summarizeContextBatchPhp(array $flags, array $elapsedNanoseconds, array $timeoutSeconds, array $addedFileInfo, array $totalFileInfo): array
    {
        $flags = array_values($flags);
        $elapsedNanoseconds = array_values($elapsedNanoseconds);
        $timeoutSeconds = array_values($timeoutSeconds);
        $addedFileInfo = array_values($addedFileInfo);
        $totalFileInfo = array_values($totalFileInfo);
        $count = \count($flags);
        $countCap = self::W55_COUNT_CAP;
        $secondsCap = self::W55_SECONDS_CAP;
        $out = array_fill(0, self::W55_CONTEXT_HEADER, 0);
        $out[0] = $count;
        $longAt = -1;
        $long = 0;
        $digest = 2_166_136_261;
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $f = $flags[$i] & 0xFFF;
            $elapsed = min(max($elapsedNanoseconds[$i] ?? 0, 0), self::W55_TOTAL_CAP);
            $timeout = min(max($timeoutSeconds[$i] ?? 0, 0), $secondsCap);
            $added = min(max($addedFileInfo[$i] ?? 0, 0), $countCap);
            $total = min(max($totalFileInfo[$i] ?? 0, 0), $countCap);
            $missing = ~$f & 0x7F;
            $media = ($missing & 0x0F) !== 0 ? 1 : 0;
            $sample = ($missing & 0x30) !== 0 ? 1 : 0;
            $timedOut = $timeout > 0 && $elapsed > $timeout * 1_000_000_000 ? 1 : 0;
            $defers = ($f & 0xE00) !== 0 ? 1 : 0;
            $seconds = min(intdiv($elapsed, 1_000_000_000), $secondsCap);
            $ppm = $total > 0 ? min(intdiv($added * 1_000_000, $total), 1_000_000) : 0;
            $out[1] += $media;
            $out[2] += $sample;
            $out[3] += $timedOut;
            $out[4] += $defers;
            $out[5] += ($f >> 7) & 1;
            $out[6] += ($f >> 8) & 1;
            $out[7] += $media === 0 && $sample === 0 ? 1 : 0;
            $out[8] += $added;
            $out[9] += $total;
            if ($longAt < 0 || $elapsed > $long) {
                $longAt = $i;
                $long = $elapsed;
            }
            $bits = $missing - (($missing >> 1) & 0x55);
            $bits = ($bits & 0x33) + (($bits >> 2) & 0x33);
            $out[12] += ($bits + ($bits >> 4)) & 0x0F;
            $row = $missing | $media << 7 | $sample << 8 | $timedOut << 9 | $defers << 10 | $ppm << 11 | $seconds << 31;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[10] = $longAt;
        $out[11] = min(intdiv($long, 1_000_000_000), $secondsCap);
        $out[13] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, int>  $flags
     * @param  array<array-key, int>  $elapsedNanoseconds
     * @param  array<array-key, int>  $timeoutSeconds
     * @param  array<array-key, int>  $addedFileInfo
     * @param  array<array-key, int>  $totalFileInfo
     * @return list<int>|null
     */
    public static function summarizeContextBatchNative(array $flags, array $elapsedNanoseconds, array $timeoutSeconds, array $addedFileInfo, array $totalFileInfo): ?array
    {
        $count = \count($flags);
        if ($count > self::W55_MAX_ITEMS) {
            return null;
        }
        $ints = pack(
            'q*',
            ...self::w55Fit($flags, $count), ...self::w55Fit($elapsedNanoseconds, $count), ...self::w55Fit($timeoutSeconds, $count),
            ...self::w55Fit($addedFileInfo, $count), ...self::w55Fit($totalFileInfo, $count),
        );
        $fields = self::W55_CONTEXT_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w55Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w556_context_summary_batch($ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 55.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW55RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w55RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w55RustFfiState('set', $previous);
        }
    }

    public static function usesW55RustFfi(): bool
    {
        $forced = self::w55RustFfiState();

        return \is_bool($forced) ? $forced : self::W55_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 55.6 routing and binding, kept in function statics so earlier waves' static state stays
     * untouched. No `$op` returns the forced routing (null for the default); `'set'` replaces it with
     * `$val` and returns the previous value; `'binding'` returns the cached `additional_w55` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w55RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w55', self::W55_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 55.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w55Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w55RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * Exactly `$count` values: the list itself when it already fits, otherwise padded with 0.
     *
     * @param  array<array-key, int>  $values
     * @return list<int>
     */
    private static function w55Fit(array $values, int $count): array
    {
        return \count($values) === $count && array_is_list($values) ? $values : array_pad(\array_slice(array_values($values), 0, $count), $count, 0);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 9.30x, p99 5.94x; uncommon mean 9.10x, p99 5.61x); see RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_RELEASE_CONTEXT_FFI_CDEF = <<<'C'
        ssize_t nntmux_w653_release_context_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 65.3 batch. */
    public const int W65_RELEASE_CONTEXT_TRAILER = 27;

    /** Cap for the Wave 65.3 running totals. */
    private const int W65_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ReleaseProcessingContext Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.3 release processing state, password, completion and outcome plan with needsMediaProcessing(),
     * needsSample() and isTimedOut()'s rules. Each item is `state|passwordStatus|found|added|total|elapsed|timeout`.
     * The trimmed state is 0 empty, 1 `pending`, 2 `downloading`, 3 `extracting`, 4 `media`, 5 `finalizing`, 6 `done`,
     * 7 `failed` (any case) or 8 other. The password status is 0 empty, 1 up to 2 trimmed ASCII digits, 2 other, and
     * classed 0 unset, 1 none (0), 2 passworded (1) or 3 other. Found is a comma list of markFound() types (video,
     * mediaInfo, audioInfo, audioSample, jpgSample, sample, par2Info) plus nfo, trimmed and case-sensitive, as mask
     * bits 0-7; other non-empty entries count as unknown. Added and total file info are 0 empty, 1 up to 6 digits, 2
     * other; elapsed and timeout seconds the same with up to 9 digits. Timed out means a positive timeout below the
     * elapsed seconds; completion is the per-mille added / total (capped at 1000, 0 without a total). The outcome is 5
     * invalid (any state 2 or state 8), 4 passworded, 3 failed (state 7 or timed out), 2 complete (state 6, or nothing
     * left to find), 1 in progress (states 1-5) or 0 idle; the next state is 0 when invalid, 7 when passworded or
     * failed, 6 when complete, 1 when idle and otherwise the following state, skipping `media` when no media is
     * needed. Per item `state | next << 4 | passwordState << 8 | passwordClass << 10 | mask << 13 | min(unknown, 15) <<
     * 21 | addedState << 25 | totalState << 27 | elapsedState << 29 | timeoutState << 31 | needsMedia << 33 |
     * needsSample << 34 | timedOut << 35 | outcome << 36 | completion << 39 | remaining << 49` (timeout - elapsed
     * seconds clamped to 0..16383, 0 without a timeout). Then the six outcome counts, the nine state counts, the eight
     * found type counts, the added and total file info totals (capped at 2^62), the timed-out count and the 1-based
     * index of the first strictly longest positive elapsed time (0 when none).
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ReleaseContextPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65ReleaseContextPlanBatchNative($items) : self::w65ReleaseContextPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ReleaseContextPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_RELEASE_CONTEXT_TRAILER) ?? self::w65ReleaseContextPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65ReleaseContextPlanBatchPhp(array $items): array
    {
        $out = [];
        $outcomes = [0, 0, 0, 0, 0, 0];
        $states = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $found = [0, 0, 0, 0, 0, 0, 0, 0];
        $addedSum = $totalSum = $timedOutCount = $slowest = $slowestElapsed = $index = 0;
        foreach ($items as $item) {
            $index++;
            [$rawState, $rawPassword, $rawFound, $rawAdded, $rawTotal, $rawElapsed, $rawTimeout] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $stateText = strtolower(trim($rawState));
            $state = $stateText === '' ? 0 : (self::W65_CONTEXT_STATES[$stateText] ?? 8);
            [$passwordState, $password] = self::w65Count($rawPassword, 2);
            $passwordClass = $passwordState !== 1 ? 0 : ($password === 0 ? 1 : ($password === 1 ? 2 : 3));
            $mask = $unknown = 0;
            foreach (explode(',', $rawFound) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $bit = self::W65_FOUND_BITS[$piece] ?? null;
                if ($bit === null) {
                    $unknown++;
                } else {
                    $mask |= 1 << $bit;
                }
            }
            [$addedState, $added] = self::w65Count($rawAdded, 6);
            [$totalState, $totalFiles] = self::w65Count($rawTotal, 6);
            [$elapsedState, $elapsed] = self::w65Count($rawElapsed, 9);
            [$timeoutState, $timeout] = self::w65Count($rawTimeout, 9);
            $needsMedia = ($mask & 0b1111) !== 0b1111;
            $needsSample = ($mask & 0b110000) !== 0b110000;
            $timedOut = $timeout > 0 && $elapsed > $timeout;
            $completion = $totalFiles > 0 ? min(intdiv($added * 1000, $totalFiles), 1000) : 0;
            if ($addedState === 2 || $totalState === 2 || $elapsedState === 2 || $timeoutState === 2 || $passwordState === 2 || $state === 8) {
                $outcome = 5;
            } elseif ($passwordClass === 2) {
                $outcome = 4;
            } elseif ($state === 7 || $timedOut) {
                $outcome = 3;
            } elseif ($state === 6 || (! $needsMedia && ! $needsSample)) {
                $outcome = 2;
            } elseif ($state >= 1) {
                $outcome = 1;
            } else {
                $outcome = 0;
            }
            $next = match ($outcome) {
                5 => 0,
                4, 3 => 7,
                2 => 6,
                1 => $state + 1 === 4 && ! $needsMedia ? 5 : $state + 1,
                default => 1,
            };
            $remaining = $timeout > 0 ? min(max(0, $timeout - $elapsed), 16_383) : 0;
            $outcomes[$outcome]++;
            $states[$state]++;
            for ($bit = 0; $bit < 8; $bit++) {
                $found[$bit] += ($mask >> $bit) & 1;
            }
            $addedSum = min($addedSum + $added, self::W65_TOTAL_CAP);
            $totalSum = min($totalSum + $totalFiles, self::W65_TOTAL_CAP);
            $timedOutCount += $timedOut ? 1 : 0;
            if ($elapsed > $slowestElapsed) {
                $slowest = $index;
                $slowestElapsed = $elapsed;
            }
            $out[] = $state | $next << 4 | $passwordState << 8 | $passwordClass << 10 | $mask << 13 | min($unknown, 15) << 21 | $addedState << 25
                | $totalState << 27 | $elapsedState << 29 | $timeoutState << 31 | ($needsMedia ? 1 : 0) << 33 | ($needsSample ? 1 : 0) << 34
                | ($timedOut ? 1 : 0) << 35 | $outcome << 36 | $completion << 39 | $remaining << 49;
        }
        array_push($out, ...$outcomes, ...$states, ...$found);
        array_push($out, $addedSum, $totalSum, $timedOutCount, $slowest);

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w65Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /** Lower-cased processing state => code (empty 0, anything else 8). */
    private const array W65_CONTEXT_STATES = ['pending' => 1, 'downloading' => 2, 'extracting' => 3, 'media' => 4, 'finalizing' => 5, 'done' => 6, 'failed' => 7];

    /** markFound() type (plus nfo), case-sensitive => mask bit. */
    private const array W65_FOUND_BITS = ['video' => 0, 'mediaInfo' => 1, 'audioInfo' => 2, 'audioSample' => 3, 'jpgSample' => 4, 'sample' => 5,
        'par2Info' => 6, 'nfo' => 7];

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w653', self::W65_RELEASE_CONTEXT_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.3 ReleaseProcessingContext export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_RELEASE_CONTEXT_FFI_CDEF.)
            $written = $ffi->nntmux_w653_release_context_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }
}
