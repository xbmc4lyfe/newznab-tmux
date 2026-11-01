<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

use App\Support\RustPipelineLibrary;

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
}
