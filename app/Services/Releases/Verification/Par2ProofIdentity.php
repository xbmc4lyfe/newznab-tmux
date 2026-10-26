<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Services\Par2PacketAccumulator;
use App\Support\RustPipelineLibrary;
use dariusiii\rarinfo\Par2Info;
use FFI;
use Throwable;

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

    /** C signature of the Wave 63.5 {@see self::w63Par2ProofPlanBatch()} helper. */
    private const string W63_PAR2_PROOF_PLAN_FFI_CDEF = 'int64_t nntmux_w635_par2_proof_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /**
     * The Wave 63.5 plan batch defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x
     * (min 4.61/4.27/5.67/5.59x); see tests/Unit/RustVerificationPolicyPar2ProofPayloadUpdateResetW63ParityTest.php.
     */
    public const bool W63_RUST_FFI_DEFAULT = true;

    /**
     * Wave 63.5 routing and FFI cache, kept in function-local statics (no properties). Without $op it
     * reports the routing; 'set' swaps the override for $val and returns the previous one; 'reset' replaces the
     * cached handle with $val (null re-resolves, false disables); 'buffer' returns `[FFI, int64_t[], capacity]`
     * with room for at least $val words, or null without the library.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w63_5', self::W63_PAR2_PROOF_PLAN_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W63_RUST_FFI_DEFAULT;
        }
    }

    /** Route the Wave 63.5 plan batch to Rust until disabled or reset. */
    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('set', true);
    }

    /** Route the Wave 63.5 plan batch to PHP until enabled or reset. */
    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('set', false);
    }

    /** Restore W63_RUST_FFI_DEFAULT routing and drop the cached Wave 63.5 handle and buffer. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('set', null);
        self::w63RustFfiState('reset', null);
    }

    public static function usesW63RustFfi(): bool
    {
        return self::w63RustFfiState() === true;
    }

    /**
     * Runs $callback with w63Par2ProofPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w63RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 63.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w63Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w63RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w635_par2_proof_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Trimmed 1-15 ASCII digits as an integer; empty yields $default, anything else sets bit 1 and yields $default. */
    private static function w63Number(string $field, ?int $default, int &$flags): ?int
    {
        $text = trim($field);
        if ($text === '') {
            return $default;
        }
        if (\strlen($text) <= 15 && strspn($text, '0123456789') === \strlen($text)) {
            return (int) $text;
        }
        $flags |= 1;

        return $default;
    }

    /**
     * Batch PAR2 proof identity plan over
     * `set_id\tblock_size\trecovery_file_count\trecovery_ids\tother_ids\tdescriptor_ids\tchecksum_ids\tfile_hashes\tslice_counts`.
     * Ten words per record: `[set_state, block_size, recovery_ids, distinct_ids, descriptors, checksums, file_hashes,
     * slices, class, flags]`. The set ID is 0 empty, 1 32 ASCII hex digits, 2 invalid (trimmed). Numbers are trimmed 1-15
     * ASCII digits (else 0; invalid sets flag 1). ID and hash lists are comma-separated, trimmed 32-hex items compared
     * case-insensitively; an empty field is an empty list, and invalid items are dropped. Descriptors and checksums are
     * counted once per ID. Slice counts are comma-separated 1-9 digit items summed. Classes, checked in order, mirror
     * fromPackets(): 1 missing main (set ID not valid, block size or recovery file count below 1), 6 invalid ID, 2
     * recovery ID count differs from the declared count, 3 duplicate recovery/other ID, 4 descriptor or checksum IDs
     * differ from the main IDs, 5 an invalid hash or a hash count other than the descriptor count, else 0 complete.
     * Flags: 1 invalid number, 2 not exactly nine fields, 4 invalid ID, 8 invalid hash, 16 invalid slice count, 32
     * duplicate descriptor or checksum ID, 64 uppercase hex.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63Par2ProofPlanBatch(array $records): array
    {
        return self::usesW63RustFfi() ? self::w63Par2ProofPlanBatchNative($records) : self::w63Par2ProofPlanBatchPhp($records);
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63Par2ProofPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w63Words($count, implode("\0", $records), 10 * $count) ?? self::w63Par2ProofPlanBatchPhp($records);
    }

    private const string W63_HEX = '0123456789abcdefABCDEF';

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function w63Par2ProofPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 9 ? 0 : 2;
            $set = trim($fields[0]);
            if ($set === '') {
                $setState = 0;
            } elseif (self::w63Hex32($set)) {
                $setState = 1;
                if (strpbrk($set, 'ABCDEF') !== false) {
                    $flags |= 64;
                }
            } else {
                $setState = 2;
            }
            $blockSize = (int) self::w63Number($fields[1] ?? '', 0, $flags);
            $recoveryCount = (int) self::w63Number($fields[2] ?? '', 0, $flags);
            $invalidId = $invalidHash = false;
            $recovery = self::w63HexList($fields[3] ?? '', 4, $flags, $invalidId);
            $other = self::w63HexList($fields[4] ?? '', 4, $flags, $invalidId);
            $descriptors = self::w63HexList($fields[5] ?? '', 4, $flags, $invalidId);
            $checksums = self::w63HexList($fields[6] ?? '', 4, $flags, $invalidId);
            $hashes = self::w63HexList($fields[7] ?? '', 8, $flags, $invalidHash);
            $slices = 0;
            if (($fields[8] ?? '') !== '') {
                foreach (explode(',', $fields[8]) as $item) {
                    $item = trim($item);
                    if ($item !== '' && \strlen($item) <= 9 && strspn($item, '0123456789') === \strlen($item)) {
                        $slices += (int) $item;
                    } else {
                        $flags |= 16;
                    }
                }
            }
            $ids = array_fill_keys(array_merge($recovery, $other), true);
            $descriptorSet = array_fill_keys($descriptors, true);
            $checksumSet = array_fill_keys($checksums, true);
            if (\count($descriptorSet) !== \count($descriptors) || \count($checksumSet) !== \count($checksums)) {
                $flags |= 32;
            }
            $class = match (true) {
                $setState !== 1 || $blockSize < 1 || $recoveryCount < 1 => 1,
                $invalidId => 6,
                \count($recovery) !== $recoveryCount => 2,
                \count($ids) !== \count($recovery) + \count($other) => 3,
                \count($ids) !== \count($descriptorSet) || array_diff_key($ids, $descriptorSet) !== []
                    || \count($ids) !== \count($checksumSet) || array_diff_key($ids, $checksumSet) !== [] => 4,
                $invalidHash || \count($hashes) !== \count($descriptorSet) => 5,
                default => 0,
            };
            array_push($out, $setState, $blockSize, \count($recovery), \count($ids), \count($descriptorSet), \count($checksumSet), \count($hashes), $slices, $class, $flags);
        }

        return $out;
    }

    /** Whether $text is exactly 32 ASCII hex digits. */
    private static function w63Hex32(string $text): bool
    {
        return \strlen($text) === 32 && strspn($text, self::W63_HEX) === 32;
    }

    /**
     * Lowercased, trimmed 32-hex items of a comma-separated, non-empty $field; invalid items set $invalidFlag and
     * $invalid, and items with uppercase hex set bit 64.
     *
     * @return list<string>
     */
    private static function w63HexList(string $field, int $invalidFlag, int &$flags, bool &$invalid): array
    {
        if ($field === '') {
            return [];
        }
        $items = [];
        foreach (explode(',', $field) as $item) {
            $item = trim($item);
            if (self::w63Hex32($item)) {
                if (strpbrk($item, 'ABCDEF') !== false) {
                    $flags |= 64;
                }
                $items[] = strtolower($item);
            } else {
                $flags |= $invalidFlag;
                $invalid = true;
            }
        }

        return $items;
    }
}
