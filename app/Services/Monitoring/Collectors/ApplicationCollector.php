<?php

declare(strict_types=1);

namespace App\Services\Monitoring\Collectors;

use App\Services\Monitoring\Prometheus\MetricFamily;
use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

class ApplicationCollector implements MetricsCollector
{
    private const FFI_CDEF = <<<'CDEF'
    ssize_t nntmux_application_collector_build_version_labels(
        const char *php_ptr,
        size_t php_len,
        const char *laravel_ptr,
        size_t laravel_len,
        size_t *out_lens,
        char *out_buf,
        size_t out_cap
    );
    CDEF;

    /**
     * Production default stays false until benchmark/parity sign-off; see
     * tests/Unit/RustMonitoringProbeComposerParityTest.php.
     */
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
        self::$ffi = null;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public static function setFfiForTesting(FFI|false|null $ffi): void
    {
        self::$ffi = $ffi;
    }

    public function collect(): iterable
    {
        yield MetricFamily::gauge('nntmux_up', 'Whether the NNTmux metrics exporter ran.')->add(1);

        yield MetricFamily::gauge('nntmux_build_info', 'NNTmux runtime versions.')->add(
            1,
            self::buildRuntimeVersionLabels(PHP_VERSION, app()->version())
        );
    }

    /**
     * @return array{php_version: string, laravel_version: string}
     */
    public static function buildRuntimeVersionLabels(string $phpVersion, string $laravelVersion): array
    {
        return self::$useRustFfi
            ? self::buildRuntimeVersionLabelsNative($phpVersion, $laravelVersion)
            : self::buildRuntimeVersionLabelsPhp($phpVersion, $laravelVersion);
    }

    /**
     * @return array{php_version: string, laravel_version: string}
     */
    public static function buildRuntimeVersionLabelsNative(string $phpVersion, string $laravelVersion): array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return self::buildRuntimeVersionLabelsPhp($phpVersion, $laravelVersion);
        }

        try {
            $lensSlot = $ffi->new('size_t[4]');
            $capacity = 0;
            $requiredCap = max(128, strlen($phpVersion) + strlen($laravelVersion) + 64);
            $outBuf = RustPipelineLibrary::obtainScratch($ffi, $requiredCap, $capacity);
            if ($outBuf === null) {
                return self::buildRuntimeVersionLabelsPhp($phpVersion, $laravelVersion);
            }

            $written = self::callFfi(
                $ffi,
                'nntmux_application_collector_build_version_labels',
                $phpVersion,
                strlen($phpVersion),
                $laravelVersion,
                strlen($laravelVersion),
                $lensSlot,
                $outBuf,
                $capacity
            );

            if (is_int($written) && $written >= 0 && $written <= $capacity) {
                $len0 = (int) $lensSlot[0];
                $len1 = (int) $lensSlot[1];
                $len2 = (int) $lensSlot[2];
                $len3 = (int) $lensSlot[3];
                if ($len0 + $len1 + $len2 + $len3 === $written) {
                    $payload = $written === 0 ? '' : FFI::string($outBuf, $written);
                    $k0 = substr($payload, 0, $len0);
                    $v0 = $len1 === 0 ? '' : substr($payload, $len0, $len1);
                    $k1 = substr($payload, $len0 + $len1, $len2);
                    $v1 = $len3 === 0 ? '' : substr($payload, $len0 + $len1 + $len2, $len3);
                    if ($k0 === 'php_version' && $k1 === 'laravel_version') {
                        return [
                            'php_version' => $v0,
                            'laravel_version' => $v1,
                        ];
                    }
                }
            }
        } catch (Throwable) {
        }

        return self::buildRuntimeVersionLabelsPhp($phpVersion, $laravelVersion);
    }

    /**
     * @return array{php_version: string, laravel_version: string}
     */
    public static function buildRuntimeVersionLabelsPhp(string $phpVersion, string $laravelVersion): array
    {
        return [
            'php_version' => $phpVersion,
            'laravel_version' => $laravelVersion,
        ];
    }

    private static function callFfi(FFI $ffi, string $symbol, mixed ...$args): mixed
    {
        return $ffi->$symbol(...$args);
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === false) {
            return null;
        }

        if (self::$ffi instanceof FFI) {
            return self::$ffi;
        }

        $ffi = RustPipelineLibrary::resolveDomain('metadata', self::FFI_CDEF);
        self::$ffi = $ffi ?? false;

        return $ffi;
    }
}
