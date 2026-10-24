<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

enum ConfigurationDomain: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_cfgd_resolve(const char *ptr, size_t len);
        ssize_t nntmux_cfgd_text(const char *ptr, size_t len, int32_t field, char *out, size_t cap);
        C;

    /** `nntmux_cfgd_text` field selectors. */
    public const int TEXT_LABEL = 0;

    public const int TEXT_TABLE = 1;

    public const int TEXT_CACHE_KEY = 2;

    public const int TEXT_MODEL_CLASS = 3;

    case Site = 'site';
    case Registration = 'registration';
    case Ingestion = 'ingestion';
    case PostProcessing = 'post-processing';
    case Metadata = 'metadata';
    case Tmux = 'tmux';

    // PHP remains the production default until benchmarks qualify Rust.
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

    public function label(): string
    {
        return self::usesRustFfi() ? $this->labelNative() : $this->labelPhp();
    }

    public function labelPhp(): string
    {
        return match ($this) {
            self::Site => 'Site',
            self::Registration => 'Registration',
            self::Ingestion => 'Ingestion',
            self::PostProcessing => 'Post Processing',
            self::Metadata => 'Metadata',
            self::Tmux => 'Tmux',
        };
    }

    public function labelNative(): string
    {
        return $this->textNative(self::TEXT_LABEL) ?? $this->labelPhp();
    }

    public function table(): string
    {
        return self::usesRustFfi() ? $this->tableNative() : $this->tablePhp();
    }

    public function tablePhp(): string
    {
        return match ($this) {
            self::Site => 'site_configurations',
            self::Registration => 'registration_configurations',
            self::Ingestion => 'ingestion_configurations',
            self::PostProcessing => 'post_processing_configurations',
            self::Metadata => 'metadata_configurations',
            self::Tmux => 'tmux_configurations',
        };
    }

    public function tableNative(): string
    {
        return $this->textNative(self::TEXT_TABLE) ?? $this->tablePhp();
    }

    /**
     * Resolve a backing value through Rust; null for an unknown value.
     */
    public static function tryFromNative(string $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return self::tryFrom($value);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_cfgd_resolve($value, \strlen($value));
        } catch (Throwable) {
            return self::tryFrom($value);
        }
        if (! \is_int($index)) {
            return self::tryFrom($value);
        }

        return $index < 0 ? null : (self::cases()[$index] ?? self::tryFrom($value));
    }

    /**
     * Text `$field` ({@see TEXT_LABEL}..{@see TEXT_MODEL_CLASS}) for this domain from Rust, or
     * null when the library is unavailable or rejects the request.
     */
    public function textNative(int $field): ?string
    {
        if (($ffi = self::ffi()) === null || ($buffer = self::textBuffer($ffi)) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_cfgd_text($this->value, \strlen($this->value), $field, $buffer, 64);
            if (! \is_int($written) || $written <= 0) {
                return null;
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /** Reusable `char[64]` text buffer for `$ffi`. */
    private static function textBuffer(FFI $ffi): ?CData
    {
        static $buffer = null;
        static $owner = null;
        if ($owner !== $ffi || $buffer === null) {
            try {
                $buffer = $ffi->new('char[64]');
                $owner = $ffi;
            } catch (Throwable) {
                return null;
            }
        }

        return $buffer;
    }

    private static function toggleState(?bool $next = null, bool $write = false): ?bool
    {
        // Enums cannot declare static properties.
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
            $ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }
}
