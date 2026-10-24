<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

enum ProcessingStage: string
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aenum_resolve(int32_t kind, const char *ptr, size_t len);
        ssize_t nntmux_aenum_label(int32_t kind, int64_t index, char *out, size_t cap);
        int32_t nntmux_aenum_rank(int32_t kind, int64_t index);
        C;

    /** `nntmux_aenum_*` selector for this enum. */
    private const int FFI_KIND = 1;

    case WorkspacePreparation = 'workspace-preparation';
    case NzbParsing = 'nzb-parsing';
    case ReleaseInitialization = 'release-initialization';
    case MessageIdSelection = 'message-id-selection';
    case MediaSourcePlanning = 'media-source-planning';
    case DirectDownloads = 'direct-downloads';
    case ArchiveDownloads = 'archive-downloads';
    case ExtractedFiles = 'extracted-files';
    case MediaSources = 'media-sources';
    case ArchiveFallbacks = 'archive-fallbacks';
    case TimeoutHandling = 'timeout-handling';
    case Finalization = 'finalization';
    case WorkspaceCleanup = 'workspace-cleanup';

    // PHP remains the production default until benchmarks qualify Rust.
    public static function enableRustFfi(): void
    {
        self::toggleState(true, true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false, true);
    }

    /**
     * Restore the production default and re-resolve the library on next use.
     */
    public static function resetRustFfi(): void
    {
        self::toggleState(null, true);
        self::ffi(true);
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
            self::WorkspacePreparation => 'Workspace preparation',
            self::NzbParsing => 'NZB parsing',
            self::ReleaseInitialization => 'Release initialization',
            self::MessageIdSelection => 'Message-ID selection',
            self::MediaSourcePlanning => 'Media source planning',
            self::DirectDownloads => 'Direct downloads',
            self::ArchiveDownloads => 'Archive downloads',
            self::ExtractedFiles => 'Extracted files',
            self::MediaSources => 'Media sources',
            self::ArchiveFallbacks => 'Archive fallbacks',
            self::TimeoutHandling => 'Timeout handling',
            self::Finalization => 'Finalization',
            self::WorkspaceCleanup => 'Workspace cleanup',
        };
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
            $written = $ffi->nntmux_aenum_label(self::FFI_KIND, $this->ordinal(), $buffer, $capacity);
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return $this->labelPhp();
            }

            return FFI::string($buffer, $written);
        } catch (Throwable) {
            return $this->labelPhp();
        }
    }

    /**
     * Position of the stage in the release processing pipeline.
     */
    public function rank(): int
    {
        return self::usesRustFfi() ? $this->rankNative() : $this->rankPhp();
    }

    public function rankPhp(): int
    {
        return match ($this) {
            self::WorkspacePreparation => 0,
            self::NzbParsing => 1,
            self::ReleaseInitialization => 2,
            self::MessageIdSelection => 3,
            self::MediaSourcePlanning => 4,
            self::DirectDownloads => 5,
            self::ArchiveDownloads => 6,
            self::ExtractedFiles => 7,
            self::MediaSources => 8,
            self::ArchiveFallbacks => 9,
            self::TimeoutHandling => 10,
            self::Finalization => 11,
            self::WorkspaceCleanup => 12,
        };
    }

    public function rankNative(): int
    {
        if (($ffi = self::ffi()) === null) {
            return $this->rankPhp();
        }
        try {
            // @phpstan-ignore method.notFound
            $rank = $ffi->nntmux_aenum_rank(self::FFI_KIND, $this->ordinal());
        } catch (Throwable) {
            return $this->rankPhp();
        }

        return \is_int($rank) && $rank >= 0 ? $rank : $this->rankPhp();
    }

    /**
     * Resolve a trimmed, case-insensitive backing value that may use `_` for `-`.
     */
    public static function tryFromNormalized(string $value): ?self
    {
        return self::usesRustFfi() ? self::tryFromNormalizedNative($value) : self::tryFromNormalizedPhp($value);
    }

    public static function tryFromNormalizedPhp(string $value): ?self
    {
        return self::tryFrom(str_replace('_', '-', strtolower(trim($value))));
    }

    public static function tryFromNormalizedNative(string $value): ?self
    {
        if (($ffi = self::ffi()) === null) {
            return self::tryFromNormalizedPhp($value);
        }
        try {
            // @phpstan-ignore method.notFound
            $index = $ffi->nntmux_aenum_resolve(self::FFI_KIND, $value, \strlen($value));
        } catch (Throwable) {
            return self::tryFromNormalizedPhp($value);
        }
        if ($index === -2) {
            return null;
        }

        return \is_int($index) && $index >= 0
            ? (self::cases()[$index] ?? self::tryFromNormalizedPhp($value))
            : self::tryFromNormalizedPhp($value);
    }

    /**
     * Declaration index of this case, as the Rust tables order it.
     */
    private function ordinal(): int
    {
        return match ($this) {
            self::WorkspacePreparation => 0,
            self::NzbParsing => 1,
            self::ReleaseInitialization => 2,
            self::MessageIdSelection => 3,
            self::MediaSourcePlanning => 4,
            self::DirectDownloads => 5,
            self::ArchiveDownloads => 6,
            self::ExtractedFiles => 7,
            self::MediaSources => 8,
            self::ArchiveFallbacks => 9,
            self::TimeoutHandling => 10,
            self::Finalization => 11,
            self::WorkspaceCleanup => 12,
        };
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
