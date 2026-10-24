<?php

declare(strict_types=1);

namespace App\Services\NameFixing\Checkers;

use App\Services\NameFixing\Contracts\NameCheckerInterface;
use App\Services\NameFixing\Data\NameFixResult;
use App\Support\RustPipelineLibrary;
use FFI;

/**
 * Abstract base class for name checkers.
 *
 * Provides common functionality for pattern-based name checking.
 */
abstract class AbstractNameChecker implements NameCheckerInterface
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_name_fixing_query_pass(uint32_t mode, const char *input, size_t len, int64_t first, int64_t second, char *out, size_t cap);
        C;

    // Retained PHP: the paired caller benchmark must pass all four 1.5x gates.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Negative ABI results retain the exact PHP implementation. */
    private static function queryPassNative(int $mode, ?string $input, int $first = 0, int $second = 0, int $needed = 4096): ?string
    {
        if ($input === null || strlen($input) > intdiv(PHP_INT_MAX - 4096, 8)) {
            return null;
        }
        try {
            $ffi = RustPipelineLibrary::resolveDomain('cleaning', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, max($needed, strlen($input) * 8 + 4096), $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_name_fixing_query_pass($mode, $input, strlen($input), $first, $second, $buffer, $capacity);

            return is_int($written) && $written >= 0 && $written <= $capacity
                ? ($written === 0 ? '' : FFI::string($buffer, $written)) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param list<string> $fields */
    private static function queryPassFields(array $fields): ?string
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

    protected int $priority = 50;

    protected string $name = 'Abstract';

    /**
     * Get the patterns to check against.
     *
     * @return array<string, string> Pattern name => regex
     */
    abstract protected function getPatterns(): array;

    /**
     * Format the method name for a matched pattern.
     */
    abstract protected function formatMethod(string $patternName): string;

    /**
     * {@inheritdoc}
     */
    public function check(object $release, string $textstring): ?NameFixResult
    {
        foreach ($this->getPatterns() as $patternName => $pattern) {
            $match = $this->matchPattern($pattern, $textstring);
            if ($match['matched']) {
                return NameFixResult::fromMatch(
                    newName: $match['text'],
                    method: $this->formatMethod($patternName),
                    checkerName: $this->getName(),
                );
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->name;
    }

    /** @return array{matched: bool, text: string} */
    private function matchPattern(string $pattern, string $subject): array
    {
        return (self::$useRustFfi ? $this->matchPatternNative($pattern, $subject) : null) ?? $this->matchPatternPhp($pattern, $subject);
    }

    /** @return array{matched: bool, text: string}|null */
    private function matchPatternNative(string $pattern, string $subject): ?array
    {
        $raw = self::queryPassNative(17, self::queryPassFields([$pattern, $subject]));
        $fields = $raw === null ? null : self::queryPassDecode($raw);

        return $fields !== null && count($fields) === 2 ? ['matched' => $fields[0] === '1', 'text' => $fields[1]] : null;
    }

    /** @return array{matched: bool, text: string} */
    private function matchPatternPhp(string $pattern, string $subject): array
    {
        $matched = (bool) preg_match($pattern, $subject, $matches);

        return ['matched' => $matched, 'text' => $matched ? $matches[0] : ''];
    }

    /**
     * Convert a pattern name constant to a readable method description.
     */
    protected function patternNameToMethod(string $patternName): string
    {
        return (self::$useRustFfi ? $this->patternNameToMethodNative($patternName) : null) ?? $this->patternNameToMethodPhp($patternName);
    }

    private function patternNameToMethodNative(string $patternName): ?string
    {
        return self::queryPassNative(4, $patternName);
    }

    protected function patternNameToMethodPhp(string $patternName): string
    {
        return str_replace('_', ' ', strtolower($patternName));
    }

    /** @return list<string>|null */
    private static function queryPassDecode(string $raw): ?array
    {
        $fields = [];
        $offset = 0;
        $length = strlen($raw);
        while ($offset < $length) {
            if ($length - $offset < 4) {
                return null;
            }
            $size = unpack('Vsize', substr($raw, $offset, 4))['size'];
            $offset += 4;
            if ($size > $length - $offset) {
                return null;
            }
            $fields[] = substr($raw, $offset, $size);
            $offset += $size;
        }

        return $fields;
    }
}
