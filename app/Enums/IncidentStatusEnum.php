<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum IncidentStatusEnum: string
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_incident_status_label(const char *value_ptr, size_t value_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_incident_status_values_nul(size_t *out_count, char *out_buf, size_t out_cap);
        C;

    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public static function setUseRustFfi(?bool $use = true): void
    {
        self::toggleState($use, true);
        if ($use === null) {
            self::categorizeFfi(true);
        }
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState() ?? false;
    }

    public static function setFfiForTesting(FFI|false|null $override = null): void
    {
        self::categorizeFfi(true, $override);
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        static $useRustFfi = null;
        if ($write) {
            $useRustFfi = $next;
        }

        return $useRustFfi;
    }

    public static function categorizeFfi(bool $reset = false, FFI|false|null $override = null): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = $override;
            if ($override !== null) {
                return $ffi === false ? null : $ffi;
            }
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Investigating => 'Investigating',
            self::Identified => 'Identified',
            self::Monitoring => 'Monitoring',
            self::Resolved => 'Resolved',
        };
    }

    public function labelNative(): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->labelPhp();
        }

        $val = $this->value;
        $len = \strlen($val);
        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->labelPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_incident_status_label($val, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_status_label($val, $len, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return $this->labelPhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return self::usesRustFfi() ? self::valuesNative() : self::valuesPhp();
    }

    /**
     * @return array<int, string>
     */
    public static function valuesPhp(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<int, string>
     */
    public static function valuesNative(): array
    {
        $ffi = self::categorizeFfi();
        $outCount = $ffi === null ? null : self::countSlot($ffi);
        if ($ffi === null || $outCount === null) {
            return self::valuesPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 64, $cap);
        if ($scratch === null) {
            return self::valuesPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_incident_status_values_nul($outCount, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::valuesPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_incident_status_values_nul($outCount, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return self::valuesPhp();
            }

            /** @var int $expected */
            $expected = $outCount[0];
            $parts = explode("\0", FFI::string($scratch, $written));

            return \count($parts) === $expected ? $parts : self::valuesPhp();
        } catch (Throwable) {
            return self::valuesPhp();
        }
    }

    private static function countSlot(FFI $ffi): ?CData
    {
        static $slot = null;
        static $owner = null;
        if ($owner !== $ffi || $slot === null) {
            try {
                $slot = $ffi->new('size_t[1]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $slot;
    }
}
