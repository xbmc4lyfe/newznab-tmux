<?php

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use InvalidArgumentException;
use Throwable;

final readonly class CandidateAdmission
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Readonly classes cannot have static properties; preserve readonly value semantics.
    private static function rustEnabled(?bool $enabled = null): bool
    {
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    public static function setUseRustFfi(bool $enabled): void
    {
        self::rustEnabled($enabled);
    }

    /** Decline objects/resources/cycles before serialize() could invoke user code. */
    private static function nativeValueSupported(mixed $value, int &$remaining, int $depth): bool
    {
        if (--$remaining < 0 || $depth > 24) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! self::nativeValueSupported($item, $remaining, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_scalar($value);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array{value: mixed}|null An envelope distinguishes a native null from failure.
     */
    private static function computeNative(int $operation, array $arguments): ?array
    {
        if (! self::rustEnabled()) {
            return null;
        }
        try {
            $remaining = 100000;
            if (! self::nativeValueSupported($arguments, $remaining, 0)) {
                return null;
            }
            $ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF);
            if ($ffi === null) {
                return null;
            }
            $input = serialize($arguments);
            if (strlen($input) > 64 * 1024 * 1024) {
                return null;
            }
            $capacity = 0;
            $output = RustPipelineLibrary::obtainScratch($ffi, max(4096, strlen($input) + 1024), $capacity);
            if ($output === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by FFI_CDEF.)
            $written = $ffi->nntmux_w18_verification_metadata_fingerprint($operation, $input, strlen($input), $output, $capacity);
            if (! is_int($written) || $written < 2 || $written > $capacity) {
                return null;
            }

            return ['value' => unserialize(FFI::string($output, $written), ['allowed_classes' => false])];
        } catch (Throwable) {
            return null;
        }
    }

    public function __construct(public int $candidateId, public string $identity, public int $version, public string $manifestHash) {}

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string} */
    public function toArray(): array
    {
        return self::rustEnabled() ? ($this->toArrayNative() ?? $this->toArrayPhp()) : $this->toArrayPhp();
    }

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string} */
    public function toArrayPhp(): array
    {
        return ['candidate_id' => $this->candidateId, 'identity' => $this->identity, 'version' => $this->version, 'manifest_hash' => $this->manifestHash];
    }

    /** @return array{candidate_id: int, identity: string, version: int, manifest_hash: string}|null */
    public function toArrayNative(): ?array
    {
        $result = self::computeNative(6, [$this->candidateId, $this->identity, $this->version, $this->manifestHash])['value'] ?? null;

        return is_array($result) ? $result : null;
    }

    /** Wave 49.2 paired benchmarks decide this default; see RustVerificationAdmissionInventoryPrepRepoSampleW49ParityTest. */
    private const W49_RUST_FFI_DEFAULT = true;

    public const string W49_FFI_CDEF = <<<'C'
        intptr_t nntmux_w49_candidate_admission_batch(const char *ints, size_t ints_len, const char *identities, size_t identities_len, const char *hashes, size_t hashes_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const string W49_HEX = '0123456789abcdef';

    /**
     * Wave 49 routing, binding and output buffer. Readonly classes cannot declare static
     * properties, so function-local statics hold them. Operations: null reads the routing
     * override, 'set' replaces it (returning the previous one), 'ffi' resolves the binding and
     * 'out' returns a buffer of at least $val words (its capacity through 'cap').
     */
    private static function w49RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w49', self::W49_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w49RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return $out;
            case 'cap':
                return $capacity;
            default:
                return $enabled;
        }
    }

    public static function usesW49RustFfi(): bool
    {
        $enabled = self::w49RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W49_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with fingerprintAdmissions() routed to Rust ($enabled) or PHP, then restores
     * the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW49RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w49RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w49RustFfiState('set', $previous);
        }
    }

    /**
     * Batch admission-receipt fingerprints. Two words per receipt: `flags | identityLength << 8`
     * (flags: 1 id > 0, 2 version >= 1, 4 manifest hash is 64 lowercase hex, 8 identity non-empty,
     * 16 identity is lowercase hex, 32 candidate id repeats an earlier one, 64 identity repeats
     * an earlier one, 128 accepted = 1|2|4|8 without 32|64; length clamped to 65535), then
     * `crc32(identity:hash) | (version & 0x7fffffff) << 32`. Accepted and duplicate counts and
     * the XXH64 digest of all earlier words follow.
     *
     * @param  list<int>  $candidateIds
     * @param  list<string>  $identities
     * @param  list<int>  $versions
     * @param  list<string>  $manifestHashes
     * @return list<int>
     */
    public static function fingerprintAdmissions(array $candidateIds, array $identities, array $versions, array $manifestHashes): array
    {
        return self::usesW49RustFfi()
            ? self::fingerprintAdmissionsNative($candidateIds, $identities, $versions, $manifestHashes)
            : self::fingerprintAdmissionsPhp($candidateIds, $identities, $versions, $manifestHashes);
    }

    /**
     * @param  list<int>  $candidateIds
     * @param  list<string>  $identities
     * @param  list<int>  $versions
     * @param  list<string>  $manifestHashes
     * @return list<int>
     */
    public static function fingerprintAdmissionsPhp(array $candidateIds, array $identities, array $versions, array $manifestHashes): array
    {
        $out = $seenIds = $seenIdentities = [];
        $accepted = $duplicates = 0;
        foreach ($candidateIds as $i => $id) {
            $identity = $identities[$i] ?? '';
            $version = $versions[$i] ?? 0;
            $hash = $manifestHashes[$i] ?? '';
            $length = \strlen($identity);
            $flags = (int) ($id > 0) | (int) ($version >= 1) << 1
                | (int) (\strlen($hash) === 64 && strspn($hash, self::W49_HEX) === 64) << 2
                | (int) ($length > 0) << 3 | (int) ($length > 0 && strspn($identity, self::W49_HEX) === $length) << 4;
            if (isset($seenIds[$id])) {
                $flags |= 32;
            } else {
                $seenIds[$id] = true;
            }
            if (isset($seenIdentities[$identity])) {
                $flags |= 64;
            } else {
                $seenIdentities[$identity] = true;
            }
            if (($flags & 0x60) !== 0) {
                $duplicates++;
            }
            if (($flags & 0x6F) === 0x0F) {
                $flags |= 0x80;
                $accepted++;
            }
            $out[] = $flags | min($length, 0xFFFF) << 8;
            $out[] = crc32($identity.':'.$hash) | ($version & 0x7FFFFFFF) << 32;
        }
        $out[] = $accepted;
        $out[] = $duplicates;
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));
        $out[] = $digest[1];

        return $out;
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, the lists differ in length or a
     * string holds a NUL byte.
     *
     * @param  list<int>  $candidateIds
     * @param  list<string>  $identities
     * @param  list<int>  $versions
     * @param  list<string>  $manifestHashes
     * @return list<int>
     */
    public static function fingerprintAdmissionsNative(array $candidateIds, array $identities, array $versions, array $manifestHashes): array
    {
        $count = \count($candidateIds);
        $ffi = $count === 0 ? null : self::w49RustFfiState('ffi');
        if (! $ffi instanceof FFI || \count($identities) !== $count || \count($versions) !== $count || \count($manifestHashes) !== $count) {
            return self::fingerprintAdmissionsPhp($candidateIds, $identities, $versions, $manifestHashes);
        }
        $joinedIdentities = implode("\0", $identities);
        $joinedHashes = implode("\0", $manifestHashes);
        $words = 2 * $count + 3;
        $values = false;
        // Rust rejects a column whose NUL count differs from $count - 1 (an embedded NUL byte).
        try {
            $out = self::w49RustFfiState('out', $words);
            if ($out instanceof CData) {
                $ints = pack('P*', ...$candidateIds, ...$versions);
                // @phpstan-ignore method.notFound (Declared by W49_FFI_CDEF.)
                $written = $ffi->nntmux_w49_candidate_admission_batch($ints, \strlen($ints), $joinedIdentities, \strlen($joinedIdentities), $joinedHashes, \strlen($joinedHashes), $count, $out, self::w49RustFfiState('cap'));
                $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;
            }
        } catch (Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::fingerprintAdmissionsPhp($candidateIds, $identities, $versions, $manifestHashes);
    }

    // ========================================================================
    // WAVE 63.4 BATCH HELPER
    // ========================================================================

    /** Enabled: the minimum of three 1000-round paired runs reached 2.32x common mean, 2.16x common p99, 1.73x uncommon mean and 1.69x uncommon p99; see RustVerificationAdmissionInventoryPrepRepoSampleBatchW63ParityTest. */
    public const bool W63_RUST_FFI_DEFAULT = true;

    private const string W63_FFI_CDEF = 'ssize_t nntmux_w634_candidate_admission_plan_batch(const char *blob, size_t blob_len, const char *twin, size_t twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);';

    /** int64 words per w63AdmissionPlanBatch() record. */
    public const int W63_ROW_WORDS = 5;

    /** int64 summary words after the w63AdmissionPlanBatch() records (none for an empty batch). */
    public const int W63_TRAILER_WORDS = 10;

    private const int W63_TIME_MAX = 1 << 40;

    private const int W63_MAX_ATTEMPTS = 8;

    private const int W63_PARTIAL_AGE = 86_400;

    /** Claimable 1..4, then the waiting and rejected header states; any other status is 0. */
    private const array W63_STATUSES = ['ready' => 1, 'pending' => 2, 'unknown' => 3, 'verifying' => 4, 'waiting_upload' => 5, 'header_rejected' => 6];

    /**
     * Verification admission plan rows for [identity, manifest hash, status, candidate id,
     * version, attempts, articles found, articles expected, created at, next attempt at, lease
     * until, now] items (times in epoch seconds; now clamped to 0..2^40).
     *
     * Flags follow fingerprintAdmissionsPhp(): 1 id > 0, 2 version >= 1, 4 manifest hash is 64
     * lowercase hex, 8 identity non-empty, 16 identity is lowercase hex, 32 candidate id repeats,
     * 64 identity repeats. The completion permille is min(found, expected) * 1000 / expected
     * (inputs clamped to 0..2^31; -1 without an expected count) and the age is now - created at
     * (-1 when unset or in the future). Decision: 1 invalid, 2 duplicate, 3 status not claimable,
     * 4 lease still held, 5 attempts exhausted (>= 8), 6 not due, 7 admit complete, 8 admit
     * partial (incomplete and at least a day old), 9 wait for articles.
     *
     * Rows [decision | status << 4 | flags << 8 | identity length << 16 | attempts << 32 (both
     * clamped to 65535), fnv1a32(identity:hash), permille, age, seconds until due (0 when due)],
     * then the trailer [one count per decision, FNV-1a digest].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int}>  $items
     * @return list<int>
     */
    public static function w63AdmissionPlanBatch(array $items): array
    {
        return self::usesW63RustFfi() ? self::w63AdmissionPlanBatchNative($items) : self::w63AdmissionPlanBatchPhp($items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int}>  $items
     * @return list<int>
     */
    public static function w63AdmissionPlanBatchPhp(array $items): array
    {
        self::w63Items($items);
        if ($items === []) {
            return [];
        }
        $out = $ids = $identities = [];
        $summary = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        $digest = 2_166_136_261;
        foreach ($items as [$identity, $hash, $status, $id, $version, $attempts, $found, $expected, $created, $next, $lease, $now]) {
            $code = self::W63_STATUSES[$status] ?? 0;
            $attempts = max(0, min(1_000_000, $attempts));
            $found = max(0, min(1 << 31, $found));
            $expected = max(0, min(1 << 31, $expected));
            $now = max(0, min(self::W63_TIME_MAX, $now));
            $length = \strlen($identity);
            $flags = ($id > 0 ? 1 : 0) | ($version >= 1 ? 2 : 0) | (\strlen($hash) === 64 && strspn($hash, self::W49_HEX) === 64 ? 4 : 0)
                | ($length > 0 ? 8 : 0) | ($length > 0 && strspn($identity, self::W49_HEX) === $length ? 16 : 0);
            if (isset($ids[$id])) {
                $flags |= 32;
            } else {
                $ids[$id] = true;
            }
            if (isset($identities[$identity])) {
                $flags |= 64;
            } else {
                $identities[$identity] = true;
            }
            $permille = $expected > 0 ? intdiv(min($found, $expected) * 1000, $expected) : -1;
            $age = $created > 0 && $created <= $now ? $now - $created : -1;
            $due = $next <= $now;
            $wait = $due ? 0 : min($next - $now, self::W63_TIME_MAX);
            $decision = match (true) {
                ($flags & 0x0F) !== 0x0F => 1,
                ($flags & 0x60) !== 0 => 2,
                $code < 1 || $code > 4 => 3,
                $lease > 0 && $lease >= $now => 4,
                $attempts >= self::W63_MAX_ATTEMPTS => 5,
                ! $due => 6,
                $permille === 1000 => 7,
                $age >= self::W63_PARTIAL_AGE => 8,
                default => 9,
            };
            $receipt = self::w63Fnv($identity.':'.$hash);
            $word = $decision | $code << 4 | $flags << 8 | min($length, 0xFFFF) << 16 | min($attempts, 0xFFFF) << 32;
            array_push($out, $word, $receipt, $permille, $age, $wait);
            $summary[$decision - 1]++;
            $value = $word ^ $receipt << 20 ^ $wait;
            $digest = (($digest ^ (($value ^ ($value >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
        }
        $summary[9] = $digest;

        return [...$out, ...$summary];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int, 5: int, 6: int, 7: int, 8: int, 9: int, 10: int, 11: int}>  $items
     * @return list<int>
     */
    public static function w63AdmissionPlanBatchNative(array $items): array
    {
        self::w63Items($items);
        $count = \count($items);
        if ($count === 0) {
            return [];
        }
        [$blob, $twin] = self::w63Join([array_column($items, 0), array_column($items, 1), array_column($items, 2)], $count);
        $ints = pack('P*', ...array_column($items, 3), ...array_column($items, 4), ...array_column($items, 5), ...array_column($items, 6), ...array_column($items, 7),
            ...array_column($items, 8), ...array_column($items, 9), ...array_column($items, 10), ...array_column($items, 11));

        return self::w63Call($blob, $twin, $ints, $count, $count * self::W63_ROW_WORDS + self::W63_TRAILER_WORDS)
            ?? self::w63AdmissionPlanBatchPhp($items);
    }

    /** @param array<array-key, mixed> $items */
    private static function w63Items(array $items): void
    {
        if (! array_is_list($items)) {
            throw new InvalidArgumentException('W63 candidate admission plan batch items must be a list.');
        }
        foreach ($items as $item) {
            if (! \is_array($item) || \count($item) !== 12 || ! array_is_list($item) || ! \is_string($item[0]) || ! \is_string($item[1]) || ! \is_string($item[2]) || ! \is_int($item[3]) || ! \is_int($item[4]) || ! \is_int($item[5]) || ! \is_int($item[6]) || ! \is_int($item[7]) || ! \is_int($item[8]) || ! \is_int($item[9]) || ! \is_int($item[10]) || ! \is_int($item[11])) {
                throw new InvalidArgumentException('W63 candidate admission plan batch items must be [string identity, string manifest hash, string status, int candidate id, int version, int attempts, int articles found, int articles expected, int created at, int next attempt at, int lease until, int now] lists.');
            }
        }
    }

    public static function usesW63RustFfi(): bool
    {
        $enabled = self::w63RustFfiState('enabled');

        return \is_bool($enabled) ? $enabled : self::W63_RUST_FFI_DEFAULT;
    }

    public static function enableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', true);
    }

    public static function disableW63RustFfi(): void
    {
        self::w63RustFfiState('enabled', false);
    }

    /** Drop the W63 override, binding, output buffer and declined-call count; routing returns to W63_RUST_FFI_DEFAULT. */
    public static function resetW63RustFfi(): void
    {
        self::w63RustFfiState('reset');
    }

    /**
     * Run $callback with the W63 dispatch forced on or off, then restore the previous choice.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW63RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w63RustFfiState('enabled');
        self::w63RustFfiState('enabled', $enabled);
        try {
            return $callback();
        } finally {
            self::w63RustFfiState('enabled', $previous);
        }
    }

    /**
     * String columns NUL-joined field-major and, when a value holds a NUL, the same columns
     * joined by 0x01 so Rust finds the real separators.
     *
     * @param  list<list<string>>  $columns
     * @return array{0: string, 1: string}
     */
    private static function w63Join(array $columns, int $count): array
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\0", $column);
        }
        $joined = implode("\0", $parts);
        if (substr_count($joined, "\0") === \count($columns) * $count - 1) {
            return [$joined, ''];
        }
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = implode("\x01", $column);
        }

        return [$joined, implode("\x01", $parts)];
    }

    /**
     * One FFI crossing for the batch: $need int64 words back. Null when the library is missing
     * or declines a malformed frame, so the caller falls back to PHP; a declined call with a
     * resolved library is counted in the 'misses' state.
     *
     * @return list<int>|null
     */
    private static function w63Call(string $blob, string $twin, string $ints, int $count, int $need): ?array
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        $state = self::w63RustFfiState();
        $ffi = $state['ffi'];
        if ($ffi === null) {
            $ffi = RustPipelineLibrary::resolveDomain('metadata_w63', self::W63_FFI_CDEF) ?? false;
            self::w63RustFfiState('ffi', $ffi);
        }
        if (! $ffi instanceof FFI) {
            return null;
        }
        try {
            $out = $state['out'];
            $cap = $state['cap'];
            if (! $out instanceof CData || $cap < $need) {
                $cap = max(4096, $need);
                $out = $ffi->new('int64_t['.$cap.']');
                self::w63RustFfiState('out', $out);
                self::w63RustFfiState('cap', $cap);
            }
            // @phpstan-ignore method.notFound (Declared by W63_FFI_CDEF.)
            $written = $ffi->nntmux_w634_candidate_admission_plan_batch($blob, \strlen($blob), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $cap);
            $values = $written === $need ? unpack('P*', FFI::string($out, $need * 8)) : false;
            if ($values !== false) {
                return array_values($values);
            }
        } catch (Throwable) {
        }
        self::w63RustFfiState('misses', $state['misses'] + 1);

        return null;
    }

    /**
     * W63 routing override, cached binding, reusable output buffer and declined-call count, kept
     * in a function static so the class declares no new property: no arguments returns the state,
     * one argument reads a key ('reset' clears everything) and two arguments write a key.
     */
    private static function w63RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var array{enabled: ?bool, ffi: FFI|false|null, out: ?CData, cap: int, misses: int} $state */
        static $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];
        if ($op === null) {
            return $state;
        }
        if ($op === 'reset') {
            $state = ['enabled' => null, 'ffi' => null, 'out' => null, 'cap' => 0, 'misses' => 0];

            return null;
        }
        if (\func_num_args() > 1) {
            $state[$op] = $val;

            return null;
        }

        return $state[$op] ?? null;
    }

    /** FNV-1a 32 of $bytes as an unsigned int. */
    private static function w63Fnv(string $bytes): int
    {
        /** @var array{1: int} $hash */
        $hash = unpack('N', hash('fnv1a32', $bytes, true));

        return $hash[1];
    }
}
