<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * App\Models\RootCategory.
 *
 * @property int $id
 * @property string $title
 * @property int $status
 * @property int $disablepreview
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection|Category[] $categories
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory query()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereDisablepreview($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\RootCategory whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class RootCategory extends Model
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_root_category_normalize_attributes(
            int64_t id,
            int64_t status,
            int64_t disable_preview,
            const char *title_ptr,
            size_t title_len,
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

    protected $guarded = [];

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class, 'root_categories_id');
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
     * Normalizes a `root_categories` row: clamps the id, collapses and truncates the title,
     * maps unknown statuses to inactive and builds a lowercase title key.
     *
     * @return array{id: int, title: string, status: int, disablepreview: int, is_active: bool, title_key: string}
     */
    public static function normalizeRootCategoryAttributes(int $id, string $title, int $status, int $disablePreview): array
    {
        return self::usesRustFfi()
            ? self::normalizeRootCategoryAttributesNative($id, $title, $status, $disablePreview)
            : self::normalizeRootCategoryAttributesPhp($id, $title, $status, $disablePreview);
    }

    /**
     * @return array{id: int, title: string, status: int, disablepreview: int, is_active: bool, title_key: string}
     */
    public static function normalizeRootCategoryAttributesNative(int $id, string $title, int $status, int $disablePreview): array
    {
        $fields = self::callNative('nntmux_root_category_normalize_attributes', [$id, $status, $disablePreview, $title, strlen($title)]);
        if ($fields === null || count($fields) !== 6 || in_array(null, $fields, true)) {
            return self::normalizeRootCategoryAttributesPhp($id, $title, $status, $disablePreview);
        }

        return [
            'id' => (int) $fields[0],
            'title' => (string) $fields[1],
            'status' => (int) $fields[2],
            'disablepreview' => (int) $fields[3],
            'is_active' => $fields[4] === '1',
            'title_key' => (string) $fields[5],
        ];
    }

    /**
     * @return array{id: int, title: string, status: int, disablepreview: int, is_active: bool, title_key: string}
     */
    public static function normalizeRootCategoryAttributesPhp(int $id, string $title, int $status, int $disablePreview): array
    {
        $collapsed = preg_replace('/[ \t\n\r\x0B\x0C]+/', ' ', $title) ?? $title;
        $title = mb_substr(trim($collapsed, self::WHITESPACE), 0, 255, 'UTF-8');
        $status = in_array($status, [Category::STATUS_INACTIVE, Category::STATUS_ACTIVE, Category::STATUS_DISABLED], true)
            ? $status
            : Category::STATUS_INACTIVE;

        return [
            'id' => max(0, $id),
            'title' => $title,
            'status' => $status,
            'disablepreview' => $disablePreview !== 0 ? 1 : 0,
            'is_active' => $status === Category::STATUS_ACTIVE,
            'title_key' => strtolower($title),
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
