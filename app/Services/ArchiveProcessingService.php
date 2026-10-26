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

    // ========================================================================
    // WAVE 65.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 3.94x common mean, 3.42x common p99, 3.11x uncommon mean and 2.68x uncommon p99; see RustUsenetDownloadAdminSnapshotAnidbArchiveBlacklistW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    private const string W65_FFI_CDEF = 'ssize_t nntmux_w654_archive_processing_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w65ArchiveProcessingPlanBatch() record. */
    public const int W65_ROW_WORDS = 4;

    /** int64 summary words after the w65ArchiveProcessingPlanBatch() records (none for an empty batch). */
    public const int W65_TRAILER_WORDS = 12;

    private const int W65_VALUE_CAP = (1 << 40) - 1;

    private const int W65_TOTAL_CAP = 1 << 62;

    /**
     * Archive processing rows for [archive name, leading bytes, LF-separated member names,
     * inspection error, encrypted flag, data bytes, nesting depth, maximum depth] items.
     *
     * The kind is the signature (2 RAR5, 1 RAR4, 3 7z, 4 ZIP), else the name's extension (1
     * `.rar`/`.rNN`, 3 `.7z`, 4 `.zip`, 5 `.NNN`, 0 other). Members are trimmed, empty ones are
     * skipped and each is classed by fileNameClassPhp() (W53 class codes); a member archive is a
     * first volume when it is `.rar` (but not `.partN.rar` with N != 1), `.001`, `.7z` or
     * `.zip`. Data bytes clamp to [0, 2^40) and depths to [0, 255]. Decisions: 1 encrypted (the
     * flag, or "password"/"encrypt" in the error), 2 error, 3 no members, 4 extract nested
     * first volumes (depth below the maximum), 5 inspect media (video, audio or images), 6 list
     * only.
     *
     * Rows [decision | kind << 4 | flags << 8 (1 signature, 2 signature/extension mismatch, 4
     * nesting limit reached, 8 error, 16 encrypted flag, 32 password error, 64 no data) |
     * members << 16 | first volumes << 32 (both clamped to 2^16 - 1) | depth << 48, class counts
     * (8 bits each, saturating at 255, class code k at bit 8k), fnv1a32(strtolower(name)) (0
     * when empty), data bytes], then the trailer [records, the six decision counts, members,
     * first volumes, video members, data bytes (saturating at 2^62), FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w65ArchiveProcessingPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65ArchiveProcessingPlanBatchNative($items) : self::w65ArchiveProcessingPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w65ArchiveProcessingPlanBatchPhp(array $items): array
    {
        self::w65Items($items);
        if ($items === []) {
            return [];
        }
        $out = [];
        $t = [\count($items), 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$name, $head, $members, $error, $encrypted, $bytes, $depth, $maxDepth]) {
            $error = trim($error);
            $encrypted = $encrypted !== 0;
            $bytes = max(0, min($bytes, self::W65_VALUE_CAP));
            $depth = max(0, min($depth, 255));
            $maxDepth = max(0, min($maxDepth, 255));
            $magic = match (true) {
                str_starts_with($head, "Rar!\x1a\x07\x01\x00") => 2,
                str_starts_with($head, "Rar!\x1a\x07\x00") => 1,
                str_starts_with($head, "7z\xBC\xAF\x27\x1C") => 3,
                str_starts_with($head, "PK\x03\x04") => 4,
                default => 0,
            };
            $dot = strrpos($name, '.');
            $extension = $dot === false ? '' : strtolower(substr($name, $dot + 1));
            $byName = match (true) {
                $extension === 'rar' || preg_match('/^r\d{2}\z/', $extension) === 1 => 1,
                $extension === '7z' => 3,
                $extension === 'zip' => 4,
                preg_match('/^\d{3}\z/', $extension) === 1 => 5,
                default => 0,
            };
            $kind = $magic !== 0 ? $magic : $byName;
            $mismatch = $magic !== 0 && $byName !== 0 && $byName !== 5 && ($magic === 2 ? 1 : $magic) !== $byName;
            $password = stripos($error, 'password') !== false || stripos($error, 'encrypt') !== false;
            $classes = [0, 0, 0, 0, 0, 0, 0];
            $listed = $first = 0;
            foreach (explode("\n", $members) as $member) {
                $member = trim($member);
                if ($member === '') {
                    continue;
                }
                $listed++;
                $class = self::W53_CLASS_CODES[self::fileNameClassPhp($member)];
                $classes[$class]++;
                if ($class === 6 && self::w65FirstVolume($member)) {
                    $first++;
                }
            }
            $decision = match (true) {
                $encrypted || $password => 1,
                $error !== '' => 2,
                $listed === 0 => 3,
                $first > 0 && $depth < $maxDepth => 4,
                $classes[1] + $classes[2] + $classes[3] > 0 => 5,
                default => 6,
            };
            $flags = ($magic !== 0 ? 1 : 0) | ($mismatch ? 2 : 0) | ($first > 0 && $depth >= $maxDepth ? 4 : 0) | ($error !== '' ? 8 : 0)
                | ($encrypted ? 16 : 0) | ($password ? 32 : 0) | ($bytes === 0 ? 64 : 0);
            $packed = 0;
            foreach ($classes as $k => $n) {
                $packed |= min($n, 255) << (8 * $k);
            }
            $word = $decision | $kind << 4 | $flags << 8 | min($listed, 0xFFFF) << 16 | min($first, 0xFFFF) << 32 | $depth << 48;
            $hash = self::w65Fnv(strtolower($name));
            array_push($out, $word, $packed, $hash, $bytes);
            $t[$decision]++;
            $t[7] += $listed;
            $t[8] += $first;
            $t[9] += $classes[1];
            $t[10] = min($t[10] + $bytes, self::W65_TOTAL_CAP);
            $value = $word ^ $packed ^ $hash << 4;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $t[11] = $digest;

        return [...$out, ...$t];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: int, 7: int}>  $items
     * @return list<int>
     */
    public static function w65ArchiveProcessingPlanBatchNative(array $items): array
    {
        self::w65Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w65Join([array_column($items, 0), array_column($items, 1), array_column($items, 2), array_column($items, 3)]);
        $ints = pack('P*', ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7));

        return self::w65Call($blob, $twin, $ints, $count, $count * self::W65_ROW_WORDS + self::W65_TRAILER_WORDS)
            ?? self::w65ArchiveProcessingPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w65Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new \InvalidArgumentException('W65 archive processing plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 8 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_string($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7])) {
                throw new \InvalidArgumentException('W65 archive processing plan batch items must be [string archive name, string head bytes, string member names, string error, int encrypted, int data bytes, int depth, int maximum depth] lists.');
            }
        }
    }

    public static function usesW65RustFfi(): bool
    {
        $enabled = self::w65RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W65_RUST_FFI_DEFAULT;
    }

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('enabled', false);
    }

    /** Drop the W65 override, binding, output buffer and declined-call count; routing returns to W65_RUST_FFI_DEFAULT. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    /**
     * Run $callback with the W65 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('enabled');
        self::w65RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w65Join(array $columns): array
    {
        $values = array_merge(...$columns);
        $joined = implode("\0", $values);

        return substr_count($joined, "\0") === \count($values) - 1 ? [$joined, ''] : [$joined, implode("\x01", $values)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w65Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w65RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w65', self::W65_FFI_CDEF) ?? false;
            self::w65RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w65RustFfiState('out', $out);
                self::w65RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W65_FFI_CDEF.)
            $written = $ffi->nntmux_w654_archive_processing_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w65RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W65 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** First volume of a set: `.rar` unless `.partN.rar` with N != 1, `.001`, `.7z` or `.zip`. */
    private static function w65FirstVolume(string $name): bool
    {
        $lower = strtolower($name);
        $dot = strrpos($lower, '.');
        if ($dot === false) {
            return false;
        }
        $extension = substr($lower, $dot + 1);
        if ($extension === 'rar') {
            return preg_match('/\.part0*(\d+)\.rar\z/', $lower, $m) === 1 ? $m[1] === '1' : true;
        }

        return $extension === '001' || $extension === '7z' || $extension === 'zip';
    }

    /** FNV-1a 32 of $bytes as an unsigned int, 0 for an empty string. */
    private static function w65Fnv(string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
