<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\PostProcessService;
use App\Services\ReleaseProcessingService;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\ServiceProvider;
use Throwable;

class ProcessingServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_processing_service_provider_normalize_registry(
            size_t count,
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    /** Singletons registered by this provider, in registration order. */
    public const array SERVICES = [PostProcessService::class, ReleaseProcessingService::class];

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategorizationProvidersEventsInformParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const string CLASS_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/';

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PostProcessService::class, function ($app) {
            return new PostProcessService;
        });

        $this->app->singleton(ReleaseProcessingService::class, function ($app) {
            return new ReleaseProcessingService;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Normalizes a list of service classes: entries are trimmed and lose leading backslashes,
     * non-strings and invalid class names are counted and dropped, and case-insensitive
     * duplicates keep their first spelling.
     *
     * @param  array<mixed>  $serviceClasses
     * @return array{services: list<string>, short_names: list<string>, count: int, duplicates: int, invalid: int, is_default: bool}
     */
    public static function normalizeServiceRegistry(array $serviceClasses): array
    {
        return self::usesRustFfi()
            ? self::normalizeServiceRegistryNative($serviceClasses)
            : self::normalizeServiceRegistryPhp($serviceClasses);
    }

    /**
     * @param  array<mixed>  $serviceClasses
     * @return array{services: list<string>, short_names: list<string>, count: int, duplicates: int, invalid: int, is_default: bool}
     */
    public static function normalizeServiceRegistryNative(array $serviceClasses): array
    {
        $strings = array_values(array_filter($serviceClasses, is_string(...)));
        $fields = self::callNative('nntmux_processing_service_provider_normalize_registry', count($strings), implode(self::FIELD_SEPARATOR, $strings));
        $count = $fields === null ? -1 : count($fields);
        if ($fields === null || $count < 4 || ! ctype_digit($fields[0]) || $count !== 4 + 2 * (int) $fields[0]
            || ! ctype_digit($fields[1]) || ! ctype_digit($fields[2])) {
            return self::normalizeServiceRegistryPhp($serviceClasses);
        }

        $services = [];
        $shortNames = [];
        for ($i = 4; $i < $count; $i += 2) {
            $services[] = $fields[$i];
            $shortNames[] = $fields[$i + 1];
        }

        return [
            'services' => $services,
            'short_names' => $shortNames,
            'count' => (int) $fields[0],
            'duplicates' => (int) $fields[1],
            'invalid' => (int) $fields[2] + count($serviceClasses) - count($strings),
            'is_default' => $fields[3] === '1',
        ];
    }

    /**
     * @param  array<mixed>  $serviceClasses
     * @return array{services: list<string>, short_names: list<string>, count: int, duplicates: int, invalid: int, is_default: bool}
     */
    public static function normalizeServiceRegistryPhp(array $serviceClasses): array
    {
        $services = [];
        $shortNames = [];
        $seen = [];
        $duplicates = 0;
        $invalid = 0;
        foreach ($serviceClasses as $entry) {
            if (! is_string($entry)) {
                $invalid++;

                continue;
            }
            $class = ltrim(trim($entry, self::TRIM_CHARS), '\\');
            if (preg_match(self::CLASS_PATTERN, $class) !== 1) {
                $invalid++;

                continue;
            }
            $key = strtolower($class);
            if (isset($seen[$key])) {
                $duplicates++;

                continue;
            }
            $seen[$key] = true;
            $services[] = $class;
            $position = strrpos($class, '\\');
            $shortNames[] = $position === false ? $class : substr($class, $position + 1);
        }

        return [
            'services' => $services,
            'short_names' => $shortNames,
            'count' => count($services),
            'duplicates' => $duplicates,
            'invalid' => $invalid,
            'is_default' => $services === self::SERVICES,
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function callNative(string $symbol, int $count, string $input): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) * 2 + 64, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol($count, $input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($count, $input, strlen($input), $buffer, $capacity);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written));
        } catch (Throwable) {
            return null;
        }
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('categorize', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
