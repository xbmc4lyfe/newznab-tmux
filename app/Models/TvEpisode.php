<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\TvEpisode.
 *
 * @property int $id
 * @property int $videos_id FK to videos.id of the parent series.
 * @property int $series Number of series/season.
 * @property int $episode Number of episode within series
 * @property string $se_complete String version of Series/Episode as taken from release subject (i.e. S02E21+22).
 * @property string $title Title of the episode.
 * @property string $firstaired Date of original airing/release.
 * @property string $summary Description/summary of the episode.
 * @property-read Collection|Release[] $release
 * @property-read Video $video
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereEpisode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereFirstaired($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereSeComplete($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereSeries($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvEpisode query()
 */
class TvEpisode extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_episode_format_se_code(
            int64_t series,
            int64_t episode,
            uint32_t has_raw,
            const char *raw_ptr,
            size_t raw_len,
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

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'videos_id');
    }

    /**
     * @return HasMany<Release, $this>
     */
    public function release(): HasMany
    {
        return $this->hasMany(Release::class, 'tv_episodes_id');
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
     * Clamps season/episode numbers and formats the `se_complete` code (raw subject text when present).
     *
     * @return array{series: int, episode: int, se_complete: string, canonical: string, is_special: bool, is_unnumbered: bool}
     */
    public static function formatSeasonEpisodeCode(int $series, int $episode, ?string $rawSeComplete = null): array
    {
        return self::usesRustFfi()
            ? self::formatSeasonEpisodeCodeNative($series, $episode, $rawSeComplete)
            : self::formatSeasonEpisodeCodePhp($series, $episode, $rawSeComplete);
    }

    /**
     * @return array{series: int, episode: int, se_complete: string, canonical: string, is_special: bool, is_unnumbered: bool}
     */
    public static function formatSeasonEpisodeCodeNative(int $series, int $episode, ?string $rawSeComplete = null): array
    {
        $raw = $rawSeComplete ?? '';
        $fields = self::callNative('nntmux_tv_episode_format_se_code', [
            $series,
            $episode,
            $rawSeComplete === null ? 0 : 1,
            $raw,
            strlen($raw),
        ]);
        if ($fields === null || count($fields) !== 6) {
            return self::formatSeasonEpisodeCodePhp($series, $episode, $rawSeComplete);
        }

        return [
            'series' => (int) $fields[0],
            'episode' => (int) $fields[1],
            'se_complete' => $fields[2],
            'canonical' => $fields[3],
            'is_special' => $fields[4] === '1',
            'is_unnumbered' => $fields[5] === '1',
        ];
    }

    /**
     * @return array{series: int, episode: int, se_complete: string, canonical: string, is_special: bool, is_unnumbered: bool}
     */
    public static function formatSeasonEpisodeCodePhp(int $series, int $episode, ?string $rawSeComplete = null): array
    {
        $series = max(0, min($series, 65535));
        $episode = max(0, min($episode, 65535));
        $canonical = sprintf('S%02dE%02d', $series, $episode);
        $raw = $rawSeComplete === null ? '' : trim($rawSeComplete, self::WHITESPACE);

        return [
            'series' => $series,
            'episode' => $episode,
            'se_complete' => strtoupper(mb_substr($raw === '' ? $canonical : $raw, 0, 10, 'UTF-8')),
            'canonical' => $canonical,
            'is_special' => $series === 0,
            'is_unnumbered' => $episode === 0,
        ];
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
