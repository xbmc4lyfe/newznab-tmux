<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ReleaseSubtitle.
 *
 * @property int $id
 * @property int $releases_id FK to releases.id
 * @property int $subsid
 * @property string $subslanguage
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle whereSubsid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle whereSubslanguage($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSubtitle query()
 */
class ReleaseSubtitle extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_subtitle_normalize_track(
            int64_t release_id,
            int64_t subs_id,
            const char *language_ptr,
            size_t language_len,
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
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'releases_id',
        'subsid',
        'subslanguage',
    ];

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Clamps the ids, collapses the language label and builds the `releases_id:subsid` unique key.
     *
     * @return array{releases_id: int, subsid: int, subslanguage: string, language_key: string, composite_key: string}
     */
    public static function normalizeSubtitleTrack(int $releaseId, int $subsId, string $subsLanguage): array
    {
        return self::usesRustFfi()
            ? self::normalizeSubtitleTrackNative($releaseId, $subsId, $subsLanguage)
            : self::normalizeSubtitleTrackPhp($releaseId, $subsId, $subsLanguage);
    }

    /**
     * @return array{releases_id: int, subsid: int, subslanguage: string, language_key: string, composite_key: string}
     */
    public static function normalizeSubtitleTrackNative(int $releaseId, int $subsId, string $subsLanguage): array
    {
        $fields = self::callNative('nntmux_release_subtitle_normalize_track', [$releaseId, $subsId, $subsLanguage, strlen($subsLanguage)]);
        if ($fields === null || count($fields) !== 5) {
            return self::normalizeSubtitleTrackPhp($releaseId, $subsId, $subsLanguage);
        }

        return [
            'releases_id' => (int) $fields[0],
            'subsid' => (int) $fields[1],
            'subslanguage' => $fields[2],
            'language_key' => $fields[3],
            'composite_key' => $fields[4],
        ];
    }

    /**
     * @return array{releases_id: int, subsid: int, subslanguage: string, language_key: string, composite_key: string}
     */
    public static function normalizeSubtitleTrackPhp(int $releaseId, int $subsId, string $subsLanguage): array
    {
        $releaseId = max(0, min($releaseId, 4294967295));
        $subsId = max(0, min($subsId, 4294967295));
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $subsLanguage) ?? $subsLanguage;
        $language = mb_substr(trim($collapsed, self::WHITESPACE), 0, 50, 'UTF-8');

        return [
            'releases_id' => $releaseId,
            'subsid' => $subsId,
            'subslanguage' => $language,
            'language_key' => strtolower($language),
            'composite_key' => $releaseId.':'.$subsId,
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
