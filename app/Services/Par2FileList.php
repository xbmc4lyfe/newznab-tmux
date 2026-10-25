<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

final class Par2FileList
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par2fl_extract_files(const char *data, size_t len, char *out, size_t out_cap);
        C;

    /** `ArchiveReader::$maxReadBytes` default. */
    public const int DEFAULT_MAX_READ_BYTES = 1048576;

    private static ?FFI $ffi = null;

    private static bool $ffiResolved = false;

    private static bool $useRustFfi = true;

    /**
     * Parse raw PAR2 data into getFiles() entries and descriptor completeness, or null when
     * Par2Info reports a parse error.
     *
     * Uses the Rust parser: paired single-call benchmarks measured 7.55x mean / 7.55x p99 on
     * typical index files and 9.92x mean / 12.36x p99 on large or many-file sets.
     *
     * @return array{files: array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>, complete: bool}|null
     */
    public static function extractFiles(string $data, int $maxReadBytes = self::DEFAULT_MAX_READ_BYTES): ?array
    {
        return self::extractFilesNative($data, $maxReadBytes);
    }

    /**
     * @return array{files: array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>, complete: bool}|null
     */
    public static function extractFilesPhp(string $data, int $maxReadBytes = self::DEFAULT_MAX_READ_BYTES): ?array
    {
        $par2Info = new Par2Info;
        $par2Info->setMaxReadBytes($maxReadBytes);
        $par2Info->setData($data);
        if ($par2Info->error) {
            return null;
        }
        $list = new self;

        return ['files' => $list->getFiles($par2Info), 'complete' => ! $list->hasUnresolvedDescriptors($par2Info)];
    }

    /**
     * @return array{files: array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>, complete: bool}|null
     */
    public static function extractFilesNative(string $data, int $maxReadBytes = self::DEFAULT_MAX_READ_BYTES, bool &$supported = false, ?string $libraryPath = null): ?array
    {
        $supported = false;
        $ffi = self::rustFfi($libraryPath);
        if ($ffi === null) {
            return self::extractFilesPhp($data, $maxReadBytes);
        }
        $len = min(\strlen($data), $maxReadBytes > 0 ? $maxReadBytes : self::DEFAULT_MAX_READ_BYTES);

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $len * 2 + 64, $capacity);
            if ($buffer === null) {
                return self::extractFilesPhp($data, $maxReadBytes);
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_par2fl_extract_files($data, $len, $buffer, $capacity);
            if (\is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return self::extractFilesPhp($data, $maxReadBytes);
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_par2fl_extract_files($data, $len, $buffer, $capacity);
            }
            if (! \is_int($written) || $written < 6 || $written > $capacity) {
                return self::extractFilesPhp($data, $maxReadBytes);
            }
            $payload = FFI::string($buffer, $written);
        } catch (Throwable) {
            return self::extractFilesPhp($data, $maxReadBytes);
        }

        $files = self::decodeFiles($payload);
        if ($files === false) {
            return self::extractFilesPhp($data, $maxReadBytes);
        }
        $supported = true;

        return $files;
    }

    /**
     * Toggle the Rust helpers; disabled, every *Native method falls back to PHP.
     */
    public static function useRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * @return array{files: array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>, complete: bool}|false|null
     */
    private static function decodeFiles(string $payload): array|false|null
    {
        if ($payload[0] !== "\x01") {
            return null;
        }
        $complete = $payload[1] === "\x01";
        $count = unpack('V', $payload, 2)[1];
        $total = \strlen($payload);
        $pos = 6;
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 41 > $total) {
                return false;
            }
            $head = unpack('Cflags/a32id/Pblocks', $payload, $pos);
            $pos += 41;
            if (($head['flags'] & 1) === 0) {
                $files[$head['id']] = ['blocks' => $head['blocks']];

                continue;
            }
            if ($pos + 84 > $total) {
                return false;
            }
            $desc = unpack((($head['flags'] & 2) === 2 ? 'esize' : 'Psize').'/Pnext/a32hash/a32hash16k/Vlen', $payload, $pos);
            $pos += 84;
            $files[$head['id']] = [
                'name' => substr($payload, $pos, $desc['len']),
                'size' => $desc['size'],
                'hash' => $desc['hash'],
                'hash_16K' => $desc['hash16k'],
                'blocks' => $head['blocks'],
                'next_offset' => $desc['next'],
            ];
            $pos += $desc['len'];
        }

        return $pos === $total ? ['files' => $files, 'complete' => $complete] : false;
    }

    private static function rustFfi(?string $libraryPath = null): ?FFI
    {
        if (! self::$useRustFfi) {
            return null;
        }
        if ($libraryPath !== null) {
            return RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF, $libraryPath);
        }
        if (! self::$ffiResolved) {
            self::$ffiResolved = true;
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF);
        }

        return self::$ffi;
    }

    /**
     * Report declared file identities without a usable description in their recovery set.
     * Descriptor-only packets are sufficient; IFSC packets are not required for a named file.
     */
    public function hasUnresolvedDescriptors(Par2Info $par2Info): bool
    {
        if ($par2Info->error) {
            return true;
        }

        $packets = $par2Info->getPackets(true);
        if (! is_array($packets)) {
            return true;
        }

        $required = $described = [];
        foreach ($packets as $packet) {
            $setId = $packet['head_set_id'];
            if ($packet['head_type'] === Par2Info::PACKET_MAIN) {
                foreach (array_merge($packet['rec_file_ids'] ?? [], $packet['other_file_ids'] ?? []) as $fileId) {
                    $fileId = is_array($fileId) ? reset($fileId) : $fileId;
                    if (! is_string($fileId) || $fileId === '') {
                        return true;
                    }
                    $required[$setId][$fileId] = true;
                }
            } elseif ($packet['head_type'] === Par2Info::PACKET_FILEVER) {
                $required[$setId][$packet['file_id']] = true;
            } elseif ($packet['head_type'] === Par2Info::PACKET_FILEDESC) {
                $fileId = $packet['file_id'];
                $required[$setId][$fileId] = true;
                if (! array_key_exists($fileId, $described[$setId] ?? [])) {
                    $name = $packet['file_name'] ?? null;
                    $described[$setId][$fileId] = is_string($name) && trim($name) !== '';
                }
            }
        }

        foreach ($required as $setId => $fileIds) {
            foreach ($fileIds as $fileId => $_) {
                if (! ($described[$setId][$fileId] ?? false)) {
                    return true;
                }
            }
        }

        return $described === [];
    }

    /**
     * Restore descriptor metadata when verification packets preceded it in the recovery set.
     *
     * @return array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>
     */
    public function getFiles(Par2Info $par2Info): array
    {
        if ($par2Info->error) {
            return [];
        }

        $files = $par2Info->getFileList();
        $packets = $par2Info->getPackets();

        if (! is_array($packets)) {
            return $files;
        }

        foreach ($packets as $packet) {
            if ($packet['head_type'] !== Par2Info::PACKET_FILEDESC) {
                continue;
            }

            $fileId = $packet['file_id'];
            if (isset($files[$fileId]['name'])) {
                continue;
            }

            $files[$fileId] = [
                'name' => ! empty($packet['file_name']) ? $packet['file_name'] : 'Unknown',
                'size' => $packet['file_length'] ?? 0,
                'hash' => $packet['file_hash'],
                'hash_16K' => $packet['file_hash_16K'],
                'blocks' => $files[$fileId]['blocks'] ?? 0,
                'next_offset' => $packet['next_offset'],
            ];
        }

        return $files;
    }

    /** Disabled: common p99 missed 1.5x in two of five paired runs (1.20x, 1.54x, 2.27x, 1.49x, 2.24x; see the W42 parity test). */
    public const bool W424_RUST_FFI_DEFAULT = false;

    public const string W424_FFI_CDEF = <<<'C'
        ssize_t nntmux_w424_par2_file_list_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W424_SUMMARY_KEYS = ['total_files', 'empty_name_count', 'par2_count', 'rar_volume_count', 'split_archive_count', 'video_count', 'sample_count', 'path_count'];

    private const array W424_VIDEO_EXTENSIONS = ['mkv' => true, 'mp4' => true, 'avi' => true, 'm2ts' => true, 'ts' => true, 'wmv' => true, 'mov' => true, 'mpg' => true, 'vob' => true];

    private static ?bool $w424UseRustFfi = null;

    private static FFI|false|null $w424Ffi = null;

    public static function useW424RustFfi(bool $enable = true): void
    {
        self::$w424UseRustFfi = $enable;
    }

    public static function usesW424RustFfi(): bool
    {
        return self::$w424UseRustFfi ?? self::W424_RUST_FFI_DEFAULT;
    }

    public static function resetW424RustFfi(): void
    {
        self::$w424UseRustFfi = null;
        self::$w424Ffi = null;
    }

    /**
     * Summarize PAR2 file description names by the case-insensitive
     * extension after the last dot (par2, rar/rNN volumes, NNN/7z/zip
     * split archives, video containers), SAMPLE tokens and slash paths.
     * Entries come from strings, integers, or the name or filename array
     * field; other values (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2FileNameBatch(array $entries): array
    {
        return self::usesW424RustFfi()
            ? self::summarizePar2FileNameBatchNative($entries)
            : self::summarizePar2FileNameBatchPhp($entries);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2FileNameBatchNative(array $entries): array
    {
        return self::w424Call(self::w424Entries($entries)) ?? self::summarizePar2FileNameBatchPhp($entries);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     * @return array<string, int>
     */
    public static function summarizePar2FileNameBatchPhp(array $entries): array
    {
        $summary = array_fill_keys(self::W424_SUMMARY_KEYS, 0);
        foreach (self::w424Entries($entries) as $entry) {
            $summary['total_files']++;
            $entry = trim($entry);
            if ($entry === '') {
                $summary['empty_name_count']++;

                continue;
            }
            $dot = strrpos($entry, '.');
            $extension = $dot === false ? '' : strtolower(substr($entry, $dot + 1));
            $summary['par2_count'] += (int) ($extension === 'par2');
            $summary['rar_volume_count'] += (int) ($extension === 'rar' || preg_match('/^r\d{2}\z/', $extension) === 1);
            $summary['split_archive_count'] += (int) ($extension === '7z' || $extension === 'zip' || preg_match('/^\d{3}\z/', $extension) === 1);
            $summary['video_count'] += (int) isset(self::W424_VIDEO_EXTENSIONS[$extension]);
            $summary['sample_count'] += (int) (preg_match('/(?<![A-Za-z0-9])SAMPLE(?![A-Za-z0-9])/i', $entry) === 1);
            $summary['path_count'] += (int) (strpbrk($entry, '/\\') !== false);
        }

        return $summary;
    }

    /** Only integer and string entries participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function w424Entries(array $input): array
    {
        $entries = [];
        foreach ($input as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? $item['filename'] ?? null;
            }
            $entries[] = is_string($item) || is_int($item) ? (string) $item : '';
        }

        return $entries;
    }

    /** @param list<string> $entries
     * @return array<string, int>|null
     */
    private static function w424Call(array $entries): ?array
    {
        try {
            $input = serialize([$entries]);
            if (strlen($input) > 16777216) {
                return null;
            }
            self::$w424Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w424', self::W424_FFI_CDEF) ?? false;
            $ffi = self::$w424Ffi;
            if ($ffi === false) {
                return null;
            }
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
            $written = $ffi->nntmux_w424_par2_file_list_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W424_FFI_CDEF.)
                $written = $ffi->nntmux_w424_par2_file_list_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W424_SUMMARY_KEYS || $result['total_files'] !== count($entries)) {
                return null;
            }
            $summary = [];
            foreach (self::W424_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > count($entries)) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Wave 51.4 batch helper
    // ---------------------------------------------------------------------

    /** Enabled: minimum of three paired runs cleared every 1.5x gate (common mean 3.30x, p99 2.99x; uncommon mean 3.60x, p99 3.32x); see RustIsbnOpenLibPar2ListProcessorNfoW51ParityTest. */
    private const bool W51_RUST_FFI_DEFAULT = true;

    public const string W51_FFI_CDEF = <<<'C'
        ssize_t nntmux_w514_par2_file_name_batch(const char *text, size_t text_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** File name flags returned by fileNameBatch(). */
    public const int W51_EMPTY_NAME = 1;

    public const int W51_PAR2 = 2;

    public const int W51_RAR_VOLUME = 4;

    public const int W51_SPLIT_ARCHIVE = 8;

    public const int W51_VIDEO = 16;

    public const int W51_SAMPLE = 32;

    public const int W51_PATH = 64;

    private static ?bool $useW51RustFfi = null;

    private static FFI|false|null $w51Ffi = null;

    private static ?CData $w51Out = null;

    private static int $w51OutCap = 0;

    public static function usesW51RustFfi(): bool
    {
        return self::$useW51RustFfi ?? self::W51_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W51 batch dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW51RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::$useW51RustFfi;
        self::$useW51RustFfi = $enabled;
        try {
            return $callback();
        } finally {
            self::$useW51RustFfi = $previous;
        }
    }

    /**
     * Classify PAR2 file description names, two ints each: [flags, offset << 32 | length of
     * the extension after the last dot in the raw name, or -1 without a dot or name]. Flags
     * follow summarizePar2FileNameBatchPhp() on the trimmed name: 1 empty, 2 par2, 4 rar or
     * rNN volume, 8 7z/zip/NNN split archive, 16 video container, 32 SAMPLE token, 64 slash
     * path.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function fileNameBatch(array $names): array
    {
        return self::usesW51RustFfi()
            ? self::fileNameBatchNative($names)
            : self::fileNameBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function fileNameBatchNative(array $names): array
    {
        self::w51AssertList($names);
        if ($names === []) {
            return [];
        }

        return self::w51Call(implode("\0", $names), '', \count($names))
            ?? self::fileNameBatchPhp($names);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    public static function fileNameBatchPhp(array $names): array
    {
        self::w51AssertList($names);
        $out = [];
        foreach ($names as $raw) {
            $entry = trim($raw);
            if ($entry === '') {
                array_push($out, self::W51_EMPTY_NAME, -1);

                continue;
            }
            $dot = strrpos($entry, '.');
            $extension = $dot === false ? '' : strtolower(substr($entry, $dot + 1));
            $flags = 0;
            if ($extension === 'par2') {
                $flags |= self::W51_PAR2;
            }
            if ($extension === 'rar' || preg_match('/^r\d{2}\z/', $extension) === 1) {
                $flags |= self::W51_RAR_VOLUME;
            }
            if ($extension === '7z' || $extension === 'zip' || preg_match('/^\d{3}\z/', $extension) === 1) {
                $flags |= self::W51_SPLIT_ARCHIVE;
            }
            if (isset(self::W424_VIDEO_EXTENSIONS[$extension])) {
                $flags |= self::W51_VIDEO;
            }
            if (preg_match('/(?<![A-Za-z0-9])SAMPLE(?![A-Za-z0-9])/i', $entry) === 1) {
                $flags |= self::W51_SAMPLE;
            }
            if (strpbrk($entry, '/\\') !== false) {
                $flags |= self::W51_PATH;
            }
            $out[] = $flags;
            $out[] = $dot === false ? -1 : ((strspn($raw, " \t\n\r\0\x0B") + $dot + 1) << 32) | (\strlen($entry) - $dot - 1);
        }

        return $out;
    }

    /**
     * One FFI crossing for the batch: NUL-joined names, two int64 words back per record. Null when
     * the library is missing or declines.
     *
     * @return list<int>|null
     */
    private static function w51Call(string $text, string $ints, int $count): ?array
    {
        try {
            self::$w51Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w51', self::W51_FFI_CDEF) ?? false;
            $ffi = self::$w51Ffi;
            if ($ffi === false) {
                return null;
            }
            $need = $count * 2;
            $out = self::$w51Out;
            if ($out === null || self::$w51OutCap < $need) {
                self::$w51OutCap = max(1024, $need);
                $out = self::$w51Out = $ffi->new('int64_t['.self::$w51OutCap.']');
            }
            // @phpstan-ignore method.notFound (Declared by W51_FFI_CDEF.)
            $written = $ffi->nntmux_w514_par2_file_name_batch($text, \strlen($text), $ints, \strlen($ints), $count, $out, self::$w51OutCap);
            if ($written !== $need) {
                return null;
            }
            $values = unpack('P*', FFI::string($out, $need * 8));

            return $values === false ? null : array_values($values);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<array-key, mixed> $values */
    private static function w51AssertList(array $values): void
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('W51 batch input must be a list.');
        }
    }
}
