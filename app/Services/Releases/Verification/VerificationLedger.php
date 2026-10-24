<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
}
