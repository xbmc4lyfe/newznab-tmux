<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\DTO;

use App\Services\Par2FileList;

/**
 * A file as the release's PAR2 index describes it.
 */
final readonly class Par2FileEntry
{
    /**
     * @param  string  $hash16k  MD5 of the file's first 16 KiB, lower-case hex
     */
    public function __construct(
        public string $name,
        public int $size,
        public string $hash16k = '',
    ) {}

    /**
     * @param  array<int|string, mixed>  $files  Par2Info::getFileList()
     * @return list<self>
     */
    public static function listFromPar2Info(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (! is_array($file) || ! is_string($file['name'] ?? null) || (int) ($file['size'] ?? 0) <= 0) {
                continue;
            }

            $entries[] = new self(
                name: $file['name'],
                size: (int) $file['size'],
                hash16k: strtolower((string) ($file['hash_16K'] ?? '')),
            );
        }

        return $entries;
    }

    /**
     * Entries of a raw PAR2 index, empty when Par2Info cannot parse it.
     *
     * Parses through Par2FileList::extractFilesNative(): 7.86x mean / 9.58x p99 on typical
     * index files and 10.55x / 12.48x on large sets in paired benchmarks.
     *
     * @return list<self>
     */
    public static function listFromPar2Data(string $data): array
    {
        return self::listFromPar2DataNative($data);
    }

    /**
     * @return list<self>
     */
    public static function listFromPar2DataPhp(string $data): array
    {
        $parsed = Par2FileList::extractFilesPhp($data);

        return $parsed === null ? [] : self::listFromPar2Info($parsed['files']);
    }

    /**
     * @return list<self>
     */
    public static function listFromPar2DataNative(string $data, bool &$supported = false): array
    {
        $parsed = Par2FileList::extractFilesNative($data, supported: $supported);

        return $parsed === null ? [] : self::listFromPar2Info($parsed['files']);
    }

    /**
     * Whether $data is the start of this file.
     */
    public function matchesStartOf(string $data): bool
    {
        $length = min(16384, $this->size);

        return $this->hash16k !== '' && strlen($data) >= $length && md5(substr($data, 0, $length)) === $this->hash16k;
    }
}
