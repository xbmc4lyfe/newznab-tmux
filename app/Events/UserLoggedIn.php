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

class UserLoggedIn
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_w361_user_gdpr_auth(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private const array CLIENT_FAMILIES = ['unknown', 'bot', 'cli', 'browser', 'other'];

    private const array BOT_MARKERS = ['bot', 'crawler', 'spider'];

    private const array CLI_PREFIXES = ['curl/', 'wget/', 'python-requests/', 'httpie/', 'go-http-client/'];

    private const int MAX_USER_AGENT_BYTES = 512;

    /**
     * PHP remains the default: the paired benchmark in
     * tests/Unit/RustUserGdprAuthEventsProviderParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public User $user;

    public string $ip;

    /**
     * Create a new event instance.
     */
    public function __construct(User $user, string $ip = '')
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
     * Audit fields for a login: a nonnegative user id, the validated IP from
     * {@see UserAccessedApi::normalizeIpAddressPhp()}, a single-spaced user agent of at
     * most 512 bytes (null when blank) with its client family, and the login method.
     *
     * @return array{user_id: int, ip: string|null, ip_version: int, user_agent: string|null, client_family: string, method: string}
     */
    public static function normalizeLoginAuditPayload(int $userId, ?string $ipAddress, ?string $userAgent, bool $viaRemember = false): array
    {
        return (self::usesRustFfi() ? self::normalizeLoginAuditPayloadNative($userId, $ipAddress, $userAgent, $viaRemember) : null)
            ?? self::normalizeLoginAuditPayloadPhp($userId, $ipAddress, $userAgent, $viaRemember);
    }

    /**
     * @return array{user_id: int, ip: string|null, ip_version: int, user_agent: string|null, client_family: string, method: string}
     */
    public static function normalizeLoginAuditPayloadPhp(int $userId, ?string $ipAddress, ?string $userAgent, bool $viaRemember = false): array
    {
        [$ip, $version] = UserAccessedApi::normalizeIpAddressPhp($ipAddress);
        // Byte-wise: runs of ASCII controls, spaces and DEL become one space.
        $agent = trim((string) preg_replace('/[\x00-\x20\x7F]+/', ' ', $userAgent ?? ''), ' ');
        if (strlen($agent) > self::MAX_USER_AGENT_BYTES) {
            $agent = rtrim(substr($agent, 0, self::MAX_USER_AGENT_BYTES), ' ');
        }

        return [
            'user_id' => max(0, $userId),
            'ip' => $ip,
            'ip_version' => $version,
            'user_agent' => $agent === '' ? null : $agent,
            'client_family' => self::clientFamily($agent),
            'method' => $viaRemember ? 'remember' : 'password',
        ];
    }

    /**
     * @return array{user_id: int, ip: string|null, ip_version: int, user_agent: string|null, client_family: string, method: string}|null
     */
    public static function normalizeLoginAuditPayloadNative(int $userId, ?string $ipAddress, ?string $userAgent, bool $viaRemember = false): ?array
    {
        $bytes = self::rust(4, pack('q2', $userId, (int) $viaRemember).UserAccessedApi::packString($ipAddress).UserAccessedApi::packString($userAgent));
        $fields = $bytes === null ? null : UserAccessedApi::unpackFrame($bytes, 4, 2);
        $family = $fields === null ? null : (self::CLIENT_FAMILIES[$fields[3]] ?? null);
        if ($fields === null || $family === null || ! in_array($fields[1], [0, 4, 6], true) || ($fields[2] !== 0 && $fields[2] !== 1)
            || ($fields[4] === null) !== ($fields[1] === 0) || ($fields[5] === null) !== ($family === 'unknown')) {
            return null;
        }

        return [
            'user_id' => (int) $fields[0],
            'ip' => $fields[4] === null ? null : (string) $fields[4],
            'ip_version' => (int) $fields[1],
            'user_agent' => $fields[5] === null ? null : (string) $fields[5],
            'client_family' => $family,
            'method' => $fields[2] === 1 ? 'remember' : 'password',
        ];
    }

    private static function clientFamily(string $agent): string
    {
        if ($agent === '') {
            return 'unknown';
        }
        $lower = strtolower($agent);
        foreach (self::BOT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return 'bot';
            }
        }
        foreach (self::CLI_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'cli';
            }
        }

        return str_contains($lower, 'mozilla/') ? 'browser' : 'other';
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
