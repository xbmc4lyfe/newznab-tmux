<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * Single canonical form for API input, shared by HTTP QUERY normalization,
 * search cursor binding, and the usage audit, so equivalent GET and QUERY
 * filters (e.g. `cat=2000,5030` and `{"cat":[2000,5030]}`) compare equal.
 */
final class ApiInputCanonicalizer
{
    /**
     * Parameters that accept either a list or a comma-separated string.
     */
    public const LIST_PARAMETERS = ['cat'];

    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_api_canonicalize_list_csv(const char *input_ptr, size_t input_len, char *out_buf, size_t out_cap);
        C;

    private static FFI|false|null $ffi = null;

    private static ?CData $outBuf512 = null;

    private static bool $useRustFfi = true;

    public function __construct(
        private readonly bool $useNative = true,
    ) {}

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string|array<array-key, mixed>|null>
     */
    public function canonicalize(array $parameters): array
    {
        $canonical = [];
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            $canonical[$key] = in_array($key, self::LIST_PARAMETERS, true)
                ? $this->listValue($value)
                : $this->value($value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return array<string, string|array<array-key, mixed>|null>
     */
    public function canonicalizePhp(array $parameters): array
    {
        $canonical = [];
        foreach ($parameters as $key => $value) {
            $key = (string) $key;
            $canonical[$key] = in_array($key, self::LIST_PARAMETERS, true)
                ? $this->listValuePhp($value)
                : $this->value($value);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    public static function isListParameter(string $key): bool
    {
        return in_array($key, self::LIST_PARAMETERS, true);
    }

    public function canonicalizeListCsv(string $csv): ?string
    {
        if ($this->useNative && self::$useRustFfi && \str_contains($csv, ',')) {
            $supported = false;
            $native = $this->canonicalizeListCsvNative($csv, $supported);
            if ($supported) {
                return $native;
            }
        }

        return $this->canonicalizeListCsvPhp($csv);
    }

    /**
     * @param-out bool $supported
     */
    public function canonicalizeListCsvNative(string $csv, bool &$supported = false): ?string
    {
        $supported = false;
        if (! $this->useNative || ! self::$useRustFfi) {
            return null;
        }
        $len = \strlen($csv);
        if ($len === 0) {
            $supported = true;

            return null;
        }
        $ffi = self::$ffi ?? self::resolveFfi();
        if ($ffi === null || $ffi === false) {
            return null;
        }
        $cap = 512;
        $scratch = $len <= 512 ? self::$outBuf512 : RustPipelineLibrary::obtainScratch($ffi, $len, $cap);
        if ($scratch === null) {
            return null;
        }
        try {
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_api_canonicalize_list_csv($csv, $len, $scratch, $cap);
            if (! \is_int($written)) {
                return null;
            }
            if ($written < -1) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $cap);
                if ($scratch === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_api_canonicalize_list_csv($csv, $len, $scratch, $cap);
                if (! \is_int($written)) {
                    return null;
                }
            }
            if ($written < 0) {
                return null;
            }
            $supported = true;

            return $written === 0 ? null : FFI::string($scratch, $written);
        } catch (Throwable) {
            $supported = false;

            return null;
        }
    }

    public function canonicalizeListCsvPhp(string $csv): ?string
    {
        return $this->listValuePhp($csv);
    }

    private function listValue(mixed $value): ?string
    {
        if (\is_scalar($value)) {
            return $this->canonicalizeListCsv((string) $value);
        }

        return $this->listValuePhp($value);
    }

    private function listValuePhp(mixed $value): ?string
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value)) {
            $items = explode(',', (string) $value);
        } else {
            return null;
        }

        $items = array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '', $items),
            static fn (string $item): bool => $item !== ''
        ));

        return $items === [] ? null : implode(',', $items);
    }

    /**
     * @return string|array<array-key, mixed>|null
     */
    private function value(mixed $value): string|array|null
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        if (is_array($value)) {
            $nested = array_map(fn (mixed $item): string|array|null => $this->value($item), $value);
            ksort($nested, SORT_STRING);

            return $nested;
        }

        return null;
    }

    private static function resolveFfi(): ?FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi === false ? null : self::$ffi;
        }
        $ffi = RustPipelineLibrary::resolveDomain('api_input_canonicalizer', self::FFI_CDEF);
        if ($ffi === null) {
            self::$ffi = false;

            return null;
        }
        try {
            self::$outBuf512 = $ffi->new('char[512]');
            self::$ffi = $ffi;

            return $ffi;
        } catch (Throwable) {
            self::$ffi = false;

            return null;
        }
    }
}
