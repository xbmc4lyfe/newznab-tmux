<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Par2PacketAccumulator;
use App\Services\Releases\Verification\LeaseHeartbeat;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Par2DescriptorEvidence
{
    // Single-call FFI remains opt-in until every paired mean/p99 gate reaches 1.5x.
    private static bool $useRustFfi = false;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    /** Validated descriptor provenance only; this version makes no eligibility claim. */
    public const PROOF_VERSION = 1;

    /**
     * Preserve packet identities and literal names independently of file collection.
     * The release lock serializes append/replay and GUID replacement. A checksum
     * identity never permits overwriting different descriptor bytes.
     *
     * @param  array{id: int, guid: string, name: ?string, searchname: ?string, predb_id: int, isrenamed: int}  $snapshot
     * @param  null|callable(): bool  $guard
     */
    public static function remember(Par2PacketAccumulator $inventory, array $snapshot, ?callable $guard = null): bool
    {
        $aborted = new RuntimeException('PAR2 descriptor evidence ownership changed.');
        try {
            return DB::transaction(function () use ($inventory, $snapshot, $guard, $aborted): bool {
                $current = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first();
                if (! Par2FileHashEvidence::snapshotMatches($current, $snapshot) || $snapshot['guid'] === ''
                    || ($guard !== null && ! $guard())) {
                    throw $aborted;
                }
                DB::table('par2_descriptor_evidence')->where('releases_id', $snapshot['id'])
                    ->whereRaw('HEX(release_guid) <> ?', [strtoupper(bin2hex($snapshot['guid']))])->delete();
                foreach (array_chunk($inventory->descriptors(), 25) as $descriptors) {
                    $rows = [];
                    foreach ($descriptors as $descriptor) {
                        if ($guard !== null && ! $guard()) {
                            throw $aborted;
                        }
                        $row = self::descriptorRow($snapshot['id'], $snapshot['guid'], $descriptor);
                        $checksum = $descriptor['packet_checksum'];
                        if (isset($rows[$checksum]) && $rows[$checksum] !== $row) {
                            throw new RuntimeException('PAR2 descriptor checksum identity contains different evidence.');
                        }
                        $rows[$checksum] = $row;
                    }
                    $existing = DB::table('par2_descriptor_evidence')->where('releases_id', $snapshot['id'])
                        ->where('release_guid', $snapshot['guid'])
                        ->whereRaw('HEX(release_guid) = ?', [strtoupper(bin2hex($snapshot['guid']))])
                        ->whereIn('packet_checksum', array_keys($rows))->get();
                    foreach ($existing as $stored) {
                        $checksum = strtolower($stored->packet_checksum);
                        if (! isset($rows[$checksum])) {
                            throw new RuntimeException('PAR2 descriptor checksum identity contains different evidence.');
                        }
                        $row = $rows[$checksum];
                        if (! self::evidenceRowsEqual((array) $stored, $row)) {
                            throw new RuntimeException('PAR2 descriptor checksum identity contains different evidence.');
                        }
                        unset($rows[$checksum]);
                    }
                    if ($rows !== []) {
                        DB::table('par2_descriptor_evidence')->insert(array_values($rows));
                    }
                    if (($guard !== null && ! $guard()) || ! Par2FileHashEvidence::snapshotMatches(
                        DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first(), $snapshot,
                    )) {
                        throw $aborted;
                    }
                }
                if (($guard !== null && ! $guard()) || ! Par2FileHashEvidence::snapshotMatches(
                    DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()->first(), $snapshot,
                )) {
                    throw $aborted;
                }

                return true;
            });
        } catch (RuntimeException $error) {
            if ($error === $aborted) {
                return false;
            }

            throw $error;
        }
    }

    /**
     * @param  array<string, string>  $descriptor
     * @return array<string, int|string>
     */
    public static function descriptorRow(int $releaseId, string $guid, array $descriptor): array
    {
        return self::$useRustFfi ? (self::descriptorRowNative($releaseId, $guid, $descriptor) ?? self::descriptorRowPhp($releaseId, $guid, $descriptor)) : self::descriptorRowPhp($releaseId, $guid, $descriptor);
    }

    /**
     * @param  array<string, string>  $descriptor
     * @return array<string, int|string>
     */
    public static function descriptorRowPhp(int $releaseId, string $guid, array $descriptor): array
    {
        return [
            'releases_id' => $releaseId, 'release_guid' => $guid,
            'packet_checksum' => $descriptor['packet_checksum'],
            'recovery_set_id' => $descriptor['recovery_set_id'], 'file_id' => $descriptor['file_id'],
            'full_md5' => $descriptor['hash'], 'hash16k' => $descriptor['hash_16K'],
            'file_size' => $descriptor['size_decimal'],
            'raw_filename_base64' => base64_encode($descriptor['filename_bytes']),
            'descriptor_proof_version' => self::PROOF_VERSION, 'classification' => 'unknown',
            'classification_version' => 0, 'classification_provenance' => 'unclassified',
        ];
    }

    /**
     * @param  array<string, string>  $descriptor
     * @return array<string, int|string>|null
     */
    public static function descriptorRowNative(int $releaseId, string $guid, array $descriptor): ?array
    {
        $result = LeaseHeartbeat::computeNative(12, [$releaseId, $guid, $descriptor])['value'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, int|string>  $incoming
     */
    public static function evidenceRowsEqual(array $stored, array $incoming): bool
    {
        return self::$useRustFfi ? (self::evidenceRowsEqualNative($stored, $incoming) ?? self::evidenceRowsEqualPhp($stored, $incoming)) : self::evidenceRowsEqualPhp($stored, $incoming);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, int|string>  $incoming
     */
    public static function evidenceRowsEqualPhp(array $stored, array $incoming): bool
    {
        foreach ($incoming as $column => $value) {
            $actual = $stored[$column];
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
     * @param  array<string, mixed>  $stored
     * @param  array<string, int|string>  $incoming
     */
    public static function evidenceRowsEqualNative(array $stored, array $incoming): ?bool
    {
        $result = LeaseHeartbeat::computeNative(13, [$stored, $incoming])['value'] ?? null;

        return is_bool($result) ? $result : null;
    }
}
