<?php

declare(strict_types=1);

namespace App\Enums;

use App\Providers\HorizonServiceProvider;

enum TmuxSequentialMode: int
{
    case Full = 0;
    case Basic = 1;
    case Stripped = 2;

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
    public static function normalizeSequentialModeValue(mixed $value): array
    {
        return (self::usesRustFfi() ? self::normalizeSequentialModeValueNative($value) : null)
            ?? self::normalizeSequentialModeValuePhp($value);
    }

    /** @return array{value: int, is_valid: bool} */
    public static function normalizeSequentialModeValuePhp(mixed $value): array
    {
        $raw = $value instanceof self ? $value->value : $value;
        if (is_string($raw) && strlen($raw) === 1 && $raw >= '0' && $raw <= '9') {
            $raw = (int) $raw;
        }
        $resolved = is_int($raw) ? self::tryFrom($raw) : null;

        return ['value' => $resolved->value ?? 0, 'is_valid' => $resolved !== null];
    }

    /** @return array{value: int, is_valid: bool}|null */
    public static function normalizeSequentialModeValueNative(mixed $value): ?array
    {
        $result = HorizonServiceProvider::normalizeEnumNative(self::class, 3, $value);
        if ($result === null || ! is_int($result['value'])) {
            return null;
        }

        return $result;
    }
}
