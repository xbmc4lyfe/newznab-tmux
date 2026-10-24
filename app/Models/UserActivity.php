<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model
{
    const UPDATED_AT = null;

    private const FFI_CDEF = <<<'CDEF'
ssize_t nntmux_user_activity_resolve_icon(
    const char *activity_type,
    size_t activity_type_len,
    uint8_t *out,
    size_t out_cap
);
ssize_t nntmux_user_activity_resolve_color_class(
    const char *activity_type,
    size_t activity_type_len,
    uint8_t *out,
    size_t out_cap
);
CDEF;

    /**
     * Production default stays PHP until paired benchmarks show >= 1.5x Rust speedup.
     */
    private static bool $useRustFfi = false;

    private static \FFI|false|null $ffi = null;

    protected $fillable = [
        'user_id',
        'username',
        'activity_type',
        'description',
        'metadata',
        'is_permanent',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
        'is_permanent' => 'boolean',
    ];

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

    /**
     * Get icon class based on activity type
     */
    public function getIconAttribute(): string
    {
        return self::resolveIconForType((string) $this->activity_type);
    }

    /**
     * Get color class based on activity type
     */
    public function getColorClassAttribute(): string
    {
        return self::resolveColorClassForType((string) $this->activity_type);
    }

    public static function resolveIconForType(string $activityType): string
    {
        if (self::$useRustFfi) {
            return self::resolveIconForTypeNative($activityType);
        }

        return self::resolveIconForTypePhp($activityType);
    }

    public static function resolveIconForTypePhp(string $activityType): string
    {
        return match ($activityType) {
            'registered' => 'user-plus',
            'deleted' => 'user-times',
            'role_updated' => 'user-shield',
            default => 'info-circle',
        };
    }

    public static function resolveIconForTypeNative(string $activityType): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::resolveIconForTypePhp($activityType);
        }

        try {
            $cap = 32;
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_resolve_icon(
                $activityType,
                strlen($activityType),
                $out,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::resolveIconForTypePhp($activityType);
            }

            return \FFI::string($out, $written);
        } catch (\Throwable) {
            return self::resolveIconForTypePhp($activityType);
        }
    }

    public static function resolveColorClassForType(string $activityType): string
    {
        if (self::$useRustFfi) {
            return self::resolveColorClassForTypeNative($activityType);
        }

        return self::resolveColorClassForTypePhp($activityType);
    }

    public static function resolveColorClassForTypePhp(string $activityType): string
    {
        return match ($activityType) {
            'registered' => 'green',
            'deleted' => 'red',
            'role_updated' => 'blue',
            default => 'gray',
        };
    }

    public static function resolveColorClassForTypeNative(string $activityType): string
    {
        $ffi = self::additionalFfi();
        if (! $ffi instanceof \FFI) {
            return self::resolveColorClassForTypePhp($activityType);
        }

        try {
            $cap = 16;
            $out = $ffi->new("uint8_t[{$cap}]");
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_user_activity_resolve_color_class(
                $activityType,
                strlen($activityType),
                $out,
                $cap
            );
            if (! is_int($written) || $written <= 0) {
                return self::resolveColorClassForTypePhp($activityType);
            }

            return \FFI::string($out, $written);
        } catch (\Throwable) {
            return self::resolveColorClassForTypePhp($activityType);
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
