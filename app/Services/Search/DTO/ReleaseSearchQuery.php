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
}
