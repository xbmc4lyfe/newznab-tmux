<?php

declare(strict_types=1);

namespace App\Services\Releases\Verification;

use dariusiii\rarinfo\Par2Info;

/** Only complete, unambiguous equivalent metadata can share a successful source proof. */
final class Par2ProofIdentity
{
    /** @param list<array<string, mixed>> $packets */
    public static function fromPackets(array $packets): ?string
    {
        $set = null;
        $main = null;
        $descriptors = [];
        $checksums = [];
        foreach ($packets as $packet) {
            $packetSet = $packet['head_set_id'];
            if ($set !== null && $set !== $packetSet) {
                return null;
            }
            $set = $packetSet;
            switch ($packet['head_type']) {
                case Par2Info::PACKET_MAIN:
                    $value = [$packet['block_size'], $packet['rec_file_count'], $packet['rec_file_ids'], $packet['other_file_ids'] ?? [], $packet['head_hash']];
                    if ($main !== null && $main !== $value) {
                        return null;
                    }
                    $main = $value;
                    break;
                case Par2Info::PACKET_FILEDESC:
                    $id = $packet['file_id'];
                    // The parser trims names; retain the validated wire digest as well.
                    $value = [$packet['file_name'], $packet['file_length'], $packet['file_hash'], $packet['file_hash_16K'], $packet['head_hash']];
                    if (isset($descriptors[$id]) && $descriptors[$id] !== $value) {
                        return null;
                    }
                    $descriptors[$id] = $value;
                    break;
                case Par2Info::PACKET_FILEVER:
                    $id = $packet['file_id'];
                    $value = [$packet['block_checksums'], $packet['head_hash']];
                    if (isset($checksums[$id]) && $checksums[$id] !== $value) {
                        return null;
                    }
                    $checksums[$id] = $value;
                    break;
                case Par2Info::PACKET_RECOVERY:
                case Par2Info::PACKET_CREATOR:
                    break;
                default:
                    return null;
            }
        }
        if ($main === null || $main[0] < 1 || $main[1] < 1 || count($main[2]) !== $main[1]) {
            return null;
        }
        $ids = [];
        foreach (array_merge($main[2], $main[3]) as $entry) {
            $id = $entry[1];
            if (isset($ids[$id])) {
                return null;
            }
            $ids[$id] = true;
        }
        ksort($ids, SORT_STRING);
        ksort($descriptors, SORT_STRING);
        ksort($checksums, SORT_STRING);
        // Incomplete inventories and optional layouts retain the original verification path.
        if (array_keys($ids) !== array_keys($descriptors) || array_keys($ids) !== array_keys($checksums)) {
            return null;
        }

        return hash('sha256', serialize(['par2-source-proof-v1', $set, $main, $descriptors, $checksums]));
    }
}
