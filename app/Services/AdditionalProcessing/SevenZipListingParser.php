<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Support\RustPipelineLibrary;
use FFI\CData;

/**
 * Incremental parser for `7z l -slt -ba` output, fed in arbitrary chunks.
 *
 * Lines end only at LF, with one optional CR before it, and records end at blank lines.
 * Nothing else counts as a line break: the output is raw UTF-8 bytes, and Unicode-aware
 * line matching such as byte-mode PCRE `\R` would also break at the 0x85 byte inside
 * characters like "Å" (C3 85) or "久" (E4 B9 85).
 *
 * A record is rejected, and the listing marked unreliable, when it can't be read
 * unambiguously: a line that isn't `Key = value`, a property given twice, a Path with
 * control characters, or no Path or Size at all. Such output most likely comes from a
 * file name with a line break in it, so the record boundaries can't be trusted; callers
 * keep the other entries but must not treat the listing as complete.
 */
final class SevenZipListingParser
{
    /** @var list<array{name: string, size: int, date: int, pass: int, compressed: int, crc32?: string}> */
    public array $files = [];

    /** False once any record had to be rejected. */
    public bool $reliable = true;

    private string $partialLine = '';

    /** @var array<string, string> */
    private array $record = [];

    private bool $recordMalformed = false;

    /** @var array<string, int> */
    private array $modifiedCache = [];

    public function __construct(private readonly ?string $libraryPath = null) {}

    public function feed(string $chunk): void
    {
        if ($chunk === '') {
            return;
        }
        if ($this->record === [] && ! $this->recordMalformed && RustPipelineLibrary::available($this->libraryPath)) {
            $this->partialLine .= $chunk;
            if (! str_contains($chunk, "\n") || ! self::hasPotentialBlankLine($this->partialLine)) {
                return;
            }
            $raw = RustPipelineLibrary::sevenZipParseListing($this->partialLine, false, $this->libraryPath);
            if ($raw !== null) {
                if ($raw !== '') {
                    $this->applyRustListing($raw);
                }

                return;
            }
            $pending = $this->partialLine;
            $this->partialLine = '';
            $this->feedPhp($pending);

            return;
        }

        $this->feedPhp($chunk);
    }

    public function feedPhp(string $chunk): void
    {
        $start = 0;
        while (($end = strpos($chunk, "\n", $start)) !== false) {
            $this->line($this->partialLine.substr($chunk, $start, $end - $start));
            $this->partialLine = '';
            $start = $end + 1;
        }
        $this->partialLine .= substr($chunk, $start);
    }

    /**
     * End of output: the last line and record count even without a trailing line break.
     */
    public function finish(): void
    {
        if ($this->record === [] && ! $this->recordMalformed && RustPipelineLibrary::available($this->libraryPath)) {
            if ($this->partialLine === '') {
                return;
            }
            $raw = RustPipelineLibrary::sevenZipParseListing($this->partialLine, true, $this->libraryPath);
            if ($raw !== null) {
                if ($raw !== '') {
                    $this->applyRustListing($raw);
                }
                $this->partialLine = '';

                return;
            }
            $pending = $this->partialLine;
            $this->partialLine = '';
            $this->feedPhp($pending);
            $this->finishPhp();

            return;
        }

        $this->finishPhp();
    }

    public function finishPhp(): void
    {
        if ($this->partialLine !== '') {
            $this->line($this->partialLine);
            $this->partialLine = '';
        }
        $this->endRecord();
    }

    private static function hasPotentialBlankLine(string $buf): bool
    {
        if ($buf === '') {
            return false;
        }
        $first = $buf[0];
        if ($first === "\n" || $first === "\r" || $first === ' ' || $first === "\t") {
            return true;
        }

        return str_contains($buf, "\n\n")
            || str_contains($buf, "\n\r\n")
            || str_contains($buf, "\n ")
            || str_contains($buf, "\n\t");
    }

    private function applyRustListing(string $raw): void
    {
        $tokens = explode("\0", $raw);
        $consumed = (int) $tokens[0];
        if (($tokens[1] ?? '1') === '0') {
            $this->reliable = false;
        }
        $this->partialLine = $consumed >= strlen($this->partialLine)
            ? ''
            : substr($this->partialLine, $consumed);

        $count = count($tokens) - 1;
        for ($i = 2; $i + 4 < $count; $i += 5) {
            $modified = $tokens[$i + 2];
            $date = $modified === '' ? 0 : ($this->modifiedCache[$modified] ??= (int) strtotime($modified));
            $flags = ord($tokens[$i + 3]) - 48;
            $file = [
                'name' => $tokens[$i],
                'size' => (int) $tokens[$i + 1],
                'date' => $date,
                'pass' => $flags & 1,
                'compressed' => ($flags >> 1) & 1,
            ];
            if ($tokens[$i + 4] !== '') {
                $file['crc32'] = $tokens[$i + 4];
            }
            $this->files[] = $file;
        }
    }

    private function line(string $line): void
    {
        if (str_ends_with($line, "\r")) {
            $line = substr($line, 0, -1);
        }
        if (strspn($line, " \t") === strlen($line)) {
            $this->endRecord();

            return;
        }

        $separator = strpos($line, ' = ');
        if ($separator !== false) {
            $key = substr($line, 0, $separator);
            $value = substr($line, $separator + 3);
        } elseif (str_ends_with($line, ' =')) {
            $key = substr($line, 0, -2);
            $value = '';
        } else {
            $this->recordMalformed = true;

            return;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9 ()._-]*$/', $key) !== 1 || array_key_exists($key, $this->record)) {
            $this->recordMalformed = true;

            return;
        }
        $this->record[$key] = $value;
    }

    private function endRecord(): void
    {
        $entry = $this->record;
        $malformed = $this->recordMalformed;
        $this->record = [];
        $this->recordMalformed = false;
        if ($entry === [] && ! $malformed) {
            return;
        }

        $path = $entry['Path'] ?? '';
        // C0 controls, DEL, and C1 controls in their UTF-8 form (C2 80 - C2 9F).
        if ($malformed || $path === '' || ! array_key_exists('Size', $entry)
            || preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $path) === 1
        ) {
            $this->reliable = false;

            return;
        }

        if (($entry['Folder'] ?? '') === '+' || str_starts_with($entry['Attributes'] ?? '', 'D')) {
            return;
        }

        $file = [
            'name' => $path,
            'size' => (int) $entry['Size'],
            'date' => (int) strtotime(substr($entry['Modified'] ?? '', 0, 19)),
            'pass' => ($entry['Encrypted'] ?? '') === '+' ? 1 : 0,
            'compressed' => in_array($entry['Method'] ?? '', ['', 'Copy'], true) ? 0 : 1,
        ];
        if (preg_match('/^[0-9A-F]{1,8}$/i', $entry['CRC'] ?? '') === 1) {
            $file['crc32'] = dechex((int) hexdec($entry['CRC']));
        }

        $this->files[] = $file;
    }

    // Wave 42.6 is independent of every earlier FFI toggle.
    public const bool W426_RUST_FFI_DEFAULT = false;

    private const string W426_FFI_CDEF = 'int32_t nntmux_w426_additional_file_manager_fallback_sync_sevenzip(uint8_t operation, const char *input, size_t input_len, char *output, size_t output_cap);';

    /** @return array{enabled: ?bool, ffi: \FFI|false|null} */
    private static function &w426State(): array
    {
        // A static local also supports readonly configuration objects.
        static $state = ['enabled' => null, 'ffi' => null];

        return $state;
    }

    public static function useW426RustFfi(bool $enable = true): void
    {
        $state = &self::w426State();
        $state['enabled'] = $enable;
    }

    public static function usesW426RustFfi(): bool
    {
        return self::w426State()['enabled'] ?? self::W426_RUST_FFI_DEFAULT;
    }

    public static function resetW426RustFfi(): void
    {
        $state = &self::w426State();
        $state = ['enabled' => null, 'ffi' => null];
    }

    /** @return list<int>|null */
    private static function w426Slots(int $operation, string $input, int $count): ?array
    {
        if (PHP_INT_SIZE !== 8 || \strlen($input) > 16_777_216) {
            return null;
        }
        try {
            $state = &self::w426State();
            $state['ffi'] ??= RustPipelineLibrary::resolveDomain('additional', self::W426_FFI_CDEF) ?? false;
            if ($state['ffi'] === false) {
                return null;
            }
            $bytes = $count * 8;
            $buffer = $state['ffi']->new('char['.$bytes.']');
            // @phpstan-ignore method.notFound
            $written = $state['ffi']->nntmux_w426_additional_file_manager_fallback_sync_sevenzip($operation, $input, \strlen($input), $buffer, $bytes);
            if ($written !== $bytes) {
                return null;
            }
            $values = unpack('q*', \FFI::string($buffer, $bytes));
            if ($values === false || \count($values) !== $count) {
                return null;
            }
            $values = array_values($values);
            foreach ($values as $index => $value) {
                if (! \is_int($value) || ($value < 0 && ! ($operation === 3 && $index === 7))) {
                    return null;
                }
            }

            return $values;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function w426Add(int $a, int $b): int
    {
        return $a > PHP_INT_MAX - $b ? PHP_INT_MAX : $a + $b;
    }

    /**
     * Summarize parsed files using the listing parser's byte-oriented path and flag validation.
     * Integer sums saturate at PHP_INT_MAX; malformed rows are counted without coercion.
     *
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, encrypted: int, compressed: int, invalid: int}
     */
    public static function summarizeListingFileBatch(array $files): array
    {
        return self::usesW426RustFfi()
            ? self::summarizeListingFileBatchNative($files)
            : self::summarizeListingFileBatchPhp($files);
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, encrypted: int, compressed: int, invalid: int}
     */
    public static function summarizeListingFileBatchPhp(array $files): array
    {
        $result = ['total' => \count($files), 'files' => 0, 'total_bytes' => 0, 'encrypted' => 0, 'compressed' => 0, 'invalid' => 0];
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                $result['invalid']++;

                continue;
            }
            $result['files']++;
            $result['total_bytes'] = self::w426Add($result['total_bytes'], $row['size']);
            $result['encrypted'] += $row['pass'];
            $result['compressed'] += $row['compressed'];
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array{total: int, files: int, total_bytes: int, encrypted: int, compressed: int, invalid: int}
     */
    public static function summarizeListingFileBatchNative(array $files): array
    {
        $total = \count($files);
        if (PHP_INT_SIZE !== 8 || $total > 100_000) {
            return self::summarizeListingFileBatchPhp($files);
        }
        $frame = pack('qV', 0, $total);
        foreach ($files as $row) {
            if (! self::w426ValidRow($row)) {
                return self::summarizeListingFileBatchPhp($files);
            }
            if (\strlen($row['name']) > 16_777_216 - 14 - \strlen($frame)) {
                return self::summarizeListingFileBatchPhp($files);
            }
            $frame .= pack('qCCV', $row['size'], $row['pass'], $row['compressed'], \strlen($row['name'])).$row['name'];
        }
        $slots = self::w426Slots(4, $frame, 6);
        if ($slots === null || $slots[0] !== $total || $slots[1] !== $total || $slots[3] > $total || $slots[4] > $total || $slots[5] !== 0) {
            return self::summarizeListingFileBatchPhp($files);
        }

        return ['total' => $slots[0], 'files' => $slots[1], 'total_bytes' => $slots[2], 'encrypted' => $slots[3], 'compressed' => $slots[4], 'invalid' => $slots[5]];
    }

    /** @phpstan-assert-if-true array{name: string, size: int, pass: int, compressed: int} $row */
    private static function w426ValidRow(mixed $row): bool
    {
        return \is_array($row) && \is_string($row['name'] ?? null) && $row['name'] !== '' && preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $row['name']) === 0 && \is_int($row['size'] ?? null) && $row['size'] >= 0 && \is_int($row['pass'] ?? null) && \in_array($row['pass'], [0, 1], true) && \is_int($row['compressed'] ?? null) && \in_array($row['compressed'], [0, 1], true);
    }

    public const string W52_FFI_CDEF = 'int64_t nntmux_w525_sevenzip_line_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W52.5 parity test's QUALIFICATION). */
    private const bool W52_RUST_FFI_DEFAULT = true;

    /**
     * Wave 52.5 routing and FFI cache, kept in function-local statics so readonly classes can use them too.
     * Without $op it reports the routing; 'set' swaps the override for $val and returns the previous one;
     * 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[\FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w52RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w52_5', self::W52_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof \FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W52_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW52RustFfi(): bool
    {
        return self::w52RustFfiState() === true;
    }

    /**
     * Runs $callback with linePlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW52RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w52RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w52RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 52.5 Rust helper, or null to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w52Words(int $count, string $data, string $ints, int $words): ?array
    {
        try {
            $handle = self::w52RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w525_sevenzip_line_plan($count, 0, $data, \strlen($data), $ints, \strlen($ints), $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('P*', \FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Property ordinals reported by linePlanBatch(). */
    private const array W52_KEY_ORDINALS = ['Path' => 1, 'Size' => 2, 'Modified' => 3, 'Attributes' => 4, 'CRC' => 5, 'Encrypted' => 6, 'Method' => 7, 'Folder' => 8];

    /**
     * Batch line plan with line()'s rules. Four words per line: `[kind | key ordinal << 2, key length, value
     * offset, value length]`, where kind 0 is a blank line, 1 a line without a separator, 2 a key the parser
     * rejects and 3 an accepted key; the ordinal (W52_KEY_ORDINALS, else 0) is only set for kind 3. One
     * trailing CR is dropped before the line is read; spans are byte offsets into the line.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function linePlanBatch(array $lines): array
    {
        return self::usesW52RustFfi() ? self::linePlanBatchNative($lines) : self::linePlanBatchPhp($lines);
    }

    /**
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function linePlanBatchPhp(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }
            $length = \strlen($line);
            if (strspn($line, " \t") === $length) {
                array_push($out, 0, 0, 0, 0);

                continue;
            }
            $separator = strpos($line, ' = ');
            if ($separator !== false) {
                $keyLength = $separator;
                $valueOffset = $separator + 3;
            } elseif (str_ends_with($line, ' =')) {
                $keyLength = $length - 2;
                $valueOffset = $length;
            } else {
                array_push($out, 1, 0, 0, 0);

                continue;
            }
            $key = substr($line, 0, $keyLength);
            $kind = preg_match('/^[A-Za-z][A-Za-z0-9 ()._-]*$/', $key) === 1 ? 3 | (self::W52_KEY_ORDINALS[$key] ?? 0) << 2 : 2;
            array_push($out, $kind, $keyLength, $valueOffset, $length - $valueOffset);
        }

        return $out;
    }

    /**
     * Rust batch over NUL-delimited lines; falls back to PHP when the library is missing or a line holds a
     * NUL byte.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    public static function linePlanBatchNative(array $lines): array
    {
        $count = \count($lines);
        if ($count === 0) {
            return [];
        }
        $data = implode("\0", $lines);

        return (substr_count($data, "\0") === $count - 1 ? self::w52Words($count, $data, '', 4 * $count) : null)
            ?? self::linePlanBatchPhp($lines);
    }

    /** Enabled: the minimum of three 301-round paired runs cleared every 1.5x gate (common mean 5.08x, p99 3.76x; uncommon mean 6.18x, p99 4.39x); see RustAdditional7zParserBudgetPersistenceMetricsContextW65ParityTest. */
    public const bool W65_RUST_FFI_DEFAULT = true;

    public const string W65_SEVEN_ZIP_LISTING_FFI_CDEF = <<<'C'
        ssize_t nntmux_w653_seven_zip_listing_batch(const char *blob, size_t blob_len, const int64_t *lens, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Trailer words after the per-item words of the Wave 65.3 batch. */
    public const int W65_SEVEN_ZIP_LISTING_TRAILER = 17;

    /** Cap for the Wave 65.3 running totals. */
    private const int W65_TOTAL_CAP = 4_611_686_018_427_387_904;

    public static function enableW65RustFfi(): void
    {
        self::w65RustFfiState('set', true);
    }

    public static function disableW65RustFfi(): void
    {
        self::w65RustFfiState('set', false);
    }

    /** Restores W65_RUST_FFI_DEFAULT and drops the cached library handle and scratch buffers. */
    public static function resetW65RustFfi(): void
    {
        self::w65RustFfiState('reset');
    }

    public static function usesW65RustFfi(): bool
    {
        $override = self::w65RustFfiState();

        return \is_bool($override) ? $override : self::W65_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with the SevenZipListingParser Wave 65 Rust path forced on or off, then restores the previous setting.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW65RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w65RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w65RustFfiState('set', $previous);
        }
    }

    /**
     * Wave 65.3 `7z l -slt` record plan. Each item is one record block: lines end at LF (one trailing CR dropped),
     * blank lines (spaces and tabs only) are skipped, and every other line must be `Key = value` (or end in ` =`) with
     * a key matching `^[A-Za-z][A-Za-z0-9 ()._-]*$` that is not repeated, else the block is malformed (the line is
     * dropped). Path is 0 missing or empty, 1 clean, 2 with C0, DEL or UTF-8 C1 controls; Size, Packed Size and Offset
     * are 0 empty, 1 up to 15 trimmed ASCII digits, 2 other; Encrypted is 0 empty, 1 `+`, 2 `-`, 3 other. The codec is
     * the Method's last space-separated token before any `:`, 0 without a Method, 1-10 for copy, lzma, lzma2, ppmd,
     * bzip2, deflate, deflate64, zstd, lz4, brotli (any case) or 11 other; AES is a caseless `aes` anywhere in the
     * Method and compressed a Method other than empty or `Copy`. The kind is 0 empty block, 1 rejected (malformed, Path
     * state other than 1, or no Size key), 2 directory (`Folder = +` or Attributes starting with `D`), then by the
     * basename's extension: 3 video, 4 audio, 5 NFO, 6 archive or volume (rar, zip, 7z, tar, gz, tgz, bz2, xz, iso,
     * `rNN`, `zNN`, `NNN`), 7 image, 8 other. Sample is a caseless `sample` in the basename. Per item `kind |
     * pathState << 4 | sizeState << 6 | packedState << 8 | offsetState << 10 | encrypted << 12 | codec << 14 | aes << 18
     * | compressed << 19 | sample << 20 | malformed << 21 | min(properties, 255) << 22 | ratio << 30 | sizeMiB << 40`,
     * with the ratio packed * 1000 / size (capped at 1023, 0 unless both are valid and size is positive) and the size
     * in MiB capped at 2^23 - 1. Then the nine kind counts and, over files (kind 3+), the encrypted, compressed and AES
     * counts, the size and packed totals (capped at 2^62), the 1-based index of the first non-sample video, of the
     * first strictly largest non-sample video, and the distinct paths (case-insensitive).
     *
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65SevenZipListingPlanBatch(array $items): array
    {
        return self::usesW65RustFfi() ? self::w65SevenZipListingPlanBatchNative($items) : self::w65SevenZipListingPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65SevenZipListingPlanBatchNative(array $items): array
    {
        return self::w65Invoke($items, \count($items) + self::W65_SEVEN_ZIP_LISTING_TRAILER) ?? self::w65SevenZipListingPlanBatchPhp($items);
    }

    /**
     * @param  array<array-key, string>  $items
     * @return list<int>
     */
    public static function w65SevenZipListingPlanBatchPhp(array $items): array
    {
        $out = [];
        $kinds = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $encrypted = $compressed = $aesCount = $sizeSum = $packedSum = $firstMedia = $largest = $index = 0;
        $largestSize = -1;
        $paths = [];
        foreach ($items as $item) {
            $index++;
            $record = [];
            $malformed = false;
            foreach (explode("\n", (string) $item) as $line) {
                if (str_ends_with($line, "\r")) {
                    $line = substr($line, 0, -1);
                }
                if (strspn($line, " \t") === \strlen($line)) {
                    continue;
                }
                $separator = strpos($line, ' = ');
                if ($separator !== false) {
                    $key = substr($line, 0, $separator);
                    $value = substr($line, $separator + 3);
                } elseif (str_ends_with($line, ' =')) {
                    $key = substr($line, 0, -2);
                    $value = '';
                } else {
                    $malformed = true;

                    continue;
                }
                if ($key === '' || strspn($key, self::W65_KEY_FIRST, 0, 1) !== 1 || strspn($key, self::W65_KEY_CHARS) !== \strlen($key) || isset($record[$key])) {
                    $malformed = true;

                    continue;
                }
                $record[$key] = $value;
            }
            $path = $record['Path'] ?? '';
            $pathState = $path === '' ? 0 : (preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $path) === 1 ? 2 : 1);
            [$sizeState, $size] = self::w65Count($record['Size'] ?? '', 15);
            [$packedState, $packed] = self::w65Count($record['Packed Size'] ?? '', 15);
            [$offsetState] = self::w65Count($record['Offset'] ?? '', 15);
            $encryption = match ($record['Encrypted'] ?? '') {
                '' => 0,
                '+' => 1,
                '-' => 2,
                default => 3,
            };
            $method = $record['Method'] ?? '';
            $isCompressed = $method !== '' && $method !== 'Copy';
            $codec = 0;
            if ($method !== '') {
                $space = strrpos($method, ' ');
                $token = $space === false ? $method : substr($method, $space + 1);
                $colon = strpos($token, ':');
                $codec = self::W65_CODECS[strtolower($colon === false ? $token : substr($token, 0, $colon))] ?? 11;
            }
            $aes = stripos($method, 'aes') !== false;
            $directory = ($record['Folder'] ?? '') === '+' || str_starts_with($record['Attributes'] ?? '', 'D');
            $slash = strrpos($path, '/');
            $backslash = strrpos($path, '\\');
            $base = substr($path, max($slash === false ? -1 : $slash, $backslash === false ? -1 : $backslash) + 1);
            $dot = strrpos($base, '.');
            $sample = stripos($base, 'sample') !== false;
            if ($record === [] && ! $malformed) {
                $kind = 0;
            } elseif ($malformed || $pathState !== 1 || ! isset($record['Size'])) {
                $kind = 1;
            } elseif ($directory) {
                $kind = 2;
            } else {
                $kind = self::w65ExtensionKind($dot === false ? '' : substr($base, $dot + 1));
            }
            $ratio = $sizeState === 1 && $size > 0 && $packedState === 1 ? min(intdiv($packed * 1000, $size), 1023) : 0;
            $mib = $sizeState === 1 ? min($size >> 20, 8_388_607) : 0;
            $kinds[$kind]++;
            if ($kind >= 3) {
                $encrypted += $encryption === 1 ? 1 : 0;
                $compressed += $isCompressed ? 1 : 0;
                $aesCount += $aes ? 1 : 0;
                $sizeSum = min($sizeSum + $size, self::W65_TOTAL_CAP);
                $packedSum = min($packedSum + $packed, self::W65_TOTAL_CAP);
                if ($kind === 3 && ! $sample) {
                    if ($firstMedia === 0) {
                        $firstMedia = $index;
                    }
                    if ($size > $largestSize) {
                        $largest = $index;
                        $largestSize = $size;
                    }
                }
                $paths[strtolower($path)] = true;
            }
            $out[] = $kind | $pathState << 4 | $sizeState << 6 | $packedState << 8 | $offsetState << 10 | $encryption << 12 | $codec << 14
                | ($aes ? 1 : 0) << 18 | ($isCompressed ? 1 : 0) << 19 | ($sample ? 1 : 0) << 20 | ($malformed ? 1 : 0) << 21
                | min(\count($record), 255) << 22 | $ratio << 30 | $mib << 40;
        }
        array_push($out, ...$kinds);
        array_push($out, $encrypted, $compressed, $aesCount, $sizeSum, $packedSum, $firstMedia, $largest, \count($paths));

        return $out;
    }

    /**
     * (0 empty, 1 one to $max ASCII digits, 2 other; the value) for the trimmed field.
     *
     * @return array{int, int}
     */
    private static function w65Count(string $text, int $max): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0, 0];
        }
        $length = \strlen($text);

        return $length <= $max && strspn($text, '0123456789') === $length ? [1, (int) $text] : [2, 0];
    }

    /** First byte of an accepted listing key. */
    private const string W65_KEY_FIRST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Bytes of an accepted listing key. */
    private const string W65_KEY_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 ()._-';

    /** Lower-cased codec token => code (any other token 11). */
    private const array W65_CODECS = ['copy' => 1, 'lzma' => 2, 'lzma2' => 3, 'ppmd' => 4, 'bzip2' => 5, 'deflate' => 6, 'deflate64' => 7,
        'zstd' => 8, 'lz4' => 9, 'brotli' => 10];

    /** Lower-cased extension => entry kind (3 video, 4 audio, 5 NFO, 6 archive, 7 image). */
    private const array W65_EXTENSION_KINDS = ['mkv' => 3, 'mp4' => 3, 'avi' => 3, 'm4v' => 3, 'ts' => 3, 'm2ts' => 3, 'wmv' => 3, 'mov' => 3,
        'mpg' => 3, 'mpeg' => 3, 'webm' => 3, 'vob' => 3, 'mp3' => 4, 'flac' => 4, 'm4a' => 4, 'aac' => 4, 'ogg' => 4, 'wav' => 4, 'ape' => 4,
        'wma' => 4, 'nfo' => 5, 'rar' => 6, 'zip' => 6, '7z' => 6, 'tar' => 6, 'gz' => 6, 'tgz' => 6, 'bz2' => 6, 'xz' => 6, 'iso' => 6,
        'jpg' => 7, 'jpeg' => 7, 'png' => 7, 'gif' => 7, 'bmp' => 7, 'webp' => 7];

    /** Entry kind of a basename extension: the W65_EXTENSION_KINDS entry, 6 for `rNN`, `zNN` and `NNN` volumes, else 8. */
    private static function w65ExtensionKind(string $extension): int
    {
        $kind = self::W65_EXTENSION_KINDS[strtolower($extension)] ?? 0;
        if ($kind !== 0) {
            return $kind;
        }

        return \strlen($extension) === 3 && strspn($extension, '0123456789', 1) === 2 && strspn($extension, '0123456789rRzZ', 0, 1) === 1 ? 6 : 8;
    }

    /**
     * Wave 65 state: no $op reads the toggle override, 'set' stores $val (non-bool clears it) and returns the
     * previous override, 'reset' clears the override, library handle and buffers, 'ffi' resolves the library
     * (null when unavailable) and 'out' and 'lens' return `int64_t` output and length-column buffers of at least
     * $val words. Function statics keep the class's property set unchanged.
     */
    private static function w65RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $override */
        static $override = null;
        /** @var \FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $out */
        static $out = null;
        /** @var int $outCap */
        static $outCap = 0;
        /** @var CData|null $lens */
        static $lens = null;
        /** @var int $lensCap */
        static $lensCap = 0;

        if ($op === 'set') {
            $previous = $override;
            $override = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op === 'reset') {
            $override = $ffi = $out = $lens = null;
            $outCap = $lensCap = 0;

            return null;
        }
        if ($op === 'ffi') {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w653', self::W65_SEVEN_ZIP_LISTING_FFI_CDEF) ?? false;

            return $ffi === false ? null : $ffi;
        }
        if ($op === 'out') {
            $words = \is_int($val) ? $val : 0;
            if ($out === null || $outCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof \FFI) {
                    return null;
                }
                $outCap = max(1024, $words * 2);
                $out = $handle->new('int64_t['.$outCap.']');
            }

            return $out;
        }
        if ($op === 'lens') {
            $words = \is_int($val) ? $val : 0;
            if ($lens === null || $lensCap < $words) {
                $handle = self::w65RustFfiState('ffi');
                if (! $handle instanceof \FFI) {
                    return null;
                }
                $lensCap = max(256, $words * 2);
                $lens = $handle->new('int64_t['.$lensCap.']');
            }

            return $lens;
        }

        return $override;
    }

    /**
     * Calls the Wave 65.3 SevenZipListingParser export with the NUL-joined $items, or with the items concatenated plus an
     * `int64_t` length column when an item holds a NUL byte. Null when the library is missing, the call declines
     * or it writes other than $words words, so the caller falls back to PHP.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<int>|null
     */
    private static function w65Invoke(array $items, int $words): ?array
    {
        try {
            $ffi = self::w65RustFfiState('ffi');
            $out = self::w65RustFfiState('out', $words);
            if (! $ffi instanceof \FFI || ! $out instanceof CData) {
                return null;
            }
            $count = \count($items);
            $blob = implode("\0", $items);
            $lens = null;
            if (substr_count($blob, "\0") !== max(0, $count - 1)) {
                $blob = implode('', $items);
                $column = self::w65RustFfiState('lens', $count);
                if (! $column instanceof CData) {
                    return null;
                }
                \FFI::memcpy($column, pack('P*', ...array_map(strlen(...), array_values($items))), $count * 8);
                $lens = \FFI::addr($column[0]);
            }
            // @phpstan-ignore method.notFound (Declared by W65_SEVEN_ZIP_LISTING_FFI_CDEF.)
            $written = $ffi->nntmux_w653_seven_zip_listing_batch($blob, \strlen($blob), $lens, $count, $out, $words);
            if ($written !== $words) {
                return null;
            }
            $values = unpack('P*', \FFI::string($out, $words * 8));

            /** @var list<int>|null */
            return $values === false ? null : array_values($values);
        } catch (\Throwable) {
            return null;
        }
    }
}
