<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CollectionFileCheckStatus;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Predb;
use App\Models\Release;
use App\Models\ReleaseRegex;
use App\Services\Categorization\CategorizationService;
use App\Services\Nzb\NzbService;
use App\Services\Releases\ReleaseDuplicateFinder;
use App\Services\Releases\ReleaseGroupResolver;
use App\Services\Releases\Verification\CandidateRepository;
use App\Services\Releases\Verification\OversizedCollectionDeferrals;
use App\Support\Data\ReleaseCreationResult;
use App\Support\RustPipelineLibrary;
use App\Support\Utf8;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ReleaseCreationService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_runner_release_transform(uint32_t mode, const char *input, size_t len, char *out, size_t cap);
        C;

    // Retain PHP until common/uncommon mean and p99 all demonstrate a 1.5x gain.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled ?? false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param list<string> $fields
     * @return list<string>|null
     */
    private static function cleaningNative(int $mode, array $fields): ?array
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $input = '';
            foreach ($fields as $field) {
                if (strlen($field) > 0xFFFFFFFF) {
                    return null;
                }
                $input .= pack('V', strlen($field)).$field;
            }
            for ($cap = max(256, strlen($input) * 2); $cap <= 16777216; $cap *= 2) {
                $out = self::$ffi->new('char['.$cap.']');
                // @phpstan-ignore method.notFound
                $written = self::$ffi->nntmux_runner_release_transform($mode, $input, strlen($input), $out, $cap);
                if ($written === -2) {
                    continue;
                }
                if (! is_int($written) || $written < 0 || $written > $cap) {
                    return null;
                }
                $bytes = $written === 0 ? '' : FFI::string($out, $written);
                $result = [];
                for ($offset = 0; $offset < $written;) {
                    if ($written - $offset < 4) {
                        return null;
                    }
                    $length = unpack('Vlength', substr($bytes, $offset, 4))['length'];
                    $offset += 4;
                    if ($length > $written - $offset) {
                        return null;
                    }
                    $result[] = substr($bytes, $offset, $length);
                    $offset += $length;
                }

                return $result;
            }
        } catch (Throwable) {
            // Missing symbols, unavailable FFI and declined inputs retain PHP behavior.
        }

        return null;
    }

    public function __construct(
        private readonly ReleaseCleaningService $releaseCleaning,
        private readonly CollectionCleanupService $collectionCleanupService,
        private readonly ReleaseDuplicateFinder $releaseDuplicateFinder,
    ) {}

    /**
     * Create releases from complete collections.
     *
     * @param  list<int>|null  $allowedIds
     * @return array{added:int,dupes:int}
     *
     * @throws Throwable
     */
    public function createReleases(int|string|null $groupID, int $limit, bool $echoCLI, ?array $allowedIds = null): array
    {
        if ($allowedIds === []) {
            return ['added' => 0, 'dupes' => 0];
        }
        if (config('release_verification.required', true)) {
            $ids = $this->collectionCleanupService->verificationReadyCollections()
                ->when(! empty($groupID), static fn ($q) => $q->where('groups_id', $groupID))
                ->when($allowedIds !== null, static fn ($q) => $q->whereIn('id', $allowedIds))
                ->orderBy('id')->limit(max(1, $limit))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            // Uploads already over the metadata budget would only read every part again and fail.
            $kept = app(OversizedCollectionDeferrals::class)->archiveEach($ids, static function (int $id): void {
                app(CandidateRepository::class)->archive($id);
            });
            foreach ($kept as $id => $outcome) {
                if ($outcome['error'] === null) {
                    continue;
                }
                // Keep this collection for retry without starving later
                // candidates or preventing the bounded window cursor saving.
                Log::warning('Verification archival failed; original headers retained.', ['collection_id' => $id,
                    'error_type' => $outcome['error']::class] + ($outcome['retry_after'] === null ? [] : ['retry_after' => $outcome['retry_after']]));
            }

            return ['added' => 0, 'dupes' => 0];
        }

        $startTime = now()->toImmutable();
        $categorize = new CategorizationService;
        $returnCount = 0;
        $duplicate = 0;

        if ($echoCLI) {
            cli()->header('Process Releases -> Create releases from complete collections.');
        }

        $collectionsQuery = Collection::query()
            ->where('collections.filecheck', CollectionFileCheckStatus::Sized->value)
            ->where('collections.filesize', '>', 0);
        if (! empty($groupID)) {
            $collectionsQuery->where('collections.groups_id', $groupID);
        }
        if ($allowedIds !== null) {
            $collectionsQuery->whereIn('collections.id', $allowedIds);
        }
        $collectionsQuery->select(['collections.*', 'usenet_groups.name as gname'])
            ->join('usenet_groups', 'usenet_groups.id', '=', 'collections.groups_id')
            ->limit($limit);
        $collections = $collectionsQuery->get();
        $releaseGroupIds = $this->loadReleaseGroupIds($collections);

        if ($echoCLI && $collections->count() > 0) {
            cli()->primary(\count($collections).' Collections ready to be converted to releases.', true);
        }

        foreach ($collections as $collection) {
            [$cleanRelName, $fromName] = $this->sanitizeCollectionNames((string) $collection->subject, (string) $collection->fromname);
            $cleanedMeta = $this->releaseCleaning->releaseCleaner(
                $collection->subject,
                $collection->fromname,
                $collection->gname
            );
            $meta = $this->normalizeCollectionReleaseMeta($cleanedMeta, $cleanRelName);
            $namingRegexId = $meta['namingRegexId'];
            $properName = $meta['properName'];
            $preID = $meta['preID'];
            $cleanedName = $meta['cleanedName'];

            if ($preID === 0 && $cleanedName !== '') {
                $preMatch = Predb::matchPre($cleanedName);
                if ($preMatch !== false) {
                    $cleanedName = $preMatch['title'];
                    $preID = $preMatch['predb_id'];
                    $properName = true;
                }
            }

            $searchName = ! empty($cleanedName) ? Utf8::clean($cleanedName) : $cleanRelName;
            $predbIdInt = (int) $preID;

            [$dupeCheck, $dupeReason] = $this->releaseDuplicateFinder->findDuplicate(
                $cleanRelName,
                $searchName,
                $predbIdInt,
                (int) $collection->filesize
            );

            if ($dupeCheck === null) {
                $determinedCategory = $categorize->determineCategory($collection->groups_id, $cleanedName, $fromName);

                $releaseID = Release::insertRelease([
                    'name' => $cleanRelName,
                    'searchname' => $searchName,
                    'totalpart' => $collection->totalfiles,
                    'groups_id' => $collection->groups_id,
                    'guid' => Str::uuid()->toString(),
                    'postdate' => $collection->date,
                    'fromname' => $fromName,
                    'size' => $collection->filesize,
                    'categories_id' => $determinedCategory['categories_id'] ?? Category::OTHER_MISC,
                    'isrenamed' => $properName === true ? 1 : 0,
                    'predb_id' => $predbIdInt,
                    'nzbstatus' => NzbService::NZB_NONE,
                ]);

                if ($releaseID !== null) {
                    DB::transaction(static function () use ($collection, $releaseID) {
                        Collection::query()->where('id', $collection->id)->update([
                            'filecheck' => CollectionFileCheckStatus::Inserted->value,
                            'releases_id' => $releaseID,
                        ]);
                    }, 10);

                    ReleaseRegex::insertOrIgnore([
                        'releases_id' => $releaseID,
                        'collection_regex_id' => $collection->collection_regexes_id,
                        'naming_regex_id' => $namingRegexId,
                    ]);

                    $groupRows = [];
                    foreach ($releaseGroupIds[(int) $collection->id] ?? [] as $groupId) {
                        $groupRows[] = ['releases_id' => $releaseID, 'groups_id' => $groupId];
                    }
                    if ($groupRows !== []) {
                        DB::table('releases_groups')->insertOrIgnore($groupRows);
                    }

                    $returnCount++;
                    if ($echoCLI) {
                        echo "Added $returnCount releases.\r";
                    }
                }
            } else {
                Log::info('Release import skipped as duplicate', [
                    'reason' => $dupeReason,
                    'matched_release_id' => $dupeCheck->id,
                    'new_searchname' => $searchName,
                    'existing_searchname' => $dupeCheck->searchname,
                    'new_size' => (int) $collection->filesize,
                    'existing_size' => (int) $dupeCheck->size,
                    'new_fromname' => $fromName,
                    'existing_fromname' => $dupeCheck->fromname,
                    'new_name' => $cleanRelName,
                    'existing_name' => $dupeCheck->name,
                ]);

                $this->collectionCleanupService->deleteCollectionsAndDescendants(
                    [$collection->id],
                    'Duplicate cleanup',
                    $echoCLI
                );

                $duplicate++;
            }
        }

        $totalTime = now()->diffInSeconds($startTime, true);
        if ($echoCLI) {
            cli()->primary(
                PHP_EOL.
                number_format($returnCount).
                ' Releases added and '.
                number_format($duplicate).
                ' duplicate collections deleted in '.
                $totalTime.Str::plural(' second', (int) $totalTime),
                true
            );
        }

        return ReleaseCreationResult::normalizeResultArray(['added' => $returnCount, 'dupes' => $duplicate]);
    }

    /**
     * Resolve all normalized cross-post groups for the selected collection
     * batch up front, avoiding release/group membership N+1 queries.
     *
     * @param  \Illuminate\Support\Collection<int, Collection>  $collections
     * @return array<int, list<int>>
     */
    private function loadReleaseGroupIds(\Illuminate\Support\Collection $collections): array
    {
        $namesByCollection = [];
        $collectionIds = $collections->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        if (Schema::hasTable('collection_groups')) {
            foreach (array_chunk($collectionIds, 500) as $chunk) {
                foreach (DB::table('collection_groups')->whereIn('collections_id', $chunk)->get() as $row) {
                    $namesByCollection[(int) $row->collections_id][(string) $row->group_name] = true;
                }
            }
        }

        foreach ($collections as $collection) {
            $collectionId = (int) $collection->id;
            if (($namesByCollection[$collectionId] ?? []) === []) {
                $fallback = (new XrefService)->extractGroupNames((string) $collection->xref);
                if ($fallback === []) {
                    $fallback = [(string) $collection->gname];
                }
                foreach ($fallback as $name) {
                    $namesByCollection[$collectionId][$name] = true;
                }
            }
        }

        $allNames = [];
        foreach ($namesByCollection as $names) {
            $allNames += $names;
        }
        $idsByName = app(ReleaseGroupResolver::class)->idsByName(array_keys($allNames));

        return $this->resolveCollectionGroupIds($namesByCollection, $idsByName);
    }

    /** @return array{string, string} */
    public function sanitizeCollectionNames(string $subject, string $poster): array
    {
        return self::usesRustFfi() ? $this->sanitizeCollectionNamesNative($subject, $poster) : $this->sanitizeCollectionNamesPhp($subject, $poster);
    }

    /** @return array{string, string} */
    public function sanitizeCollectionNamesPhp(string $subject, string $poster): array
    {
        return [Utf8::clean(str_replace(['#', '@', '$', '%', '^', '§', '¨', '©', 'Ö'], '', $subject)), Utf8::clean(trim($poster, "'"))];
    }

    /** @return array{string, string} */
    public function sanitizeCollectionNamesNative(string $subject, string $poster): array
    {
        $result = self::cleaningNative(5, [$subject, $poster]);

        return $result === null || count($result) !== 2 ? $this->sanitizeCollectionNamesPhp($subject, $poster) : [Utf8::clean($result[0]), Utf8::clean($result[1])];
    }

    /** @return array{namingRegexId:int, properName:mixed, preID:int, cleanedName:mixed} */
    public function normalizeCollectionReleaseMeta(mixed $cleanedMeta, string $cleanRelName): array
    {
        return self::usesRustFfi() ? $this->normalizeCollectionReleaseMetaNative($cleanedMeta, $cleanRelName) : $this->normalizeCollectionReleaseMetaPhp($cleanedMeta, $cleanRelName);
    }

    /** @return array{namingRegexId:int, properName:mixed, preID:int, cleanedName:mixed} */
    public function normalizeCollectionReleaseMetaPhp(mixed $cleanedMeta, string $cleanRelName): array
    {
        return is_array($cleanedMeta)
            ? ['namingRegexId' => (int) ($cleanedMeta['id'] ?? 0), 'properName' => $cleanedMeta['properlynamed'] ?? false, 'preID' => max(0, (int) ($cleanedMeta['predb'] ?? 0)), 'cleanedName' => $cleanedMeta['cleansubject'] ?? $cleanRelName]
            : ['namingRegexId' => 0, 'properName' => true, 'preID' => 0, 'cleanedName' => $cleanRelName];
    }

    /** @return array{namingRegexId:int, properName:mixed, preID:int, cleanedName:mixed} */
    public function normalizeCollectionReleaseMetaNative(mixed $cleanedMeta, string $cleanRelName): array
    {
        $isArray = is_array($cleanedMeta);
        $result = self::cleaningNative(8, [(string) (int) $isArray, (string) ($isArray ? (int) ($cleanedMeta['id'] ?? 0) : 0), (string) ($isArray ? (int) ($cleanedMeta['predb'] ?? 0) : 0)]);
        if ($result === null || count($result) !== 3) {
            return $this->normalizeCollectionReleaseMetaPhp($cleanedMeta, $cleanRelName);
        }

        return ['namingRegexId' => (int) $result[0], 'properName' => $result[2] === '1' ? ($cleanedMeta['properlynamed'] ?? false) : true, 'preID' => (int) $result[1], 'cleanedName' => $result[2] === '1' ? ($cleanedMeta['cleansubject'] ?? $cleanRelName) : $cleanRelName];
    }

    /** @param array<int, array<string|int, bool>> $namesByCollection
     * @param  array<string|int, int>  $idsByName
     * @return array<int, list<int>>
     */
    public function resolveCollectionGroupIds(array $namesByCollection, array $idsByName): array
    {
        return self::usesRustFfi() ? $this->resolveCollectionGroupIdsNative($namesByCollection, $idsByName) : $this->resolveCollectionGroupIdsPhp($namesByCollection, $idsByName);
    }

    /** @param array<int, array<string|int, bool>> $namesByCollection
     * @param  array<string|int, int>  $idsByName
     * @return array<int, list<int>>
     */
    public function resolveCollectionGroupIdsPhp(array $namesByCollection, array $idsByName): array
    {
        $resolved = [];
        foreach ($namesByCollection as $collectionId => $names) {
            foreach (array_keys($names) as $name) {
                if (isset($idsByName[$name])) {
                    $resolved[$collectionId][] = $idsByName[$name];
                }
            }
        }
        foreach ($resolved as &$ids) {
            $ids = array_values(array_unique($ids));
        }
        unset($ids);

        return $resolved;
    }

    /** @param array<int, array<string|int, bool>> $namesByCollection
     * @param  array<string|int, int>  $idsByName
     * @return array<int, list<int>>
     */
    public function resolveCollectionGroupIdsNative(array $namesByCollection, array $idsByName): array
    {
        $fields = [(string) count($idsByName)];
        foreach ($idsByName as $name => $id) {
            array_push($fields, (string) $name, (string) $id);
        }
        foreach ($namesByCollection as $id => $names) {
            array_push($fields, (string) $id, (string) count($names));
            foreach (array_keys($names) as $name) {
                $fields[] = (string) $name;
            }
        }
        $result = self::cleaningNative(7, $fields);
        if ($result === null || count($result) % 2 !== 0) {
            return $this->resolveCollectionGroupIdsPhp($namesByCollection, $idsByName);
        }
        $resolved = [];
        foreach (array_chunk($result, 2) as [$id, $groupId]) {
            $resolved[(int) $id][] = (int) $groupId;
        }

        return $resolved;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all eight runs (min 1.65x; see the W43 parity test). */
    public const bool W431_RUST_FFI_DEFAULT = true;

    public const string W431_FFI_CDEF = <<<'C'
        ssize_t nntmux_w431_release_creation_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W431_SUMMARY_KEYS = ['total_subjects', 'empty_subject_count', 'stripped_char_count', 'quoted_filename_count', 'non_ascii_count', 'oversized_subject_count'];

    private static ?bool $w431UseRustFfi = null;

    private static FFI|false|null $w431Ffi = null;

    public static function useW431RustFfi(bool $enable = true): void
    {
        self::$w431UseRustFfi = $enable;
    }

    public static function usesW431RustFfi(): bool
    {
        return self::$w431UseRustFfi ?? self::W431_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; earlier toggles are untouched. */
    public static function resetW431RustFfi(): void
    {
        self::$w431UseRustFfi = null;
        self::$w431Ffi = null;
    }

    /**
     * Summarizes trimmed collection subjects: subjects holding a character sanitizeCollectionNamesPhp() strips,
     * a quoted filename (`"[^"]+"`), a non-ASCII byte, or more than 255 bytes.
     * Entries come from strings, integers, or the first non-null subject, name array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCollectionSubjectBatch(array $rows): array
    {
        return self::usesW431RustFfi() ? self::summarizeCollectionSubjectBatchNative($rows) : self::summarizeCollectionSubjectBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCollectionSubjectBatchNative(array $rows): array
    {
        $values = CollectionsCleaningService::w431Invoke(self::$w431Ffi, self::W431_FFI_CDEF, 'nntmux_w431_release_creation_batch', $rows, ['subject', 'name'], \count(self::W431_SUMMARY_KEYS));

        return $values === null ? self::summarizeCollectionSubjectBatchPhp($rows) : array_combine(self::W431_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCollectionSubjectBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W431_SUMMARY_KEYS, 0);
        foreach (CollectionsCleaningService::w431Entries($rows, ['subject', 'name']) as $entry) {
            $summary['total_subjects']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_subject_count']++;

                continue;
            }
            $summary['stripped_char_count'] += (int) (preg_match('/[#@$%^]|§|¨|©|Ö/', $entry) === 1);
            $summary['quoted_filename_count'] += (int) (preg_match('/"[^"]+"/', $entry) === 1);
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xff]/', $entry) === 1);
            $summary['oversized_subject_count'] += (int) (\strlen($entry) > 255);
        }

        return $summary;
    }

    // ========================================================================
    // WAVE 62.1 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.57x, p99 4.59x; uncommon mean 4.39x, p99 3.54x); see RustObfuscationRouterReleaseCreationExtraProcessingXrefW62ParityTest. */
    public const bool W62_RUST_FFI_DEFAULT = true;

    public const string W62_RELEASE_CREATION_FFI_CDEF = <<<'C'
        ssize_t nntmux_w621_release_creation_plan_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of w62ReleaseCreationPlanBatch(). */
    public const int W62_RELEASE_CREATION_TRAILER = 16;

    public static function usesW62RustFfi(): bool
    {
        $override = self::w62RustFfiState();

        return \is_bool($override) ? $override : self::W62_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the ReleaseCreationService Wave 62 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW62RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w62RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w62RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 62.1 release creation plans. Each item is `name|filesize|totalfiles|filecheck|minSize|minFiles|predbId`;
     * numbers are 1-15 digits after trimming (the first three are required, the rest default to 0 when empty).
     * The decision, in order: 6 a malformed number, 1 empty (trimmed) name, 2 filecheck other than
     * CollectionFileCheckStatus::Sized, 3 zero size, 4 under minSize, 5 under minFiles, else 0 create. Flags:
     * 1 PreDB match (renamed), 2 `"` in the name, 4 control bytes, 8 non-ASCII, 16 yEnc (case-insensitive),
     * 32 a name over 255 bytes, 64 a case-insensitive repeat of an earlier name in the batch. Per item
     * `decision | flags << 3 | bitLength(size) << 10 | min(files, 4095) << 17 | min(nameLength, 1023) << 29 |
     * min(controlBytes, 63) << 39 | (fnv1a32(strtolower(name)) & 0x3ffff) << 45` (the GUID pre-plan bucket);
     * then 7 decision and 7 flag counts, the MiB (size >> 20) of creatable items and the largest file count.
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseCreationPlanBatch(array $items): array
    {
        return self::usesW62RustFfi() ? self::w62ReleaseCreationPlanBatchNative($items) : self::w62ReleaseCreationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseCreationPlanBatchNative(array $items): array
    {
        return self::w62Invoke($items, \count($items) + self::W62_RELEASE_CREATION_TRAILER) ?? self::w62ReleaseCreationPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w62ReleaseCreationPlanBatchPhp(array $items): array
    {
        $out = [];
        $tail = array_fill(0, self::W62_RELEASE_CREATION_TRAILER, 0);
        $seen = [];
        foreach ($items as $item) {
            [$rawName, $size, $files, $check, $minSize, $minFiles, $predb] = explode('|', (string) $item, 7) + ['', '', '', '', '', '', ''];
            $name = trim($rawName);
            $size = self::w62Uint($size, -1);
            $files = self::w62Uint($files, -1);
            $check = self::w62Uint($check, -1);
            $minSize = self::w62Uint($minSize, 0);
            $minFiles = self::w62Uint($minFiles, 0);
            $predb = self::w62Uint($predb, 0);
            $decision = match (true) {
                min($size, $files, $check, $minSize, $minFiles, $predb) < 0 => 6,
                $name === '' => 1,
                $check !== CollectionFileCheckStatus::Sized->value => 2,
                $size === 0 => 3,
                $minSize > 0 && $size < $minSize => 4,
                $minFiles > 0 && $files < $minFiles => 5,
                default => 0,
            };
            $length = \strlen($name);
            $control = (int) preg_match_all('/[\x00-\x1f\x7f]/', $name);
            $flags = ($predb > 0 ? 1 : 0) | (str_contains($name, '"') ? 2 : 0) | ($control > 0 ? 4 : 0)
                | (preg_match('/[\x80-\xff]/', $name) === 1 ? 8 : 0) | (stripos($name, 'yenc') !== false ? 16 : 0) | ($length > 255 ? 32 : 0);
            $lower = strtolower($name);
            if ($name !== '') {
                if (isset($seen[$lower])) {
                    $flags |= 64;
                } else {
                    $seen[$lower] = true;
                }
            }
            $size = max(0, $size);
            $files = max(0, $files);
            $tail[$decision]++;
            for ($b = 0; $b < 7; $b++) {
                $tail[7 + $b] += $flags >> $b & 1;
            }
            if ($decision === 0) {
                $tail[14] += $size >> 20;
            }
            $tail[15] = max($tail[15], $files);
            $bits = 0;
            for ($v = $size; $v > 0; $v >>= 1) {
                $bits++;
            }
            $out[] = $decision | $flags << 3 | $bits << 10 | min($files, 4095) << 17 | min($length, 1023) << 29 | min($control, 63) << 39
                | ((int) hexdec(hash('fnv1a32', $lower)) & 0x3FFFF) << 45;
        }
        array_push($out, ...$tail);

        return $out;
    }

    /** A field of 1-15 digits after trimming as its value, $empty when empty, else -1. */
    private static function w62Uint(string $raw, int $empty): int
    {
        $value = trim($raw);
        if ($value === '') {
            return $empty;
        }

        return \strlen($value) <= 15 && strspn($value, '0123456789') === \strlen($value) ? (int) $value : -1;
    }

    /**
     * Wave 62 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and
     * returns the previous override, 'ffi' resolves the library (null when unavailable) and 'out' and
     * 'lens' return `int64_t` output and length-column buffers of at least $val words.
     */
    private static function w62RustFfiState(?string $op = null, mixed $val = null): mixed
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
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('release_creation_w621', self::W62_RELEASE_CREATION_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w62RustFfiState('ffi');
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
                $handle = self::w62RustFfiState('ffi');
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
     * Calls the Wave 62.1 ReleaseCreationService export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call
     * declines or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w62Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w62RustFfiState('ffi');
            $out = self::w62RustFfiState('out', $words);
            if (! $ffi instanceof FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w62RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W62_RELEASE_CREATION_FFI_CDEF.)
            $written = $ffi->nntmux_w621_release_creation_plan_batch($blob, \strlen($blob), $lens, $count, $out, $words);
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
