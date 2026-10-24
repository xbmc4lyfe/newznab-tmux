<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class AdminLogViewerRequest extends FormRequest
{
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

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $file = $this->input('file');
        $query = $this->input('q', $this->input('search'));

        $this->merge(self::usesRustFfi() ? self::normalizeInputNative($file, $query) : self::normalizeInputPhp($file, $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{file: mixed, q: mixed} */
    public static function normalizeInputNative(mixed $file, mixed $query): array
    {
        $result = self::computeNative(9, [$file, $query]);

        return is_array($result) && array_keys($result) === ['file', 'q'] ? $result : self::normalizeInputPhp($file, $query);
    }

    /** @return array{file: mixed, q: mixed} */
    public static function normalizeInputPhp(mixed $file, mixed $query): array
    {
        return [
            'file' => is_string($file) ? trim($file) : $file,
            'q' => is_string($query) ? trim($query) : $query,
        ];
    }
}
