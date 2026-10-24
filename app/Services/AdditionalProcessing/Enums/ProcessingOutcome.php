<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

enum ProcessingOutcome: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_pout_classify(const char *value, size_t len);
        C;

    /** `nntmux_pout_classify` flag bits above the case index. */
    private const int SUCCESSFUL_BIT = 0x100;

    private const int DELETED_BIT = 0x200;

    case Completed = 'completed';
    case NoUsefulArtifacts = 'no-useful-artifacts';
    case Passworded = 'passworded';
    case GroupUnavailable = 'group-unavailable';
    case TemporaryWorkspaceUnavailable = 'temporary-workspace-unavailable';
    case TimedOut = 'timed-out';
    case DeletedAfterTimeout = 'deleted-after-timeout';
    case DeletedBrokenNzb = 'deleted-broken-nzb';
    case DeletedPar2Only = 'deleted-par2-only';
    case NotFound = 'not-found';
    // A partial publication whose NZB late articles may still replace; processed once settled.
    case AwaitingLateArticles = 'awaiting-late-articles';
    case Failed = 'failed';

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

    public function isSuccessful(): bool
    {
        return self::usesRustFfi() ? $this->isSuccessfulNative() : $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        return match ($this) {
            self::Completed, self::NoUsefulArtifacts, self::Passworded => true,
            default => false,
        };
    }

    public function isSuccessfulNative(): bool
    {
        $code = self::classifyNative($this->value);

        return $code === null ? $this->isSuccessfulPhp() : ($code & self::SUCCESSFUL_BIT) !== 0;
    }

    public function isDeleted(): bool
    {
        return self::usesRustFfi() ? $this->isDeletedNative() : $this->isDeletedPhp();
    }

    public function isDeletedPhp(): bool
    {
        return match ($this) {
            self::DeletedAfterTimeout, self::DeletedBrokenNzb, self::DeletedPar2Only => true,
            default => false,
        };
    }

    public function isDeletedNative(): bool
    {
        $code = self::classifyNative($this->value);

        return $code === null ? $this->isDeletedPhp() : ($code & self::DELETED_BIT) !== 0;
    }

    /**
     * Resolve a stored outcome code; null when it names no case.
     */
    public static function resolve(string $value): ?self
    {
        return self::usesRustFfi() ? self::resolveNative($value) : self::resolvePhp($value);
    }

    public static function resolvePhp(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public static function resolveNative(string $value): ?self
    {
        $code = self::classifyNative($value);

        return $code === null ? self::resolvePhp($value) : (self::cases()[$code & 0xFF] ?? self::resolvePhp($value));
    }

    /**
     * `nntmux_pout_classify` result, or null for an unknown value or an unavailable library.
     */
    private static function classifyNative(string $value): ?int
    {
        if (($ffi = self::ffi()) === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_pout_classify($value, \strlen($value));
        } catch (Throwable) {
            return null;
        }

        return \is_int($code) && $code >= 0 ? $code : null;
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
