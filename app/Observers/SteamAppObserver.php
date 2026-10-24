<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SecondarySearchIndex;
use App\Facades\Search;
use App\Models\SteamApp;
use App\Support\RustPipelineLibrary;
use App\Support\SecondaryIndexDocuments;
use Illuminate\Support\Facades\Log;

class SteamAppObserver
{
    private const FFI_CDEF = <<<'CDEF'
int32_t nntmux_steam_app_observer_resolve_id(
    uint8_t kind,
    int64_t int_val,
    const char *str_ptr,
    size_t str_len,
    int64_t *out_id
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

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

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(\FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function created(SteamApp $steamApp): void
    {
        $this->sync($steamApp);
    }

    public function updated(SteamApp $steamApp): void
    {
        $this->sync($steamApp);
    }

    public function deleted(SteamApp $steamApp): void
    {
        try {
            $id = self::resolveValidSteamAppId($steamApp->getAttribute('id'));
            if ($id > 0) {
                Search::deleteSecondary(SecondarySearchIndex::Steam, $id);
            }
        } catch (\Throwable $e) {
            Log::error('SteamAppObserver: delete from search index failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function resolveValidSteamAppId(mixed $rawId): int
    {
        if (self::$useRustFfi) {
            return self::resolveValidSteamAppIdNative($rawId);
        }

        return self::resolveValidSteamAppIdPhp($rawId);
    }

    public static function resolveValidSteamAppIdPhp(mixed $rawId): int
    {
        if (is_int($rawId)) {
            return $rawId > 0 ? $rawId : 0;
        }
        if (is_string($rawId) || is_float($rawId) || is_bool($rawId)) {
            $id = (int) $rawId;

            return $id > 0 ? $id : 0;
        }

        return 0;
    }

    public static function resolveValidSteamAppIdNative(mixed $rawId): int
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::resolveValidSteamAppIdPhp($rawId);
        }

        if ($rawId === null || $rawId === false) {
            $kind = 0;
            $intVal = 0;
            $strVal = '';
        } elseif (is_int($rawId)) {
            $kind = 1;
            $intVal = $rawId;
            $strVal = '';
        } elseif ($rawId === true) {
            $kind = 1;
            $intVal = 1;
            $strVal = '';
        } elseif (is_string($rawId)) {
            $kind = 2;
            $intVal = 0;
            $strVal = $rawId;
        } else {
            return self::resolveValidSteamAppIdPhp($rawId);
        }

        try {
            $outId = $ffi->new('int64_t[1]');
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_steam_app_observer_resolve_id(
                $kind,
                $intVal,
                $strVal,
                strlen($strVal),
                $outId
            );
            $resolvedId = $outId[0];
            if (($rc !== 0 && $rc !== 1) || ! is_int($resolvedId)) {
                return self::resolveValidSteamAppIdPhp($rawId);
            }

            return $rc === 1 && $resolvedId > 0 ? $resolvedId : 0;
        } catch (\Throwable) {
            return self::resolveValidSteamAppIdPhp($rawId);
        }
    }

    private function sync(SteamApp $steamApp): void
    {
        try {
            $id = self::resolveValidSteamAppId($steamApp->getAttribute('id'));
            if ($id <= 0) {
                return;
            }
            Search::insertSecondary(
                SecondarySearchIndex::Steam,
                $id,
                SecondaryIndexDocuments::steam($steamApp)
            );
        } catch (\Throwable $e) {
            Log::error('SteamAppObserver: sync to search index failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private static function additionalFfi(): ?\FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof \FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
