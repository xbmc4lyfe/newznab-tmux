<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * App\Models\Genre.
 *
 * @property int $id
 * @property string $title
 * @property int|null $type
 * @property bool $disabled
 * @property-read Collection|MusicInfo[] $music
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre whereDisabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre disabled()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre enabled()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre ofType($type)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Genre query()
 */
class Genre extends Model
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_genre_has_type_filter(
            const char *type_ptr,
            size_t type_len
        );
        ssize_t nntmux_genre_build_active_sql(
            uint32_t has_type,
            int64_t type_int,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustRolePaymentGenreMetricModelParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public static function setUseRustFfi(bool $useRustFfi = true): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @return HasMany<MusicInfo, $this>
     */
    public function music(): HasMany
    {
        return $this->hasMany(MusicInfo::class, 'genres_id');
    }

    /**
     * @return HasMany<ConsoleInfo, $this>
     */
    public function consoleInfo(): HasMany
    {
        return $this->hasMany(ConsoleInfo::class, 'genres_id');
    }

    /**
     * @return HasMany<GamesInfo, $this>
     */
    public function gamesInfo(): HasMany
    {
        return $this->hasMany(GamesInfo::class, 'genres_id');
    }

    /**
     * Scope to filter disabled genres.
     *
     * @param  Builder<Genre>  $query
     * @return Builder<Genre>
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('disabled', '=', 1);
    }

    /**
     * Scope to filter enabled genres.
     *
     * @param  Builder<Genre>  $query
     * @return Builder<Genre>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('disabled', '=', 0);
    }

    /**
     * Scope to filter by type.
     *
     * @param  Builder<Genre>  $query
     * @return Builder<Genre>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        if (self::hasTypeFilter($type)) {
            return $query->where('type', '=', $type);
        }

        return $query;
    }

    /**
     * Get filtered genres based on type and active status.
     */
    public static function getFiltered(string $type = '', bool $activeOnly = false): Collection // @phpstan-ignore missingType.generics
    {
        if ($activeOnly) {
            return self::getActiveGenres($type);
        }

        $query = self::query();

        if (self::hasTypeFilter($type)) {
            $query->ofType($type);
        }

        return $query->orderBy('title')->get();
    }

    /**
     * Get count of filtered genres.
     */
    public static function getFilteredCount(string $type = '', bool $activeOnly = false): int
    {
        if ($activeOnly) {
            return self::getActiveGenres($type)->count();
        }

        $query = self::query();

        if (self::hasTypeFilter($type)) {
            $query->ofType($type);
        }

        return $query->count();
    }

    public static function hasTypeFilter(string $type): bool
    {
        return self::$useRustFfi
            ? self::hasTypeFilterNative($type)
            : self::hasTypeFilterPhp($type);
    }

    public static function hasTypeFilterNative(string $type): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::hasTypeFilterPhp($type);
        }

        try {
            $rc = self::callFfi($ffi, 'nntmux_genre_has_type_filter', $type, strlen($type));
            if ($rc === 1) {
                return true;
            }
            if ($rc === 0) {
                return false;
            }
        } catch (Throwable) {
        }

        return self::hasTypeFilterPhp($type);
    }

    public static function hasTypeFilterPhp(string $type): bool
    {
        return ! empty($type);
    }

    public static function buildActiveGenresSql(string $type = ''): string
    {
        return self::$useRustFfi
            ? self::buildActiveGenresSqlNative($type)
            : self::buildActiveGenresSqlPhp($type);
    }

    public static function buildActiveGenresSqlNative(string $type = ''): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildActiveGenresSqlPhp($type);
        }

        $hasType = ! empty($type);
        $typeInt = $hasType ? intval($type) : 0;

        try {
            $capacity = 0;
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($outBuf === null) {
                return self::buildActiveGenresSqlPhp($type);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_genre_build_active_sql',
                $hasType ? 1 : 0,
                $typeInt,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written > 0 && $written <= $capacity) {
                return FFI::string($outBuf, $written);
            }
        } catch (Throwable) {
        }

        return self::buildActiveGenresSqlPhp($type);
    }

    public static function buildActiveGenresSqlPhp(string $type = ''): string
    {
        $typeSql = ! empty($type) ? ' AND g.type = '.intval($type) : '';

        return sprintf(
            'SELECT g.*
            FROM genres g
            INNER JOIN (SELECT DISTINCT genres_id FROM musicinfo) x ON x.genres_id = g.id WHERE 1=1 %1$s
            UNION
            SELECT g.*
            FROM genres g
            INNER JOIN (SELECT DISTINCT genres_id FROM consoleinfo) x ON x.genres_id = g.id WHERE 1=1 %1$s
            UNION
            SELECT g.*
            FROM genres g
            INNER JOIN (SELECT DISTINCT genres_id FROM gamesinfo) x ON x.genres_id = g.id WHERE 1=1 %1$s
            ORDER BY title',
            $typeSql
        );
    }

    /**
     * Get genres that are actively used in musicinfo, consoleinfo, or gamesinfo.
     */
    private static function getActiveGenres(string $type = ''): Collection // @phpstan-ignore missingType.generics
    {
        $sql = self::buildActiveGenresSql($type);

        $results = DB::select($sql);

        return self::hydrate($results);
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
