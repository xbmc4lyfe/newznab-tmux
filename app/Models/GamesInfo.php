<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\GamesInfo.
 *
 * @property int $id
 * @property string $title
 * @property string|null $asin
 * @property string|null $url
 * @property string|null $publisher
 * @property int|null $genres_id
 * @property string|null $esrb
 * @property string|null $releasedate
 * @property string|null $review
 * @property bool $cover
 * @property bool $backdrop
 * @property string $trailer
 * @property string $classused
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Genre|null $genre
 * @property-read Collection|Release[] $releases
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereAsin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereBackdrop($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereClassused($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereEsrb($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereGenresId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereReleasedate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereReview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereTrailer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo whereUrl($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\GamesInfo query()
 */
class GamesInfo extends Model
{
    /**
     * @var string
     */
    protected $table = 'gamesinfo';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'cover' => 'boolean',
        'backdrop' => 'boolean',
        'genres_id' => 'integer',
        'releasedate' => 'date',
    ];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_games_info_image_paths(const char *covers_path_ptr, size_t covers_path_len, const char *id_ptr, size_t id_len, uint32_t enabled, uint32_t is_backdrop, size_t *out_webp_len, char *out_buf, size_t out_cap);
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
     * Get the genre for this game.
     *
     * @return BelongsTo<Genre, $this>
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'genres_id');
    }

    /**
     * Get the releases for this game.
     *
     * @return HasMany<Release, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'gamesinfo_id');
    }

    /**
     * Resolve the `.webp` and legacy `.jpg` candidate paths for a game cover or backdrop.
     *
     * @return array{webp: string, jpg: string}|null
     */
    public static function resolveImageCandidatePaths(string $coversPath, mixed $id, mixed $enabled, bool $isBackdrop = false): ?array
    {
        return self::usesRustFfi()
            ? self::resolveImageCandidatePathsNative($coversPath, $id, $enabled, $isBackdrop)
            : self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function resolveImageCandidatePathsNative(string $coversPath, mixed $id, mixed $enabled, bool $isBackdrop = false): ?array
    {
        if (($ffi = self::ffi()) === null || \is_array($id) || \is_object($id)) {
            return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
        }
        $enabledInt = ! empty($enabled) ? 1 : 0;
        $backdropInt = $isBackdrop ? 1 : 0;
        $idStr = (string) ($id ?? '');
        $coversLen = \strlen($coversPath);
        $idLen = \strlen($idStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, ($coversLen + $idLen) * 2 + 64), $capacity);
        if ($buffer === null) {
            return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
        }
        try {
            $outWebpLen = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_games_info_image_paths($coversPath, $coversLen, $idStr, $idLen, $enabledInt, $backdropInt, $outWebpLen, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_games_info_image_paths($coversPath, $coversLen, $idStr, $idLen, $enabledInt, $backdropInt, $outWebpLen, $buffer, $capacity);
            }
            if ($written === 0) {
                return null;
            }
            if (! \is_int($written) || $written < 0 || $written > $capacity) {
                return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
            }
            $webpLen = (int) $outWebpLen[0];
            if ($webpLen <= 0 || $webpLen >= $written) {
                return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
            }
            $blob = FFI::string($buffer, $written);

            return [
                'webp' => substr($blob, 0, $webpLen),
                'jpg' => substr($blob, $webpLen),
            ];
        } catch (Throwable) {
            return self::resolveImageCandidatePathsPhp($coversPath, $id, $enabled, $isBackdrop);
        }
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function resolveImageCandidatePathsPhp(string $coversPath, mixed $id, mixed $enabled, bool $isBackdrop = false): ?array
    {
        if (! $enabled) {
            return null;
        }

        $suffix = $isBackdrop ? '-backdrop.webp' : '.webp';
        $path = $coversPath.'/games/'.$id.$suffix;
        $legacyPath = preg_replace('/\.webp$/', '.jpg', $path);

        return [
            'webp' => $path,
            'jpg' => \is_string($legacyPath) ? $legacyPath : ($coversPath.'/games/'.$id.($isBackdrop ? '-backdrop.jpg' : '.jpg')),
        ];
    }

    /**
     * Get the cover image path.
     */
    public function getCoverPath(): ?string
    {
        return self::usesRustFfi()
            ? $this->getCoverPathNative()
            : $this->getCoverPathPhp();
    }

    public function getCoverPathNative(): ?string
    {
        $paths = self::resolveImageCandidatePathsNative(
            (string) config('nntmux_settings.covers_path'),
            $this->id,
            $this->cover,
            false,
        );
        if ($paths === null) {
            return null;
        }
        if (file_exists($paths['webp'])) {
            return $paths['webp'];
        }

        return file_exists($paths['jpg']) ? $paths['jpg'] : null;
    }

    public function getCoverPathPhp(): ?string
    {
        if (! $this->cover) {
            return null;
        }

        $path = config('nntmux_settings.covers_path').'/games/'.$this->id.'.webp';

        if (file_exists($path)) {
            return $path;
        }

        $legacyPath = preg_replace('/\.webp$/', '.jpg', $path);

        return is_string($legacyPath) && file_exists($legacyPath) ? $legacyPath : null;
    }

    /**
     * Get the backdrop image path.
     */
    public function getBackdropPath(): ?string
    {
        return self::usesRustFfi()
            ? $this->getBackdropPathNative()
            : $this->getBackdropPathPhp();
    }

    public function getBackdropPathNative(): ?string
    {
        $paths = self::resolveImageCandidatePathsNative(
            (string) config('nntmux_settings.covers_path'),
            $this->id,
            $this->backdrop,
            true,
        );
        if ($paths === null) {
            return null;
        }
        if (file_exists($paths['webp'])) {
            return $paths['webp'];
        }

        return file_exists($paths['jpg']) ? $paths['jpg'] : null;
    }

    public function getBackdropPathPhp(): ?string
    {
        if (! $this->backdrop) {
            return null;
        }

        $path = config('nntmux_settings.covers_path').'/games/'.$this->id.'-backdrop.webp';

        if (file_exists($path)) {
            return $path;
        }

        $legacyPath = preg_replace('/\.webp$/', '.jpg', $path);

        return is_string($legacyPath) && file_exists($legacyPath) ? $legacyPath : null;
    }
}
