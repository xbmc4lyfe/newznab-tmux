<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum UserRole: int
{
    public const string FFI_CDEF = SignupError::FFI_CDEF;

    private const int FFI_KIND = 1;

    private const int DECLINE = -2147483648;

    private const int FLAG_KNOWN = 1;

    private const int FLAG_ADMIN = 2;

    private const int FLAG_MODERATOR = 4;

    private const int FLAG_DISABLED = 8;

    private const int FLAG_ELEVATED = 16;

    case USER = 1;
    case ADMIN = 2;
    case DISABLED = 3;
    case MODERATOR = 4;

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

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::ADMIN => 'Admin',
            self::DISABLED => 'Disabled',
            self::MODERATOR => 'Moderator',
        };
    }

    public function labelNative(): string
    {
        return self::nativeLabel($this->value) ?? $this->labelPhp();
    }

    public function isAdmin(): bool
    {
        return self::usesRustFfi() ? $this->isAdminNative() : $this->isAdminPhp();
    }

    public function isAdminPhp(): bool
    {
        return $this === self::ADMIN;
    }

    public function isAdminNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isAdminPhp() : ($flags & self::FLAG_ADMIN) !== 0;
    }

    public function isModerator(): bool
    {
        return self::usesRustFfi() ? $this->isModeratorNative() : $this->isModeratorPhp();
    }

    public function isModeratorPhp(): bool
    {
        return $this === self::MODERATOR;
    }

    public function isModeratorNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isModeratorPhp() : ($flags & self::FLAG_MODERATOR) !== 0;
    }

    public function isDisabled(): bool
    {
        return self::usesRustFfi() ? $this->isDisabledNative() : $this->isDisabledPhp();
    }

    public function isDisabledPhp(): bool
    {
        return $this === self::DISABLED;
    }

    public function isDisabledNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->isDisabledPhp() : ($flags & self::FLAG_DISABLED) !== 0;
    }

    public function hasElevatedPrivileges(): bool
    {
        return self::usesRustFfi() ? $this->hasElevatedPrivilegesNative() : $this->hasElevatedPrivilegesPhp();
    }

    public function hasElevatedPrivilegesPhp(): bool
    {
        return in_array($this, [self::ADMIN, self::MODERATOR], true);
    }

    public function hasElevatedPrivilegesNative(): bool
    {
        $flags = self::nativeFlags($this->value);

        return $flags === null ? $this->hasElevatedPrivilegesPhp() : ($flags & self::FLAG_ELEVATED) !== 0;
    }

    /**
     * Resolve a case from its exact display label (e.g. a role name such as "Admin").
     */
    public static function tryFromLabel(string $label): ?self
    {
        return self::usesRustFfi() ? self::tryFromLabelNative($label) : self::tryFromLabelPhp($label);
    }

    public static function tryFromLabelPhp(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->labelPhp() === $label) {
                return $case;
            }
        }

        return null;
    }

    public static function tryFromLabelNative(string $label): ?self
    {
        $ffi = self::ffi();
        $slot = $ffi === null ? null : self::valueSlot($ffi);
        if ($ffi !== null && $slot !== null) {
            try {
                $rc = self::call($ffi, 'nntmux_signup_role_queue_resolve_label', self::FFI_KIND, $label, \strlen($label), $slot);
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

        return self::tryFromLabelPhp($label);
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
