<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\TvInfo.
 *
 * @property int $videos_id FK to video.id
 * @property string $summary Description/summary of the show.
 * @property string $publisher The channel/network of production/release (ABC, BBC, Showtime, etc.).
 * @property string $localzone The linux tz style identifier
 * @property int $image Does the video have a cover image?
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereLocalzone($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo wherePublisher($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\TvInfo query()
 */
class TvInfo extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_tv_info_normalize_attributes(
            int64_t video_id,
            int64_t image,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustTvSubtitleSteamPasswordModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'tv_info';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var string
     */
    protected $primaryKey = 'videos_id';

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Clamps the video id, trims the summary, collapses the publisher, validates the tz-style zone and flags the image.
     *
     * @return array{videos_id: int, summary: string, publisher: string, localzone: string, image: int}
     */
    public static function normalizeTvInfoAttributes(int $videoId, string $summary, string $publisher, string $localZone, int $image): array
    {
        return self::usesRustFfi()
            ? self::normalizeTvInfoAttributesNative($videoId, $summary, $publisher, $localZone, $image)
            : self::normalizeTvInfoAttributesPhp($videoId, $summary, $publisher, $localZone, $image);
    }

    /**
     * @return array{videos_id: int, summary: string, publisher: string, localzone: string, image: int}
     */
    public static function normalizeTvInfoAttributesNative(int $videoId, string $summary, string $publisher, string $localZone, int $image): array
    {
        $input = $summary.self::FIELD_SEPARATOR.$publisher.self::FIELD_SEPARATOR.$localZone;
        $fields = self::callNative('nntmux_tv_info_normalize_attributes', [$videoId, $image, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 5) {
            return self::normalizeTvInfoAttributesPhp($videoId, $summary, $publisher, $localZone, $image);
        }

        return [
            'videos_id' => (int) $fields[0],
            'summary' => $fields[1],
            'publisher' => $fields[2],
            'localzone' => $fields[3],
            'image' => $fields[4] === '1' ? 1 : 0,
        ];
    }

    /**
     * @return array{videos_id: int, summary: string, publisher: string, localzone: string, image: int}
     */
    public static function normalizeTvInfoAttributesPhp(int $videoId, string $summary, string $publisher, string $localZone, int $image): array
    {
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $publisher) ?? $publisher;
        $zone = mb_substr(trim($localZone, self::WHITESPACE), 0, 50, 'UTF-8');

        return [
            'videos_id' => max(0, min($videoId, 4294967295)),
            'summary' => trim($summary, self::WHITESPACE),
            'publisher' => mb_substr(trim($collapsed, self::WHITESPACE), 0, 50, 'UTF-8'),
            'localzone' => preg_match('~^[A-Za-z0-9_+/-]*$~D', $zone) === 1 ? $zone : '',
            'image' => $image > 0 ? 1 : 0,
        ];
    }

    /**
     * @param  list<int|string>  $args
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
