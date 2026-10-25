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
use Throwable;

/**
 * predb.club JSON API (predb.ovh-compatible v1 schema).
 *
 * Row shape: {id, name, team, cat, size (MB), files, preAt (unix), nuke: null|{type, reason, ...}}
 */
final class PredbClubSource extends HttpFeedSource
{
    /** predb.club caps `count` at 100. */
    private const int MAX_PAGE_SIZE = 100;

    /** Enabled: seven paired runs cleared 1.5x on every gate (min common mean 2.21x, p99 1.76x; uncommon mean 2.49x, p99 2.09x; see the W45 parity test). */
    public const bool W454_RUST_FFI_DEFAULT = true;

    public const string W454_FFI_CDEF = <<<'C'
        ssize_t nntmux_w454_predb_feed_title_batch(const char *input, size_t len, char *out, size_t cap);
        C;

    /** Summary keys shared by the predb.club, predb.net, predb.org and predb.ovh title batches. */
    public const array W454_SUMMARY_KEYS = ['total_titles', 'empty_title_count', 'padded_title_count', 'group_suffix_count', 'dotted_count', 'episode_count', 'resolution_count', 'scene_tag_count', 'non_ascii_count', 'control_char_count', 'overlong_count'];

    private static ?bool $w454UseRustFfi = null;

    private static FFI|false|null $w454Ffi = null;

    public function key(): string
    {
        return 'predb_club';
    }

    public function fetch(int $page = 1): array
    {
        $response = $this->http()->get($this->endpoint, [
            'count' => min($this->pageSize, self::MAX_PAGE_SIZE),
            'page' => max(1, $page),
        ])->throw();

        $native = PredatabaseSource::nativeJsonEntries($response->body(), 6, 'predb.club');
        if ($native !== null) {
            return $native[1];
        }

        if ($response->json('status') === 'error') {
            $message = (string) $response->json('message', 'unknown');

            throw str_contains(strtolower($message), 'rate limit')
                ? new FeedRateLimitedException('predb.club rate limited: '.$message)
                : new RuntimeException('predb.club API error: '.$message);
        }

        $rows = $response->json('data.rows');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('predb.club returned an unexpected response (no data.rows).');
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

            [$nuked, $reason] = $this->nukeStatus($row['nuke'] ?? null);

            $entries[] = new PredbFeedEntry(
                title: trim($row['name']),
                source: 'predb.club',
                category: is_string($row['cat'] ?? null) && $row['cat'] !== '' ? $row['cat'] : null,
                size: PredbFeedEntry::sizeFromMegabytes(is_numeric($row['size'] ?? null) ? (float) $row['size'] : null),
                files: PredbFeedEntry::filesFromCount(is_numeric($row['files'] ?? null) ? (int) $row['files'] : null),
                predate: is_numeric($row['preAt'] ?? null) ? CarbonImmutable::createFromTimestampUTC((int) $row['preAt']) : null,
                nuked: $nuked,
                nukeReason: $reason,
            );
        }

        return $entries;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function nukeStatus(mixed $nuke): array
    {
        if (! is_array($nuke)) {
            return [Predb::PRE_NONUKE, null];
        }

        $reason = is_string($nuke['reason'] ?? null) && $nuke['reason'] !== '' ? $nuke['reason'] : null;
        $status = match (strtolower((string) ($nuke['type'] ?? 'nuke'))) {
            'unnuke', 'undelpre' => Predb::PRE_UNNUKED,
            'modnuke' => Predb::PRE_MODNUKE,
            'renuke' => Predb::PRE_RENUKED,
            'oldnuke' => Predb::PRE_OLDNUKE,
            default => Predb::PRE_NUKED,
        };

        return [$status, $reason];
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
     * Summarize release titles as parseRows() keeps them. Entries are strings,
     * integers, or rows whose `name` field is a string or integer; anything else
     * counts as empty.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbClubTitleBatch(array $rows): array
    {
        return self::usesW454RustFfi()
            ? self::summarizePredbClubTitleBatchNative($rows)
            : self::summarizePredbClubTitleBatchPhp($rows);
    }

    /**
     * Rust summary; falls back to PHP when the library or input frame is unusable.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbClubTitleBatchNative(array $rows): array
    {
        $entries = self::w454Entries($rows);
        $input = serialize([$entries]);
        if (strlen($input) <= 16777216) {
            self::$w454Ffi ??= RustPipelineLibrary::resolveDomain('metadata_w454', self::W454_FFI_CDEF) ?? false;
            $native = self::$w454Ffi === false ? null : self::w454Invoke(self::$w454Ffi, $input, count($entries));
            if ($native !== null) {
                return $native;
            }
        }

        return self::w454SummarizeTitles($entries);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array<string, int>
     */
    public static function summarizePredbClubTitleBatchPhp(array $rows): array
    {
        return self::w454SummarizeTitles(self::w454Entries($rows));
    }

    /**
     * Shared PHP classifier for the predb JSON feed title batches: trim() empties
     * (rows parseRows() skips), padded titles, a `-group` suffix, whitespace-free
     * dotted names, SxxEyy markers, resolutions, scene tags, non-ASCII and
     * control bytes, and titles over 255 bytes. PCRE runs without /u, so \b, \s
     * and /i are ASCII-only.
     *
     * @internal Shared by the predb.net, predb.org and predb.ovh W454 helpers.
     *
     * @param  list<string>  $titles
     * @return array<string, int>
     */
    public static function w454SummarizeTitles(array $titles): array
    {
        $summary = array_fill_keys(self::W454_SUMMARY_KEYS, 0);
        foreach ($titles as $raw) {
            $summary['total_titles']++;
            $title = trim($raw);
            if ($title === '') {
                $summary['empty_title_count']++;

                continue;
            }
            $summary['padded_title_count'] += (int) ($title !== $raw);
            $summary['group_suffix_count'] += (int) (preg_match('/-\w+\z/', $title) === 1);
            $summary['dotted_count'] += (int) (strpbrk($title, " \t\n\v\f\r") === false && str_contains($title, '.'));
            $summary['episode_count'] += (int) (preg_match('/\bS\d{1,3}E\d{1,4}\b/i', $title) === 1);
            $summary['resolution_count'] += (int) (preg_match('/\b(?:480|576|720|1080|2160)[pi]\b/i', $title) === 1);
            $summary['scene_tag_count'] += (int) (preg_match('/\b(?:PROPER|REPACK|INTERNAL|READNFO|DIRFIX|NFOFIX|SUBFIX|RERIP|REAL)\b/i', $title) === 1);
            $summary['non_ascii_count'] += (int) (preg_match('/[\x80-\xFF]/', $title) === 1);
            $summary['control_char_count'] += (int) (preg_match('/[\x00-\x1F\x7F]/', $title) === 1);
            $summary['overlong_count'] += (int) (strlen($title) > 255);
        }

        return $summary;
    }

    /**
     * Runs the shared Rust title export on a serialized `[list<string>]` frame.
     *
     * @internal Shared by the predb.net, predb.org and predb.ovh W454 helpers.
     *
     * @return array<string, int>|null
     */
    public static function w454Invoke(FFI $ffi, string $input, int $count): ?array
    {
        try {
            $capacity = 0;
            $out = RustPipelineLibrary::obtainScratch($ffi, 2048, $capacity);
            if ($out === null) {
                return null;
            }
            // @phpstan-ignore method.notFound (Declared by W454_FFI_CDEF.)
            $written = $ffi->nntmux_w454_predb_feed_title_batch($input, strlen($input), $out, $capacity);
            if (is_int($written) && $written < -1 && $written >= -16777216) {
                $out = RustPipelineLibrary::obtainScratch($ffi, -$written, $capacity);
                if ($out === null) {
                    return null;
                }
                // @phpstan-ignore method.notFound (Declared by W454_FFI_CDEF.)
                $written = $ffi->nntmux_w454_predb_feed_title_batch($input, strlen($input), $out, $capacity);
            }
            if (! is_int($written) || $written <= 0 || $written > $capacity || $written > 16777216) {
                return null;
            }
            $result = @unserialize(FFI::string($out, $written), ['allowed_classes' => false]);
            if (! is_array($result) || array_keys($result) !== self::W454_SUMMARY_KEYS || $result['total_titles'] !== $count) {
                return null;
            }
            $summary = [];
            foreach (self::W454_SUMMARY_KEYS as $key) {
                $value = $result[$key];
                if (! is_int($value) || $value < 0 || $value > $count) {
                    return null;
                }
                $summary[$key] = $value;
            }

            return $summary;
        } catch (Throwable) {
            return null;
        }
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
