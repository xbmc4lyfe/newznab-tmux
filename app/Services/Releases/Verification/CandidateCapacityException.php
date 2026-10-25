<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Nzb\NzbArticleFingerprintUnavailable;
use App\Support\RustPipelineLibrary;
use FFI;
use RuntimeException;

final class CandidateCapacityException extends RuntimeException
{
    public const string FFI_CDEF = NzbArticleFingerprintUnavailable::FFI_CDEF;

    /**
     * Production default stays false: the paired benchmark in
     * tests/Unit/RustVerificationCapacityPrefileSingletonParityTest.php is below 1.5x.
     */
    public const bool RUST_FFI_DEFAULT = false;

    /** Counts are clamped to [0, 2^40] so percentages cannot overflow. */
    private const int COUNT_MAX = 1099511627776;

    private const int UTILIZATION_MAX = 1000;

    private const array REASONS = ['within_capacity', 'candidates', 'bytes', 'candidates_and_bytes'];

    private static ?bool $useRustFfi = null;

    private static FFI|false|null $ffi = null;

    public static function useRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = null;
        self::$ffi = null;
    }

    /**
     * Summarizes verification candidate capacity. Every value is clamped to [0, 2^40]; a zero limit is unlimited and
     * reports -1 available. Utilization is the larger of the two integer percentages, capped at 1000.
     *
     * @return array{active_candidates: int, max_candidates: int, pending_bytes: int, max_pending_bytes: int, available_candidates: int, available_bytes: int, candidates_full: bool, bytes_full: bool, utilization_percent: int, reason: string, at_capacity: bool}
     */
    public static function summarizeCapacity(int $activeCandidates, int $maxCandidates, int $pendingBytes, int $maxPendingBytes): array
    {
        return (self::usesRustFfi() ? self::summarizeCapacityNative($activeCandidates, $maxCandidates, $pendingBytes, $maxPendingBytes) : null)
            ?? self::summarizeCapacityPhp($activeCandidates, $maxCandidates, $pendingBytes, $maxPendingBytes);
    }

    /**
     * @return array{active_candidates: int, max_candidates: int, pending_bytes: int, max_pending_bytes: int, available_candidates: int, available_bytes: int, candidates_full: bool, bytes_full: bool, utilization_percent: int, reason: string, at_capacity: bool}|null
     */
    private static function summarizeCapacityNative(int $activeCandidates, int $maxCandidates, int $pendingBytes, int $maxPendingBytes): ?array
    {
        $s = NzbArticleFingerprintUnavailable::evaluateW372(self::ffi(), 1, 0, pack('q4', $activeCandidates, $maxCandidates, $pendingBytes, $maxPendingBytes), 11);
        if ($s === null || min($s[0], $s[1], $s[2], $s[3]) < 0 || max($s[0], $s[1], $s[2], $s[3]) > self::COUNT_MAX || $s[4] < -1 || $s[5] < -1
            || ! \in_array($s[6], [0, 1], true) || ! \in_array($s[7], [0, 1], true) || $s[8] < 0 || $s[8] > self::UTILIZATION_MAX
            || ! isset(self::REASONS[$s[9]]) || ! \in_array($s[10], [0, 1], true)) {
            return null;
        }

        return [
            'active_candidates' => $s[0],
            'max_candidates' => $s[1],
            'pending_bytes' => $s[2],
            'max_pending_bytes' => $s[3],
            'available_candidates' => $s[4],
            'available_bytes' => $s[5],
            'candidates_full' => $s[6] === 1,
            'bytes_full' => $s[7] === 1,
            'utilization_percent' => $s[8],
            'reason' => self::REASONS[$s[9]],
            'at_capacity' => $s[10] === 1,
        ];
    }

    /**
     * @return array{active_candidates: int, max_candidates: int, pending_bytes: int, max_pending_bytes: int, available_candidates: int, available_bytes: int, candidates_full: bool, bytes_full: bool, utilization_percent: int, reason: string, at_capacity: bool}
     */
    private static function summarizeCapacityPhp(int $activeCandidates, int $maxCandidates, int $pendingBytes, int $maxPendingBytes): array
    {
        $clamp = static fn (int $value): int => max(0, min(self::COUNT_MAX, $value));
        $active = $clamp($activeCandidates);
        $max = $clamp($maxCandidates);
        $pending = $clamp($pendingBytes);
        $maxPending = $clamp($maxPendingBytes);
        $available = static fn (int $used, int $limit): int => $limit > 0 ? max(0, $limit - $used) : -1;
        $utilization = static fn (int $used, int $limit): int => $limit > 0 ? min(self::UTILIZATION_MAX, intdiv($used * 100, $limit)) : 0;
        $candidatesFull = $max > 0 && $active >= $max;
        $bytesFull = $maxPending > 0 && $pending >= $maxPending;

        return [
            'active_candidates' => $active,
            'max_candidates' => $max,
            'pending_bytes' => $pending,
            'max_pending_bytes' => $maxPending,
            'available_candidates' => $available($active, $max),
            'available_bytes' => $available($pending, $maxPending),
            'candidates_full' => $candidatesFull,
            'bytes_full' => $bytesFull,
            'utilization_percent' => max($utilization($active, $max), $utilization($pending, $maxPending)),
            'reason' => self::REASONS[($candidatesFull ? 1 : 0) | ($bytesFull ? 2 : 0)],
            'at_capacity' => $candidatesFull || $bytesFull,
        ];
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain(NzbArticleFingerprintUnavailable::FFI_DOMAIN, self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }
}
