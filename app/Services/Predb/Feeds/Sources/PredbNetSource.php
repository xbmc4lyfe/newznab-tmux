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
 * api.predb.net JSON API.
 *
 * Row shape: {release, section, size (MB), files, pretime (unix), status (0 ok, 1 nuke, 2 unnuke, 3 delpre, 4 undelpre), reason, group}
 */
final class PredbNetSource extends HttpFeedSource
{
    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.52x, p99 1.74x; uncommon mean 2.46x, p99 1.98x; see the W45 parity test). */
    public const bool W454_RUST_FFI_DEFAULT = true;

    public const string W454_FFI_CDEF = PredbClubSource::W454_FFI_CDEF;

    private static ?bool $w454UseRustFfi = null;

    private static FFI|false|null $w454Ffi = null;

    /**
     * The API rejects later pages (HTTP 400), so only the newest 100 pages are reachable.
     */
    public const MAX_PAGE = 100;

    public function key(): string
    {
        return 'predb_net';
    }

    public function fetch(int $page = 1): array
    {
        if ($page > self::MAX_PAGE) {
            return [];
        }

        $response = $this->http()->get($this->endpoint, [
            'limit' => $this->pageSize,
            'page' => max(1, $page),
        ])->throw();

        $native = PredatabaseSource::nativeJsonEntries($response->body(), 7, 'predb.net');
        if ($native !== null) {
            return $native[1];
        }

        if ($response->json('status') === 'error') {
            $message = (string) $response->json('message', 'unknown');

            throw str_contains(strtolower($message), 'rate limit')
                ? new FeedRateLimitedException('predb.net rate limited: '.$message)
                : new RuntimeException('predb.net API error: '.$message);
        }

        $rows = $response->json('data');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predb.net returned an unexpected response (no data).');
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
            if (! is_array($row) || ! is_string($row['release'] ?? null) || trim($row['release']) === '') {
                continue;
            }

            $status = (int) ($row['status'] ?? 0);
            $reason = is_string($row['reason'] ?? null) && $row['reason'] !== '' ? $row['reason'] : null;

            $entries[] = new PredbFeedEntry(
                title: trim($row['release']),
                source: 'predb.net',
                category: is_string($row['section'] ?? null) && $row['section'] !== '' ? $row['section'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['pretime'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['pretime']) : null,
                nuked: match ($status) {
                    0 => Predb::PRE_NONUKE,
                    2, 4 => Predb::PRE_UNNUKED, // unnuke, undelpre
                    default => Predb::PRE_NUKED,
                },
                nukeReason: $status === 0 ? null : $reason,
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
     * rows whose `release` field is a string or integer; anything else counts as empty.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbNetTitleBatch(array $rows): array
    {
        return self::usesW454RustFfi()
            ? self::summarizePredbNetTitleBatchNative($rows)
            : self::summarizePredbNetTitleBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbNetTitleBatchNative(array $rows): array
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
    public static function summarizePredbNetTitleBatchPhp(array $rows): array
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
                $row = $row['release'] ?? null;
            }
            $entries[] = is_string($row) || is_int($row) ? (string) $row : '';
        }

        return $entries;
    }
}
