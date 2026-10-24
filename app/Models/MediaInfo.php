<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Mhor\MediaInfo\Container\MediaInfoContainer;
use Throwable;

class MediaInfo extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_mi_unique_id_placeholder(int32_t tag, int64_t int_value, const char *ptr, size_t len);
        C;

    protected $guarded = [];

    // PHP remains the production default until benchmarks qualify Rust.
    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function addData(mixed $id, MediaInfoContainer $xmlArray): void
    {
        $mediainfoArray = $xmlArray->getGeneral();
        if (! $mediainfoArray) {
            return;
        }

        // Check if we have the same release in the database
        if (self::where('releases_id', $id)->exists()) {
            return;
        }

        $mediaUniqueId = self::normalizeUniqueId($mediainfoArray->get('unique_id'));

        self::insertOrIgnore([
            'releases_id' => $id,
            'movie_name' => $mediainfoArray->get('movie_name') ?? null,
            'file_name' => $mediainfoArray->get('file_name') ?? null,
            'unique_id' => $mediaUniqueId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    /**
     * Null for a missing or placeholder `unique_id` (`0` or `0x0` is not unique), otherwise
     * the value unchanged.
     */
    public static function normalizeUniqueId(mixed $uniqueId): mixed
    {
        return self::usesRustFfi() ? self::normalizeUniqueIdNative($uniqueId) : self::normalizeUniqueIdPhp($uniqueId);
    }

    public static function normalizeUniqueIdPhp(mixed $uniqueId): mixed
    {
        if ($uniqueId === null || $uniqueId === '0' || $uniqueId === '0x0' || $uniqueId === 0) {
            return null;
        }

        return $uniqueId;
    }

    public static function normalizeUniqueIdNative(mixed $uniqueId): mixed
    {
        if (($ffi = self::ffi()) === null) {
            return self::normalizeUniqueIdPhp($uniqueId);
        }
        [$tag, $int, $string] = match (true) {
            $uniqueId === null => [0, 0, null],
            \is_int($uniqueId) => [1, $uniqueId, null],
            \is_string($uniqueId) => [2, 0, $uniqueId],
            default => [3, 0, null],
        };
        try {
            // @phpstan-ignore method.notFound
            $placeholder = $ffi->nntmux_mi_unique_id_placeholder($tag, $int, $string, $string === null ? 0 : \strlen($string));
        } catch (Throwable) {
            return self::normalizeUniqueIdPhp($uniqueId);
        }

        return match ($placeholder) {
            1 => null,
            0 => $uniqueId,
            default => self::normalizeUniqueIdPhp($uniqueId),
        };
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
