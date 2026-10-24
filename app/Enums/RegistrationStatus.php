<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum RegistrationStatus: int
{
    public const string FFI_CDEF = SignupError::FFI_CDEF;

    private const int FFI_KIND = 3;

    private const int DECLINE = -2147483648;

    case Open = 0;
    case Invite = 1;
    case Closed = 2;

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
            self::Open => 'Open',
            self::Invite => 'Invite',
            self::Closed => 'Closed',
        };
    }

    public function labelNative(): string
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return $this->labelPhp();
        }

        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
            if ($scratch === null) {
                return $this->labelPhp();
            }
            $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            if (\is_int($written) && $written < 0 && $written > self::DECLINE) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                $written = self::call($ffi, 'nntmux_signup_role_queue_label', self::FFI_KIND, $this->value, $scratch, $cap);
            }
            if (\is_int($written) && $written > 0 && $written <= $cap) {
                return FFI::string($scratch, $written);
            }
        } catch (Throwable) {
        }

        return $this->labelPhp();
    }

    /**
     * Resolve a case from its exact display label.
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
