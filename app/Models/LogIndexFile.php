<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use Database\Factories\LogIndexFileFactory;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Indexing progress of one log file in the Manticore log index.
 *
 * A file is identified by device + inode (so size-rotated files keep their progress when renamed);
 * the hash of its first `head_length` bytes guards against inode reuse.
 *
 * @property int $id
 * @property string $path
 * @property int $device
 * @property int $inode
 * @property string $head_hash
 * @property int $head_length
 * @property bool $structured
 * @property int $indexed_offset
 * @property int $indexed_line
 * @property int $indexed_size
 * @property CarbonImmutable|null $indexed_at
 */
class LogIndexFile extends Model
{
    /** @use HasFactory<LogIndexFileFactory> */
    use HasFactory;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_log_index_file_reset_progress_spec(
    int64_t *out_scalars,
    uint8_t *out_buf,
    size_t out_cap
);
CDEF;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    protected $guarded = ['id'];

    /**
     * @var array<string, int|bool>
     */
    protected $attributes = [
        'structured' => true,
        'indexed_offset' => 0,
        'indexed_line' => 0,
        'indexed_size' => 0,
    ];

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi = null): void
    {
        self::$ffi = $ffi;
    }

    protected function casts(): array
    {
        return [
            'device' => 'integer',
            'inode' => 'integer',
            'head_length' => 'integer',
            'structured' => 'boolean',
            'indexed_offset' => 'integer',
            'indexed_line' => 'integer',
            'indexed_size' => 'integer',
            'indexed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Forget all indexing progress so the file is indexed again from the start.
     */
    public function resetProgress(): void
    {
        $this->forceFill(self::buildResetProgressAttributes())->save();
    }

    /**
     * @return array<string, int|null>
     */
    public static function buildResetProgressAttributes(): array
    {
        if (self::$useRustFfi) {
            return self::buildResetProgressAttributesNative();
        }

        return self::buildResetProgressAttributesPhp();
    }

    /**
     * @return array<string, int|null>
     */
    public static function buildResetProgressAttributesPhp(): array
    {
        return [
            'indexed_offset' => 0,
            'indexed_line' => 0,
            'indexed_size' => 0,
            'indexed_at' => null,
        ];
    }

    /**
     * @return array<string, int|null>
     */
    public static function buildResetProgressAttributesNative(): array
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof FFI) {
            return self::buildResetProgressAttributesPhp();
        }

        try {
            $cap = 96;
            $outScalars = $ffi->new('int64_t[4]');
            $outBuf = $ffi->new("uint8_t[{$cap}]");

            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_log_index_file_reset_progress_spec($outScalars, $outBuf, $cap);
            $s0 = $outScalars[0];
            $s1 = $outScalars[1];
            $s2 = $outScalars[2];
            $s3 = $outScalars[3];
            if (
                ! is_int($written)
                || $written <= 0
                || ! is_int($s0)
                || ! is_int($s1)
                || ! is_int($s2)
                || ! is_int($s3)
            ) {
                return self::buildResetProgressAttributesPhp();
            }

            $keys = explode("\0", FFI::string($outBuf, $written));
            if (count($keys) < 4) {
                return self::buildResetProgressAttributesPhp();
            }

            return [
                $keys[0] => $s0,
                $keys[1] => $s1,
                $keys[2] => $s2,
                $keys[3] => $s3 === 0 ? null : $s3,
            ];
        } catch (Throwable) {
            return self::buildResetProgressAttributesPhp();
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
