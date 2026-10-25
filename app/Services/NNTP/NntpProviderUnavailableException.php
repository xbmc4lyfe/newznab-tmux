<?php

declare(strict_types=1);

namespace App\Services\NNTP;

/**
 * A requested provider could not be connected or authenticated, as opposed to a single article
 * being missing; callers can stop querying that provider for the rest of their work.
 */
final class NntpProviderUnavailableException extends \RuntimeException
{
    public const string FFI_CDEF = NntpArticleMissingException::FFI_CDEF;

    /** Consecutive failures that open the provider circuit. */
    public const int CIRCUIT_FAILURES = 3;

    private const int MAX_BACKOFF_SECONDS = 300;

    private const array KINDS = ['connect', 'auth', 'denied', 'unavailable', 'protocol'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
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
     * Classify a provider failure: no status (<= 0) is a connect failure, 480-482 authentication, 502 access denied,
     * 400/503 service unavailable, anything else a protocol failure. Authentication and denial are not retryable and
     * open the circuit at once; other kinds open it after CIRCUIT_FAILURES consecutive failures and back off 5 s
     * doubling per failure up to 300 s. The host is trimmed, loses trailing dots and is ASCII-lowercased.
     *
     * @return array{host: ?string, status: int, kind: string, tls: bool, default_port: int, consecutive_failures: int, circuit_open: bool, retryable: bool, backoff_seconds: int}
     */
    public static function classifyProviderFailure(?string $host, int $statusCode, bool $tlsEnabled, int $consecutiveFailures): array
    {
        return self::usesRustFfi() ? self::classifyProviderFailureNative($host, $statusCode, $tlsEnabled, $consecutiveFailures)
            : self::classifyProviderFailurePhp($host, $statusCode, $tlsEnabled, $consecutiveFailures);
    }

    /** @return array{host: ?string, status: int, kind: string, tls: bool, default_port: int, consecutive_failures: int, circuit_open: bool, retryable: bool, backoff_seconds: int} */
    public static function classifyProviderFailurePhp(?string $host, int $statusCode, bool $tlsEnabled, int $consecutiveFailures): array
    {
        $host = rtrim(trim($host ?? ''), '.');
        $kind = match (true) {
            $statusCode <= 0 => 'connect',
            $statusCode >= 480 && $statusCode <= 482 => 'auth',
            $statusCode === 502 => 'denied',
            $statusCode === 400 || $statusCode === 503 => 'unavailable',
            default => 'protocol',
        };
        $consecutive = max(0, $consecutiveFailures);
        $retryable = $kind !== 'auth' && $kind !== 'denied';

        return self::result($host === '' ? null : strtolower($host), $statusCode, $kind, $tlsEnabled, $consecutive,
            ! $retryable || $consecutive >= self::CIRCUIT_FAILURES, $retryable, $retryable ? min(self::MAX_BACKOFF_SECONDS, 5 << min($consecutive, 6)) : 0);
    }

    /** @return array{host: ?string, status: int, kind: string, tls: bool, default_port: int, consecutive_failures: int, circuit_open: bool, retryable: bool, backoff_seconds: int} */
    public static function classifyProviderFailureNative(?string $host, int $statusCode, bool $tlsEnabled, int $consecutiveFailures): array
    {
        $slots = NntpArticleMissingException::nativeSlots(NntpArticleMissingException::evaluateNative(1,
            pack('q5', (int) ($host !== null), \strlen($host ?? ''), $statusCode, (int) $tlsEnabled, $consecutiveFailures).($host ?? '')), 9);
        if ($slots !== null) {
            $normalized = NntpArticleMissingException::nativeRange($host, $slots[0], $slots[1], $slots[2]);
            if ($normalized !== false && isset(self::KINDS[$slots[3]]) && $slots[4] === ($tlsEnabled ? 563 : 119) && $slots[5] >= 0
                && \in_array($slots[6], [0, 1], true) && \in_array($slots[7], [0, 1], true) && $slots[8] >= 0 && $slots[8] <= self::MAX_BACKOFF_SECONDS) {
                return self::result($normalized === null ? null : strtolower($normalized), $statusCode, self::KINDS[$slots[3]], $tlsEnabled, $slots[5],
                    $slots[6] === 1, $slots[7] === 1, $slots[8]);
            }
        }

        return self::classifyProviderFailurePhp($host, $statusCode, $tlsEnabled, $consecutiveFailures);
    }

    /** @return array{host: ?string, status: int, kind: string, tls: bool, default_port: int, consecutive_failures: int, circuit_open: bool, retryable: bool, backoff_seconds: int} */
    private static function result(?string $host, int $status, string $kind, bool $tls, int $consecutive, bool $circuitOpen, bool $retryable, int $backoff): array
    {
        return ['host' => $host, 'status' => $status, 'kind' => $kind, 'tls' => $tls, 'default_port' => $tls ? 563 : 119,
            'consecutive_failures' => $consecutive, 'circuit_open' => $circuitOpen, 'retryable' => $retryable, 'backoff_seconds' => $backoff];
    }
}
