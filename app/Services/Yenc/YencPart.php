<?php

declare(strict_types=1);

namespace App\Services\Yenc;

use App\Support\RustPipelineLibrary;
use FFI;

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
}
