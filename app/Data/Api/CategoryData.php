<?php

declare(strict_types=1);

namespace App\Data\Api;

use App\Models\RootCategory;
use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * API v2 representation of a {@see RootCategory} with its sub-categories.
 *
 * Replaces the legacy `App\Transformers\CategoryTransformer`.
 */
#[TypeScript]
final class CategoryData extends Data
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_category_data_normalize_payload(
        int64_t id,
        const char *title_ptr,
        size_t title_len,
        int64_t *out_id,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustCoverCartFailedControllersParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

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

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    /**
     * @param  array<int|string, string>  $subcategories  Map of sub-category ID → title.
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $subcategories,
    ) {}

    public static function fromCategory(RootCategory $category): self
    {
        /** @var array<int|string, string> $subcategories */
        $subcategories = $category->categories()->pluck('title', 'id')->all();
        $normalized = self::normalizeCategoryPayload($category->id, $category->title, $subcategories);

        return new self(
            id: $normalized['id'],
            name: $normalized['name'],
            subcategories: $normalized['subcategories'],
        );
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayload(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        if (self::$useRustFfi) {
            return self::normalizeCategoryPayloadNative($id, $title, $subcategories);
        }

        return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayloadPhp(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        $normalizedSubcategories = [];
        foreach ($subcategories as $subId => $subTitle) {
            $normalizedSubcategories[$subId] = is_scalar($subTitle) || $subTitle === null ? (string) $subTitle : '';
        }

        return [
            'id' => (int) $id,
            'name' => (string) $title,
            'subcategories' => $normalizedSubcategories,
        ];
    }

    /**
     * @param  array<int|string, mixed>  $subcategories
     * @return array{id: int, name: string, subcategories: array<int|string, string>}
     */
    public static function normalizeCategoryPayloadNative(
        int|string|null $id,
        ?string $title,
        array $subcategories,
    ): array {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
        }

        try {
            $titleStr = (string) $title;
            $outId = $ffi->new('int64_t[1]');
            $cap = 0;
            $buf = RustPipelineLibrary::obtainScratch($ffi, max(32, strlen($titleStr) + 8), $cap);
            if ($buf === null) {
                return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
            }

            $rc = (int) self::callFfi(
                $ffi,
                'nntmux_category_data_normalize_payload',
                (int) $id,
                $titleStr,
                strlen($titleStr),
                $outId,
                $buf,
                $cap
            );
            if ($rc < -1) {
                $buf = RustPipelineLibrary::obtainScratch($ffi, -$rc, $cap);
                if ($buf === null) {
                    return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
                }
                $rc = (int) self::callFfi(
                    $ffi,
                    'nntmux_category_data_normalize_payload',
                    (int) $id,
                    $titleStr,
                    strlen($titleStr),
                    $outId,
                    $buf,
                    $cap
                );
            }
            if ($rc < 0) {
                return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
            }

            $normalizedSubcategories = [];
            foreach ($subcategories as $subId => $subTitle) {
                $normalizedSubcategories[$subId] = is_scalar($subTitle) || $subTitle === null ? (string) $subTitle : '';
            }

            return [
                'id' => (int) $outId[0],
                'name' => $rc === 0 ? '' : FFI::string($buf, $rc),
                'subcategories' => $normalizedSubcategories,
            ];
        } catch (Throwable) {
            return self::normalizeCategoryPayloadPhp($id, $title, $subcategories);
        }
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
