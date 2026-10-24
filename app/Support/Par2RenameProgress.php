<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Par2RenameProgress
{
    public const string FFI_CDEF = <<<'C'
        ssize_t nntmux_par2_progress_normalize_retry(const char *json, size_t len, size_t max_items, char *out, size_t cap);
        ssize_t nntmux_par2_progress_encode_retry(const char *packed, size_t len, char *out, size_t cap);
        C;

    // PHP remains the production default: FFI marshaling stays below the 1.5x gate.
    private static bool $useRustFfi = false;

    private static FFI|false|null $ffi = null;

    public static function setUseRustFfi(bool $enabled): void
    {
        self::$useRustFfi = $enabled;
    }

    public static function usesRustFfi(): bool
    {
        return self::$useRustFfi;
    }

    private static function ffi(): ?FFI
    {
        if (self::$ffi === null) {
            self::$ffi = RustPipelineLibrary::resolveDomain('additional', self::FFI_CDEF) ?? false;
        }

        return self::$ffi === false ? null : self::$ffi;
    }

    /**
     * Stored retry payload as the integer indexes it lists; anything else is dropped.
     *
     * @return list<int>
     *
     * @throws \JsonException when the payload is not valid JSON
     */
    public static function normalizeRetryIndexes(string $json): array
    {
        return (self::$useRustFfi ? self::normalizeRetryIndexesNative($json) : null) ?? self::normalizeRetryIndexesPhp($json);
    }

    /**
     * @return list<int>
     */
    public static function normalizeRetryIndexesPhp(string $json): array
    {
        $retry = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($retry) ? array_values(array_filter($retry, is_int(...))) : [];
    }

    /**
     * @return list<int>|null Null when PHP must decode the payload (strings, floats, nesting or errors).
     */
    public static function normalizeRetryIndexesNative(string $json): ?array
    {
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        // A JSON integer takes at least two bytes with its separator, so this bounds the output.
        $maxItems = intdiv(\strlen($json), 2) + 1;
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, max(16, $maxItems * 8), $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_par2_progress_normalize_retry($json, \strlen($json), $maxItems, $scratch, $cap);
            if ($written < 0 || $written % 8 !== 0) {
                return null;
            }
            if ($written === 0) {
                return [];
            }
            $values = unpack('q*', FFI::string($scratch, $written));
        } catch (Throwable) {
            return null;
        }

        return $values === false ? null : array_values($values);
    }

    /**
     * @param  list<int>  $retryIndexes
     */
    public static function encodeRetryIndexes(array $retryIndexes): string
    {
        return (self::$useRustFfi ? self::encodeRetryIndexesNative($retryIndexes) : null) ?? self::encodeRetryIndexesPhp($retryIndexes);
    }

    /**
     * @param  list<int>  $retryIndexes
     */
    public static function encodeRetryIndexesPhp(array $retryIndexes): string
    {
        return json_encode($retryIndexes, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<mixed>  $retryIndexes
     */
    public static function encodeRetryIndexesNative(array $retryIndexes): ?string
    {
        if (! array_is_list($retryIndexes)) {
            return null;
        }
        foreach ($retryIndexes as $index) {
            if (! \is_int($index)) {
                return null;
            }
        }
        if ($retryIndexes === []) {
            return '[]';
        }
        $ffi = self::ffi();
        if ($ffi === null) {
            return null;
        }
        $packed = pack('q*', ...$retryIndexes);
        try {
            $cap = 0;
            // Each value needs at most 20 digits plus its separator.
            $scratch = RustPipelineLibrary::obtainScratch($ffi, \count($retryIndexes) * 21 + 2, $cap);
            if ($scratch === null) {
                return null;
            }
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_par2_progress_encode_retry($packed, \strlen($packed), $scratch, $cap);

            return $written > 0 ? FFI::string($scratch, $written) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{signature: string, offset: int, retry_indexes: list<int>}|null */
    public static function get(int $releaseId): ?array
    {
        $row = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first();
        if ($row === null) {
            return null;
        }
        $retry = self::normalizeRetryIndexes((string) $row->retry_indexes);

        return ['signature' => (string) $row->signature, 'offset' => (int) $row->offset,
            'retry_indexes' => $retry];
    }

    /** @param list<int> $retryIndexes */
    public static function save(int $releaseId, string $signature, int $offset, array $retryIndexes): bool
    {
        return DB::transaction(function () use ($releaseId, $signature, $offset, $retryIndexes): bool {
            $release = DB::table('releases')->where('id', $releaseId)->lockForUpdate()
                ->first(['isrenamed', 'proc_par2', 'predb_id']);
            if ($release === null || (int) $release->isrenamed === 1 || (int) $release->proc_par2 === 1 || (int) $release->predb_id > 0) {
                DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();

                return false;
            }
            $previous = DB::table('par2_rename_progress')->where('releases_id', $releaseId)->first(['signature']);
            if ($previous !== null && $previous->signature !== $signature) {
                DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();
            }
            DB::table('par2_rename_progress')->updateOrInsert(['releases_id' => $releaseId],
                ['signature' => $signature, 'offset' => $offset, 'retry_indexes' => self::encodeRetryIndexes($retryIndexes), 'updated_at' => now()]);

            return true;
        });
    }

    /**
     * Clear completed work only while the committed identity is still terminal. A reset or
     * replacement release may already have new candidate buffers by the time callbacks run.
     *
     * @param  array{id:int,guid:string,name:?string,searchname:?string}  $snapshot
     */
    public static function forgetCompletedSnapshot(array $snapshot): bool
    {
        return DB::transaction(function () use ($snapshot): bool {
            $release = DB::table('releases')->where('id', $snapshot['id'])->lockForUpdate()
                ->first();
            if ($release === null || ! isset($release->id, $release->guid)
                || ! property_exists($release, 'name') || ! property_exists($release, 'searchname')
                || (int) $release->id !== $snapshot['id']
                || $release->guid !== $snapshot['guid'] || $release->name !== $snapshot['name']
                || $release->searchname !== $snapshot['searchname']
                || ((int) ($release->predb_id ?? 0) <= 0 && (int) ($release->isrenamed ?? 0) !== 1 && (int) ($release->proc_par2 ?? 0) !== 1)) {
                return false;
            }
            DB::table('par2_rename_progress')->where('releases_id', $snapshot['id'])->delete();

            return true;
        });
    }

    public static function forget(int $releaseId): void
    {
        try {
            DB::table('par2_rename_progress')->where('releases_id', $releaseId)->delete();
        } catch (Throwable $e) {
            Log::warning('Could not clear completed PAR2 probe progress', ['release_id' => $releaseId, 'error' => $e->getMessage()]);
        }
    }
}
