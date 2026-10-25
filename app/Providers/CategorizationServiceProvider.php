<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Categorization\CategorizationPipeline;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\ServiceProvider;
use Throwable;

class CategorizationServiceProvider extends ServiceProvider
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_categorization_service_provider_binding_metadata(
            const char *input_ptr,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string DEFAULT_ALIAS = 'categorization';

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustCategorizationProvidersEventsInformParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const string TRIM_CHARS = " \t\n\r\0\x0B\x0C";

    private const string CLASS_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/';

    private const string ALIAS_PATTERN = '/\A[a-z][a-z0-9_.\-]{0,63}\z/';

    private const string FIELD_SEPARATOR = "\x1F";

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the pipeline as a singleton
        $this->app->singleton(CategorizationPipeline::class, function ($app) {
            return CategorizationPipeline::createDefault();
        });

        // Alias for easier access
        $this->app->alias(CategorizationPipeline::class, self::DEFAULT_ALIAS);
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
     * Normalizes a container binding: the class loses padding and leading backslashes, the alias
     * is trimmed and lower-cased, and both are checked against PHP identifier/alias syntax.
     *
     * @return array{service_class: string, alias: string, short_name: string, namespace: string, class_valid: bool, alias_valid: bool, is_default: bool}
     */
    public static function normalizeBindingMetadata(string $serviceClass, string $alias): array
    {
        return self::usesRustFfi()
            ? self::normalizeBindingMetadataNative($serviceClass, $alias)
            : self::normalizeBindingMetadataPhp($serviceClass, $alias);
    }

    /**
     * @return array{service_class: string, alias: string, short_name: string, namespace: string, class_valid: bool, alias_valid: bool, is_default: bool}
     */
    public static function normalizeBindingMetadataNative(string $serviceClass, string $alias): array
    {
        $fields = self::callNative('nntmux_categorization_service_provider_binding_metadata', $serviceClass.self::FIELD_SEPARATOR.$alias);
        if ($fields === null || count($fields) !== 7) {
            return self::normalizeBindingMetadataPhp($serviceClass, $alias);
        }

        return [
            'service_class' => $fields[0],
            'alias' => $fields[1],
            'short_name' => $fields[2],
            'namespace' => $fields[3],
            'class_valid' => $fields[4] === '1',
            'alias_valid' => $fields[5] === '1',
            'is_default' => $fields[6] === '1',
        ];
    }

    /**
     * @return array{service_class: string, alias: string, short_name: string, namespace: string, class_valid: bool, alias_valid: bool, is_default: bool}
     */
    public static function normalizeBindingMetadataPhp(string $serviceClass, string $alias): array
    {
        $class = ltrim(trim($serviceClass, self::TRIM_CHARS), '\\');
        $alias = strtolower(trim($alias, self::TRIM_CHARS));
        $position = strrpos($class, '\\');

        return [
            'service_class' => $class,
            'alias' => $alias,
            'short_name' => $position === false ? $class : substr($class, $position + 1),
            'namespace' => $position === false ? '' : substr($class, 0, $position),
            'class_valid' => preg_match(self::CLASS_PATTERN, $class) === 1,
            'alias_valid' => preg_match(self::ALIAS_PATTERN, $alias) === 1,
            'is_default' => $class === CategorizationPipeline::class && $alias === self::DEFAULT_ALIAS,
        ];
    }

    /**
     * @return list<string>|null
     */
    private static function callNative(string $symbol, string $input): ?array
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
            $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol($input, strlen($input), $buffer, $capacity);
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
