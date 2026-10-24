<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\BookInfo.
 *
 * @property int $id
 * @property string $title
 * @property string $author
 * @property string|null $asin
 * @property string|null $isbn
 * @property string|null $ean
 * @property string|null $url
 * @property int|null $salesrank
 * @property string|null $publisher
 * @property string|null $publishdate
 * @property string|null $pages
 * @property string|null $overview
 * @property string $genre
 * @property bool $cover
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereAsin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereAuthor($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereEan($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereGenre($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereIsbn($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereOverview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo wherePages($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo wherePublishdate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereSalesrank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo whereUrl($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BookInfo query()
 */
class BookInfo extends Model
{
    /**
     * @var string
     */
    protected $table = 'bookinfo';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_book_info_cover_paths(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, size_t *out_webp_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_book_resource_resolve_cover_key(int32_t id_kind, int64_t id_int, const char *id_ptr, size_t id_len, uint32_t has_cover, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustMetadataAnidbBookGamesIgdbMovieParityTest). */
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
     * Get the releases associated with this book.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'bookinfo_id');
    }

    /**
     * Format relative candidate paths (`webp` and legacy `jpg`) for a book cover when `(int) $id > 0`.
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
            $written = $ffi->nntmux_book_info_cover_paths($idKind, $idInt, $idStr, $idLen, $outWebpLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatCoverCandidateRelativePathsPhp($id);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_book_info_cover_paths($idKind, $idInt, $idStr, $idLen, $outWebpLen, $buffer, $capacity);
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
        $webp = 'covers/book/'.$idStr.'.webp';
        $jpg = preg_replace('/\.webp$/', '.jpg', $webp);

        return [
            'webp' => $webp,
            'jpg' => \is_string($jpg) ? $jpg : ('covers/book/'.$idStr.'.jpg'),
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

        return storage_path('covers/book/'.$this->id.'.webp');
    }

    /**
     * Check if cover image exists.
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

        return file_exists($coverPath) || (is_string($legacyPath) && file_exists($legacyPath));
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

        return getImageAssetUrl('book', $assetKey);
    }

    public function getCoverUrlPhp(): ?string
    {
        if ((int) $this->id <= 0 || ! $this->cover || ! $this->hasCoverImagePhp()) {
            return null;
        }

        return getImageAssetUrl('book', (string) $this->id);
    }
}
