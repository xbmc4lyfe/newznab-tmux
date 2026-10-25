<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;

/**
 * The Rust toggle and FFI cache use singleton-prefixed names because the using models already declare their own
 * RUST_FFI_DEFAULT, $useRustFfi, usesRustFfi() and ffi(); trait statics are per using class.
 */
trait IsSingletonConfiguration
{
    public const int SINGLETON_ID = 1;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php is below 1.5x.
     */
    public const bool SINGLETON_RUST_FFI_DEFAULT = false;

    private const array SINGLETON_STATUSES = ['ok', 'empty', 'missing', 'duplicated', 'extra_rows'];

    private static ?bool $singletonUseRustFfi = null;

    private static FFI|false|null $singletonFfi = null;

    public static function singleton(): static
    {
        /** @var static $configuration */
        $configuration = static::query()->findOrFail(self::SINGLETON_ID);

        return $configuration;
    }

    public static function useSingletonRustFfi(?bool $use): void
    {
        self::$singletonUseRustFfi = $use;
    }

    public static function usesSingletonRustFfi(): bool
    {
        return self::$singletonUseRustFfi ?? self::SINGLETON_RUST_FFI_DEFAULT;
    }

    public static function resetSingletonRustFfi(): void
    {
        self::$singletonUseRustFfi = null;
        self::$singletonFfi = null;
    }

    /**
     * Diagnoses a singleton configuration table from its primary keys: empty, missing the singleton row, holding it
     * more than once, holding extra rows beside it, or ok. Bounds are null for an empty table.
     *
     * @param  list<int>  $ids
     * @return array{row_count: int, has_singleton: bool, singleton_rows: int, extra_rows: int, min_id: ?int, max_id: ?int, status: string, needs_repair: bool}
     */
    public static function summarizeSingletonIds(array $ids): array
    {
        return (self::usesSingletonRustFfi() ? self::summarizeSingletonIdsNative($ids) : null) ?? self::summarizeSingletonIdsPhp($ids);
    }

    protected static function booted(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute($model->getKeyName(), self::SINGLETON_ID);
        });
    }

    /**
     * @param  list<int>  $ids
     * @return array{row_count: int, has_singleton: bool, singleton_rows: int, extra_rows: int, min_id: ?int, max_id: ?int, status: string, needs_repair: bool}|null
     */
    private static function summarizeSingletonIdsNative(array $ids): ?array
    {
        $s = NzbArticleFingerprintUnavailable::evaluateW372(self::singletonFfi(), 4, 0, pack('q*', ...$ids), 8);
        $rows = \count($ids);
        if ($s === null || $s[0] !== $rows || $s[1] < 0 || $s[1] > $rows || $s[2] !== $rows - $s[1] || $s[3] !== ($rows > 0 ? 1 : 0)
            || $s[4] > $s[5] || ! isset(self::SINGLETON_STATUSES[$s[6]]) || $s[7] !== ($s[6] === 0 ? 0 : 1)) {
            return null;
        }

        return [
            'row_count' => $rows,
            'has_singleton' => $s[1] > 0,
            'singleton_rows' => $s[1],
            'extra_rows' => $s[2],
            'min_id' => $rows > 0 ? $s[4] : null,
            'max_id' => $rows > 0 ? $s[5] : null,
            'status' => self::SINGLETON_STATUSES[$s[6]],
            'needs_repair' => $s[7] === 1,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{row_count: int, has_singleton: bool, singleton_rows: int, extra_rows: int, min_id: ?int, max_id: ?int, status: string, needs_repair: bool}
     */
    private static function summarizeSingletonIdsPhp(array $ids): array
    {
        $rows = \count($ids);
        $singleton = 0;
        foreach ($ids as $id) {
            if ($id === self::SINGLETON_ID) {
                $singleton++;
            }
        }
        $status = match (true) {
            $rows === 0 => 'empty',
            $singleton === 0 => 'missing',
            $singleton > 1 => 'duplicated',
            $rows > $singleton => 'extra_rows',
            default => 'ok',
        };

        return [
            'row_count' => $rows,
            'has_singleton' => $singleton > 0,
            'singleton_rows' => $singleton,
            'extra_rows' => $rows - $singleton,
            'min_id' => $ids === [] ? null : min($ids),
            'max_id' => $ids === [] ? null : max($ids),
            'status' => $status,
            'needs_repair' => $status !== 'ok',
        ];
    }

    private static function singletonFfi(): ?FFI
    {
        if (self::$singletonFfi === null) {
            self::$singletonFfi = RustPipelineLibrary::resolveDomain(NzbArticleFingerprintUnavailable::FFI_DOMAIN, NzbArticleFingerprintUnavailable::FFI_CDEF) ?? false;
        }

        return self::$singletonFfi === false ? null : self::$singletonFfi;
    }
}
