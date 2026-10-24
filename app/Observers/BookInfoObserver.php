<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\BookInfo;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookInfoObserver
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_book_info_observer_doc_id(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, int64_t *out_doc_id);
        ssize_t nntmux_book_info_observer_search_doc(const char *ser_ptr, size_t ser_len, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustSecondaryIndexObserversMetadataParityTest). */
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
        self::$ffi = null;
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
     * Resolve the integer secondary index document ID for a `BookInfo` model.
     */
    public static function resolveDocumentId(BookInfo $bookInfo): int
    {
        return self::usesRustFfi()
            ? self::resolveDocumentIdNative($bookInfo)
            : self::resolveDocumentIdPhp($bookInfo);
    }

    public static function resolveDocumentIdNative(BookInfo $bookInfo): int
    {
        return self::resolveDocumentIdFromAttributesNative($bookInfo->getAttributes());
    }

    public static function resolveDocumentIdPhp(BookInfo $bookInfo): int
    {
        return self::resolveDocumentIdFromAttributesPhp($bookInfo->getAttributes());
    }

    /**
     * Resolve the integer secondary index document ID from raw `BookInfo` attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributes(array $attributes): int
    {
        return self::usesRustFfi()
            ? self::resolveDocumentIdFromAttributesNative($attributes)
            : self::resolveDocumentIdFromAttributesPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributesNative(array $attributes): int
    {
        if (($ffi = self::ffi()) === null) {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }
        $rawId = $attributes['id'] ?? null;
        if ($rawId === null) {
            $idKind = 0;
            $idInt = 0;
            $idStr = '';
        } elseif (\is_int($rawId) || \is_bool($rawId)) {
            $idKind = 1;
            $idInt = (int) $rawId;
            $idStr = '';
        } elseif (\is_string($rawId)) {
            $idKind = 2;
            $idInt = 0;
            $idStr = $rawId;
        } else {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }

        try {
            $outDocId = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_book_info_observer_doc_id(
                $idKind,
                $idInt,
                $idStr,
                \strlen($idStr),
                $outDocId,
            );

            return $rc === 1 && isset($outDocId[0]) && \is_int($outDocId[0])
                ? $outDocId[0]
                : self::resolveDocumentIdFromAttributesPhp($attributes);
        } catch (Throwable) {
            return self::resolveDocumentIdFromAttributesPhp($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function resolveDocumentIdFromAttributesPhp(array $attributes): int
    {
        return (int) ($attributes['id'] ?? 0);
    }

    /**
     * Build the normalized 4-key book search document passed to `Search::insertSecondary()`.
     *
     * @return array<string, mixed>
     */
    public static function buildSearchDocument(BookInfo $bookInfo): array
    {
        return self::usesRustFfi()
            ? self::buildSearchDocumentNative($bookInfo)
            : self::buildSearchDocumentPhp($bookInfo);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentNative(BookInfo $bookInfo): array
    {
        return self::normalizeSearchDocumentNative(self::extractAttributes($bookInfo));
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildSearchDocumentPhp(BookInfo $bookInfo): array
    {
        return self::normalizeSearchDocumentPhp(self::extractAttributes($bookInfo));
    }

    /**
     * Normalize raw `BookInfo` attributes into the 4-key secondary search document.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocument(array $attributes): array
    {
        return self::usesRustFfi()
            ? self::normalizeSearchDocumentNative($attributes)
            : self::normalizeSearchDocumentPhp($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentNative(array $attributes): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        $remaining = 256;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
        try {
            $serialized = serialize($attributes);
            $serLen = \strlen($serialized);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $serLen + 256), $capacity);
            if ($buffer === null) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_info_observer_search_doc($serialized, $serLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeSearchDocumentPhp($attributes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_info_observer_search_doc($serialized, $serLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizeSearchDocumentPhp($attributes);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizeSearchDocumentPhp($attributes);

            return $result;
        } catch (Throwable) {
            return self::normalizeSearchDocumentPhp($attributes);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizeSearchDocumentPhp(array $attributes): array
    {
        return [
            'title' => (string) ($attributes['title'] ?? ''),
            'author' => (string) ($attributes['author'] ?? ''),
            'publishdate' => (string) ($attributes['publishdate'] ?? ''),
            'cover' => (int) ($attributes['cover'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractAttributes(BookInfo $bookInfo): array
    {
        $raw = $bookInfo->getAttributes();

        return [
            'title' => $raw['title'] ?? null,
            'author' => $raw['author'] ?? null,
            'publishdate' => $raw['publishdate'] ?? null,
            'cover' => $raw['cover'] ?? null,
        ];
    }

    private static function isSafeSerializableValue(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 16) {
            return false;
        }
        if (\is_array($value)) {
            foreach ($value as $v) {
                if (! self::isSafeSerializableValue($v, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || \is_int($value) || \is_string($value) || \is_bool($value);
    }

    public function created(BookInfo $bookInfo): void
    {
        $this->sync($bookInfo);
    }

    public function updated(BookInfo $bookInfo): void
    {
        $this->sync($bookInfo);
    }

    public function deleted(BookInfo $bookInfo): void
    {
        try {
            Search::deleteSecondary(SecondarySearchIndex::Books, self::resolveDocumentId($bookInfo));
        } catch (Throwable $e) {
            Log::error('BookInfoObserver: delete from search index failed', [
                'id' => $bookInfo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sync(BookInfo $bookInfo): void
    {
        try {
            Search::insertSecondary(
                SecondarySearchIndex::Books,
                self::resolveDocumentId($bookInfo),
                self::buildSearchDocument($bookInfo)
            );
        } catch (Throwable $e) {
            Log::error('BookInfoObserver: sync to search index failed', [
                'id' => $bookInfo->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
