<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Current health of a monitored service (stored in service_statuses.status).
 */
enum ServiceStatusEnum: string
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_service_status_label(const char *value_ptr, size_t value_len, char *out_buf, size_t out_cap);
        int32_t nntmux_service_status_severity(const char *value_ptr, size_t value_len);
        ssize_t nntmux_service_status_values_nul(size_t *out_count, char *out_buf, size_t out_cap);
        C;

    case Operational = 'operational';
    case Degraded = 'degraded';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';
    case Maintenance = 'maintenance';

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
            self::Operational => 'Operational',
            self::Degraded => 'Degraded',
            self::PartialOutage => 'Partial outage',
            self::MajorOutage => 'Major outage',
            self::Maintenance => 'Maintenance',
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
            $written = $ffi->nntmux_service_status_label($val, $len, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->labelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_service_status_label($val, $len, $scratch, $cap);
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
     * Higher value = worse condition for aggregation (worst wins).
     */
    public function severity(): int
    {
        return self::usesRustFfi() ? $this->severityNative() : $this->severityPhp();
    }

    public function severityPhp(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Degraded => 1,
            self::Maintenance => 2,
            self::PartialOutage => 3,
            self::MajorOutage => 4,
        };
    }

    public function severityNative(): int
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->severityPhp();
        }

        $val = $this->value;
        try {
            // @phpstan-ignore method.notFound
            $sev = $ffi->nntmux_service_status_severity($val, \strlen($val));
            if (\is_int($sev) && $sev >= 0 && $sev <= 4) {
                return $sev;
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->severityPhp();
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
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 128, $cap);
        if ($scratch === null) {
            return self::valuesPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_service_status_values_nul($outCount, $scratch, $cap);
            if (\is_int($written) && $written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return self::valuesPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_service_status_values_nul($outCount, $scratch, $cap);
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
