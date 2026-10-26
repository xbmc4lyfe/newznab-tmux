<?php

namespace App\Services\Nzb;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
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

    /** The Wave 41.2 export shared by the directory sync, NFO import, coverage, creation claim and import status helpers. */
    public const string W412_FFI_CDEF = <<<'C'
        int32_t nntmux_w412_nzb_sync_nfo_import_coverage_candidate(
            uint32_t operation,
            uint32_t flags,
            const char *input,
            size_t input_len,
            char *out_buf,
            size_t out_cap
        );
        C;

    public const string W412_FFI_DOMAIN = 'nfo_w412';

    /**
     * Default for the Wave 41.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbSyncNfoImportCoverageCandidateW41ParityTest.php is below 1.5x.
     */
    public const bool W412_RUST_FFI_DEFAULT = false;

    public const int W412_MAX_INPUT = 16777216;

    private static ?bool $useW412RustFfi = null;

    public static function useW412RustFfi(bool $enable = true): void
    {
        self::$useW412RustFfi = $enable;
    }

    public static function usesW412RustFfi(): bool
    {
        return self::$useW412RustFfi ?? self::W412_RUST_FFI_DEFAULT;
    }

    public static function resetW412RustFfi(): void
    {
        self::$useW412RustFfi = null;
    }

    /**
     * Run one Wave 41.2 operation; null whenever the library, input or answer is unusable, so callers fall back to PHP.
     *
     * @return list<int>|null
     */
    public static function evaluateW412Native(int $operation, string $input, int $slots): ?array
    {
        try {
            if ($operation < 0 || $operation > 0xFFFFFFFF || $slots < 1 || $input === '' || \strlen($input) > self::W412_MAX_INPUT) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain(self::W412_FFI_DOMAIN, self::W412_FFI_CDEF, getenv('NNTMUX_PIPELINE_FFI_LIB') ?: null);
            if (! $ffi instanceof FFI) {
                return null;
            }
            $needed = $slots * 8;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $capacity = 0;
                $output = RustPipelineLibrary::obtainScratch($ffi, $needed, $capacity);
                if ($output === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w412_nzb_sync_nfo_import_coverage_candidate($operation, 0, $input, \strlen($input), $output, $capacity);
                if (! \is_int($written) || $written === -1 || $written > $capacity) {
                    return null;
                }
                if ($written < 0) {
                    // Too small: retry once with the reported capacity.
                    $needed = -$written;

                    continue;
                }
                if ($written !== $slots * 8) {
                    return null;
                }
                $values = array_values(unpack('q'.$slots, FFI::string($output, $written)) ?: []);

                return \count($values) === $slots ? $values : null;
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Summarize directory sync attempts (`function`, `extension`, `synced`, `elapsed_ms`) the way
     * normalizeBridgeProbeResultPhp() classifies one probe: unsupported unless `function` is a non-empty string and
     * `extension` is true, otherwise ok only when `synced` is true. Elapsed times count only as integers, negatives as
     * zero; slow means above max(0, $slowMs). The elapsed total saturates at PHP_INT_MAX.
     *
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, ok: int, failed: int, unsupported: int, slow: int, total_elapsed_ms: int, max_elapsed_ms: int}
     */
    public static function summarizeDirectorySyncAttempts(array $attempts, int $slowMs = 250): array
    {
        return (self::usesW412RustFfi() ? self::summarizeDirectorySyncAttemptsNative($attempts, $slowMs) : null)
            ?? self::summarizeDirectorySyncAttemptsPhp($attempts, $slowMs);
    }

    /**
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, ok: int, failed: int, unsupported: int, slow: int, total_elapsed_ms: int, max_elapsed_ms: int}
     */
    public static function summarizeDirectorySyncAttemptsPhp(array $attempts, int $slowMs = 250): array
    {
        $threshold = max(0, $slowMs);
        $ok = $failed = $unsupported = $slow = $total = $max = 0;
        foreach ($attempts as $attempt) {
            [$function, $extension, $synced, $elapsed] = self::syncAttemptFields($attempt);
            $elapsed = max(0, $elapsed);
            if ($function === 0 || $extension === 0) {
                $unsupported++;
            } elseif ($synced === 1) {
                $ok++;
            } else {
                $failed++;
            }
            $slow += $elapsed > $threshold ? 1 : 0;
            $total = $total > PHP_INT_MAX - $elapsed ? PHP_INT_MAX : $total + $elapsed;
            $max = max($max, $elapsed);
        }

        return ['attempts' => \count($attempts), 'ok' => $ok, 'failed' => $failed, 'unsupported' => $unsupported, 'slow' => $slow,
            'total_elapsed_ms' => $total, 'max_elapsed_ms' => $max];
    }

    /**
     * @param  array<array-key, mixed>  $attempts
     * @return array{attempts: int, ok: int, failed: int, unsupported: int, slow: int, total_elapsed_ms: int, max_elapsed_ms: int}|null
     */
    public static function summarizeDirectorySyncAttemptsNative(array $attempts, int $slowMs = 250): ?array
    {
        if (\count($attempts) * 4 + 1 > self::W412_MAX_INPUT / 8) {
            return null;
        }
        $words = [$slowMs];
        foreach ($attempts as $attempt) {
            array_push($words, ...self::syncAttemptFields($attempt));
        }
        $slots = self::evaluateW412Native(0, pack('q*', ...$words), 7);
        if ($slots === null) {
            return null;
        }
        [$count, $ok, $failed, $unsupported, $slow, $total, $max] = $slots;
        if ($count !== \count($attempts) || min($slots) < 0 || $ok + $failed + $unsupported !== $count || $slow > $count || $max > $total) {
            return null;
        }

        return ['attempts' => $count, 'ok' => $ok, 'failed' => $failed, 'unsupported' => $unsupported, 'slow' => $slow,
            'total_elapsed_ms' => $total, 'max_elapsed_ms' => $max];
    }

    /** @return array{0|1, 0|1, -1|0|1, int} function present, extension loaded, sync result (-1 none) and elapsed ms */
    private static function syncAttemptFields(mixed $attempt): array
    {
        if (! \is_array($attempt)) {
            return [0, 0, -1, 0];
        }
        $function = $attempt['function'] ?? null;
        $synced = $attempt['synced'] ?? null;
        $elapsed = $attempt['elapsed_ms'] ?? 0;

        return [\is_string($function) && $function !== '' ? 1 : 0, ($attempt['extension'] ?? false) === true ? 1 : 0,
            $synced === true ? 1 : ($synced === false ? 0 : -1), \is_int($elapsed) ? $elapsed : 0];
    }

    /** Wave 56.2 paired benchmarks decide this default; see RustDirectorySyncCoverageCreationImportNzbServiceW56ParityTest. */
    private const bool W56_RUST_FFI_DEFAULT = true;

    private const string W56_FFI_CDEF = <<<'C'
        intptr_t nntmux_w56_directory_sync_plan_batch(const char *paths, size_t paths_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 56 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w56RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $capacity = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = \is_bool($val) ? $val : null;

                return $previous;
            case 'ffi':
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w56', self::W56_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w56RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW56RustFfi(): bool
    {
        $enabled = self::w56RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W56_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planDirectorySyncBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW56RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w56RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w56RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w56Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w56Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /**
     * Batch directory sync plan: per path, `flags | min(normalized length, 0xFFFFFF) << 8 |
     * crc32(normalized) << 32` and, for sync candidates, the index of the first candidate with
     * the same parent directory (-1 otherwise). Stream wrappers (`://`) stay as given; other
     * paths get backslashes as slashes, slash runs collapsed and one trailing slash dropped
     * (except for the root). Flags: 1 stream wrapper, 2 absolute, 4 normalization changed the
     * path, 8 repeats an earlier normalized path, 16 local sync candidate, 32 empty. Candidates,
     * wrappers, duplicates, empties, distinct candidate parents and the XXH64 digest of all
     * earlier words follow.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function planDirectorySyncBatch(array $paths): array
    {
        return self::usesW56RustFfi() ? self::planDirectorySyncBatchNative($paths) : self::planDirectorySyncBatchPhp($paths);
    }

    /**
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function planDirectorySyncBatchPhp(array $paths): array
    {
        $out = $seen = $parents = [];
        $candidates = $wrappers = $duplicates = $empties = 0;
        foreach (array_values($paths) as $i => $path) {
            $wrapper = str_contains($path, '://');
            $norm = $wrapper ? $path : self::w56SyncPath($path);
            $flags = ($wrapper ? 1 : 0) | (! $wrapper && str_starts_with($norm, '/') ? 2 : 0) | ($norm !== $path ? 4 : 0);
            $first = -1;
            if ($norm === '') {
                $flags |= 32;
                $empties++;
            } elseif (isset($seen[$norm])) {
                $flags |= 8;
                $duplicates++;
            } else {
                $seen[$norm] = true;
                if (! $wrapper) {
                    $flags |= 16;
                    $candidates++;
                    $slash = strrpos($norm, '/');
                    $first = $parents[$slash === false ? '.' : ($slash === 0 ? '/' : substr($norm, 0, $slash))] ??= $i;
                }
            }
            $wrappers += $wrapper ? 1 : 0;
            array_push($out, $flags | min(\strlen($norm), 0xFFFFFF) << 8 | crc32($norm) << 32, $first);
        }
        array_push($out, $candidates, $wrappers, $duplicates, $empties, \count($parents));
        $out[] = self::w56Digest($out);

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a path holds a NUL byte or the
     * paths are not a list.
     *
     * @param  list<string>  $paths
     * @return list<int>
     */
    public static function planDirectorySyncBatchNative(array $paths): array
    {
        $count = \count($paths);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w56RustFfiState('out', $words);
        if ($state === null || ! array_is_list($paths)) {
            return self::planDirectorySyncBatchPhp($paths);
        }
        $blob = implode("\0", $paths);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W56_FFI_CDEF.)
            $written = $ffi->nntmux_w56_directory_sync_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w56Words($written, $out, $words) ?? self::planDirectorySyncBatchPhp($paths);
    }

    private static function w56SyncPath(string $path): string
    {
        $norm = str_replace('\\', '/', $path);
        while (str_contains($norm, '//')) {
            $norm = str_replace('//', '/', $norm);
        }

        return $norm !== '/' && str_ends_with($norm, '/') ? substr($norm, 0, -1) : $norm;
    }
}
