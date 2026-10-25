<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\SteamApp.
 *
 * @property int $id
 * @property string $name Steam application name
 * @property int $appid Steam application id
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp whereAppid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp whereName($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\SteamApp query()
 */
class SteamApp extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_steam_app_normalize_record(
            int64_t app_id,
            const char *name_ptr,
            size_t name_len,
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

    protected $dateFormat = false;

    /**
     * @var bool
     */
    public $timestamps = false;

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
     * Clamps the app id, collapses the name and derives a lowercase ASCII slug.
     *
     * @return array{appid: int, name: string, slug: string, is_valid: bool}
     */
    public static function normalizeSteamAppRecord(int $appId, string $name): array
    {
        return self::usesRustFfi()
            ? self::normalizeSteamAppRecordNative($appId, $name)
            : self::normalizeSteamAppRecordPhp($appId, $name);
    }

    /**
     * @return array{appid: int, name: string, slug: string, is_valid: bool}
     */
    public static function normalizeSteamAppRecordNative(int $appId, string $name): array
    {
        $fields = self::callNative('nntmux_steam_app_normalize_record', [$appId, $name, strlen($name)]);
        if ($fields === null || count($fields) !== 4) {
            return self::normalizeSteamAppRecordPhp($appId, $name);
        }

        return [
            'appid' => (int) $fields[0],
            'name' => $fields[1],
            'slug' => $fields[2],
            'is_valid' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{appid: int, name: string, slug: string, is_valid: bool}
     */
    public static function normalizeSteamAppRecordPhp(int $appId, string $name): array
    {
        $appId = max(0, min($appId, 4294967295));
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $name) ?? $name;
        $name = mb_substr(trim($collapsed, self::WHITESPACE), 0, 255, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '';

        return [
            'appid' => $appId,
            'name' => $name,
            'slug' => trim($slug, '-'),
            'is_valid' => $appId > 0 && $name !== '',
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
