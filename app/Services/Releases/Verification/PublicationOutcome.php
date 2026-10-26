<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Throwable;

/**
 * What became of one remotely published candidate. The status names are the batch endpoint's
 * wire vocabulary; the primary and the worker share this type so neither invents its own.
 */
final class PublicationOutcome
{
    public const string FFI_CDEF = <<<'C'
        intptr_t nntmux_w18_verification_metadata_fingerprint(uint32_t operation, const char *input, size_t length, char *output, size_t capacity);
        C;

    // Enable only after paired common/uncommon mean and p99 all reach 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
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
        if (! self::$useRustFfi) {
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

    public const string PUBLISHED = 'published';

    /** Policy rejected the candidate; the row is terminal and holds no release. */
    public const string FILTERED = 'filtered';

    /** A covered release lacks the supplied metadata; the candidate is retained for review. */
    public const string METADATA_REVIEW = 'metadata_review';

    /** A partial upload failed a policy more articles may still satisfy: the worker returns it to waiting. */
    public const string DEFERRED = 'deferred';

    /** The claim no longer owns the candidate generation. */
    public const string STALE = 'stale';

    /** An exclusive maintenance holder or a durable pause forbids publication; retry later. */
    public const string PAUSED = 'paused';

    /** Not started (the batch ran out of time); nothing was read or written for it. */
    public const string RETRY = 'retry';

    public const string INVALID = 'invalid';

    public const string ERROR = 'error';

    /** Primary only: publication returned no release and the row names none, as the single endpoint reports it. */
    public const string UNRESOLVED = 'unresolved';

    /**
     * Worker only: the primary could not be reached, or did not answer in time. Nothing is known about
     * the item; unlike an error response, which the primary gave, sending it again is safe and expected.
     */
    public const string TRANSPORT = 'transport';

    /** Worker only: nothing was sent, or the single endpoint answered without a release; read the row for why. */
    public const string NO_RELEASE = 'no_release';

    public function __construct(public readonly string $status, public readonly ?int $releaseId = null, public readonly ?string $error = null) {}

    public function published(): bool
    {
        return self::$useRustFfi ? ($this->publishedNative() ?? $this->publishedPhp()) : $this->publishedPhp();
    }

    public function publishedPhp(): bool
    {
        return $this->status === self::PUBLISHED && $this->releaseId !== null;
    }

    public function publishedNative(): ?bool
    {
        $result = self::computeNative(2, [$this->status, $this->releaseId])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** Validate wire vocabulary without changing permissive construction of existing outcomes. */
    public static function isValidStatus(mixed $status): bool
    {
        return self::$useRustFfi ? (self::isValidStatusNative($status) ?? self::isValidStatusPhp($status)) : self::isValidStatusPhp($status);
    }

    public static function isValidStatusPhp(mixed $status): bool
    {
        return in_array($status, [self::PUBLISHED, self::FILTERED, self::METADATA_REVIEW, self::DEFERRED,
            self::STALE, self::PAUSED, self::RETRY, self::INVALID, self::ERROR, self::UNRESOLVED,
            self::TRANSPORT, self::NO_RELEASE], true);
    }

    public static function isValidStatusNative(mixed $status): ?bool
    {
        $result = self::computeNative(3, [$status])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }

    /** @return array{status: string, release_id: int|null, error: string|null} */
    public function toArray(): array
    {
        return self::$useRustFfi ? ($this->toArrayNative() ?? $this->toArrayPhp()) : $this->toArrayPhp();
    }

    /** @return array{status: string, release_id: int|null, error: string|null} */
    public function toArrayPhp(): array
    {
        return ['status' => $this->status, 'release_id' => $this->releaseId, 'error' => $this->error];
    }

    /** @return array{status: string, release_id: int|null, error: string|null}|null */
    public function toArrayNative(): ?array
    {
        $result = self::computeNative(4, [$this->status, $this->releaseId, $this->error])['value'] ?? null;

        return is_array($result) ? $result : null;
    }

    // Rust is the default only while the paired W57.6 benchmark clears 1.5x on all four metrics.
    private const bool W57_RUST_FFI_DEFAULT = true;

    public const string W57_FFI_CDEF = <<<'C'
        int32_t nntmux_w576_outcome_tally_batch(const char *statuses, size_t statuses_len, const char *status_twin, size_t status_twin_len, const char *ints, size_t ints_len, size_t count, int64_t *out, size_t out_cap);
        C;

    private const int W57_MAX_ITEMS = 1_048_576;

    public const int W57_OUTCOME_HEADER = 28;

    /** Wire status => declaration index (12 names none). */
    private const array W57_STATUS_INDEX = [self::PUBLISHED => 0, self::FILTERED => 1, self::METADATA_REVIEW => 2, self::DEFERRED => 3,
        self::STALE => 4, self::PAUSED => 5, self::RETRY => 6, self::INVALID => 7, self::ERROR => 8, self::UNRESOLVED => 9,
        self::TRANSPORT => 10, self::NO_RELEASE => 11];

    /** Declaration index => forwarding cycle kind: 0 forwarded, 1 duplicates, 2 settled, 3 transient, 4 failure. */
    private const array W57_CYCLE_KIND = [0, 2, 2, 2, 1, 3, 3, 4, 4, 2, 3, 2, 4];

    /**
     * Tally outcomes as the forwarder settles them, given each status and release id (null when none).
     * Published needs a release id; terminal cases (dropped or finished) are 0-2, 4, 7-9 and 12, retryable
     * (sent again) 5, 6 and 10; the cycle kind follows `ForwardVerifiedPublications::FORWARDING_CYCLE_KINDS`.
     *
     * Layout: `[0]` count, `[1..13]` per declaration index (13 unknown), `[14]` published, `[15]` terminal,
     * `[16]` retryable, `[17..21]` per cycle kind, `[22]` published without a release id, `[23]` first
     * failure and `[24]` first retryable index (-1 when none), `[25]` published ppm, `[26]` largest
     * published release id (-1 when none), `[27]` FNV-1a digest; then per outcome `case | published << 4 |
     * terminal << 5 | retryable << 6 | kind << 7 | present << 10 | min(bytes, 65535) << 11 |
     * clamp(release_id, 0, 2^36-1) << 27` (release id 0 when absent).
     *
     * @param  array<array-key, string>  $statuses
     * @param  array<array-key, int|null>  $releaseIds
     * @return list<int>
     */
    public static function tallyOutcomeBatch(array $statuses, array $releaseIds): array
    {
        return (self::usesW57RustFfi() ? self::tallyOutcomeBatchNative($statuses, $releaseIds) : null) ?? self::tallyOutcomeBatchPhp($statuses, $releaseIds);
    }

    /**
     * @param  array<array-key, string>  $statuses
     * @param  array<array-key, int|null>  $releaseIds
     * @return list<int>
     */
    public static function tallyOutcomeBatchPhp(array $statuses, array $releaseIds): array
    {
        $statuses = array_values($statuses);
        $releaseIds = array_values($releaseIds);
        $count = \count($statuses);
        $out = array_fill(0, self::W57_OUTCOME_HEADER, 0);
        $out[0] = $count;
        $out[23] = $out[24] = $out[26] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($statuses as $at => $status) {
            $status = (string) $status;
            $case = self::W57_STATUS_INDEX[$status] ?? 12;
            $id = $releaseIds[$at] ?? null;
            $present = $id !== null;
            $id = $present ? (int) $id : 0;
            $published = $case === 0 && $present;
            $terminal = $case <= 2 || $case === 4 || ($case >= 7 && $case <= 9) || $case === 12;
            $retryable = $case === 5 || $case === 6 || $case === 10;
            $kind = self::W57_CYCLE_KIND[$case];
            $out[1 + $case]++;
            $out[14] += (int) $published;
            $out[15] += (int) $terminal;
            $out[16] += (int) $retryable;
            $out[17 + $kind]++;
            $out[22] += (int) ($case === 0 && ! $present);
            if ($kind === 4 && $out[23] < 0) {
                $out[23] = $at;
            }
            if ($retryable && $out[24] < 0) {
                $out[24] = $at;
            }
            if ($published && $id > $out[26]) {
                $out[26] = $id;
            }
            $stored = $present ? ($id < 0 ? 0 : min($id, 68_719_476_735)) : 0;
            $row = $case | (int) $published << 4 | (int) $terminal << 5 | (int) $retryable << 6 | $kind << 7 | (int) $present << 10
                | min(\strlen($status), 65_535) << 11 | $stored << 27;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        if ($count > 0) {
            $out[25] = intdiv($out[14] * 1_000_000, $count);
        }
        $out[27] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust tally, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $statuses
     * @param  array<array-key, int|null>  $releaseIds
     * @return list<int>|null
     */
    public static function tallyOutcomeBatchNative(array $statuses, array $releaseIds): ?array
    {
        $count = \count($statuses);
        if ($count > self::W57_MAX_ITEMS || \count($releaseIds) !== $count) {
            return null;
        }
        $releaseIds = array_values($releaseIds);
        [$joined, $twin] = self::w57Strings(array_values($statuses));
        // Absence flags then ids; pack() reads null as 0 and booleans as 0/1.
        $ints = pack('q*', ...array_map('is_null', $releaseIds), ...$releaseIds);
        $fields = self::W57_OUTCOME_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w57Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w576_outcome_tally_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $out, $fields));
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
     * Wave 57.6 routing and binding in function statics (shared by every Wave 57.6 target, including readonly classes and enums).
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
