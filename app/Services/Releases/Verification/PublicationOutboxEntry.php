<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * One verified candidate waiting in the per-host publication outbox. It holds exactly what the
 * primary needs: the claim (fenced by its lease token), the reason and the sampled evidence.
 */
final readonly class PublicationOutboxEntry
{
    private const string FFI_CDEF = <<<'C'
        ssize_t nntmux_verification_outbox_normalize(const char *, size_t, char *, size_t);
        C;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::useRustFfi($enabled);
    }

    private static function useRustFfi(?bool $enabled = null): bool
    {
        // Readonly classes cannot declare static properties.
        static $useRustFfi = false;
        if ($enabled !== null) {
            $useRustFfi = $enabled;
        }

        return $useRustFfi;
    }

    /**
     * @param  array{id: int, version: int, manifest_hash: string, lease_token: string}  $claim
     * @param  array{articles: int, bytes: int, sampled_files: int, verified_file_names: list<string>, identity_hints: list<string>}  $evidence
     * @param  float  $enqueuedAt  Unix seconds when the worker handed the result off.
     * @param  int  $attempts  Failed sends so far that count toward the attempt limit.
     * @param  int  $deferrals  Sends that never reached the primary's processing (unreachable, or not started); they only back off.
     * @param  float  $notBefore  Unix seconds before which a requeued entry is not sent again.
     * @param  int  $modifiedAt  Unix seconds of the file's last modification (not a lease signal: requeues rewrite the file).
     * @param  float  $heartbeatAt  Unix seconds of the last time the candidate's lease was really renewed; the hand-off
     *                              renews it, so it starts at $enqueuedAt. Only the lease/3 renewal schedule reads it.
     */
    public function __construct(public string $name, public string $path, public array $claim, public string $reason, public array $evidence,
        public float $enqueuedAt, public int $attempts = 0, public float $notBefore = 0.0, public int $modifiedAt = 0, public int $deferrals = 0, public float $heartbeatAt = 0.0) {}

    /**
     * The name of an entry's file: one per lease of a candidate generation. A candidate that is claimed
     * again keeps its id and version, so the lease token is part of the name; an operation on an old
     * entry can then never touch the new one.
     */
    public static function nameFor(int $id, int $version, string $leaseToken): string
    {
        return $id.'-'.$version.'-'.substr(sha1($leaseToken), 0, 12).'.json';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['claim' => $this->claim, 'reason' => $this->reason, 'evidence' => $this->evidence,
            'enqueued_at' => $this->enqueuedAt, 'heartbeat_at' => $this->heartbeatAt, 'attempts' => $this->attempts, 'deferrals' => $this->deferrals, 'not_before' => $this->notBefore];
    }

    public function claim(): object
    {
        return (object) $this->claim;
    }

    /** The item {@see RemoteReleasePublisher::publishMany()} sends; its manifest carries exactly this entry's evidence. */
    public function item(): RemotePublishItem
    {
        $manifest = ['verification_mode' => 'spot_check',
            'spot_check' => ['articles' => $this->evidence['articles'], 'bytes' => $this->evidence['bytes'], 'sampled_files' => $this->evidence['sampled_files']],
            'verified_file_names' => $this->evidence['verified_file_names'],
            'identity_hints' => array_map(static fn (string $name): array => ['name' => $name], $this->evidence['identity_hints'])];

        return new RemotePublishItem($this->claim(), new VerificationResult('sampled', $this->reason, $manifest));
    }

    /** Whether it has waited longer than $seconds since the worker handed it off. */
    public function olderThan(float $seconds, float $now): bool
    {
        return $now - $this->enqueuedAt > $seconds;
    }

    /**
     * The same entry after one more failed send, not to be sent again before $notBefore. A send the
     * primary never processed ($counted false) does not use up an attempt; only the age limit ends those.
     * $heartbeatAt carries the newest known lease renewal into the rewritten document.
     */
    public function withFailure(float $notBefore, bool $counted, ?float $heartbeatAt = null): self
    {
        return new self($this->name, $this->path, $this->claim, $this->reason, $this->evidence, $this->enqueuedAt,
            $this->attempts + ($counted ? 1 : 0), $notBefore, $this->modifiedAt, $this->deferrals + ($counted ? 0 : 1),
            max($this->heartbeatAt, $heartbeatAt ?? 0.0));
    }

    /** Failed sends of either kind, for the backoff. */
    public function failures(): int
    {
        return $this->attempts + $this->deferrals;
    }

    /** The decoded entry, or null when the document is not a valid one. */
    public static function decode(string $name, string $path, string $json, int $modifiedAt): ?self
    {
        // The PHP path remains admitted until paired caller benchmarks qualify Rust.
        return self::decodeNative($name, $path, $json, $modifiedAt) ?? self::decodePhp($name, $path, $json, $modifiedAt);
    }

    /** The decoded entry, or null when invalid or the native candidate declines. */
    public static function decodeNative(string $name, string $path, string $json, int $modifiedAt): ?self
    {
        if (! self::useRustFfi() || ($ffi = RustPipelineLibrary::resolveDomain('nfo', self::FFI_CDEF)) === null) {
            return null;
        }
        // Keep PHP's JSON parser, duplicate-key handling and integer/float classification.
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $input = serialize($data);
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($input) + 512, $capacity);
        if ($buffer === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_outbox_normalize($input, strlen($input), $buffer, $capacity);
        if ($written < -1) {
            $buffer = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
            if ($buffer === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_verification_outbox_normalize($input, strlen($input), $buffer, $capacity);
        }
        if ($written <= 0 || $written > $capacity) {
            return null;
        }
        $row = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);
        if (! is_array($row) || count($row) !== 8) {
            return null;
        }

        return new self($name, $path, $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $modifiedAt, $row[6], $row[7]);
    }

    /** The original decoder, including its PHP scalar conversions and warnings. */
    public static function decodePhp(string $name, string $path, string $json, int $modifiedAt): ?self
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['claim'] ?? null) || ! is_array($data['evidence'] ?? null)) {
            return null;
        }
        $claim = $data['claim'];
        $evidence = $data['evidence'];
        if (! is_int($claim['id'] ?? null) || ! is_int($claim['version'] ?? null) || ! is_string($claim['manifest_hash'] ?? null)
            || ! is_string($claim['lease_token'] ?? null) || ! is_string($data['reason'] ?? null)
            || ! is_int($evidence['articles'] ?? null) || ! is_int($evidence['bytes'] ?? null) || ! is_int($evidence['sampled_files'] ?? null)
            || ! is_array($evidence['verified_file_names'] ?? null) || ! is_array($evidence['identity_hints'] ?? null)) {
            return null;
        }

        return new self($name, $path, ['id' => $claim['id'], 'version' => $claim['version'], 'manifest_hash' => $claim['manifest_hash'],
            'lease_token' => $claim['lease_token']], $data['reason'],
            ['articles' => $evidence['articles'], 'bytes' => $evidence['bytes'], 'sampled_files' => $evidence['sampled_files'],
                'verified_file_names' => array_values(array_map('strval', $evidence['verified_file_names'])),
                'identity_hints' => array_values(array_map('strval', $evidence['identity_hints']))],
            (float) ($data['enqueued_at'] ?? 0), max(0, (int) ($data['attempts'] ?? 0)), (float) ($data['not_before'] ?? 0), $modifiedAt, max(0, (int) ($data['deferrals'] ?? 0)),
            (float) ($data['heartbeat_at'] ?? $data['enqueued_at'] ?? 0));
    }

    // Rust is the default only while the paired W57.6 benchmark clears 1.5x on all four metrics.
    private const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        int32_t nntmux_w576_outbox_entry_batch(const char *strings, size_t strings_len, const char *string_twin, size_t string_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W57_MAX_ITEMS = 1_048_576;

    public const int W57_ENTRY_HEADER = 13;

    private const int W57_COUNT_CAP = 2_147_483_647;

    /**
     * Validate outbox entries as listed: names (`<id>-<version>-<12 lowercase hex>.json`, 1-18 digit
     * numbers), claim ids and versions, manifest hashes, lease tokens, attempts and deferrals. Flags:
     * bit 0 valid, 1 well-formed name, 2 name id and 3 name version equal the claim's, 4 hash kind
     * non-zero (1 lowercase SHA-256 hex, 2 SHA-256 hex holding uppercase, 3 lowercase SHA-1 hex), 5 lease
     * token of 1-255 printable ASCII bytes, 6 id > 0, 7 version >= 0, 8 attempts/deferrals needed
     * clamping to 0..2^31-1, 9 tried before.
     *
     * Layout: `[0]` count, `[1]` valid, `[2]` bad name, `[3]` name/claim mismatch, `[4]` bad hash, `[5]`
     * uppercase hash, `[6]` bad token, `[7]` bad id or version, `[8]` clamped counts, `[9]` total and
     * `[10]` largest failures, `[11]` first invalid index (-1 when none), `[12]` FNV-1a digest; then per
     * entry `flags | hash_kind << 10 | min(token bytes, 255) << 12 | min(failures, 2^20-1) << 20 |
     * min(name bytes, 1023) << 40`.
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $manifestHashes
     * @param  array<array-key, string>  $leaseTokens
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>
     */
    public static function summarizeEntryBatch(array $names, array $ids, array $versions, array $manifestHashes, array $leaseTokens, array $attempts, array $deferrals): array
    {
        return (self::usesW57RustFfi() ? self::summarizeEntryBatchNative($names, $ids, $versions, $manifestHashes, $leaseTokens, $attempts, $deferrals) : null)
            ?? self::summarizeEntryBatchPhp($names, $ids, $versions, $manifestHashes, $leaseTokens, $attempts, $deferrals);
    }

    /**
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $manifestHashes
     * @param  array<array-key, string>  $leaseTokens
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>
     */
    public static function summarizeEntryBatchPhp(array $names, array $ids, array $versions, array $manifestHashes, array $leaseTokens, array $attempts, array $deferrals): array
    {
        $names = array_values($names);
        $ids = array_values($ids);
        $versions = array_values($versions);
        $manifestHashes = array_values($manifestHashes);
        $leaseTokens = array_values($leaseTokens);
        $attempts = array_values($attempts);
        $deferrals = array_values($deferrals);
        $count = \count($names);
        $out = array_fill(0, self::W57_ENTRY_HEADER, 0);
        $out[0] = $count;
        $out[11] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        for ($at = 0; $at < $count; $at++) {
            $name = (string) $names[$at];
            $hash = (string) ($manifestHashes[$at] ?? '');
            $token = (string) ($leaseTokens[$at] ?? '');
            $id = (int) ($ids[$at] ?? 0);
            $version = (int) ($versions[$at] ?? 0);
            $tried = (int) ($attempts[$at] ?? 0);
            $deferred = (int) ($deferrals[$at] ?? 0);
            $nameOk = preg_match('/\A(\d{1,18})-(\d{1,18})-[0-9a-f]{12}\.json\z/', $name, $match) === 1;
            $nameId = $nameOk && (int) $match[1] === $id;
            $nameVersion = $nameOk && (int) $match[2] === $version;
            $hashLength = \strlen($hash);
            $kind = $hashLength === 64
                ? (strspn($hash, '0123456789abcdef') === 64 ? 1 : (strspn($hash, '0123456789abcdefABCDEF') === 64 ? 2 : 0))
                : ($hashLength === 40 && strspn($hash, '0123456789abcdef') === 40 ? 3 : 0);
            $tokenLength = \strlen($token);
            $tokenOk = $tokenLength >= 1 && $tokenLength <= 255 && preg_match('/\A[\x21-\x7e]+\z/', $token) === 1;
            $idOk = $id > 0;
            $versionOk = $version >= 0;
            $clamped = $tried < 0 || $tried > self::W57_COUNT_CAP || $deferred < 0 || $deferred > self::W57_COUNT_CAP;
            $failures = ($tried < 0 ? 0 : min($tried, self::W57_COUNT_CAP)) + ($deferred < 0 ? 0 : min($deferred, self::W57_COUNT_CAP));
            $valid = $nameOk && $nameId && $nameVersion && $kind !== 0 && $tokenOk && $idOk && $versionOk;
            $out[1] += (int) $valid;
            $out[2] += (int) ! $nameOk;
            $out[3] += (int) ($nameOk && ! ($nameId && $nameVersion));
            $out[4] += (int) ($kind === 0);
            $out[5] += (int) ($kind === 2);
            $out[6] += (int) ! $tokenOk;
            $out[7] += (int) ! ($idOk && $versionOk);
            $out[8] += (int) $clamped;
            $out[9] += $failures;
            $out[10] = max($out[10], $failures);
            if (! $valid && $out[11] < 0) {
                $out[11] = $at;
            }
            $flags = (int) $valid | (int) $nameOk << 1 | (int) $nameId << 2 | (int) $nameVersion << 3 | (int) ($kind !== 0) << 4
                | (int) $tokenOk << 5 | (int) $idOk << 6 | (int) $versionOk << 7 | (int) $clamped << 8 | (int) ($failures > 0) << 9;
            $row = $flags | $kind << 10 | min($tokenLength, 255) << 12 | min($failures, 1_048_575) << 20 | min(\strlen($name), 1023) << 40;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[12] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $names
     * @param  array<array-key, int>  $ids
     * @param  array<array-key, int>  $versions
     * @param  array<array-key, string>  $manifestHashes
     * @param  array<array-key, string>  $leaseTokens
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $deferrals
     * @return list<int>|null
     */
    public static function summarizeEntryBatchNative(array $names, array $ids, array $versions, array $manifestHashes, array $leaseTokens, array $attempts, array $deferrals): ?array
    {
        $count = \count($names);
        if ($count > self::W57_MAX_ITEMS || \count($ids) !== $count || \count($versions) !== $count || \count($manifestHashes) !== $count
            || \count($leaseTokens) !== $count || \count($attempts) !== $count || \count($deferrals) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w57Strings(array_map('strval', [...array_values($names), ...array_values($manifestHashes), ...array_values($leaseTokens)]));
        $ints = pack('q*', ...array_values($ids), ...array_values($versions), ...array_values($attempts), ...array_values($deferrals));
        $fields = self::W57_ENTRY_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w57Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w576_outbox_entry_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 57.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW57RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w57RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w57RustFfiState('set', $previous);
        }
    }

    public static function usesW57RustFfi(): bool
    {
        $forced = self::w57RustFfiState();

        return \is_bool($forced) ? $forced : self::W57_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 57.6 routing and binding in function statics (readonly classes cannot declare static properties).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w57` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w57RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        /** @var bool|null $enabled */
        static $enabled = null;
        /** @var FFI|false|null $ffi */
        static $ffi = null;
        /** @var CData|null $buffer */
        static $buffer = null;
        static $capacity = 0;
        if ($op === 'set') {
            $previous = $enabled;
            $enabled = \is_bool($val) ? $val : null;

            return $previous;
        }
        if ($op !== 'binding') {
            return $enabled;
        }
        $fields = \is_int($val) ? $val : 0;
        try {
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w57', self::W57_FFI_CDEF) ?? false;
            if ($ffi === false) {
                return null;
            }
            if ($buffer === null || $capacity < $fields) {
                $capacity = max(256, $fields);
                $buffer = $ffi->new('int64_t['.$capacity.']');
            }
        } catch (Throwable) {
            return null;
        }

        return $buffer === null ? null : [$ffi, $buffer];
    }

    /**
     * The batch's `$fields` output words from one Wave 57.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w57Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w57RustFfiState('binding', $fields);
        if ($binding === null) {
            return null;
        }
        try {
            if ($call($binding[0], $binding[1]) !== 1) {
                return null;
            }
            $values = unpack('P*', FFI::string($binding[1], $fields * 8));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * NUL-joined values and, when one holds a NUL, the same list joined with 0x01 so Rust
     * finds the separators where the two differ.
     *
     * @param  list<string>  $items
     * @return array{0: string, 1: string}
     */
    private static function w57Strings(array $items): array
    {
        $joined = implode("\0", $items);

        return $items !== [] && substr_count($joined, "\0") !== \count($items) - 1 ? [$joined, implode("\x01", $items)] : [$joined, ''];
    }
}
