<?php

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;

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
        } catch (\Throwable) {
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
        } catch (\Throwable) {
            $values = false;
        }

        /** @var list<int>|null $result */
        $result = \is_array($values) ? array_values($values) : null;

        return $result ?? self::fingerprintAdmissionsPhp($candidateIds, $identities, $versions, $manifestHashes);
    }
}
