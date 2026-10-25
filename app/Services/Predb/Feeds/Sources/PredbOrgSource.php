<?php

declare(strict_types=1);

namespace App\Services\Predb\Feeds\Sources;

use App\Models\Predb;
use App\Services\Predb\Feeds\PredbFeedEntry;
use App\Support\RustPipelineLibrary;
use Carbon\CarbonImmutable;
use FFI;
use RuntimeException;

/**
 * predb.org JSON API (anonymous, full history, newest first).
 *
 * Row shape: {id, rlsname, section, grp, ctime (unix), size (MB), files, status, nukereason, genre}
 *
 * The site's newest entries can trail the scene by a day, so this source is opt-in: use it with
 * `predb:import-feed --source=predb_org --since=...` to fill in history, not as a live feed.
 */
final class PredbOrgSource extends HttpFeedSource
{
    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.25x, p99 1.71x; uncommon mean 2.48x, p99 1.94x; see the W45 parity test). */
    public const bool W454_RUST_FFI_DEFAULT = true;

    public const string W454_FFI_CDEF = PredbClubSource::W454_FFI_CDEF;

    private static ?bool $w454UseRustFfi = null;

    private static FFI|false|null $w454Ffi = null;

    /** predb.org caps `limit` at 100. */
    private const int MAX_PAGE_SIZE = 100;

    public function key(): string
    {
        return 'predb_org';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            // `q` is required; `*` lists every release, newest first.
            'q' => '*',
            'limit' => min($this->pageSize, self::MAX_PAGE_SIZE),
            'page' => max(1, $page),
        ])->throw();

        $native = PredatabaseSource::nativeJsonEntries($response->body(), 8, 'predb.org');
        if ($native !== null) {
            return $native[1];
        }

        $rows = $response->json('values');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predb.org returned an unexpected response (no values).');
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
            if (! is_array($row) || ! is_string($row['rlsname'] ?? null) || trim($row['rlsname']) === '') {
                continue;
            }

            $reason = is_string($row['nukereason'] ?? null) && $row['nukereason'] !== '' ? $row['nukereason'] : null;
            // `status` 1 is a nuke (the reason can be empty); 0 is a normal release.
            $nuked = (string) ($row['status'] ?? '0') === '1' || $reason !== null;

            $entries[] = new PredbFeedEntry(
                title: trim($row['rlsname']),
                source: 'predb.org',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['ctime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['ctime']) : null,
                nuked: $nuked ? Predb::PRE_NUKED : Predb::PRE_NONUKE,
                nukeReason: $reason,
            );
        }

        return $entries;
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
     * rows whose `rlsname` field is a string or integer; anything else counts as empty.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbOrgTitleBatch(array $rows): array
    {
        return self::usesW454RustFfi()
            ? self::summarizePredbOrgTitleBatchNative($rows)
            : self::summarizePredbOrgTitleBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbOrgTitleBatchNative(array $rows): array
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
    public static function summarizePredbOrgTitleBatchPhp(array $rows): array
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
                $row = $row['rlsname'] ?? null;
            }
            $entries[] = is_string($row) || is_int($row) ? (string) $row : '';
        }

        return $entries;
    }
}
