<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\Binary.
 *
 * @property int $id
 * @property string $name
 * @property int $collections_id
 * @property int $filenumber
 * @property int $totalparts
 * @property int $currentparts
 * @property bool $partcheck
 * @property int $partsize
 * @property mixed $binaryhash
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereBinaryhash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereCollectionsId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereCurrentparts($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereFilenumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary wherePartcheck($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary wherePartsize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary whereTotalparts($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Binary query()
 */
class Binary extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w33_binary_normalize_part_progress(
            int64_t total_parts,
            int64_t current_parts,
            int64_t part_size,
            uint32_t part_check,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustBinaryPartCollectionModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var bool
     */
    public $timestamps = false;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Clamps a binary's part counters and derives its completion progress.
     *
     * @return array{totalparts: int, currentparts: int, partsize: int, partcheck: int, missing_parts: int, progress_basis_points: int, complete: bool, average_part_size: int}
     */
    public static function normalizePartProgress(int $totalParts, int $currentParts, int $partSize, bool $partCheck): array
    {
        return self::usesRustFfi()
            ? self::normalizePartProgressNative($totalParts, $currentParts, $partSize, $partCheck)
            : self::normalizePartProgressPhp($totalParts, $currentParts, $partSize, $partCheck);
    }

    /**
     * @return array{totalparts: int, currentparts: int, partsize: int, partcheck: int, missing_parts: int, progress_basis_points: int, complete: bool, average_part_size: int}
     */
    public static function normalizePartProgressNative(int $totalParts, int $currentParts, int $partSize, bool $partCheck): array
    {
        $fields = self::callNative('nntmux_w33_binary_normalize_part_progress', [$totalParts, $currentParts, $partSize, $partCheck ? 1 : 0]);
        if ($fields === null || count($fields) !== 8) {
            return self::normalizePartProgressPhp($totalParts, $currentParts, $partSize, $partCheck);
        }

        return [
            'totalparts' => (int) $fields[0],
            'currentparts' => (int) $fields[1],
            'partsize' => (int) $fields[2],
            'partcheck' => (int) $fields[3],
            'missing_parts' => (int) $fields[4],
            'progress_basis_points' => (int) $fields[5],
            'complete' => $fields[6] === '1',
            'average_part_size' => (int) $fields[7],
        ];
    }

    /**
     * @return array{totalparts: int, currentparts: int, partsize: int, partcheck: int, missing_parts: int, progress_basis_points: int, complete: bool, average_part_size: int}
     */
    public static function normalizePartProgressPhp(int $totalParts, int $currentParts, int $partSize, bool $partCheck): array
    {
        $totalParts = max(0, min($totalParts, 4294967295));
        $currentParts = max(0, min($currentParts, $totalParts));
        $partSize = max(0, $partSize);
        $complete = $totalParts > 0 && $currentParts >= $totalParts;

        return [
            'totalparts' => $totalParts,
            'currentparts' => $currentParts,
            'partsize' => $partSize,
            'partcheck' => $partCheck || $complete ? 1 : 0,
            'missing_parts' => $totalParts - $currentParts,
            'progress_basis_points' => $totalParts > 0 ? intdiv($currentParts * 10000, $totalParts) : 0,
            'complete' => $complete,
            'average_part_size' => $currentParts > 0 ? intdiv($partSize, $currentParts) : 0,
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
            self::$ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
