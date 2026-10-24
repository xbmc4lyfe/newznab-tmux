<?php

declare(strict_types=1);

namespace App\Services\TvProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Value object containing release information for TV processing.
 */
class TvReleaseContext
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_release_context_from_serialized(const char *ser, size_t ser_len, int64_t *out_release_id, int64_t *out_groups_id, int64_t *out_categories_id, int64_t *out_videos_id, int64_t *out_tv_episodes_id, int32_t *out_flags, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustTvBookSeriesLookupParityTest). */
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

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    public function __construct(
        public readonly int $releaseId,
        public readonly string $searchName,
        public readonly int $groupsId,
        public readonly int $categoriesId,
        public readonly int $videosId = 0,
        public readonly int $tvEpisodesId = 0,
        public readonly ?string $guid = null,
        public readonly ?string $leftGuid = null,
    ) {}

    /**
     * Create from a Release model array/object.
     *
     * @param  array<string, mixed>|object  $release
     */
    public static function fromRelease(array|object $release): self
    {
        return self::usesRustFfi()
            ? self::fromReleaseNative($release)
            : self::fromReleasePhp($release);
    }

    /**
     * @param  array<string, mixed>|object  $release
     */
    public static function fromReleaseNative(array|object $release): self
    {
        return self::fromDataNative(self::extractDataArray($release));
    }

    /**
     * @param  array<string, mixed>|object  $release
     */
    public static function fromReleasePhp(array|object $release): self
    {
        return self::fromDataPhp(self::extractDataArray($release));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromData(array $data): self
    {
        return self::usesRustFfi()
            ? self::fromDataNative($data)
            : self::fromDataPhp($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromDataNative(array $data): self
    {
        if (($ffi = self::ffi()) === null) {
            return self::fromDataPhp($data);
        }
        try {
            $ser = serialize($data);
            $len = \strlen($ser);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $len + 32), $capacity);
            if ($buffer === null) {
                return self::fromDataPhp($data);
            }
            $outReleaseId = $ffi->new('int64_t[1]');
            $outGroupsId = $ffi->new('int64_t[1]');
            $outCategoriesId = $ffi->new('int64_t[1]');
            $outVideosId = $ffi->new('int64_t[1]');
            $outTvEpisodesId = $ffi->new('int64_t[1]');
            $outFlags = $ffi->new('int32_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_tv_release_context_from_serialized(
                $ser,
                $len,
                $outReleaseId,
                $outGroupsId,
                $outCategoriesId,
                $outVideosId,
                $outTvEpisodesId,
                $outFlags,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::fromDataPhp($data);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_tv_release_context_from_serialized(
                    $ser,
                    $len,
                    $outReleaseId,
                    $outGroupsId,
                    $outCategoriesId,
                    $outVideosId,
                    $outTvEpisodesId,
                    $outFlags,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written < 2 || $written > $capacity) {
                return self::fromDataPhp($data);
            }
            $parts = explode("\0", FFI::string($buffer, $written), 3);
            if (\count($parts) !== 3) {
                return self::fromDataPhp($data);
            }
            $flags = (int) $outFlags[0];

            return new self(
                releaseId: (int) $outReleaseId[0],
                searchName: $parts[0],
                groupsId: (int) $outGroupsId[0],
                categoriesId: (int) $outCategoriesId[0],
                videosId: (int) $outVideosId[0],
                tvEpisodesId: (int) $outTvEpisodesId[0],
                guid: ($flags & 1) !== 0 ? $parts[1] : null,
                leftGuid: ($flags & 2) !== 0 ? $parts[2] : null,
            );
        } catch (Throwable) {
            return self::fromDataPhp($data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromDataPhp(array $data): self
    {
        return new self(
            releaseId: (int) ($data['id'] ?? 0),
            searchName: (string) ($data['searchname'] ?? ''),
            groupsId: (int) ($data['groups_id'] ?? 0),
            categoriesId: (int) ($data['categories_id'] ?? 0),
            videosId: (int) ($data['videos_id'] ?? 0),
            tvEpisodesId: (int) ($data['tv_episodes_id'] ?? 0),
            guid: $data['guid'] ?? null,
            leftGuid: $data['leftguid'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>|object  $release
     * @return array<string, mixed>
     */
    private static function extractDataArray(array|object $release): array
    {
        // Handle Eloquent models properly - use toArray() instead of casting
        if ($release instanceof Model) {
            return $release->toArray();
        }

        return \is_array($release) ? $release : (array) $release;
    }
}
