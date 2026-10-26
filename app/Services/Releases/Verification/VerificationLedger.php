<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Pending-capacity accounting and the locks that order verification writers.
 *
 * Legacy file manifests serialize every writer on the capacity row for its whole transaction.
 * Database manifests are protected by candidate and manifest row locks instead, so writers run
 * concurrently: each applies its capacity change as one conditional delta, its last lock request
 * before commit, and publications serialize only with each other on their own row.
 */
final class VerificationLedger
{
    private static bool $useRustFfi = true;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public const string LOCKS_TABLE = 'release_verification_locks';

    public const string PUBLICATION_LOCK = 'publication';

    /** Publication lock rows `publication:0` … `publication:1023`, chosen by duplicate-search key. */
    public const int PUBLICATION_STRIPES = 1024;

    /** Database manifests let verification writers run concurrently. */
    public function concurrent(): bool
    {
        return config('release_verification.manifest_storage', 'database') === 'database';
    }

    /** Take the legacy global writer lock when manifests are files; database manifests need none. */
    public function enter(string $missing = 'Verification migration has not initialized capacity state.'): void
    {
        if ($this->concurrent()) {
            return;
        }
        if (DB::table('release_verification_state')->where('id', 1)->lockForUpdate()->first() === null) {
            throw new RuntimeException($missing);
        }
    }

    /**
     * Keys a release's duplicate search looks up committed releases by: its article fingerprint,
     * predb id, searchname, name and exact size. Two publications that could find each other as
     * duplicates share at least one key. Names compare under a case- and accent-insensitive
     * collation, so their keys keep only lowercase ASCII letters and digits: a coarser key only
     * serializes more.
     *
     * @return list<string>
     */
    public static function publicationKeys(int $size, ?string $fingerprint, string $name, string $searchName, int $predbId): array
    {
        return self::publicationKeysPhp($size, $fingerprint, $name, $searchName, $predbId);
    }

    /** @return list<string> */
    public static function publicationKeysPhp(int $size, ?string $fingerprint, string $name, string $searchName, int $predbId): array
    {
        $fold = static fn (string $value): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value)));

        return array_values(array_filter([
            $fingerprint !== null && $fingerprint !== '' ? 'fingerprint:'.strtolower($fingerprint) : null,
            $predbId > 0 ? 'predb:'.$predbId : null,
            $searchName !== '' ? 'name:'.$fold($searchName) : null,
            $name !== '' ? 'name:'.$fold($name) : null,
            $size > 0 ? 'size:'.$size : null,
        ]));
    }

    public static function publicationStripe(string $key): string
    {
        return self::publicationStripePhp($key);
    }

    public static function publicationStripePhp(string $key): string
    {
        return self::PUBLICATION_LOCK.':'.(crc32($key) % self::PUBLICATION_STRIPES);
    }

    /** @return list<string>|null */
    public static function publicationKeysNative(int $size, ?string $fingerprint, string $name, string $searchName, int $predbId): ?array
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        // Str::ascii's language tables remain the collation contract; include their cost in FFI timing.
        $fold = static fn (string $value): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value)));
        $foldedName = $name === '' ? '' : $fold($name);
        $foldedSearch = $searchName === '' ? '' : $fold($searchName);
        $fingerprint ??= '';
        $capacity = 0;
        $buffer = RustPipelineLibrary::obtainScratch($ffi, strlen($fingerprint) + strlen($foldedName) + strlen($foldedSearch) + 256, $capacity);
        if ($buffer === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $written = $ffi->nntmux_verification_publication_keys($size, $predbId, $fingerprint, strlen($fingerprint), $foldedName, strlen($foldedName), $foldedSearch, strlen($foldedSearch), (int) ($name !== ''), (int) ($searchName !== ''), $buffer, $capacity);
        if (! is_int($written) || $written < 0 || $written > $capacity) {
            return null;
        }
        // Length-delimited serialization preserves embedded NULs and newlines in fingerprints.
        $keys = unserialize(FFI::string($buffer, $written), ['allowed_classes' => false]);

        return is_array($keys) ? $keys : null;
    }

    public static function publicationStripeNative(string $key): ?string
    {
        if (! self::$useRustFfi || ($ffi = PreparedManifest::nativeLibrary()) === null) {
            return null;
        }
        // @phpstan-ignore method.notFound
        $stripe = $ffi->nntmux_verification_publication_stripe($key, strlen($key));

        return $stripe >= 0 ? self::PUBLICATION_LOCK.':'.$stripe : null;
    }

    /**
     * Serialize publications (and their duplicate detection) that share a duplicate-search key,
     * not with admission or unrelated releases.
     *
     * One statement locks all of a publication's stripes in primary-key order, so overlapping
     * publications cannot deadlock. It then locks the shared `publication` row: in share mode,
     * which striped publications hold together, or exclusively when it has no keys (or some
     * stripes do not exist yet), so such a publication waits for, and excludes, every other.
     *
     * Only locking reads run here: the caller's first plain read, its duplicate search, must take
     * its REPEATABLE READ view after these locks are granted to see the releases committed while
     * it waited.
     *
     * @param  list<string>  $keys
     */
    public function enterPublication(array $keys, string $missing = 'Verification migration has not initialized capacity state.',
        ?PublicationTiming $timing = null): void
    {
        if (! $this->concurrent()) {
            $started = $timing === null ? 0 : hrtime(true);
            $completed = false;
            try {
                $this->enter($missing);
                $completed = true;
            } finally {
                $timing?->recordLock(['path' => 'legacy_state', 'stripe_count' => 0, 'stripe_lock_ns' => 0,
                    'publication_row_lock_ns' => hrtime(true) - $started, 'completed' => $completed]);
            }

            return;
        }
        $stripes = array_values(array_unique(array_map(self::publicationStripe(...), $keys)));
        sort($stripes);
        $started = $timing === null ? 0 : hrtime(true);
        $stripeEnded = null;
        $striped = false;
        $completed = false;
        // Recorded even when a lock statement throws (a deadlock or lock-wait timeout retries the
        // transaction), so the contended attempts keep their stripe and publication-row split.
        try {
            $striped = $stripes !== []
                && DB::table(self::LOCKS_TABLE)->whereIn('name', $stripes)->orderBy('name')->lockForUpdate()->count() === count($stripes);
            $stripeEnded = $timing === null ? 0 : hrtime(true);
            $shared = DB::table(self::LOCKS_TABLE)->where('name', self::PUBLICATION_LOCK);
            $row = ($striped ? $shared->sharedLock() : $shared->lockForUpdate())->first();
            $completed = true;
        } finally {
            if ($timing !== null) {
                $now = hrtime(true);
                $timing->recordLock(['path' => $stripeEnded === null ? 'striped' : ($striped ? 'striped' : 'exclusive'),
                    'stripe_count' => count($stripes), 'stripe_lock_ns' => ($stripeEnded ?? $now) - $started,
                    'publication_row_lock_ns' => $stripeEnded === null ? 0 : $now - $stripeEnded, 'completed' => $completed]);
            }
        }
        if ($row === null) {
            throw new RuntimeException('Verification publication lock has not been initialized.');
        }
    }

    /** Advisory, non-locking capacity check that lets a full ledger fail before expensive work. */
    public function fits(int $delta): bool
    {
        if ($delta <= 0) {
            return true;
        }
        $pending = DB::table('release_verification_state')->where('id', 1)->value('pending_bytes');
        if ($pending === null) {
            throw new RuntimeException('Verification migration has not initialized capacity state.');
        }

        return (int) $pending + $delta <= (int) config('release_verification.pending_bytes');
    }

    /**
     * Apply a pending-bytes delta, refusing a growth that would exceed capacity.
     *
     * The capacity row stays locked until commit, so callers issue this as their last lock request:
     * afterwards they may write only rows their transaction already holds.
     */
    public function adjust(int $delta): bool
    {
        if ($delta === 0) {
            return true;
        }
        $query = DB::table('release_verification_state')->where('id', 1);
        if ($delta > 0) {
            $changed = $query->whereRaw('pending_bytes + ? <= ?', [$delta, (int) config('release_verification.pending_bytes')])
                ->increment('pending_bytes', $delta);
        } else {
            $changed = $query->decrement('pending_bytes', -$delta);
        }
        if ($changed === 1) {
            return true;
        }
        if (! DB::table('release_verification_state')->where('id', 1)->exists()) {
            throw new RuntimeException('Verification migration has not initialized capacity state.');
        }

        return false;
    }

    // Rust is the default only while the paired W58.6 benchmark clears 1.5x on all four metrics.
    private const bool W58_RUST_FFI_DEFAULT = true;

    public const string W58_FFI_CDEF = <<<'C'
        int32_t nntmux_w586_ledger_summary_batch(const char *states, size_t states_len, const char *state_twin, size_t state_twin_len, const char *ints, size_t ints_len, size_t count, int64_t now, int64_t capacity, int64_t pending, int64_t max_attempts, int64_t *out, size_t out_cap);
        C;

    private const int W58_MAX_ITEMS = 1_048_576;

    public const int W58_LEDGER_HEADER = 26;

    /** Ledger state => index (8 names none). */
    private const array W58_LEDGER_STATE = ['waiting_upload' => 0, 'ready' => 1, 'verifying' => 2, 'verified' => 3,
        'published' => 4, 'dirty' => 5, 'expired' => 6, 'stale' => 7];

    /** Allowed target-state bits per source state. */
    private const array W58_LEDGER_ALLOWED = [0x62, 0xE4, 0xEA, 0xB0, 0x00, 0x43, 0x00, 0x42];

    /**
     * Validate ledger entries applied in order. Class 0 valid, 1 no-op, 2 forbidden, 3 unknown state.
     * Attempts clamp to 0..2^31-1, failures to 0..attempts, timestamps (unix seconds) and `$now` to
     * 0..2^40, deltas to ±2^50, `$capacity` and `$pending` to 0..2^62 and `$maxAttempts` to 1..2^31-1.
     * A valid transition applies its delta as `adjust()` does: growth beyond capacity is refused, a
     * shrink floors at 0.
     *
     * Layout: `[0]` count, `[1..4]` per class, `[5..13]` per target state, `[14]` refused, `[15]` final
     * pending bytes, `[16]` first forbidden or unknown and `[17]` first refused index (-1 when none),
     * `[18]` exhausted, `[19]` attempts, `[20]` failures, `[21]` oldest age and `[22]` its index (-1
     * when none), `[23]` missing and `[24]` future timestamps, `[25]` FNV-1a digest; then per entry
     * `class | from << 2 | to << 6 | refused << 10 | missing << 11 | future << 12 | exhausted << 13 |
     * min(failures, 1023) << 14 | min(attempts, 1023) << 24 | min(age, 2^28-1) << 34`.
     *
     * @param  array<array-key, string>  $from
     * @param  array<array-key, string>  $to
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $failures
     * @param  array<array-key, int>  $updatedAt
     * @param  array<array-key, int>  $deltas
     * @return list<int>
     */
    public static function summarizeLedgerBatch(array $from, array $to, array $attempts, array $failures, array $updatedAt,
        array $deltas, int $now, int $capacity, int $pending, int $maxAttempts): array
    {
        return (self::usesW58RustFfi() ? self::summarizeLedgerBatchNative($from, $to, $attempts, $failures, $updatedAt, $deltas, $now, $capacity, $pending, $maxAttempts) : null)
            ?? self::summarizeLedgerBatchPhp($from, $to, $attempts, $failures, $updatedAt, $deltas, $now, $capacity, $pending, $maxAttempts);
    }

    /**
     * @param  array<array-key, string>  $from
     * @param  array<array-key, string>  $to
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $failures
     * @param  array<array-key, int>  $updatedAt
     * @param  array<array-key, int>  $deltas
     * @return list<int>
     */
    public static function summarizeLedgerBatchPhp(array $from, array $to, array $attempts, array $failures, array $updatedAt,
        array $deltas, int $now, int $capacity, int $pending, int $maxAttempts): array
    {
        $from = array_values($from);
        $to = array_values($to);
        $attempts = array_values($attempts);
        $failures = array_values($failures);
        $updatedAt = array_values($updatedAt);
        $deltas = array_values($deltas);
        $now = max(0, min($now, 1 << 40));
        $capacity = max(0, min($capacity, 1 << 62));
        $pending = max(0, min($pending, 1 << 62));
        $maxAttempts = max(1, min($maxAttempts, 2_147_483_647));
        $count = \count($from);
        $out = array_fill(0, self::W58_LEDGER_HEADER, 0);
        $out[0] = $count;
        $out[16] = $out[17] = $out[22] = -1;
        $digest = 2_166_136_261;
        $rows = [];
        foreach ($from as $at => $source) {
            $source = self::W58_LEDGER_STATE[(string) $source] ?? 8;
            $target = self::W58_LEDGER_STATE[(string) ($to[$at] ?? '')] ?? 8;
            $class = match (true) {
                $source === 8 || $target === 8 => 3,
                $source === $target => 1,
                ((self::W58_LEDGER_ALLOWED[$source] >> $target) & 1) === 1 => 0,
                default => 2,
            };
            $tries = max(0, min((int) ($attempts[$at] ?? 0), 2_147_483_647));
            $failed = max(0, min((int) ($failures[$at] ?? 0), $tries));
            $stamp = max(0, min((int) ($updatedAt[$at] ?? 0), 1 << 40));
            $delta = max(-(1 << 50), min((int) ($deltas[$at] ?? 0), 1 << 50));
            $missing = $stamp === 0;
            $future = $stamp > $now;
            $age = $missing || $future ? 0 : $now - $stamp;
            $exhausted = $tries >= $maxAttempts;
            $refused = false;
            if ($class === 0) {
                if ($delta > 0) {
                    if ($pending + $delta > $capacity) {
                        $refused = true;
                    } else {
                        $pending += $delta;
                    }
                } elseif ($delta < 0) {
                    $pending = max(0, $pending + $delta);
                }
            }
            $out[1 + $class]++;
            $out[5 + $target]++;
            if ($refused) {
                $out[14]++;
                if ($out[17] < 0) {
                    $out[17] = $at;
                }
            }
            if ($class >= 2 && $out[16] < 0) {
                $out[16] = $at;
            }
            $out[18] += (int) $exhausted;
            $out[19] += $tries;
            $out[20] += $failed;
            if (! $missing && ! $future && ($out[22] < 0 || $age > $out[21])) {
                $out[21] = $age;
                $out[22] = $at;
            }
            $out[23] += (int) $missing;
            $out[24] += (int) $future;
            $row = $class | $source << 2 | $target << 6 | (int) $refused << 10 | (int) $missing << 11 | (int) $future << 12
                | (int) $exhausted << 13 | min($failed, 1023) << 14 | min($tries, 1023) << 24 | min($age, (1 << 28) - 1) << 34;
            $digest = (($digest ^ (($row ^ ($row >> 32)) & 0xFFFFFFFF)) * 16_777_619) & 0xFFFFFFFF;
            $rows[] = $row;
        }
        $out[15] = $pending;
        $out[25] = $digest;

        return [...$out, ...$rows];
    }

    /**
     * Rust summary, or null when PHP must compute it (unavailable library or rejected input).
     *
     * @param  array<array-key, string>  $from
     * @param  array<array-key, string>  $to
     * @param  array<array-key, int>  $attempts
     * @param  array<array-key, int>  $failures
     * @param  array<array-key, int>  $updatedAt
     * @param  array<array-key, int>  $deltas
     * @return list<int>|null
     */
    public static function summarizeLedgerBatchNative(array $from, array $to, array $attempts, array $failures, array $updatedAt,
        array $deltas, int $now, int $capacity, int $pending, int $maxAttempts): ?array
    {
        $count = \count($from);
        if ($count > self::W58_MAX_ITEMS || \count($to) !== $count || \count($attempts) !== $count || \count($failures) !== $count
            || \count($updatedAt) !== $count || \count($deltas) !== $count) {
            return null;
        }
        [$joined, $twin] = self::w58Strings(array_values($from), array_values($to));
        $ints = pack('q*', ...array_values($attempts), ...array_values($failures), ...array_values($updatedAt), ...array_values($deltas));
        $fields = self::W58_LEDGER_HEADER + $count;

        // @phpstan-ignore method.notFound
        return self::w58Call($fields, static fn (FFI $ffi, CData $out): mixed => $ffi->nntmux_w586_ledger_summary_batch($joined, \strlen($joined), $twin, \strlen($twin), $ints, \strlen($ints), $count, $now, $capacity, $pending, $maxAttempts, $out, $fields));
    }

    /**
     * Run `$callback` with the Wave 58.6 Rust batch path forced on or off, then restore the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW58RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w58RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w58RustFfiState('set', $previous);
        }
    }

    public static function usesW58RustFfi(): bool
    {
        $forced = self::w58RustFfiState();

        return \is_bool($forced) ? $forced : self::W58_RUST_FFI_DEFAULT;
    }

    /**
     * Wave 58.6 routing and binding in function statics (no static properties, so readonly classes can share it).
     * No `$op` returns the forced routing (null for the default); `'set'` replaces it with `$val`
     * and returns the previous value; `'binding'` returns the cached `additional_w58` binding and
     * an output buffer of at least `$val` values, or null.
     */
    private static function w58RustFfiState(?string $op = null, mixed $val = null): mixed
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
            $ffi ??= RustPipelineLibrary::resolveDomain('additional_w58', self::W58_FFI_CDEF) ?? false;
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
     * The batch's `$fields` output words from one Wave 58.6 call, or null when PHP must compute it.
     *
     * @param  callable(FFI, CData): mixed  $call
     * @return list<int>|null
     */
    private static function w58Call(int $fields, callable $call): ?array
    {
        /** @var array{0: FFI, 1: CData}|null $binding */
        $binding = self::w58RustFfiState('binding', $fields);
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
     * Columns NUL-joined one after another and, when a value holds a NUL, the same columns joined
     * with 0x01 so Rust finds the separators where the two differ.
     *
     * @param  list<string>  ...$columns
     * @return array{0: string, 1: string}
     */
    private static function w58Strings(array ...$columns): array
    {
        $joined = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $joined .= ($items > 0 ? "\0" : '').implode("\0", $column);
                $items += \count($column);
            }
        }
        if ($items === 0 || substr_count($joined, "\0") === $items - 1) {
            return [$joined, ''];
        }
        $twin = '';
        $items = 0;
        foreach ($columns as $column) {
            if ($column !== []) {
                $twin .= ($items > 0 ? "\x01" : '').implode("\x01", $column);
                $items += \count($column);
            }
        }

        return [$joined, $twin];
    }
}
