<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * @property int $releases_id
 * @property string $password
 * @property-read Release $release
 */
class ReleaseNzbPassword extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_nzb_password_normalize_entry(
            int64_t release_id,
            const char *password_ptr,
            size_t password_len,
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

    public $timestamps = false;

    protected $primaryKey = 'releases_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @var array<string> */
    protected $guarded = [];

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releases_id');
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
     * Clamps the release id and trims the password (inner whitespace is significant) to the column width.
     *
     * @return array{releases_id: int, password: string, has_password: bool}
     */
    public static function normalizePasswordEntry(int $releaseId, string $password): array
    {
        return self::usesRustFfi()
            ? self::normalizePasswordEntryNative($releaseId, $password)
            : self::normalizePasswordEntryPhp($releaseId, $password);
    }

    /**
     * @return array{releases_id: int, password: string, has_password: bool}
     */
    public static function normalizePasswordEntryNative(int $releaseId, string $password): array
    {
        $fields = self::callNative('nntmux_release_nzb_password_normalize_entry', [$releaseId, $password, strlen($password)]);
        if ($fields === null || count($fields) !== 3) {
            return self::normalizePasswordEntryPhp($releaseId, $password);
        }

        return [
            'releases_id' => (int) $fields[0],
            'password' => $fields[1],
            'has_password' => $fields[2] === '1',
        ];
    }

    /**
     * @return array{releases_id: int, password: string, has_password: bool}
     */
    public static function normalizePasswordEntryPhp(int $releaseId, string $password): array
    {
        $password = mb_substr(trim($password, self::WHITESPACE), 0, 255, 'UTF-8');

        return [
            'releases_id' => max(0, min($releaseId, 4294967295)),
            'password' => $password,
            'has_password' => $password !== '',
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
