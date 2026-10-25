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

    /**
     * Enabled: the paired benchmark in the W54 parity test measures 4.34x common mean, 3.39x common
     * p99, 4.04x uncommon mean and 2.95x uncommon p99 (per-metric minimum of three runs), above the
     * 1.5x rule.
     */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = 'ssize_t nntmux_w541_version_code_batch(const char *versions, size_t versions_len, size_t count, int64_t *out, size_t out_cap);';

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /** Runs $callback with the Wave 54 batch routed to Rust or PHP, then restores the routing. */
    public static function withW54RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w54RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w54RustFfiState('set', $previous);
        }
    }

    /**
     * One comparable code per runtime version string: the leading
     * `[vV]?major(.minor)?(.patch)?` (1-6 digits each, missing parts 0) packed as
     * `major << 40 | minor << 20 | patch`, or -1 when the string does not start with a version.
     *
     * @param  array<array-key, string>  $versions
     * @return list<int>
     */
    public static function runtimeVersionCodes(array $versions): array
    {
        return self::usesW54RustFfi() ? self::runtimeVersionCodesNative($versions) : self::runtimeVersionCodesPhp($versions);
    }

    /**
     * @param  array<array-key, string>  $versions
     * @return list<int>
     */
    public static function runtimeVersionCodesPhp(array $versions): array
    {
        $codes = [];
        foreach ($versions as $version) {
            if (preg_match('/\A[vV]?(\d{1,6})(?:\.(\d{1,6}))?(?:\.(\d{1,6}))?/', $version, $match) !== 1) {
                $codes[] = -1;

                continue;
            }
            $codes[] = ((int) $match[1] << 40) | ((int) ($match[2] ?? 0) << 20) | (int) ($match[3] ?? 0);
        }

        return $codes;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing or declines the batch (a version
     * holding a NUL byte).
     *
     * @param  array<array-key, string>  $versions
     * @return list<int>
     */
    public static function runtimeVersionCodesNative(array $versions): array
    {
        $count = \count($versions);
        $buffer = $count === 0 ? null : self::w54Buffer($count);
        if ($buffer === null) {
            return self::runtimeVersionCodesPhp($versions);
        }
        [$ffi, $out, $cap] = $buffer;
        $joined = implode("\0", $versions);
        try {
            // @phpstan-ignore method.notFound
            $codes = self::w54Decode($out, $ffi->nntmux_w541_version_code_batch($joined, \strlen($joined), $count, $out, $cap), $count);
        } catch (Throwable) {
            $codes = null;
        }

        return $codes !== null && \count($codes) === $count ? $codes : self::runtimeVersionCodesPhp($versions);
    }

    /**
     * Lazily resolved binding plus the reusable output buffer sized for `$words` words.
     *
     * @return array{0: FFI, 1: FFI\CData, 2: int}|null
     */
    private static function w54Buffer(int $words): ?array
    {
        $buffer = self::w54RustFfiState('out', $words);

        return \is_array($buffer) ? $buffer : null;
    }

    /** @return list<int>|null The `$written` words the export reported, or null when it declined. */
    private static function w54Decode(FFI\CData $out, mixed $written, int $maxWords): ?array
    {
        if (! \is_int($written) || $written < 0 || $written > $maxWords) {
            return null;
        }
        if ($written === 0) {
            return [];
        }
        $decoded = unpack('P*', FFI::string($out, $written * 8));

        /** @var list<int>|null */
        return \is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Wave 54 routing override (`set`) plus the lazily resolved binding and reusable `int64_t`
     * output buffer (`out`, sized for `$val` words), kept in function-local statics.
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'out':
                $ffi ??= RustPipelineLibrary::resolveDomain('application_collector_w54', self::W54_FFI_CDEF) ?? false;
                if (! $ffi instanceof FFI || ! \is_int($val) || $val < 1) {
                    return null;
                }
                if (! $out instanceof FFI\CData || $outCap < $val) {
                    $outCap = max(512, $val * 2);
                    $out = $ffi->new("int64_t[{$outCap}]");
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled;
        }
    }
}
