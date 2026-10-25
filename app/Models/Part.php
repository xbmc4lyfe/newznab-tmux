<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCompositePrimaryKey;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\Part.
 *
 * @property int $binaries_id
 * @property string $messageid
 * @property int $number
 * @property int $partnumber
 * @property int $size
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part whereBinariesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part whereMessageid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part wherePartnumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part whereSize($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Part query()
 */
class Part extends Model
{
    use HasCompositePrimaryKey;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w33_part_normalize_segment(
            int64_t binaries_id,
            int64_t number,
            int64_t part_number,
            int64_t size,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustBinaryPartCollectionModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $primaryKey = 'binaries_id';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return non-empty-list<string>
     */
    public function compositeKeyColumns(): array
    {
        return ['binaries_id', 'partnumber'];
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
     * Normalizes a part row, stripping one `<...>` pair from the message-ID, and builds its
     * `binaries_id:partnumber` composite key (matching {@see compositeKeyColumns()}).
     *
     * @return array{binaries_id: int, messageid: string, number: int, partnumber: int, size: int, valid: bool, composite_key: string}
     */
    public static function normalizePartSegment(int $binariesId, string $messageId, int $number, int $partNumber, int $size): array
    {
        return self::usesRustFfi()
            ? self::normalizePartSegmentNative($binariesId, $messageId, $number, $partNumber, $size)
            : self::normalizePartSegmentPhp($binariesId, $messageId, $number, $partNumber, $size);
    }

    /**
     * @return array{binaries_id: int, messageid: string, number: int, partnumber: int, size: int, valid: bool, composite_key: string}
     */
    public static function normalizePartSegmentNative(int $binariesId, string $messageId, int $number, int $partNumber, int $size): array
    {
        $fields = self::callNative('nntmux_w33_part_normalize_segment', [$binariesId, $number, $partNumber, $size, $messageId, strlen($messageId)]);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizePartSegmentPhp($binariesId, $messageId, $number, $partNumber, $size);
        }

        return [
            'binaries_id' => (int) $fields[0],
            'messageid' => $fields[1],
            'number' => (int) $fields[2],
            'partnumber' => (int) $fields[3],
            'size' => (int) $fields[4],
            'valid' => $fields[5] === '1',
            'composite_key' => $fields[6],
        ];
    }

    /**
     * @return array{binaries_id: int, messageid: string, number: int, partnumber: int, size: int, valid: bool, composite_key: string}
     */
    public static function normalizePartSegmentPhp(int $binariesId, string $messageId, int $number, int $partNumber, int $size): array
    {
        $messageId = trim($messageId, self::WHITESPACE);
        if (strlen($messageId) >= 2 && str_starts_with($messageId, '<') && str_ends_with($messageId, '>')) {
            $messageId = trim(substr($messageId, 1, -1), self::WHITESPACE);
        }
        $messageId = mb_substr($messageId, 0, 255, 'UTF-8');
        $binariesId = max(0, $binariesId);
        $partNumber = max(0, $partNumber);

        return [
            'binaries_id' => $binariesId,
            'messageid' => $messageId,
            'number' => max(0, $number),
            'partnumber' => $partNumber,
            'size' => max(0, $size),
            'valid' => $messageId !== '' && $partNumber > 0 && $binariesId > 0,
            'composite_key' => $binariesId.':'.$partNumber,
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
