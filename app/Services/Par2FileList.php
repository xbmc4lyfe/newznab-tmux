<?php

declare(strict_types=1);

namespace App\Services;

use dariusiii\rarinfo\Par2Info;

final class Par2FileList
{
    /**
     * Report declared file identities without a usable description in their recovery set.
     * Descriptor-only packets are sufficient; IFSC packets are not required for a named file.
     */
    public function hasUnresolvedDescriptors(Par2Info $par2Info): bool
    {
        if ($par2Info->error) {
            return true;
        }

        $packets = $par2Info->getPackets(true);
        if (! is_array($packets)) {
            return true;
        }

        $required = $described = [];
        foreach ($packets as $packet) {
            $setId = $packet['head_set_id'];
            if ($packet['head_type'] === Par2Info::PACKET_MAIN) {
                foreach (array_merge($packet['rec_file_ids'] ?? [], $packet['other_file_ids'] ?? []) as $fileId) {
                    $fileId = is_array($fileId) ? reset($fileId) : $fileId;
                    if (! is_string($fileId) || $fileId === '') {
                        return true;
                    }
                    $required[$setId][$fileId] = true;
                }
            } elseif ($packet['head_type'] === Par2Info::PACKET_FILEVER) {
                $required[$setId][$packet['file_id']] = true;
            } elseif ($packet['head_type'] === Par2Info::PACKET_FILEDESC) {
                $fileId = $packet['file_id'];
                $required[$setId][$fileId] = true;
                if (! array_key_exists($fileId, $described[$setId] ?? [])) {
                    $name = $packet['file_name'] ?? null;
                    $described[$setId][$fileId] = is_string($name) && trim($name) !== '';
                }
            }
        }

        foreach ($required as $setId => $fileIds) {
            foreach ($fileIds as $fileId => $_) {
                if (! ($described[$setId][$fileId] ?? false)) {
                    return true;
                }
            }
        }

        return $described === [];
    }

    /**
     * Restore descriptor metadata when verification packets preceded it in the recovery set.
     *
     * @return array<string, array{name?: string, size?: int|float, hash?: string, hash_16K?: string, blocks: int, next_offset?: int}>
     */
    public function getFiles(Par2Info $par2Info): array
    {
        if ($par2Info->error) {
            return [];
        }

        $files = $par2Info->getFileList();
        $packets = $par2Info->getPackets();

        if (! is_array($packets)) {
            return $files;
        }

        foreach ($packets as $packet) {
            if ($packet['head_type'] !== Par2Info::PACKET_FILEDESC) {
                continue;
            }

            $fileId = $packet['file_id'];
            if (isset($files[$fileId]['name'])) {
                continue;
            }

            $files[$fileId] = [
                'name' => ! empty($packet['file_name']) ? $packet['file_name'] : 'Unknown',
                'size' => $packet['file_length'] ?? 0,
                'hash' => $packet['file_hash'],
                'hash_16K' => $packet['file_hash_16K'],
                'blocks' => $files[$fileId]['blocks'] ?? 0,
                'next_offset' => $packet['next_offset'],
            ];
        }

        return $files;
    }
}
