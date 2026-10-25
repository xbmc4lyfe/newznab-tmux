<?php

declare(strict_types=1);

namespace App\Facades;

use App\Services\NNTP\NntpArticleMissingException;
use App\Services\YencService;
use Illuminate\Support\Facades\Facade;

/**
 * Yenc Facade
 *
 * @method static string|false decode(string &$text, bool $ignore = false)
 * @method static string decodeIgnore(string &$text)
 * @method static bool enabled()
 * @method static string decoderName()
 * @method static bool isYencEncoded(string $text)
 * @method static string encode(string $data, string $filename, int $lineLength = 128, bool $includeCrc32 = true)
 * @method static array|null extractMetadata(string $text)
 *
 * @see YencService
 */
class Yenc extends Facade // @phpstan-ignore missingType.iterableValue
{
    public const string FFI_CDEF = NntpArticleMissingException::FFI_CDEF;

    /** The default ceiling for one yEnc codec call. */
    public const int CODEC_DEFAULT_MAX_BYTES = 16777216;

    /** No caller-supplied ceiling may exceed 1 TiB. */
    private const int CODEC_HARD_MAX_BYTES = 1099511627776;

    private const array DRIVERS = ['PHP', 'RapidYenc'];

    private const array ACTIONS = ['skip', 'decode', 'reject'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return YencService::class;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Normalize a codec call: the trimmed driver name matches "rapidyenc", "rapid_yenc" or "native" (RapidYenc) or
     * "php" ASCII case-insensitively; anything else is an unknown driver served by PHP. A ceiling below one byte
     * selects the 16 MiB default and larger ceilings are capped at 1 TiB. Negative payloads read as zero; an empty
     * payload is skipped, one above the ceiling rejected, otherwise decoded with an encoded-size estimate (2% escape
     * overhead plus CRLF per 128-byte line).
     *
     * @return array{driver: string, native: bool, known_driver: bool, max_bytes: int, payload_bytes: int, action: string, encoded_estimate: int}
     */
    public static function normalizeCodecCallPolicy(?string $driverName, int $payloadBytes, int $maxBytes = self::CODEC_DEFAULT_MAX_BYTES): array
    {
        return self::usesRustFfi() ? self::normalizeCodecCallPolicyNative($driverName, $payloadBytes, $maxBytes)
            : self::normalizeCodecCallPolicyPhp($driverName, $payloadBytes, $maxBytes);
    }

    /** @return array{driver: string, native: bool, known_driver: bool, max_bytes: int, payload_bytes: int, action: string, encoded_estimate: int} */
    public static function normalizeCodecCallPolicyPhp(?string $driverName, int $payloadBytes, int $maxBytes = self::CODEC_DEFAULT_MAX_BYTES): array
    {
        [$driver, $known] = match (strtolower(trim($driverName ?? ''))) {
            'rapidyenc', 'rapid_yenc', 'native' => ['RapidYenc', true],
            'php' => ['PHP', true],
            default => ['PHP', false],
        };
        $max = $maxBytes >= 1 ? min($maxBytes, self::CODEC_HARD_MAX_BYTES) : self::CODEC_DEFAULT_MAX_BYTES;
        $payload = max(0, $payloadBytes);
        $action = $payload === 0 ? 'skip' : ($payload > $max ? 'reject' : 'decode');
        $lines = intdiv($payload, 128) + ($payload % 128 !== 0 ? 1 : 0);

        return ['driver' => $driver, 'native' => $driver === 'RapidYenc', 'known_driver' => $known, 'max_bytes' => $max, 'payload_bytes' => $payload,
            'action' => $action, 'encoded_estimate' => $action === 'decode' ? $payload + intdiv($payload, 50) + $lines * 2 : 0];
    }

    /** @return array{driver: string, native: bool, known_driver: bool, max_bytes: int, payload_bytes: int, action: string, encoded_estimate: int} */
    public static function normalizeCodecCallPolicyNative(?string $driverName, int $payloadBytes, int $maxBytes = self::CODEC_DEFAULT_MAX_BYTES): array
    {
        $slots = NntpArticleMissingException::nativeSlots(NntpArticleMissingException::evaluateNative(4,
            pack('q4', (int) ($driverName !== null), \strlen($driverName ?? ''), $payloadBytes, $maxBytes).($driverName ?? '')), 6);
        if ($slots !== null && isset(self::DRIVERS[$slots[0]]) && \in_array($slots[1], [0, 1], true) && $slots[2] >= 1 && $slots[2] <= self::CODEC_HARD_MAX_BYTES
            && $slots[3] >= 0 && isset(self::ACTIONS[$slots[4]]) && $slots[5] >= 0) {
            return ['driver' => self::DRIVERS[$slots[0]], 'native' => $slots[0] === 1, 'known_driver' => $slots[1] === 1, 'max_bytes' => $slots[2],
                'payload_bytes' => $slots[3], 'action' => self::ACTIONS[$slots[4]], 'encoded_estimate' => $slots[5]];
        }

        return self::normalizeCodecCallPolicyPhp($driverName, $payloadBytes, $maxBytes);
    }
}
