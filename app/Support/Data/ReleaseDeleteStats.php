<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Throwable;

/**
 * Data transfer object for release deletion statistics.
 *
 * Tracks the number of releases deleted by category during cleanup operations.
 */
#[TypeScript]
final class ReleaseDeleteStats extends Data
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_release_delete_stats(uint32_t mode, const int64_t *input, size_t len, int64_t *out, size_t cap);
        ssize_t nntmux_release_summary(uint32_t mode, const int64_t *input, size_t len, char *out, size_t cap);
        C;

    // PHP remains the default unless every paired common/uncommon mean and p99 gains 1.5x.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        self::$ffi ??= RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF) ?? false;

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * @param  list<int>  $values
     * @return list<int>|null
     */
    private static function calculateNative(int $mode, array $values, int $size): ?array
    {
        try {
            $ffi = self::ffi();
            if ($ffi === null || count($values) > 1024) {
                return null;
            }
            $input = $ffi->new('int64_t['.max(1, count($values)).']');
            foreach ($values as $i => $value) {
                $input[$i] = $value;
            }
            $out = $ffi->new('int64_t['.$size.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_release_delete_stats($mode, $input, count($values), $out, $size);
            if ($written !== $size) {
                return null;
            }
            $result = [];
            for ($i = 0; $i < $size; $i++) {
                $result[] = (int) $out[$i];
            }

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(
        public int $retention = 0,
        public int $password = 0,
        public int $duplicate = 0,
        public int $completion = 0,
        public int $disabledCategory = 0,
        public int $categoryMinSize = 0,
        public int $disabledGenre = 0,
        public int $miscOther = 0,
        public int $miscHashed = 0,
    ) {}

    public function increment(string $field): self
    {
        $values = $this->fieldValues();
        if (array_key_exists($field, $values)) {
            $values[$field]++;
        }

        return new self(...$values);
    }

    public function total(): int
    {
        return self::$useRustFfi ? $this->totalNative() : $this->totalPhp();
    }

    /** @return array<string, int> */
    private function fieldValues(): array
    {
        return ['retention' => $this->retention, 'password' => $this->password, 'duplicate' => $this->duplicate, 'completion' => $this->completion, 'disabledCategory' => $this->disabledCategory, 'categoryMinSize' => $this->categoryMinSize, 'disabledGenre' => $this->disabledGenre, 'miscOther' => $this->miscOther, 'miscHashed' => $this->miscHashed];
    }

    public function add(self $other): self
    {
        return self::$useRustFfi ? $this->addNative($other) : $this->addPhp($other);
    }

    public function addNative(self $other): self
    {
        $values = self::calculateNative(0, [...array_values($this->fieldValues()), ...array_values($other->fieldValues())], 9);

        return $values === null ? $this->addPhp($other) : new self(...$values);
    }

    public function addPhp(self $other): self
    {
        $values = $this->fieldValues();
        foreach ($other->fieldValues() as $field => $count) {
            $values[$field] += $count;
        }

        return new self(...$values);
    }

    public function totalNative(): int
    {
        return self::calculateNative(1, array_values($this->fieldValues()), 1)[0] ?? $this->totalPhp();
    }

    public function totalPhp(): int
    {
        return array_sum($this->fieldValues());
    }

    public function hasDeletions(): bool
    {
        return self::$useRustFfi ? $this->hasDeletionsNative() : $this->hasDeletionsPhp();
    }

    public function hasDeletionsNative(): bool
    {
        $values = self::calculateNative(3, array_values($this->fieldValues()), 1);

        return $values === null ? $this->hasDeletionsPhp() : $values[0] === 1;
    }

    public function hasDeletionsPhp(): bool
    {
        return $this->totalPhp() > 0;
    }

    public function toSummaryString(): string
    {
        return self::$useRustFfi ? $this->toSummaryStringNative() : $this->toSummaryStringPhp();
    }

    public function toSummaryStringNative(): string
    {
        try {
            if (($ffi = self::ffi()) !== null) {
                $input = $ffi->new('int64_t[9]');
                foreach (array_values($this->fieldValues()) as $i => $value) {
                    $input[$i] = $value;
                }
                $out = $ffi->new('char[512]');
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_release_summary(1, $input, 9, $out, 512);
                if (is_int($written) && $written >= 0 && $written <= 512) {
                    return FFI::string($out, $written);
                }
            }
        } catch (Throwable) {
            // Preserve PHP reporting when Rust cannot be used.
        }

        return $this->toSummaryStringPhp();
    }

    public function toSummaryStringPhp(): string
    {
        $fields = [];
        foreach ($this->fieldValues() as $field => $count) {
            if ($count !== 0) {
                $fields[] = $field.': '.$count;
            }
        }

        return $fields === [] ? 'No releases deleted.' : implode(', ', $fields);
    }

    /** @return array<string, int> */
    public function toLogContext(): array
    {
        return self::$useRustFfi ? $this->toLogContextNative() : $this->toLogContextPhp();
    }

    /** @return array<string, int> */
    public function toLogContextNative(): array
    {
        $fields = $this->fieldValues();
        $values = self::calculateNative(2, array_values($fields), 10);
        if ($values === null) {
            return $this->toLogContextPhp();
        }
        $keys = [...array_keys($fields), 'total'];

        return array_combine($keys, $values);
    }

    /** @return array<string, int> */
    public function toLogContextPhp(): array
    {
        return [...$this->fieldValues(), 'total' => $this->totalPhp()];
    }
}
