<?php

declare(strict_types=1);

namespace App\Services\Nzb;

use App\Models\Release;
use App\Models\ReleaseNfo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\LazyCollection;
use PDO;
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
}
