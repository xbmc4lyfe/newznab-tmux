<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\ConsoleInfo.
 *
 * @property int $id
 * @property string $title
 * @property string|null $asin
 * @property string|null $url
 * @property int|null $salesrank
 * @property string|null $platform
 * @property string|null $publisher
 * @property int|null $genres_id
 * @property string|null $esrb
 * @property string|null $releasedate
 * @property string|null $review
 * @property bool $cover
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereAsin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereEsrb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereGenresId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereReleasedate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereReview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereSalesrank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo whereUrl($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ConsoleInfo query()
 */
class ConsoleInfo extends Model
{
    /**
     * @var string
     */
    protected $table = 'consoleinfo';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_console_info_cover_paths(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, size_t *out_webp_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_console_info_resolve_cover_key(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, uint32_t has_cover, char *out_buf, size_t out_cap);
        ssize_t nntmux_console_info_normalize_field(int32_t kind, const char *input_ptr, size_t input_len, uint32_t has_input, char *out_buf, size_t out_cap);
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

    // ========================================
    // Relationships
    // ========================================

    /**
     * Get the genre for the console info.
     *
     * @return BelongsTo<Genre, $this>
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'genres_id');
    }

    /**
     * Get the releases for the console info.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'consoleinfo_id');
    }

    // ========================================
    // Query Scopes
    // ========================================

    /**
     * Scope a query to only include consoles with covers.
     */
    public function scopeWithCover(mixed $query): mixed
    {
        return $query->where('cover', 1);
    }

    /**
     * Scope a query to only include consoles for a specific platform.
     */
    public function scopeForPlatform(mixed $query, string $platform): mixed
    {
        return $query->where('platform', $platform);
    }

    // ========================================
    // Cover, ASIN & Platform Helpers
    // ========================================

    /**
     * Format relative candidate paths (`webp` and legacy `jpg`) for a console cover when `(int) $id > 0`.
     *
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePaths(mixed $id): ?array
    {
        return self::usesRustFfi()
            ? self::formatCoverCandidateRelativePathsNative($id)
            : self::formatCoverCandidateRelativePathsPhp($id);
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePathsNative(mixed $id): ?array
    {
        if (($ffi = self::ffi()) === null) {
            return self::formatCoverCandidateRelativePathsPhp($id);
        }
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
            return self::formatCoverCandidateRelativePathsPhp($id);
        }

        $idLen = \strlen($idStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(96, ($idLen * 2) + 48), $capacity);
        if ($buffer === null) {
            return self::formatCoverCandidateRelativePathsPhp($id);
        }
        try {
            $outWebpLen = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_console_info_cover_paths($idKind, $idInt, $idStr, $idLen, $outWebpLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatCoverCandidateRelativePathsPhp($id);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_console_info_cover_paths($idKind, $idInt, $idStr, $idLen, $outWebpLen, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::formatCoverCandidateRelativePathsPhp($id);
            }
            $webpLen = (int) $outWebpLen[0];
            if ($webpLen <= 0 || $webpLen >= $written) {
                return self::formatCoverCandidateRelativePathsPhp($id);
            }
            $blob = FFI::string($buffer, $written);

            return [
                'webp' => substr($blob, 0, $webpLen),
                'jpg' => substr($blob, $webpLen),
            ];
        } catch (Throwable) {
            return self::formatCoverCandidateRelativePathsPhp($id);
        }
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePathsPhp(mixed $id): ?array
    {
        if ((int) $id <= 0) {
            return null;
        }

        $idStr = (string) $id;
        $webp = 'covers/console/'.$idStr.'.webp';
        $jpg = preg_replace('/\.webp$/', '.jpg', $webp);

        return [
            'webp' => $webp,
            'jpg' => \is_string($jpg) ? $jpg : ('covers/console/'.$idStr.'.jpg'),
        ];
    }

    /**
     * Resolve the console cover asset key (`(string) $id`) when `(int) $id > 0 && $cover`.
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
            $written = $ffi->nntmux_console_info_resolve_cover_key($idKind, $idInt, $idStr, $idLen, $hasCover, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::resolveCoverAssetKeyPhp($id, $cover);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_console_info_resolve_cover_key($idKind, $idInt, $idStr, $idLen, $hasCover, $buffer, $capacity);
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
     * Normalize an ASIN string by trimming whitespace and uppercasing ASCII characters.
     */
    public static function normalizeAsin(mixed $asin): ?string
    {
        return self::usesRustFfi()
            ? self::normalizeAsinNative($asin)
            : self::normalizeAsinPhp($asin);
    }

    public static function normalizeAsinNative(mixed $asin): ?string
    {
        if (($ffi = self::ffi()) === null || \is_array($asin) || \is_object($asin) || \is_bool($asin)) {
            return self::normalizeAsinPhp($asin);
        }
        $hasInput = $asin !== null ? 1 : 0;
        $asinStr = $hasInput === 1 ? (string) $asin : '';
        $asinLen = \strlen($asinStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $asinLen + 16), $capacity);
        if ($buffer === null) {
            return self::normalizeAsinPhp($asin);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_console_info_normalize_field(0, $asinStr, $asinLen, $hasInput, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizeAsinPhp($asin);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_console_info_normalize_field(0, $asinStr, $asinLen, $hasInput, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizeAsinPhp($asin);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizeAsinPhp($asin);
        }
    }

    public static function normalizeAsinPhp(mixed $asin): ?string
    {
        if ($asin === null || \is_array($asin) || \is_object($asin) || \is_bool($asin)) {
            return null;
        }
        $trimmed = strtoupper(trim((string) $asin));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Normalize a platform string by trimming and collapsing whitespace runs.
     */
    public static function normalizePlatform(mixed $platform): string
    {
        return self::usesRustFfi()
            ? self::normalizePlatformNative($platform)
            : self::normalizePlatformPhp($platform);
    }

    public static function normalizePlatformNative(mixed $platform): string
    {
        if (($ffi = self::ffi()) === null || \is_array($platform) || \is_object($platform) || \is_bool($platform)) {
            return self::normalizePlatformPhp($platform);
        }
        $hasInput = $platform !== null ? 1 : 0;
        $platStr = $hasInput === 1 ? (string) $platform : '';
        $platLen = \strlen($platStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $platLen + 16), $capacity);
        if ($buffer === null) {
            return self::normalizePlatformPhp($platform);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_console_info_normalize_field(1, $platStr, $platLen, $hasInput, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::normalizePlatformPhp($platform);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_console_info_normalize_field(1, $platStr, $platLen, $hasInput, $buffer, $capacity);
            }
            if ($written === 0) {
                return '';
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::normalizePlatformPhp($platform);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::normalizePlatformPhp($platform);
        }
    }

    public static function normalizePlatformPhp(mixed $platform): string
    {
        if ($platform === null || \is_array($platform) || \is_object($platform) || \is_bool($platform)) {
            return '';
        }
        $collapsed = preg_replace('/\s+/', ' ', (string) $platform);

        return trim(\is_string($collapsed) ? $collapsed : (string) $platform);
    }

    /**
     * Get the cover image path.
     */
    public function getCoverPath(): string
    {
        return self::usesRustFfi()
            ? $this->getCoverPathNative()
            : $this->getCoverPathPhp();
    }

    public function getCoverPathNative(): string
    {
        $paths = self::formatCoverCandidateRelativePathsNative($this->id);
        if ($paths === null) {
            return '';
        }

        return storage_path($paths['webp']);
    }

    public function getCoverPathPhp(): string
    {
        if ((int) $this->id <= 0) {
            return '';
        }

        return storage_path('covers/console/'.$this->id.'.webp');
    }

    /**
     * Check if cover image exists locally.
     */
    public function hasCoverImage(): bool
    {
        return self::usesRustFfi()
            ? $this->hasCoverImageNative()
            : $this->hasCoverImagePhp();
    }

    public function hasCoverImageNative(): bool
    {
        $paths = self::formatCoverCandidateRelativePathsNative($this->id);
        if ($paths === null) {
            return false;
        }

        return file_exists(storage_path($paths['webp']))
            || file_exists(storage_path($paths['jpg']));
    }

    public function hasCoverImagePhp(): bool
    {
        $coverPath = $this->getCoverPathPhp();
        if ($coverPath === '') {
            return false;
        }

        $legacyPath = preg_replace('/\.webp$/', '.jpg', $coverPath);

        return file_exists($coverPath) || (\is_string($legacyPath) && file_exists($legacyPath));
    }

    /**
     * Get the cover URL.
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
        if ($assetKey === null || ! $this->hasCoverImageNative()) {
            return null;
        }

        return getImageAssetUrl('console', $assetKey);
    }

    public function getCoverUrlPhp(): ?string
    {
        if ((int) $this->id <= 0 || ! $this->cover || ! $this->hasCoverImagePhp()) {
            return null;
        }

        return getImageAssetUrl('console', (string) $this->id);
    }

    // ========================================
    // Static Helper Methods
    // ========================================

    /**
     * Get console info by ID with genre.
     */
    public static function getWithGenre(int $id): ?self
    {
        return static::query()
            ->where('consoleinfo.id', $id)
            ->select('consoleinfo.*', 'genres.title as genres')
            ->leftJoin('genres', 'genres.id', '=', 'consoleinfo.genres_id')
            ->first();
    }

    /**
     * Find by ASIN.
     */
    public static function findByAsin(string $asin): ?self
    {
        return static::where('asin', $asin)->first();
    }
}
