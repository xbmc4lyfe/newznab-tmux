<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

final class CandidateMetadataBudgetExceeded extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_candidate_metadata_budget_summarize(
            int64_t consumed_bytes,
            int64_t budget_bytes,
            int64_t file_count,
            int64_t max_files,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBudgetIntegrityExceptionsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int PERCENT_CAP = 1000;

    private const string FIELD_SEPARATOR = "\x1F";

    private const array REASONS = ['bytes_and_files', 'bytes', 'files', 'within_budget'];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    /**
     * Summarizes a candidate metadata budget check. Negative inputs clamp to zero and a zero
     * budget or file limit disables that limit; usage is an integer percentage capped at 1000.
     *
     * @return array{consumed_bytes: int, budget_bytes: int, file_count: int, max_files: int, bytes_exceeded: bool, files_exceeded: bool, overrun_bytes: int, overrun_files: int, usage_percent: int, reason: string, exceeded: bool}
     */
    public static function summarizeBudgetOverrun(int $consumedBytes, int $budgetBytes, int $fileCount, int $maxFiles): array
    {
        return self::usesRustFfi()
            ? self::summarizeBudgetOverrunNative($consumedBytes, $budgetBytes, $fileCount, $maxFiles)
            : self::summarizeBudgetOverrunPhp($consumedBytes, $budgetBytes, $fileCount, $maxFiles);
    }

    /**
     * @return array{consumed_bytes: int, budget_bytes: int, file_count: int, max_files: int, bytes_exceeded: bool, files_exceeded: bool, overrun_bytes: int, overrun_files: int, usage_percent: int, reason: string, exceeded: bool}
     */
    public static function summarizeBudgetOverrunNative(int $consumedBytes, int $budgetBytes, int $fileCount, int $maxFiles): array
    {
        $fields = self::callNative('nntmux_candidate_metadata_budget_summarize', [$consumedBytes, $budgetBytes, $fileCount, $maxFiles], 256);
        if ($fields === null || count($fields) !== 11 || ! in_array($fields[9], self::REASONS, true)) {
            return self::summarizeBudgetOverrunPhp($consumedBytes, $budgetBytes, $fileCount, $maxFiles);
        }
        foreach ([0, 1, 2, 3, 6, 7, 8] as $index) {
            if (! ctype_digit($fields[$index])) {
                return self::summarizeBudgetOverrunPhp($consumedBytes, $budgetBytes, $fileCount, $maxFiles);
            }
        }

        return [
            'consumed_bytes' => (int) $fields[0],
            'budget_bytes' => (int) $fields[1],
            'file_count' => (int) $fields[2],
            'max_files' => (int) $fields[3],
            'bytes_exceeded' => $fields[4] === '1',
            'files_exceeded' => $fields[5] === '1',
            'overrun_bytes' => (int) $fields[6],
            'overrun_files' => (int) $fields[7],
            'usage_percent' => (int) $fields[8],
            'reason' => $fields[9],
            'exceeded' => $fields[10] === '1',
        ];
    }

    /**
     * @return array{consumed_bytes: int, budget_bytes: int, file_count: int, max_files: int, bytes_exceeded: bool, files_exceeded: bool, overrun_bytes: int, overrun_files: int, usage_percent: int, reason: string, exceeded: bool}
     */
    public static function summarizeBudgetOverrunPhp(int $consumedBytes, int $budgetBytes, int $fileCount, int $maxFiles): array
    {
        $consumed = max(0, $consumedBytes);
        $budget = max(0, $budgetBytes);
        $files = max(0, $fileCount);
        $limit = max(0, $maxFiles);
        $bytesExceeded = $budget > 0 && $consumed > $budget;
        $filesExceeded = $limit > 0 && $files > $limit;
        $percent = 0;
        if ($budget > 0) {
            $percent = min(self::PERCENT_CAP, $consumed > intdiv(PHP_INT_MAX, 100)
                ? intdiv($consumed, max(1, intdiv($budget, 100)))
                : intdiv($consumed * 100, $budget));
        }

        return [
            'consumed_bytes' => $consumed,
            'budget_bytes' => $budget,
            'file_count' => $files,
            'max_files' => $limit,
            'bytes_exceeded' => $bytesExceeded,
            'files_exceeded' => $filesExceeded,
            'overrun_bytes' => $bytesExceeded ? $consumed - $budget : 0,
            'overrun_files' => $filesExceeded ? $files - $limit : 0,
            'usage_percent' => $percent,
            'reason' => match (true) {
                $bytesExceeded && $filesExceeded => 'bytes_and_files',
                $bytesExceeded => 'bytes',
                $filesExceeded => 'files',
                default => 'within_budget',
            },
            'exceeded' => $bytesExceeded || $filesExceeded,
        ];
    }

    /**
     * @param  list<int|string>  $args  arguments preceding the caller-owned output buffer
     * @return list<string>|null
     */
    private static function callNative(string $symbol, array $args, int $minBytes, int $limit = PHP_INT_MAX): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }

        try {
            $capacity = 0;
            $buffer = RustPipelineLibrary::obtainScratch($ffi, $minBytes, $capacity);
            if ($buffer === null) {
                return null;
            }
            $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            if (is_int($written) && $written < -1) {
                $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($buffer === null) {
                    return null;
                }
                $written = $ffi->$symbol(...[...$args, $buffer, $capacity]);
            }
            if (! is_int($written) || $written < 0 || $written > $capacity) {
                return null;
            }

            return explode(self::FIELD_SEPARATOR, FFI::string($buffer, $written), $limit);
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
