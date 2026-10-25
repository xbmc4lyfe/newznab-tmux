<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * App\Models\CategoryRegex.
 *
 * @property int $id
 * @property string $group_regex This is a regex to match against usenet groups
 * @property string $regex Regex used to match a release name to categorize it
 * @property bool $status 1=ON 0=OFF
 * @property string $description Optional extra details on this regex
 * @property int $ordinal Order to run the regex in
 * @property int $categories_id Which categories id to put the release in
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereCategoriesId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereGroupRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereOrdinal($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereRegex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex whereStatus($value)
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\CategoryRegex query()
 */
class CategoryRegex extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w34_category_regex_normalize_rule(
            int64_t status,
            int64_t ordinal,
            int64_t categories_id,
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
     * Normalizes a category regex row and reports whether it can route releases to a category.
     *
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, categories_id: int, is_usable: bool}
     */
    public static function normalizeCategoryRegexRule(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal, int $categoriesId): array
    {
        return self::usesRustFfi()
            ? self::normalizeCategoryRegexRuleNative($groupRegex, $regex, $status, $description, $ordinal, $categoriesId)
            : self::normalizeCategoryRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal, $categoriesId);
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, categories_id: int, is_usable: bool}
     */
    public static function normalizeCategoryRegexRuleNative(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal, int $categoriesId): array
    {
        $input = $groupRegex.self::FIELD_SEPARATOR.$regex.self::FIELD_SEPARATOR.($description ?? '');
        $fields = self::callNative('nntmux_w34_category_regex_normalize_rule', [$status, $ordinal, $categoriesId, $description === null ? 1 : 0, $input, strlen($input)]);
        if ($fields === null || count($fields) !== 11) {
            return self::normalizeCategoryRegexRulePhp($groupRegex, $regex, $status, $description, $ordinal, $categoriesId);
        }

        return [
            ...CollectionRegex::decodeRegexRuleBase($fields),
            'categories_id' => (int) $fields[9],
            'is_usable' => $fields[10] === '1',
        ];
    }

    /**
     * @return array{group_regex: string, regex: string, status: int, description: string, ordinal: int, is_active: bool, delimiter_valid: bool, modifiers: string, sort_key: string, categories_id: int, is_usable: bool}
     */
    public static function normalizeCategoryRegexRulePhp(string $groupRegex, string $regex, int $status, ?string $description, int $ordinal, int $categoriesId): array
    {
        $rule = CollectionRegex::normalizeRegexRuleBasePhp($groupRegex, $regex, $status, $description, $ordinal);
        $categoriesId = max(0, min($categoriesId, 2147483647));

        return [
            ...$rule,
            'categories_id' => $categoriesId,
            'is_usable' => $rule['is_active'] && $rule['delimiter_valid'] && $categoriesId > 0,
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
