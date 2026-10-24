<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 */
class TrustedDevice extends Model
{
    /** Stored user agents are limited to this display width. */
    public const int USER_AGENT_LIMIT = 500;

    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_trusted_device_token_hash(const char *token, size_t token_len, const char *key, size_t key_len, char *out, size_t cap);
        intptr_t nntmux_trusted_device_cookie_token(const char *cookie, size_t cookie_len, int64_t user_id, int64_t now, char *out, size_t cap);
        intptr_t nntmux_trusted_device_user_agent_limit(const char *ua, size_t ua_len, size_t limit, char *out, size_t cap);
        C;

    /**
     * The Rust helpers stay opt-in: only hmacTokenHash beat PHP by 1.5x in the paired
     * benchmark, while cookiePayloadToken and limitUserAgent, which share this toggle, did not
     * (tests/Unit/RustEmailDomainCaptchaTurnstileTrustedDeviceParityTest.php).
     */
    private const bool DEFAULT_USE_RUST_FFI = false;

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'last_used_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{plain: string, device: self}
     */
    public static function issueForUser(User $user, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $plainToken = Str::random(64);
        $now = now();

        DB::insert(
            'insert into trusted_devices (user_id, token_hash, expires_at, ip_address, user_agent, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?)',
            [
                $user->id,
                self::hashToken($plainToken),
                $now->copy()->addDays(30)->toDateTimeString(),
                $ipAddress,
                self::limitUserAgent($userAgent),
                $now->toDateTimeString(),
                $now->toDateTimeString(),
            ]
        );

        $deviceId = (int) DB::getPdo()->lastInsertId();

        /** @var self $device */
        $device = self::query()->findOrFail($deviceId);

        return ['plain' => $plainToken, 'device' => $device];
    }

    public static function findValidForUser(int $userId, string $plainToken): ?self
    {
        if ($plainToken === '') {
            return null;
        }

        /** @var self|null $device */
        $device = self::query()
            ->where('user_id', $userId)
            ->where('token_hash', self::hashToken($plainToken))
            ->where('expires_at', '>', now())
            ->first();

        if ($device !== null) {
            $device->forceFill(['last_used_at' => now()])->save();
        }

        return $device;
    }

    public static function cookieIsValidForUser(mixed $cookie, int $userId): bool
    {
        $token = self::cookiePayloadToken($cookie, $userId, time());

        return $token !== null && self::findValidForUser($userId, $token) !== null;
    }

    public static function hashToken(string $plainToken): string
    {
        return self::hmacTokenHash($plainToken, (string) config('app.key'));
    }

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
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::DEFAULT_USE_RUST_FFI;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::$ffi = $override;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Lowercase hex `hash_hmac('sha256', $plainToken, $key)`.
     */
    public static function hmacTokenHash(string $plainToken, string $key): string
    {
        return self::usesRustFfi()
            ? self::hmacTokenHashNative($plainToken, $key)
            : self::hmacTokenHashPhp($plainToken, $key);
    }

    public static function hmacTokenHashPhp(string $plainToken, string $key): string
    {
        return hash_hmac('sha256', $plainToken, $key);
    }

    public static function hmacTokenHashNative(string $plainToken, string $key): string
    {
        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, 64, $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::hmacTokenHashPhp($plainToken, $key);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_trusted_device_token_hash($plainToken, \strlen($plainToken), $key, \strlen($key), $scratch, $cap);

            return $written === 64 ? FFI::string($scratch, 64) : self::hmacTokenHashPhp($plainToken, $key);
        } catch (Throwable) {
            return self::hmacTokenHashPhp($plainToken, $key);
        }
    }

    /**
     * The device token of a JSON trusted-device cookie that belongs to the user and has not
     * expired at `$now`, or null when the payload is unusable.
     */
    public static function cookiePayloadToken(mixed $cookie, int $userId, int $now): ?string
    {
        return self::usesRustFfi()
            ? self::cookiePayloadTokenNative($cookie, $userId, $now)
            : self::cookiePayloadTokenPhp($cookie, $userId, $now);
    }

    public static function cookiePayloadTokenPhp(mixed $cookie, int $userId, int $now): ?string
    {
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        $data = json_decode($cookie, true);
        if (! is_array($data) ||
            ! isset($data['user_id'], $data['token'], $data['expires_at']) ||
            ! is_numeric($data['user_id']) ||
            ! is_string($data['token']) ||
            ! is_numeric($data['expires_at']) ||
            (int) $data['user_id'] !== $userId ||
            $now > (int) $data['expires_at']) {
            return null;
        }

        return $data['token'];
    }

    public static function cookiePayloadTokenNative(mixed $cookie, int $userId, int $now): ?string
    {
        if (! is_string($cookie) || $cookie === '') {
            return null;
        }

        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, \strlen($cookie), $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::cookiePayloadTokenPhp($cookie, $userId, $now);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_trusted_device_cookie_token($cookie, \strlen($cookie), $userId, $now, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap + 1) {
                return self::cookiePayloadTokenPhp($cookie, $userId, $now);
            }

            return match ($written) {
                0 => null,
                1 => '',
                default => FFI::string($scratch, $written - 1),
            };
        } catch (Throwable) {
            return self::cookiePayloadTokenPhp($cookie, $userId, $now);
        }
    }

    /**
     * `Str::limit($userAgent, USER_AGENT_LIMIT, '')`, keeping null.
     */
    public static function limitUserAgent(?string $userAgent): ?string
    {
        return self::usesRustFfi()
            ? self::limitUserAgentNative($userAgent)
            : self::limitUserAgentPhp($userAgent);
    }

    public static function limitUserAgentPhp(?string $userAgent): ?string
    {
        return $userAgent !== null ? Str::limit($userAgent, self::USER_AGENT_LIMIT, '') : null;
    }

    public static function limitUserAgentNative(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $ffi = self::ffi();
        $cap = 0;
        $scratch = $ffi !== null ? RustPipelineLibrary::obtainScratch($ffi, max(1, \strlen($userAgent)), $cap) : null;
        if ($ffi === null || $scratch === null) {
            return self::limitUserAgentPhp($userAgent);
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_trusted_device_user_agent_limit($userAgent, \strlen($userAgent), self::USER_AGENT_LIMIT, $scratch, $cap);
            if (! \is_int($written) || $written < 0 || $written > $cap) {
                return self::limitUserAgentPhp($userAgent);
            }

            return $written === 0 ? '' : FFI::string($scratch, $written);
        } catch (Throwable) {
            return self::limitUserAgentPhp($userAgent);
        }
    }
}
