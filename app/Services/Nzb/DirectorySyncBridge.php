<?php

namespace App\Services\Nzb;

use App\Support\RustPipelineLibrary;
use FFI;
use Throwable;

final class DirectorySyncBridge
{
    public const string FFI_CDEF = 'int64_t nntmux_w32_nzb_sync_verification_publication(uint32_t operation,const char *input,size_t length,char *output,size_t capacity);';

    private const int NATIVE_MAX_BYTES = 16777216;

    /**
     * Rust stays opt-in: the JSON FFI round trip is slower than these PHP helpers (< 1.5x); see
     * tests/Unit/RustNzbSyncVerificationPublicationParityTest.php.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public static string $fail = '';

    /** Test-only tracing; production must not retain per-sync history. */
    public static bool $trace = false;

    /** @var list<array<string, bool|int|string|null>> */
    public static array $events = [];

    /** @var \Closure(string, string): void|null */
    public static ?\Closure $afterSync = null;

    /** @param array<string, bool|int|string|null> $event */
    private static function recordEvent(array $event): void
    {
        if (self::$trace) {
            self::$events[] = $event;
        }
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The event fields of one sync probe. $functionValue names the descriptor sync primitive (null when it is
     * unavailable), $extensionLoaded whether this platform and path support a local directory sync, and
     * $syncSucceeded the attempt's result (null when none was made).
     *
     * @return array{ok: bool, failure?: string}
     */
    public static function normalizeBridgeProbeResult(?string $functionValue, bool $extensionLoaded, ?bool $syncSucceeded): array
    {
        return self::usesRustFfi()
            ? self::normalizeBridgeProbeResultNative($functionValue, $extensionLoaded, $syncSucceeded)
            : self::normalizeBridgeProbeResultPhp($functionValue, $extensionLoaded, $syncSucceeded);
    }

    /** @return array{ok: bool, failure?: string} */
    public static function normalizeBridgeProbeResultPhp(?string $functionValue, bool $extensionLoaded, ?bool $syncSucceeded): array
    {
        if ($functionValue === null || $functionValue === '' || ! $extensionLoaded) {
            return ['ok' => false, 'failure' => 'unsupported'];
        }

        return ['ok' => $syncSucceeded === true];
    }

    /** @return array{ok: bool, failure?: string} */
    public static function normalizeBridgeProbeResultNative(?string $functionValue, bool $extensionLoaded, ?bool $syncSucceeded): array
    {
        if (self::transformNative(0, [$functionValue, $extensionLoaded, $syncSucceeded], $result) && \is_array($result)
            && ($result === ['ok' => false, 'failure' => 'unsupported'] || (array_keys($result) === ['ok'] && \is_bool($result['ok'])))) {
            /** @var array{ok: bool, failure?: string} $result */
            return $result;
        }

        return self::normalizeBridgeProbeResultPhp($functionValue, $extensionLoaded, $syncSucceeded);
    }

    /**
     * Shared Wave 32.2 JSON bridge. Rust wraps each result in a one-element array so a null result
     * stays distinct from a declined call; false means Rust declined or is unavailable.
     *
     * @param  array<array-key, mixed>  $args  Positional null/bool/int/string arguments; anything else declines.
     *
     * @param-out mixed $result
     */
    public static function transformNative(int $operation, array $args, mixed &$result = null): bool
    {
        try {
            if (! array_is_list($args)) {
                return false;
            }
            foreach ($args as $arg) {
                if (! ($arg === null || \is_bool($arg) || \is_int($arg) || \is_string($arg))) {
                    return false;
                }
            }
            $input = json_encode($args, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (\strlen($input) > self::NATIVE_MAX_BYTES) {
                return false;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return false;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, min(self::NATIVE_MAX_BYTES, max(256, \strlen($input) * 2 + 256)), $capacity);
            if ($output === null) {
                return false;
            }
            $capacity = min($capacity, self::NATIVE_MAX_BYTES);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w32_nzb_sync_verification_publication($operation, $input, \strlen($input), $output, $capacity);
            if (\is_int($written) && $written < -1 && -$written > $capacity && -$written <= self::NATIVE_MAX_BYTES) {
                $output = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($output === null) {
                    return false;
                }
                $capacity = min($capacity, self::NATIVE_MAX_BYTES);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w32_nzb_sync_verification_publication($operation, $input, \strlen($input), $output, $capacity);
            }
            if (! \is_int($written) || $written <= 0 || $written > $capacity) {
                return false;
            }
            $decoded = json_decode(FFI::string($output, $written), true, 64, JSON_THROW_ON_ERROR);
            if (! \is_array($decoded) || \count($decoded) !== 1 || ! \array_key_exists(0, $decoded)) {
                return false;
            }
            $result = $decoded[0];

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Acknowledges only a successfully synchronized local directory descriptor. */
    public static function sync(string $directory, string $boundary): bool
    {
        if (self::$fail === $boundary) {
            self::recordEvent(['boundary' => $boundary, 'ok' => false, 'injected' => true]);

            return false;
        }

        $probe = self::normalizeBridgeProbeResult(
            function_exists('fsync') ? 'fsync' : null,
            in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) && ! str_contains($directory, '://'),
            null,
        );
        if (isset($probe['failure'])) {
            self::recordEvent(['boundary' => $boundary] + $probe);

            return false;
        }

        $stream = null;
        $warning = false;
        $ok = false;
        set_error_handler(static function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $stream = fopen($directory, 'r');
            if (is_resource($stream)) {
                $metadata = stream_get_meta_data($stream);
                $stat = fstat($stream);
                if ($metadata['wrapper_type'] === 'plainfile' && is_array($stat) && (($stat['mode'] & 0170000) === 0040000)) {
                    $ok = fsync($stream);
                }
            }
        } catch (Throwable) {
            $ok = false;
        } finally {
            try {
                if (is_resource($stream) && ! fclose($stream)) {
                    $ok = false;
                }
            } catch (Throwable) {
                $ok = false;
            } finally {
                restore_error_handler();
            }
        }
        $probe = self::normalizeBridgeProbeResult('fsync', true, $ok && ! $warning);
        $ok = $probe['ok'];
        self::recordEvent(['boundary' => $boundary] + $probe);
        if ($ok && self::$afterSync !== null) {
            (self::$afterSync)($directory, $boundary);
        }

        return $ok;
    }
}
