<?php

declare(strict_types=1);

namespace App\Models;

use App\Providers\SearchServiceProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ReleaseSearchData.
 *
 * @mixin \Eloquent
 *
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSearchData newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSearchData newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ReleaseSearchData query()
 */
class ReleaseSearchData extends Model
{
    /**
     * Rust stays opt-in: the FFI round trip is slower than the PHP helper (< 1.5x); see
     * tests/Unit/RustSearchProviderFacadeFacetsParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Search document fields with trimmed text, a dotless `plainsearchname`, non-negative ids, and whether
     * the document can be indexed (a positive id and a search name).
     *
     * @return array{id: int, name: string, searchname: string, plainsearchname: string, fromname: string, categories_id: int, indexable: bool}
     */
    public static function normalizeSearchDocumentFields(int $releaseId, string $name, string $searchName, string $fromName, int $categoriesId): array
    {
        return self::usesRustFfi()
            ? self::normalizeSearchDocumentFieldsNative($releaseId, $name, $searchName, $fromName, $categoriesId)
            : self::normalizeSearchDocumentFieldsPhp($releaseId, $name, $searchName, $fromName, $categoriesId);
    }

    /**
     * @return array{id: int, name: string, searchname: string, plainsearchname: string, fromname: string, categories_id: int, indexable: bool}
     */
    public static function normalizeSearchDocumentFieldsPhp(int $releaseId, string $name, string $searchName, string $fromName, int $categoriesId): array
    {
        $id = max(0, $releaseId);
        $searchName = trim($searchName);

        return [
            'id' => $id,
            'name' => trim($name),
            'searchname' => $searchName,
            'plainsearchname' => str_replace(['.', '-'], ' ', $searchName),
            'fromname' => trim($fromName),
            'categories_id' => max(0, $categoriesId),
            'indexable' => $id > 0 && $searchName !== '',
        ];
    }

    /**
     * @return array{id: int, name: string, searchname: string, plainsearchname: string, fromname: string, categories_id: int, indexable: bool}
     */
    public static function normalizeSearchDocumentFieldsNative(int $releaseId, string $name, string $searchName, string $fromName, int $categoriesId): array
    {
        $result = SearchServiceProvider::evaluateNative(3, [(string) $releaseId, $name, $searchName, $fromName, (string) $categoriesId]);
        if ($result !== null && \count($result) === 7 && \in_array($result[6], ['0', '1'], true)
            && preg_match('/^(0|[1-9][0-9]*)$/', $result[0]) === 1 && preg_match('/^(0|[1-9][0-9]*)$/', $result[5]) === 1) {
            return [
                'id' => (int) $result[0],
                'name' => $result[1],
                'searchname' => $result[2],
                'plainsearchname' => $result[3],
                'fromname' => $result[4],
                'categories_id' => (int) $result[5],
                'indexable' => $result[6] === '1',
            ];
        }

        return self::normalizeSearchDocumentFieldsPhp($releaseId, $name, $searchName, $fromName, $categoriesId);
    }
}
