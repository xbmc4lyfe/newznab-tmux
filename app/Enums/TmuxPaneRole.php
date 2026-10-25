<?php

declare(strict_types=1);

namespace App\Enums;

use App\Providers\HorizonServiceProvider;

enum TmuxPaneRole: string
{
    case Monitor = 'monitor';
    case Binaries = 'binaries';
    case Backfill = 'backfill';
    case Releases = 'releases';
    case Sequential = 'sequential';
    case FixNames = 'fix_names';
    case RemoveCrap = 'remove_crap';
    case PostAdditional = 'post_additional';
    case PostTv = 'post_tv';
    case PostMetadata = 'post_metadata';
    case PostMovies = 'post_movies';
    case IrcScraper = 'irc_scraper';
    case Htop = 'htop';
    case Nmon = 'nmon';
    case Vnstat = 'vnstat';
    case Tcptrack = 'tcptrack';
    case BandwidthMonitor = 'bandwidth_monitor';
    case Mytop = 'mytop';
    case Redis = 'redis';
    case Console = 'console';

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
     * Exact backing values or this enum's cases only; no object/scalar coercion.
     * Unknown roles remain null so they cannot be classified as known workers.
     *
     * @return array{value: string|null, is_valid: bool}
     */
    public static function normalizePaneRoleValue(mixed $value): array
    {
        return (self::usesRustFfi() ? self::normalizePaneRoleValueNative($value) : null)
            ?? self::normalizePaneRoleValuePhp($value);
    }

    /** @return array{value: string|null, is_valid: bool} */
    public static function normalizePaneRoleValuePhp(mixed $value): array
    {
        $raw = $value instanceof self ? $value->value : $value;
        $resolved = is_string($raw) ? self::tryFrom($raw) : null;

        return ['value' => $resolved?->value, 'is_valid' => $resolved !== null];
    }

    /** @return array{value: string|null, is_valid: bool}|null */
    public static function normalizePaneRoleValueNative(mixed $value): ?array
    {
        $result = HorizonServiceProvider::normalizeEnumNative(self::class, 1, $value);
        if ($result === null || ($result['value'] !== null && ! is_string($result['value']))) {
            return null;
        }

        return $result;
    }
}
