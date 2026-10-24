<?php

declare(strict_types=1);

namespace App\Support\Configuration;

use App\Enums\RegistrationStatus;
use App\Models\RegistrationConfiguration;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

final readonly class RegistrationConfigurationData
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_registration_config_default_status(void);
        int32_t nntmux_registration_config_validate_status(int64_t status, int64_t *out_normalized);
        ssize_t nntmux_registration_config_status_label(int64_t status, char *out_buf, size_t out_cap);
        C;

    public function __construct(public RegistrationStatus $status) {}

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

    public static function defaults(): self
    {
        return self::usesRustFfi() ? self::defaultsNative() : self::defaultsPhp();
    }

    public static function defaultsPhp(): self
    {
        return new self(RegistrationStatus::Open);
    }

    public static function defaultsNative(): self
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return self::defaultsPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_registration_config_default_status();
            if (\is_int($code)) {
                $status = RegistrationStatus::tryFrom($code);
                if ($status !== null) {
                    return new self($status);
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::defaultsPhp();
    }

    public static function fromModel(RegistrationConfiguration $model): self
    {
        return self::usesRustFfi() ? self::fromModelNative($model) : self::fromModelPhp($model);
    }

    public static function fromModelPhp(RegistrationConfiguration $model): self
    {
        return new self($model->status);
    }

    public static function fromModelNative(RegistrationConfiguration $model): self
    {
        $ffi = self::categorizeFfi();
        $out = $ffi === null ? null : self::outSlot($ffi);
        if ($ffi === null || $out === null) {
            return self::fromModelPhp($model);
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_registration_config_validate_status($model->status->value, $out);
            if ($rc === 1) {
                $status = RegistrationStatus::tryFrom((int) $out[0]);
                if ($status !== null) {
                    return new self($status);
                }
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return self::fromModelPhp($model);
    }

    /** @return array{status: int} */
    public function toArray(): array
    {
        return self::usesRustFfi() ? $this->toArrayNative() : $this->toArrayPhp();
    }

    /** @return array{status: int} */
    public function toArrayPhp(): array
    {
        return ['status' => $this->status->value];
    }

    /** @return array{status: int} */
    public function toArrayNative(): array
    {
        $ffi = self::categorizeFfi();
        $out = $ffi === null ? null : self::outSlot($ffi);
        if ($ffi === null || $out === null) {
            return $this->toArrayPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $rc = $ffi->nntmux_registration_config_validate_status($this->status->value, $out);
            if ($rc === 1) {
                return ['status' => (int) $out[0]];
            }
        } catch (Throwable) {
            // Fall through to PHP fallback.
        }

        return $this->toArrayPhp();
    }

    public function statusLabel(): string
    {
        return self::usesRustFfi() ? $this->statusLabelNative() : $this->statusLabelPhp();
    }

    public function statusLabelPhp(): string
    {
        return $this->status->label();
    }

    public function statusLabelNative(): string
    {
        $ffi = self::categorizeFfi();
        if ($ffi === null) {
            return $this->statusLabelPhp();
        }

        $cap = 0;
        $scratch = RustPipelineLibrary::obtainScratch($ffi, 32, $cap);
        if ($scratch === null) {
            return $this->statusLabelPhp();
        }

        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_registration_config_status_label($this->status->value, $scratch, $cap);
            if (\is_int($written) && $written < -2) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return $this->statusLabelPhp();
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_registration_config_status_label($this->status->value, $scratch, $cap);
            }
            if (! \is_int($written) || $written <= 0 || $written > $cap) {
                return $this->statusLabelPhp();
            }

            return FFI::string($scratch, $written);
        } catch (Throwable) {
            return $this->statusLabelPhp();
        }
    }

    private static function outSlot(FFI $ffi): ?CData
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
}
