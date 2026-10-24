<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Constants for blacklist/whitelist operations.
 * Migrated from Blacklight\Binaries.
 */
final class BlacklistConstants
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_wave21_blacklist_constant(const char *input, size_t len);
        C;

    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    // Operation type constants
    public const OPTYPE_BLACKLIST = 1;

    public const OPTYPE_WHITELIST = 2;

    // Blacklist status constants
    public const BLACKLIST_ENABLED = 1;

    // Blacklist field constants
    public const BLACKLIST_FIELD_SUBJECT = 1;

    public const BLACKLIST_FIELD_FROM = 2;

    public const BLACKLIST_FIELD_MESSAGEID = 3;

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

    public static function resolveConstant(string $name): ?int
    {
        if (self::$useRustFfi) {
            $resolved = self::resolveConstantNative($name);
            if ($resolved !== null) {
                return $resolved['value'];
            }
        }

        return self::resolveConstantPhp($name);
    }

    /** @return array{value: ?int}|null */
    public static function resolveConstantNative(string $name): ?array
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $value = self::$ffi->nntmux_wave21_blacklist_constant($name, strlen($name));
            if (! is_int($value) || $value < 0) {
                return null;
            }

            return ['value' => $value === 0 ? null : $value];
        } catch (Throwable) {
            return null;
        }
    }

    public static function resolveConstantPhp(string $name): ?int
    {
        return match ($name) {
            'OPTYPE_BLACKLIST' => self::OPTYPE_BLACKLIST,
            'OPTYPE_WHITELIST' => self::OPTYPE_WHITELIST,
            'BLACKLIST_ENABLED' => self::BLACKLIST_ENABLED,
            'BLACKLIST_FIELD_SUBJECT' => self::BLACKLIST_FIELD_SUBJECT,
            'BLACKLIST_FIELD_FROM' => self::BLACKLIST_FIELD_FROM,
            'BLACKLIST_FIELD_MESSAGEID' => self::BLACKLIST_FIELD_MESSAGEID,
            default => null,
        };
    }
}
