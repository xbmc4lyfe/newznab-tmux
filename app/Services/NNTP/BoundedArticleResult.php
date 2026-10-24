<?php

declare(strict_types=1);

namespace App\Services\NNTP;

use App\Services\Yenc\YencPart;
use App\Support\RustPipelineLibrary;
use FFI;

/** A complete bounded article, or a failure with no usable body or metadata. */
final readonly class BoundedArticleResult
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

    private function __construct(
        public ?string $body,
        public ?YencPart $part,
        public ?string $error,
        public bool $oversized,
    ) {}

    public static function success(string $body, ?YencPart $part = null): self
    {
        return new self($body, $part, null, false);
    }

    public static function failure(string $error, bool $oversized = false): self
    {
        return new self(null, null, $error, $oversized);
    }

    public function outcome(): string
    {
        return self::useRustFfi() ? ($this->outcomeNative() ?? $this->outcomePhp()) : $this->outcomePhp();
    }

    public function outcomePhp(): string
    {
        return $this->body !== null && $this->error === null ? 'success' : ($this->oversized ? 'oversized' : 'failure');
    }

    public function outcomeNative(): ?string
    {
        $value = self::computeNative(10, [$this->body !== null, $this->error, $this->oversized])['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    public static function exceedsByteLimit(string $body, int $limit): bool
    {
        return self::useRustFfi() ? (self::exceedsByteLimitNative($body, $limit) ?? self::exceedsByteLimitPhp($body, $limit)) : self::exceedsByteLimitPhp($body, $limit);
    }

    public static function exceedsByteLimitPhp(string $body, int $limit): bool
    {
        return strlen($body) > $limit;
    }

    public static function exceedsByteLimitNative(string $body, int $limit): ?bool
    {
        $value = self::computeNative(11, [strlen($body), $limit])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function isSuccess(): bool
    {
        return self::useRustFfi() ? ($this->isSuccessNative() ?? $this->isSuccessPhp()) : $this->isSuccessPhp();
    }

    public function isSuccessPhp(): bool
    {
        return $this->body !== null && $this->error === null;
    }

    public function isSuccessNative(): ?bool
    {
        $value = self::computeNative(12, [$this->body !== null, $this->error])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function hasMetadata(): bool
    {
        return self::useRustFfi() ? ($this->hasMetadataNative() ?? $this->hasMetadataPhp()) : $this->hasMetadataPhp();
    }

    public function hasMetadataPhp(): bool
    {
        return $this->part !== null;
    }

    public function hasMetadataNative(): ?bool
    {
        $value = self::computeNative(13, [$this->part !== null])['value'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
