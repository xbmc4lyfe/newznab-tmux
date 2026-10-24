<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Throwable;

/**
 * AniDB Collection API Resource for transforming anime collections.
 */
class AnidbCollection extends ResourceCollection
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_anidb_collection_resolve_meta(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, int64_t *out_meta, size_t meta_cap);
        ssize_t nntmux_anidb_collection_build_envelope(uint32_t has_total, int64_t total_val, uint32_t has_per_page, int64_t per_page_val, uint32_t has_current_page, int64_t current_page_val, uint32_t has_last_page, int64_t last_page_val, int64_t collection_count, uint32_t has_first, const char *first_ptr, size_t first_len, uint32_t has_last, const char *last_ptr, size_t last_len, uint32_t has_prev, const char *prev_ptr, size_t prev_len, uint32_t has_next, const char *next_ptr, size_t next_len, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustConsoleMovieTvStatusCollectionsParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
    }

    public static function enableRustFfi(): void
    {
        self::setUseRustFfi(true);
    }

    public static function disableRustFfi(): void
    {
        self::setUseRustFfi(false);
    }

    public static function resetRustFfi(): void
    {
        self::setUseRustFfi(null);
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = AnidbResource::class;

    /**
     * Transform the resource collection into an array.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::usesRustFfi()
            ? $this->toArrayNative($request)
            : $this->toArrayPhp($request);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArrayNative(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toArrayPhp(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }

    /**
     * Transform the resource collection into a paginated array with `data`, `meta`, and `links`.
     *
     * @return array<int|string, mixed>
     */
    public function toPaginatedArray(Request $request): array
    {
        return self::usesRustFfi()
            ? $this->toPaginatedArrayNative($request)
            : $this->toPaginatedArrayPhp($request);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toPaginatedArrayNative(Request $request): array
    {
        $hasTotal = method_exists($this->resource, 'total');
        $hasPerPage = method_exists($this->resource, 'perPage');
        $hasCurrentPage = method_exists($this->resource, 'currentPage');
        $hasLastPage = method_exists($this->resource, 'lastPage');

        $total = $hasTotal ? (int) $this->resource->total() : null;
        $perPage = $hasPerPage ? (int) $this->resource->perPage() : null;
        $currentPage = $hasCurrentPage ? (int) $this->resource->currentPage() : null;
        $lastPage = $hasLastPage ? (int) $this->resource->lastPage() : null;
        $resolvedLastPage = $lastPage ?? 1;

        $envelope = self::buildPaginationEnvelopeNative(
            $total,
            $perPage,
            $currentPage,
            $lastPage,
            $this->collection->count(),
            $this->url(1),
            $this->url($resolvedLastPage),
            $this->previousPageUrl(),
            $this->nextPageUrl(),
        );

        return [
            'data' => $this->collection,
            'meta' => $envelope['meta'],
            'links' => $envelope['links'],
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function toPaginatedArrayPhp(Request $request): array
    {
        return [
            'data' => $this->collection,
            'meta' => [
                'total' => $this->total(),
                'per_page' => $this->perPage(),
                'current_page' => $this->currentPage(),
                'last_page' => $this->lastPage(),
            ],
            'links' => [
                'first' => $this->url(1),
                'last' => $this->url($this->lastPage()),
                'prev' => $this->previousPageUrl(),
                'next' => $this->nextPageUrl(),
            ],
        ];
    }

    /**
     * Resolve the 4 pagination metadata integers (`total`, `per_page`, `current_page`, `last_page`).
     *
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    public static function resolveMeta(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
    ): array {
        return self::usesRustFfi()
            ? self::resolveMetaNative($total, $perPage, $currentPage, $lastPage, $collectionCount)
            : self::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $collectionCount);
    }

    /**
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    public static function resolveMetaNative(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
    ): array {
        if (($ffi = self::ffi()) === null || $collectionCount < 0) {
            return self::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $collectionCount);
        }
        try {
            $outMeta = $ffi->new('int64_t[4]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_collection_resolve_meta(
                $total !== null ? 1 : 0,
                $total ?? 0,
                $perPage !== null ? 1 : 0,
                $perPage ?? 0,
                $currentPage !== null ? 1 : 0,
                $currentPage ?? 0,
                $lastPage !== null ? 1 : 0,
                $lastPage ?? 0,
                $collectionCount,
                $outMeta,
                4,
            );
            if ($written === 4) {
                return [
                    'total' => (int) $outMeta[0],
                    'per_page' => (int) $outMeta[1],
                    'current_page' => (int) $outMeta[2],
                    'last_page' => (int) $outMeta[3],
                ];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $collectionCount);
    }

    /**
     * @return array{total: int, per_page: int, current_page: int, last_page: int}
     */
    public static function resolveMetaPhp(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
    ): array {
        return [
            'total' => $total ?? $collectionCount,
            'per_page' => $perPage ?? $collectionCount,
            'current_page' => $currentPage ?? 1,
            'last_page' => $lastPage ?? 1,
        ];
    }

    /**
     * Build the `meta` and `links` pagination envelope for `AnidbCollection`.
     *
     * @return array{meta: array{total: int, per_page: int, current_page: int, last_page: int}, links: array{first: ?string, last: ?string, prev: ?string, next: ?string}}
     */
    public static function buildPaginationEnvelope(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
        ?string $firstUrl,
        ?string $lastUrl,
        ?string $prevUrl,
        ?string $nextUrl,
    ): array {
        return self::usesRustFfi()
            ? self::buildPaginationEnvelopeNative(
                $total,
                $perPage,
                $currentPage,
                $lastPage,
                $collectionCount,
                $firstUrl,
                $lastUrl,
                $prevUrl,
                $nextUrl,
            )
            : self::buildPaginationEnvelopePhp(
                $total,
                $perPage,
                $currentPage,
                $lastPage,
                $collectionCount,
                $firstUrl,
                $lastUrl,
                $prevUrl,
                $nextUrl,
            );
    }

    /**
     * @return array{meta: array{total: int, per_page: int, current_page: int, last_page: int}, links: array{first: ?string, last: ?string, prev: ?string, next: ?string}}
     */
    public static function buildPaginationEnvelopeNative(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
        ?string $firstUrl,
        ?string $lastUrl,
        ?string $prevUrl,
        ?string $nextUrl,
    ): array {
        if (($ffi = self::ffi()) === null || $collectionCount < 0) {
            return self::buildPaginationEnvelopePhp(
                $total,
                $perPage,
                $currentPage,
                $lastPage,
                $collectionCount,
                $firstUrl,
                $lastUrl,
                $prevUrl,
                $nextUrl,
            );
        }
        $firstStr = $firstUrl ?? '';
        $firstLen = \strlen($firstStr);
        $lastStr = $lastUrl ?? '';
        $lastLen = \strlen($lastStr);
        $prevStr = $prevUrl ?? '';
        $prevLen = \strlen($prevStr);
        $nextStr = $nextUrl ?? '';
        $nextLen = \strlen($nextStr);

        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch(
            $ffi,
            max(512, $firstLen + $lastLen + $prevLen + $nextLen + 320),
            $capacity,
        );
        if ($buffer === null) {
            return self::buildPaginationEnvelopePhp(
                $total,
                $perPage,
                $currentPage,
                $lastPage,
                $collectionCount,
                $firstUrl,
                $lastUrl,
                $prevUrl,
                $nextUrl,
            );
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_collection_build_envelope(
                $total !== null ? 1 : 0,
                $total ?? 0,
                $perPage !== null ? 1 : 0,
                $perPage ?? 0,
                $currentPage !== null ? 1 : 0,
                $currentPage ?? 0,
                $lastPage !== null ? 1 : 0,
                $lastPage ?? 0,
                $collectionCount,
                $firstUrl !== null ? 1 : 0,
                $firstStr,
                $firstLen,
                $lastUrl !== null ? 1 : 0,
                $lastStr,
                $lastLen,
                $prevUrl !== null ? 1 : 0,
                $prevStr,
                $prevLen,
                $nextUrl !== null ? 1 : 0,
                $nextStr,
                $nextLen,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildPaginationEnvelopePhp(
                        $total,
                        $perPage,
                        $currentPage,
                        $lastPage,
                        $collectionCount,
                        $firstUrl,
                        $lastUrl,
                        $prevUrl,
                        $nextUrl,
                    );
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_collection_build_envelope(
                    $total !== null ? 1 : 0,
                    $total ?? 0,
                    $perPage !== null ? 1 : 0,
                    $perPage ?? 0,
                    $currentPage !== null ? 1 : 0,
                    $currentPage ?? 0,
                    $lastPage !== null ? 1 : 0,
                    $lastPage ?? 0,
                    $collectionCount,
                    $firstUrl !== null ? 1 : 0,
                    $firstStr,
                    $firstLen,
                    $lastUrl !== null ? 1 : 0,
                    $lastStr,
                    $lastLen,
                    $prevUrl !== null ? 1 : 0,
                    $prevStr,
                    $prevLen,
                    $nextUrl !== null ? 1 : 0,
                    $nextStr,
                    $nextLen,
                    $buffer,
                    $capacity,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $capacity) {
                $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);
                if (\is_array($decoded) && isset($decoded['meta'], $decoded['links'])) {
                    /** @var array{meta: array{total: int, per_page: int, current_page: int, last_page: int}, links: array{first: ?string, last: ?string, prev: ?string, next: ?string}} $decoded */
                    return $decoded;
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::buildPaginationEnvelopePhp(
            $total,
            $perPage,
            $currentPage,
            $lastPage,
            $collectionCount,
            $firstUrl,
            $lastUrl,
            $prevUrl,
            $nextUrl,
        );
    }

    /**
     * @return array{meta: array{total: int, per_page: int, current_page: int, last_page: int}, links: array{first: ?string, last: ?string, prev: ?string, next: ?string}}
     */
    public static function buildPaginationEnvelopePhp(
        ?int $total,
        ?int $perPage,
        ?int $currentPage,
        ?int $lastPage,
        int $collectionCount,
        ?string $firstUrl,
        ?string $lastUrl,
        ?string $prevUrl,
        ?string $nextUrl,
    ): array {
        return [
            'meta' => self::resolveMetaPhp($total, $perPage, $currentPage, $lastPage, $collectionCount),
            'links' => [
                'first' => $firstUrl,
                'last' => $lastUrl,
                'prev' => $prevUrl,
                'next' => $nextUrl,
            ],
        ];
    }

    /**
     * Get total items count when available.
     */
    protected function total(): int
    {
        if (method_exists($this->resource, 'total')) {
            return $this->resource->total();
        }

        return $this->collection->count();
    }

    /**
     * Get items per page when available.
     */
    protected function perPage(): int
    {
        if (method_exists($this->resource, 'perPage')) {
            return $this->resource->perPage();
        }

        return $this->collection->count();
    }

    /**
     * Get current page when available.
     */
    protected function currentPage(): int
    {
        if (method_exists($this->resource, 'currentPage')) {
            return $this->resource->currentPage();
        }

        return 1;
    }

    /**
     * Get last page when available.
     */
    protected function lastPage(): int
    {
        if (method_exists($this->resource, 'lastPage')) {
            return $this->resource->lastPage();
        }

        return 1;
    }

    /**
     * Get URL for a specific page.
     */
    protected function url(int $page): ?string
    {
        if (method_exists($this->resource, 'url')) {
            return $this->resource->url($page);
        }

        return null;
    }

    /**
     * Get previous page URL.
     */
    protected function previousPageUrl(): ?string
    {
        if (method_exists($this->resource, 'previousPageUrl')) {
            return $this->resource->previousPageUrl();
        }

        return null;
    }

    /**
     * Get next page URL.
     */
    protected function nextPageUrl(): ?string
    {
        if (method_exists($this->resource, 'nextPageUrl')) {
            return $this->resource->nextPageUrl();
        }

        return null;
    }
}
