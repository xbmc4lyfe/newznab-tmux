<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AnidbInfo;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

/**
 * AniDB API Resource for transforming anime data.
 *
 * @mixin AnidbInfo
 */
class AnidbResource extends JsonResource
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_anidb_resource_resolve_picture(const char *picture_ptr, size_t picture_len, uint32_t has_picture, const char *anidbid_ptr, size_t anidbid_len, int32_t *out_mode, char *out_buf, size_t out_cap);
        ssize_t nntmux_anidb_resource_normalize_payload(const char *ser_ptr, size_t ser_len, uint32_t has_picture_url, const char *picture_url_ptr, size_t picture_url_len, char *out_buf, size_t out_cap);
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
        return self::normalizePayloadNative($this->rawAttributes(), $this->getPictureUrlNative());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayPhp(Request $request): array
    {
        return self::normalizePayloadPhp($this->rawAttributes(), $this->getPictureUrlPhp());
    }

    /**
     * Normalize raw AniDB resource attributes and resolved picture URL into the API response array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $attributes, ?string $pictureUrl): array
    {
        return self::usesRustFfi()
            ? self::normalizePayloadNative($attributes, $pictureUrl)
            : self::normalizePayloadPhp($attributes, $pictureUrl);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadNative(array $attributes, ?string $pictureUrl): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizePayloadPhp($attributes, $pictureUrl);
        }
        $remaining = 1024;
        if (! self::isSafeSerializableValue($attributes, $remaining, 0)) {
            return self::normalizePayloadPhp($attributes, $pictureUrl);
        }
        try {
            $ser = serialize($attributes);
            $serLen = \strlen($ser);
            $hasPicUrl = $pictureUrl !== null ? 1 : 0;
            $picUrlStr = $pictureUrl ?? '';
            $picUrlLen = \strlen($picUrlStr);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(1024, $serLen + $picUrlLen + 512), $capacity);
            if ($buffer === null) {
                return self::normalizePayloadPhp($attributes, $pictureUrl);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_resource_normalize_payload($ser, $serLen, $hasPicUrl, $picUrlStr, $picUrlLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizePayloadPhp($attributes, $pictureUrl);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_resource_normalize_payload($ser, $serLen, $hasPicUrl, $picUrlStr, $picUrlLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::normalizePayloadPhp($attributes, $pictureUrl);
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, mixed> $result */
            $result = \is_array($decoded) ? $decoded : self::normalizePayloadPhp($attributes, $pictureUrl);

            return $result;
        } catch (Throwable) {
            return self::normalizePayloadPhp($attributes, $pictureUrl);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function normalizePayloadPhp(array $attributes, ?string $pictureUrl): array
    {
        return [
            'anidbid' => $attributes['anidbid'] ?? null,
            'anilist_id' => $attributes['anilist_id'] ?? null,
            'mal_id' => $attributes['mal_id'] ?? null,
            'title' => $attributes['title'] ?? null,
            'english_title' => $attributes['english_title'] ?? null,
            'original_title' => $attributes['original_title'] ?? null,
            'romaji_title' => $attributes['romaji_title'] ?? null,
            'type' => $attributes['type'] ?? null,
            'media_type' => $attributes['media_type'] ?? null,
            'country' => $attributes['country'] ?? null,
            'startdate' => $attributes['startdate'] ?? null,
            'enddate' => $attributes['enddate'] ?? null,
            'episodes' => $attributes['episodes'] ?? null,
            'duration' => $attributes['duration'] ?? null,
            'status' => $attributes['status'] ?? null,
            'source' => $attributes['source'] ?? null,
            'rating' => $attributes['rating'] ?? null,
            'description' => $attributes['description'] ?? null,
            'picture' => $attributes['picture'] ?? null,
            'categories' => $attributes['categories'] ?? null,
            'characters' => $attributes['characters'] ?? null,
            'creators' => $attributes['creators'] ?? null,
            'related' => $attributes['related'] ?? null,
            'similar' => $attributes['similar'] ?? null,
            'hashtag' => $attributes['hashtag'] ?? null,
            'updated' => $attributes['updated'] ?? null,
            'picture_url' => $pictureUrl,
        ];
    }

    /**
     * Resolve whether `picture` is empty, a direct URL, or a local anime cover asset key.
     *
     * @return array{mode: 'url'|'asset', value: string, alternate: string}|null
     */
    public static function resolvePictureTarget(mixed $picture, mixed $anidbid): ?array
    {
        return self::usesRustFfi()
            ? self::resolvePictureTargetNative($picture, $anidbid)
            : self::resolvePictureTargetPhp($picture, $anidbid);
    }

    /**
     * @return array{mode: 'url'|'asset', value: string, alternate: string}|null
     */
    public static function resolvePictureTargetNative(mixed $picture, mixed $anidbid): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return self::resolvePictureTargetPhp($picture, $anidbid);
        }
        if (\is_object($picture) || \is_array($picture) || \is_object($anidbid) || \is_array($anidbid)) {
            return self::resolvePictureTargetPhp($picture, $anidbid);
        }
        $hasPicture = ! empty($picture) ? 1 : 0;
        $picStr = $hasPicture === 1 ? (string) $picture : '';
        $anidbStr = (string) ($anidbid ?? '');
        $picLen = \strlen($picStr);
        $anidbLen = \strlen($anidbStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $picLen + $anidbLen * 2 + 16), $capacity);
        if ($buffer === null) {
            return self::resolvePictureTargetPhp($picture, $anidbid);
        }
        try {
            $outMode = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_resource_resolve_picture($picStr, $picLen, $hasPicture, $anidbStr, $anidbLen, $outMode, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::resolvePictureTargetPhp($picture, $anidbid);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_resource_resolve_picture($picStr, $picLen, $hasPicture, $anidbStr, $anidbLen, $outMode, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::resolvePictureTargetPhp($picture, $anidbid);
            }
            $mode = (int) $outMode[0];
            if ($mode === 0) {
                return null;
            }
            $payload = $written === 0 ? '' : FFI::string($buffer, $written);
            if ($mode === 1) {
                return ['mode' => 'url', 'value' => $payload, 'alternate' => ''];
            }
            if ($mode === 2) {
                $parts = explode("\0", $payload, 2);
                if (\count($parts) === 2) {
                    return ['mode' => 'asset', 'value' => $parts[0], 'alternate' => $parts[1]];
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::resolvePictureTargetPhp($picture, $anidbid);
    }

    /**
     * @return array{mode: 'url'|'asset', value: string, alternate: string}|null
     */
    public static function resolvePictureTargetPhp(mixed $picture, mixed $anidbid): ?array
    {
        if (empty($picture)) {
            return null;
        }
        if (filter_var($picture, FILTER_VALIDATE_URL)) {
            return ['mode' => 'url', 'value' => (string) $picture, 'alternate' => ''];
        }
        $anidbStr = (string) $anidbid;

        return ['mode' => 'asset', 'value' => $anidbStr.'-cover', 'alternate' => $anidbStr];
    }

    /**
     * Get the picture URL.
     */
    public function getPictureUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getPictureUrlNative()
            : $this->getPictureUrlPhp();
    }

    public function getPictureUrlNative(): ?string
    {
        $target = self::resolvePictureTargetNative($this->picture, $this->anidbid);
        if ($target === null) {
            return null;
        }
        if ($target['mode'] === 'url') {
            return $target['value'];
        }

        return getImageAssetUrl('anime', $target['value'], null, [$target['alternate']]);
    }

    public function getPictureUrlPhp(): ?string
    {
        if (empty($this->picture)) {
            return null;
        }

        // If picture is already a URL, return it
        if (filter_var($this->picture, FILTER_VALIDATE_URL)) {
            return $this->picture;
        }

        // Otherwise construct the local path
        return getImageAssetUrl(
            'anime',
            $this->anidbid.'-cover',
            null,
            [(string) $this->anidbid]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rawAttributes(): array
    {
        return [
            'anidbid' => $this->anidbid,
            'anilist_id' => $this->anilist_id,
            'mal_id' => $this->mal_id,
            'title' => $this->title,
            'english_title' => $this->english_title ?? null,
            'original_title' => $this->original_title ?? null,
            'romaji_title' => $this->romaji_title ?? null,
            'type' => $this->type,
            'media_type' => $this->media_type,
            'country' => $this->country,
            'startdate' => $this->startdate,
            'enddate' => $this->enddate,
            'episodes' => $this->episodes,
            'duration' => $this->duration,
            'status' => $this->status,
            'source' => $this->source,
            'rating' => $this->rating,
            'description' => $this->description,
            'picture' => $this->picture,
            'categories' => $this->categories,
            'characters' => $this->characters,
            'creators' => $this->creators,
            'related' => $this->related,
            'similar' => $this->similar,
            'hashtag' => $this->hashtag,
            'updated' => $this->updated,
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
