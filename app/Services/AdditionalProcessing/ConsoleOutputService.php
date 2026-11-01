<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    public const bool W416_RUST_FFI_DEFAULT = false;

    private const string W416_FFI_CDEF = 'int32_t nntmux_w416_additional_candidate_diag_orch_console_nzb(uint8_t operation, const char *input, size_t input_len, char *out_buf, size_t out_cap);';

    private static ?bool $w416UseRustFfi = null;

    private static FFI|false|null $w416Ffi = null;

    public static function useW416RustFfi(bool $enable = true): void
    {
        self::$w416UseRustFfi = $enable;
    }

    public static function usesW416RustFfi(): bool
    {
        return self::$w416UseRustFfi ?? self::W416_RUST_FFI_DEFAULT;
    }

    public static function resetW416RustFfi(): void
    {
        self::$w416UseRustFfi = null;
        self::$w416Ffi = null;
    }

    /** @return list<int>|null */
    private static function w416Slots(int $operation, string $input, int $count): ?array
    {
        if (\strlen($input) > 16_777_216) {
            return null;
        }
        try {
            self::$w416Ffi ??= RustPipelineLibrary::resolveDomain('additional', self::W416_FFI_CDEF) ?? false;
            if (self::$w416Ffi === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = self::$w416Ffi->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = self::$w416Ffi->nntmux_w416_additional_candidate_diag_orch_console_nzb($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            foreach ($values as $value) {
                if (! \is_int($value) || $value < 0) {
                    return null;
                }
            }

            return array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    private static function w416Add(int $left, int $right): int
    {
        return $left > PHP_INT_MAX - $right ? PHP_INT_MAX : $left + $right;
    }

    /**
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, warnings: int, errors: int, progress: int, text_bytes: int, truncated: bool, invalid: int}
     */
    public static function summarizeConsoleEventBatch(array $events, int $maxBytes = 65536): array
    {
        return self::usesW416RustFfi()
            ? self::summarizeConsoleEventBatchNative($events, $maxBytes)
            : self::summarizeConsoleEventBatchPhp($events, $maxBytes);
    }

    /**
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, warnings: int, errors: int, progress: int, text_bytes: int, truncated: bool, invalid: int}
     */
    public static function summarizeConsoleEventBatchPhp(array $events, int $maxBytes = 65536): array
    {
        $result = ['total' => \count($events), 'warnings' => 0, 'errors' => 0, 'progress' => 0, 'text_bytes' => 0, 'truncated' => false, 'invalid' => 0];
        foreach ($events as $row) {
            if (! \is_array($row) || ! \is_string($row['kind'] ?? null) || ! \is_string($row['text'] ?? null)) {
                $result['invalid']++;

                continue;
            }
            $key = match ($row['kind']) {
                'warning' => 'warnings',
                'error' => 'errors',
                'progress' => 'progress',
                default => null,
            };
            if ($key !== null) {
                $result[$key]++;
            }
            $result['text_bytes'] = self::w416Add($result['text_bytes'], \strlen($row['text']));
        }
        $result['truncated'] = $result['text_bytes'] > max(0, $maxBytes);

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $events
     * @return array{total: int, warnings: int, errors: int, progress: int, text_bytes: int, truncated: bool, invalid: int}
     */
    public static function summarizeConsoleEventBatchNative(array $events, int $maxBytes = 65536): array
    {
        $total = \count($events);
        if ($total > 100_000) {
            return self::summarizeConsoleEventBatchPhp($events, $maxBytes);
        }
        $frame = pack('qqV', $maxBytes, 0, $total);
        foreach ($events as $row) {
            if (! \is_array($row) || ! \is_string($row['kind'] ?? null) || ! \is_string($row['text'] ?? null)) {
                return self::summarizeConsoleEventBatchPhp($events, $maxBytes);
            }
            $frame .= pack('qq', match ($row['kind']) {
                'warning' => 1, 'error' => 2, 'progress' => 3, default => 0
            }, \strlen($row['text']));
        }
        $slots = self::w416Slots(4, $frame, 7);
        if ($slots === null || $slots[0] !== $total || $slots[6] !== 0 || $slots[5] > 1 || $slots[5] !== (int) ($slots[4] > max(0, $maxBytes)) || $slots[1] > $total || $slots[2] > $total || $slots[3] > $total || $total < $slots[1] + $slots[2] + $slots[3]) {
            return self::summarizeConsoleEventBatchPhp($events, $maxBytes);
        }

        return [
            'total' => $slots[0],
            'warnings' => $slots[1],
            'errors' => $slots[2],
            'progress' => $slots[3],
            'text_bytes' => $slots[4],
            'truncated' => $slots[5] === 1,
            'invalid' => $slots[6],
        ];
    }

    // ---------------------------------------------------------------------
    // Wave 52.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 2.62x, p99 1.79x; uncommon mean 2.02x, p99 1.74x); see RustWorkPlannerArchiveConfigConsoleMediaExtractW52ParityTest. */
    private const bool W52_RUST_FFI_DEFAULT = true;

    public const string W52_FFI_CDEF = <<<'C'
        ssize_t nntmux_w524_console_line_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** consoleLineBatch() flags. */
    public const int W52_LINE_BLANK = 1;

    public const int W52_LINE_ANSI = 2;

    public const int W52_LINE_CARRIAGE_RETURN = 4;

    public const int W52_LINE_NEWLINE_END = 8;

    public const int W52_LINE_ERROR = 16;

    public const int W52_LINE_WARNING = 32;

    private const string W52_CSI_PATTERN = '/\x1b\[[0-9;?]*[ -\/]*[@-~]/';

    /**
     * Measure console output lines, three ints each: [flags, bytes left after stripping ANSI
     * CSI sequences, newline count]. Flags: 1 blank after trim(), 2 holds a CSI sequence, 4
     * carriage return (progress redraw), 8 ends with a newline, 16 'error' and 32 'warn'
     * (ASCII case-insensitive).
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function consoleLineBatch(array $lines): array
    {
        return self::usesW52RustFfi()
            ? self::consoleLineBatchNative($lines)
            : self::consoleLineBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function consoleLineBatchNative(array $lines): array
    {
        self::w52AssertList($lines);
        if ($lines === []) {
            return [];
        }

        [$text, $twin] = self::w52Text($lines);

        return self::w52Call($text, $twin, \count($lines))
            ?? self::consoleLineBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function consoleLineBatchPhp(array $lines): array
    {
        self::w52AssertList($lines);
        $out = [];
        foreach ($lines as $line) {
            $visible = (string) preg_replace(self::W52_CSI_PATTERN, '', $line, -1, $escapes);
            $out[] = (trim($line) === '' ? self::W52_LINE_BLANK : 0)
                | ($escapes > 0 ? self::W52_LINE_ANSI : 0)
                | (str_contains($line, "\r") ? self::W52_LINE_CARRIAGE_RETURN : 0)
                | (str_ends_with($line, "\n") ? self::W52_LINE_NEWLINE_END : 0)
                | (stripos($line, 'error') !== false ? self::W52_LINE_ERROR : 0)
                | (stripos($line, 'warn') !== false ? self::W52_LINE_WARNING : 0);
            $out[] = \strlen($visible);
            $out[] = substr_count($line, "\n");
        }

        return $out;
    }

    public static function usesW52RustFfi(): bool
    {
        $enabled = self::w52RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W52_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W52 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('enabled');
        self::w52RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w52RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: encoded text and packed int64 columns in, 3 int64
     * words back per record. Null when the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w52Call(string $text, string $ints, int $count): ?array
    {
        try {
            $state = self::w52RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w52', self::W52_FFI_CDEF) ?? false;
                self::w52RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * 3;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w52RustFfiState('out', $out);
                self::w52RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W52_FFI_CDEF.)
            $written = $ffi->nntmux_w524_console_line_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Text column encoding: NUL-joined values and, when a value holds a NUL byte, the same
     * values joined with 0x01 so Rust finds the separators where the two buffers differ.
     *
     * @param  list<string>  $values
     * @return array{0: string, 1: string}
     */
    private static function w52Text(array $values): array
    {
        $joined = implode("\0", $values);

        return [$joined, substr_count($joined, "\0") === \count($values) - 1 ? '' : implode("\x01", $values)];
    }

    /** @param array<array-key, mixed> $values */
    private static function w52AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W52 batch input must be a list.');
        }
    }

    /**
     * W52 routing override and cached binding. Readonly classes cannot declare static
     * properties, so they live in a function static: no arguments returns the state, one
     * argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;
        }

        return $state[$op] ?? null;
    }
}
