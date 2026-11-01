<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Par2FileHashEvidence
{
    public const RETRY_DELAY_SECONDS = 60;

    public const INVENTORY_PROOF_VERSION = 1;

    public static function sizeDecimal(mixed $size): ?string
    {
        if (is_int($size)) {
            return $size >= 0 ? (string) $size : null;
        }
        if (! is_string($size) || preg_match('/^(?:0|[1-9][0-9]{0,19})$/D', $size) !== 1
            || (strlen($size) === 20 && strcmp($size, '18446744073709551615') > 0)) {
            return null;
        }

        return $size;
    }

    public static function littleEndianSize(string $bytes): string
    {
        if (strlen($bytes) !== 8) {
            throw new RuntimeException('A PAR2 file size requires eight bytes.');
        }
        $words = unpack('Vlow/Vhigh', $bytes);
        if (! is_array($words)) {
            throw new RuntimeException('Could not decode a PAR2 file size.');
        }

        return bcadd(bcmul((string) $words['high'], '4294967296', 0), (string) $words['low'], 0);
    }

    /** @param array<string, mixed> $snapshot */
    public static function snapshotMatches(?object $current, array $snapshot): bool
    {
        if ($current === null || ! isset($snapshot['id'], $snapshot['guid']) || ! is_string($snapshot['guid']) || $snapshot['guid'] === ''
            || ! array_key_exists('name', $snapshot) || ! array_key_exists('searchname', $snapshot)) {
            return false;
        }
        foreach ($snapshot as $column => $value) {
            if (! property_exists($current, $column)) {
                return false;
            }
            $actual = $current->{$column};
            if (is_int($value)) {
                $actual = (int) $actual;
            }
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Preserve content evidence independently of optional filename collection.
     * Empty size marks a known digest whose size cannot be represented exactly.
     *
     * @param  array<string, mixed>|null  $expectedSnapshot
     * @param  null|callable(): bool  $guard
     */
    public static function remember(int $releaseId, string $fullMd5, string $hash16k, mixed $size, ?array $expectedSnapshot = null, ?callable $guard = null): bool
    {
        if ($expectedSnapshot === null || ! array_key_exists('name', $expectedSnapshot) || ! array_key_exists('searchname', $expectedSnapshot)
            || ($expectedSnapshot['id'] ?? null) !== $releaseId || ! is_string($expectedSnapshot['guid'] ?? null)
            || $expectedSnapshot['guid'] === ''
            || strlen($fullMd5) !== 32 || ! ctype_xdigit($fullMd5) || strlen($hash16k) !== 32 || ! ctype_xdigit($hash16k)) {
            return false;
        }
        $aborted = new RuntimeException('PAR2 file evidence ownership changed.');
        try {
            return DB::transaction(function () use ($releaseId, $fullMd5, $hash16k, $size, $expectedSnapshot, $guard, $aborted): bool {
                $current = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
                if ($current === null || ! is_string($current->guid ?? null) || $current->guid === ''
                    || ($guard !== null && ! $guard())) {
                    throw $aborted;
                }

                if (! self::snapshotMatches($current, $expectedSnapshot)) {
                    throw $aborted;
                }

                $captured = [];
                foreach (['guid', 'name', 'searchname', 'predb_id', 'isrenamed'] as $column) {
                    $captured[$column] = $current->{$column} ?? null;
                }
                $captured = array_merge($captured, $expectedSnapshot);
                $staleRowsRemoved = DB::table('par_file_hashes')->where('releases_id', $releaseId)
                    ->whereRaw('HEX(release_guid) <> ?', [strtoupper(bin2hex($current->guid))])->delete();
                $exactSize = self::sizeDecimal($size) ?? '';
                $repaired = 0;
                if ($exactSize !== '') {
                    $repaired = DB::table('par_file_hashes')->where('releases_id', $releaseId)->where('release_guid', $current->guid)
                        ->where('hash16k', strtolower($hash16k))->where('full_md5', strtolower($fullMd5))->where('file_size', '')->delete();
                }
                $hasExact = $exactSize === '' && DB::table('par_file_hashes')->where('releases_id', $releaseId)
                    ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($current->guid))])
                    ->where('hash16k', strtolower($hash16k))->where('full_md5', strtolower($fullMd5))->where('file_size', '<>', '')->exists();
                $inserted = ! $hasExact && DB::table('par_file_hashes')->insertOrIgnore([
                    'releases_id' => $releaseId, 'release_guid' => $current->guid,
                    'hash16k' => strtolower($hash16k), 'full_md5' => strtolower($fullMd5),
                    'file_size' => $exactSize,
                ]) > 0;
                if ($staleRowsRemoved > 0 || $repaired > 0 || $inserted) {
                    DB::table('par_file_hash_inventories')->where('releases_id', $releaseId)->delete();
                }
                if (! DB::table('par_hashes')->where('releases_id', $releaseId)->where('hash', strtolower($hash16k))->exists()) {
                    DB::table('par_hashes')->insertOrIgnore(['releases_id' => $releaseId, 'hash' => strtolower($hash16k)]);
                }
                if (($inserted || $repaired > 0) && (int) ($current->predb_id ?? 0) === 0 && in_array((int) ($current->isrenamed ?? 0), [0, 1], true)) {
                    DB::table('releases')->where('id', $releaseId)->where('predb_id', 0)->where('isrenamed', (int) $current->isrenamed)
                        ->whereRaw('HEX(guid) = ?', [strtoupper(bin2hex($current->guid))])->update(['proc_hash16k' => 0]);
                    DB::table('par2_hash_retry')->where('releases_id', $releaseId)->delete();
                    if (array_key_exists('proc_hash16k', $captured)) {
                        $captured['proc_hash16k'] = 0;
                    }
                }
                if ($guard !== null && ! $guard()) {
                    throw $aborted;
                }
                $after = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
                foreach ($captured as $column => $value) {
                    $actual = $after->{$column} ?? null;
                    if (is_int($value)) {
                        $actual = (int) $actual;
                    }
                    if ($actual !== $value) {
                        throw $aborted;
                    }
                }

                return true;
            });
        } catch (RuntimeException $exception) {
            if ($exception === $aborted) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * Compare under the caller's release row lock. A complete current set must not
     * certify descriptors retained from a different, still partial recovery set.
     *
     * @param  list<array{name?: string, size?: int|float, size_decimal?: string, hash?: string, hash_16K?: string, blocks?: int, next_offset?: int}>  $files
     */
    public static function inventoryEvidenceMatches(int $releaseId, string $guid, array $files): bool
    {
        $expected = [];
        foreach ($files as $file) {
            $size = self::sizeDecimal($file['size_decimal'] ?? $file['size'] ?? null);
            if (! isset($file['hash'], $file['hash_16K']) || $size === null) {
                return false;
            }
            $expected[strtolower($file['hash_16K']).':'.strtolower($file['hash']).':'.$size] = true;
        }
        if ($expected === []) {
            return false;
        }
        $stored = DB::table('par_file_hashes')->where('releases_id', $releaseId)
            ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($guid))]);
        if ((clone $stored)->count() !== count($expected)) {
            return false;
        }
        foreach ($stored->select(['hash16k', 'full_md5', 'file_size'])->cursor() as $row) {
            $key = $row->hash16k.':'.$row->full_md5.':'.$row->file_size;
            if (! isset($expected[$key])) {
                return false;
            }
            unset($expected[$key]);
        }

        return $expected === [];
    }

    /** @param array<string, mixed> $expectedSnapshot
     * @param  null|callable(): bool  $guard
     */
    public static function markInventoryComplete(int $releaseId, array $expectedSnapshot, ?callable $guard = null): bool
    {
        $aborted = new RuntimeException('PAR2 inventory completeness ownership changed.');
        try {
            return DB::transaction(function () use ($releaseId, $expectedSnapshot, $guard, $aborted): bool {
                $current = DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first();
                if (! self::snapshotMatches($current, $expectedSnapshot) || ($guard !== null && ! $guard())) {
                    throw $aborted;
                }
                $evidence = DB::table('par_file_hashes')->where('releases_id', $releaseId)
                    ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($current->guid))]);
                $count = (int) (clone $evidence)->count();
                if ($count === 0) {
                    return false;
                }
                $lastId = (int) (clone $evidence)->max('id');
                DB::table('par_file_hash_inventories')->updateOrInsert(
                    ['releases_id' => $releaseId],
                    ['release_guid' => $current->guid, 'evidence_last_id' => $lastId, 'evidence_count' => $count,
                        'proof_version' => self::INVENTORY_PROOF_VERSION],
                );
                if (($guard !== null && ! $guard())
                    || ! self::snapshotMatches(DB::table('releases')->where('id', $releaseId)->lockForUpdate()->first(), $expectedSnapshot)) {
                    throw $aborted;
                }

                return true;
            });
        } catch (RuntimeException $exception) {
            if ($exception === $aborted) {
                return false;
            }
            throw $exception;
        }
    }
}
