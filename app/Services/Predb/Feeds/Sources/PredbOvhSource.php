<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\FeedRateLimitedException;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use RuntimeException;

/**
 * predb.ovh JSON API (v1).
 *
 * Written from the published v1 documentation (https://github.com/predbdotovh/pre-api/blob/master/docs/index.md):
 * base URL https://predb.ovh/api/v1/, parameters `count` and `page`, rows
 * {id, name, team, cat, genre, url, size (kB), files, preAt (unix), nuke: {typeId, type, reason, ...}|null}.
 * Nuke type ids: 1 nuke, 2 unnuke, 3 modnuke, 4 delpre, 5 undelpre. The service has not been reachable from our
 * network when this was written, so it is not verified against live responses. Opt-in only: add `predb_ovh`
 * to PREDB_FEED_SOURCES once the service is confirmed.
 */
final class PredbOvhSource extends HttpFeedSource
{
    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.45x, p99 1.75x; uncommon mean 2.47x, p99 2.01x; see the W45 parity test). */
    public const bool W454_RUST_FFI_DEFAULT = true;

    public const string W454_FFI_CDEF = PredbClubSource::W454_FFI_CDEF;

    private static ?bool $w454UseRustFfi = null;

    private static FFI|false|null $w454Ffi = null;

    public function key(): string
    {
        return 'predb_ovh';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'count' => $this->pageSize,
            'page' => max(1, $page),
        ])->throw();

        $native = PredatabaseSource::nativeJsonEntries($response->body(), 5, 'predb.ovh');
        if ($native !== null) {
            return $native[1];
        }

        if ($response->json('status') === 'error') {
            $message = (string) $response->json('message', 'unknown');

            throw str_contains(strtolower($message), 'rate limit')
                ? new FeedRateLimitedException('predb.ovh rate limited: '.$message)
                : new RuntimeException('predb.ovh API error: '.$message);
        }

        $rows = $response->json('data.rows');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predb.ovh returned an unexpected response (no data.rows).');
        }

        return $this->parseRows($rows);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<PredbFeedEntry>
     */
    public function parseRows(array $rows): array
    {
        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || trim($row['name']) === '') {
                continue;
            }

            $nuke = is_array($row['nuke'] ?? null) ? $row['nuke'] : null;
            // Explicit, valid PRE identifiers must refer to the same row.
            $rowId = self::preId($row['id'] ?? null);
            $nukePreId = self::preId($nuke['preId'] ?? null);
            if ($rowId !== null && $nukePreId !== null && $rowId !== $nukePreId) {
                $nuke = null;
            }
            $typeId = $nuke !== null ? (int) ($nuke['typeId'] ?? 0) : 0;
            $reason = is_string($nuke['reason'] ?? null) && $nuke['reason'] !== '' ? $nuke['reason'] : null;

            $entries[] = new PredbFeedEntry(
                title: trim($row['name']),
                source: 'predb.ovh',
                category: is_string($row['cat'] ?? null) && $row['cat'] !== '' ? $row['cat'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] / 1024 : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['preAt'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['preAt']) : null,
                nuked: match ($typeId) {
                    0 => Predb::PRE_NONUKE,
                    1 => Predb::PRE_NUKED,
                    2, 5 => Predb::PRE_UNNUKED,
                    3 => Predb::PRE_MODNUKE,
                    default => Predb::PRE_NUKED,
                },
                nukeReason: $typeId === 0 ? null : $reason,
            );
        }

        return $entries;
    }

    /** Canonical positive uint64 ID without narrowing through PHP integers or floats. */
    private static function preId(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }
        if (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }
        $id = ltrim($value, '0');
        if ($id === '' || strlen($id) > 20 || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)) {
            return null;
        }

        return $id;
    }

    /** Null restores the benchmark-qualified default. */
    public static function useW454RustFfi(?bool $enable = true): void
    {
        self::$w454UseRustFfi = $enable;
    }

    public static function usesW454RustFfi(): bool
    {
        return self::$w454UseRustFfi ?? self::W454_RUST_FFI_DEFAULT;
    }

    public static function resetW454RustFfi(): void
    {
        self::$w454UseRustFfi = null;
        self::$w454Ffi = null;
    }

    /**
     * Summarize release titles as parseRows() keeps them (see
     * PredbClubSource::w454SummarizeTitles()). Entries are strings, integers, or
     * rows whose `name` field is a string or integer; anything else counts as empty.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbOvhTitleBatch(array $rows): array
    {
        return self::usesW454RustFfi()
            ? self::summarizePredbOvhTitleBatchNative($rows)
            : self::summarizePredbOvhTitleBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbOvhTitleBatchNative(array $rows): array
    {
        $entries = self::w454Entries($rows);
        $input = serialize([$entries]);
        if (strlen($input) <= 16777216) {
            self::$w454Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w454', self::W454_FFI_CDEF) ?? false;
            $native = self::$w454Ffi === false ? null : PredbClubSource::w454Invoke(self::$w454Ffi, $input, count($entries));
            if ($native !== null) {
                return $native;
            }
        }

        return PredbClubSource::w454SummarizeTitles($entries);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbOvhTitleBatchPhp(array $rows): array
    {
        return PredbClubSource::w454SummarizeTitles(self::w454Entries($rows));
    }

    /** Only integer and string titles participate; objects are never cast or serialized.
     *
     * @param  array<array-key, mixed>  $rows
     * @return list<string>
     */
    private static function w454Entries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $row = $row['name'] ?? null;
            }
            $entries[] = is_string($row) || is_int($row) ? (string) $row : '';
        }

        return $entries;
    }
}
