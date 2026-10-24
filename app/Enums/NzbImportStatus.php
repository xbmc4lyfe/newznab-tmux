<?php

declare(strict_types=1);

namespace App\Enums;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

/**
 * NZB import result values used while processing imported NZB files.
 */
enum NzbImportStatus: string
{
    case Inserted = 'inserted';

    case Queued = 'queued';

    case MetadataIgnored = 'metadata_ignored';

    case MetadataDeferred = 'metadata_deferred';

    case Duplicate = 'duplicate';

    case Blacklisted = 'blacklisted';

    case NoGroup = 'nogroup';

    case Failed = 'failed';

    public const string FFI_CDEF = 'intptr_t nntmux_w22_article_source_enums_log_requests(uint32_t operation, const uint8_t *input, size_t length, uint8_t *output, size_t capacity);';

    public static function enableRustFfi(): void
    {
        self::toggleState(true);
    }

    public static function disableRustFfi(): void
    {
        self::toggleState(false);
    }

    public static function resetRustFfi(): void
    {
        self::toggleState(false);
        self::ffi(true);
    }

    public static function usesRustFfi(): bool
    {
        return self::toggleState();
    }

    private static function toggleState(?bool $next = null): bool
    {
        static $enabled = false;
        if ($next !== null) {
            $enabled = $next;
        }

        return $enabled;
    }

    private static function ffi(bool $reset = false): ?FFI
    {
        static $ffi = null;
        if ($reset) {
            $ffi = null;
        }
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('wave22-article-source-enums-log-requests', self::FFI_CDEF) ?? false;
        }

        return $ffi === false ? null : $ffi;
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<array-key, mixed>|string|null
     */
    private static function computeNative(int $operation, array $arguments): array|string|null
    {
        try {
            foreach ($arguments as $key => $value) {
                if (\ReflectionReference::fromArrayElement($arguments, $key) !== null) {
                    return null;
                }
                if ($operation === 8 && is_array($value)) {
                    if (! array_is_list($value)) {
                        return null;
                    }
                    foreach ($value as $index => $level) {
                        if (! is_string($level) || \ReflectionReference::fromArrayElement($value, $index) !== null) {
                            return null;
                        }
                    }
                } elseif ($value !== null && ! is_string($value) && ! is_bool($value) && ! is_int($value)) {
                    return null;
                }
            }
            $input = serialize($arguments);
            $length = strlen($input);
            if ($length > 64 * 1024 * 1024 - 1024 || ($ffi = self::ffi()) === null) {
                return null;
            }
            $capacity = $length + 1024;
            $inputBuffer = $ffi->new('uint8_t['.max(1, $length).']');
            FFI::memcpy($inputBuffer, $input, $length);
            $output = $ffi->new('uint8_t['.$capacity.']');
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w22_article_source_enums_log_requests($operation, $inputBuffer, $length, $output, $capacity);
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }
            $result = unserialize(FFI::string($output, $written), ['allowed_classes' => false]);

            return is_array($result) || is_string($result) ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function classify(string $value): ?self
    {
        $result = self::usesRustFfi() ? self::classifyNative($value) : self::classifyPhp($value);

        return $result === null ? null : self::tryFrom($result);
    }

    public static function classifyNative(string $value): ?string
    {
        $result = self::computeNative(3, [$value]);

        return is_string($result) ? $result : self::classifyPhp($value);
    }

    public static function classifyPhp(string $value): ?string
    {
        return self::tryFrom($value)?->value;
    }

    public function label(): string
    {
        return (self::usesRustFfi() ? self::labelNative($this->value) : null) ?? self::labelPhp($this->value) ?? $this->value;
    }

    public static function labelNative(string $value): ?string
    {
        $result = self::computeNative(4, [$value]);

        return is_string($result) ? $result : self::labelPhp($value);
    }

    public static function labelPhp(string $value): ?string
    {
        return match ($value) {
            'inserted' => 'Inserted',
            'queued' => 'Queued',
            'metadata_ignored' => 'Metadata Ignored',
            'metadata_deferred' => 'Metadata Deferred',
            'duplicate' => 'Duplicate',
            'blacklisted' => 'Blacklisted',
            'nogroup' => 'No Group',
            'failed' => 'Failed',
            default => null,
        };
    }
}
