<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Collection;
use App\Models\Release;
use App\Models\ReleaseNzbCreationFailure;
use App\Services\Binaries\BinariesConfig;
use App\Services\CollectionCleanupService;
use App\Services\Configuration\ConfigurationProvider;
use App\Services\Releases\Fingerprint\FingerprintDispatch;
use App\Support\Data\NzbCreationResult;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Published NZB storage with a database backend and a legacy filesystem rollback backend.
 */
class NzbService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_nzb_groups_from_xref(const char *xref, size_t xref_len, char *out_buf, size_t out_cap);
        int32_t nntmux_nzb_is_temporary_path_name(const char *path, size_t path_len);
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

    /** The Wave 40.2 export shared by the NZB service, parser, contents, manifest reader and write service helpers. */
    public const string W402_FFI_CDEF = <<<'C'
        int64_t nntmux_w40_nzb_service_parser_manifest_write_contents(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W402_FFI_DOMAIN = 'nfo_w402';

    /**
     * Default for the Wave 40.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbServiceParserManifestWriteContentsW40ParityTest.php is below 1.5x.
     */
    public const bool W402_RUST_FFI_DEFAULT = false;

    public const int W402_MAX_INPUT = 16777216;

    private static ?bool $useW402RustFfi = null;

    public static function useW402RustFfi(?bool $use): void
    {
        self::$useW402RustFfi = $use;
    }

    public static function usesW402RustFfi(): bool
    {
        return self::$useW402RustFfi ?? self::W402_RUST_FFI_DEFAULT;
    }

    public static function resetW402RustFfi(): void
    {
        self::$useW402RustFfi = null;
    }

    /**
     * Run one Wave 40.2 operation; null whenever the library, input or answer is unusable, so callers fall back to PHP.
     *
     * @return list<int>|null
     */
    public static function evaluateW402Native(int $operation, string $input, int $slots): ?array
    {
        try {
            if ($operation < 0 || $slots < 1 || $input === '' || \strlen($input) > self::W402_MAX_INPUT) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain(self::W402_FFI_DOMAIN, self::W402_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $needed = $slots * 8;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $output = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w40_nzb_service_parser_manifest_write_contents($operation, 0, $input, \strlen($input), $output, $capacity);
                if (! \is_int($written) || $written === -1 || $written > $capacity) {
                    return null;
                }
                if ($written < 0) {
                    // Too small: retry once with the reported capacity.
                    $needed = -$written;

                    continue;
                }
                if ($written !== $slots * 8) {
                    return null;
                }
                $values = array_values(unpack('q'.$slots, FFI::string($output, $written)) ?: []);

                return \count($values) === $slots ? $values : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Summarize NZB storage inventory entries (`guid`, `bytes`). A non-empty string guid is present, anything else is missing;
     * bytes count only as integers, negatives as zero. Every entry is either gzip-sized (bytes >= max(0, $minGzipBytes)) or
     * undersized, and the compressed bytes sum, saturating at PHP_INT_MAX.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, valid_gzip: int, undersized: int, missing_guid: int, total_compressed_bytes: int}
     */
    public static function summarizeNzbStorageInventory(array $entries, int $minGzipBytes = 64): array
    {
        return (self::usesW402RustFfi() ? self::summarizeNzbStorageInventoryNative($entries, $minGzipBytes) : null)
            ?? self::summarizeNzbStorageInventoryPhp($entries, $minGzipBytes);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, valid_gzip: int, undersized: int, missing_guid: int, total_compressed_bytes: int}
     */
    private static function summarizeNzbStorageInventoryPhp(array $entries, int $minGzipBytes): array
    {
        $threshold = max(0, $minGzipBytes);
        $valid = $missing = $total = 0;
        foreach ($entries as $entry) {
            [$hasGuid, $bytes] = self::storageInventoryFields($entry);
            $bytes = max(0, $bytes);
            $missing += $hasGuid ? 0 : 1;
            $valid += $bytes >= $threshold ? 1 : 0;
            $total = $total > PHP_INT_MAX - $bytes ? PHP_INT_MAX : $total + $bytes;
        }

        return self::storageInventorySummary(\count($entries), $valid, $missing, $total);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array{entries: int, valid_gzip: int, undersized: int, missing_guid: int, total_compressed_bytes: int}|null
     */
    private static function summarizeNzbStorageInventoryNative(array $entries, int $minGzipBytes): ?array
    {
        if (\count($entries) * 2 + 1 > self::W402_MAX_INPUT / 8) {
            return null;
        }
        $words = [$minGzipBytes];
        foreach ($entries as $entry) {
            [$hasGuid, $bytes] = self::storageInventoryFields($entry);
            $words[] = (int) $hasGuid;
            $words[] = $bytes;
        }
        $slots = self::evaluateW402Native(0, pack('q*', ...$words), 5);
        if ($slots === null) {
            return null;
        }
        [$count, $valid, $undersized, $missing, $total] = $slots;
        if ($count !== \count($entries) || min($valid, $undersized, $missing, $total) < 0 || $valid + $undersized !== $count || $missing > $count) {
            return null;
        }

        return self::storageInventorySummary($count, $valid, $missing, $total);
    }

    /** @return array{bool, int} whether a non-empty string guid is present, and the bytes (0 unless an integer) */
    private static function storageInventoryFields(mixed $entry): array
    {
        if (! \is_array($entry)) {
            return [false, 0];
        }
        $guid = $entry['guid'] ?? null;
        $bytes = $entry['bytes'] ?? 0;

        return [\is_string($guid) && $guid !== '', \is_int($bytes) ? $bytes : 0];
    }

    /** @return array{entries: int, valid_gzip: int, undersized: int, missing_guid: int, total_compressed_bytes: int} */
    private static function storageInventorySummary(int $entries, int $valid, int $missing, int $total): array
    {
        return ['entries' => $entries, 'valid_gzip' => $valid, 'undersized' => $entries - $valid, 'missing_guid' => $missing,
            'total_compressed_bytes' => $total];
    }

    public const NZB_NONE = 0; // Release has no NZB file yet.

    public const NZB_ADDED = 1; // Release had an NZB file created.

    protected const NZB_DTD_NAME = 'nzb';

    protected const NZB_DTD_PUBLIC = '-//newzBin//DTD NZB 1.1//EN';

    protected const NZB_DTD_EXTERNAL = 'http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd';

    protected const NZB_XML_NS = 'http://www.newzbin.com/DTD/2003/nzb';

    /**
     * Levels deep to store NZB files.
     */
    protected int $nzbSplitLevel;

    /**
     * Path to store NZB files.
     */
    protected string $siteNzbPath;

    /**
     * Candidate base paths to search for existing NZB files.
     *
     * @var list<string>
     */
    protected array $siteNzbPaths = [];

    /**
     * String used for head in NZB XML file.
     */
    protected string $nzbCommentString;

    protected string $siteCommentString;

    public function __construct(
        private readonly CollectionCleanupService $collectionCleanupService,
        ?BinariesConfig $binariesConfig = null,
    ) {
        $this->binariesConfig = $binariesConfig;
        try {
            $nzbSplitLevel = (int) app(ConfigurationProvider::class)->ingestion()->nzbSplitLevel;
        } catch (Throwable) {
            // Table or database connection isn't available yet (e.g., during migrations, command discovery, or tests)
            $nzbSplitLevel = 1;
        }
        $this->nzbSplitLevel = $nzbSplitLevel;
        $this->siteNzbPaths = ($this->usesDatabase() && ! config('nntmux.nzb_legacy_reads', false)) ? [] : $this->buildCandidateNzbBasePaths((string) config('nntmux_settings.path_to_nzbs'));
        $this->siteNzbPath = ($this->usesDatabase() && ! config('nntmux.nzb_legacy_reads', false)) ? '' : $this->selectPreferredNzbBasePath($this->siteNzbPaths);
        $this->nzbCommentString = sprintf(
            'NZB Generated by: NNTmux %s',
            now()->format('F j, Y, g:i a O')
        );
        $this->siteCommentString = sprintf(
            'NZB downloaded from %s',
            config('app.name')
        );
    }

    private ?BinariesConfig $binariesConfig;

    private function binariesConfig(): BinariesConfig
    {
        return $this->binariesConfig ??= BinariesConfig::fromSettings();
    }

    public function usesDatabase(): bool
    {
        return config('nntmux.nzb_storage', 'database') === 'database';
    }

    public function hasNzb(string $guid): bool
    {
        return $this->usesDatabase() ? (app(DatabaseNzbStore::class)->exists($guid)
            || (config('nntmux.nzb_legacy_reads', false) && $this->legacyNzbPath($guid) !== false)) : $this->nzbPath($guid) !== false;
    }

    public static function databaseReference(string $guid): string
    {
        return 'database-nzb:'.$guid;
    }

    /**
     * Write an NZB file for a release.
     *
     * @throws Throwable
     */
    public function writeNzbForReleaseId(Release $release): bool
    {
        return $this->createNzbForRelease($release)->success;
    }

    public function createNzbForRelease(Release $release): NzbCreationResult
    {
        if (config('release_verification.required', true)) {
            return NzbCreationResult::transient('Release verification must publish the verified NZB; header-only creation is disabled.');
        }
        try {
            $collections = Collection::whereReleasesId($release->id)
                ->join('usenet_groups', 'collections.groups_id', '=', 'usenet_groups.id')
                ->select(['collections.*', DB::raw('UNIX_TIMESTAMP(collections.date) AS udate'), 'usenet_groups.name as groupname'])
                ->orderBy('collections.id')
                ->get()
                ->keyBy('id');
        } catch (Throwable $e) {
            return NzbCreationResult::transient('Failed to load release collections: '.$e->getMessage());
        }

        if ($collections->isEmpty()) {
            return NzbCreationResult::deterministic('Release has no collections to write into an NZB.');
        }

        $collectionIds = $collections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        try {
            $emptyCollection = DB::selectOne(
                'SELECT c.id FROM collections c
                 LEFT JOIN binaries b ON b.collections_id = c.id
                 WHERE c.releases_id = ? GROUP BY c.id HAVING COUNT(b.id) = 0 LIMIT 1',
                [$release->id]
            );
            if ($emptyCollection !== null) {
                return NzbCreationResult::deterministic("Collection {$emptyCollection->id} has no binaries.", $collectionIds);
            }

            $emptyBinary = DB::selectOne(
                'SELECT b.id FROM binaries b
                 INNER JOIN collections c ON c.id = b.collections_id
                 LEFT JOIN parts p ON p.binaries_id = b.id
                 WHERE c.releases_id = ? GROUP BY b.id HAVING COUNT(p.partnumber) = 0 LIMIT 1',
                [$release->id]
            );
            if ($emptyBinary !== null) {
                return NzbCreationResult::deterministic("Binary {$emptyBinary->id} has no parts.", $collectionIds);
            }

            $groupsByCollection = $this->loadCollectionGroups((int) $release->id, $collections);
        } catch (Throwable $e) {
            return NzbCreationResult::transient('Failed to load NZB binaries or parts: '.$e->getMessage(), $collectionIds);
        }

        $XMLWriter = new \XMLWriter;
        $XMLWriter->openMemory();
        $XMLWriter->setIndent(true);
        $XMLWriter->setIndentString('  ');

        $path = null;
        $tempPath = null;
        $gz = null;

        try {
            $path = $this->usesDatabase() ? self::databaseReference((string) $release->guid)
                : ($this->buildNzbPath($release->guid, $this->nzbSplitLevel, true).$release->guid.'.nzb.gz');
            $tempPath = $this->usesDatabase() ? null : $this->temporaryNzbPath($path);
            $gz = $this->usesDatabase() ? fopen('php://memory', 'w+b') : $this->openGzipFile($tempPath);

            if ($gz === false) {
                return NzbCreationResult::transient("Failed to open temporary NZB file for writing: {$tempPath}", $collectionIds, $path);
            }

            $XMLWriter->startDocument('1.0', 'UTF-8');
            $XMLWriter->startDtd(self::NZB_DTD_NAME, self::NZB_DTD_PUBLIC, self::NZB_DTD_EXTERNAL);
            $XMLWriter->endDtd();
            $XMLWriter->writeComment($this->nzbCommentString);

            $XMLWriter->startElement('nzb');
            $XMLWriter->writeAttribute('xmlns', self::NZB_XML_NS);
            $XMLWriter->startElement('head');
            $XMLWriter->startElement('meta');
            $XMLWriter->writeAttribute('type', 'category');
            $XMLWriter->text(! empty($release->category->parent) ? $release->category->parent->title.' >'.$release->category->title : 'Other > Misc');
            $XMLWriter->endElement();
            $XMLWriter->startElement('meta');
            $XMLWriter->writeAttribute('type', 'name');
            $XMLWriter->text($release->name);
            $XMLWriter->endElement();
            $XMLWriter->endElement(); // head
            if (! $this->flushXmlWriter($XMLWriter, $gz)) {
                return NzbCreationResult::transient("Failed to write NZB header to temporary file: {$tempPath}", $collectionIds, $path);
            }

            $cursor = ['collection_id' => 0, 'name' => '', 'binary_id' => 0, 'partnumber' => 0];
            $openBinaryId = 0;
            $fingerprintAccumulator = app(NzbArticleFingerprint::class);
            do {
                $page = $this->loadNzbRowPage((int) $release->id, $cursor);
                foreach ($page as $row) {
                    $binaryId = (int) $row->binary_id;
                    if ($binaryId !== $openBinaryId) {
                        if ($openBinaryId > 0) {
                            $XMLWriter->endElement(); // segments
                            $XMLWriter->endElement(); // file
                            if (! $this->flushXmlWriter($XMLWriter, $gz)) {
                                return NzbCreationResult::transient("Failed to write NZB file entry to temporary file: {$tempPath}", $collectionIds, $path);
                            }
                        }

                        $collection = $collections->get((int) $row->collection_id);
                        $groups = $groupsByCollection[(int) $row->collection_id] ?? [];
                        if ($collection === null || $groups === []) {
                            return NzbCreationResult::deterministic("Collection {$row->collection_id} has no valid cross-post groups.", $collectionIds, $path);
                        }

                        $subject = $this->buildBinarySubject((string) $row->binary_name, (int) $row->totalparts);
                        $XMLWriter->startElement('file');
                        $XMLWriter->writeAttribute('poster', (string) $collection->fromname);
                        $XMLWriter->writeAttribute('date', (string) $collection->udate);
                        $XMLWriter->writeAttribute('subject', (string) $subject);
                        $XMLWriter->startElement('groups');
                        foreach ($groups as $group) {
                            $XMLWriter->writeElement('group', $group);
                        }
                        $XMLWriter->endElement(); // groups
                        $XMLWriter->startElement('segments');
                        $openBinaryId = $binaryId;
                    }

                    $messageId = $this->normalizeSegmentMessageId($row->messageid);
                    if ($messageId === '') {
                        return NzbCreationResult::deterministic("Part {$row->partnumber} for binary {$binaryId} has an empty message ID.", $collectionIds, $path);
                    }

                    $fingerprintAccumulator->add($messageId);
                    $XMLWriter->startElement('segment');
                    $XMLWriter->writeAttribute('bytes', (string) $row->size);
                    $XMLWriter->writeAttribute('number', (string) $row->partnumber);
                    $XMLWriter->text($messageId);
                    $XMLWriter->endElement();

                    $cursor = [
                        'collection_id' => (int) $row->collection_id,
                        'name' => (string) $row->binary_name,
                        'binary_id' => $binaryId,
                        'partnumber' => (int) $row->partnumber,
                    ];
                }
            } while (\count($page) === $this->binariesConfig()->nzbStreamRows);

            if ($openBinaryId > 0) {
                $XMLWriter->endElement(); // segments
                $XMLWriter->endElement(); // file
            }

            $XMLWriter->writeComment($this->siteCommentString);
            $XMLWriter->endElement(); // nzb
            $XMLWriter->endDocument();
            if (! $this->flushXmlWriter($XMLWriter, $gz)) {
                return NzbCreationResult::transient("Failed to write NZB footer to temporary file: {$tempPath}", $collectionIds, $path);
            }

            $compressedBody = null;
            if ($this->usesDatabase()) {
                rewind($gz);
                $document = stream_get_contents($gz);
                $compressedBody = is_string($document) ? gzencode($document) : false;
                $closed = fclose($gz) && is_string($compressedBody);
            } else {
                $closed = gzclose($gz);
            }
            $gz = null;
            if (! $closed) {
                return NzbCreationResult::transient("Failed to close temporary NZB file: {$tempPath}", $collectionIds, $path);
            }

            $fingerprint = $fingerprintAccumulator->value();

            DB::transaction(function () use ($release, $fingerprint, &$tempPath, $path, $compressedBody): void {
                $current = Release::query()->whereKey($release->id)->where('guid', $release->guid)->lockForUpdate()->first();
                if ($current === null) {
                    throw new \RuntimeException('Release changed before NZB installation.');
                }
                $sources = new NzbSourceGuard($this);
                $receipt = $this->usesDatabase() ? $sources->installCompressed($current, $compressedBody)
                    : $sources->install($current, $tempPath, $path,
                        move: fn (string $temporary, string $destination): bool => $this->moveTemporaryNzbIntoPlace($temporary, $destination));
                DB::afterRollBack(static fn () => $receipt->rollback());
                DB::afterCommit(static fn () => $receipt->close());
                $tempPath = null;
                $release->update($this->successfulReleaseUpdateValues($fingerprint));

                if (NzbCreationCandidateQuery::supportsFailureState()) {
                    ReleaseNzbCreationFailure::query()
                        ->where('releases_id', $release->id)
                        ->delete();
                    $release->unsetRelation('nzbCreationFailure');
                }
            });
            try {
                FingerprintDispatch::enqueue((int) $release->id);
            } catch (Throwable $dispatchException) {
                Log::warning('Could not queue release fingerprint metadata collection.', [
                    'release_id' => $release->id,
                    'exception' => $dispatchException::class,
                ]);
            }
        } catch (NzbArticleFingerprintUnavailable|NzbArticleFingerprintTransportFailure $e) {
            return NzbCreationResult::deferred($e->getMessage(), $collectionIds, $path);
        } catch (Throwable $e) {
            return NzbCreationResult::transient('Failed to write NZB file: '.$e->getMessage(), $collectionIds, $path);
        } finally {
            unset($XMLWriter);
            if (is_resource($gz)) {
                if ($this->usesDatabase()) {
                    fclose($gz);
                } else {
                    gzclose($gz);
                }
            }
            if ($tempPath !== null && File::isFile($tempPath)) {
                File::delete($tempPath);
            }
        }

        // Delete CBP (Collections, Binaries, Parts) for release that has its NZB created.
        // Use a transaction to ensure cascading deletes complete properly.
        try {
            $this->collectionCleanupService->deleteCollectionsAndDescendants(
                $collectionIds,
                'NZB cleanup',
                false
            );
        } catch (Throwable $e) {
            // Log the error but don't fail the NZB creation since the file was written successfully
            Log::warning('Failed to delete collections for release '.$release->id.': '.$e->getMessage());
        }

        // Chmod to fix issues some users have with file permissions.
        if (! $this->usesDatabase()) {
            chmod($path, 0777);
        }

        return NzbCreationResult::success($path, $collectionIds);
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $collections
     * @return array<int, list<string>>
     */
    private function loadCollectionGroups(int $releaseId, \Illuminate\Support\Collection $collections): array
    {
        $groups = [];
        if (Schema::hasTable('collection_groups')) {
            $rows = DB::select(
                'SELECT cg.collections_id, cg.group_name
                 FROM collection_groups cg INNER JOIN collections c ON c.id = cg.collections_id
                 WHERE c.releases_id = ? ORDER BY cg.collections_id, cg.group_name',
                [$releaseId]
            );
            foreach ($rows as $row) {
                $groups[(int) $row->collections_id][] = (string) $row->group_name;
            }
        }

        foreach ($collections as $collection) {
            $collectionId = (int) $collection->id;
            if (($groups[$collectionId] ?? []) === []) {
                $groups[$collectionId] = $this->groupsFromXref((string) $collection->xref);
            }
        }

        return $groups;
    }

    /**
     * @param  array{collection_id: int, name: string, binary_id: int, partnumber: int}  $cursor
     * @return list<object>
     */
    private function loadNzbRowPage(int $releaseId, array $cursor): array
    {
        $limit = $this->binariesConfig()->nzbStreamRows;

        return DB::select(
            'SELECT b.collections_id AS collection_id, b.id AS binary_id, b.name AS binary_name,
                    b.totalparts, p.messageid, p.size, p.partnumber
             FROM binaries b
             INNER JOIN collections c ON c.id = b.collections_id
             INNER JOIN parts p ON p.binaries_id = b.id
             WHERE c.releases_id = ? AND (
                b.collections_id > ? OR
                (b.collections_id = ? AND b.name > ?) OR
                (b.collections_id = ? AND b.name = ? AND b.id > ?) OR
                (b.collections_id = ? AND b.name = ? AND b.id = ? AND p.partnumber > ?)
             )
             ORDER BY b.collections_id, b.name, b.id, p.partnumber
             LIMIT '.$limit,
            [
                $releaseId,
                $cursor['collection_id'],
                $cursor['collection_id'], $cursor['name'],
                $cursor['collection_id'], $cursor['name'], $cursor['binary_id'],
                $cursor['collection_id'], $cursor['name'], $cursor['binary_id'], $cursor['partnumber'],
            ]
        );
    }

    /**
     * Build a folder path on the hard drive where the NZB file will be stored.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @param  int  $levelsToSplit  How many sub-paths the folder will be in.
     * @param  bool  $createIfNotExist  Create the folder if it doesn't exist.
     * @return string $nzbpath The path to store the NZB file.
     */
    public function buildNzbPath(string $releaseGuid, int $levelsToSplit, bool $createIfNotExist): string
    {
        $nzbPath = $this->siteNzbPath;

        if ($createIfNotExist && ! File::isDirectory($nzbPath)) {
            if (! File::makeDirectory($nzbPath, 0775, true) && ! File::isDirectory($nzbPath)) { // @phpstan-ignore booleanNot.alwaysTrue
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $nzbPath));
            }

            File::chmod($nzbPath, 02775);
        }

        for ($i = 0; $i < $levelsToSplit && $i < 32; $i++) {
            $nzbPath .= $releaseGuid[$i].'/';

            if ($createIfNotExist && ! File::isDirectory($nzbPath)) {
                if (! File::makeDirectory($nzbPath, 0775) && ! File::isDirectory($nzbPath)) { // @phpstan-ignore booleanNot.alwaysTrue
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $nzbPath));
                }

                File::chmod($nzbPath, 02775);
            }
        }

        return $nzbPath;
    }

    /**
     * Build a path on a specific NZB base directory.
     */
    protected function buildNzbPathAtBasePath(string $basePath, string $releaseGuid, int $levelsToSplit): string
    {
        $nzbPath = '';

        for ($i = 0; $i < $levelsToSplit && $i < 32; $i++) {
            $nzbPath .= $releaseGuid[$i].'/';
        }

        return $basePath.$nzbPath;
    }

    /**
     * Retrieve path + filename of the NZB to be stored.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @param  int  $levelsToSplit  How many sub-paths the folder will be in. (optional)
     * @param  bool  $createIfNotExist  Create the folder if it doesn't exist. (optional)
     * @return string Path+filename.
     */
    public function getNzbPath(string $releaseGuid, int $levelsToSplit = 0, bool $createIfNotExist = false): string
    {
        if ($levelsToSplit === 0) {
            $levelsToSplit = $this->nzbSplitLevel;
        }

        return $this->buildNzbPath($releaseGuid, $levelsToSplit, $createIfNotExist).$releaseGuid.'.nzb.gz';
    }

    /**
     * Determine if an NZB exists, returning the path+filename, if not return false.
     *
     * @param  string  $releaseGuid  The guid of the release.
     * @return false|string On success: (string) Path+file name of the nzb.
     *                      On failure: false.
     *
     * @phpstan-impure It reads the filesystem.
     */
    public function nzbPath(string $releaseGuid): bool|string
    {
        if ($this->usesDatabase()) {
            return false;
        }

        return $this->legacyNzbPath($releaseGuid);
    }

    private function legacyNzbPath(string $releaseGuid): string|false
    {
        foreach ($this->nzbPathCandidates($releaseGuid) as $nzbFile) {
            if (is_file($nzbFile)) {
                return $nzbFile;
            }
        }

        return false;
    }

    /**
     * Where nzbPath() looks for a release's NZB, in the order it looks.
     *
     * @return list<string>
     */
    public function nzbPathCandidates(string $releaseGuid): array
    {
        $levelsToSplit = $this->nzbSplitLevel === 0 ? 1 : $this->nzbSplitLevel;
        $candidates = [];
        foreach ($this->siteNzbPaths as $basePath) {
            $candidates[] = $this->buildNzbPathAtBasePath($basePath, $releaseGuid, $levelsToSplit).$releaseGuid.'.nzb.gz';
        }
        $candidates[] = $this->getNzbPath($releaseGuid);

        return array_values(array_unique($candidates));
    }

    /**
     * Read and decompress an NZB file contents.
     *
     * @param  string  $releaseGuid  The release GUID
     * @return string|false The decompressed NZB contents or false on failure
     */
    public function readNzbContents(string $releaseGuid): string|false
    {
        if ($this->usesDatabase()) {
            $store = app(DatabaseNzbStore::class);
            if ($store->exists($releaseGuid)) {
                return $store->read($releaseGuid);
            }

            return config('nntmux.nzb_legacy_reads', false) ? $this->readLegacyNzbContents($releaseGuid) : false;
        }
        $nzbPath = $this->nzbPath($releaseGuid);
        if ($nzbPath === false) {
            return false;
        }

        $contents = unzipGzipFile($nzbPath);

        return ! empty($contents) ? $contents : false;
    }

    /** Read-only migration input. Never returns or creates a database source's file path. */
    public function readLegacyNzbContents(string $guid): string|false
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $guid) !== 1) {
            return false;
        }
        $path = $this->legacyNzbPath($guid);
        $body = $path === false ? false : file_get_contents($path, false, null, 0, DatabaseNzbStore::MAX_COMPRESSED_BYTES + 1);

        return is_string($body) && strlen($body) <= DatabaseNzbStore::MAX_COMPRESSED_BYTES
            ? DatabaseNzbStore::decode($body, DatabaseNzbStore::MAX_DECODED_BYTES) : false;
    }

    /**
     * Comparison-only read ceiling. Failed or over-limit reads consume their whole
     * decoded allowance, so repeated corrupt candidates cannot bypass the budget.
     */
    public function readNzbContentsBounded(string $releaseGuid, int $maxCompressedBytes, int $maxDecodedBytes, ?int &$decodedBytes = null): string|false
    {
        $decodedBytes = 0;
        if ($maxCompressedBytes <= 0 || $maxDecodedBytes <= 0) {
            return false;
        }
        if ($this->usesDatabase() && app(DatabaseNzbStore::class)->exists($releaseGuid)) {
            $contents = app(DatabaseNzbStore::class)->read($releaseGuid, $maxCompressedBytes, $maxDecodedBytes);
            $decodedBytes = is_string($contents) ? strlen($contents) : $maxDecodedBytes;

            return $contents;
        }
        $path = $this->usesDatabase() ? (config('nntmux.nzb_legacy_reads', false) ? $this->legacyNzbPath($releaseGuid) : false) : $this->nzbPath($releaseGuid);
        if (! is_string($path)) {
            return false;
        }
        $compressed = @file_get_contents($path, false, null, 0, $maxCompressedBytes + 1);
        if ($compressed === false || strlen($compressed) > $maxCompressedBytes || ! str_starts_with($compressed, "\x1f\x8b")) {
            $decodedBytes = $maxDecodedBytes;

            return false;
        }
        $inflater = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($inflater === false) {
            $decodedBytes = $maxDecodedBytes;

            return false;
        }
        $contents = '';
        $length = strlen($compressed);
        for ($offset = 0; $offset < $length; $offset += 256) {
            $chunk = @inflate_add($inflater, substr($compressed, $offset, 256), $offset + 256 >= $length ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($chunk === false || strlen($contents) + strlen($chunk) > $maxDecodedBytes) {
                $decodedBytes = $maxDecodedBytes;

                return false;
            }
            $contents .= $chunk;
            if (inflate_get_status($inflater) === ZLIB_STREAM_END) {
                break;
            }
        }
        $decodedBytes = strlen($contents);
        if (inflate_get_status($inflater) !== ZLIB_STREAM_END || inflate_get_read_len($inflater) !== $length) {
            $decodedBytes = $maxDecodedBytes;

            return false;
        }

        return $contents === '' ? false : $contents;
    }

    /**
     * Delete an NZB file.
     *
     * @param  string  $releaseGuid  The release GUID
     * @return bool True if deleted, false otherwise
     */
    public function deleteNzb(string $releaseGuid): bool
    {
        return (new NzbSourceGuard($this))->delete($releaseGuid);
    }

    /**
     * @return list<string>
     */
    public function findStaleTemporaryNzbPaths(int $olderThanSeconds = 86400): array
    {
        $cutoff = time() - max(1, $olderThanSeconds);
        $paths = [];

        foreach (array_unique($this->siteNzbPaths) as $basePath) {
            if (! File::isDirectory($basePath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = $file->getPathname();
                $isStaged = self::isStagedNzbPathName($path);
                if (! $isStaged && ! $this->isTemporaryNzbPath($path)) {
                    continue;
                }

                try {
                    // A staged generation's ctime is when its upgrade renamed it into place.
                    $age = $isStaged ? max($file->getMTime(), $file->getCTime()) : $file->getMTime();
                    if ($age <= $cutoff) {
                        $paths[] = $path;
                    }
                } catch (Throwable $e) {
                    Log::channel('nzb_creation')->warning('Failed to inspect temporary NZB file', [
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        sort($paths);

        return $paths;
    }

    public function cleanupStaleTemporaryNzbs(int $olderThanSeconds = 86400): int
    {
        $deleted = 0;

        foreach ($this->findStaleTemporaryNzbPaths($olderThanSeconds) as $path) {
            if (! File::isFile($path)) {
                continue;
            }
            if (self::isStagedNzbPathName($path)) {
                // Never just deleted: it may be the committed generation its upgrade did not get to swap in.
                $outcome = $this->reconcileStaleStagedNzb($path, $olderThanSeconds);
                if ($outcome === 'deleted') {
                    $deleted++;
                } elseif ($outcome === 'applied') {
                    Log::channel('nzb_creation')->warning('Applied a committed staged NZB generation left beside its release', ['path' => $path]);
                }

                continue;
            }

            $deleted += $this->deleteStaleTemporaryNzb($path) ? 1 : 0;
        }

        return $deleted;
    }

    /** Remove one stale temporary NZB (never a staged generation; see reconcileStaleStagedNzb()). */
    public function deleteStaleTemporaryNzb(string $path): bool
    {
        if (self::isStagedNzbPathName($path) || ! File::isFile($path)) {
            return false;
        }
        try {
            if (File::delete($path)) {
                return true;
            }

            Log::channel('nzb_creation')->warning('Failed to delete stale temporary NZB file', [
                'path' => $path,
            ]);
        } catch (Throwable $e) {
            Log::channel('nzb_creation')->warning('Failed to delete stale temporary NZB file', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Get the default NZB split level.
     */
    public function getNzbSplitLevel(): int
    {
        return $this->nzbSplitLevel;
    }

    /**
     * Get the base NZB storage path.
     */
    public function getSiteNzbPath(): string
    {
        return $this->siteNzbPath;
    }

    /**
     * @return list<string>
     */
    protected function buildCandidateNzbBasePaths(string $configuredPath): array
    {
        $defaultPath = $this->normalizeNzbBasePath(storage_path('nzb'));
        $configuredPath = $configuredPath !== '' ? $configuredPath : $defaultPath;
        $configuredPath = $this->normalizeNzbBasePath($configuredPath);

        $candidatePaths = [$configuredPath];
        $remappedStoragePath = $this->remapStoragePathToCurrentApplication($configuredPath);

        if ($remappedStoragePath !== null && ! in_array($remappedStoragePath, $candidatePaths, true)) {
            $candidatePaths[] = $remappedStoragePath;
        }

        if (! in_array($defaultPath, $candidatePaths, true)) {
            $candidatePaths[] = $defaultPath;
        }

        return $candidatePaths;
    }

    /**
     * @param  list<string>  $candidatePaths
     */
    protected function selectPreferredNzbBasePath(array $candidatePaths): string
    {
        $configuredPath = $candidatePaths[0] ?? $this->normalizeNzbBasePath(storage_path('nzb'));
        $remappedStoragePath = $candidatePaths[1] ?? null;

        if (
            $remappedStoragePath !== null &&
            $configuredPath !== $remappedStoragePath &&
            ! is_dir($configuredPath) &&
            is_dir($remappedStoragePath) &&
            str_contains(str_replace('\\', '/', $configuredPath), '/storage/')
        ) {
            return $remappedStoragePath;
        }

        return $configuredPath;
    }

    protected function remapStoragePathToCurrentApplication(string $path): ?string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $storageMarker = '/storage/';

        if (! str_contains($normalizedPath, $storageMarker)) {
            return null;
        }

        $relativeStoragePath = Str::after($normalizedPath, $storageMarker);

        return $this->normalizeNzbBasePath(storage_path($relativeStoragePath));
    }

    protected function normalizeNzbBasePath(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', trim($path));

        return rtrim($normalizedPath, '/').'/';
    }

    /**
     * Build the NZB subject line in the shape expected by downstream parsers.
     */
    private function buildBinarySubject(string $binaryName, int $totalParts): string
    {
        return rtrim($binaryName).' (1/'.$totalParts.')';
    }

    /**
     * Normalize stored message IDs before writing them into NZB segments.
     */
    private function normalizeSegmentMessageId(string $messageId): string
    {
        return NzbArticleFingerprint::normalizeMessageId($messageId);
    }

    /**
     * @return list<string>
     */
    public function groupsFromXref(string $xref): array
    {
        // Retain native PHP in production: string copy + `explode("\n", ...)` across FFI (~332-531 ns)
        // is 0.79x-1.06x vs cached PCRE `#(\S+):\S+#` (~262-565 ns).
        return $this->groupsFromXrefPhp($xref);
    }

    /**
     * @return list<string>
     */
    public function groupsFromXrefPhp(string $xref): array
    {
        if (preg_match_all('#(\S+):\S+#', $xref, $hits)) {
            return array_values(array_unique($hits[1]));
        }

        if (preg_match_all('#(\S+)#', $xref, $hits)) {
            return array_values(array_unique($hits[1]));
        }

        return [];
    }

    /**
     * @return list<string>|null
     */
    public static function groupsFromXrefNative(string $xref): ?array
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($xref === '') {
            return [];
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }

        $len = \strlen($xref);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(256, $len), $cap);
        if ($scratch === null) {
            return null;
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_nzb_groups_from_xref($xref, $len, $scratch, $cap);
            if (! \is_int($written) || $written === -1) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_nzb_groups_from_xref($xref, $len, $scratch, $cap);
                if (! \is_int($written) || $written < 0) {
                    return null;
                }
            }

            return $written === 0 ? [] : explode("\n", FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Temporary sibling for an NZB being written; recognised by {@see findStaleTemporaryNzbPaths()}.
     */
    public static function temporaryNzbPathFor(string $path): string
    {
        return $path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(6));
    }

    public static function isTemporaryNzbPathName(string $path): bool
    {
        // Retain native PHP in production: `basename()` + cached PCRE (~91-98 ns) beats
        // single-call FFI boundary (~137-139 ns, 0.66x-0.71x).
        return self::isTemporaryNzbPathNamePhp($path);
    }

    public static function isTemporaryNzbPathNamePhp(string $path): bool
    {
        return preg_match('/\.nzb\.gz\.tmp\.\d+\.[0-9a-f]{12}$/', basename($path)) === 1;
    }

    public static function isTemporaryNzbPathNameNative(string $path): ?bool
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
            $res = $ffi->nntmux_nzb_is_temporary_path_name($path, \strlen($path));

            return \is_int($res) && $res >= 0 ? ($res === 1) : null;
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

    /**
     * The generation a partial release's in-place upgrade staged beside its canonical NZB, tagged with the
     * upgrading candidate; only a crash or a failed callback between commit and swap, or a rollback whose
     * cleanup failed, leaves one behind.
     */
    public static function isStagedNzbPathName(string $path): bool
    {
        return preg_match(NzbSourceGuard::STAGED_NAME, basename($path)) === 1;
    }

    /** A stale staged generation is settled whether or not its release still exists. */
    public function isStaleStagedNzb(string $path, int $olderThanSeconds = 86400): bool
    {
        if (! self::isStagedNzbPathName($path)) {
            return false;
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat !== false && ($stat['mode'] & 0170000) === 0100000
            && max($stat['mtime'], $stat['ctime']) <= time() - max(1, $olderThanSeconds);
    }

    /**
     * Settle a stale staged generation by its upgrade's outcome: swapped in when it committed, removed when it
     * rolled back or was already swapped in (see NzbSourceGuard::reconcileStaged()). A dry run reports.
     *
     * @return 'applied'|'deleted'|'kept'
     */
    public function reconcileStaleStagedNzb(string $path, int $olderThanSeconds = 86400, bool $apply = true): string
    {
        if (! $this->isStaleStagedNzb($path, $olderThanSeconds)) {
            return 'kept';
        }
        try {
            return (new NzbSourceGuard($this))->reconcileStaged($path, $apply);
        } catch (Throwable $e) {
            Log::channel('nzb_creation')->warning('Failed to reconcile a staged NZB generation', ['path' => $path, 'error' => $e->getMessage()]);

            return 'kept';
        }
    }

    private function temporaryNzbPath(string $path): string
    {
        return self::temporaryNzbPathFor($path);
    }

    private function isTemporaryNzbPath(string $path): bool
    {
        return self::isTemporaryNzbPathName($path);
    }

    /**
     * @return resource|false
     */
    protected function openGzipFile(string $path): mixed
    {
        return gzopen($path, 'wb7');
    }

    /**
     * @param  resource  $gz
     *
     * @phpstan-impure
     */
    private function flushXmlWriter(\XMLWriter $XMLWriter, mixed $gz): bool
    {
        $buffer = $XMLWriter->outputMemory(true);
        if ($buffer === '') {
            return true;
        }

        $written = $this->usesDatabase() ? fwrite($gz, $buffer) : gzwrite($gz, $buffer);

        return $written !== false && $written === \strlen($buffer);
    }

    protected function moveTemporaryNzbIntoPlace(string $temporaryPath, string $finalPath): bool
    {
        return rename($temporaryPath, $finalPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulReleaseUpdateValues(?string $articleFingerprint = null): array
    {
        $values = ['nzbstatus' => self::NZB_ADDED];

        if ($articleFingerprint !== null && ReleaseArticleFingerprintStore::columnExists()) {
            $values[ReleaseArticleFingerprintStore::COLUMN] = $articleFingerprint;
        }

        if (NzbCreationCandidateQuery::supportsClaims()) {
            $values += [
                NzbCreationCandidateQuery::CLAIMED_AT_COLUMN => null,
                NzbCreationCandidateQuery::CLAIM_TOKEN_COLUMN => null,
            ];
        }

        return $values;
    }

    /** Wave 56.2 paired benchmarks decide this default; see RustDirectorySyncCoverageCreationImportNzbServiceW56ParityTest. */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = <<<'C'
        intptr_t nntmux_w56_nzb_storage_plan_batch(const char *guids, size_t guids_len, const char *subjects, size_t subjects_len, size_t count, int64_t levels, int64_t *out, size_t out_cap);
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
     * Runs $callback with planStorageBatch() routed to Rust ($enabled) or PHP, then restores the
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

    /** XML entity escapes for NZB text nodes and attributes. */
    private const array W56_XML_ESCAPES = ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&apos;'];

    /**
     * Batch storage plan over release guids and file subjects. Per release: `flags |
     * min(subpath length, 0xFFFFFF) << 8 | crc32(subpath) << 32` for the storage subpath
     * buildNzbPath() nests under the base path (`g[0]/…/g[L-1]/` with L = clamp($levels, 0,
     * min(32, strlen(guid)))) plus `guid.nzb.gz`, and `min(escapes, 0xFFFFFFFF) | crc32(escaped
     * subject) << 32` for the subject with `&<>"'` XML-escaped. Flags: 1 lowercase SHA-1 guid,
     * 2 repeats an earlier guid, 4 the subject needs escaping. Valid guids, duplicates, subjects
     * needing escapes, the saturating escape total, distinct top-level directories and the
     * XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $guids
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planStorageBatch(array $guids, array $subjects, int $levels): array
    {
        return self::usesW56RustFfi() ? self::planStorageBatchNative($guids, $subjects, $levels) : self::planStorageBatchPhp($guids, $subjects, $levels);
    }

    /**
     * @param  list<string>  $guids
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planStorageBatchPhp(array $guids, array $subjects, int $levels): array
    {
        $subjects = array_values($subjects);
        $out = $seen = $tops = [];
        $valid = $duplicates = $escaped = $escapes = 0;
        foreach (array_values($guids) as $i => $guid) {
            $subject = $subjects[$i] ?? '';
            $depth = min(max(0, $levels), 32, \strlen($guid));
            $path = '';
            for ($l = 0; $l < $depth; $l++) {
                $path .= $guid[$l].'/';
            }
            $path .= $guid.'.nzb.gz';
            if ($depth > 0) {
                $tops[$guid[0]] = true;
            }
            $sha1 = \strlen($guid) === 40 && strspn($guid, '0123456789abcdef') === 40;
            $duplicate = isset($seen[$guid]);
            $seen[$guid] = true;
            $count = \strlen($subject) - \strlen(str_replace(['&', '<', '>', '"', "'"], '', $subject));
            $valid += $sha1 ? 1 : 0;
            $duplicates += $duplicate ? 1 : 0;
            $escaped += $count > 0 ? 1 : 0;
            $escapes = $escapes > PHP_INT_MAX - $count ? PHP_INT_MAX : $escapes + $count;
            $flags = ($sha1 ? 1 : 0) | ($duplicate ? 2 : 0) | ($count > 0 ? 4 : 0);
            array_push($out, $flags | min(\strlen($path), 0xFFFFFF) << 8 | crc32($path) << 32,
                min($count, 0xFFFFFFFF) | crc32($count === 0 ? $subject : strtr($subject, self::W56_XML_ESCAPES)) << 32);
        }
        array_push($out, $valid, $duplicates, $escaped, $escapes, \count($tops));
        $out[] = self::w56Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a guid or subject holds a NUL
     * byte or the columns are not lists of one length.
     *
     * @param  list<string>  $guids
     * @param  list<string>  $subjects
     * @return list<int>
     */
    public static function planStorageBatchNative(array $guids, array $subjects, int $levels): array
    {
        $count = \count($guids);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 || \count($subjects) !== $count ? null : self::w56RustFfiState('out', $words);
        if ($state === null || ! array_is_list($guids) || ! array_is_list($subjects)) {
            return self::planStorageBatchPhp($guids, $subjects, $levels);
        }
        $guidBlob = implode("\0", $guids);
        $subjectBlob = implode("\0", $subjects);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w56_nzb_storage_plan_batch($guidBlob, \strlen($guidBlob), $subjectBlob, \strlen($subjectBlob), $count, $levels, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w56Words($written, $out, $words) ?? self::planStorageBatchPhp($guids, $subjects, $levels);
    }
}
