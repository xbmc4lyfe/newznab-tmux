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

    /**
     * Wave 47.3 batch metadata normalization and eligibility scoring. Defaults to Rust: the
     * paired benchmark cleared 1.5x on common and uncommon mean and p99;
     * useW47ReleaseContextRustFfi(false) selects PHP.
     */
    public const bool W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT = true;

    public const string W47_FFI_CDEF = <<<'C'
        ssize_t nntmux_w47_tv_release_context_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, char *out, size_t out_cap);
        C;

    /** Values written per context, followed by the eligible count and the score sum. */
    public const int W47_FIELDS_PER_CONTEXT = 4;

    /** Scores at or above this count as eligible for a provider lookup. */
    public const int W47_ELIGIBLE_SCORE = 50;

    private static ?bool $w47RustFfiOverride = null;

    private static FFI|false|null $w47RustFfiHandle = null;

    /** Null restores W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT. */
    public static function useW47ReleaseContextRustFfi(?bool $enable = true): void
    {
        self::$w47RustFfiOverride = $enable;
        if ($enable === null) {
            self::$w47RustFfiHandle = null;
        }
    }

    public static function usesW47ReleaseContextRustFfi(): bool
    {
        return self::$w47RustFfiOverride ?? self::W47_RELEASE_CONTEXT_RUST_FFI_DEFAULT;
    }

    /**
     * Four values per context (normalized searchname length, its FNV-1a 32 digest, flag bits and
     * the 0-100 eligibility score), then the eligible count and the score sum. The normalized
     * name lowercases ASCII and collapses `.`, `_`, space and tab runs into single spaces.
     *
     * Flags: 1 TV category, 2 has show, 4 has episode, 8 SxxEyy marker, 16 name shorter than 5,
     * 32 non-ASCII name, 64 invalid release id, 128 invalid group id.
     *
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatch(array $contexts): array
    {
        return self::usesW47ReleaseContextRustFfi()
            ? self::w47EligibilityBatchNative($contexts)
            : self::w47EligibilityBatchPhp($contexts);
    }

    /**
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatchNative(array $contexts): array
    {
        $count = \count($contexts);
        $blob = implode("\0", array_column($contexts, 'searchName'));
        if (($count > 0 && substr_count($blob, "\0") !== $count - 1) || ($ffi = self::w47RustFfi()) === null) {
            return self::w47EligibilityBatchPhp($contexts);
        }
        try {
            $ints = pack(
                'q*',
                ...array_column($contexts, 'releaseId'),
                ...array_column($contexts, 'groupsId'),
                ...array_column($contexts, 'categoriesId'),
                ...array_column($contexts, 'videosId'),
                ...array_column($contexts, 'tvEpisodesId'),
            );
            $bytes = ($count * self::W47_FIELDS_PER_CONTEXT + 2) * 8;
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $bytes, $capacity);
            if ($buffer === null || \strlen($ints) !== $count * 40) {
                return self::w47EligibilityBatchPhp($contexts);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w47_tv_release_context_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $buffer, $capacity);
            if ($written !== $bytes) {
                return self::w47EligibilityBatchPhp($contexts);
            }
            $values = unpack('P*', FFI::string($buffer, $bytes));
            if ($values === false) {
                return self::w47EligibilityBatchPhp($contexts);
            }

            /** @var list<int> */
            return array_values($values);
        } catch (Throwable) {
            return self::w47EligibilityBatchPhp($contexts);
        }
    }

    /**
     * @param  list<self>  $contexts
     * @return list<int>
     */
    public static function w47EligibilityBatchPhp(array $contexts): array
    {
        $out = [];
        $eligible = 0;
        $scoreSum = 0;
        foreach ($contexts as $context) {
            $name = strtolower(trim((string) preg_replace('/[._ \t]+/', ' ', $context->searchName), ' '));
            $length = \strlen($name);
            $marker = preg_match('/(?:^| )s\d+e\d/', $name) === 1;
            $nonAscii = preg_match('/[\x80-\xFF]/', $name) === 1;
            $tv = $context->categoriesId >= 5000 && $context->categoriesId <= 5999;
            $hasShow = $context->videosId > 0;
            $hasEpisode = $context->tvEpisodesId > 0;
            $short = $length < 5;
            $badRelease = $context->releaseId <= 0;
            $badGroup = $context->groupsId <= 0;
            $flags = ($tv ? 1 : 0) | ($hasShow ? 2 : 0) | ($hasEpisode ? 4 : 0) | ($marker ? 8 : 0)
                | ($short ? 16 : 0) | ($nonAscii ? 32 : 0) | ($badRelease ? 64 : 0) | ($badGroup ? 128 : 0);
            $score = 0;
            if ($tv && ! $badRelease) {
                $score = 50 + ($marker ? 30 : 0) - ($short ? 40 : 0) + ($hasShow ? 0 : 10)
                    - ($hasEpisode ? 50 : 0) - ($nonAscii ? 10 : 0) - ($badGroup ? 5 : 0);
                $score = max(0, min(100, $score));
            }
            if ($score >= self::W47_ELIGIBLE_SCORE) {
                $eligible++;
            }
            $scoreSum += $score;
            array_push($out, $length, self::w47Fnv($name), $flags, $score);
        }
        $out[] = $eligible;
        $out[] = $scoreSum;

        return $out;
    }

    private static function w47Fnv(string $bytes): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('N', hash('fnv1a32', $bytes, true));

        return $digest[1];
    }

    private static function w47RustFfi(): ?FFI
    {
        if (self::$w47RustFfiHandle === null) {
            self::$w47RustFfiHandle = RustPipelineLibrary::resolveDomain('categorize_w47', self::W47_FFI_CDEF) ?? false;
        }

        return self::$w47RustFfiHandle === false ? null : self::$w47RustFfiHandle;
    }
}
