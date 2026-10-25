<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\CollectionRegex.
 *
 * @property int $id
 * @property string $group_regex This is a regex to match against usenet groups
 * @property string $regex Regex used for collection grouping
 * @property bool $status 1=ON 0=OFF
 * @property string $description Optional extra details on this regex
 * @property int $ordinal Order to run the regex in
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereGroupRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereOrdinal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex whereStatus($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CollectionRegex query()
 */
class CollectionRegex extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_collection_regex_normalize_rule(
            int64_t status,
            int64_t ordinal,
            uint32_t description_is_null,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Trailing PCRE modifiers accepted by {@see regexDelimiterPhp()}. */
    public const string PCRE_MODIFIERS = 'imsxuADSUXJn';

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustRegexIngestionConfigModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string TRIM_CHARACTERS = " \t\n\r\0\x0B\x0C";

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
     * Normalizes a collection regex row and reports whether it can group collections.
     *
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, usable_named_groups: int, is_usable: bool}
     */
    public static function normalizeCollectionRegexRule(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        return self::usesRustFfi()
            ? self::normalizeCollectionRegexRuleNative($groupRegex, $regex, $status, $description, $ordinal)
            : self::normalizeCollectionRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal);
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, usable_named_groups: int, is_usable: bool}
     */
    public static function normalizeCollectionRegexRuleNative(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        $input = $groupRegex.self::FIELD_SEPARATOR.$regex.self::FIELD_SEPARATOR.($description ?? '');
        $fields = self::callNative('nntmux_w34_collection_regex_normalize_rule', [$status, $ordinal, $description === null ? 1 : 0, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 12) {
            return self::normalizeCollectionRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal);
        }

        return [
            ...self::decodeRegexRuleBase($fields),
            'named_groups' => $fields[9] === '' ? [] : explode(',', $fields[9]),
            'usable_named_groups' => (int) $fields[10],
            'is_usable' => $fields[11] === '1',
        ];
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, usable_named_groups: int, is_usable: bool}
     */
    public static function normalizeCollectionRegexRulePhp(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        $rule = self::normalizeRegexRuleBasePhp($groupRegex, $regex, $status, $description, $ordinal);
        $names = self::namedCaptureGroupsPhp($rule['regex']);
        $usable = count(array_filter($names, self::isUsableCaptureName(...)));

        return [
            ...$rule,
            'named_groups' => $names,
            'usable_named_groups' => $usable,
            'is_usable' => $rule['is_active'] && $rule['delimiter_valid'] && $usable > 0,
        ];
    }

    /**
     * Shared regex-table row normalization used by the collection, naming and category regex models.
     *
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string}
     */
    public static function normalizeRegexRuleBasePhp(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        $groupRegex = mb_substr(trim($groupRegex, self::TRIM_CHARACTERS), 0, 255, 'UTF-8');
        $regex = trim($regex, self::TRIM_CHARACTERS);
        $status = $status === 1 ? 1 : 0;
        $description = mb_substr(trim((string) preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $description ?? ''), self::TRIM_CHARACTERS), 0, 1000, 'UTF-8');
        $ordinal = max(0, min($ordinal, 2147483647));
        [$delimiterValid, $modifiers] = self::regexDelimiterPhp($regex);

        return [
            'group_regex' => $groupRegex,
            'regex' => $regex,
            'status' => $status,
            'description' => $description,
            'ordinal' => $ordinal,
            'is_active' => $status === 1 && $regex !== '' && $groupRegex !== '',
            'delimiter_valid' => $delimiterValid,
            'modifiers' => $modifiers,
            'sort_key' => sprintf('%010d|%s', $ordinal, strtolower($groupRegex)),
        ];
    }

    /**
     * Whether `$regex` is a delimited PCRE pattern with only supported trailing modifiers.
     *
     * @return array{bool, string}
     */
    public static function regexDelimiterPhp(string $regex): array
    {
        if (strlen($regex) < 2) {
            return [false, ''];
        }
        $open = $regex[0];
        $byte = ord($open);
        if ($byte < 0x21 || $byte > 0x7E || $open === '\\' || self::isAlphanumericByte($byte)) {
            return [false, ''];
        }
        $close = match ($open) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $open,
        };
        $position = strrpos($regex, $close);
        if ($position === false || $position < 1) {
            return [false, ''];
        }
        $modifiers = substr($regex, $position + 1);

        return strspn($modifiers, self::PCRE_MODIFIERS) === strlen($modifiers) ? [true, $modifiers] : [false, ''];
    }

    /**
     * Named capture group names (`(?P<name>`, `(?<name>`, `(?'name'`) in pattern order.
     *
     * @return list<string>
     */
    public static function namedCaptureGroupsPhp(string $regex): array
    {
        $names = [];
        $length = strlen($regex);
        $i = 0;
        while ($i < $length) {
            $char = $regex[$i];
            if ($char === '\\') {
                $i += 2;

                continue;
            }
            if ($char !== '(' || ($regex[$i + 1] ?? '') !== '?') {
                $i++;

                continue;
            }
            $j = $i + 2;
            $open = $regex[$j] ?? '';
            if ($open === 'P') {
                $j++;
                $open = $regex[$j] ?? '';
                if ($open !== '<') {
                    $i++;

                    continue;
                }
            }
            $close = match ($open) {
                '<' => '>',
                "'" => "'",
                default => null,
            };
            if ($close === null) {
                $i++;

                continue;
            }
            $start = ++$j;
            while ($j < $length && self::isWordByte(ord($regex[$j]))) {
                $j++;
            }
            if ($j > $start && $j < $length && $regex[$j] === $close && ! self::isDigitByte(ord($regex[$start]))) {
                $names[] = substr($regex, $start, $j - $start);
                $i = $j + 1;
            } else {
                $i++;
            }
        }

        return $names;
    }

    /**
     * Named groups whose values `RegexService::_matchRegex()` concatenates.
     */
    public static function isUsableCaptureName(string $name): bool
    {
        return stripos($name, 'reqid') === false && stripos($name, 'parts') === false;
    }

    /**
     * @param  list<string>  $fields
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string}
     */
    public static function decodeRegexRuleBase(array $fields): array
    {
        return [
            'group_regex' => $fields[0],
            'regex' => $fields[1],
            'status' => (int) $fields[2],
            'description' => $fields[3],
            'ordinal' => (int) $fields[4],
            'is_active' => $fields[5] === '1',
            'delimiter_valid' => $fields[6] === '1',
            'modifiers' => $fields[7],
            'sort_key' => $fields[8],
        ];
    }

    private static function isAlphanumericByte(int $byte): bool
    {
        return self::isDigitByte($byte) || ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A);
    }

    private static function isDigitByte(int $byte): bool
    {
        return $byte >= 0x30 && $byte <= 0x39;
    }

    private static function isWordByte(int $byte): bool
    {
        return $byte === 0x5F || self::isAlphanumericByte($byte);
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
