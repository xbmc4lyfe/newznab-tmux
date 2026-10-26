<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Data Transfer Object for name fix results.
 *
 * Encapsulates the result of a name fixing operation, including the new name,
 * method used, and optional metadata.
 */
#[TypeScript]
final class NameFixResult extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_fix_result(uint32_t mode, const char *input, size_t len, int64_t predb_id, uint32_t matched, char *out, size_t cap);
        C;

    // Remains opt-in until the end-to-end performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $newName,
        public string $method,
        public string $checkerName,
        public int $preDbId = 0,
        public float $confidence = 1.0,
        public array $metadata = [],
    ) {}

    public static function matched(
        string $newName,
        string $method,
        string $checkerName,
        int $preDbId = 0,
        float $confidence = 1.0
    ): self {
        return (self::$useRustFfi ? self::matchedNative($newName, $method, $checkerName, $preDbId, $confidence) : null)
            ?? self::matchedPhp($newName, $method, $checkerName, $preDbId, $confidence);
    }

    public static function matchedPhp(
        string $newName,
        string $method,
        string $checkerName,
        int $preDbId = 0,
        float $confidence = 1.0
    ): self {
        return new self($newName, $method, $checkerName, $preDbId, $confidence);
    }

    public static function matchedNative(
        string $newName,
        string $method,
        string $checkerName,
        int $preDbId = 0,
        float $confidence = 1.0
    ): ?self {
        $fields = self::resultFieldsNative(0, [$newName, $method, $checkerName], $preDbId, 1);
        if ($fields !== [$newName, $method, $checkerName, (string) $preDbId]) {
            return null;
        }

        return new self($fields[0], $fields[1], $fields[2], $preDbId, $confidence);
    }

    public static function unmatched(string $checkerName = ''): self
    {
        return (self::$useRustFfi ? self::unmatchedNative($checkerName) : null) ?? self::unmatchedPhp($checkerName);
    }

    public static function unmatchedPhp(string $checkerName = ''): self
    {
        return new self('', '', $checkerName, 0, 0.0);
    }

    public static function unmatchedNative(string $checkerName = ''): ?self
    {
        $fields = self::resultFieldsNative(1, [$checkerName]);
        if ($fields !== ['', '', $checkerName, '0']) {
            return null;
        }

        return new self($fields[0], $fields[1], $fields[2], 0, 0.0);
    }

    public static function fromCheckResult(?self $result, string $checkerName = ''): self
    {
        return (self::$useRustFfi ? self::fromCheckResultNative($result, $checkerName) : null)
            ?? self::fromCheckResultPhp($result, $checkerName);
    }

    public static function fromCheckResultPhp(?self $result, string $checkerName = ''): self
    {
        return $result ?? self::unmatchedPhp($checkerName);
    }

    public static function fromCheckResultNative(?self $result, string $checkerName = ''): ?self
    {
        $fields = self::resultFieldsNative(
            2,
            $result === null ? ['', '', $checkerName] : [$result->newName, $result->method, $result->checkerName],
            $result === null ? 0 : $result->preDbId,
            $result === null ? 0 : 1,
        );
        $expected = $result === null ? ['', '', $checkerName, '0']
            : [$result->newName, $result->method, $result->checkerName, (string) $result->preDbId];
        if ($fields !== $expected) {
            return null;
        }

        // Preserve identity, metadata, confidence, and Spatie's transformation context.
        return $result ?? new self($fields[0], $fields[1], $fields[2], 0, 0.0);
    }

    public function isSuccessful(): bool
    {
        return (self::$useRustFfi ? $this->isSuccessfulNative() : null) ?? $this->isSuccessfulPhp();
    }

    public function isSuccessfulPhp(): bool
    {
        return $this->newName !== '';
    }

    public function isSuccessfulNative(): ?bool
    {
        $status = self::callNative(3, $this->newName);

        return is_int($status) ? $status === 1 : null;
    }

    /** @return array<array-key, mixed> */
    public function toArray(): array
    {
        $data = $this->toArrayPhp();

        return (self::$useRustFfi ? self::arrayProjectionNative($data) : null) ?? $data;
    }

    /** @return array<array-key, mixed> */
    public function toArrayPhp(): array
    {
        return parent::toArray();
    }

    /**
     * Framework-owned serialization stays in PHP, including only/except and metadata.
     * Rust round-trips only top-level string pairs without JSON or numeric coercion.
     *
     * @return array<array-key, mixed>|null
     */
    public function toArrayNative(): ?array
    {
        return self::arrayProjectionNative(parent::toArray());
    }

    /**
     * Serialize the framework context once, including when native declines.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    private static function arrayProjectionNative(array $data): ?array
    {
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $fields[] = $key;
                $fields[] = $value;
            }
        }
        $output = self::resultFieldsNative(4, $fields);
        if ($output !== $fields) {
            return null;
        }
        for ($i = 0; $i < count($output); $i += 2) {
            $data[$output[$i]] = $output[$i + 1];
        }

        return $data;
    }

    public function getSummary(): string
    {
        return (self::$useRustFfi ? $this->getSummaryNative() : null) ?? $this->getSummaryPhp();
    }

    public function getSummaryPhp(): string
    {
        return $this->isSuccessfulPhp()
            ? "{$this->checkerName}: {$this->method} => {$this->newName}"
            : "{$this->checkerName}: no match";
    }

    public function getSummaryNative(): ?string
    {
        $input = self::encodeFields([$this->newName, $this->method, $this->checkerName]);
        if ($input === null) {
            return null;
        }
        $output = self::callNative(5, $input);

        return is_string($output) ? $output : null;
    }

    /** @param list<string> $fields */
    private static function encodeFields(array $fields): ?string
    {
        $input = '';
        foreach ($fields as $field) {
            if (strlen($field) > 0xFFFFFFFF) {
                return null;
            }
            $input .= pack('V', strlen($field)).$field;
        }

        return $input;
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>|null
     */
    private static function resultFieldsNative(int $mode, array $fields, int $preDbId = 0, int $matched = 0): ?array
    {
        $input = self::encodeFields($fields);
        if ($input === null) {
            return null;
        }
        $output = self::callNative($mode, $input, $preDbId, $matched);
        if (! is_string($output)) {
            return null;
        }
        $decoded = [];
        $offset = 0;
        $length = strlen($output);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', $output, $offset);
            if ($size === false || ! is_int($size['size'])) {
                return null;
            }
            $offset += 4;
            if ($size['size'] > $length - $offset) {
                return null;
            }
            $decoded[] = substr($output, $offset, $size['size']);
            $offset += $size['size'];
        }

        return $decoded;
    }

    private static function callNative(int $mode, string $input, int $preDbId = 0, int $matched = 0): string|int|null
    {
        if (strlen($input) > PHP_INT_MAX - 128) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max(128, strlen($input) + 128), $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fix_result($mode, $input, strlen($input), $preDbId, $matched, $buffer, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }
            if ($mode === 3) {
                return $written <= 1 ? $written : null;
            }

            return $written === 0 ? '' : FFI::string($buffer, $written);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Create a result from a pattern match.
     */
    public static function fromMatch(
        string $newName,
        string $method,
        string $checkerName,
        int $preDbId = 0,
        float $confidence = 1.0
    ): self {
        return new self(
            newName: $newName,
            method: $method,
            checkerName: $checkerName,
            preDbId: $preDbId,
            confidence: $confidence,
        );
    }

    /**
     * Create a result from PreDB match.
     */
    public static function fromPreDb(
        string $newName,
        int $preDbId,
        string $source,
        string $checkerName = 'PreDB'
    ): self {
        return new self(
            newName: $newName,
            method: "PreDB: {$source}",
            checkerName: $checkerName,
            preDbId: $preDbId,
            confidence: 1.0,
            metadata: ['source' => $source],
        );
    }

    /**
     * Check if this result has a PreDB ID.
     */
    public function hasPreDbId(): bool
    {
        return $this->preDbId > 0;
    }

    /**
     * Get a formatted method string for logging.
     */
    public function getFormattedMethod(): string
    {
        return "{$this->checkerName}: {$this->method}";
    }

    // ---------------------------------------------------------------------
    // Wave 57.4 batch helper
    // ---------------------------------------------------------------------

    /** Retained PHP: the minimum of three paired runs missed the 1.5x gates, since packing the result properties costs about as much as the PHP checks (common mean 1.41x, p99 1.35x; uncommon mean 1.00x, p99 0.96x); see RustNameFixingPatternsAppGameMovieTvNameFixResultW57ParityTest. */
    public const bool W57_RUST_FFI_DEFAULT = false;

    public const string W57_FFI_CDEF = <<<'C'
        ssize_t nntmux_w57_name_fix_result_row_batch(const char *blob, size_t blob_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** int64 words per nameFixResultRowBatch() record, and the trailing summary words. */
    public const int W57_ROW_WORDS = 6;

    public const int W57_SUMMARY_WORDS = 8;

    /** Method keywords (lowercase) classified 2 + index by the first one the method contains. */
    private const array W57_METHOD_KEYWORDS = ['nfo', 'par2', 'sfv', 'srr', 'file', 'crc', 'hash', 'md5', 'uid', 'xxx', 'tv',
        'movie', 'game', 'app', 'music', 'title'];

    private const string W57_CONTROL = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f"
        ."\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    private const string W57_LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Result rows plus a batch summary. Each record yields six words: flags (successful 1,
     * preDbId > 0 2, "PreDB: " method 4, untrimmed new name 8, control byte 16, non-ASCII byte
     * 32, valid cleaned name 64, empty method 128, empty checker 256), the method class (0 empty,
     * 1 PreDB, 2 + the first W57_METHOD_KEYWORDS entry the lowercased method contains, else -1),
     * the PreDB source span (offset << 32 | length, or -1), the confidence tier (-1 outside
     * [0, 1] or NaN, else 3/2/1/0 at the 0.95/0.75/0.5 thresholds), the trim() span of the new
     * name and strlen(getSummary()). A valid cleaned name is the trimmed new name with 5-255
     * bytes, no control byte, an ASCII letter and a [._ -] separator, not led by '.' or '-'.
     * Non-empty batches end with eight summary words: successful, PreDB id, valid name and
     * invalid confidence counts, then the tier 0-3 counts.
     *
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function nameFixResultRowBatch(array $results): array
    {
        return self::usesW57RustFfi() ? self::nameFixResultRowBatchNative($results) : self::nameFixResultRowBatchPhp($results);
    }

    /**
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function nameFixResultRowBatchPhp(array $results): array
    {
        self::w57Results($results);
        if ($results === []) {
            return [];
        }
        $out = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0];
        foreach ($results as $result) {
            $name = $result->newName;
            $method = $result->method;
            $checker = $result->checkerName;
            $confidence = $result->confidence;
            $length = \strlen($name);
            $trimmed = trim($name);
            $trimmedLength = \strlen($trimmed);
            $preDb = str_starts_with($method, 'PreDB: ');
            $valid = $trimmedLength >= 5 && $trimmedLength <= 255
                && strcspn($trimmed, self::W57_CONTROL) === $trimmedLength
                && strpbrk($trimmed, self::W57_LETTERS) !== false
                && strpbrk($trimmed, '._ -') !== false
                && $trimmed[0] !== '.' && $trimmed[0] !== '-';
            if ($method === '') {
                $class = 0;
            } elseif ($preDb) {
                $class = 1;
            } else {
                $class = -1;
                $lowerMethod = strtolower($method);
                foreach (self::W57_METHOD_KEYWORDS as $i => $keyword) {
                    if (str_contains($lowerMethod, $keyword)) {
                        $class = $i + 2;
                        break;
                    }
                }
            }
            $tier = ! ($confidence >= 0.0 && $confidence <= 1.0) ? -1
                : ($confidence >= 0.95 ? 3 : ($confidence >= 0.75 ? 2 : ($confidence >= 0.5 ? 1 : 0)));
            $successful = $name !== '';
            array_push(
                $out,
                ($successful ? 1 : 0)
                    | ($result->preDbId > 0 ? 2 : 0)
                    | ($preDb ? 4 : 0)
                    | ($trimmedLength !== $length ? 8 : 0)
                    | (strcspn($name, self::W57_CONTROL) !== $length ? 16 : 0)
                    | (preg_match('/[\x80-\xff]/', $name) === 1 ? 32 : 0)
                    | ($valid ? 64 : 0)
                    | ($method === '' ? 128 : 0)
                    | ($checker === '' ? 256 : 0),
                $class,
                $preDb ? (7 << 32) | (\strlen($method) - 7) : -1,
                $tier,
                (strspn($name, " \t\n\r\0\x0B") << 32) | $trimmedLength,
                $successful ? \strlen($checker) + \strlen($method) + $length + 6 : \strlen($checker) + 10,
            );
            $summary[0] += $successful ? 1 : 0;
            $summary[1] += $result->preDbId > 0 ? 1 : 0;
            $summary[2] += $valid ? 1 : 0;
            $summary[$tier + 4]++;
        }
        array_push($out, ...$summary);

        return $out;
    }

    /**
     * @param  list<self>  $results
     * @return list<int>
     */
    public static function nameFixResultRowBatchNative(array $results): array
    {
        self::w57Results($results);
        $count = \count($results);
        if ($count === 0) {
            return [];
        }
        // Blob lengths in record order, then the preDbId and confidence columns.
        $parts = [];
        $lens = [];
        $ids = [];
        $confidences = [];
        foreach ($results as $result) {
            $parts[] = $result->newName;
            $parts[] = $result->method;
            $parts[] = $result->checkerName;
            $lens[] = \strlen($result->newName);
            $lens[] = \strlen($result->method);
            $lens[] = \strlen($result->checkerName);
            $ids[] = $result->preDbId;
            $confidences[] = $result->confidence;
        }
        $ints = pack('P*', ...$lens, ...$ids).pack('e*', ...$confidences);

        return self::w57Call(implode('', $parts), $ints, $count, $count * self::W57_ROW_WORDS + self::W57_SUMMARY_WORDS)
            ?? self::nameFixResultRowBatchPhp($results);
    }

    /** @param array<array-key, mixed> $results */
    private static function w57Results(array $results): void
    {
        if (! array_is_list($results)) {
            throw new \InvalidArgumentException('W57 batch results must be a list.');
        }
        foreach ($results as $result) {
            if (! $result instanceof self) {
                throw new \InvalidArgumentException('W57 batch results must be NameFixResult instances.');
            }
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $enabled = self::w57RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Run $callback with the W57 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('enabled');
        self::w57RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('enabled', $previous);
        }
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP.
     *
     * @return list<int>|null
     */
    private static function w57Call(string $blob, string $ints, int $count, int $need): ?array
    {
        try {
            /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int} $state */
            $state = self::w57RustFfiState();
            $ffi = $state['ffi'];
            if ($ffi === null) {
                $ffi = RustPipelineLibrary::resolveDomain('metadata_w57', self::W57_FFI_CDEF) ?? false;
                self::w57RustFfiState('ffi', $ffi);
            }
            if (! $ffi instanceof FFI) {
                return null;
            }
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w57RustFfiState('out', $out);
                self::w57RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W57_FFI_CDEF.)
            $written = $ffi->nntmux_w57_name_fix_result_row_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
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
     * W57 routing override and cached binding plus reusable output buffer, kept in a function
     * static: no arguments returns the state, one argument reads a key ('reset' clears
     * everything) and two arguments write a key.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
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
