<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Services\RegexService;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

/**
 * The file an article belongs to, as its yEnc header describes it.
 */
final readonly class YencPart
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w19_verification_prereqs(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        // Single-call candidates stay off until all four performance gates pass.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    /** @param list<mixed> $arguments
     * @return array{value: mixed}|null
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        try {
            foreach ($arguments as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    return null;
                }
                if (is_string($value) && strlen($value) > 64 * 1024 * 1024) {
                    return null;
                }
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w19_verification_prereqs($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  int  $size  size of the whole file
     * @param  int|null  $begin  1-based offset of this part's first byte in the file
     * @param  int|null  $end  1-based offset of this part's last byte in the file
     */
    public function __construct(
        public string $name,
        public int $size,
        public ?int $part = null,
        public ?int $begin = null,
        public ?int $end = null,
    ) {}

    /**
     * 0-based offset of this article's data in the file.
     */
    public function offset(): int
    {
        return self::useRustFfi() ? ($this->offsetNative() ?? $this->offsetPhp()) : $this->offsetPhp();
    }

    public function offsetPhp(): int
    {
        return $this->begin === null ? 0 : $this->begin - 1;
    }

    public function offsetNative(): ?int
    {
        $value = self::computeNative(0, [$this->begin])['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Bytes this article carries, or null when its header does not say.
     */
    public function length(): ?int
    {
        return self::useRustFfi() ? ($this->lengthNative() ?? $this->lengthPhp()) : $this->lengthPhp();
    }

    public function lengthPhp(): ?int
    {
        return $this->begin === null || $this->end === null ? null : $this->end - $this->begin + 1;
    }

    public function lengthNative(): ?int
    {
        $value = self::computeNative(1, [$this->begin, $this->end])['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    public static function byteLength(string $bytes): int
    {
        return self::useRustFfi() ? (self::byteLengthNative($bytes) ?? self::byteLengthPhp($bytes)) : self::byteLengthPhp($bytes);
    }

    public static function byteLengthPhp(string $bytes): int
    {
        return strlen($bytes);
    }

    public static function byteLengthNative(string $bytes): ?int
    {
        $value = self::computeNative(2, [$bytes])['value'] ?? null;

        return is_int($value) ? $value : null;
    }

    public static function validSpan(?int $partBegin, ?int $partEnd, int $totalSize): bool
    {
        return self::useRustFfi() ? (self::validSpanNative($partBegin, $partEnd, $totalSize) ?? self::validSpanPhp($partBegin, $partEnd, $totalSize)) : self::validSpanPhp($partBegin, $partEnd, $totalSize);
    }

    public static function validSpanPhp(?int $partBegin, ?int $partEnd, int $totalSize): bool
    {
        return $partBegin !== null && $partEnd !== null && $partBegin >= 1 && $partEnd >= $partBegin && $partEnd <= $totalSize;
    }

    public static function validSpanNative(?int $partBegin, ?int $partEnd, int $totalSize): ?bool
    {
        $value = self::computeNative(3, [$partBegin, $partEnd, $totalSize])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public static function normalizeCrc32(?string $crc): ?string
    {
        return self::useRustFfi() ? (self::normalizeCrc32Native($crc) ?? self::normalizeCrc32Php($crc)) : self::normalizeCrc32Php($crc);
    }

    public static function normalizeCrc32Php(?string $crc): ?string
    {
        return $crc !== null && strlen($crc) >= 1 && strlen($crc) <= 8 && ctype_xdigit($crc) ? str_pad(strtolower($crc), 8, '0', STR_PAD_LEFT) : null;
    }

    public static function normalizeCrc32Native(?string $crc): ?string
    {
        $value = self::computeNative(4, [$crc])['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function isComplete(string $bytes): bool
    {
        return self::useRustFfi() ? ($this->isCompleteNative($bytes) ?? $this->isCompletePhp($bytes)) : $this->isCompletePhp($bytes);
    }

    public function isCompletePhp(string $bytes): bool
    {
        if ($this->begin === null && $this->end === null) {
            return $this->size >= 0 && strlen($bytes) === $this->size;
        }

        return self::validSpanPhp($this->begin, $this->end, $this->size) && strlen($bytes) === $this->lengthPhp();
    }

    public function isCompleteNative(string $bytes): ?bool
    {
        $value = self::computeNative(5, [strlen($bytes), $this->begin, $this->end, $this->size])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /** @return array{name: string, size: int, part: int|null, begin: int|null, end: int|null} */
    public function metadata(): array
    {
        return self::useRustFfi() ? ($this->metadataNative() ?? $this->metadataPhp()) : $this->metadataPhp();
    }

    /** @return array{name: string, size: int, part: int|null, begin: int|null, end: int|null} */
    public function metadataPhp(): array
    {
        return ['name' => $this->name, 'size' => $this->size, 'part' => $this->part, 'begin' => $this->begin, 'end' => $this->end];
    }

    /** @return array{name: string, size: int, part: int|null, begin: int|null, end: int|null}|null */
    public function metadataNative(): ?array
    {
        $value = self::computeNative(6, [$this->name, $this->size, $this->part, $this->begin, $this->end])['value'] ?? null;

        return is_array($value) ? $value : null;
    }

    /** Enabled: paired common/uncommon mean and p99 gains reach 1.5x in all five runs (min 1.75x; see the W46 parity test). */
    public const bool W461_RUST_FFI_DEFAULT = true;

    public const string W461_FFI_CDEF = <<<'C'
        ssize_t nntmux_w461_yenc_crc_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    private const array W461_SUMMARY_KEYS = ['total_crcs', 'empty_crc_count', 'valid_count', 'padded_count', 'uppercase_count', 'zero_count', 'too_long_count', 'non_hex_count'];

    /**
     * Readonly classes cannot declare static properties, so the toggle and cached handle live here.
     *
     * @return array{use: ?bool, ffi: FFI|false|null}
     */
    private static function &w461State(): array
    {
        /** @var array{use: ?bool, ffi: FFI|false|null} $state */
        static $state = ['use' => null, 'ffi' => null];

        return $state;
    }

    /** Null restores the benchmark default. */
    public static function useW461RustFfi(?bool $enable = true): void
    {
        $state = &self::w461State();
        $state['use'] = $enable;
    }

    public static function usesW461RustFfi(): bool
    {
        return self::w461State()['use'] ?? self::W461_RUST_FFI_DEFAULT;
    }

    /** Restores the benchmark default and drops the cached library handle; setUseRustFfi() is untouched. */
    public static function resetW461RustFfi(): void
    {
        $state = &self::w461State();
        $state = ['use' => null, 'ffi' => null];
    }

    /**
     * Summarizes yEnc CRC32 values with normalizeCrc32Php(): valid ones (padded, holding an uppercase digit,
     * or normalizing to zero) and those too long or not hexadecimal.
     * Entries come from strings, integers, or the first non-null crc32, pcrc32 array field; other values
     * (including objects) count as empty entries.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCrcBatch(array $rows): array
    {
        return self::usesW461RustFfi() ? self::summarizeCrcBatchNative($rows) : self::summarizeCrcBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCrcBatchNative(array $rows): array
    {
        $state = &self::w461State();
        $values = RegexService::w461Invoke($state['ffi'], self::W461_FFI_CDEF, 'nntmux_w461_yenc_crc_batch', $rows, ['crc32', 'pcrc32'], \count(self::W461_SUMMARY_KEYS));

        return $values === null ? self::summarizeCrcBatchPhp($rows) : array_combine(self::W461_SUMMARY_KEYS, $values);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizeCrcBatchPhp(array $rows): array
    {
        $summary = array_fill_keys(self::W461_SUMMARY_KEYS, 0);
        foreach (RegexService::w461Entries($rows, ['crc32', 'pcrc32']) as $crc) {
            $summary['total_crcs']++;
            if ($crc === '') {
                $summary['empty_crc_count']++;

                continue;
            }
            $normalized = self::normalizeCrc32Php($crc);
            if ($normalized === null) {
                $summary[strlen($crc) > 8 ? 'too_long_count' : 'non_hex_count']++;

                continue;
            }
            $summary['valid_count']++;
            $summary['padded_count'] += (int) (strlen($crc) < 8);
            $summary['uppercase_count'] += (int) (strpbrk($crc, 'ABCDEF') !== false);
            $summary['zero_count'] += (int) ($normalized === '00000000');
        }

        return $summary;
    }

    // ---- Wave 63.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 8.66x/9.59x, uncommon 8.97x/8.99x); see RustFingerprintCollectorYencDecoderPartServiceLogFileW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_YENC_PART_FFI_CDEF = <<<'C'
        intptr_t nntmux_w632_yenc_part_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W63_YENC_PART_TAIL = 20;

    /**
     * Wave 63 routing, binding and output buffer, kept in function-local statics (no static or
     * instance properties). Operations: null reads the routing override, 'set' replaces it
     * (returning the previous one), 'reset' also drops the binding and buffer, 'ffi' resolves the
     * binding and 'out' returns `[binding, buffer, capacity]` with room for at least $val words
     * (null without the library).
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'reset':
                $previous = $enabled;
                [$enabled, $ffi, $out, $capacity] = [null, null, null, 0];

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w63', self::W63_YENC_PART_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w63RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    /** Routes w63YencPartPlanBatch() to Rust until reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Routes w63YencPartPlanBatch() to PHP until reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restores W63_RUST_FFI_DEFAULT and drops the cached binding and buffer; earlier toggles are untouched. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with w63YencPartPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous
     * routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencPartPlanBatch(array $rows): array
    {
        return self::usesW63RustFfi() ? self::w63YencPartPlanBatchNative($rows) : self::w63YencPartPlanBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencPartPlanBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W63_YENC_PART_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w63RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::w63YencPartPlanBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W63_YENC_PART_FFI_CDEF.)
            $written = $ffi->nntmux_w632_yenc_part_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (\Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::w63YencPartPlanBatchPhp($rows);
    }

    /** @param  list<int>  $out */
    private static function w63Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /** A trimmed field: null when empty, its value for 1 to $max ASCII digits, otherwise false. */
    private static function w63Int(string $value, int $max): int|false|null
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = \strlen($value);

        return $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : false;
    }

    /**
     * Wave 63.2 part plan. Per `name\tsize\tpart\tbegin\tend\tcrc32\tbody_bytes` row `status |
     * continuity << 3 | crc_class << 5 | min(part, 65535) << 7 | min(length, 2^32 - 1) << 23 |
     * (crc32(lowercase trimmed name) & 0xFF) << 55`. Numbers are w63Int() fields of up to 12 digits;
     * a malformed one, a missing size or an empty trimmed name makes the row invalid (status 0, only
     * the CRC class kept). Statuses: 1 single-part body of exactly `size` bytes, 2 single-part body
     * of another length, 3 validSpanPhp() range with a body of exactly lengthPhp() bytes, 4 such a
     * range with another body length, 5 an invalid range, 6 a part number without a range or half a
     * range. CRC classes (normalizeCrc32Php() of the trimmed field): 0 absent, 1 valid, 2 malformed,
     * 3 zero. Per file (lowercase trimmed name) a valid range continues the previous one (1), leaves
     * a gap (2) or overlaps it (3), 0 for the first range and other statuses. Then seven status,
     * four continuity and four CRC class counts, the distinct files, the bytes of complete rows
     * (statuses 1 and 3), the largest range end, the ranges whose part number does not follow the
     * file's previous range and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function w63YencPartPlanBatchPhp(array $rows): array
    {
        $out = $files = [];
        $statuses = array_fill(0, 7, 0);
        $continuity = $crcs = [0, 0, 0, 0];
        $totalLength = $maxEnd = $partGaps = 0;
        foreach ($rows as $row) {
            [$name, $size, $part, $begin, $end, $crc, $body] = explode("\t", (string) $row, 7) + array_fill(0, 7, '');
            $name = trim($name);
            $size = self::w63Int($size, 12);
            $part = self::w63Int($part, 12);
            $begin = self::w63Int($begin, 12);
            $end = self::w63Int($end, 12);
            $body = self::w63Int($body, 12);
            $crcCode = self::w63CrcClass($crc);
            if (! \is_int($size) || $part === false || $begin === false || $end === false || $body === false || $name === '') {
                $statuses[0]++;
                $crcs[$crcCode]++;
                $out[] = $crcCode << 5;

                continue;
            }
            $body ??= 0;
            [$status, $length] = match (true) {
                $begin === null && $end === null => $part !== null ? [6, 0] : [$body === $size ? 1 : 2, $size],
                $begin !== null && $end !== null => self::validSpanPhp($begin, $end, $size) ? [$body === $end - $begin + 1 ? 3 : 4, $end - $begin + 1] : [5, 0],
                default => [6, 0],
            };
            $key = strtolower($name);
            $files[$key] ??= [null, null];
            $cont = 0;
            if (($status === 3 || $status === 4) && $begin !== null && $end !== null) {
                [$lastEnd, $lastPart] = $files[$key];
                if ($lastEnd !== null) {
                    $cont = $begin === $lastEnd + 1 ? 1 : ($begin > $lastEnd + 1 ? 2 : 3);
                }
                if ($part !== null && $lastPart !== null && $part !== $lastPart + 1) {
                    $partGaps++;
                }
                $files[$key] = [$end, $part];
                $maxEnd = max($maxEnd, $end);
            }
            if ($status === 1 || $status === 3) {
                $totalLength += $length;
            }
            $statuses[$status]++;
            $continuity[$cont]++;
            $crcs[$crcCode]++;
            $out[] = $status | $cont << 3 | $crcCode << 5 | min($part ?? 0, 0xFFFF) << 7 | min($length, 0xFFFFFFFF) << 23 | (crc32($key) & 0xFF) << 55;
        }
        array_push($out, ...$statuses, ...$continuity, ...$crcs);
        array_push($out, \count($files), $totalLength, $maxEnd, $partGaps);
        $out[] = self::w63Digest($out);

        return $out;
    }

    /** 0 absent, 1 valid, 2 malformed, 3 zero: normalizeCrc32Php() of the trimmed field. */
    private static function w63CrcClass(string $crc): int
    {
        $crc = trim($crc);
        $length = \strlen($crc);

        return match (true) {
            $length === 0 => 0,
            $length > 8 || strspn($crc, '0123456789abcdefABCDEF') !== $length => 2,
            strspn($crc, '0') === $length => 3,
            default => 1,
        };
    }
}
