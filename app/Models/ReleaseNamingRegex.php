<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\ReleaseNamingRegex.
 *
 * @property int $id
 * @property string $group_regex This is a regex to match against usenet groups
 * @property string $regex Regex used for extracting name from subject
 * @property bool $status 1=ON 0=OFF
 * @property string $description Optional extra details on this regex
 * @property int $ordinal Order to run the regex in
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereGroupRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereOrdinal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex whereStatus($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseNamingRegex query()
 */
class ReleaseNamingRegex extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_release_naming_regex_normalize_rule(
            int64_t status,
            int64_t ordinal,
            uint32_t description_is_null,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustRegexIngestionConfigModelsParityTest.php is below 1.5x.
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
     * Normalizes a release naming regex row and derives the order in which
     * `RegexService::_matchRegex()` concatenates its named captures.
     *
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, concat_order: list<string>, is_usable: bool}
     */
    public static function normalizeReleaseNamingRegexRule(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        return self::usesRustFfi()
            ? self::normalizeReleaseNamingRegexRuleNative($groupRegex, $regex, $status, $description, $ordinal)
            : self::normalizeReleaseNamingRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal);
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, concat_order: list<string>, is_usable: bool}
     */
    public static function normalizeReleaseNamingRegexRuleNative(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        $input = $groupRegex.self::FIELD_SEPARATOR.$regex.self::FIELD_SEPARATOR.($description ?? '');
        $fields = self::callNative('nntmux_w34_release_naming_regex_normalize_rule', [$status, $ordinal, $description === null ? 1 : 0, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 12) {
            return self::normalizeReleaseNamingRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal);
        }

        return [
            ...CollectionRegex::decodeRegexRuleBase($fields),
            'named_groups' => $fields[9] === '' ? [] : explode(',', $fields[9]),
            'concat_order' => $fields[10] === '' ? [] : explode(',', $fields[10]),
            'is_usable' => $fields[11] === '1',
        ];
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, named_groups: list<string>, concat_order: list<string>, is_usable: bool}
     */
    public static function normalizeReleaseNamingRegexRulePhp(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal): array
    {
        $rule = CollectionRegex::normalizeRegexRuleBasePhp($groupRegex, $regex, $status, $description, $ordinal);
        $names = CollectionRegex::namedCaptureGroupsPhp($rule['regex']);
        $order = array_values(array_unique(array_filter($names, CollectionRegex::isUsableCaptureName(...))));
        sort($order, SORT_STRING);

        return [
            ...$rule,
            'named_groups' => $names,
            'concat_order' => $order,
            'is_usable' => $rule['is_active'] && $rule['delimiter_valid'] && $order !== [],
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
