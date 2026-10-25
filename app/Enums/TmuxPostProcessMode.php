<?php

declare(strict_types=1);

namespace App\Enums;

use App\Providers\HorizonServiceProvider;

enum TmuxPostProcessMode: int
{
    case Disabled = 0;
    case Additional = 1;
    case Nfo = 2;
    case All = 3;

    public const string FFI_CDEF = HorizonServiceProvider::FFI_CDEF;

    // Retain PHP until paired caller benchmarks meet the 1.5x adoption gate.
    private const bool RUST_FFI_DEFAULT = false;

    public static function setUseRustFfi(?bool $use): void
    {
        HorizonServiceProvider::setRustFfiToggle(self::class, $use);
    }

    public static function usesRustFfi(): bool
    {
        return HorizonServiceProvider::rustFfiToggle(self::class) ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Accept backing integers, canonical single-digit strings, or this enum's cases.
     * Reject other types and unknown values, falling back to the safe zero mode.
     *
     * @return array{value: int, is_valid: bool}
     */
    public static function normalizePostProcessModeValue(mixed $value): array
    {
        return (self::usesRustFfi() ? self::normalizePostProcessModeValueNative($value) : null)
            ?? self::normalizePostProcessModeValuePhp($value);
    }

    /** @return array{value: int, is_valid: bool} */
    public static function normalizePostProcessModeValuePhp(mixed $value): array
    {
        $raw = $value instanceof self ? $value->value : $value;
        if (is_string($raw) && strlen($raw) === 1 && $raw >= '0' && $raw <= '9') {
            $raw = (int) $raw;
        }
        $resolved = is_int($raw) ? self::tryFrom($raw) : null;

        return ['value' => $resolved->value ?? 0, 'is_valid' => $resolved !== null];
    }

    /** @return array{value: int, is_valid: bool}|null */
    public static function normalizePostProcessModeValueNative(mixed $value): ?array
    {
        $result = HorizonServiceProvider::normalizeEnumNative(self::class, 2, $value);
        if ($result === null || ! is_int($result['value'])) {
            return null;
        }

        return $result;
    }
}
