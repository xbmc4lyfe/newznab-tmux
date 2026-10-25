<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use ReflectionReference;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessRunner
{
    public const string FFI_CDEF = 'int32_t nntmux_w23_mcp_tool_runner(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);';

    // Opt-in only until correctness and end-to-end performance gates pass.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi && RustPipelineLibrary::resolveDomain('mcp-tool-runner', ProcessRunner::FFI_CDEF) !== null;
    }

    public function __construct(
        private readonly SensitiveValueRedactor $redactor,
    ) {}

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{command: list<string>, exit_code: int|null, duration_ms: int, status: string, output: string, truncated: bool}
     */
    public function run(array $command, int $timeoutSeconds, array $environment = []): array
    {
        $startedAt = hrtime(true);
        $process = new Process($command, base_path(), $environment, null, $timeoutSeconds);

        try {
            $process->run();
            $status = $process->isSuccessful() ? 'passed' : 'failed';
            $exitCode = $process->getExitCode();
        } catch (ProcessTimedOutException) {
            $status = 'timed_out';
            $exitCode = null;
        } catch (Throwable $throwable) {
            $status = 'failed';
            $exitCode = null;
            $processOutput = $throwable->getMessage();
        }

        $processOutput ??= trim($process->getOutput()."\n".$process->getErrorOutput());
        [$output, $truncated] = $this->limit($this->redactor->redact($processOutput));

        return self::buildProcessResult(
            $command,
            $exitCode,
            (int) ((hrtime(true) - $startedAt) / 1_000_000),
            $status,
            $output,
            $truncated,
        );
    }

    /**
     * @internal Shared bounded serialization transport for the MCP pure helpers.
     *
     * @param  array<array-key, mixed>  $args
     */
    public static function transformNative(int $op, array $args): mixed
    {
        $maximum = 67_108_864;
        try {
            $remaining = 32_768;
            if (! self::supportsNativeValue($args, 0, $remaining)) {
                return null;
            }
            $input = serialize($args);
            if (strlen($input) > $maximum) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('mcp-tool-runner', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $capacity = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, min($maximum, max(256, strlen($input) + 256)), $capacity);
            if ($scratch === null) {
                return null;
            }
            $capacity = min($maximum, $capacity);
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w23_mcp_tool_runner($op, $input, strlen($input), $scratch, $capacity);
            if (is_int($written) && $written < -1 && -$written <= $maximum && -$written > $capacity) {
                $scratch = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($scratch === null) {
                    return null;
                }
                $capacity = min($maximum, $capacity);
                // @phpstan-ignore method.notFound
                $written = $ffi->nntmux_w23_mcp_tool_runner($op, $input, strlen($input), $scratch, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity) {
                return null;
            }

            return unserialize(FFI::string($scratch, $written), ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reject values whose serialization could execute hooks, lose identity or
     * change type. Inspect references before descending, including cycles.
     */
    private static function supportsNativeValue(mixed $value, int $depth, int &$remaining): bool
    {
        if ($depth > 64 || --$remaining < 0) {
            return false;
        }
        if (! is_array($value)) {
            return $value === null || is_scalar($value);
        }
        foreach ($value as $key => $entry) {
            if (--$remaining < 0 || ReflectionReference::fromArrayElement($value, $key) !== null
                || ! self::supportsNativeValue($entry, $depth + 1, $remaining)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{string, bool} */
    private function limit(string $output): array
    {
        return self::limitOutput($output, (int) config('nntmux-mcp.limits.max_process_output_bytes', 32_768));
    }

    /**
     * @return array{string, bool}
     */
    public static function limitOutput(string $output, int $maximum): array
    {
        return (self::$useRustFfi ? self::limitOutputNative($output, $maximum) : null)
            ?? self::limitOutputPhp($output, $maximum);
    }

    /**
     * @return array{string, bool}
     */
    public static function limitOutputPhp(string $output, int $maximum): array
    {
        $maximum = max(1, $maximum);
        if (strlen($output) <= $maximum) {
            return [$output, false];
        }

        return [mb_strcut($output, 0, $maximum).'\n[output truncated]', true];
    }

    /**
     * @return array{string, bool}|null
     */
    public static function limitOutputNative(string $output, int $maximum): ?array
    {
        $maximum = max(1, $maximum);
        if (strlen($output) > $maximum && (strcasecmp(mb_internal_encoding(), 'UTF-8') !== 0 || ! mb_check_encoding($output, 'UTF-8'))) {
            return null;
        }
        $result = ProcessRunner::transformNative(0, [$output, $maximum]);

        return is_array($result) ? $result : null;
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null, duration_ms: int, status: string, output: string, truncated: bool}
     */
    public static function buildProcessResult(array $command, ?int $exitCode, int $durationMs, string $status, string $output, bool $truncated): array
    {
        return (self::$useRustFfi ? self::buildProcessResultNative($command, $exitCode, $durationMs, $status, $output, $truncated) : null)
            ?? self::buildProcessResultPhp($command, $exitCode, $durationMs, $status, $output, $truncated);
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null, duration_ms: int, status: string, output: string, truncated: bool}
     */
    public static function buildProcessResultPhp(array $command, ?int $exitCode, int $durationMs, string $status, string $output, bool $truncated): array
    {
        return [
            'command' => $command,
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'status' => $status,
            'output' => $output,
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null, duration_ms: int, status: string, output: string, truncated: bool}|null
     */
    public static function buildProcessResultNative(array $command, ?int $exitCode, int $durationMs, string $status, string $output, bool $truncated): ?array
    {
        $result = ProcessRunner::transformNative(1, [$command, $exitCode, $durationMs, $status, $output, $truncated]);

        return is_array($result) ? $result : null;
    }

    /** Wave 54.2 paired benchmarks decide this default; see RustMcpProcessProbeVerificationPlannerRunnerDbFpCollectorW54ParityTest. */
    private const bool W54_RUST_FFI_DEFAULT = true;

    private const string W54_FFI_CDEF = <<<'C'
        intptr_t nntmux_w542_process_output_limit_batch(const char *outputs, size_t outputs_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /**
     * Wave 54 routing, binding and output buffer, kept in function-local statics. Operations:
     * null reads the routing override, 'set' replaces it (returning the previous one), 'ffi'
     * resolves the binding and 'out' returns `[binding, buffer, capacity]` with room for at
     * least $val words (null without the library).
     */
    private static function w54RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w54', self::W54_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w54RustFfiState('ffi');
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

    public static function usesW54RustFfi(): bool
    {
        $enabled = self::w54RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W54_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planOutputLimitBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
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
     * Batch output limit plan, as run() applies limitOutputPhp() to each redacted output. Two
     * words per output: the kept byte length and `flags | crc32(limited output) << 32`. Flags:
     * 1 truncated, 2 empty, 4 the cut backed off a multibyte sequence, 8 maximum below 1, 16
     * holds a NUL byte, 32 invalid UTF-8. Truncated outputs, input bytes, kept bytes, invalid
     * outputs, the longest input and the XXH64 digest of all earlier words follow.
     *
     * @param  list<string>  $outputs
     * @param  list<int>  $maximums
     * @return list<int>
     */
    public static function planOutputLimitBatch(array $outputs, array $maximums): array
    {
        return self::usesW54RustFfi()
            ? self::planOutputLimitBatchNative($outputs, $maximums)
            : self::planOutputLimitBatchPhp($outputs, $maximums);
    }

    /**
     * @param  list<string>  $outputs
     * @param  list<int>  $maximums
     * @return list<int>
     */
    public static function planOutputLimitBatchPhp(array $outputs, array $maximums): array
    {
        $maximums = array_values($maximums);
        $out = [];
        $truncatedCount = $input = $keptTotal = $invalid = $longest = 0;
        foreach (array_values($outputs) as $i => $output) {
            $requested = $maximums[$i] ?? 0;
            [$limited, $truncated] = self::limitOutputPhp($output, $requested);
            $length = \strlen($output);
            $kept = $truncated ? \strlen($limited) - 20 : $length;
            $bad = ! mb_check_encoding($output, 'UTF-8');
            $truncatedCount += (int) $truncated;
            $input += $length;
            $keptTotal += $kept;
            $invalid += (int) $bad;
            $longest = max($longest, $length);
            $out[] = $kept;
            $out[] = ($truncated ? 1 : 0) | ($output === '' ? 2 : 0) | ($truncated && $kept < max(1, $requested) ? 4 : 0)
                | ($requested < 1 ? 8 : 0) | (str_contains($output, "\0") ? 16 : 0) | ($bad ? 32 : 0) | crc32($limited) << 32;
        }
        array_push($out, $truncatedCount, $input, $keptTotal, $invalid, $longest);
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the internal encoding is not
     * UTF-8 (mb_strcut() cuts by it), or the lists differ in length or are not lists.
     *
     * @param  list<string>  $outputs
     * @param  list<int>  $maximums
     * @return list<int>
     */
    public static function planOutputLimitBatchNative(array $outputs, array $maximums): array
    {
        $count = \count($outputs);
        $words = 2 * $count + 6;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w54RustFfiState('out', $words);
        if ($state === null || \count($maximums) !== $count || ! array_is_list($outputs) || ! array_is_list($maximums)
            || strcasecmp(mb_internal_encoding(), 'UTF-8') !== 0) {
            return self::planOutputLimitBatchPhp($outputs, $maximums);
        }
        $blob = implode('', $outputs);
        $ints = pack('P*', ...array_map(strlen(...), $outputs), ...$maximums);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W54_FFI_CDEF.)
            $written = $ffi->nntmux_w542_process_output_limit_batch($blob, \strlen($blob), $ints, \strlen($ints), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : self::planOutputLimitBatchPhp($outputs, $maximums);
    }
}
