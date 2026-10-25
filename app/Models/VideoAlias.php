<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * App\Models\VideoAlias.
 *
 * @property int $videos_id FK to videos.id of the parent title.
 * @property string $title AKA of the video.
 * @property-read Video $video
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoAlias whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoAlias whereVideosId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoAlias newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoAlias newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\VideoAlias query()
 */
class VideoAlias extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_video_alias_normalize_pair(
            int64_t video_id,
            const char *title_ptr,
            size_t title_len,
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

    protected $table = 'videos_aliases';

    /**
     * @var string
     */
    protected $primaryKey = 'videos_id';

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['videos_id', 'title'];
    }

    /**
     * @return BelongsTo<Video, $this>
     */
    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'videos_id');
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
     * Normalizes an AKA title and builds its lowercase `videos_id|title` composite key.
     *
     * @return array{videos_id: int, title: string, composite_key: string, is_valid: bool}
     */
    public static function normalizeAliasPair(int $videoId, string $title): array
    {
        return self::usesRustFfi()
            ? self::normalizeAliasPairNative($videoId, $title)
            : self::normalizeAliasPairPhp($videoId, $title);
    }

    /**
     * @return array{videos_id: int, title: string, composite_key: string, is_valid: bool}
     */
    public static function normalizeAliasPairNative(int $videoId, string $title): array
    {
        $fields = self::callNative('nntmux_video_alias_normalize_pair', [$videoId, $title, strlen($title)]);
        if ($fields === null || count($fields) !== 4) {
            return self::normalizeAliasPairPhp($videoId, $title);
        }

        return [
            'videos_id' => (int) $fields[0],
            'title' => $fields[1],
            'composite_key' => $fields[2],
            'is_valid' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{videos_id: int, title: string, composite_key: string, is_valid: bool}
     */
    public static function normalizeAliasPairPhp(int $videoId, string $title): array
    {
        $videoId = max(0, min($videoId, 4294967295));
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $title) ?? $title;
        $title = mb_substr(trim($collapsed, self::WHITESPACE), 0, 180, 'UTF-8');

        return [
            'videos_id' => $videoId,
            'title' => $title,
            'composite_key' => $videoId.'|'.strtolower($title),
            'is_valid' => $videoId > 0 && $title !== '',
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
