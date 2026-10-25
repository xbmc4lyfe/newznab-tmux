<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

/**
 * One verified candidate a worker wants the primary to publish. (Each property is readonly; the class itself
 * cannot be, because it holds the static Rust toggle.)
 */
final class RemotePublishItem
{
    public const string FFI_CDEF = CandidateRepository::FFI_CDEF;

    /** Rust stays opt-in: the FFI round trip is slower than this PHP helper (< 1.5x). */
    private const bool RUST_FFI_DEFAULT = false;

    private static ?bool $useRustFfi = null;

    public function __construct(public readonly object $claim, public readonly VerificationResult $result) {}

    public static function setUseRustFfi(?bool $use): void
    {
        self::$useRustFfi = $use;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi ?? self::RUST_FFI_DEFAULT;
    }

    /**
     * The claim fields the primary checks before publishing, cast as the remote publication payload sends them.
     *
     * @return array{id: int, version: int, manifest_hash: string, lease_token: string}
     */
    public function claimPayload(): array
    {
        $id = $this->claim->id;
        $version = $this->claim->version;
        $hash = (string) $this->claim->manifest_hash;
        $token = (string) $this->claim->lease_token;
        if ((\is_int($id) || \is_string($id)) && (\is_int($version) || \is_string($version))) {
            return self::normalizeClaimIdentity($id, $version, $hash, $token);
        }

        return ['id' => (int) $id, 'version' => (int) $version, 'manifest_hash' => $hash, 'lease_token' => $token];
    }

    /**
     * Cast a claim's identity as PHP's (int) and (string) casts do; a null lease token becomes empty.
     *
     * @return array{id: int, version: int, manifest_hash: string, lease_token: string}
     */
    public static function normalizeClaimIdentity(int|string $id, int|string $version, string $manifestHash, ?string $leaseToken): array
    {
        return self::usesRustFfi() ? self::normalizeClaimIdentityNative($id, $version, $manifestHash, $leaseToken)
            : self::normalizeClaimIdentityPhp($id, $version, $manifestHash, $leaseToken);
    }

    /** @return array{id: int, version: int, manifest_hash: string, lease_token: string} */
    public static function normalizeClaimIdentityPhp(int|string $id, int|string $version, string $manifestHash, ?string $leaseToken): array
    {
        return ['id' => (int) $id, 'version' => (int) $version, 'manifest_hash' => $manifestHash, 'lease_token' => (string) $leaseToken];
    }

    /**
     * Rust parses canonical decimal integers only; any other string falls back to PHP's cast.
     *
     * @return array{id: int, version: int, manifest_hash: string, lease_token: string}
     */
    public static function normalizeClaimIdentityNative(int|string $id, int|string $version, string $manifestHash, ?string $leaseToken): array
    {
        $id = (string) $id;
        $version = (string) $version;
        $slots = CandidateRepository::nativeSlots(CandidateRepository::evaluateNative(3, pack('q2', \strlen($id), \strlen($version)).$id.$version), 2);
        if ($slots !== null) {
            return ['id' => $slots[0], 'version' => $slots[1], 'manifest_hash' => $manifestHash, 'lease_token' => (string) $leaseToken];
        }

        return self::normalizeClaimIdentityPhp($id, $version, $manifestHash, $leaseToken);
    }
}
