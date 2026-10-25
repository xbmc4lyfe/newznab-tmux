<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;
use Throwable;

final class CandidateAdmissionDeferredException extends RuntimeException
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_candidate_admission_deferral_summarize(
            int64_t active_workers,
            int64_t max_workers,
            int64_t queue_depth,
            int64_t retry_delay_ms,
            char *out_buf,
            size_t out_cap
        );
        C;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationBudgetIntegrityExceptionsParityTest.php is below 1.5x.
     */
    private const bool RUST_FFI_DEFAULT = false;

    private const int DEFAULT_RETRY_DELAY_MS = 1_000;

    private const int MAX_RETRY_DELAY_MS = 300_000;

    private const int QUEUE_BACKOFF_CAP = 1_000;

    private const int QUEUE_BACKOFF_STEP_MS = 10;

    private const array PRESSURES = ['idle', 'available', 'saturated', 'backlogged'];

    private const string FIELD_SEPARATOR = "\x1F";

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
     * Summarizes the admission deferral policy. Negative counts clamp to zero; a non-positive
     * retry delay uses the 1s default and delays cap at 300s. When no worker slot is free the
     * admission defers and backs off 10ms per queued candidate (up to 1000 candidates).
     *
     * @return array{active_workers: int, max_workers: int, queue_depth: int, available_slots: int, saturated: bool, should_defer: bool, retry_delay_ms: int, delay_clamped: bool, pressure: string}
     */
    public static function summarizeDeferralPolicy(int $activeWorkers, int $maxWorkers, int $queueDepth, int $retryDelayMs): array
    {
        return self::usesRustFfi()
            ? self::summarizeDeferralPolicyNative($activeWorkers, $maxWorkers, $queueDepth, $retryDelayMs)
            : self::summarizeDeferralPolicyPhp($activeWorkers, $maxWorkers, $queueDepth, $retryDelayMs);
    }

    /**
     * @return array{active_workers: int, max_workers: int, queue_depth: int, available_slots: int, saturated: bool, should_defer: bool, retry_delay_ms: int, delay_clamped: bool, pressure: string}
     */
    public static function summarizeDeferralPolicyNative(int $activeWorkers, int $maxWorkers, int $queueDepth, int $retryDelayMs): array
    {
        $fields = self::callNative('nntmux_candidate_admission_deferral_summarize', [$activeWorkers, $maxWorkers, $queueDepth, $retryDelayMs], 256);
        if ($fields === null || count($fields) !== 9 || ! in_array($fields[8], self::PRESSURES, true)) {
            return self::summarizeDeferralPolicyPhp($activeWorkers, $maxWorkers, $queueDepth, $retryDelayMs);
        }
        foreach ([0, 1, 2, 3, 6] as $index) {
            if (! ctype_digit($fields[$index])) {
                return self::summarizeDeferralPolicyPhp($activeWorkers, $maxWorkers, $queueDepth, $retryDelayMs);
            }
        }

        return [
            'active_workers' => (int) $fields[0],
            'max_workers' => (int) $fields[1],
            'queue_depth' => (int) $fields[2],
            'available_slots' => (int) $fields[3],
            'saturated' => $fields[4] === '1',
            'should_defer' => $fields[5] === '1',
            'retry_delay_ms' => (int) $fields[6],
            'delay_clamped' => $fields[7] === '1',
            'pressure' => $fields[8],
        ];
    }

    /**
     * @return array{active_workers: int, max_workers: int, queue_depth: int, available_slots: int, saturated: bool, should_defer: bool, retry_delay_ms: int, delay_clamped: bool, pressure: string}
     */
    public static function summarizeDeferralPolicyPhp(int $activeWorkers, int $maxWorkers, int $queueDepth, int $retryDelayMs): array
    {
        $active = max(0, $activeWorkers);
        $limit = max(0, $maxWorkers);
        $depth = max(0, $queueDepth);
        $available = max(0, $limit - $active);
        $saturated = $available === 0;
        $base = $retryDelayMs <= 0 ? self::DEFAULT_RETRY_DELAY_MS : min(self::MAX_RETRY_DELAY_MS, $retryDelayMs);
        $delay = $saturated
            ? min(self::MAX_RETRY_DELAY_MS, $base + min($depth, self::QUEUE_BACKOFF_CAP) * self::QUEUE_BACKOFF_STEP_MS)
            : $base;

        return [
            'active_workers' => $active,
            'max_workers' => $limit,
            'queue_depth' => $depth,
            'available_slots' => $available,
            'saturated' => $saturated,
            'should_defer' => $saturated,
            'retry_delay_ms' => $delay,
            'delay_clamped' => $retryDelayMs <= 0 || $retryDelayMs > self::MAX_RETRY_DELAY_MS,
            'pressure' => match (true) {
                ! $saturated && $active === 0 && $depth === 0 => 'idle',
                ! $saturated => 'available',
                $depth > 0 => 'backlogged',
                default => 'saturated',
            },
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
