<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlacklistConstants;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\BinaryBlacklist.
 *
 * @property int $id
 * @property string|null $groupname
 * @property string $regex
 * @property int $msgcol
 * @property int $optype
 * @property int $status
 * @property string|null $description
 * @property string|null $last_activity
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereGroupname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereLastActivity($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereMsgcol($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereOptype($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist whereStatus($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\BinaryBlacklist query()
 */
class BinaryBlacklist extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w33_binary_blacklist_normalize_rule(
            uint32_t group_is_null,
            int64_t msg_col,
            int64_t op_type,
            int64_t status,
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

    private const string NULL_MARKER = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var string
     */
    protected $table = 'binaryblacklist';

    /**
     * @var bool
     */
    public $timestamps = false;

    protected $dateFormat = false;

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
     * Normalizes a `binaryblacklist` rule and builds its group/op/column/regex composite key.
     *
     * @return array{groupname: ?string, regex: string, msgcol: int, optype: int, status: int, is_global: bool, field: string, composite_key: string}
     */
    public static function normalizeBlacklistRuleAttributes(?string $groupName, string $regex, int $msgCol, int $opType, int $status): array
    {
        return self::usesRustFfi()
            ? self::normalizeBlacklistRuleAttributesNative($groupName, $regex, $msgCol, $opType, $status)
            : self::normalizeBlacklistRuleAttributesPhp($groupName, $regex, $msgCol, $opType, $status);
    }

    /**
     * @return array{groupname: ?string, regex: string, msgcol: int, optype: int, status: int, is_global: bool, field: string, composite_key: string}
     */
    public static function normalizeBlacklistRuleAttributesNative(?string $groupName, string $regex, int $msgCol, int $opType, int $status): array
    {
        $input = ($groupName ?? '').self::FIELD_SEPARATOR.$regex;
        $fields = self::callNative('nntmux_w33_binary_blacklist_normalize_rule', [$groupName === null ? 1 : 0, $msgCol, $opType, $status, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 8) {
            return self::normalizeBlacklistRuleAttributesPhp($groupName, $regex, $msgCol, $opType, $status);
        }

        return [
            'groupname' => $fields[0] === self::NULL_MARKER ? null : $fields[0],
            'regex' => $fields[1],
            'msgcol' => (int) $fields[2],
            'optype' => (int) $fields[3],
            'status' => (int) $fields[4],
            'is_global' => $fields[5] === '1',
            'field' => $fields[6],
            'composite_key' => $fields[7],
        ];
    }

    /**
     * @return array{groupname: ?string, regex: string, msgcol: int, optype: int, status: int, is_global: bool, field: string, composite_key: string}
     */
    public static function normalizeBlacklistRuleAttributesPhp(?string $groupName, string $regex, int $msgCol, int $opType, int $status): array
    {
        $group = $groupName === null ? '' : trim($groupName, self::WHITESPACE);
        $group = $group === '' ? null : strtolower(mb_substr($group, 0, 255, 'UTF-8'));
        $regex = trim($regex, self::WHITESPACE);
        $msgCol = $msgCol >= BlacklistConstants::BLACKLIST_FIELD_SUBJECT && $msgCol <= BlacklistConstants::BLACKLIST_FIELD_MESSAGEID
            ? $msgCol
            : BlacklistConstants::BLACKLIST_FIELD_SUBJECT;
        $opType = $opType === BlacklistConstants::OPTYPE_WHITELIST
            ? BlacklistConstants::OPTYPE_WHITELIST
            : BlacklistConstants::OPTYPE_BLACKLIST;
        $status = $status === BlacklistConstants::BLACKLIST_ENABLED ? 1 : 0;

        return [
            'groupname' => $group,
            'regex' => $regex,
            'msgcol' => $msgCol,
            'optype' => $opType,
            'status' => $status,
            'is_global' => $group === null,
            'field' => match ($msgCol) {
                BlacklistConstants::BLACKLIST_FIELD_FROM => 'from',
                BlacklistConstants::BLACKLIST_FIELD_MESSAGEID => 'messageid',
                default => 'subject',
            },
            'composite_key' => ($group ?? '*').'|'.$opType.'|'.$msgCol.'|'.$regex,
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
