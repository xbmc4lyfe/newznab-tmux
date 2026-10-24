<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\AnidbInfo.
 *
 * @property-read AnidbTitle $title
 *
 * @mixin \Eloquent
 *
 * @property int $anidbid ID of title from AniDB
 * @property string|null $type
 * @property string|null $startdate
 * @property string|null $enddate
 * @property string $updated
 * @property string|null $related
 * @property string|null $similar
 * @property string|null $creators
 * @property string|null $description
 * @property string|null $rating
 * @property string|null $picture
 * @property string|null $categories
 * @property string|null $characters
 * @property int|null $anilist_id ID from AniList
 * @property int|null $mal_id ID from MyAnimeList
 * @property string|null $country ISO 3166-1 alpha-2 country code
 * @property string|null $media_type ANIME or MANGA
 * @property int|null $episodes Number of episodes
 * @property int|null $duration Duration in minutes
 * @property string|null $status Media status (FINISHED, RELEASING, etc.)
 * @property string|null $source Original source (MANGA, ORIGINAL, etc.)
 * @property string|null $hashtag AniList hashtag
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereAnidbid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereCategories($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereCharacters($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereCreators($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereEnddate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo wherePicture($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereRelated($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereSimilar($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereStartdate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbInfo whereUpdated($value)
 */
class AnidbInfo extends Model
{
    /**
     * @var string
     */
    protected $primaryKey = 'anidbid';

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var string
     */
    protected $table = 'anidb_info';

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_anidb_info_picture_paths(const char *anidbid_ptr, size_t anidbid_len, size_t *out_webp_len, size_t *out_legacy_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_anidb_info_provider_url(int32_t provider, const char *id_ptr, size_t id_len, uint32_t has_id, char *out_buf, size_t out_cap);
        ssize_t nntmux_anidb_info_external_links(const char *anidb_ptr, size_t anidb_len, uint32_t has_anidb, const char *anilist_ptr, size_t anilist_len, uint32_t has_anilist, const char *mal_ptr, size_t mal_len, uint32_t has_mal, size_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_anidb_resource_resolve_picture(const char *picture_ptr, size_t picture_len, uint32_t has_picture, const char *anidbid_ptr, size_t anidbid_len, int32_t *out_mode, char *out_buf, size_t out_cap);
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
     * Get the title associated with this info.
     *
     * @return BelongsTo<AnidbTitle, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(AnidbTitle::class, 'anidbid');
    }

    /**
     * Get the releases associated with this anime.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'anidbid', 'anidbid');
    }

    /**
     * Format relative candidate paths for anime cover images.
     *
     * @return array{webp: string, legacy_jpg: string, old_legacy_jpg: string}
     */
    public static function formatPictureCandidateRelativePaths(mixed $anidbid): array
    {
        return self::usesRustFfi()
            ? self::formatPictureCandidateRelativePathsNative($anidbid)
            : self::formatPictureCandidateRelativePathsPhp($anidbid);
    }

    /**
     * @return array{webp: string, legacy_jpg: string, old_legacy_jpg: string}
     */
    public static function formatPictureCandidateRelativePathsNative(mixed $anidbid): array
    {
        if (($ffi = self::ffi()) === null || \is_array($anidbid) || \is_object($anidbid)) {
            return self::formatPictureCandidateRelativePathsPhp($anidbid);
        }
        $idStr = (string) ($anidbid ?? '');
        $idLen = \strlen($idStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, ($idLen * 3) + 96), $capacity);
        if ($buffer === null) {
            return self::formatPictureCandidateRelativePathsPhp($anidbid);
        }
        try {
            $outWebpLen = $ffi->new('size_t[1]');
            $outLegacyLen = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_info_picture_paths($idStr, $idLen, $outWebpLen, $outLegacyLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatPictureCandidateRelativePathsPhp($anidbid);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_info_picture_paths($idStr, $idLen, $outWebpLen, $outLegacyLen, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::formatPictureCandidateRelativePathsPhp($anidbid);
            }
            $webpLen = (int) $outWebpLen[0];
            $legacyLen = (int) $outLegacyLen[0];
            if ($webpLen <= 0 || $legacyLen <= 0 || ($webpLen + $legacyLen) >= $written) {
                return self::formatPictureCandidateRelativePathsPhp($anidbid);
            }
            $blob = FFI::string($buffer, $written);

            return [
                'webp' => substr($blob, 0, $webpLen),
                'legacy_jpg' => substr($blob, $webpLen, $legacyLen),
                'old_legacy_jpg' => substr($blob, $webpLen + $legacyLen),
            ];
        } catch (Throwable) {
            return self::formatPictureCandidateRelativePathsPhp($anidbid);
        }
    }

    /**
     * @return array{webp: string, legacy_jpg: string, old_legacy_jpg: string}
     */
    public static function formatPictureCandidateRelativePathsPhp(mixed $anidbid): array
    {
        $idStr = (string) ($anidbid ?? '');
        $webp = 'covers/anime/'.$idStr.'-cover.webp';
        $legacy = preg_replace('/\.webp$/', '.jpg', $webp);

        return [
            'webp' => $webp,
            'legacy_jpg' => \is_string($legacy) ? $legacy : ('covers/anime/'.$idStr.'-cover.jpg'),
            'old_legacy_jpg' => 'covers/anime/'.$idStr.'.jpg',
        ];
    }

    /**
     * Format a provider anime URL (`0` = AniDB, `1` = AniList, `2` = MyAnimeList).
     */
    public static function formatProviderUrl(int $provider, mixed $id): ?string
    {
        return self::usesRustFfi()
            ? self::formatProviderUrlNative($provider, $id)
            : self::formatProviderUrlPhp($provider, $id);
    }

    public static function formatProviderUrlNative(int $provider, mixed $id): ?string
    {
        if (($ffi = self::ffi()) === null || \is_array($id) || \is_object($id)) {
            return self::formatProviderUrlPhp($provider, $id);
        }
        $hasId = ! empty($id) ? 1 : 0;
        $idStr = $hasId === 1 ? (string) $id : '';
        $idLen = \strlen($idStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $idLen + 48), $capacity);
        if ($buffer === null) {
            return self::formatProviderUrlPhp($provider, $id);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_info_provider_url($provider, $idStr, $idLen, $hasId, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::formatProviderUrlPhp($provider, $id);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_info_provider_url($provider, $idStr, $idLen, $hasId, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::formatProviderUrlPhp($provider, $id);
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::formatProviderUrlPhp($provider, $id);
        }
    }

    public static function formatProviderUrlPhp(int $provider, mixed $id): ?string
    {
        if (empty($id)) {
            return null;
        }

        return match ($provider) {
            0 => 'https://anidb.net/anime/'.$id,
            1 => 'https://anilist.co/anime/'.$id,
            2 => 'https://myanimelist.net/anime/'.$id,
            default => null,
        };
    }

    /**
     * Build available external anime links keyed by provider.
     *
     * @return array<string, string>
     */
    public static function buildExternalLinks(mixed $anidbid, mixed $anilistId, mixed $malId): array
    {
        return self::usesRustFfi()
            ? self::buildExternalLinksNative($anidbid, $anilistId, $malId)
            : self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
    }

    /**
     * @return array<string, string>
     */
    public static function buildExternalLinksNative(mixed $anidbid, mixed $anilistId, mixed $malId): array
    {
        if (
            ($ffi = self::ffi()) === null
            || \is_array($anidbid)
            || \is_object($anidbid)
            || \is_array($anilistId)
            || \is_object($anilistId)
            || \is_array($malId)
            || \is_object($malId)
        ) {
            return self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
        }
        $hasAnidb = ! empty($anidbid) ? 1 : 0;
        $anidbStr = $hasAnidb === 1 ? (string) $anidbid : '';
        $anidbLen = \strlen($anidbStr);

        $hasAnilist = ! empty($anilistId) ? 1 : 0;
        $anilistStr = $hasAnilist === 1 ? (string) $anilistId : '';
        $anilistLen = \strlen($anilistStr);

        $hasMal = ! empty($malId) ? 1 : 0;
        $malStr = $hasMal === 1 ? (string) $malId : '';
        $malLen = \strlen($malStr);

        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(256, $anidbLen + $anilistLen + $malLen + 192), $capacity);
        if ($buffer === null) {
            return self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
        }
        try {
            $outCount = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_anidb_info_external_links(
                $anidbStr,
                $anidbLen,
                $hasAnidb,
                $anilistStr,
                $anilistLen,
                $hasAnilist,
                $malStr,
                $malLen,
                $hasMal,
                $outCount,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_anidb_info_external_links(
                    $anidbStr,
                    $anidbLen,
                    $hasAnidb,
                    $anilistStr,
                    $anilistLen,
                    $hasAnilist,
                    $malStr,
                    $malLen,
                    $hasMal,
                    $outCount,
                    $buffer,
                    $capacity,
                );
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
            }
            if ((int) $outCount[0] === 0) {
                return [];
            }
            $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

            /** @var array<string, string> $result */
            $result = \is_array($decoded) ? $decoded : self::buildExternalLinksPhp($anidbid, $anilistId, $malId);

            return $result;
        } catch (Throwable) {
            return self::buildExternalLinksPhp($anidbid, $anilistId, $malId);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function buildExternalLinksPhp(mixed $anidbid, mixed $anilistId, mixed $malId): array
    {
        return array_filter([
            'anidb' => self::formatProviderUrlPhp(0, $anidbid),
            'anilist' => self::formatProviderUrlPhp(1, $anilistId),
            'myanimelist' => self::formatProviderUrlPhp(2, $malId),
        ]);
    }

    /**
     * Get the picture path.
     */
    public function getPicturePath(): string
    {
        return self::usesRustFfi()
            ? $this->getPicturePathNative()
            : $this->getPicturePathPhp();
    }

    public function getPicturePathNative(): string
    {
        $paths = self::formatPictureCandidateRelativePathsNative($this->anidbid);

        return storage_path($paths['webp']);
    }

    public function getPicturePathPhp(): string
    {
        return storage_path('covers/anime/'.$this->anidbid.'-cover.webp');
    }

    /**
     * Check if picture image exists locally.
     */
    public function hasPictureImage(): bool
    {
        return self::usesRustFfi()
            ? $this->hasPictureImageNative()
            : $this->hasPictureImagePhp();
    }

    public function hasPictureImageNative(): bool
    {
        $paths = self::formatPictureCandidateRelativePathsNative($this->anidbid);

        return file_exists(storage_path($paths['webp']))
            || file_exists(storage_path($paths['legacy_jpg']))
            || file_exists(storage_path($paths['old_legacy_jpg']));
    }

    public function hasPictureImagePhp(): bool
    {
        $path = $this->getPicturePathPhp();

        $legacyPath = preg_replace('/\.webp$/', '.jpg', $path);
        $oldLegacyPath = storage_path('covers/anime/'.$this->anidbid.'.jpg');

        return file_exists($path)
            || (is_string($legacyPath) && file_exists($legacyPath))
            || file_exists($oldLegacyPath);
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
        if (($ffi = self::ffi()) !== null && ! \is_array($this->picture) && ! \is_object($this->picture) && ! \is_array($this->anidbid) && ! \is_object($this->anidbid)) {
            $hasPicture = ! empty($this->picture) ? 1 : 0;
            $picStr = $hasPicture === 1 ? (string) $this->picture : '';
            $anidbStr = (string) ($this->anidbid ?? '');
            $picLen = \strlen($picStr);
            $anidbLen = \strlen($anidbStr);
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, $picLen + ($anidbLen * 2) + 16), $capacity);
            if ($buffer !== null) {
                try {
                    $outMode = $ffi->new('int32_t[1]');
                    // @phpstan-ignore method.notFound
                    $written = $ffi->nntmux_anidb_resource_resolve_picture($picStr, $picLen, $hasPicture, $anidbStr, $anidbLen, $outMode, $buffer, $capacity);
                    if (\is_int($written) && $written < -1) {
                        $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                        if ($buffer !== null) {
                            // @phpstan-ignore method.notFound
                            $written = $ffi->nntmux_anidb_resource_resolve_picture($picStr, $picLen, $hasPicture, $anidbStr, $anidbLen, $outMode, $buffer, $capacity);
                        }
                    }
                    if ($buffer !== null && \is_int($written) && $written >= 0 && $written <= $capacity) {
                        $mode = (int) $outMode[0];
                        if ($mode === 0) {
                            return null;
                        }
                        $payload = $written === 0 ? '' : FFI::string($buffer, $written);
                        if ($mode === 1) {
                            return $payload;
                        }
                        if ($mode === 2) {
                            $parts = explode("\0", $payload, 2);
                            if (\count($parts) === 2) {
                                return $this->hasPictureImageNative()
                                    ? getImageAssetUrl('anime', $parts[0], null, [$parts[1]])
                                    : null;
                            }
                        }
                    }
                } catch (Throwable) {
                    // Fall through to PHP fallback.
                }
            }
        }

        return $this->getPictureUrlPhp();
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
        if ($this->hasPictureImagePhp()) {
            return getImageAssetUrl(
                'anime',
                $this->anidbid.'-cover',
                null,
                [(string) $this->anidbid]
            );
        }

        return null;
    }

    /**
     * Get AniDB URL if anidbid exists.
     */
    public function getAnidbUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getAnidbUrlNative()
            : $this->getAnidbUrlPhp();
    }

    public function getAnidbUrlNative(): ?string
    {
        return self::formatProviderUrlNative(0, $this->anidbid);
    }

    public function getAnidbUrlPhp(): ?string
    {
        if (empty($this->anidbid)) {
            return null;
        }

        return 'https://anidb.net/anime/'.$this->anidbid;
    }

    /**
     * Get AniList URL if anilist_id exists.
     */
    public function getAnilistUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getAnilistUrlNative()
            : $this->getAnilistUrlPhp();
    }

    public function getAnilistUrlNative(): ?string
    {
        return self::formatProviderUrlNative(1, $this->anilist_id);
    }

    public function getAnilistUrlPhp(): ?string
    {
        if (empty($this->anilist_id)) {
            return null;
        }

        return 'https://anilist.co/anime/'.$this->anilist_id;
    }

    /**
     * Get MyAnimeList URL if mal_id exists.
     */
    public function getMalUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getMalUrlNative()
            : $this->getMalUrlPhp();
    }

    public function getMalUrlNative(): ?string
    {
        return self::formatProviderUrlNative(2, $this->mal_id);
    }

    public function getMalUrlPhp(): ?string
    {
        if (empty($this->mal_id)) {
            return null;
        }

        return 'https://myanimelist.net/anime/'.$this->mal_id;
    }

    /**
     * Get MyAnimeList URL if mal_id exists.
     */
    public function getMyAnimeListUrl(): ?string
    {
        return self::usesRustFfi()
            ? $this->getMyAnimeListUrlNative()
            : $this->getMyAnimeListUrlPhp();
    }

    public function getMyAnimeListUrlNative(): ?string
    {
        return $this->getMalUrlNative();
    }

    public function getMyAnimeListUrlPhp(): ?string
    {
        return $this->getMalUrlPhp();
    }

    /**
     * Get available external links keyed by provider.
     *
     * @return array<string, string>
     */
    public function getExternalLinks(): array
    {
        return self::usesRustFfi()
            ? $this->getExternalLinksNative()
            : $this->getExternalLinksPhp();
    }

    /**
     * @return array<string, string>
     */
    public function getExternalLinksNative(): array
    {
        return self::buildExternalLinksNative($this->anidbid, $this->anilist_id, $this->mal_id);
    }

    /**
     * @return array<string, string>
     */
    public function getExternalLinksPhp(): array
    {
        return array_filter([
            'anidb' => $this->getAnidbUrlPhp(),
            'anilist' => $this->getAnilistUrlPhp(),
            'myanimelist' => $this->getMyAnimeListUrlPhp(),
        ]);
    }

    /**
     * Check whether at least one external link exists.
     */
    public function hasExternalLinks(): bool
    {
        return self::usesRustFfi()
            ? $this->hasExternalLinksNative()
            : $this->hasExternalLinksPhp();
    }

    public function hasExternalLinksNative(): bool
    {
        return $this->getExternalLinksNative() !== [];
    }

    public function hasExternalLinksPhp(): bool
    {
        return $this->getExternalLinksPhp() !== [];
    }
}
