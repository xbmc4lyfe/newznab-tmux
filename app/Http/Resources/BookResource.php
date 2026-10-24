<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BookInfo;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * Book API Resource for transforming book data.
 *
 * @mixin BookInfo
 */
class BookResource extends JsonResource
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_book_resource_resolve_cover_key(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, uint32_t has_cover, char *out_buf, size_t out_cap);
        ssize_t nntmux_book_resource_normalize_payload(const char *ser_ptr, size_t ser_len, uint32_t has_cover_url, const char *cover_url_ptr, size_t cover_url_len, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustGenreResourcesMetadataConfigParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::$useRustFfi = $use;
        if ($use === null) {
            self::$ffi = null;
        }
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
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::usesRustFfi()
            ? $this->toArrayNative($request)
            : $this->toArrayPhp($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayNative(Request $request): array
    {
        return self::normalizePayloadNative($this->rawAttributes(), $this->getCoverUrlNative());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayPhp(Request $request): array
    {
        return self::normalizePayloadPhp($this->rawAttributes(), $this->getCoverUrlPhp());
    }

    /**
     * Normalize raw book resource attributes and resolved cover URL into the API response array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $attributes, ?string $coverUrl): array
    {
        return self::usesRustFfi()
            ? self::normalizePayloadNative($attributes, $coverUrl)
            : self::normalizePayloadPhp($attributes, $coverUrl);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadNative(array $attributes, ?string $coverUrl): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizePayloadPhp($attributes, $coverUrl);
        }
        $remaining = 1024;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizePayloadPhp($attributes, $coverUrl);
        }
        try {
            $ser = serialize($attributes);
            $serLen = \strlen($ser);
            $hasCoverUrl = $coverUrl !== null ? 1 : 0;
            $coverUrlStr = $coverUrl ?? '';
            $coverUrlLen = \strlen($coverUrlStr);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(512, $serLen + $coverUrlLen + 256), $capacity);
            if ($buffer === null) {
                return self::normalizePayloadPhp($attributes, $coverUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_resource_normalize_payload($ser, $serLen, $hasCoverUrl, $coverUrlStr, $coverUrlLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizePayloadPhp($attributes, $coverUrl);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_resource_normalize_payload($ser, $serLen, $hasCoverUrl, $coverUrlStr, $coverUrlLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizePayloadPhp($attributes, $coverUrl);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizePayloadPhp($attributes, $coverUrl);

            return $result;
        } catch (Throwable) {
            return self::normalizePayloadPhp($attributes, $coverUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadPhp(array $attributes, ?string $coverUrl): array
    {
        return [
            'id' => $attributes['id'] ?? null,
            'title' => $attributes['title'] ?? null,
            'author' => $attributes['author'] ?? null,
            'asin' => $attributes['asin'] ?? null,
            'isbn' => $attributes['isbn'] ?? null,
            'ean' => $attributes['ean'] ?? null,
            'url' => $attributes['url'] ?? null,
            'salesrank' => $attributes['salesrank'] ?? null,
            'publisher' => $attributes['publisher'] ?? null,
            'publishdate' => $attributes['publishdate'] ?? null,
            'pages' => $attributes['pages'] ?? null,
            'overview' => $attributes['overview'] ?? null,
            'genre' => $attributes['genre'] ?? null,
            'cover' => ! empty($attributes['cover']),
            'cover_url' => $coverUrl,
            'created_at' => $attributes['created_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? null,
        ];
    }

    /**
     * Resolve the cover image asset key (`(string) $id`) when `(int) $id > 0 && $cover`.
     */
    public static function resolveCoverAssetKey(mixed $id, mixed $cover): ?string
    {
        return self::usesRustFfi()
            ? self::resolveCoverAssetKeyNative($id, $cover)
            : self::resolveCoverAssetKeyPhp($id, $cover);
    }

    public static function resolveCoverAssetKeyNative(mixed $id, mixed $cover): ?string
    {
        if (($ffi = self::ffi()) === null) {
            return self::resolveCoverAssetKeyPhp($id, $cover);
        }
        $hasCover = ! empty($cover) ? 1 : 0;
        if ($id === null || $id === false) {
            $idKind = 0;
            $idInt = 0;
            $idStr = '';
        } elseif (\is_int($id)) {
            $idKind = 1;
            $idInt = $id;
            $idStr = '';
        } elseif (\is_string($id)) {
            $idKind = 2;
            $idInt = 0;
            $idStr = $id;
        } else {
            return self::resolveCoverAssetKeyPhp($id, $cover);
        }

        $idLen = \strlen($idStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $idLen + 16), $capacity);
        if ($buffer === null) {
            return self::resolveCoverAssetKeyPhp($id, $cover);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_book_resource_resolve_cover_key($idKind, $idInt, $idStr, $idLen, $hasCover, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::resolveCoverAssetKeyPhp($id, $cover);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_resource_resolve_cover_key($idKind, $idInt, $idStr, $idLen, $hasCover, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::resolveCoverAssetKeyPhp($id, $cover);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::resolveCoverAssetKeyPhp($id, $cover);
        }
    }

    public static function resolveCoverAssetKeyPhp(mixed $id, mixed $cover): ?string
    {
        if ((int) $id <= 0 || ! $cover) {
            return null;
        }

        return (string) $id;
    }

    /**
     * Get the cover image URL.
     */
    public function getCoverUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getCoverUrlNative()
            : $this->getCoverUrlPhp();
    }

    public function getCoverUrlNative(): ?string
    {
        $assetKey = self::resolveCoverAssetKeyNative($this->id, $this->cover);
        if ($assetKey === null) {
            return null;
        }

        return getImageAssetUrl('book', $assetKey);
    }

    public function getCoverUrlPhp(): ?string
    {
        if ((int) $this->id <= 0 || ! $this->cover) {
            return null;
        }

        return getImageAssetUrl('book', (string) $this->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAttributes(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'author' => $this->author,
            'asin' => $this->asin,
            'isbn' => $this->isbn,
            'ean' => $this->ean,
            'url' => $this->url,
            'salesrank' => $this->salesrank,
            'publisher' => $this->publisher,
            'publishdate' => $this->publishdate,
            'pages' => $this->pages,
            'overview' => $this->overview,
            'genre' => $this->genre,
            'cover' => $this->cover,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
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
}
