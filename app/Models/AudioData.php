<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\AudioData.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property int $audioid
 * @property string|null $audioformat
 * @property string|null $audiomode
 * @property string|null $audiobitratemode
 * @property string|null $audiobitrate
 * @property string|null $audiochannels
 * @property string|null $audiosamplerate
 * @property string|null $audiolibrary
 * @property string|null $audiolanguage
 * @property string|null $audiotitle
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiobitrate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiobitratemode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiochannels($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudioformat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudioid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiolanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiolibrary($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiomode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiosamplerate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereAudiotitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData whereReleasesId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AudioData query()
 */
class AudioData extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_audio_data_normalize_track(
            int64_t release_id,
            int64_t audio_id,
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
    protected $table = 'audio_data';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

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
     * Normalizes a MediaInfo audio track into `audio_data` column values.
     *
     * @return array{releases_id: int, audioid: int, audioformat: ?string, audiomode: ?string, audiobitratemode: ?string, audiobitrate: ?string, audiochannels: ?string, audiosamplerate: ?string, audiolanguage: ?string, channel_count: ?int, composite_key: string}
     */
    public static function normalizeAudioTrackAttributes(
        int $releaseId,
        int $audioId,
        ?string $format,
        ?string $mode,
        ?string $bitrateMode,
        ?string $bitrate,
        ?string $channels,
        ?string $sampleRate,
        ?string $language
    ): array {
        return self::usesRustFfi()
            ? self::normalizeAudioTrackAttributesNative($releaseId, $audioId, $format, $mode, $bitrateMode, $bitrate, $channels, $sampleRate, $language)
            : self::normalizeAudioTrackAttributesPhp($releaseId, $audioId, $format, $mode, $bitrateMode, $bitrate, $channels, $sampleRate, $language);
    }

    /**
     * @return array{releases_id: int, audioid: int, audioformat: ?string, audiomode: ?string, audiobitratemode: ?string, audiobitrate: ?string, audiochannels: ?string, audiosamplerate: ?string, audiolanguage: ?string, channel_count: ?int, composite_key: string}
     */
    public static function normalizeAudioTrackAttributesNative(
        int $releaseId,
        int $audioId,
        ?string $format,
        ?string $mode,
        ?string $bitrateMode,
        ?string $bitrate,
        ?string $channels,
        ?string $sampleRate,
        ?string $language
    ): array {
        $values = [$format, $mode, $bitrateMode, $bitrate, $channels, $sampleRate, $language];
        $nullMask = 0;
        foreach ($values as $index => $value) {
            if ($value === null) {
                $nullMask |= 1 << $index;
            }
        }
        $input = implode(self::FIELD_SEPARATOR, $values);
        $fields = self::callNative('nntmux_audio_data_normalize_track', [$releaseId, $audioId, $nullMask, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 11) {
            return self::normalizeAudioTrackAttributesPhp($releaseId, $audioId, $format, $mode, $bitrateMode, $bitrate, $channels, $sampleRate, $language);
        }
        $channelCount = self::decodeOptional($fields[9]);

        return [
            'releases_id' => (int) $fields[0],
            'audioid' => (int) $fields[1],
            'audioformat' => self::decodeOptional($fields[2]),
            'audiomode' => self::decodeOptional($fields[3]),
            'audiobitratemode' => self::decodeOptional($fields[4]),
            'audiobitrate' => self::decodeOptional($fields[5]),
            'audiochannels' => self::decodeOptional($fields[6]),
            'audiosamplerate' => self::decodeOptional($fields[7]),
            'audiolanguage' => self::decodeOptional($fields[8]),
            'channel_count' => $channelCount === null ? null : (int) $channelCount,
            'composite_key' => $fields[10],
        ];
    }

    /**
     * @return array{releases_id: int, audioid: int, audioformat: ?string, audiomode: ?string, audiobitratemode: ?string, audiobitrate: ?string, audiochannels: ?string, audiosamplerate: ?string, audiolanguage: ?string, channel_count: ?int, composite_key: string}
     */
    public static function normalizeAudioTrackAttributesPhp(
        int $releaseId,
        int $audioId,
        ?string $format,
        ?string $mode,
        ?string $bitrateMode,
        ?string $bitrate,
        ?string $channels,
        ?string $sampleRate,
        ?string $language
    ): array {
        $releaseId = max(0, min($releaseId, 4294967295));
        $audioId = max(0, min($audioId, 4294967295));
        $channels = self::cleanOptional($channels, 25);

        return [
            'releases_id' => $releaseId,
            'audioid' => $audioId,
            'audioformat' => self::cleanOptional($format, 50),
            'audiomode' => self::cleanOptional($mode, 50),
            'audiobitratemode' => self::cleanOptional($bitrateMode, 50),
            'audiobitrate' => self::cleanOptional($bitrate, 10),
            'audiochannels' => $channels,
            'audiosamplerate' => self::cleanOptional($sampleRate, 25),
            'audiolanguage' => self::cleanOptional($language, 50),
            'channel_count' => self::channelCountPhp($channels),
            'composite_key' => $releaseId.':'.$audioId,
        ];
    }

    /**
     * Leading 1-3 digit channel count ("6 channels" => 6); zero or no digits => null.
     */
    private static function channelCountPhp(?string $channels): ?int
    {
        if ($channels === null) {
            return null;
        }
        $digits = strspn($channels, '0123456789');
        if ($digits === 0 || $digits > 3) {
            return null;
        }
        $count = (int) substr($channels, 0, $digits);

        return $count === 0 ? null : $count;
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
