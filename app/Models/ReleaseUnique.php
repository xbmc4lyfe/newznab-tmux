<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ReleaseUnique.
 *
 * @property int $releases_id FK to releases.id.
 * @property mixed $uniqueid Unique_ID from mediainfo.
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseUnique whereReleasesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseUnique whereUniqueid($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseUnique newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseUnique newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseUnique query()
 */
class ReleaseUnique extends Model
{
    use HasCompositePrimaryKey;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_release_unique_composite_key_columns_nul(
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'release_unique';

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
     * @var list<string>
     */
    protected $fillable = [
        'releases_id',
        'uniqueid',
    ];

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
        self::enableCompositeKeyRustFfi();
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
        self::disableCompositeKeyRustFfi();
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
        self::resetCompositeKeyRustFfi();
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
        self::setUseCompositeKeyRustFfi($useRustFfi);
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
        self::setCompositeKeyFfiForTesting($ffi);
    }

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return self::resolveCompositeKeyColumns();
    }

    /**
     * @return non-empty-list<string>
     */
    public static function resolveCompositeKeyColumns(): array
    {
        if (self::$useRustFfi) {
            return self::resolveCompositeKeyColumnsNative();
        }

        return self::resolveCompositeKeyColumnsPhp();
    }

    /**
     * @return non-empty-list<string>
     */
    public static function resolveCompositeKeyColumnsPhp(): array
    {
        return ['releases_id', 'uniqueid'];
    }

    /**
     * @return non-empty-list<string>
     */
    public static function resolveCompositeKeyColumnsNative(): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::resolveCompositeKeyColumnsPhp();
        }

        try {
            $cap = 64;
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_unique_composite_key_columns_nul($outBuf, $cap);
            if (! is_int($written) || $written <= 0) {
                return self::resolveCompositeKeyColumnsPhp();
            }

            $parts = explode("\0", FFI::string($outBuf, $written));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                return self::resolveCompositeKeyColumnsPhp();
            }

            return [$parts[0], $parts[1]];
        } catch (Throwable) {
            return self::resolveCompositeKeyColumnsPhp();
        }
    }

    private static function additionalFfi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
