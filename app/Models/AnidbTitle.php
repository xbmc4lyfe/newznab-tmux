<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * App\Models\AnidbTitle.
 *
 * @property int $anidbid ID of title from AniDB
 * @property string $type type of title.
 * @property string $lang
 * @property string $title
 * @property-read Collection|AnidbInfo[] $info
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle whereAnidbid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle whereLang($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle whereType($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\AnidbTitle query()
 */
class AnidbTitle extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_anidb_title_normalize_attributes(
            int64_t anidb_id,
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

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $primaryKey = 'anidbid';

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['anidbid', 'type', 'lang', 'title'];
    }

    /**
     * @return HasMany<AnidbInfo, $this>
     */
    public function info(): HasMany
    {
        return $this->hasMany(AnidbInfo::class, 'anidbid');
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
     * Normalizes an `anidb_titles` row and builds its lowercase composite key.
     *
     * @return array{anidbid: int, type: string, lang: string, title: string, composite_key: string}
     */
    public static function normalizeTitleAttributes(int $anidbId, string $type, string $lang, string $title): array
    {
        return self::usesRustFfi()
            ? self::normalizeTitleAttributesNative($anidbId, $type, $lang, $title)
            : self::normalizeTitleAttributesPhp($anidbId, $type, $lang, $title);
    }

    /**
     * @return array{anidbid: int, type: string, lang: string, title: string, composite_key: string}
     */
    public static function normalizeTitleAttributesNative(int $anidbId, string $type, string $lang, string $title): array
    {
        $input = $type.self::FIELD_SEPARATOR.$lang.self::FIELD_SEPARATOR.$title;
        $fields = self::callNative('nntmux_anidb_title_normalize_attributes', [$anidbId, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 5) {
            return self::normalizeTitleAttributesPhp($anidbId, $type, $lang, $title);
        }

        return [
            'anidbid' => (int) $fields[0],
            'type' => $fields[1],
            'lang' => $fields[2],
            'title' => $fields[3],
            'composite_key' => $fields[4],
        ];
    }

    /**
     * @return array{anidbid: int, type: string, lang: string, title: string, composite_key: string}
     */
    public static function normalizeTitleAttributesPhp(int $anidbId, string $type, string $lang, string $title): array
    {
        $anidbId = max(0, min($anidbId, 4294967295));
        $type = strtolower(mb_substr(trim($type, self::WHITESPACE), 0, 25, 'UTF-8'));
        $lang = strtolower(mb_substr(trim($lang, self::WHITESPACE), 0, 25, 'UTF-8'));
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $title) ?? $title;
        $title = mb_substr(trim($collapsed, self::WHITESPACE), 0, 255, 'UTF-8');

        return [
            'anidbid' => $anidbId,
            'type' => $type,
            'lang' => $lang,
            'title' => $title,
            'composite_key' => $anidbId.'|'.$type.'|'.$lang.'|'.strtolower($title),
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
