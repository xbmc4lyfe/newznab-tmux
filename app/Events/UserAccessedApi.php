<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UserAccessedApi
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w361_user_gdpr_auth(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustUserGdprAuthEventsProviderParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int MAX_IP_BYTES = 45;

    private const int MAX_ENDPOINT_BYTES = 255;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var User
     */
    public $user;

    public ?string $ip;

    /**
     * Create a new event instance.
     */
    public function __construct(mixed $user, ?string $ip = null)
    {
        $this->user = $user;
        $this->ip = $ip;
    }

    public static function setUseRustFfi(?bool $enabled): void
    {
        self::$useRustFfi = $enabled;
        if ($enabled === null) {
            self::$ffi = null;
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Audit fields for an API access: a nonnegative user id, a validated IP (IPv6 lowercased,
     * invalid as null) and the endpoint path without its query string or fragment.
     *
     * @return array{user_id: int, ip: string|null, ip_version: int, endpoint: string, has_query: bool}
     */
    public static function normalizeAccessAuditPayload(int $userId, ?string $ipAddress, ?string $endpoint): array
    {
        return (self::usesRustFfi() ? self::normalizeAccessAuditPayloadNative($userId, $ipAddress, $endpoint) : null)
            ?? self::normalizeAccessAuditPayloadPhp($userId, $ipAddress, $endpoint);
    }

    /**
     * @return array{user_id: int, ip: string|null, ip_version: int, endpoint: string, has_query: bool}
     */
    public static function normalizeAccessAuditPayloadPhp(int $userId, ?string $ipAddress, ?string $endpoint): array
    {
        [$ip, $version] = self::normalizeIpAddressPhp($ipAddress);
        $path = trim($endpoint ?? '');
        $cut = strcspn($path, '?#');

        return [
            'user_id' => max(0, $userId),
            'ip' => $ip,
            'ip_version' => $version,
            'endpoint' => substr('/'.trim(trim(substr($path, 0, $cut)), '/'), 0, self::MAX_ENDPOINT_BYTES),
            'has_query' => $cut < strlen($path),
        ];
    }

    /**
     * @return array{user_id: int, ip: string|null, ip_version: int, endpoint: string, has_query: bool}|null
     */
    public static function normalizeAccessAuditPayloadNative(int $userId, ?string $ipAddress, ?string $endpoint): ?array
    {
        $bytes = self::rust(3, pack('q', $userId).self::packString($ipAddress).self::packString($endpoint));
        $fields = $bytes === null ? null : self::unpackFrame($bytes, 3, 2);
        if ($fields === null || ! in_array($fields[1], [0, 4, 6], true) || ($fields[2] !== 0 && $fields[2] !== 1)
            || ($fields[3] === null) !== ($fields[1] === 0) || $fields[4] === null) {
            return null;
        }

        return [
            'user_id' => (int) $fields[0],
            'ip' => $fields[3] === null ? null : (string) $fields[3],
            'ip_version' => (int) $fields[1],
            'endpoint' => (string) $fields[4],
            'has_query' => $fields[2] === 1,
        ];
    }

    /**
     * Trims an address and accepts strict dotted-quad IPv4 or RFC 4291 text IPv6 (lowercased).
     *
     * @return array{0: string|null, 1: int} The address (null when missing/invalid) and version 4, 6 or 0.
     */
    public static function normalizeIpAddressPhp(?string $ipAddress): array
    {
        $ip = trim($ipAddress ?? '');
        if ($ip === '' || strlen($ip) > self::MAX_IP_BYTES) {
            return [null, 0];
        }
        if (self::isIpv4($ip)) {
            return [$ip, 4];
        }
        $lower = strtolower($ip);

        return self::isIpv6($lower) ? [$lower, 6] : [null, 0];
    }

    /** Encodes a nullable string as an i64 byte length (-1 = null) followed by its bytes. */
    public static function packString(?string $value): string
    {
        return $value === null ? pack('q', -1) : pack('q', strlen($value)).$value;
    }

    /**
     * Decodes `$ints` i64 fields followed by `$strings` nullable strings, requiring the
     * whole frame to be consumed.
     *
     * @return list<int|string|null>|null
     */
    public static function unpackFrame(string $bytes, int $ints, int $strings): ?array
    {
        $length = strlen($bytes);
        if ($length < 8 * ($ints + $strings)) {
            return null;
        }
        /** @var list<int> $fields */
        $fields = array_values(unpack("q{$ints}", $bytes) ?: []);
        $offset = 8 * $ints;
        for ($i = 0; $i < $strings; $i++) {
            if ($offset + 8 > $length) {
                return null;
            }
            /** @var int $size */
            $size = unpack('q', $bytes, $offset)[1];
            $offset += 8;
            if ($size === -1) {
                $fields[] = null;

                continue;
            }
            if ($size < 0 || $size > $length - $offset) {
                return null;
            }
            $fields[] = substr($bytes, $offset, $size);
            $offset += $size;
        }

        return $offset === $length ? $fields : null;
    }

    private static function isIpv4(string $ip): bool
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }
        foreach ($parts as $part) {
            if ($part === '' || strlen($part) > 3 || ! ctype_digit($part) || ($part[0] === '0' && strlen($part) > 1) || (int) $part > 255) {
                return false;
            }
        }

        return true;
    }

    /** Expects lowercase input; one "::" may stand for omitted groups and a trailing IPv4 counts as two. */
    private static function isIpv6(string $ip): bool
    {
        $halves = explode('::', $ip);
        if (count($halves) > 2) {
            return false;
        }
        $last = count($halves) - 1;
        $groups = 0;
        foreach ($halves as $h => $half) {
            if ($half === '') {
                continue;
            }
            $parts = explode(':', $half);
            $finalPart = count($parts) - 1;
            foreach ($parts as $i => $part) {
                if ($h === $last && $i === $finalPart && str_contains($part, '.')) {
                    if (! self::isIpv4($part)) {
                        return false;
                    }
                    $groups += 2;

                    continue;
                }
                if ($part === '' || strlen($part) > 4 || strspn($part, '0123456789abcdef') !== strlen($part)) {
                    return false;
                }
                $groups++;
            }
        }

        return count($halves) === 2 ? $groups <= 7 : $groups === 8;
    }

    private static function rust(int $operation, string $input): ?string
    {
        try {
            self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;
            if (self::$ffi === false) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch(self::$ffi, strlen($input) + 128, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = self::$ffi->nntmux_w361_user_gdpr_auth($operation, $input, strlen($input), $buffer, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }
}
