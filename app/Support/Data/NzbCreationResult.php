<?php

declare(strict_types=1);

namespace App\Support\Data;

use App\Support\RustPipelineLibrary;
use FFI;

final readonly class NzbCreationResult
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w17_nzb_xml_verification_timing(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Paired common/uncommon mean and p99 must all pass before adoption.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    /** Reject objects before serialization can invoke user code; bound cycles. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /** @param list<mixed> $arguments */
    private static function computeNative(int $operation, array $arguments): mixed
    {
        if (! self::rustEnabled()) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 512), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w17_nzb_xml_verification_timing($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($output, $written), ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function classifyOutcome(bool $success, string $failureType): string
    {
        return self::classifyOutcomeNative($success, $failureType) ?? self::classifyOutcomePhp($success, $failureType);
    }

    public static function classifyOutcomePhp(bool $success, string $failureType): string
    {
        return $success ? 'success' : $failureType;
    }

    public static function classifyOutcomeNative(bool $success, string $failureType): ?string
    {
        $result = self::computeNative(13, [$success, $failureType]);

        return is_string($result) ? $result : null;
    }

    public static function failureMatches(string $failureType, string $expected): bool
    {
        return self::failureMatchesNative($failureType, $expected) ?? self::failureMatchesPhp($failureType, $expected);
    }

    public static function failureMatchesPhp(string $failureType, string $expected): bool
    {
        return $failureType === $expected;
    }

    public static function failureMatchesNative(string $failureType, string $expected): ?bool
    {
        $result = self::computeNative(14, [$failureType, $expected]);

        return is_bool($result) ? $result : null;
    }

    public const string FAILURE_NONE = 'none';

    public const string FAILURE_DETERMINISTIC = 'deterministic';

    public const string FAILURE_TRANSIENT = 'transient';

    public const string FAILURE_DEFERRED = 'deferred';

    /**
     * @param  list<int>  $collectionIds
     */
    private function __construct(
        public bool $success,
        public string $failureType,
        public string $reason,
        public ?string $path,
        public array $collectionIds,
    ) {}

    /**
     * @param  list<int>  $collectionIds
     */
    public static function success(string $path, array $collectionIds): self
    {
        return new self(true, self::FAILURE_NONE, '', $path, $collectionIds);
    }

    /**
     * @param  list<int>  $collectionIds
     */
    public static function deterministic(string $reason, array $collectionIds = [], ?string $path = null): self
    {
        return new self(false, self::FAILURE_DETERMINISTIC, $reason, $path, $collectionIds);
    }

    /**
     * @param  list<int>  $collectionIds
     */
    public static function transient(string $reason, array $collectionIds = [], ?string $path = null): self
    {
        return new self(false, self::FAILURE_TRANSIENT, $reason, $path, $collectionIds);
    }

    /**
     * @param  list<int>  $collectionIds
     */
    public static function deferred(string $reason, array $collectionIds = [], ?string $path = null): self
    {
        return new self(false, self::FAILURE_DEFERRED, $reason, $path, $collectionIds);
    }

    public function outcome(): string
    {
        return self::classifyOutcome($this->success, $this->failureType);
    }

    public function isDeferred(): bool
    {
        return self::failureMatches($this->failureType, self::FAILURE_DEFERRED);
    }

    public function isDeterministicFailure(): bool
    {
        return self::failureMatches($this->failureType, self::FAILURE_DETERMINISTIC);
    }

    public function isTransientFailure(): bool
    {
        return self::failureMatches($this->failureType, self::FAILURE_TRANSIENT);
    }
}
