<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

class PasswordSecurity extends Model
{
    use HasFactory; // @phpstan-ignore missingType.generics

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_password_security_normalize_two_factor(
            uint32_t has_secret,
            uint32_t enabled,
            const char *secret_ptr,
            size_t secret_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustGdprSecurityRegistrationModelsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    /** Separators users paste between secret groups; removed before validation. */
    private const array SECRET_SEPARATORS = [' ', "\t", "\n", "\r", "\0", "\x0B", "\x0C", '-'];

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const int SECRET_MIN = 16;

    private const int SECRET_MAX = 128;

    private const string FIELD_SEPARATOR = "\x1F";

    private const string NULL_MARK = "\x1E";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'google2fa_enable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a Google 2FA secret (separators and `=` padding removed, uppercase RFC 4648
     * base32, 16-128 characters) and derives the enable state, which requires a valid secret.
     *
     * @return array{google2fa_secret: ?string, google2fa_enable: bool, is_configured: bool, needs_verification: bool}
     */
    public static function normalizeTwoFactorState(?string $secret, bool $enabled): array
    {
        return self::usesRustFfi()
            ? self::normalizeTwoFactorStateNative($secret, $enabled)
            : self::normalizeTwoFactorStatePhp($secret, $enabled);
    }

    /**
     * @return array{google2fa_secret: ?string, google2fa_enable: bool, is_configured: bool, needs_verification: bool}
     */
    public static function normalizeTwoFactorStateNative(?string $secret, bool $enabled): array
    {
        $raw = $secret ?? '';
        $fields = self::callNative('nntmux_password_security_normalize_two_factor', [$secret === null ? 0 : 1, $enabled ? 1 : 0, $raw, strlen($raw)]);
        if ($fields === null || count($fields) !== 4 || $fields[1] === null || $fields[2] === null || $fields[3] === null) {
            return self::normalizeTwoFactorStatePhp($secret, $enabled);
        }

        return [
            'google2fa_secret' => $fields[0],
            'google2fa_enable' => $fields[1] === '1',
            'is_configured' => $fields[2] === '1',
            'needs_verification' => $fields[3] === '1',
        ];
    }

    /**
     * @return array{google2fa_secret: ?string, google2fa_enable: bool, is_configured: bool, needs_verification: bool}
     */
    public static function normalizeTwoFactorStatePhp(?string $secret, bool $enabled): array
    {
        $normalized = null;
        if ($secret !== null) {
            $candidate = rtrim(strtoupper(str_replace(self::SECRET_SEPARATORS, '', $secret)), '=');
            $length = strlen($candidate);
            if ($length >= self::SECRET_MIN && $length <= self::SECRET_MAX && strspn($candidate, self::BASE32_ALPHABET) === $length) {
                $normalized = $candidate;
            }
        }
        $configured = $normalized !== null;

        return [
            'google2fa_secret' => $normalized,
            'google2fa_enable' => $enabled && $configured,
            'is_configured' => $configured,
            'needs_verification' => $configured && ! $enabled,
        ];
    }

    /**
     * @param  list<int|string>  $args
     * @return list<?string>|null
     */
    private static function callNative(string $symbol, array $args): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, 1024, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return array_map(
                static fn (string $field): ?string => $field === self::NULL_MARK ? null : $field,
                explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written)),
            );
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
