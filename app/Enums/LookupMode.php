<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

enum LookupMode: int
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_lookup_mode_label(int64_t mode, char *out_buf, size_t out_cap);
        C;

    case Disabled = 0;
    case All = 1;
    case Renamed = 2;

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::ffi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::ffi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    private static function ffi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function label(): string
    {
        return self::usesRustFfi()
            ? $this->labelNative()
            : $this->labelPhp();
    }

    public function labelNative(): string
    {
        if (($ffi = self::ffi()) === null) {
            return $this->labelPhp();
        }
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, 64, $capacity);
        if ($buffer === null) {
            return $this->labelPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_lookup_mode_label($this->value, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_lookup_mode_label($this->value, $buffer, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->labelPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Disabled => 'Disabled',
            self::All => 'All releases',
            self::Renamed => 'Renamed releases only',
        };
    }
}
