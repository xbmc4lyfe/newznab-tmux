<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\UsenetGroup;
use App\Support\ReleaseSearchIndexSync;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Throwable;

final class UsenetGroupObserver
{
    /** Usenet group attributes copied into release search documents. */
    public const array REINDEX_FIELDS = ['name'];

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_search_sync_reindex_required(uint32_t kind, const char *keys, size_t keys_len, const size_t *lens, size_t count);
        C;

    private const int FFI_KIND = 2;

    /**
     * The Rust helper stays opt-in: it did not beat PHP by 1.5x in the paired benchmark
     * (tests/Unit/RustSearchSyncObserversHistoryParityTest.php).
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Whether the dirty attributes (`Model::getDirty()`) touch a reindexed field,
     * matching `Model::isDirty(REINDEX_FIELDS)`.
     *
     * @param  array<array-key, mixed>  $dirty
     */
    public static function requiresReindex(array $dirty): bool
    {
        return self::usesRustFfi()
            ? self::requiresReindexNative($dirty)
            : self::requiresReindexPhp($dirty);
    }

    /**
     * @param  array<array-key, mixed>  $dirty
     */
    public static function requiresReindexPhp(array $dirty): bool
    {
        foreach (self::REINDEX_FIELDS as $field) {
            if (array_key_exists($field, $dirty)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $dirty
     */
    public static function requiresReindexNative(array $dirty): bool
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::requiresReindexPhp($dirty);
        }

        try {
            $count = \count($dirty);
            $lens = $ffi->new('size_t['.max(1, $count).']');
            $packed = '';
            $i = 0;
            foreach ($dirty as $key => $_) {
                $key = (string) $key;
                $packed .= $key;
                $lens[$i++] = \strlen($key);
            }
            // @phpstan-ignore method.notFound
            $result = $ffi->nntmux_search_sync_reindex_required(self::FFI_KIND, $packed, \strlen($packed), $lens, $count);
        } catch (Throwable) {
            return self::requiresReindexPhp($dirty);
        }

        return $result === 0 || $result === 1
            ? $result === 1
            : self::requiresReindexPhp($dirty);
    }

    public function updated(UsenetGroup $group): void
    {
        if (! self::requiresReindex($group->getDirty())) {
            return;
        }

        DB::afterCommit(fn (): bool => $this->reindex($group));
    }

    private function reindex(UsenetGroup $group): bool
    {
        ReleaseSearchIndexSync::forQueryGroup((int) $group->id);

        return true;
    }
}
