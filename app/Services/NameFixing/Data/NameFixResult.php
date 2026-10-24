<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Data;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
