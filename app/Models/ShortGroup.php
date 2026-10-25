<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ShortGroup.
 *
 * @property int $id
 * @property string $name
 * @property int $first_record
 * @property int $last_record
 * @property string|null $updated
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup whereFirstRecord($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup whereLastRecord($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup whereUpdated($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ShortGroup query()
 */
class ShortGroup extends Model
{
    /**
     * @var bool
     */
    public $timestamps = false;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_short_group_normalize_watermark(
            int64_t first_record,
            int64_t last_record,
            const char *name_ptr,
            size_t name_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategoryGroupParhashModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string WHITESPACE = " \t\n\r\0\x0B\x0C";

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a `short_groups` row: trims and lowercases the group name, clamps negative
     * article numbers to zero and counts the articles between the watermarks.
     *
     * @return array{name: string, first_record: int, last_record: int, article_count: int, is_valid: bool}
     */
    public static function normalizeArticleWatermarkRange(string $groupName, int $firstRecord, int $lastRecord): array
    {
        return self::usesRustFfi()
            ? self::normalizeArticleWatermarkRangeNative($groupName, $firstRecord, $lastRecord)
            : self::normalizeArticleWatermarkRangePhp($groupName, $firstRecord, $lastRecord);
    }

    /**
     * @return array{name: string, first_record: int, last_record: int, article_count: int, is_valid: bool}
     */
    public static function normalizeArticleWatermarkRangeNative(string $groupName, int $firstRecord, int $lastRecord): array
    {
        $fields = self::callNative('nntmux_short_group_normalize_watermark', [$firstRecord, $lastRecord, $groupName, strlen($groupName)]);
        if ($fields === null || count($fields) !== 5 || in_array(null, $fields, true)) {
            return self::normalizeArticleWatermarkRangePhp($groupName, $firstRecord, $lastRecord);
        }

        return [
            'name' => (string) $fields[0],
            'first_record' => (int) $fields[1],
            'last_record' => (int) $fields[2],
            'article_count' => (int) $fields[3],
            'is_valid' => $fields[4] === '1',
        ];
    }

    /**
     * @return array{name: string, first_record: int, last_record: int, article_count: int, is_valid: bool}
     */
    public static function normalizeArticleWatermarkRangePhp(string $groupName, int $firstRecord, int $lastRecord): array
    {
        $name = strtolower(mb_substr(trim($groupName, self::WHITESPACE), 0, 255, 'UTF-8'));
        $first = max(0, $firstRecord);
        $last = max(0, $lastRecord);
        $count = $first > 0 && $last >= $first ? $last - $first + 1 : 0;

        return [
            'name' => $name,
            'first_record' => $first,
            'last_record' => $last,
            'article_count' => $count,
            'is_valid' => $name !== '' && $count > 0,
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
