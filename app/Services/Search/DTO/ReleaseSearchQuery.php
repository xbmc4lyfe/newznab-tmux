<?php

declare(strict_types=1);

namespace App\Services\Search\DTO;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final class ReleaseSearchQuery
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_search_normalize_media_unique_id(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        int32_t nntmux_search_normalize_range_bound(const char *input_ptr, size_t input_len, int64_t *out_val);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $outI64 = null;

    private static bool $useRustFfi = true;

    /**
     * Rust stays opt-in for the Wave 41.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustSearchDtoQueryCursorPageMediaCodecW41ParityTest.php.
     */
    public const bool W415_RUST_FFI_DEFAULT = false;

    /** The `normalizedSortField()` allow-list; the first entry is the fallback. */
    private const array W415_SORT_FIELDS = ['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id'];

    private static ?bool $useW415RustFfi = null;

    /**
     * @param  array<string, mixed>|string|null  $phrases
     * @param  list<int>|null  $categoryIds
     * @param  list<int>  $excludedCategoryIds
     * @param  array<string, array<string, int>>  $ranges
     * @param  list<int>|null  $releaseIds
     */
    public function __construct(
        public readonly array|string|null $phrases = null,
        public readonly ?array $categoryIds = null,
        public readonly array $excludedCategoryIds = [],
        public readonly ?array $releaseIds = null,
        public readonly int $minSize = 0,
        public readonly int $maxSize = 0,
        public readonly int $maxAgeDays = -1,
        public readonly int $minDate = 0,
        public readonly int $maxDate = 0,
        public readonly ?int $groupId = null,
        public readonly bool $passwordAllowRar = false,
        public readonly ?int $passwordStatusMin = null,
        public readonly ?bool $hasMediaInfo = null,
        public readonly ?string $mediaUniqueId = null,
        public readonly int $minVideoWidth = 0,
        public readonly int $maxVideoWidth = 0,
        public readonly int $minVideoHeight = 0,
        public readonly int $maxVideoHeight = 0,
        public readonly string $sortField = 'postdate_ts',
        public readonly string $sortDirection = 'desc',
        public readonly bool $tryFuzzy = true,
        public readonly int $limit = 100,
        public readonly int $offset = 0,
        public readonly ?SearchCursor $cursor = null,
        public readonly bool $trackTotal = true,
        public readonly bool $includeDocuments = false,
        public readonly array $ranges = [],
        public readonly ?int $minId = null,
        public readonly ?int $maxId = null,
        public readonly bool $fuzzyOnly = false,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /** @param array<string, mixed> $criteria */
    public static function fromCriteria(array $criteria, int $limit, int $offset = 0, ?SearchCursor $cursor = null): self
    {
        return new self(
            phrases: is_array($criteria['phrases'] ?? null) || is_string($criteria['phrases'] ?? null) ? $criteria['phrases'] : null,
            categoryIds: is_array($criteria['category_ids'] ?? null) ? array_map('intval', $criteria['category_ids']) : null,
            excludedCategoryIds: is_array($criteria['excluded_category_ids'] ?? null) ? array_map('intval', $criteria['excluded_category_ids']) : [],
            releaseIds: is_array($criteria['release_ids'] ?? null) ? array_map('intval', $criteria['release_ids']) : null,
            minSize: (int) ($criteria['min_size'] ?? 0),
            maxSize: (int) ($criteria['max_size'] ?? 0),
            maxAgeDays: (int) ($criteria['max_age_days'] ?? -1),
            minDate: (int) ($criteria['min_date'] ?? 0),
            maxDate: (int) ($criteria['max_date'] ?? 0),
            groupId: isset($criteria['groups_id']) ? (int) $criteria['groups_id'] : null,
            passwordAllowRar: (bool) ($criteria['password_allow_rar'] ?? false),
            passwordStatusMin: isset($criteria['password_status_min']) ? (int) $criteria['password_status_min'] : null,
            hasMediaInfo: array_key_exists('has_media_info', $criteria) && $criteria['has_media_info'] !== null
                ? (bool) $criteria['has_media_info']
                : null,
            mediaUniqueId: self::normalizeMediaUniqueId($criteria['media_unique_id'] ?? null),
            minVideoWidth: (int) ($criteria['min_video_width'] ?? 0),
            maxVideoWidth: (int) ($criteria['max_video_width'] ?? 0),
            minVideoHeight: (int) ($criteria['min_video_height'] ?? 0),
            maxVideoHeight: (int) ($criteria['max_video_height'] ?? 0),
            sortField: (string) ($criteria['sort_field'] ?? 'postdate_ts'),
            sortDirection: (string) ($criteria['sort_dir'] ?? 'desc'),
            tryFuzzy: (bool) ($criteria['try_fuzzy'] ?? true),
            limit: max(1, $limit),
            offset: max(0, $offset),
            cursor: $cursor,
            trackTotal: (bool) ($criteria['track_total'] ?? true),
            includeDocuments: (bool) ($criteria['include_documents'] ?? false),
            ranges: self::normalizeRanges($criteria['ranges'] ?? []),
            minId: self::normalizeRangeBound($criteria['min_id'] ?? null),
            maxId: self::normalizeRangeBound($criteria['max_id'] ?? null),
            fuzzyOnly: (bool) ($criteria['fuzzy_only'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function criteria(): array
    {
        return [
            'ranges' => self::normalizeRanges($this->ranges),
            'min_id' => $this->minId,
            'max_id' => $this->maxId,
            'fuzzy_only' => $this->fuzzyOnly,
            'phrases' => $this->phrases,
            'category_ids' => $this->categoryIds,
            'excluded_category_ids' => $this->excludedCategoryIds,
            'release_ids' => $this->releaseIds,
            'min_size' => max(0, $this->minSize),
            'max_size' => max(0, $this->maxSize),
            'max_age_days' => $this->maxAgeDays,
            'min_date' => max(0, $this->minDate),
            'max_date' => max(0, $this->maxDate),
            'groups_id' => $this->groupId,
            'password_allow_rar' => $this->passwordAllowRar,
            'password_status_min' => $this->passwordStatusMin,
            'has_media_info' => $this->hasMediaInfo,
            'media_unique_id' => $this->mediaUniqueId,
            'min_video_width' => max(0, $this->minVideoWidth),
            'max_video_width' => max(0, $this->maxVideoWidth),
            'min_video_height' => max(0, $this->minVideoHeight),
            'max_video_height' => max(0, $this->maxVideoHeight),
            'sort_field' => $this->normalizedSortField(),
            'sort_dir' => $this->normalizedSortDirection(),
            'try_fuzzy' => $this->tryFuzzy,
            'cursor_sort' => $this->cursor?->sortValues,
            'track_total' => $this->trackTotal,
            'include_documents' => $this->includeDocuments,
        ];
    }

    public function normalizedSortField(): string
    {
        return in_array($this->sortField, ['postdate_ts', 'adddate_ts', 'size', 'totalpart', 'grabs', 'comments', 'categories_id', 'id'], true)
            ? $this->sortField
            : 'postdate_ts';
    }

    public function normalizedSortDirection(): string
    {
        return strtolower($this->sortDirection) === 'asc' ? 'asc' : 'desc';
    }

    /** @return array<string, array<string, int>> */
    public static function normalizeRanges(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        $normalized = [];
        foreach (['size', 'totalpart', 'grabs', 'comments', 'adddate_ts'] as $field) {
            if (! is_array($ranges[$field] ?? null)) {
                continue;
            }
            foreach (['min', 'max'] as $bound) {
                $value = self::normalizeRangeBound($ranges[$field][$bound] ?? null);
                if ($value !== null) {
                    $normalized[$field][$bound] = $value;
                }
            }
        }

        return $normalized;
    }

    /** @return array<string, array<string, int>> */
    public static function normalizeRangesNative(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        $normalized = [];
        foreach (['size', 'totalpart', 'grabs', 'comments', 'adddate_ts'] as $field) {
            if (! is_array($ranges[$field] ?? null)) {
                continue;
            }
            foreach (['min', 'max'] as $bound) {
                $value = self::normalizeRangeBoundNative($ranges[$field][$bound] ?? null);
                if ($value !== null) {
                    $normalized[$field][$bound] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Retains native PHP `filter_var` in production under the < 1.5x FFI threshold (`0.38x` mean,
     * `0.67x` p99 due to single-scalar FFI boundary overhead); see `normalizeRangeBoundNative`.
     */
    public static function normalizeRangeBound(mixed $value): ?int
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer >= 0 ? $integer : null;
    }

    public static function normalizeRangeBoundNative(mixed $value): ?int
    {
        if (! self::$useRustFfi || (! \is_int($value) && ! \is_string($value))) {
            return null;
        }
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if ($value === '') {
            return null;
        }
        $ffi = self::resolveFfi();
        $out = self::$outI64;
        if ($ffi === null || $out === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_search_normalize_range_bound($value, \strlen($value), FFI::addr($out));

            // @phpstan-ignore property.notFound
            return $rc === 1 ? (int) $out->cdata : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Retains native PHP `trim` in production under the < 1.5x FFI threshold (`0.33x` mean,
     * `0.50x` p99 due to single-scalar `FFI::string` allocation overhead); see `normalizeMediaUniqueIdNative`.
     */
    public static function normalizeMediaUniqueId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $mediaUniqueId = trim((string) $value);

        return $mediaUniqueId === '' || $mediaUniqueId === '-1' ? null : $mediaUniqueId;
    }

    public static function normalizeMediaUniqueIdNative(mixed $value): ?string
    {
        if (! self::$useRustFfi || ! \is_scalar($value)) {
            return null;
        }
        $str = (string) $value;
        $len = \strlen($str);
        if ($len === 0) {
            return null;
        }
        $ffi = self::resolveFfi();
        if ($ffi === null) {
            return null;
        }
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, max(64, $len), $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_search_normalize_media_unique_id($str, $len, $scratch, $cap);
            if (! \is_int($written)) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_search_normalize_media_unique_id($str, $len, $scratch, $cap);
            }

            return \is_int($written) && $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function useW415RustFfi(bool $enable = true): void
    {
        self::$useW415RustFfi = $enable;
    }

    public static function usesW415RustFfi(): bool
    {
        return self::$useW415RustFfi ?? self::W415_RUST_FFI_DEFAULT;
    }

    public static function resetW415RustFfi(): void
    {
        self::$useW415RustFfi = null;
    }

    /**
     * Summarizes a batch of `['sort_field' => ..., 'sort_dir' => ...]` specs with the rules of
     * `normalizedSortField()` and `normalizedSortDirection()`. Non-array specs are invalid; a missing
     * or non-string field falls back to `postdate_ts`, and only an (ASCII case-insensitive) `asc`
     * string direction is ascending. `distinct_fields` counts the normalized fields of valid specs.
     *
     * @param  array<array-key, mixed>  $specs
     * @return array{specs: int, invalid_specs: int, known_fields: int, fallback_fields: int, asc: int, desc: int, distinct_fields: int}
     */
    public static function summarizeSortSpecBatch(array $specs): array
    {
        return self::usesW415RustFfi() ? self::summarizeSortSpecBatchNative($specs) : self::summarizeSortSpecBatchPhp($specs);
    }

    /**
     * @param  array<array-key, mixed>  $specs
     * @return array{specs: int, invalid_specs: int, known_fields: int, fallback_fields: int, asc: int, desc: int, distinct_fields: int}
     */
    public static function summarizeSortSpecBatchPhp(array $specs): array
    {
        $invalid = 0;
        $known = 0;
        $asc = 0;
        $distinct = [];
        foreach ($specs as $spec) {
            if (! \is_array($spec)) {
                $invalid++;

                continue;
            }
            $field = $spec['sort_field'] ?? null;
            $direction = $spec['sort_dir'] ?? null;
            $isKnown = \is_string($field) && \in_array($field, self::W415_SORT_FIELDS, true);
            $known += (int) $isKnown;
            $distinct[$isKnown ? $field : self::W415_SORT_FIELDS[0]] = true;
            $asc += (int) (\is_string($direction) && strtolower($direction) === 'asc');
        }
        $valid = \count($specs) - $invalid;

        return [
            'specs' => \count($specs),
            'invalid_specs' => $invalid,
            'known_fields' => $known,
            'fallback_fields' => $valid - $known,
            'asc' => $asc,
            'desc' => $valid - $asc,
            'distinct_fields' => \count($distinct),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $specs
     * @return array{specs: int, invalid_specs: int, known_fields: int, fallback_fields: int, asc: int, desc: int, distinct_fields: int}
     */
    public static function summarizeSortSpecBatchNative(array $specs): array
    {
        $parts = [];
        foreach ($specs as $spec) {
            if (! \is_array($spec)) {
                array_push($parts, '0', '', '');

                continue;
            }
            $field = $spec['sort_field'] ?? null;
            $direction = $spec['sort_dir'] ?? null;
            array_push($parts, '1', \is_string($field) ? $field : '', \is_string($direction) ? $direction : '');
        }
        $fields = SearchCursor::evaluateW415Native(1, $parts);
        if ($fields === null || \count($fields) !== 7) {
            return self::summarizeSortSpecBatchPhp($specs);
        }

        return [
            'specs' => (int) $fields[0],
            'invalid_specs' => (int) $fields[1],
            'known_fields' => (int) $fields[2],
            'fallback_fields' => (int) $fields[3],
            'asc' => (int) $fields[4],
            'desc' => (int) $fields[5],
            'distinct_fields' => (int) $fields[6],
        ];
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('release_search_query', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outI64 = $ffi->new('int64_t');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }

    public const string W51_FFI_CDEF = 'int64_t nntmux_w515_release_query_normalize(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W51.5 parity test's QUALIFICATION). */
    private const bool W51_RUST_FFI_DEFAULT = true;

    private static ?bool $w51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$w51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with normalizeRequestBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$w51RustFfi;
        self::$w51RustFfi = $enabled;

        try {
            return $callback();
        } finally {
            self::$w51RustFfi = $previous;
        }
    }

    /**
     * Exactly $words output words from the Wave 51.5 Rust helper, or null to fall back to PHP. The helper
     * leads with the number of rows PHP must compute, returned in $patches.
     *
     * @return list<int>|null
     */
    private static function w51Words(int $count, string $data, string $ints, int $words, ?int &$patches = null): ?array
    {
        if (self::$w51Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w51Ffi = RustPipelineLibrary::resolveDomain('search_w51', self::W51_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w51Out = null;
            self::$w51OutCap = 0;
        }
        $ffi = self::$w51Ffi;
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            if (self::$w51Out === null || $words >= self::$w51OutCap) {
                $cap = max(1024, $words + 1);
                self::$w51Out = $ffi->new('int64_t['.$cap.']');
                self::$w51OutCap = $cap;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w515_release_query_normalize($count, 0, $data, \strlen($data), $ints, \strlen($ints), self::$w51Out, self::$w51OutCap);
            if ($written !== $words + 1) {
                return null;
            }
            $patches = self::$w51Out[0];
            /** @var array<int, int>|false $values */
            $values = unpack('P*', FFI::string(self::$w51Out, $written * 8), 8);

            return \is_array($values) ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** PHP `hash('xxh64')` of $value read as a signed big-endian word, as the Rust helper reports it. */
    private static function w51Digest(string $value): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', hash('xxh64', $value, true));

        return $unpacked[1];
    }

    /**
     * Batch normalization of request strings with the rules of normalizedSortField(),
     * normalizedSortDirection(), normalizeMediaUniqueId() and normalizeRangeBound(). Five words per request:
     * `[W415_SORT_FIELDS index (0 fallback) | known field << 3 | ascending << 4, min ID (-1 null), max ID
     * (-1 null), media unique ID length (-1 null), xxh64(media unique ID) (0 null)]`.
     *
     * @param  list<string>  $mediaIds
     * @param  list<string>  $sortFields
     * @param  list<string>  $sortDirs
     * @param  list<string>  $minIds
     * @param  list<string>  $maxIds
     * @return list<int>
     */
    public static function normalizeRequestBatch(array $mediaIds, array $sortFields, array $sortDirs, array $minIds, array $maxIds): array
    {
        return self::usesW51RustFfi()
            ? self::normalizeRequestBatchNative($mediaIds, $sortFields, $sortDirs, $minIds, $maxIds)
            : self::normalizeRequestBatchPhp($mediaIds, $sortFields, $sortDirs, $minIds, $maxIds);
    }

    /**
     * @param  list<string>  $mediaIds
     * @param  list<string>  $sortFields
     * @param  list<string>  $sortDirs
     * @param  list<string>  $minIds
     * @param  list<string>  $maxIds
     * @return list<int>
     */
    public static function normalizeRequestBatchPhp(array $mediaIds, array $sortFields, array $sortDirs, array $minIds, array $maxIds): array
    {
        $out = [];
        foreach ($sortFields as $i => $field) {
            $index = array_search($field, self::W415_SORT_FIELDS, true);
            $flags = ($index === false ? 0 : $index | 8) | (strtolower($sortDirs[$i]) === 'asc' ? 16 : 0);
            $media = self::normalizeMediaUniqueId($mediaIds[$i]);
            array_push(
                $out,
                $flags,
                self::normalizeRangeBound($minIds[$i]) ?? -1,
                self::normalizeRangeBound($maxIds[$i]) ?? -1,
                $media === null ? -1 : \strlen($media),
                $media === null ? 0 : self::w51Digest($media),
            );
        }

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the columns differ in length or a value
     * holds a NUL byte.
     *
     * @param  list<string>  $mediaIds
     * @param  list<string>  $sortFields
     * @param  list<string>  $sortDirs
     * @param  list<string>  $minIds
     * @param  list<string>  $maxIds
     * @return list<int>
     */
    public static function normalizeRequestBatchNative(array $mediaIds, array $sortFields, array $sortDirs, array $minIds, array $maxIds): array
    {
        $count = \count($sortFields);
        if ($count === 0 || \count($mediaIds) !== $count || \count($sortDirs) !== $count || \count($minIds) !== $count || \count($maxIds) !== $count
            || ! array_is_list($mediaIds) || ! array_is_list($sortFields) || ! array_is_list($sortDirs) || ! array_is_list($minIds) || ! array_is_list($maxIds)) {
            return self::normalizeRequestBatchPhp($mediaIds, $sortFields, $sortDirs, $minIds, $maxIds);
        }
        $data = implode("\0", $mediaIds)."\0".implode("\0", $sortFields)."\0".implode("\0", $sortDirs)."\0".implode("\0", $minIds)."\0".implode("\0", $maxIds);

        return self::w51Words($count, $data, '', 5 * $count) ?? self::normalizeRequestBatchPhp($mediaIds, $sortFields, $sortDirs, $minIds, $maxIds);
    }
}
