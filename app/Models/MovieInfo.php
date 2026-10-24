<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * App\Models\MovieInfo.
 *
 * @property int $id
 * @property string $imdbid
 * @property int $tmdbid
 * @property string $title
 * @property string $tagline
 * @property string $rating
 * @property string $rtrating RottenTomatoes rating score
 * @property string $plot
 * @property string $year
 * @property string $genre
 * @property string $type
 * @property string $director
 * @property string $actors
 * @property string $language
 * @property bool $cover
 * @property bool $backdrop
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $trailer
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereActors($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereBackdrop($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereDirector($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereGenre($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereImdbid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo wherePlot($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereRtrating($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereTagline($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereTmdbid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereTrailer($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereYear($value)
 *
 * @mixin \Eloquent
 *
 * @property int $traktid
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MovieInfo whereTraktid($value)
 */
class MovieInfo extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_movie_info_all_cache_key(const char *search_ptr, size_t search_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_normalize_imdb_id(const char *raw_ptr, size_t raw_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_image_paths(const char *imdb_id_ptr, size_t imdb_id_len, uint32_t kind, size_t *out_webp_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_split_csv(const char *raw_ptr, size_t raw_len, size_t *out_count, char *out_buf, size_t out_cap);
        ssize_t nntmux_movie_info_external_links(const char *imdb_ptr, size_t imdb_len, int64_t tmdb_id, int64_t trakt_id, char *out_buf, size_t out_cap);
        C;

    /** Null selects the benchmark-qualified production default (PHP; see RustConsoleMovieTvStatusCollectionsParityTest). */
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'movieinfo';

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = ['id'];

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
     * Get the releases associated with this movie.
     */
    public function releases(): HasMany // @phpstan-ignore missingType.generics
    {
        return $this->hasMany(Release::class, 'imdbid', 'imdbid');
    }

    public static function getAll(string $search = ''): mixed
    {
        $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_medium'));
        $cacheKey = self::buildAllCacheKey($search);
        $movie = Cache::get($cacheKey);
        if ($movie !== null) {
            return $movie;
        }
        $sql = self::query()->select('*');
        if (! empty($search)) {
            // Search by both title and IMDB ID
            $sql->where(function ($query) use ($search) {
                $query->whereLike('title', '%'.$search.'%')
                    ->orWhere('imdbid', $search);
            });
        }

        $movie = $sql->paginate(config('nntmux.items_per_page'));
        Cache::put($cacheKey, $movie, $expiresAt);

        return $movie;
    }

    /**
     * Build the MD5 cache key used by `MovieInfo::getAll()`.
     */
    public static function buildAllCacheKey(string $search = ''): string
    {
        return self::usesRustFfi()
            ? self::buildAllCacheKeyNative($search)
            : self::buildAllCacheKeyPhp($search);
    }

    public static function buildAllCacheKeyNative(string $search = ''): string
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildAllCacheKeyPhp($search);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return self::buildAllCacheKeyPhp($search);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_all_cache_key($search, \strlen($search), $buffer, $capacity);
            if (\is_int($written) && $written === 32) {
                return FFI::string($buffer, 32);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::buildAllCacheKeyPhp($search);
    }

    public static function buildAllCacheKeyPhp(string $search = ''): string
    {
        return md5($search);
    }

    /**
     * Normalize an IMDb identifier (strips optional `tt` prefix, rejects zero/non-numeric, left-pads to 7 digits).
     */
    public static function normalizeImdbId(string|int|null $imdbId): ?string
    {
        return self::usesRustFfi()
            ? self::normalizeImdbIdNative($imdbId)
            : self::normalizeImdbIdPhp($imdbId);
    }

    public static function normalizeImdbIdNative(string|int|null $imdbId): ?string
    {
        if ($imdbId === null) {
            return null;
        }
        $raw = (string) $imdbId;
        if (($ffi = self::ffi()) === null) {
            return self::normalizeImdbIdPhp($raw);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 32, $capacity);
        if ($buffer === null) {
            return self::normalizeImdbIdPhp($raw);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_normalize_imdb_id($raw, \strlen($raw), $buffer, $capacity);
            if (\is_int($written) && $written >= 0 && $written <= $capacity) {
                return $written === 0 ? null : FFI::string($buffer, $written);
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::normalizeImdbIdPhp($raw);
    }

    public static function normalizeImdbIdPhp(string|int|null $imdbId): ?string
    {
        if ($imdbId === null) {
            return null;
        }
        $trimmed = trim((string) $imdbId, " \t\n\r\0\x0B");
        if (strncasecmp($trimmed, 'tt', 2) === 0) {
            $trimmed = trim(substr($trimmed, 2), " \t\n\r\0\x0B");
        }
        $len = \strlen($trimmed);
        if ($len === 0 || $len > 12 || ! ctype_digit($trimmed)) {
            return null;
        }
        if (ltrim($trimmed, '0') === '') {
            return null;
        }

        return $len < 7 ? str_pad($trimmed, 7, '0', STR_PAD_LEFT) : $trimmed;
    }

    /**
     * Format candidate relative image filenames (`webp` and `jpg`) for a movie cover or backdrop.
     *
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatImageCandidateRelativePaths(string|int|null $imdbId, string $suffix = 'cover'): ?array
    {
        return self::usesRustFfi()
            ? self::formatImageCandidateRelativePathsNative($imdbId, $suffix)
            : self::formatImageCandidateRelativePathsPhp($imdbId, $suffix);
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatImageCandidateRelativePathsNative(string|int|null $imdbId, string $suffix = 'cover'): ?array
    {
        if ($imdbId === null) {
            return null;
        }
        $kind = match ($suffix) {
            'cover' => 0,
            'backdrop' => 1,
            default => -1,
        };
        if ($kind < 0) {
            return null;
        }
        $raw = (string) $imdbId;
        if (($ffi = self::ffi()) === null) {
            return self::formatImageCandidateRelativePathsPhp($raw, $suffix);
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 128, $capacity);
        if ($buffer === null) {
            return self::formatImageCandidateRelativePathsPhp($raw, $suffix);
        }
        try {
            $outWebpLen = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_image_paths(
                $raw,
                \strlen($raw),
                $kind,
                $outWebpLen,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written === 0) {
                return null;
            }
            if (\is_int($written) && $written > 0 && $written <= $capacity) {
                $webpLen = (int) $outWebpLen[0];
                if ($webpLen > 0 && $webpLen < $written) {
                    $packed = FFI::string($buffer, $written);

                    return [
                        'webp' => substr($packed, 0, $webpLen),
                        'jpg' => substr($packed, $webpLen),
                    ];
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::formatImageCandidateRelativePathsPhp($raw, $suffix);
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatImageCandidateRelativePathsPhp(string|int|null $imdbId, string $suffix = 'cover'): ?array
    {
        if ($suffix !== 'cover' && $suffix !== 'backdrop') {
            return null;
        }
        $normalized = self::normalizeImdbIdPhp($imdbId);
        if ($normalized === null) {
            return null;
        }

        return [
            'webp' => $normalized.'-'.$suffix.'.webp',
            'jpg' => $normalized.'-'.$suffix.'.jpg',
        ];
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePaths(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePaths($imdbId, 'cover');
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePathsNative(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePathsNative($imdbId, 'cover');
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatCoverCandidateRelativePathsPhp(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePathsPhp($imdbId, 'cover');
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatBackdropCandidateRelativePaths(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePaths($imdbId, 'backdrop');
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatBackdropCandidateRelativePathsNative(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePathsNative($imdbId, 'backdrop');
    }

    /**
     * @return array{webp: string, jpg: string}|null
     */
    public static function formatBackdropCandidateRelativePathsPhp(string|int|null $imdbId): ?array
    {
        return self::formatImageCandidateRelativePathsPhp($imdbId, 'backdrop');
    }

    /**
     * Split a comma- or pipe-separated metadata field (`genre`, `actors`) into trimmed non-empty strings.
     *
     * @return list<string>
     */
    public static function splitCommaSeparatedList(?string $raw): array
    {
        return self::usesRustFfi()
            ? self::splitCommaSeparatedListNative($raw)
            : self::splitCommaSeparatedListPhp($raw);
    }

    /**
     * @return list<string>
     */
    public static function splitCommaSeparatedListNative(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (($ffi = self::ffi()) === null) {
            return self::splitCommaSeparatedListPhp($raw);
        }
        $rawLen = \strlen($raw);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, max(64, $rawLen + 16), $capacity);
        if ($buffer === null) {
            return self::splitCommaSeparatedListPhp($raw);
        }
        try {
            $outCount = $ffi->new('size_t[1]');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_split_csv(
                $raw,
                $rawLen,
                $outCount,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::splitCommaSeparatedListPhp($raw);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_movie_info_split_csv(
                    $raw,
                    $rawLen,
                    $outCount,
                    $buffer,
                    $capacity,
                );
            }
            if (\is_int($written) && $written === 0) {
                return [];
            }
            if (\is_int($written) && $written > 0 && $written <= $capacity) {
                $packed = FFI::string($buffer, $written);
                /** @var list<string> $parts */
                $parts = explode("\0", $packed);

                return $parts;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::splitCommaSeparatedListPhp($raw);
    }

    /**
     * @return list<string>
     */
    public static function splitCommaSeparatedListPhp(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $segments = preg_split('/[,|]/', $raw);
        if (! \is_array($segments)) {
            return [];
        }
        $items = [];
        foreach ($segments as $segment) {
            $trimmed = trim($segment, " \t\n\r\0\x0B");
            if ($trimmed !== '') {
                $items[] = $trimmed;
            }
        }

        return $items;
    }

    /**
     * Build canonical external provider URLs (`imdb`, `tmdb`, `trakt`) for a movie record.
     *
     * @return array{imdb: ?string, tmdb: ?string, trakt: ?string}
     */
    public static function buildExternalLinks(string|int|null $imdbId, ?int $tmdbId, ?int $traktId): array
    {
        return self::usesRustFfi()
            ? self::buildExternalLinksNative($imdbId, $tmdbId, $traktId)
            : self::buildExternalLinksPhp($imdbId, $tmdbId, $traktId);
    }

    /**
     * @return array{imdb: ?string, tmdb: ?string, trakt: ?string}
     */
    public static function buildExternalLinksNative(string|int|null $imdbId, ?int $tmdbId, ?int $traktId): array
    {
        if (($ffi = self::ffi()) === null) {
            return self::buildExternalLinksPhp($imdbId, $tmdbId, $traktId);
        }
        $imdbStr = $imdbId !== null ? (string) $imdbId : '';
        $imdbLen = \strlen($imdbStr);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 512, $capacity);
        if ($buffer === null) {
            return self::buildExternalLinksPhp($imdbId, $tmdbId, $traktId);
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_movie_info_external_links(
                $imdbStr,
                $imdbLen,
                $tmdbId ?? 0,
                $traktId ?? 0,
                $buffer,
                $capacity,
            );
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::buildExternalLinksPhp($imdbId, $tmdbId, $traktId);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_movie_info_external_links(
                    $imdbStr,
                    $imdbLen,
                    $tmdbId ?? 0,
                    $traktId ?? 0,
                    $buffer,
                    $capacity,
                );
            }
            if (\is_int($written) && $written > 0 && $written <= $capacity) {
                $decoded = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);
                if (\is_array($decoded) && \array_key_exists('imdb', $decoded) && \array_key_exists('tmdb', $decoded) && \array_key_exists('trakt', $decoded)) {
                    /** @var array{imdb: ?string, tmdb: ?string, trakt: ?string} $decoded */
                    return $decoded;
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::buildExternalLinksPhp($imdbId, $tmdbId, $traktId);
    }

    /**
     * @return array{imdb: ?string, tmdb: ?string, trakt: ?string}
     */
    public static function buildExternalLinksPhp(string|int|null $imdbId, ?int $tmdbId, ?int $traktId): array
    {
        $normalizedImdb = self::normalizeImdbIdPhp($imdbId);

        return [
            'imdb' => $normalizedImdb !== null ? 'https://www.imdb.com/title/tt'.$normalizedImdb.'/' : null,
            'tmdb' => ($tmdbId !== null && $tmdbId > 0) ? 'https://www.themoviedb.org/movie/'.$tmdbId : null,
            'trakt' => ($traktId !== null && $traktId > 0) ? 'https://trakt.tv/movies/'.$traktId : null,
        ];
    }

    /**
     * Get the preferred cover image path (prefers WebP over JPG).
     */
    public function getCoverPath(): ?string
    {
        $candidates = self::formatCoverCandidateRelativePaths($this->imdbid ?? null);
        if ($candidates === null) {
            return null;
        }

        $webpPath = public_path('covers/movies/'.$candidates['webp']);
        if (File::exists($webpPath)) {
            return 'covers/movies/'.$candidates['webp'];
        }

        $jpgPath = public_path('covers/movies/'.$candidates['jpg']);
        if (File::exists($jpgPath)) {
            return 'covers/movies/'.$candidates['jpg'];
        }

        return null;
    }

    /**
     * Get the preferred backdrop image path (prefers WebP over JPG).
     */
    public function getBackdropPath(): ?string
    {
        $candidates = self::formatBackdropCandidateRelativePaths($this->imdbid ?? null);
        if ($candidates === null) {
            return null;
        }

        $webpPath = public_path('covers/movies/'.$candidates['webp']);
        if (File::exists($webpPath)) {
            return 'covers/movies/'.$candidates['webp'];
        }

        $jpgPath = public_path('covers/movies/'.$candidates['jpg']);
        if (File::exists($jpgPath)) {
            return 'covers/movies/'.$candidates['jpg'];
        }

        return null;
    }

    /**
     * Check if a cover image exists on disk.
     */
    public function hasCoverImage(): bool
    {
        return $this->getCoverPath() !== null;
    }

    /**
     * Check if a backdrop image exists on disk.
     */
    public function hasBackdropImage(): bool
    {
        return $this->getBackdropPath() !== null;
    }

    /**
     * Get the cover image URL (prefers WebP over JPG).
     */
    public function getCoverUrl(): ?string
    {
        $path = $this->getCoverPath();

        return $path ? asset($path) : null;
    }

    /**
     * Get the backdrop image URL (prefers WebP over JPG).
     */
    public function getBackdropUrl(): ?string
    {
        $path = $this->getBackdropPath();

        return $path ? asset($path) : null;
    }

    /**
     * Get the parsed list of genres for this movie.
     *
     * @return list<string>
     */
    public function getGenresList(): array
    {
        return self::splitCommaSeparatedList($this->genre ?? null);
    }

    /**
     * Get the parsed list of actors for this movie.
     *
     * @return list<string>
     */
    public function getActorsList(): array
    {
        return self::splitCommaSeparatedList($this->actors ?? null);
    }

    /**
     * Get external provider links (`imdb`, `tmdb`, `trakt`) for this movie.
     *
     * @return array{imdb: ?string, tmdb: ?string, trakt: ?string}
     */
    public function getExternalLinks(): array
    {
        return self::buildExternalLinks(
            $this->imdbid ?? null,
            isset($this->tmdbid) ? (int) $this->tmdbid : null,
            isset($this->traktid) ? (int) $this->traktid : null,
        );
    }

    /**
     * Check whether at least one external provider link is available.
     */
    public function hasExternalLinks(): bool
    {
        $links = $this->getExternalLinks();

        return $links['imdb'] !== null || $links['tmdb'] !== null || $links['trakt'] !== null;
    }
}
