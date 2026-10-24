<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * Service for CLI output during additional processing.
 * Centralizes all echo/output functionality.
 */
class ConsoleOutputService
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_console_status_format(uint32_t kind, int64_t a, int64_t b, const char *text, size_t text_len, char *out, size_t cap);
        C;

    public const int STATUS_RELEASE_START = 0;

    public const int STATUS_COMPRESSED_FAILURE = 1;

    public const int STATUS_PAR2_MAPPED = 2;

    public const int STATUS_MEDIA_SOURCE = 3;

    public const int STATUS_RELEASE_TIMEOUT = 4;

    public const int STATUS_RELEASE_TIMEOUT_DELETED = 5;

    public const int STATUS_RELEASE_DELETED = 6;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public function __construct(
        private readonly bool $echoCLI = false
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Status label for one of the STATUS_* kinds. `$a` is the release id or count, `$b` the
     * size, elapsed seconds or timeout count, and `$text` the media source kind.
     */
    public static function formatStatus(int $kind, int $a = 0, int $b = 0, string $text = ''): string
    {
        return (self::$useRustFfi ? self::formatStatusNative($kind, $a, $b, $text) : null) ?? self::formatStatusPhp($kind, $a, $b, $text);
    }

    public static function formatStatusPhp(int $kind, int $a = 0, int $b = 0, string $text = ''): string
    {
        return match ($kind) {
            self::STATUS_RELEASE_START => PHP_EOL.'['.$a.']['.human_filesize($b, 1).']',
            self::STATUS_COMPRESSED_FAILURE => 'fC'.$a,
            self::STATUS_PAR2_MAPPED => '(p2n:'.$a.')',
            self::STATUS_MEDIA_SOURCE => '(src:'.$text.')',
            self::STATUS_RELEASE_TIMEOUT => ' Release '.$a.' timed out after '.$b.'s',
            self::STATUS_RELEASE_TIMEOUT_DELETED => ' Release '.$a.' deleted after '.$b.' timeout(s)',
            self::STATUS_RELEASE_DELETED => 'Deleted broken release ID '.$a,
            default => throw new \InvalidArgumentException("Unknown console status kind [{$kind}]."),
        };
    }

    public static function formatStatusNative(int $kind, int $a = 0, int $b = 0, string $text = ''): ?string
    {
        // The Rust labels hard-code the Unix line ending.
        if (PHP_EOL !== "\n") {
            return null;
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 96 + \strlen($text), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_console_status_format($kind, $a, $b, $text, \strlen($text), $scratch, $cap);

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function echo(string $message, string $type = 'primary'): void
    {
        if ($this->echoCLI) {
            cli()->$type($message);
        }
    }

    public function debug(string $message): void
    {
        if ($this->echoCLI && config('app.env') === 'local' && config('app.debug') === true) {
            cli()->debug('DEBUG: '.$message);
        }
    }

    public function echoDescription(int $totalReleases): void
    {
        if ($totalReleases > 1 && $this->echoCLI) {
            $this->echo(
                PHP_EOL.
                'Additional post-processing, started at: '.
                now()->format('D M d, Y G:i a').
                PHP_EOL.
                'Downloaded: (xB)=yEnc article, (cB)=compressed part'.
                PHP_EOL.
                'Failures: fC#=Compressed(part #), fS=Sample, fM=Media(video), fA=Audio, fJ=JPEG, G=Missing group, T=Timeout skip'.
                PHP_EOL.
                'Processing: r=RAR, z=ZIP, (vRAW)=Inline video detected'.
                PHP_EOL.
                'Added: s=Sample image, j=JPEG image, A=Audio sample, a=Audio MediaInfo, v=Video sample'.
                PHP_EOL.
                'Added: m=Video MediaInfo, n=NFO, ^=Inner file details (RAR/ZIP)'.
                '',
                'header'
            );
        }
    }

    public function echoReleaseStart(int $releaseId, int $size): void
    {
        $this->echo(self::formatStatus(self::STATUS_RELEASE_START, $releaseId, $size), 'primaryOver');
    }

    public function setProcessTitle(int $releaseId): void
    {
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title(PHP_BINARY.' '.__DIR__.'/AdditionalProcessingOrchestrator.php ReleaseID: '.$releaseId);
        }
    }

    public function echoCompressedDownload(): void
    {
        $this->echo('(cB)', 'primaryOver');
    }

    public function echoCompressedFailure(int $failCount): void
    {
        $this->echo(self::formatStatus(self::STATUS_COMPRESSED_FAILURE, $failCount), 'warningOver');
    }

    public function echoGroupUnavailable(): void
    {
        $this->echo('G', 'warningOver');
    }

    public function echoSampleDownload(): void
    {
        $this->echo('(sB)', 'primaryOver');
    }

    public function echoSampleFailure(): void
    {
        $this->echo('fS', 'warningOver');
    }

    public function echoMediaInfoDownload(): void
    {
        $this->echo('(mB)', 'primaryOver');
    }

    public function echoMediaInfoFailure(): void
    {
        $this->echo('fM', 'warningOver');
    }

    public function echoAudioDownload(): void
    {
        $this->echo('(aB)', 'primaryOver');
    }

    public function echoAudioFailure(): void
    {
        $this->echo('fA', 'warningOver');
    }

    public function echoJpgDownload(): void
    {
        $this->echo('(jB)', 'primaryOver');
    }

    public function echoJpgFailure(): void
    {
        $this->echo('fJ', 'warningOver');
    }

    public function echoArchiveMarker(string $marker): void
    {
        $this->echo($marker, 'primaryOver');
    }

    public function echoFileInfoAdded(): void
    {
        $this->echo('^', 'primaryOver');
    }

    public function echoSampleCreated(): void
    {
        $this->echo('s', 'primaryOver');
    }

    public function echoJpgSaved(): void
    {
        $this->echo('j', 'primaryOver');
    }

    public function echoAudioSampleCreated(): void
    {
        $this->echo('A', 'primaryOver');
    }

    public function echoAudioInfoAdded(): void
    {
        $this->echo('a', 'primaryOver');
    }

    public function echoVideoCreated(): void
    {
        $this->echo('v', 'primaryOver');
    }

    public function echoMediaInfoAdded(): void
    {
        $this->echo('m', 'primaryOver');
    }

    public function echoNfoFound(): void
    {
        $this->echo('n', 'primaryOver');
    }

    public function echoInlineVideo(): void
    {
        $this->echo('(vRAW)', 'primaryOver');
    }

    public function echoPar2Mapped(int $files): void
    {
        $this->echo(self::formatStatus(self::STATUS_PAR2_MAPPED, $files), 'primaryOver');
    }

    public function echoMediaSource(string $kind): void
    {
        $this->echo(self::formatStatus(self::STATUS_MEDIA_SOURCE, text: $kind), 'primaryOver');
    }

    public function echoReleaseTimeout(int $releaseId, int $elapsedSeconds): void
    {
        $this->echo('T', 'warningOver');
        $this->echo(self::formatStatus(self::STATUS_RELEASE_TIMEOUT, $releaseId, $elapsedSeconds), 'warning');
    }

    public function echoReleaseTimeoutDeleted(int $releaseId, int $timeoutCount): void
    {
        $this->echo('T', 'warningOver');
        $this->echo(self::formatStatus(self::STATUS_RELEASE_TIMEOUT_DELETED, $releaseId, $timeoutCount), 'warning');
    }

    public function warning(string $message): void
    {
        $this->echo($message, 'warning');
    }

    public function echoReleaseDeleted(int $releaseId): void
    {
        $this->echo(self::formatStatus(self::STATUS_RELEASE_DELETED, $releaseId), 'warningOver');
    }

    public function endOutput(): void
    {
        if ($this->echoCLI) {
            echo PHP_EOL;
        }
    }

    public function isEnabled(): bool
    {
        return $this->echoCLI;
    }
}
