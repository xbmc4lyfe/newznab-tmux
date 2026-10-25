<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Par2PacketAccumulator;
use dariusiii\rarinfo\Par2Info;

/**
 * Only complete, unambiguous equivalent metadata can share a successful source proof.
 *
 * Retains PHP under the < 1.5x FFI bailout rule: `nntmux_par2_proof_identity` in
 * `rust/pipeline-ffi/src/metadata.rs` is hash-identical but must marshal every duplicated
 * packet through `serialize()`, while PHP serializes only the deduplicated canonical set
 * (measured 0.47x-0.78x mean).
 */
final class Par2ProofIdentity
{
    /**
     * Default for the Wave 44.2 toggle only (summarizeProofIdentities); false because the paired benchmark in
     * tests/Unit/RustPar2PacketMetadataProofPayloadSanitizerW44ParityTest.php is below 1.5x (common mean 0.46-0.53x).
     */
    public const bool W442_RUST_FFI_DEFAULT = false;

    private static ?bool $useW442RustFfi = null;

    /** Force the Wave 44.2 helper onto Rust (true) or PHP (false); null restores W442_RUST_FFI_DEFAULT. */
    public static function useW442RustFfi(?bool $enable = true): void
    {
        self::$useW442RustFfi = $enable;
    }

    public static function usesW442RustFfi(): bool
    {
        return self::$useW442RustFfi ?? self::W442_RUST_FFI_DEFAULT;
    }

    public static function resetW442RustFfi(): void
    {
        self::$useW442RustFfi = null;
    }

    /** @param list<array<string, mixed>> $packets */
    public static function fromPackets(array $packets): ?string
    {
        $set = null;
        $main = null;
        $descriptors = [];
        $checksums = [];
        foreach ($packets as $packet) {
            $packetSet = $packet['head_set_id'];
            if ($set !== null && $set !== $packetSet) {
                return null;
            }
            $set = $packetSet;
            switch ($packet['head_type']) {
                case Par2Info::PACKET_MAIN:
                    $value = [$packet['block_size'], $packet['rec_file_count'], $packet['rec_file_ids'], $packet['other_file_ids'] ?? [], $packet['head_hash']];
                    if ($main !== null && $main !== $value) {
                        return null;
                    }
                    $main = $value;
                    break;
                case Par2Info::PACKET_FILEDESC:
                    $id = $packet['file_id'];
                    // The parser trims names; retain the validated wire digest as well.
                    $value = [$packet['file_name'], $packet['file_length'], $packet['file_hash'], $packet['file_hash_16K'], $packet['head_hash']];
                    if (isset($descriptors[$id]) && $descriptors[$id] !== $value) {
                        return null;
                    }
                    $descriptors[$id] = $value;
                    break;
                case Par2Info::PACKET_FILEVER:
                    $id = $packet['file_id'];
                    $value = [$packet['block_checksums'], $packet['head_hash']];
                    if (isset($checksums[$id]) && $checksums[$id] !== $value) {
                        return null;
                    }
                    $checksums[$id] = $value;
                    break;
                case Par2Info::PACKET_RECOVERY:
                case Par2Info::PACKET_CREATOR:
                    break;
                default:
                    return null;
            }
        }
        if ($main === null || $main[0] < 1 || $main[1] < 1 || count($main[2]) !== $main[1]) {
            return null;
        }
        $ids = [];
        foreach (array_merge($main[2], $main[3]) as $entry) {
            $id = $entry[1];
            if (isset($ids[$id])) {
                return null;
            }
            $ids[$id] = true;
        }
        ksort($ids, SORT_STRING);
        ksort($descriptors, SORT_STRING);
        ksort($checksums, SORT_STRING);
        // Incomplete inventories and optional layouts retain the original verification path.
        if (array_keys($ids) !== array_keys($descriptors) || array_keys($ids) !== array_keys($checksums)) {
            return null;
        }

        return hash('sha256', serialize(['par2-source-proof-v1', $set, $main, $descriptors, $checksums]));
    }

    /**
     * Group fromPackets() results as a verifier sharing source proofs would: null is absent (no shareable proof), any
     * other value that is not a lowercase SHA-256 hex digest is invalid, and proofs are grouped by identity to report
     * their distinct count and the largest group that one successful verification covers.
     *
     * @param  array<array-key, mixed>  $identities
     * @return array{identities: int, absent: int, invalid: int, proofs: int, distinct: int, largest_group: int}
     */
    public static function summarizeProofIdentities(array $identities): array
    {
        return (self::usesW442RustFfi() ? self::summarizeProofIdentitiesNative($identities) : null)
            ?? self::summarizeProofIdentitiesPhp($identities);
    }

    /**
     * @param  array<array-key, mixed>  $identities
     * @return array{identities: int, absent: int, invalid: int, proofs: int, distinct: int, largest_group: int}
     */
    public static function summarizeProofIdentitiesPhp(array $identities): array
    {
        $absent = $invalid = $proofs = $largest = 0;
        $groups = [];
        foreach ($identities as $identity) {
            if ($identity === null) {
                $absent++;
            } elseif (! \is_string($identity) || preg_match('/\A[0-9a-f]{64}\z/', $identity) !== 1) {
                $invalid++;
            } else {
                $proofs++;
                $groups[$identity] = ($groups[$identity] ?? 0) + 1;
                $largest = max($largest, $groups[$identity]);
            }
        }

        return ['identities' => \count($identities), 'absent' => $absent, 'invalid' => $invalid, 'proofs' => $proofs, 'distinct' => \count($groups), 'largest_group' => $largest];
    }

    /**
     * @param  array<array-key, mixed>  $identities
     * @return array{identities: int, absent: int, invalid: int, proofs: int, distinct: int, largest_group: int}|null
     */
    public static function summarizeProofIdentitiesNative(array $identities): ?array
    {
        $slots = [];
        $tail = [];
        foreach ($identities as $identity) {
            if (\is_string($identity)) {
                array_push($slots, \strlen($identity), 0);
                $tail[] = $identity;
            } else {
                array_push($slots, -1, $identity === null ? 1 : 2);
            }
        }
        $values = Par2PacketAccumulator::evaluateW442Native(2, \count($identities), $slots, implode('', $tail), 6);
        if ($values === null) {
            return null;
        }
        [$count, $absent, $invalid, $proofs, $distinct, $largest] = $values;
        if ($count !== \count($identities) || min($values) < 0 || $absent + $invalid + $proofs !== $count
            || $distinct > $proofs || $largest > $proofs || ($proofs > 0) !== ($distinct > 0 && $largest > 0)) {
            return null;
        }

        return ['identities' => $count, 'absent' => $absent, 'invalid' => $invalid, 'proofs' => $proofs, 'distinct' => $distinct, 'largest_group' => $largest];
    }
}
