<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\VideoData.
 *
 * @property int $releases_id FK to releases.id
 * @property string|null $containerformat
 * @property string|null $overallbitrate
 * @property string|null $videoduration
 * @property string|null $videoformat
 * @property string|null $videocodec
 * @property int|null $videowidth
 * @property int|null $videoheight
 * @property string|null $videoaspect
 * @property float|null $videoframerate
 * @property string|null $videolibrary
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereContainerformat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereOverallbitrate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideoaspect($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideocodec($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideoduration($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideoformat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideoframerate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideoheight($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideolibrary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData whereVideowidth($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoData query()
 */
class VideoData extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_video_data_normalize_stream(
            int64_t release_id,
            uint32_t has_width,
            int64_t width,
            uint32_t has_height,
            int64_t height,
            uint32_t has_frame_rate,
            double frame_rate,
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

    private const float ASPECT_TOLERANCE = 0.02;

    /**
     * Named display aspect ratios, matched to the closest within ASPECT_TOLERANCE (first wins ties).
     */
    private const array ASPECT_RATIOS = [
        ['1:1', 1.0],
        ['5:4', 1.25],
        ['4:3', 4 / 3],
        ['3:2', 1.5],
        ['16:10', 1.6],
        ['16:9', 16 / 9],
        ['1.85:1', 1.85],
        ['2:1', 2.0],
        ['2.20:1', 2.2],
        ['2.35:1', 2.35],
        ['2.39:1', 2.39],
    ];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'video_data';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $primaryKey = 'releases_id';

    /**
     * @var array<string>
     */
    protected $guarded = [];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes MediaInfo video stream values and classifies the aspect ratio and resolution.
     *
     * @return array{releases_id: int, containerformat: ?string, videocodec: ?string, videowidth: ?int, videoheight: ?int, videoaspect: ?string, resolution_class: string, videoframerate: ?float}
     */
    public static function normalizeVideoStreamAttributes(int $releaseId, ?string $container, ?string $codec, ?int $width, ?int $height, ?float $frameRate): array
    {
        return self::usesRustFfi()
            ? self::normalizeVideoStreamAttributesNative($releaseId, $container, $codec, $width, $height, $frameRate)
            : self::normalizeVideoStreamAttributesPhp($releaseId, $container, $codec, $width, $height, $frameRate);
    }

    /**
     * @return array{releases_id: int, containerformat: ?string, videocodec: ?string, videowidth: ?int, videoheight: ?int, videoaspect: ?string, resolution_class: string, videoframerate: ?float}
     */
    public static function normalizeVideoStreamAttributesNative(int $releaseId, ?string $container, ?string $codec, ?int $width, ?int $height, ?float $frameRate): array
    {
        $input = $container.self::FIELD_SEPARATOR.$codec;
        $fields = self::callNative('nntmux_video_data_normalize_stream', [
            $releaseId,
            $width === null ? 0 : 1,
            $width ?? 0,
            $height === null ? 0 : 1,
            $height ?? 0,
            $frameRate === null ? 0 : 1,
            $frameRate ?? 0.0,
            ($container === null ? 0b01 : 0) | ($codec === null ? 0b10 : 0),
            $input,
            strlen($input),
        ]);
        if ($fields === null || count($fields) !== 8) {
            return self::normalizeVideoStreamAttributesPhp($releaseId, $container, $codec, $width, $height, $frameRate);
        }
        $nullable = static fn (string $field): ?string => $field === self::NULL_MARKER ? null : $field;
        $videoWidth = $nullable($fields[3]);
        $videoHeight = $nullable($fields[4]);

        return [
            'releases_id' => (int) $fields[0],
            'containerformat' => $nullable($fields[1]),
            'videocodec' => $nullable($fields[2]),
            'videowidth' => $videoWidth === null ? null : (int) $videoWidth,
            'videoheight' => $videoHeight === null ? null : (int) $videoHeight,
            'videoaspect' => $nullable($fields[5]),
            'resolution_class' => $fields[6],
            'videoframerate' => $fields[7] === '1' ? $frameRate : null,
        ];
    }

    /**
     * @return array{releases_id: int, containerformat: ?string, videocodec: ?string, videowidth: ?int, videoheight: ?int, videoaspect: ?string, resolution_class: string, videoframerate: ?float}
     */
    public static function normalizeVideoStreamAttributesPhp(int $releaseId, ?string $container, ?string $codec, ?int $width, ?int $height, ?float $frameRate): array
    {
        $width = $width !== null && $width > 0 && $width <= 2147483647 ? $width : null;
        $height = $height !== null && $height > 0 && $height <= 2147483647 ? $height : null;

        return [
            'releases_id' => max(0, min($releaseId, 4294967295)),
            'containerformat' => self::cleanOptional($container, 50),
            'videocodec' => self::cleanOptional($codec, 50),
            'videowidth' => $width,
            'videoheight' => $height,
            'videoaspect' => $width !== null && $height !== null ? self::aspectLabelPhp($width, $height) : null,
            'resolution_class' => self::resolutionClassPhp($width, $height),
            'videoframerate' => $frameRate !== null && is_finite($frameRate) && $frameRate > 0 && $frameRate < 1000 ? $frameRate : null,
        ];
    }

    private static function aspectLabelPhp(int $width, int $height): string
    {
        $ratio = (float) $width / (float) $height;
        $best = null;
        $bestDiff = INF;
        foreach (self::ASPECT_RATIOS as [$label, $target]) {
            $diff = abs($ratio - $target);
            if ($diff < $bestDiff) {
                $best = $label;
                $bestDiff = $diff;
            }
        }
        if ($best !== null && $bestDiff <= self::ASPECT_TOLERANCE) {
            return $best;
        }
        // Round-half-up hundredths in integer arithmetic, e.g. 2.76:1.
        $hundredths = intdiv(200 * $width + $height, 2 * $height);

        return substr(sprintf('%d.%02d:1', intdiv($hundredths, 100), $hundredths % 100), 0, 10);
    }

    private static function resolutionClassPhp(?int $width, ?int $height): string
    {
        if ($width === null && $height === null) {
            return 'unknown';
        }
        $w = $width ?? 0;
        $h = $height ?? 0;

        return match (true) {
            $h >= 2000 || $w >= 3800 => '2160p',
            $h >= 1400 || $w >= 2500 => '1440p',
            $h >= 1000 || $w >= 1900 => '1080p',
            $h >= 700 || $w >= 1260 => '720p',
            $h >= 540 => '576p',
            $h >= 400 => '480p',
            default => 'sd',
        };
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
