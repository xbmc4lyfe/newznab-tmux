<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\Carbon;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\MusicInfo.
 *
 * @property int $id
 * @property string $title
 * @property string|null $asin
 * @property string|null $url
 * @property int|null $salesrank
 * @property string|null $artist
 * @property string|null $publisher
 * @property string|null $releasedate
 * @property string|null $review
 * @property string $year
 * @property int|null $genres_id
 * @property string|null $tracks
 * @property bool $cover
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property int|null $_totalcount Computed total count from aggregate queries
 * @property-read Genre|null $genre
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereArtist($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereAsin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereCover($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereGenresId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereReleasedate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereReview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereSalesrank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereTracks($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo whereYear($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\MusicInfo query()
 */
class MusicInfo extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_music_info_normalize_catalogue(
            uint32_t has_genre,
            int64_t genre_id,
            uint32_t cover,
            uint32_t null_mask,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustMediaCatalogueModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARKER = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'musicinfo';

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = true;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Genre, $this>
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class, 'genres_id');
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes catalogue values for a `musicinfo` row and builds its lowercase search key.
     *
     * @return array{title: string, artist: ?string, year: string, asin: ?string, genres_id: ?int, cover: int, search_key: string}
     */
    public static function normalizeMusicCatalogueAttributes(string $title, ?string $artist, ?string $year, ?string $asin, ?int $genreId, bool $cover): array
    {
        return self::usesRustFfi()
            ? self::normalizeMusicCatalogueAttributesNative($title, $artist, $year, $asin, $genreId, $cover)
            : self::normalizeMusicCatalogueAttributesPhp($title, $artist, $year, $asin, $genreId, $cover);
    }

    /**
     * @return array{title: string, artist: ?string, year: string, asin: ?string, genres_id: ?int, cover: int, search_key: string}
     */
    public static function normalizeMusicCatalogueAttributesNative(string $title, ?string $artist, ?string $year, ?string $asin, ?int $genreId, bool $cover): array
    {
        $input = $title.self::FIELD_SEPARATOR.$artist.self::FIELD_SEPARATOR.$year.self::FIELD_SEPARATOR.$asin;
        $nullMask = ($artist === null ? 0b10 : 0) | ($year === null ? 0b100 : 0) | ($asin === null ? 0b1000 : 0);
        $fields = self::callNative('nntmux_music_info_normalize_catalogue', [
            $genreId === null ? 0 : 1,
            $genreId ?? 0,
            $cover ? 1 : 0,
            $nullMask,
            $input,
            strlen($input),
        ]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeMusicCatalogueAttributesPhp($title, $artist, $year, $asin, $genreId, $cover);
        }
        $genre = self::decodeOptional($fields[4]);

        return [
            'title' => $fields[0],
            'artist' => self::decodeOptional($fields[1]),
            'year' => $fields[2],
            'asin' => self::decodeOptional($fields[3]),
            'genres_id' => $genre === null ? null : (int) $genre,
            'cover' => (int) $fields[5],
            'search_key' => $fields[6],
        ];
    }

    /**
     * @return array{title: string, artist: ?string, year: string, asin: ?string, genres_id: ?int, cover: int, search_key: string}
     */
    public static function normalizeMusicCatalogueAttributesPhp(string $title, ?string $artist, ?string $year, ?string $asin, ?int $genreId, bool $cover): array
    {
        $title = self::cleanText($title, 255);
        if ($artist !== null) {
            $artist = self::cleanText($artist, 255);
            if ($artist === '') {
                $artist = null;
            }
        }
        $year = $year !== null && preg_match('/(?<!\d)((?:18|19|20|21)\d{2})(?!\d)/', $year, $match) === 1 ? $match[1] : '';

        return [
            'title' => $title,
            'artist' => $artist,
            'year' => $year,
            'asin' => self::cleanOptional($asin, 128),
            'genres_id' => $genreId === null || $genreId <= 0 || $genreId > 2147483647 ? null : $genreId,
            'cover' => $cover ? 1 : 0,
            'search_key' => strtolower($artist !== null ? $artist.' - '.$title : $title),
        ];
    }

    /**
     * Collapses ASCII whitespace runs, trims and keeps at most `$max` characters.
     */
    private static function cleanText(string $value, int $max): string
    {
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $value) ?? $value;

        return mb_substr(trim($collapsed, self::WHITESPACE), 0, $max, 'UTF-8');
    }

    /**
     * Trims, maps an empty value to null and keeps at most `$max` characters.
     */
    private static function cleanOptional(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value, self::WHITESPACE);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    private static function decodeOptional(string $field): ?string
    {
        return $field === self::NULL_MARKER ? null : $field;
    }

    /**
     * @param  list<int|float|string>  $args
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 4096, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
