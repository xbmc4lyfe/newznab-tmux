<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\ReleaseNfo;
use App\Support\RustPipelineLibrary;
use FFI;
use FFI\CData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use PDO;
use Throwable;
use WeakMap;

/**
 * Persists and looks up releases.article_fingerprint (see {@see NzbArticleFingerprint}).
 * Column support is cached per PDO connection, so a schema change on a new connection is seen.
 */
final class ReleaseArticleFingerprintStore
{
    // Paired common/uncommon mean and p99 must all reach 1.5x before adoption.
    private static bool $useRustFfi = false;

    public static function enableRustFfi(): void
    {
        self::$useRustFfi = true;
    }

    public static function disableRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function resetRustFfi(): void
    {
        self::$useRustFfi = false;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    public const string COLUMN = 'article_fingerprint';

    /** @var WeakMap<PDO, bool>|null */
    private static ?WeakMap $columnSupport = null;

    public static function columnExists(): bool
    {
        $pdo = DB::connection()->getPdo();
        self::$columnSupport ??= new WeakMap;

        return self::$columnSupport[$pdo] ??= Schema::hasColumn('releases', self::COLUMN);
    }

    public static function store(int $releaseId, ?string $fingerprint): void
    {
        $values = self::updatePayload($fingerprint);
        if ($values !== [] && self::columnExists()) {
            Release::query()->where('id', $releaseId)->update($values);
        }
    }

    /**
     * Releases recorded with this fingerprint, oldest first.
     *
     * @param  list<string>  $columns
     * @return LazyCollection<int, Release>
     */
    public static function candidates(string $fingerprint, array $columns = ['id', 'guid', 'name', 'searchname', 'fromname', 'size']): LazyCollection
    {
        if (! self::columnExists()) {
            return LazyCollection::empty();
        }

        return Release::query()->where(self::COLUMN, $fingerprint)->select($columns)->lazyById(100);
    }

    /** @return array<string, string> */
    public static function updatePayload(?string $fingerprint): array
    {
        return (self::$useRustFfi ? self::updatePayloadNative($fingerprint) : null) ?? self::updatePayloadPhp($fingerprint);
    }

    /** @return array<string, string> */
    public static function updatePayloadPhp(?string $fingerprint): array
    {
        // Preserve exact bytes, including empty/non-hex values accepted by store().
        return $fingerprint === null ? [] : [self::COLUMN => $fingerprint];
    }

    /** @return array<string, string>|null */
    public static function updatePayloadNative(?string $fingerprint): ?array
    {
        $result = ReleaseNfo::computeNative(3, [$fingerprint]);

        return is_array($result) ? $result : null;
    }

    /**
     * Default for the Wave 43.2 toggle only; it stays false because the paired benchmark in
     * tests/Unit/RustNzbPar2ResultFingerprintStoreWorkerQueueW43ParityTest.php is below 1.5x.
     */
    public const bool W432_RUST_FFI_DEFAULT = false;

    /** The canonical NzbArticleFingerprint value: a lowercase SHA-1 hex digest. */
    public const string W432_CANONICAL_FINGERPRINT = '/\A[0-9a-f]{40}\z/';

    private static ?bool $useW432RustFfi = null;

    /** Force the Wave 43.2 helper onto Rust (true) or PHP (false); null restores W432_RUST_FFI_DEFAULT. */
    public static function useW432RustFfi(?bool $enable = true): void
    {
        self::$useW432RustFfi = $enable;
    }

    public static function usesW432RustFfi(): bool
    {
        return self::$useW432RustFfi ?? self::W432_RUST_FFI_DEFAULT;
    }

    public static function resetW432RustFfi(): void
    {
        self::$useW432RustFfi = null;
    }

    /**
     * Classify fingerprints before storage, as store() treats them: null is absent (no update), any other non-string is
     * invalid, and every string is stored byte for byte; stored values are canonical (lowercase 40-hex), noncanonical_hex
     * (40 hex digits with an uppercase letter), empty or other, with their distinct count and summed bytes.
     *
     * @param  array<array-key, mixed>  $fingerprints
     * @return array{fingerprints: int, absent: int, invalid: int, stored: int, canonical: int, noncanonical_hex: int, empty: int, other: int, distinct: int, payload_bytes: int}
     */
    public static function summarizeFingerprintBatch(array $fingerprints): array
    {
        return (self::usesW432RustFfi() ? self::summarizeFingerprintBatchNative($fingerprints) : null)
            ?? self::summarizeFingerprintBatchPhp($fingerprints);
    }

    /**
     * @param  array<array-key, mixed>  $fingerprints
     * @return array{fingerprints: int, absent: int, invalid: int, stored: int, canonical: int, noncanonical_hex: int, empty: int, other: int, distinct: int, payload_bytes: int}
     */
    public static function summarizeFingerprintBatchPhp(array $fingerprints): array
    {
        $absent = $invalid = $stored = $canonical = $noncanonical = $empty = $other = $bytes = 0;
        $seen = [];
        foreach ($fingerprints as $fingerprint) {
            if ($fingerprint === null) {
                $absent++;
            } elseif (! \is_string($fingerprint)) {
                $invalid++;
            } else {
                $stored++;
                $bytes += \strlen($fingerprint);
                $seen[$fingerprint] = true;
                if ($fingerprint === '') {
                    $empty++;
                } elseif (preg_match(self::W432_CANONICAL_FINGERPRINT, $fingerprint) === 1) {
                    $canonical++;
                } elseif (\strlen($fingerprint) === 40 && ctype_xdigit($fingerprint)) {
                    $noncanonical++;
                } else {
                    $other++;
                }
            }
        }

        return ['fingerprints' => \count($fingerprints), 'absent' => $absent, 'invalid' => $invalid, 'stored' => $stored, 'canonical' => $canonical,
            'noncanonical_hex' => $noncanonical, 'empty' => $empty, 'other' => $other, 'distinct' => \count($seen), 'payload_bytes' => $bytes];
    }

    /**
     * @param  array<array-key, mixed>  $fingerprints
     * @return array{fingerprints: int, absent: int, invalid: int, stored: int, canonical: int, noncanonical_hex: int, empty: int, other: int, distinct: int, payload_bytes: int}|null
     */
    public static function summarizeFingerprintBatchNative(array $fingerprints): ?array
    {
        $slots = [];
        $tail = [];
        foreach ($fingerprints as $fingerprint) {
            if (\is_string($fingerprint)) {
                array_push($slots, \strlen($fingerprint), 0);
                $tail[] = $fingerprint;
            } else {
                array_push($slots, -1, $fingerprint === null ? 1 : 2);
            }
        }
        $values = Par2CandidateRecoveryResult::evaluateW432Native(1, \count($fingerprints), $slots, implode('', $tail), 10);
        if ($values === null) {
            return null;
        }
        [$count, $absent, $invalid, $stored, $canonical, $noncanonical, $empty, $other, $distinct, $bytes] = $values;
        if ($count !== \count($fingerprints) || min($values) < 0 || $absent + $invalid + $stored !== $count
            || $canonical + $noncanonical + $empty + $other !== $stored || $distinct > $stored || ($stored > 0) !== ($distinct > 0)) {
            return null;
        }

        return ['fingerprints' => $count, 'absent' => $absent, 'invalid' => $invalid, 'stored' => $stored, 'canonical' => $canonical,
            'noncanonical_hex' => $noncanonical, 'empty' => $empty, 'other' => $other, 'distinct' => $distinct, 'payload_bytes' => $bytes];
    }

    // ---- Wave 60.2 batch plan ----------------------------------------------------------------

    /** Enabled: the minimum of three paired runs cleared every 1.5x gate (common mean/p99 4.36x/4.50x, uncommon 4.65x/5.13x); see RustNzbUploadStagingXmlElementsPar2RecoveryResultFingerprintStoreW60ParityTest. */
    public const bool W60_RUST_FFI_DEFAULT = true;

    private const string W60_FINGERPRINT_STORE_FFI_CDEF = <<<'C'
        intptr_t nntmux_w602_release_article_fingerprint_store_plan_batch(const char *items, size_t items_len, size_t count, int64_t *out, size_t out_cap);
        C;

    /** Summary words after the per-row words, the digest included. */
    public const int W60_FINGERPRINT_STORE_TAIL = 10;

    /**
     * Wave 60 routing, binding and output buffer, kept in function-local statics (no new static properties).
     * Operations: null reads the routing override, 'set' replaces it (returning the previous
     * one), 'ffi' resolves the binding and 'out' returns `[binding, buffer, capacity]` with room
     * for at least $val words (null without the library).
     */
    private static function w60RustFfiState(?string $op = null, mixed $val = null): mixed
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
                $ffi ??= RustPipelineLibrary::resolveDomain('nfo_w60', self::W60_FINGERPRINT_STORE_FFI_CDEF) ?? false;

                return $ffi === false ? null : $ffi;
            case 'out':
                $words = \is_int($val) ? $val : 0;
                if (! $out instanceof CData || $capacity < $words) {
                    $resolved = self::w60RustFfiState('ffi');
                    $buffer = $resolved instanceof FFI ? $resolved->new('int64_t['.max(1024, $words).']') : null;
                    if (! $buffer instanceof CData) {
                        return null;
                    }
                    [$out, $capacity] = [$buffer, max(1024, $words)];
                }

                return [$ffi, $out, $capacity];
            default:
                return $enabled;
        }
    }

    public static function usesW60RustFfi(): bool
    {
        $enabled = self::w60RustFfiState();

        return \is_bool($enabled) ? $enabled : self::W60_RUST_FFI_DEFAULT;
    }

    /**
     * Runs $callback with planFingerprintStoreBatch() routed to Rust ($enabled) or PHP, then restores the
     * previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW60RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w60RustFfiState('set', $enabled);
        try {
            return $callback();
        } finally {
            self::w60RustFfiState('set', $previous);
        }
    }

    /** @param  list<int>  $out */
    private static function w60Digest(array $out): int
    {
        /** @var array{1: int} $digest */
        $digest = unpack('J', hash('xxh64', pack('P*', ...$out), true));

        return $digest[1];
    }

    /**
     * The words the export wrote, or null when it declined (returning anything but $words).
     *
     * @return list<int>|null
     */
    private static function w60Words(mixed $written, CData $out, int $words): ?array
    {
        /** @var array<int, int>|false $values */
        $values = $written === $words ? unpack('P*', FFI::string($out, $words * 8)) : false;

        return \is_array($values) ? array_values($values) : null;
    }

    /** 1 to $max ASCII digits as an integer, otherwise null. */
    private static function w60Digits(string $value, int $max): ?int
    {
        $length = \strlen($value);

        return $length > 0 && $length <= $max && strspn($value, '0123456789') === $length ? (int) $value : null;
    }

    /** Releases sharing one fingerprint above which the key is quarantined. */
    public const int W60_COLLISION_LIMIT = 8;

    /** Days after which a stored fingerprint is refreshed. */
    public const int W60_STALE_DAYS = 30;

    /**
     * Per `fingerprint\tstored\tcount\tage_days` row `key | relation << 2 | collision << 4 | heavy << 5 |
     * stale << 6 | bad_number << 7 | duplicate << 8 | action << 9 | min(count, 0xFFFF) << 12 |
     * min(age, 0xFFFF) << 28 | (crc32(lower(fingerprint)) & 0x7FFFF) << 44`. Key: 1 canonical
     * (lowercase 40-hex, W432_CANONICAL_FINGERPRINT), 2 40 hex digits with an uppercase letter, 0
     * empty, 3 other. Relation to the stored value: 0 none stored, 1 identical, 2 equal ignoring
     * ASCII case, 3 conflict. Count (1-9 digits) of releases sharing the key: collision from 2,
     * heavy above W60_COLLISION_LIMIT; stale above W60_STALE_DAYS (1-6 digits); a bad number
     * counts as 0. Duplicate: a hex key already seen in the batch (case-insensitive). Action: 0
     * skip (key 0 or 3), 5 quarantine (heavy), 4 replace (conflict), 1 store (none stored), 3
     * refresh (case drift or stale), 2 keep. Then the per-action counts, the distinct hex keys, the
     * collision and stale counts and the XXH64 digest of all earlier words.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planFingerprintStoreBatchPhp(array $rows): array
    {
        $out = $seen = [];
        $actions = [0, 0, 0, 0, 0, 0];
        $collisions = $stales = 0;
        foreach ($rows as $row) {
            [$fingerprint, $stored, $count, $age] = explode("\t", (string) $row, 4) + ['', '', '', ''];
            $hex = \strlen($fingerprint) === 40 && strspn($fingerprint, '0123456789abcdefABCDEF') === 40;
            $key = $hex ? (strspn($fingerprint, '0123456789abcdef') === 40 ? 1 : 2) : ($fingerprint === '' ? 0 : 3);
            $relation = match (true) {
                $stored === '' => 0,
                $stored === $fingerprint => 1,
                strcasecmp($stored, $fingerprint) === 0 => 2,
                default => 3,
            };
            $sharing = self::w60Digits($count, 9);
            $days = self::w60Digits($age, 6);
            $bad = $sharing === null || $days === null;
            $sharing ??= 0;
            $days ??= 0;
            $heavy = $sharing > self::W60_COLLISION_LIMIT;
            $stale = $days > self::W60_STALE_DAYS;
            $lower = strtolower($fingerprint);
            $duplicate = $hex && isset($seen[$lower]);
            if ($hex) {
                $seen[$lower] = true;
            }
            $action = match (true) {
                ! $hex => 0,
                $heavy => 5,
                $relation === 3 => 4,
                $relation === 0 => 1,
                $relation === 2 || $stale => 3,
                default => 2,
            };
            $actions[$action]++;
            $collisions += $sharing >= 2 ? 1 : 0;
            $stales += $stale ? 1 : 0;
            $out[] = $key | $relation << 2 | ($sharing >= 2 ? 1 : 0) << 4 | (int) $heavy << 5 | (int) $stale << 6 | (int) $bad << 7 | (int) $duplicate << 8
                | $action << 9 | min($sharing, 0xFFFF) << 12 | min($days, 0xFFFF) << 28 | (crc32($lower) & 0x7FFFF) << 44;
        }
        array_push($out, ...$actions);
        array_push($out, \count($seen), $collisions, $stales);
        $out[] = self::w60Digest($out);

        return $out;
    }

    /**
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planFingerprintStoreBatch(array $rows): array
    {
        return self::usesW60RustFfi() ? self::planFingerprintStoreBatchNative($rows) : self::planFingerprintStoreBatchPhp($rows);
    }

    /**
     * Rust batch; falls back to PHP when the library is missing, a row holds a NUL byte or the
     * column is not a list.
     *
     * @param  array<array-key, string>  $rows
     * @return list<int>
     */
    public static function planFingerprintStoreBatchNative(array $rows): array
    {
        $count = \count($rows);
        $words = $count + self::W60_FINGERPRINT_STORE_TAIL;
        /** @var array{FFI, CData, int}|null $state */
        $state = $count === 0 ? null : self::w60RustFfiState('out', $words);
        if ($state === null || ! array_is_list($rows)) {
            return self::planFingerprintStoreBatchPhp($rows);
        }
        $blob = implode("\0", $rows);
        [$ffi, $out, $cap] = $state;
        try {
            // @phpstan-ignore method.notFound (Declared by W60_FINGERPRINT_STORE_FFI_CDEF.)
            $written = $ffi->nntmux_w602_release_article_fingerprint_store_plan_batch($blob, \strlen($blob), $count, $out, $cap);
        } catch (Throwable) {
            $written = -1;
        }

        return self::w60Words($written, $out, $words) ?? self::planFingerprintStoreBatchPhp($rows);
    }
}
