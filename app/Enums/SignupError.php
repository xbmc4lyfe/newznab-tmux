<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum SignupError: int
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_signup_role_queue_label(uint32_t kind, int64_t value, char *out_buf, size_t out_cap);
        int32_t nntmux_signup_role_queue_flags(uint32_t kind, int64_t value);
        int32_t nntmux_signup_role_queue_resolve_label(uint32_t kind, const char *label_ptr, size_t label_len, int64_t *out_value);
        C;

    private const int FFI_KIND = 0;

    private const int DECLINE = -2147483648;

    private const int FLAG_KNOWN = 1;

    private const int FLAG_ERROR = 2;

    private const int FLAG_SUCCESS = 4;

    case BAD_USERNAME = -1;
    case BAD_PASSWORD = -2;
    case BAD_EMAIL = -3;
    case USERNAME_IN_USE = -4;
    case EMAIL_IN_USE = -5;
    case BAD_INVITE_CODE = -6;
    case SUCCESS = 1;

    /** Opt-in only; see the paired measurements in RustSignupRoleQueueEnumComposerParityTest. */
    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::rustFfiToggle($useRustFfi);
    }

    public static function usesRustFfi(): bool
    {
        return self::rustFfiToggle();
    }

    public static function resetRustFfi(): void
    {
        self::rustFfiToggle(false);
        self::ffi(true);
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::ffi(true, $ffi);
    }

    public function message(): string
    {
        return self::usesRustFfi() ? $this->messageNative() : $this->messagePhp();
    }

    public function messagePhp(): string
    {
        return match ($this) {
            self::BAD_USERNAME => 'Invalid username provided.',
            self::BAD_PASSWORD => 'Invalid password provided.',
            self::BAD_EMAIL => 'Invalid email address provided.',
            self::USERNAME_IN_USE => 'Username is already in use.',
            self::EMAIL_IN_USE => 'Email address is already in use.',
            self::BAD_INVITE_CODE => 'Invalid or expired invite code.',
            self::SUCCESS => 'Registration successful.',
        };
    }

    public function messageNative(): string
    {
        return self::nativeLabel($this->value) ?? $this->messagePhp();
    }

    public function isError(): bool
    {
        return self::usesRustFfi() ? $this->isErrorNative() : $this->isErrorPhp();
    }

    public function isErrorPhp(): bool
    {
        return $this->value < 0;
    }

    public function isErrorNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isErrorPhp() : ($flags & self::FLAG_ERROR) !== 0;
    }

    public function isSuccess(): bool
    {
        return self::usesRustFfi() ? $this->isSuccessNative() : $this->isSuccessPhp();
    }

    public function isSuccessPhp(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isSuccessNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isSuccessPhp() : ($flags & self::FLAG_SUCCESS) !== 0;
    }

    /**
     * Resolve a case from its exact user-facing message.
     */
    public static function tryFromMessage(string $message): ?self
    {
        return self::usesRustFfi() ? self::tryFromMessageNative($message) : self::tryFromMessagePhp($message);
    }

    public static function tryFromMessagePhp(string $message): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->messagePhp() === $message) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromMessageNative(string $message): ?self
    {
        $ffi = self::ffi();
        $slot = $ffi === null ? null : self::valueSlot($ffi);
        if ($ffi !== null && $slot !== null) {
            try {
                $rc = self::call($ffi, 'nntmux_signup_role_queue_resolve_label', self::FFI_KIND, $message, \strlen($message), $slot);
                if ($rc === 0) {
                    return null;
                }
                if ($rc === 1) {
                    $case = self::tryFrom((int) $slot[0]);
                    if ($case !== null) {
                        return $case;
                    }
                }
            } catch (Throwable) {
            }
        }

        return self::tryFromMessagePhp($message);
    }

    private static function nativeLabel(int $value): ?string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $value, $scratch, $cap);
            if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $value, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nativeFlags(int $value): ?int
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $flags = self::call($ffi, 'nntmux_signup_role_queue_flags', self::FFI_KIND, $value);

            return \is_int($flags) && $flags > 0 && ($flags & self::FLAG_KNOWN) !== 0 ? $flags : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function call(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function valueSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('int64_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }

    private static function rustFfiToggle(?bool $set = null): bool
    {
        static $enabled = false;
        if ($set !== null) {
            $enabled = $set;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;

            return null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('search', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
