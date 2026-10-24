<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Support\RustPipelineLibrary;
use FFI;
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
}
