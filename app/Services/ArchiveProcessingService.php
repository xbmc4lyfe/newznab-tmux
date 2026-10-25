<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\ArchiveInfo;
use FFI;
use FFI\CData;
use Throwable;

class ArchiveProcessingService
{
    public const string FFI_CDEF = <<<'C'
        int32_t nntmux_aps_analysis_code(int32_t set_data_ok, const char *error, size_t error_len, int32_t encrypted_prop, int32_t summary_flag_present, int64_t summary_flag);
        C;

    public const bool W466_RUST_FFI_DEFAULT = false;

    public const string W466_FFI_CDEF = <<<'C'
        int32_t nntmux_w466_archive_file_summary(const char *names, size_t names_len, size_t name_count, const char *sizes, size_t sizes_len, int64_t *out, size_t out_count);
        C;

    /** Lower-case extensions (after the last dot) counted by summarizeFileList(). */
    private const array W466_EXTENSION_CLASSES = [
        'mkv' => 'video', 'mp4' => 'video', 'avi' => 'video', 'm4v' => 'video', 'mov' => 'video', 'wmv' => 'video',
        'ts' => 'video', 'm2ts' => 'video', 'mpg' => 'video', 'mpeg' => 'video', 'vob' => 'video', 'webm' => 'video',
        'mp3' => 'audio', 'flac' => 'audio', 'm4a' => 'audio', 'aac' => 'audio', 'ogg' => 'audio', 'wav' => 'audio',
        'wma' => 'audio', 'ape' => 'audio', 'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image',
        'webp' => 'image', 'bmp' => 'image', 'nfo' => 'text', 'txt' => 'text', 'diz' => 'text', 'sfv' => 'text',
        'par2' => 'par2', 'rar' => 'archive', 'zip' => 'archive', '7z' => 'archive',
    ];

    // PHP remains the production default until benchmarks qualify Rust.
    public static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    private static ?bool $w466RustFfi = null;

    private static FFI|false|null $w466Ffi = null;

    private static ?CData $w466Summary = null;

    public function __construct(private readonly ArchiveInfo $archiveInfo) {}

    public static function setUseRustFfi(bool $useRustFfi): void
    {
        self::$useRustFfi = $useRustFfi;
    }

    public static function additionalFfi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    private static function w466Ffi(): ?FFI
    {
        if (self::$w466Ffi === null) {
            self::$w466Ffi = RustPipelineLibrary::resolveDomain('additional', self::W466_FFI_CDEF) ?? false;
        }

        return self::$w466Ffi === false ? null : self::$w466Ffi;
    }

    public static function useW466RustFfi(?bool $enabled): void
    {
        self::$w466RustFfi = $enabled;
    }

    public static function usesW466RustFfi(): bool
    {
        return self::$w466RustFfi ?? self::W466_RUST_FFI_DEFAULT;
    }

    public static function resetW466RustFfi(): void
    {
        self::$w466RustFfi = null;
        self::$w466Ffi = null;
        self::$w466Summary = null;
    }

    /**
     * Analyze compressed data (RAR/ZIP/etc.).
     * Returns an array with: ok(bool), error(?string), summary(?array), is_encrypted(bool)
     *
     * @return array<string, mixed>
     */
    public function analyze(string $compressedData): array
    {
        $ok = $this->archiveInfo->setData($compressedData, true);
        $error = $this->archiveInfo->error;
        if (! $ok || $error !== '') {
            return self::normalizeAnalysis($ok, $error, false, null);
        }
        $summary = $this->archiveInfo->getSummary(true);

        return self::normalizeAnalysis(true, $error, ! empty($this->archiveInfo->isEncrypted), $summary);
    }

    /**
     * Normalize an archive inspection: `$archiveError` is ArchiveInfo's error after
     * setData(), `$isEncryptedProp` is `! empty($archiveInfo->isEncrypted)`.
     *
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysis(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        return self::$useRustFfi
            ? self::normalizeAnalysisNative($setDataOk, $archiveError, $isEncryptedProp, $summary)
            : self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
    }

    /**
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysisPhp(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        if (! $setDataOk) {
            return ['ok' => false, 'error' => $archiveError ?: 'Unknown error', 'summary' => null, 'is_encrypted' => false];
        }
        if ($archiveError !== '') {
            return ['ok' => false, 'error' => $archiveError, 'summary' => null, 'is_encrypted' => false];
        }
        $isEncrypted = $isEncryptedProp || (isset($summary['is_encrypted']) && (int) $summary['is_encrypted'] !== 0);

        return ['ok' => true, 'error' => null, 'summary' => $summary, 'is_encrypted' => $isEncrypted];
    }

    /**
     * @return array{ok: bool, error: mixed, summary: mixed, is_encrypted: bool}
     */
    public static function normalizeAnalysisNative(bool $setDataOk, mixed $archiveError, bool $isEncryptedProp, mixed $summary): array
    {
        $ffi = self::additionalFfi();
        // Non-string errors and non-scalar flags keep PHP's loose semantics.
        if ($ffi === null || ! is_string($archiveError)) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }
        // Like PHP's short-circuit, the summary flag is only cast when the property is falsy.
        $flagPresent = $setDataOk && $archiveError === '' && ! $isEncryptedProp && isset($summary['is_encrypted']);
        if ($flagPresent && ! is_scalar($summary['is_encrypted'])) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }
        $flag = $flagPresent ? (int) $summary['is_encrypted'] : 0;

        try {
            // @phpstan-ignore method.notFound
            $code = $ffi->nntmux_aps_analysis_code(
                $setDataOk ? 1 : 0,
                $archiveError,
                strlen($archiveError),
                $isEncryptedProp ? 1 : 0,
                $flagPresent ? 1 : 0,
                $flag,
            );
        } catch (Throwable) {
            return self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary);
        }

        return match ($code) {
            0, 1 => ['ok' => true, 'error' => null, 'summary' => $summary, 'is_encrypted' => $code === 1],
            2 => ['ok' => false, 'error' => $archiveError, 'summary' => null, 'is_encrypted' => false],
            3 => ['ok' => false, 'error' => 'Unknown error', 'summary' => null, 'is_encrypted' => false],
            default => self::normalizeAnalysisPhp($setDataOk, $archiveError, $isEncryptedProp, $summary),
        };
    }

    /**
     * Counts for an archive listing: entries, encrypted and compressed entries, the
     * `(int)` size total (a float once it overflows) and entries per name class. The
     * variadic signature lets PHP enforce that every entry is an array.
     *
     * @param  array<array-key, mixed>  ...$files
     * @return array{files: int, encrypted: int, compressed: int, bytes: int|float, video: int, audio: int, image: int, text: int, par2: int, archive: int, other: int}
     */
    public static function summarizeFileList(array ...$files): array
    {
        return (self::usesW466RustFfi() ? self::fileListSummaryNative($files) : null) ?? self::fileListSummaryPhp($files);
    }

    /**
     * @param  array<array-key, mixed>  ...$files
     * @return array{files: int, encrypted: int, compressed: int, bytes: int|float, video: int, audio: int, image: int, text: int, par2: int, archive: int, other: int}
     */
    public static function summarizeFileListPhp(array ...$files): array
    {
        return self::fileListSummaryPhp($files);
    }

    /**
     * Rust summary, or null when PHP must summarize (names with NUL bytes, an
     * overflowing total or an unavailable library).
     *
     * @param  array<array-key, mixed>  ...$files
     * @return array{files: int, encrypted: int, compressed: int, bytes: int, video: int, audio: int, image: int, text: int, par2: int, archive: int, other: int}|null
     */
    public static function summarizeFileListNative(array ...$files): ?array
    {
        return self::fileListSummaryNative($files);
    }

    /** Name class used by summarizeFileList(). */
    public static function fileNameClassPhp(string $name): string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return 'other';
        }
        $extension = strtolower(substr($name, $dot + 1));

        return self::W466_EXTENSION_CLASSES[$extension] ?? (preg_match('/^(?:r\d{2}|\d{3})\z/', $extension) === 1 ? 'archive' : 'other');
    }

    /**
     * @param  array<array-key, array<array-key, mixed>>  $files
     * @return array{files: int, encrypted: int, compressed: int, bytes: int|float, video: int, audio: int, image: int, text: int, par2: int, archive: int, other: int}
     */
    private static function fileListSummaryPhp(array $files): array
    {
        $summary = ['files' => 0, 'encrypted' => 0, 'compressed' => 0, 'bytes' => 0, 'video' => 0, 'audio' => 0, 'image' => 0, 'text' => 0, 'par2' => 0, 'archive' => 0, 'other' => 0];
        foreach ($files as $file) {
            $summary['files']++;
            if (! empty($file['pass'])) {
                $summary['encrypted']++;
            }
            if (! empty($file['compressed'])) {
                $summary['compressed']++;
            }
            $summary['bytes'] += (int) ($file['size'] ?? 0);
            $summary[self::fileNameClassPhp((string) ($file['name'] ?? ''))]++;
        }

        return $summary;
    }

    /**
     * @param  array<array-key, array<array-key, mixed>>  $files
     * @return array{files: int, encrypted: int, compressed: int, bytes: int, video: int, audio: int, image: int, text: int, par2: int, archive: int, other: int}|null
     */
    private static function fileListSummaryNative(array $files): ?array
    {
        $ffi = self::$w466Ffi instanceof FFI ? self::$w466Ffi : self::w466Ffi();
        if ($ffi === null) {
            return null;
        }
        $count = \count($files);
        // array_column() keeps null names and skips missing ones, which PHP reads as ''.
        $names = array_column($files, 'name');
        $joined = implode("\0", $names);
        $nameCount = \count($names);
        if (substr_count($joined, "\0") !== max(0, $nameCount - 1)) {
            return null;
        }
        // pack() converts sizes exactly like the (int) cast; missing sizes add nothing.
        $sizes = array_column($files, 'size');
        $packed = $sizes === [] ? '' : pack('q*', ...$sizes);

        try {
            $out = self::$w466Summary ??= $ffi->new('int64_t[8]');
            // @phpstan-ignore method.notFound
            $status = $ffi->nntmux_w466_archive_file_summary($joined, \strlen($joined), $nameCount, $packed, \strlen($packed), $out, 8);
        } catch (Throwable) {
            return null;
        }
        if ($status !== 1) {
            return null;
        }

        return [
            'files' => $count,
            'encrypted' => \count(array_filter(array_column($files, 'pass'))),
            'compressed' => \count(array_filter(array_column($files, 'compressed'))),
            'bytes' => $out[0], 'video' => $out[1], 'audio' => $out[2], 'image' => $out[3],
            'text' => $out[4], 'par2' => $out[5], 'archive' => $out[6],
            'other' => $out[7] + $count - $nameCount,
        ];
    }

    /**
     * Get file list from currently analyzed archive.
     *
     * @return array<string, mixed>
     */
    public function getFileList(): array
    {
        return $this->archiveInfo->getArchiveFileList() ?: [];
    }

    /**
     * Get file contents by name and source from the current archive.
     */
    public function getFileData(string $name, string $source): string|false
    {
        return $this->archiveInfo->getFileData($name, $source);
    }

    /**
     * Extract file by name from the current archive to destination path.
     */
    public function extractFile(string $name, string $destPath): bool
    {
        return $this->archiveInfo->extractFile($name, $destPath);
    }

    // ---------------------------------------------------------------------
    // Wave 53.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 4.24x, p99 3.36x; uncommon mean 3.91x, p99 2.64x); see RustAnidbArchiveMovieBrowseMovieServiceIgdbExW53ParityTest. */
    private const bool W53_RUST_FFI_DEFAULT = true;

    public const string W53_FFI_CDEF = <<<'C'
        ssize_t nntmux_w534_archive_entry_class_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** fileNameClassPhp() classes as archiveEntryClassBatch() codes. */
    private const array W53_CLASS_CODES = ['other' => 0, 'video' => 1, 'audio' => 2, 'image' => 3, 'text' => 4, 'par2' => 5, 'archive' => 6];

    /**
     * Classify many archive entry names at once. Each name yields three words: its
     * fileNameClassPhp() class code (other 0, video 1, audio 2, image 3, text 4, par2 5,
     * archive 6), its volume index (`.partN.rar` N, `.rar` 0, `.rNN` NN + 1, `.NNN` NNN, else -1)
     * and flags: 1 a sample/proof path token, 2 a directory part, 4 a dot-file base name.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function archiveEntryClassBatch(array $names): array
    {
        return self::usesW53RustFfi()
            ? self::archiveEntryClassBatchNative($names)
            : self::archiveEntryClassBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function archiveEntryClassBatchNative(array $names): array
    {
        self::w53AssertList($names);
        if ($names === []) {
            return [];
        }

        return self::w53Call(implode("\0", $names), '', \count($names), 3)
            ?? self::archiveEntryClassBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function archiveEntryClassBatchPhp(array $names): array
    {
        self::w53AssertList($names);
        $out = [];
        foreach ($names as $name) {
            $out[] = self::W53_CLASS_CODES[self::fileNameClassPhp($name)];
            if (preg_match('/\.part0*(\d{1,4})\.rar\z/i', $name, $m) === 1) {
                $out[] = (int) $m[1];
            } else {
                $dot = strrpos($name, '.');
                $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
                $out[] = match (true) {
                    $dot === false => -1,
                    $extension === 'rar' => 0,
                    preg_match('/\Ar\d{2}\z/', $extension) === 1 => (int) substr($extension, 1) + 1,
                    preg_match('/\A\d{3}\z/', $extension) === 1 => (int) $extension,
                    default => -1,
                };
            }
            $slash = strrpos(strtr($name, '\\', '/'), '/');
            $out[] = (preg_match('/(?:\A|[\/\\\\._ -])(?:sample|proof)(?:[\/\\\\._ -]|\z)/i', $name) === 1 ? 1 : 0)
                | ($slash === false ? 0 : 2)
                | (($name[$slash === false ? 0 : $slash + 1] ?? '') === '.' ? 4 : 0);
        }

        return $out;
    }

    public static function usesW53RustFfi(): bool
    {
        $enabled = self::w53RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W53_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W53 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW53RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w53RustFfiState('enabled');
        self::w53RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w53RustFfiState('enabled', $previous);
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w53AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new \InvalidArgumentException('W53 batch input must be a list.');
        }
    }

    /**
     * One FFI crossing for the batch: NUL-joined text fields and packed int64 columns in,
     * `$words` int64 words back per record. Null when the library is missing or declines
     * (a value holding a NUL byte adds a field, which the export rejects).
     *
     * @return list<int>|null
     */
    private static function w53Call(string $text, string $ints, int $count, int $words): ?array
    {
        try {
            $state = self::w53RustFfiState();
            $ffi = $state['ffi'] ?? null;
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w53', self::W53_FFI_CDEF) ?? false;
                self::w53RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $need = $count * $words;
            $out = $state['out'] ?? null;
            $cap = $state['cap'] ?? 0;
            if (! $out instanceof CData || ! \is_int($cap) || $cap < $need) {
                $cap = max(1024, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w53RustFfiState('out', $out);
                self::w53RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W53_FFI_CDEF.)
            $written = $ffi->nntmux_w534_archive_entry_class_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, $cap);
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
     * W53 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w53RustFfiState(?string $op = null, mixed $val = null): mixed
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
