<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Enums\NzbImportStatus;
use App\Exceptions\NzbPasswordMetadataRejected;
use App\Models\Category;
use App\Models\Predb;
use App\Models\Release;
use App\Models\UsenetGroup;
use App\Services\BlacklistService;
use App\Services\Categorization\CategorizationService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\ReleaseCleaningService;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\Verification\CandidateAdmissionDeferredException;
use App\Services\Releases\Verification\CandidateCapacityException;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\NzbManifestReader;
use App\Support\RustPipelineLibrary;
use App\Support\Utf8;
use FFI;
use FFI\CData;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Service for importing NZB files into the database.
 */
class NzbImportService
{
    public const string FFI_CDEF = <<<'C'
        int64_t nntmux_nzb_derive_release_name_span(const char *path, size_t path_len);
        ssize_t nntmux_nzb_normalize_imported_subject(const char *subject, size_t subject_len, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    protected BlacklistService $blacklistService;

    protected ReleaseCleaningService $releaseCleaner;

    protected \stdClass|bool $site;

    protected mixed $crossPostt;

    protected CategorizationService $category;

    protected ReleaseDuplicateFinder $releaseDuplicateFinder;

    /**
     * List of all the group names/ids in the DB.
     *
     * @var array<string, mixed>
     */
    protected array $allGroups;

    /**
     * Was this run from the browser?
     */
    protected bool $browser;

    /**
     * Return value for browser.
     */
    protected string $retVal;

    /**
     * Guid of the current releases.
     */
    protected string $relGuid;

    protected ?int $relId = null;

    protected ?string $verificationCandidateIdentity = null;

    /** @var array{candidate_id: int, identity: string, version: int, manifest_hash: string}|null */
    protected ?array $verificationAdmission = null;

    public mixed $echoCLI;

    public NzbService $nzb;

    /**
     * Seconds to wait for another import of the same articles to finish (all metadata settings).
     */
    protected int $identityLockWaitSeconds = 30;

    /**
     * Lease for the article lock; it must outlive the whole scan, insert and NZB write
     * ({@see Lock::block()} releases it as soon as the import finishes).
     */
    protected int $identityLockSeconds = 3600;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $options = [])
    {
        $this->echoCLI = config('nntmux.echocli');
        $this->blacklistService = new BlacklistService;
        $this->category = new CategorizationService;
        $this->nzb = app(NzbService::class);
        $this->releaseCleaner = new ReleaseCleaningService;
        $this->releaseDuplicateFinder = app(ReleaseDuplicateFinder::class);
        $this->crossPostt = app(ConfigurationProvider::class)->ingestion()->crossPostHours !== '' ? app(ConfigurationProvider::class)->ingestion()->crossPostHours : 2;

        // Set properties from options
        $this->browser = isset($options['Browser']) ? (bool) $options['Browser'] : false;
        $this->retVal = '';
    }

    /**
     * Begin importing NZB files.
     *
     * @param  null|callable(array{path:string,status:NzbImportStatus,release_id:int|null,release_guid:string|null,candidate_identity:string|null,input_capture:array<string,mixed>|null}):void  $resultCallback
     *
     * @throws FileNotFoundException
     */
    public function beginImport(
        mixed $filesToProcess,
        bool $useNzbName = false,
        bool $delete = false,
        bool $deleteFailed = false,
        int $source = 1,
        ?callable $resultCallback = null,
    ): bool|string {
        // Get all the groups in the DB.
        if (! $this->getAllGroups()) {
            if ($this->browser) {
                return $this->retVal;
            }

            return false;
        }

        $start = now()->toImmutable()->format('Y-m-d H:i:s');
        $nzbsImported = $nzbsSkipped = $nzbsDuplicate = 0;

        // Convert all files to string paths and filter to only process NZB files
        $nzbFiles = [];
        foreach ($filesToProcess as $file) {
            $filePath = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
            $lowerFilePath = strtolower($filePath);
            if (Str::endsWith($lowerFilePath, '.nzb') || Str::endsWith($lowerFilePath, '.nzb.gz')) {
                $nzbFiles[] = $filePath;
            }
        }

        if (empty($nzbFiles)) {
            $this->echoOut('No NZB files found to process.');
            if ($this->browser) {
                return $this->retVal;
            }

            return false;
        }

        $totalFilesFiltered = count($filesToProcess) - count($nzbFiles);
        if ($totalFilesFiltered > 0) {
            $this->echoOut("Filtered out {$totalFilesFiltered} non-NZB files. Processing ".count($nzbFiles).' NZB files.');
        }

        $inputCapture = null;
        $reportResult = function (
            string $path,
            NzbImportStatus $status,
            ?int $releaseId = null,
            ?string $releaseGuid = null,
            ?string $candidateIdentity = null,
            ?array $inputCapture = null,
        ) use ($resultCallback): void {
            if ($inputCapture !== null) {
                // The importer owns the authoritative outcome; caller notification can be delayed or discard fields.
                app(NzbUploadManifestService::class)->recordNzbOutcome($path, $status, $releaseId, $releaseGuid,
                    candidateIdentity: $candidateIdentity, inputCapture: $inputCapture, candidateAdmission: $status === NzbImportStatus::Queued ? $this->verificationAdmission : null);
            }
            if ($resultCallback === null) {
                return;
            }

            $resultCallback([
                'path' => $path,
                'input_capture' => $inputCapture,
                'candidate_admission' => $status === NzbImportStatus::Queued ? $this->verificationAdmission : null,
                'status' => $status,
                'release_id' => $status === NzbImportStatus::Inserted ? $releaseId : null,
                'release_guid' => $status === NzbImportStatus::Inserted ? $releaseGuid : null,
                'candidate_identity' => $status === NzbImportStatus::Queued ? $candidateIdentity : null,
            ]);
        };

        // Loop over the NZB file names only.
        foreach ($nzbFiles as $nzbFilePath) {
            $inputCapture = null;
            $this->verificationAdmission = null;
            // Admission must commit before source custody can be relinquished.
            // A nested transaction cannot establish that durable boundary.
            if (config('release_verification.required', true) && DB::transactionLevel() > 0) {
                $reportResult($nzbFilePath, NzbImportStatus::MetadataDeferred, inputCapture: $inputCapture);
                $this->echoOut('Verification admission deferred by an active transaction; retaining source for retry.');
                $nzbsSkipped++;

                continue;
            }

            // Check if the file is really there.
            if (File::isFile($nzbFilePath)) {
                $this->relGuid = '';
                $this->relId = null;
                $this->verificationCandidateIdentity = null;

                $inputReservation = app(NzbUploadManifestService::class)->reserveInput($nzbFilePath);
                // Get the contents of the NZB file as a string.
                if (Str::endsWith(strtolower($nzbFilePath), '.nzb.gz')) {
                    $nzbString = unzipGzipFile($nzbFilePath);
                } else {
                    $nzbString = File::get($nzbFilePath);
                }

                if ($nzbString === false) {
                    $this->echoOut('ERROR: Unable to read: '.$nzbFilePath);
                    $reportResult($nzbFilePath, NzbImportStatus::Failed, inputCapture: $inputCapture);

                    if ($deleteFailed) {
                        app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                    }
                    $nzbsSkipped++;

                    continue;
                }

                $inputCapture = app(NzbUploadManifestService::class)->finalizeInput($nzbFilePath, $nzbString, $inputReservation);

                if ($inputReservation !== null && $inputCapture === null) {
                    $reportResult($nzbFilePath, NzbImportStatus::MetadataDeferred, inputCapture: $inputCapture);
                    $nzbsSkipped++;

                    continue;
                }

                // Load it as an XML object.
                $nzbXML = @simplexml_load_string($nzbString);
                if ($nzbXML === false || strtolower($nzbXML->getName()) !== 'nzb') {
                    $this->echoOut('ERROR: Unable to load NZB XML data: '.$nzbFilePath);
                    $reportResult($nzbFilePath, NzbImportStatus::Failed, inputCapture: $inputCapture);

                    if ($deleteFailed) {
                        app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                    }
                    $nzbsSkipped++;

                    continue;
                }

                // Legacy publication serializes identity checks and NZB writes under every metadata setting.
                // Queued verification uses its own transactional candidate-admission protections.
                $nzbFileName = $useNzbName === true ? $this->deriveReleaseNameFromNzbPath($nzbFilePath) : '';
                try {
                    $lockFingerprint = config('release_verification.required', true) ? null : NzbArticleFingerprint::fromXml($nzbXML);
                } catch (NzbArticleFingerprintUnavailable|NzbArticleFingerprintTransportFailure) {
                    $this->echoOut('Native fingerprint backend is unavailable; leaving it for a retry: '.$nzbFilePath);
                    $nzbsSkipped++;

                    continue;
                }
                $stored = false;
                $path = null;
                try {
                    if (config('release_verification.required', true)) {
                        (new NzbManifestReader)->passwordMetadata(new NzbXmlElements($nzbXML));
                    }
                    [$importStatus, $stored, $path] = $this->withArticleIdentityLock(
                        $lockFingerprint,
                        function () use ($nzbXML, $nzbFileName, $source, $nzbString): array {
                            $status = $this->scanNZBFile($nzbXML, $nzbFileName, $source);

                            return $status === NzbImportStatus::Inserted
                                ? [$status, ...$this->storeImportedNzb($nzbString)]
                                : [$status, false, null];
                        }
                    );
                } catch (NzbArticleFingerprintUnavailable|NzbArticleFingerprintTransportFailure) {
                    $this->echoOut('Native fingerprint backend is unavailable; leaving it for a retry: '.$nzbFilePath);
                    $nzbsSkipped++;

                    continue;
                } catch (LockTimeoutException) {
                    // Another worker is importing the same articles: not a failure, so never delete it.
                    $this->echoOut('Another import of the same articles is in progress; leaving it for a retry: '.$nzbFilePath);
                    $nzbsSkipped++;

                    continue;
                } catch (NzbPasswordMetadataRejected) {
                    $importStatus = NzbImportStatus::MetadataIgnored;
                } catch (Throwable $exception) {
                    Log::error('NZB import failed while scanning or inserting a release.', [
                        'path' => $nzbFilePath,
                        'exception' => $exception,
                    ]);

                    $message = 'ERROR: Problem inserting: '.$nzbFilePath;
                    if (! $this->browser) {
                        $message .= ': '.$exception->getMessage();
                    }

                    $this->echoOut($message);
                    $importStatus = NzbImportStatus::Failed;
                }

                if (in_array($importStatus, [NzbImportStatus::MetadataIgnored, NzbImportStatus::MetadataDeferred], true)) {
                    $reportResult($nzbFilePath, $importStatus, inputCapture: $inputCapture);
                    $this->echoOut($importStatus === NzbImportStatus::MetadataDeferred
                        ? 'Verification admission deferred; retaining source for retry.'
                        : 'Uploader metadata requires review; retaining source.');
                    $nzbsSkipped++;
                } elseif ($importStatus === NzbImportStatus::Queued) {
                    $reportResult($nzbFilePath, $importStatus, candidateIdentity: $this->verificationCandidateIdentity, inputCapture: $inputCapture);
                    $nzbsImported++;
                    // Without a durable capture there is no authority to retire a pathname:
                    // even a published replay can race an unrelated uploader replacement.
                    if ($delete && $inputCapture !== null) {
                        $current = Str::endsWith(strtolower($nzbFilePath), '.nzb.gz') ? unzipGzipFile($nzbFilePath) : File::get($nzbFilePath);
                        if ($current === $nzbString) {
                            app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture, retainForCompanion: true);
                        }
                    }
                } elseif ($importStatus === NzbImportStatus::Inserted) {
                    if (! $stored) {
                        $destination = $path ?? $this->relGuid;
                        $this->echoOut('ERROR: Problem compressing NZB file to: '.$destination);
                        $reportResult($nzbFilePath, NzbImportStatus::Failed, inputCapture: $inputCapture);

                        if ($deleteFailed) {
                            app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                        }
                        $nzbsSkipped++;
                    } else {
                        $reportResult($nzbFilePath, NzbImportStatus::Inserted, $this->relId, $this->relGuid, inputCapture: $inputCapture);
                        if ($this->relId !== null) {
                            try {
                                FingerprintDispatch::enqueue($this->relId);
                            } catch (Throwable $dispatchException) {
                                Log::warning('Could not queue imported release fingerprint metadata collection.', [
                                    'release_id' => $this->relId,
                                    'exception' => $dispatchException::class,
                                ]);
                            }
                        }

                        if ($delete) {
                            // Relinquish only the still-owned captured generation for staged uploads.
                            app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                        }

                        $nzbsImported++;
                    }
                } else {
                    $reportResult($nzbFilePath, $importStatus, inputCapture: $inputCapture);

                    if ($importStatus === NzbImportStatus::Duplicate) {
                        $nzbsDuplicate++;

                        if ($delete || $deleteFailed) {
                            app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                        }
                    } else {
                        if (in_array($importStatus, [NzbImportStatus::Blacklisted, NzbImportStatus::NoGroup], true)) {
                            if ($delete || $deleteFailed) {
                                app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                            }
                        } else {
                            $this->echoOut('ERROR: Failed to insert NZB!');
                            if ($deleteFailed) {
                                app(NzbUploadManifestService::class)->deleteCapturedInput($nzbFilePath, $inputCapture);
                            }
                        }

                        $nzbsSkipped++;
                    }
                }
            } else {
                $this->echoOut('ERROR: Unable to fetch: '.$nzbFilePath);
                $reportResult($nzbFilePath, NzbImportStatus::Failed, inputCapture: $inputCapture);
                $nzbsSkipped++;
            }
        }
        $this->echoOut(
            'Processed '.
            $nzbsImported.
            ' NZBs in '.
            now()->diffInSeconds($start, true).' seconds, '.
            $nzbsSkipped.
            ' NZBs were skipped, '.
            $nzbsDuplicate.
            ' were duplicates.'
        );

        if ($this->browser) {
            return $this->retVal;
        }

        return true;
    }

    /**
     * Derive a clean release name from an NZB file path by stripping the
     * trailing ".nzb"/".nzb.gz" wrapper and a known trailing media/container
     * extension (e.g. ".mkv", ".mp4"). Example:
     *   "Show - S01E07 [1080p].mkv.nzb.gz" => "Show - S01E07 [1080p]".
     */
    public function deriveReleaseNameFromNzbPath(string $path): string
    {
        // Retain native PHP in production: 1.45x-1.52x mean / 1.40x-1.46x p99 speedup is
        // slightly below the strict >= 1.5x p99 adoption gate across both workloads.
        return $this->deriveReleaseNameFromNzbPathPhp($path);
    }

    public function deriveReleaseNameFromNzbPathPhp(string $path): string
    {
        return self::w56ReleaseName($path);
    }

    public static function deriveReleaseNameFromNzbPathNative(string $path): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $span = $ffi->nntmux_nzb_derive_release_name_span($path, \strlen($path));
            if (! \is_int($span) || $span < 0) {
                return null;
            }

            $start = $span >> 32;
            $len = $span & 0xFFFFFFFF;

            return $len === 0 ? '' : \substr($path, $start, $len);
        } catch (Throwable) {
            return null;
        }
    }

    protected function writeCompressedNzb(string $path, string $contents): bool
    {
        // Write next to the destination and rename into place, so a crash mid-write never leaves a
        // truncated gzip at the final path (which would look like a stored NZB).
        $temporaryPath = NzbService::temporaryNzbPathFor($path);
        $handle = @gzopen($temporaryPath, 'w5');
        if ($handle === false) {
            Log::error('Unable to open imported NZB destination for writing.', ['path' => $path]);

            return false;
        }

        $stored = false;

        try {
            $written = gzwrite($handle, $contents);
            if ($written !== \strlen($contents) || ! gzclose($handle)) {
                Log::error('Unable to write the complete imported NZB file.', ['path' => $path]);

                return false;
            }

            $handle = null;
            if (! $this->moveCompressedNzbIntoPlace($temporaryPath, $path)) {
                Log::error('Unable to move the imported NZB file into place.', ['path' => $path]);

                return false;
            }

            $stored = File::isFile($path);

            return $stored;
        } catch (Throwable $exception) {
            Log::error('Imported NZB compression failed.', [
                'path' => $path,
                'exception' => $exception,
            ]);

            return false;
        } finally {
            if (\is_resource($handle)) {
                @gzclose($handle);
            }
            if (File::isFile($temporaryPath)) {
                File::delete($temporaryPath);
            }
        }
    }

    protected function moveCompressedNzbIntoPlace(string $temporaryPath, string $finalPath): bool
    {
        return DB::transaction(function () use ($temporaryPath, $finalPath): bool {
            $release = DB::table('releases')->where('id', $this->relId)->where('guid', $this->relGuid)
                ->lockForUpdate()->first(['id', 'guid']);
            if ($release === null) {
                throw new \RuntimeException('Imported release changed before NZB installation.');
            }
            $receipt = (new NzbSourceGuard($this->nzb))->install($release, $temporaryPath, $finalPath);
            DB::afterRollBack(static fn () => $receipt->rollback());
            DB::afterCommit(static fn () => $receipt->close());

            return true;
        });
    }

    /**
     * Scan and process an NZB file.
     *
     * @throws \Exception
     */
    protected function scanNZBFile(mixed &$nzbXML, mixed $nzbFileName = '', mixed $source = ''): NzbImportStatus
    {
        $binary_names = [];
        $totalFiles = $totalSize = $groupID = 0;
        $isBlackListed = $groupName = $firstName = $posterName = $postDate = false;

        // Go through the NZB, get the details, look if it's blacklisted, look if we have the groups.
        $elements = new NzbXmlElements($nzbXML);
        foreach ($elements->files() as $file) {
            $binary_names[] = $file->attributes()['subject'];
            $totalFiles++;
            $groupID = -1;

            // Get the nzb info.
            if ($firstName === false) {
                $firstName = (string) $file->attributes()->subject;
            }
            if ($posterName === false) {
                $posterName = (string) $file->attributes()->poster;
            }
            if ($postDate === false) {
                $postDate = Carbon::createFromTimestamp((string) $file->attributes()->date, date_default_timezone_get())->format('Y-m-d H:i:s');
            }

            // Make a fake message array to use to check the blacklist.
            $msg = ['Subject' => (string) $file->attributes()->subject, 'From' => (string) $file->attributes()->poster, 'Message-ID' => ''];

            // Get the group names, group_id, check if it's blacklisted.
            $groupArr = [];
            foreach ($elements->groups($file) as $group) {
                $group = $this->normalizeGroupName($group);

                // If group_id is -1 try to get a group_id.
                if ($groupID === -1) {
                    if (array_key_exists($group, $this->allGroups)) {
                        $groupID = $this->allGroups[$group];
                        if (! $groupName) {
                            $groupName = $group;
                        }
                    } else {
                        $group = UsenetGroup::isValidGroup($group);
                        if ($group !== false) {
                            $groupID = UsenetGroup::addGroup([
                                'name' => $group,
                                'description' => 'Added by NZBimport script.',
                                'backfill_target' => 1,
                                'minfilestoformrelease' => '',
                                'minsizetoformrelease' => '',
                                'first_record' => 0,
                                'last_record' => 0,
                                'active' => 0,
                                'backfill' => 0,
                            ]);
                            $this->allGroups[$group] = $groupID;

                            $this->echoOut("Adding missing group: ($group)");
                        }
                    }
                }
                // Add all the found groups to an array.
                $groupArr[] = $group;

                // Check if this NZB is blacklisted (only if group is valid).
                if ($group !== false && $this->blacklistService->isBlackListed($msg, $group)) {
                    $isBlackListed = true;
                    break;
                }
            }

            // If we found a group and it's not blacklisted.
            if ($groupID !== -1 && ! $isBlackListed) {
                // Get the size of the release.
                foreach ($elements->segments($file) as $segment) {
                    $totalSize += (int) $segment->attributes()->bytes;
                }
            } else {
                if ($isBlackListed) {
                    $errorMessage = 'Subject is blacklisted: '.mb_convert_encoding(trim($firstName), 'UTF-8', mb_list_encodings());
                } else {
                    $errorMessage = 'No group found for '.$firstName.' (one of '.implode(', ', $groupArr).' are missing';
                }
                $this->echoOut($errorMessage);

                // Persist blacklist usage stats if we matched any rule during this NZB processing
                $this->blacklistService->updateBlacklistUsage($this->blacklistService->getAndClearIdsToUpdate()); // @phpstan-ignore argument.type

                return $isBlackListed ? NzbImportStatus::Blacklisted : NzbImportStatus::NoGroup;
            }
        }

        // After scanning all files, persist any matched whitelist/blacklist usage
        $this->blacklistService->updateBlacklistUsage($this->blacklistService->getAndClearIdsToUpdate()); // @phpstan-ignore argument.type

        // Try to insert the NZB details into the DB.
        return $this->insertNZB(
            [
                'subject' => $firstName,
                'useFName' => $nzbFileName,
                'postDate' => empty($postDate) ? now()->format('Y-m-d H:i:s') : $postDate,
                'from' => empty($posterName) ? '' : $posterName,
                'groups_id' => $groupID,
                'groupName' => $groupName,
                'totalFiles' => $totalFiles,
                'totalSize' => $totalSize,
                'nzbCategoryId' => $this->resolveNzbCategoryId($nzbXML),
                'articleFingerprint' => NzbArticleFingerprint::fromXml($nzbXML),
                'verificationXml' => config('release_verification.required', true) ? $nzbXML->asXML() : null,
                'articleXml' => $nzbXML,
            ]
        );
    }

    /**
     * Record the article fingerprint of a just-inserted release; if that fails, remove the release
     * so it is not left marked as having an NZB that was never stored.
     *
     * @throws Throwable
     */
    protected function persistArticleFingerprint(int $releaseId, ?string $fingerprint): void
    {
        try {
            ReleaseArticleFingerprintStore::store($releaseId, $fingerprint);
        } catch (Throwable $exception) {
            // Delete through the model so ReleaseObserver also removes the search document.
            Release::query()->find($releaseId)?->delete();

            throw $exception;
        }
    }

    /**
     * Store the compressed NZB for the release just inserted; on failure remove the release so its
     * fingerprint is never left without an NZB.
     *
     * @return array{0: bool, 1: ?string} Whether the NZB was stored, and its path when known
     */
    protected function storeImportedNzb(string $nzbString): array
    {
        $path = null;
        try {
            if (DatabaseNzbStore::enabled()) {
                $release = (object) ['id' => $this->relId, 'guid' => $this->relGuid];
                app(DatabaseNzbStore::class)->put($release, $nzbString);

                return [true, NzbService::databaseReference($this->relGuid)];
            }
            $path = $this->nzb->getNzbPath($this->relGuid, 0, true);
            $stored = DB::transaction(function () use ($path, $nzbString): bool {
                $release = Release::query()->where('id', $this->relId)->where('guid', $this->relGuid)->lockForUpdate()->first();
                if ($release === null) {
                    throw new \RuntimeException('Imported release disappeared before storage.');
                }
                $stored = $this->writeCompressedNzb($path, $nzbString);
                if (! $stored && (new NzbSourceGuard($this->nzb))->snapshot($this->relGuid) === null) {
                    // Preserve any generation another writer already retained for this row.
                    $release->delete();
                }

                return $stored;
            });
        } catch (Throwable $exception) {
            Log::error('NZB import failed while storing the compressed file.', [
                'guid' => $this->relGuid,
                'path' => $path,
                'exception' => $exception,
            ]);
            $stored = false;
        }

        return [$stored, $path];
    }

    /** Compare complete article sets; metadata only nominates existing releases. */
    protected function findIdenticalArticleUpload(int $totalSize, ?string $fingerprint, ?\SimpleXMLElement $incoming = null, string $name = '', string $searchName = '', int $predbId = 0): ?Release
    {
        return (new NzbArticleCoverage($this->nzb, $this->releaseDuplicateFinder))->findCoveredUpload($totalSize, $fingerprint, $incoming, $name, $searchName, $predbId);
    }

    protected function resolveNzbCategoryId(mixed $nzbXML): ?int
    {
        if (! $nzbXML instanceof \SimpleXMLElement) {
            return null;
        }

        $categoryMetadata = [];
        foreach ((new NzbXmlElements($nzbXML))->metadata() as $meta) {
            if (mb_strtolower(trim((string) $meta->attributes()['type'])) !== 'category') {
                continue;
            }

            $value = trim((string) $meta);
            if ($value !== '') {
                $categoryMetadata[] = $value;
            }
        }

        if ($categoryMetadata === []) {
            return null;
        }

        $activeCategories = Category::query()
            ->where('status', Category::STATUS_ACTIVE)
            ->get(['id', 'title']);
        $resolvedCategoryIds = [];

        foreach ($categoryMetadata as $value) {
            if (ctype_digit($value)) {
                $matchingCategories = $activeCategories->filter(
                    static fn (Category $category): bool => $category->id === (int) $value
                );
            } else {
                $normalizedValue = mb_strtolower($value);
                $matchingCategories = $activeCategories->filter(
                    static fn (Category $category): bool => mb_strtolower($category->title) === $normalizedValue
                );
            }

            if ($matchingCategories->count() !== 1) {
                return null;
            }

            $resolvedCategoryIds[] = (int) $matchingCategories->first()->id;
        }

        $resolvedCategoryIds = array_values(array_unique($resolvedCategoryIds));

        return count($resolvedCategoryIds) === 1 ? $resolvedCategoryIds[0] : null;
    }

    /**
     * Serialise the identity check, insert and NZB file write for one set of articles, so two
     * concurrent imports of the same NZB cannot both pass {@see findIdenticalArticleUpload()}
     * and a fingerprint is never visible without its stored NZB (all metadata settings).
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     *
     * @throws LockTimeoutException When another import holds the lock for longer than the wait.
     * @throws Throwable When the identity callback or cache backend fails.
     */
    protected function withArticleIdentityLock(?string $fingerprint, callable $callback): mixed
    {
        if ($fingerprint === null) {
            return $callback();
        }

        $store = Cache::store((string) config('nntmux.release_dedupe_lock_store', 'database'))->getStore();
        if (! $store instanceof LockProvider) {
            throw new \RuntimeException('The release_dedupe_lock_store cache store does not support locks.');
        }

        return $store->lock('nzb-import-article:'.$fingerprint, $this->identityLockSeconds)
            ->block($this->identityLockWaitSeconds, $callback);
    }

    /**
     * Insert the NZB details into the database.
     *
     * @throws \Exception
     */
    protected function insertNZB(mixed $nzbDetails): NzbImportStatus
    {
        if (config('release_verification.required', true)) {
            if (! is_string($nzbDetails['verificationXml'] ?? null)) {
                throw new \RuntimeException('NZB must be queued for download verification before publication.');
            }
            $subject = $this->normalizeImportedSubject((string) $nzbDetails['subject']);
            $info = (object) ['groups_id' => $nzbDetails['groups_id'],
                'searchname' => $nzbDetails['useFName'] !== '' ? $nzbDetails['useFName'] : $subject,
                'fromname' => $nzbDetails['from'], 'postdate' => $nzbDetails['postDate'], 'metadata_source' => 'import',
                'import_name_override' => $nzbDetails['useFName'] !== '', 'original_subject' => $subject];
            $categoryId = $nzbDetails['nzbCategoryId'] ?? null;
            if (is_int($categoryId) && $categoryId > 0
                && Category::query()->where('id', $categoryId)->where('status', Category::STATUS_ACTIVE)->exists()) {
                $info->categories_id = $categoryId;
            }
            try {
                $manifest = app(NzbManifestReader::class)->read($nzbDetails['verificationXml'], $info);
            } catch (NzbPasswordMetadataRejected) {
                return NzbImportStatus::MetadataIgnored;
            }
            $manifest['identity'] = sha1('import:'.($nzbDetails['articleFingerprint'] ?? $nzbDetails['verificationXml']));
            try {
                $admission = app(CandidateRepository::class)->enqueueWithAdmission($manifest);
                $admitted = $admission !== null;
                $this->verificationAdmission = $admission?->toArray();
            } catch (CandidateCapacityException|CandidateAdmissionDeferredException) {
                return NzbImportStatus::MetadataDeferred;
            } catch (Throwable) {
                try {
                    Log::warning('NZB verification admission deferred.', [
                        'category' => 'unexpected_admission_failure',
                    ]);
                } catch (Throwable) {
                    // A diagnostic failure must not relinquish the retained upload.
                }

                return NzbImportStatus::MetadataDeferred;
            }
            if (! $admitted) {
                return NzbImportStatus::MetadataIgnored;
            }
            $this->verificationCandidateIdentity = $manifest['identity'];
            $this->echoOut('NZB queued for download verification.');

            return NzbImportStatus::Queued;
        }

        // Make up a GUID for the release.
        $this->relGuid = Str::uuid()->toString();

        $subject = $this->normalizeImportedSubject((string) $nzbDetails['subject']);

        $renamed = 0;
        $cleanedMeta = null;
        if ($nzbDetails['useFName'] !== '') {
            $cleanName = $nzbDetails['useFName'];
            $renamed = 1;
        } else {
            $cleanedMeta = $this->releaseCleaner->releaseCleaner($subject, $nzbDetails['from'], $nzbDetails['groupName']);
            if (\is_array($cleanedMeta)) {
                $cleanName = $cleanedMeta['cleansubject'] ?? $subject;
                $renamed = (isset($cleanedMeta['properlynamed']) && $cleanedMeta['properlynamed'] === true) ? 1 : 0;
            } else {
                $cleanName = \is_string($cleanedMeta) ? $cleanedMeta : $subject;
            }
        }

        if (! \is_string($cleanName)) {
            $cleanName = $subject;
        }

        $preIdFromCleaner = 0;
        if (\is_array($cleanedMeta) && isset($cleanedMeta['predb']) && (int) $cleanedMeta['predb'] > 0) {
            $preIdFromCleaner = (int) $cleanedMeta['predb'];
        }

        $predbIdInt = $preIdFromCleaner;
        if ($predbIdInt === 0 && $cleanName !== '') {
            $preMatch = Predb::matchPre($cleanName);
            if ($preMatch !== false) {
                $cleanName = $preMatch['title'];
                $predbIdInt = (int) $preMatch['predb_id'];
                $renamed = 1;
            }
        }

        $escapedSubject = $subject;
        $escapedFromName = $nzbDetails['from'];
        $escapedSearchName = Utf8::clean($cleanName);
        if ($escapedSearchName === '') {
            $escapedSearchName = $escapedSubject;
        }

        $dupeCheck = $this->findIdenticalArticleUpload(
            (int) $nzbDetails['totalSize'],
            $nzbDetails['articleFingerprint'] ?? null,
            $nzbDetails['articleXml'] ?? null,
            $escapedSubject,
            $escapedSearchName,
            $predbIdInt
        );
        $dupeReason = $dupeCheck !== null ? 'covered_articles' : null;

        if ($dupeCheck !== null) {
            Log::info('NZB import skipped as duplicate', [
                'reason' => $dupeReason,
                'matched_release_id' => $dupeCheck->id,
                'new_searchname' => $escapedSearchName,
                'existing_searchname' => $dupeCheck->searchname,
                'new_size' => (int) $nzbDetails['totalSize'],
                'existing_size' => (int) $dupeCheck->size,
                'new_fromname' => $escapedFromName,
                'existing_fromname' => $dupeCheck->fromname,
                'new_name' => $escapedSubject,
                'existing_name' => $dupeCheck->name,
            ]);
            $this->echoOut('This release is already in our DB so skipping: '.$subject);

            return NzbImportStatus::Duplicate;
        }

        $categoryId = $nzbDetails['nzbCategoryId'];
        if (! \is_int($categoryId)) {
            $determinedCategory = $this->category->determineCategory($nzbDetails['groups_id'], $cleanName, $escapedFromName);
            $categoryId = (int) $determinedCategory['categories_id'];
        }

        $relID = Release::insertRelease(
            [
                'name' => $escapedSubject,
                'searchname' => $escapedSearchName,
                'totalpart' => $nzbDetails['totalFiles'],
                'groups_id' => $nzbDetails['groups_id'],
                'guid' => $this->relGuid,
                'postdate' => $nzbDetails['postDate'],
                'fromname' => $escapedFromName,
                'size' => $nzbDetails['totalSize'],
                'categories_id' => $categoryId,
                'isrenamed' => $renamed,
                'predb_id' => $predbIdInt,
                'nzbstatus' => NzbService::NZB_ADDED,
            ]
        );

        if ($relID === null) {
            $this->echoOut('ERROR: Problem inserting: '.$subject);

            return NzbImportStatus::Failed;
        }

        $this->relId = (int) $relID;
        $this->persistArticleFingerprint($this->relId, $nzbDetails['articleFingerprint'] ?? null);

        return NzbImportStatus::Inserted;
    }

    public function normalizeImportedSubject(string $subject): string
    {
        if (self::$useRustFfi) {
            $native = self::normalizeImportedSubjectNative($subject);
            if ($native !== null) {
                return $native;
            }
        }

        return $this->normalizeImportedSubjectPhp($subject);
    }

    public function normalizeImportedSubjectPhp(string $subject): string
    {
        $partLess = preg_replace('/(\(\d+\/\d+\))*$/', 'yEnc', $subject) ?? $subject;

        return mb_convert_encoding(trim(preg_replace('/yEnc.*$/i', 'yEnc', $partLess) ?? $partLess), 'UTF-8', mb_list_encodings());
    }

    public static function normalizeImportedSubjectNative(string $subject): ?string
    {
        if (! self::$useRustFfi) {
            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $len = \strlen($subject);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 16), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_normalize_imported_subject($subject, $len, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nzb_normalize_imported_subject($subject, $len, $scratch, $cap);
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }

    protected function normalizeGroupName(\SimpleXMLElement|string $group): string
    {
        return trim((string) $group);
    }

    /**
     * Get all groups in the DB.
     */
    protected function getAllGroups(): bool
    {
        $this->allGroups = [];
        $groups = UsenetGroup::query()->get(['id', 'name']);

        if ($groups instanceof \Traversable) {
            foreach ($groups as $group) {
                $this->allGroups[$group['name']] = $group['id'];
            }
        }

        if (\count($this->allGroups) === 0) {
            $this->echoOut('You have no groups in your database!');

            return false;
        }

        return true;
    }

    /**
     * Echo message to browser or CLI.
     */
    protected function echoOut(string $message): void
    {
        if ($this->browser) {
            $this->retVal .= $message.'<br />';
        } elseif ($this->echoCLI) {
            cli()->notice($message);
        }
    }

    /**
     * Default for the Wave 41.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php is below 1.5x.
     */
    public const bool W412_RUST_FFI_DEFAULT = false;

    private static ?bool $useW412RustFfi = null;

    public static function useW412RustFfi(bool $enable = true): void
    {
        self::$useW412RustFfi = $enable;
    }

    public static function usesW412RustFfi(): bool
    {
        return self::$useW412RustFfi ?? self::W412_RUST_FFI_DEFAULT;
    }

    public static function resetW412RustFfi(): void
    {
        self::$useW412RustFfi = null;
    }

    /** NzbImportStatus values in the Wave 41.2 output order; the Rust export matches these bytes exactly. */
    public const array W412_IMPORT_STATUSES = ['inserted', 'queued', 'metadata_ignored', 'metadata_deferred', 'duplicate', 'blacklisted', 'nogroup', 'failed'];

    /**
     * Tally NZB import results (`status`, `bytes`). A status is an NzbImportStatus or its exact string value; anything
     * else counts as unknown. Bytes count only as integers on inserted results, negatives as zero, saturating at PHP_INT_MAX.
     *
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, inserted: int, queued: int, metadata_ignored: int, metadata_deferred: int, duplicate: int, blacklisted: int, nogroup: int, failed: int, unknown: int, inserted_bytes: int}
     */
    public static function summarizeImportStatusBatch(array $results): array
    {
        return (self::usesW412RustFfi() ? self::summarizeImportStatusBatchNative($results) : null)
            ?? self::summarizeImportStatusBatchPhp($results);
    }

    /**
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, inserted: int, queued: int, metadata_ignored: int, metadata_deferred: int, duplicate: int, blacklisted: int, nogroup: int, failed: int, unknown: int, inserted_bytes: int}
     */
    public static function summarizeImportStatusBatchPhp(array $results): array
    {
        $counts = array_fill_keys(self::W412_IMPORT_STATUSES, 0);
        $unknown = $bytes = 0;
        foreach ($results as $result) {
            [$status, $size] = self::importStatusFields($result);
            if ($status === null || ! isset($counts[$status])) {
                $unknown++;

                continue;
            }
            $counts[$status]++;
            if ($status === 'inserted') {
                $size = max(0, $size);
                $bytes = $bytes > PHP_INT_MAX - $size ? PHP_INT_MAX : $bytes + $size;
            }
        }

        return self::importStatusSummary(\count($results), array_values($counts), $unknown, $bytes);
    }

    /**
     * @param  array<array-key, mixed>  $results
     * @return array{results: int, inserted: int, queued: int, metadata_ignored: int, metadata_deferred: int, duplicate: int, blacklisted: int, nogroup: int, failed: int, unknown: int, inserted_bytes: int}|null
     */
    public static function summarizeImportStatusBatchNative(array $results): ?array
    {
        $words = [0, \count($results)];
        $texts = [];
        $size = 16 + \count($results) * 16;
        foreach ($results as $result) {
            [$status, $bytes] = self::importStatusFields($result);
            $words[] = $status === null ? -1 : \strlen($status);
            $words[] = $bytes;
            if ($status !== null) {
                $texts[] = $status;
                $size += \strlen($status);
            }
            if ($size > DirectorySyncBridge::W412_MAX_INPUT) {
                return null;
            }
        }
        $slots = DirectorySyncBridge::evaluateW412Native(4, pack('q*', ...$words).implode('', $texts), 11);
        if ($slots === null) {
            return null;
        }
        $count = $slots[0];
        $counts = \array_slice($slots, 1, 8);
        if ($count !== \count($results) || min($slots) < 0 || array_sum($counts) + $slots[9] !== $count) {
            return null;
        }

        return self::importStatusSummary($count, $counts, $slots[9], $slots[10]);
    }

    /** @return array{string|null, int} the status text (null unless an NzbImportStatus or string) and the bytes (0 unless an integer) */
    private static function importStatusFields(mixed $result): array
    {
        if (! \is_array($result)) {
            return [null, 0];
        }
        $status = $result['status'] ?? null;
        $bytes = $result['bytes'] ?? 0;

        return [$status instanceof NzbImportStatus ? $status->value : (\is_string($status) ? $status : null), \is_int($bytes) ? $bytes : 0];
    }

    /**
     * @param  list<int>  $counts  per W412_IMPORT_STATUSES entry
     * @return array{results: int, inserted: int, queued: int, metadata_ignored: int, metadata_deferred: int, duplicate: int, blacklisted: int, nogroup: int, failed: int, unknown: int, inserted_bytes: int}
     */
    private static function importStatusSummary(int $results, array $counts, int $unknown, int $bytes): array
    {
        /** @var array{results: int, inserted: int, queued: int, metadata_ignored: int, metadata_deferred: int, duplicate: int, blacklisted: int, nogroup: int, failed: int, unknown: int, inserted_bytes: int} */
        return ['results' => $results, ...array_combine(self::W412_IMPORT_STATUSES, $counts), 'unknown' => $unknown, 'inserted_bytes' => $bytes];
    }

    /** Wave 56.2 paired benchmarks decide this default; see RustDirectorySyncCoverageCreationImportNzbServiceW56ParityTest. */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = <<<'C'
        intptr_t nntmux_w56_import_file_plan_batch(const char *paths, size_t paths_len, const char *heads, size_t heads_len, const char *ints, size_t ints_len, size_t count, int64_t batch_size, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 56 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w56', self::W56_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w56RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planImportFileBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w56Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w56Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch import file plan over NZB paths and their leading bytes. Per file: `flags |
     * min(release name length, 0xFFFFFF) << 8 | crc32(release name) << 32` (the name
     * deriveReleaseNameFromNzbPathPhp() gives) and the import batch (importable ordinal /
     * max(1, $batchSize)) of importable files (-1 otherwise). Flags: 1 `.nzb` basename, 2
     * `.nzb.gz` basename (both case-insensitive), 4 gzip magic, 8 XML head (`<` after an
     * optional UTF-8 BOM and spaces, tabs or line breaks), 16 extension/content mismatch (a
     * `.nzb.gz` without gzip magic or a `.nzb` with it), 32 repeats an earlier non-empty release
     * name, 64 importable (an NZB extension, gzip or XML content, no mismatch, a new non-empty
     * name). NZB files, gzip heads, importable files, duplicates, batches and the XXH64 digest
     * of all earlier words follow.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function planImportFileBatch(array $paths, array $heads, int $batchSize = 100): array
    {
        return self::usesW56RustFfi() ? self::planImportFileBatchNative($paths, $heads, $batchSize) : self::planImportFileBatchPhp($paths, $heads, $batchSize);
    }

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function planImportFileBatchPhp(array $paths, array $heads, int $batchSize = 100): array
    {
        $size = max(1, $batchSize);
        $heads = array_values($heads);
        $out = $seen = [];
        $nzb = $gzip = $importable = $duplicates = 0;
        foreach (array_values($paths) as $i => $path) {
            $head = $heads[$i] ?? '';
            $base = strtolower(basename($path));
            $gz = str_ends_with($base, '.nzb.gz');
            $plain = ! $gz && str_ends_with($base, '.nzb');
            $magic = str_starts_with($head, "\x1f\x8b");
            $xml = str_starts_with(ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head, " \t\n\r"), '<');
            $mismatch = ($gz && ! $magic) || ($plain && $magic);
            $name = self::w56ReleaseName($path);
            $duplicate = $name !== '' && isset($seen[$name]);
            if ($name !== '') {
                $seen[$name] = true;
            }
            $ok = ($gz || $plain) && ($magic || $xml) && ! $mismatch && $name !== '' && ! $duplicate;
            $flags = ($plain ? 1 : 0) | ($gz ? 2 : 0) | ($magic ? 4 : 0) | ($xml ? 8 : 0) | ($mismatch ? 16 : 0) | ($duplicate ? 32 : 0) | ($ok ? 64 : 0);
            $nzb += $gz || $plain ? 1 : 0;
            $gzip += $magic ? 1 : 0;
            $duplicates += $duplicate ? 1 : 0;
            array_push($out, $flags | min(\strlen($name), 0xFFFFFF) << 8 | crc32($name) << 32, $ok ? intdiv($importable, $size) : -1);
            $importable += $ok ? 1 : 0;
        }
        array_push($out, $nzb, $gzip, $importable, $duplicates, $importable > 0 ? intdiv($importable - 1, $size) + 1 : 0);
        $out[] = self::w56Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, PCRE's character tables are not
     * the default (C) ones, a path holds a NUL byte or the columns are not lists of one length.
     *
     * @param  list<string>  $paths
     * @param  list<string>  $heads
     * @return list<int>
     */
    public static function planImportFileBatchNative(array $paths, array $heads, int $batchSize = 100): array
    {
        $count = \count($paths);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 || \count($heads) !== $count ? null : self::w56RustFfiState('out', $words);
        if ($state === null || ! array_is_list($paths) || ! array_is_list($heads) || ! NzbParserService::pcreUsesDefaultTables()) {
            return self::planImportFileBatchPhp($paths, $heads, $batchSize);
        }
        $blob = implode("\0", $paths);
        $headBlob = implode('', $heads);
        $lengths = pack('P*', ...array_map(strlen(...), $heads));
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w56_import_file_plan_batch($blob, \strlen($blob), $headBlob, \strlen($headBlob), $lengths, \strlen($lengths), $count, $batchSize, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w56Words($written, $out, $words) ?? self::planImportFileBatchPhp($paths, $heads, $batchSize);
    }

    private static function w56ReleaseName(string $path): string
    {
        $name = basename($path);

        // Strip trailing .nzb or .nzb.gz (case-insensitive).
        $name = preg_replace('/\.nzb(\.gz)?$/i', '', $name) ?? $name;

        // Strip one trailing known media/container extension from the allow-list.
        $name = preg_replace(
            '/\.(mkv|mp4|avi|mov|wmv|flv|m4v|ts|webm|mpg|mpeg|iso|m2ts|ogm|divx|vob)$/i',
            '',
            $name
        ) ?? $name;

        // Tidy up any trailing dots/whitespace left behind.
        return rtrim($name, ". \t\n\r\0\x0B");
    }
}
