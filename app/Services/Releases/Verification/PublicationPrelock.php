<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * Work a publication derives from its manifest and unlocked reads before it takes any lock.
 *
 * Every field is optional: a step that was skipped (an outer transaction, a stale claim) or
 * threw leaves its field unset, and the locked section then computes it as it always did.
 * Values are immutable, so each retried transaction attempt reuses them unchanged. (Each property
 * is readonly; the class itself cannot be, because it holds the static Rust toggle.)
 */
final class PublicationPrelock
{
    public const string FFI_CDEF = CandidatePreparationService::FFI_CDEF;

    private const array POLICIES = ['unknown', 'accepted', 'rejected'];

    private const array COVERAGES = ['unknown', 'skipped', 'unusable', 'none', 'required'];

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    /** Wave 39.6 paired benchmarks decide this default; see RustVerificationStorePrelockManifestResetW39ParityTest. */
    public const bool W396_RUST_FFI_DEFAULT = false;

    private static ?bool $w396UseRustFfi = null;

    /**
     * @param  array<string, mixed>|null  $archived  The candidate's archived manifest, read from $archivedPath.
     * @param  bool  $policyKnown  Whether $policyRejection holds an evaluated collection-policy verdict.
     * @param  string|null  $body  The gzipped verified NZB for this exact manifest.
     * @param  array<string, true>|null  $coverageIds  Article IDs the incoming NZB requires an earlier release to cover.
     * @param  bool  $coverageKnown  Whether coverageIds() ran; null with this set means the document has no usable
     *                               inventory (oversized or malformed), which no earlier release can cover.
     */
    public function __construct(
        public readonly ?int $size = null,
        public readonly ?string $archivedPath = null,
        public readonly ?array $archived = null,
        public readonly bool $policyKnown = false,
        public readonly ?string $policyRejection = null,
        public readonly ?string $body = null,
        public readonly ?array $coverageIds = null,
        public readonly bool $coverageKnown = false,
    ) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * Which prelock results the locked section can reuse.
     *
     * @return array{size_known: bool, archive_known: bool, policy: string, coverage: string, coverage_count: ?int, locked_steps: int}
     */
    public function summary(): array
    {
        return self::summarizePrelockState($this->size, $this->archivedPath, $this->policyKnown, $this->policyRejection,
            $this->coverageKnown, $this->coverageIds === null ? null : \count($this->coverageIds));
    }

    /**
     * Summarize prelock work without its payloads. The policy is unknown, accepted or rejected; coverage is
     * unknown, skipped (a rejected candidate never compares coverage), unusable (no usable inventory), none or
     * required. locked_steps counts the steps the locked section must still compute itself.
     *
     * @return array{size_known: bool, archive_known: bool, policy: string, coverage: string, coverage_count: ?int, locked_steps: int}
     */
    public static function summarizePrelockState(?int $size, ?string $archivedPath, bool $policyKnown, ?string $policyRejection, bool $coverageKnown, ?int $coverageCount): array
    {
        return self::usesRustFfi()
            ? self::summarizePrelockStateNative($size, $archivedPath, $policyKnown, $policyRejection, $coverageKnown, $coverageCount)
            : self::summarizePrelockStatePhp($size, $archivedPath, $policyKnown, $policyRejection, $coverageKnown, $coverageCount);
    }

    /** @return array{size_known: bool, archive_known: bool, policy: string, coverage: string, coverage_count: ?int, locked_steps: int} */
    public static function summarizePrelockStatePhp(?int $size, ?string $archivedPath, bool $policyKnown, ?string $policyRejection, bool $coverageKnown, ?int $coverageCount): array
    {
        $policy = ! $policyKnown ? 'unknown' : ($policyRejection === null ? 'accepted' : 'rejected');
        $coverage = $coverageKnown ? ($coverageCount === null ? 'unusable' : ($coverageCount <= 0 ? 'none' : 'required'))
            : ($policy === 'rejected' ? 'skipped' : 'unknown');

        return [
            'size_known' => $size !== null,
            'archive_known' => $archivedPath !== null,
            'policy' => $policy,
            'coverage' => $coverage,
            'coverage_count' => $coverageKnown && $coverageCount !== null ? max(0, $coverageCount) : null,
            'locked_steps' => (int) ($size === null) + (int) ($archivedPath === null) + (int) ! $policyKnown + (int) ($coverage === 'unknown'),
        ];
    }

    /** @return array{size_known: bool, archive_known: bool, policy: string, coverage: string, coverage_count: ?int, locked_steps: int} */
    public static function summarizePrelockStateNative(?int $size, ?string $archivedPath, bool $policyKnown, ?string $policyRejection, bool $coverageKnown, ?int $coverageCount): array
    {
        $result = CandidatePreparationService::evaluateNative(5, pack('q8', (int) ($size !== null), $size ?? 0, (int) ($archivedPath !== null), (int) $policyKnown,
            (int) ($policyRejection !== null), (int) $coverageKnown, (int) ($coverageCount !== null), $coverageCount ?? 0));
        if ($result !== null && \strlen($result) === 56) {
            $slots = array_values(unpack('q7', $result) ?: []);
            if (\count($slots) === 7 && \in_array($slots[0], [0, 1], true) && \in_array($slots[1], [0, 1], true) && isset(self::POLICIES[$slots[2]], self::COVERAGES[$slots[3]])
                && \in_array($slots[4], [0, 1], true) && $slots[5] >= 0 && $slots[6] >= 0 && $slots[6] <= 4) {
                return [
                    'size_known' => $slots[0] === 1,
                    'archive_known' => $slots[1] === 1,
                    'policy' => self::POLICIES[$slots[2]],
                    'coverage' => self::COVERAGES[$slots[3]],
                    'coverage_count' => $slots[4] === 1 ? $slots[5] : null,
                    'locked_steps' => $slots[6],
                ];
            }
        }

        return self::summarizePrelockStatePhp($size, $archivedPath, $policyKnown, $policyRejection, $coverageKnown, $coverageCount);
    }

    public static function useW396RustFfi(?bool $enabled): void
    {
        self::$w396UseRustFfi = $enabled;
    }

    public static function usesW396RustFfi(): bool
    {
        return self::$w396UseRustFfi ?? self::W396_RUST_FFI_DEFAULT;
    }

    public static function resetW396RustFfi(): void
    {
        self::$w396UseRustFfi = null;
    }

    /**
     * Evaluate a prelock lease. It expires at $acquiredAtMs + max(0, $ttlMs) (saturating at PHP_INT_MAX) and
     * is expired once $nowMs reaches that instant. remaining_ms is 0 when expired and saturates at
     * PHP_INT_MAX; a live lease needs renewal within max(0, $renewMarginMs), and is valid only when it is
     * live and not acquired in the future.
     *
     * @return array{expires_at_ms: int, remaining_ms: int, expired: bool, needs_renewal: bool, valid: bool}
     */
    public static function planPrelockLeaseWindow(int $acquiredAtMs, int $ttlMs, int $nowMs, int $renewMarginMs = 5000): array
    {
        return (self::usesW396RustFfi() ? self::planPrelockLeaseWindowNative($acquiredAtMs, $ttlMs, $nowMs, $renewMarginMs) : null)
            ?? self::planPrelockLeaseWindowPhp($acquiredAtMs, $ttlMs, $nowMs, $renewMarginMs);
    }

    /** @return array{expires_at_ms: int, remaining_ms: int, expired: bool, needs_renewal: bool, valid: bool} */
    public static function planPrelockLeaseWindowPhp(int $acquiredAtMs, int $ttlMs, int $nowMs, int $renewMarginMs = 5000): array
    {
        $ttl = max(0, $ttlMs);
        $expires = $acquiredAtMs > PHP_INT_MAX - $ttl ? PHP_INT_MAX : $acquiredAtMs + $ttl;
        $expired = $nowMs >= $expires;
        $remaining = $expired ? 0 : ($nowMs < 0 && $expires > PHP_INT_MAX + $nowMs ? PHP_INT_MAX : $expires - $nowMs);

        return [
            'expires_at_ms' => $expires,
            'remaining_ms' => $remaining,
            'expired' => $expired,
            'needs_renewal' => ! $expired && $remaining <= max(0, $renewMarginMs),
            'valid' => ! $expired && $nowMs >= $acquiredAtMs,
        ];
    }

    /** @return array{expires_at_ms: int, remaining_ms: int, expired: bool, needs_renewal: bool, valid: bool}|null */
    public static function planPrelockLeaseWindowNative(int $acquiredAtMs, int $ttlMs, int $nowMs, int $renewMarginMs = 5000): ?array
    {
        $slots = CollectionReleasePolicy::w396Slots(4, 0, pack('q4', $acquiredAtMs, $ttlMs, $nowMs, $renewMarginMs), 5);
        if ($slots === null || $slots[1] < 0 || ($slots[2] & ~1) !== 0 || ($slots[3] & ~1) !== 0 || ($slots[4] & ~1) !== 0) {
            return null;
        }

        return [
            'expires_at_ms' => $slots[0],
            'remaining_ms' => $slots[1],
            'expired' => $slots[2] === 1,
            'needs_renewal' => $slots[3] === 1,
            'valid' => $slots[4] === 1,
        ];
    }
}
