<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MovieInfo;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * Movie API Resource for transforming movie data.
 *
 * @mixin MovieInfo
 */
class MovieResource extends JsonResource
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_movie_resource_format_asset_or_url(const char *imdb_ptr, size_t imdb_len, uint32_t has_imdb, uint32_t flag, int32_t kind, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_resource_normalize_payload(const char *ser_ptr, size_t ser_len, uint32_t has_cover_url, const char *cover_url_ptr, size_t cover_url_len, uint32_t has_backdrop_url, const char *backdrop_url_ptr, size_t backdrop_url_len, char *out_buf, size_t out_cap);
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
        return self::normalizePayloadNative(
            $this->rawAttributes(),
            $this->getCoverUrlNative(),
            $this->getBackdropUrlNative(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayPhp(Request $request): array
    {
        return self::normalizePayloadPhp(
            $this->rawAttributes(),
            $this->getCoverUrlPhp(),
            $this->getBackdropUrlPhp(),
        );
    }

    /**
     * Normalize raw movie resource attributes and resolved cover/backdrop URLs into the API response array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $attributes, ?string $coverUrl, ?string $backdropUrl): array
    {
        return self::usesRustFfi()
            ? self::normalizePayloadNative($attributes, $coverUrl, $backdropUrl)
            : self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadNative(array $attributes, ?string $coverUrl, ?string $backdropUrl): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
        }
        $remaining = 1024;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
        }
        try {
            $ser = serialize($attributes);
            $serLen = \strlen($ser);
            $hasCoverUrl = $coverUrl !== null ? 1 : 0;
            $coverUrlStr = $coverUrl ?? '';
            $coverUrlLen = \strlen($coverUrlStr);
            $hasBackdropUrl = $backdropUrl !== null ? 1 : 0;
            $backdropUrlStr = $backdropUrl ?? '';
            $backdropUrlLen = \strlen($backdropUrlStr);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(1024, $serLen + $coverUrlLen + $backdropUrlLen + 512), $capacity);
            if ($buffer === null) {
                return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_resource_normalize_payload(
                $ser,
                $serLen,
                $hasCoverUrl,
                $coverUrlStr,
                $coverUrlLen,
                $hasBackdropUrl,
                $backdropUrlStr,
                $backdropUrlLen,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_movie_resource_normalize_payload(
                    $ser,
                    $serLen,
                    $hasCoverUrl,
                    $coverUrlStr,
                    $coverUrlLen,
                    $hasBackdropUrl,
                    $backdropUrlStr,
                    $backdropUrlLen,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);

            return $result;
        } catch (Throwable) {
            return self::normalizePayloadPhp($attributes, $coverUrl, $backdropUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadPhp(array $attributes, ?string $coverUrl, ?string $backdropUrl): array
    {
        $imdbid = $attributes['imdbid'] ?? null;

        return [
            'id' => $attributes['id'] ?? null,
            'imdbid' => $imdbid,
            'tmdbid' => $attributes['tmdbid'] ?? null,
            'traktid' => $attributes['traktid'] ?? null,
            'title' => $attributes['title'] ?? null,
            'year' => $attributes['year'] ?? null,
            'rating' => $attributes['rating'] ?? null,
            'rtrating' => $attributes['rtrating'] ?? null,
            'tagline' => $attributes['tagline'] ?? null,
            'plot' => $attributes['plot'] ?? null,
            'genre' => $attributes['genre'] ?? null,
            'type' => $attributes['type'] ?? null,
            'director' => $attributes['director'] ?? null,
            'actors' => $attributes['actors'] ?? null,
            'language' => $attributes['language'] ?? null,
            'cover' => ! empty($attributes['cover']),
            'backdrop' => ! empty($attributes['backdrop']),
            'trailer' => $attributes['trailer'] ?? null,
            'cover_url' => $coverUrl,
            'backdrop_url' => $backdropUrl,
            'imdb_url' => $imdbid ? 'https://www.imdb.com/title/tt'.$imdbid.'/' : null,
            'created_at' => $attributes['created_at'] ?? null,
            'updated_at' => $attributes['updated_at'] ?? null,
        ];
    }

    /**
     * Resolve the movie cover asset key (`<imdbid>-cover`).
     */
    public static function resolveCoverAssetKey(mixed $imdbid, mixed $cover): ?string
    {
        return self::usesRustFfi()
            ? self::resolveCoverAssetKeyNative($imdbid, $cover)
            : self::resolveCoverAssetKeyPhp($imdbid, $cover);
    }

    public static function resolveCoverAssetKeyNative(mixed $imdbid, mixed $cover): ?string
    {
        $res = self::formatAssetOrUrlNative($imdbid, ! empty($cover), 0);

        return $res === false ? self::resolveCoverAssetKeyPhp($imdbid, $cover) : $res;
    }

    public static function resolveCoverAssetKeyPhp(mixed $imdbid, mixed $cover): ?string
    {
        if (! $cover || ! $imdbid) {
            return null;
        }

        return (string) $imdbid.'-cover';
    }

    /**
     * Resolve the movie backdrop asset key (`<imdbid>-backdrop`).
     */
    public static function resolveBackdropAssetKey(mixed $imdbid, mixed $backdrop): ?string
    {
        return self::usesRustFfi()
            ? self::resolveBackdropAssetKeyNative($imdbid, $backdrop)
            : self::resolveBackdropAssetKeyPhp($imdbid, $backdrop);
    }

    public static function resolveBackdropAssetKeyNative(mixed $imdbid, mixed $backdrop): ?string
    {
        $res = self::formatAssetOrUrlNative($imdbid, ! empty($backdrop), 1);

        return $res === false ? self::resolveBackdropAssetKeyPhp($imdbid, $backdrop) : $res;
    }

    public static function resolveBackdropAssetKeyPhp(mixed $imdbid, mixed $backdrop): ?string
    {
        if (! $backdrop || ! $imdbid) {
            return null;
        }

        return (string) $imdbid.'-backdrop';
    }

    /**
     * Format the IMDb title URL (`https://www.imdb.com/title/tt<imdbid>/`).
     */
    public static function formatImdbUrl(mixed $imdbid): ?string
    {
        return self::usesRustFfi()
            ? self::formatImdbUrlNative($imdbid)
            : self::formatImdbUrlPhp($imdbid);
    }

    public static function formatImdbUrlNative(mixed $imdbid): ?string
    {
        $res = self::formatAssetOrUrlNative($imdbid, true, 2);

        return $res === false ? self::formatImdbUrlPhp($imdbid) : $res;
    }

    public static function formatImdbUrlPhp(mixed $imdbid): ?string
    {
        return $imdbid ? 'https://www.imdb.com/title/tt'.$imdbid.'/' : null;
    }

    /**
     * Get the cover image URL using the original imdbid as stored in the database.
     */
    public function getCoverUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getCoverUrlNative()
            : $this->getCoverUrlPhp();
    }

    public function getCoverUrlNative(): ?string
    {
        $assetKey = self::resolveCoverAssetKeyNative($this->imdbid, $this->cover);
        if ($assetKey === null) {
            return null;
        }

        return getImageAssetUrl('movies', $assetKey);
    }

    public function getCoverUrlPhp(): ?string
    {
        if (! $this->cover || ! $this->imdbid) {
            return null;
        }

        return getImageAssetUrl('movies', (string) $this->imdbid.'-cover');
    }

    /**
     * Get the backdrop image URL using the original imdbid as stored in the database.
     */
    public function getBackdropUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getBackdropUrlNative()
            : $this->getBackdropUrlPhp();
    }

    public function getBackdropUrlNative(): ?string
    {
        $assetKey = self::resolveBackdropAssetKeyNative($this->imdbid, $this->backdrop);
        if ($assetKey === null) {
            return null;
        }

        return getImageAssetUrl('movies', $assetKey);
    }

    public function getBackdropUrlPhp(): ?string
    {
        if (! $this->backdrop || ! $this->imdbid) {
            return null;
        }

        return getImageAssetUrl('movies', (string) $this->imdbid.'-backdrop');
    }

    /**
     * @return string|null|false `false` when declined to PHP fallback
     */
    private static function formatAssetOrUrlNative(mixed $imdbid, bool $flag, int $kind): string|null|false
    {
        if (($ffi = self::ffi()) === null) {
            return false;
        }
        if (\is_object($imdbid) || \is_array($imdbid)) {
            return false;
        }
        $hasImdb = ! empty($imdbid) ? 1 : 0;
        $imdbStr = $hasImdb === 1 ? (string) $imdbid : '';
        $imdbLen = \strlen($imdbStr);
        $flagInt = $flag ? 1 : 0;
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $imdbLen + 48), $capacity);
        if ($buffer === null) {
            return false;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_resource_format_asset_or_url($imdbStr, $imdbLen, $hasImdb, $flagInt, $kind, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return false;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_movie_resource_format_asset_or_url($imdbStr, $imdbLen, $hasImdb, $flagInt, $kind, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return false;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAttributes(): array
    {
        return [
            'id' => $this->id,
            'imdbid' => $this->imdbid,
            'tmdbid' => $this->tmdbid,
            'traktid' => $this->traktid,
            'title' => $this->title,
            'year' => $this->year,
            'rating' => $this->rating,
            'rtrating' => $this->rtrating,
            'tagline' => $this->tagline,
            'plot' => $this->plot,
            'genre' => $this->genre,
            'type' => $this->type,
            'director' => $this->director,
            'actors' => $this->actors,
            'language' => $this->language,
            'cover' => $this->cover,
            'backdrop' => $this->backdrop,
            'trailer' => $this->trailer,
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
