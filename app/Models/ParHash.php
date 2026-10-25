<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ParHash.
 *
 * @property int $releases_id FK to releases.id
 * @property string $hash hash_16k block of par2
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ParHash whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ParHash whereReleasesId($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ParHash newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ParHash newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ParHash query()
 */
class ParHash extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par_hash_normalize_16k(
            int64_t release_id,
            const char *hash_ptr,
            size_t hash_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategoryGroupParhashModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int UNSIGNED_INT_MAX = 4294967295;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $primaryKey = 'releases_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['releases_id', 'hash'];
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
     * Normalizes a `par_hashes` row: accepts only a 32-character hexadecimal PAR2 hash_16k
     * (lowercased, as `ReleaseFile::normalizeParHash()` stores it) and clamps the release id.
     *
     * @return array{releases_id: int, hash: ?string, is_valid: bool}
     */
    public static function normalizePar2Hash16k(int $releaseId, string $hash): array
    {
        return self::usesRustFfi()
            ? self::normalizePar2Hash16kNative($releaseId, $hash)
            : self::normalizePar2Hash16kPhp($releaseId, $hash);
    }

    /**
     * @return array{releases_id: int, hash: ?string, is_valid: bool}
     */
    public static function normalizePar2Hash16kNative(int $releaseId, string $hash): array
    {
        $fields = self::callNative('nntmux_par_hash_normalize_16k', [$releaseId, $hash, strlen($hash)]);
        if ($fields === null || count($fields) !== 3 || $fields[0] === null || $fields[2] === null) {
            return self::normalizePar2Hash16kPhp($releaseId, $hash);
        }

        return [
            'releases_id' => (int) $fields[0],
            'hash' => $fields[1],
            'is_valid' => $fields[2] === '1',
        ];
    }

    /**
     * @return array{releases_id: int, hash: ?string, is_valid: bool}
     */
    public static function normalizePar2Hash16kPhp(int $releaseId, string $hash): array
    {
        $normalized = strlen($hash) === 32 && ctype_xdigit($hash) ? strtolower($hash) : null;

        return [
            'releases_id' => max(0, min($releaseId, self::UNSIGNED_INT_MAX)),
            'hash' => $normalized,
            'is_valid' => $normalized !== null && $releaseId >= 1 && $releaseId <= self::UNSIGNED_INT_MAX,
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
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

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
