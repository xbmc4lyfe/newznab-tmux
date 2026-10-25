<?php

declare(strict_types=1);

namespace App\Support;

use FFI;
use FFI\CData;
use Throwable;

/**
 * Normalizes release rows into search index document fields (Manticore + Elasticsearch).
 */
final class ReleaseSearchIndexDocument
{
    private const string FFI_CDEF = <<<'C'
        int64_t nntmux_predb_validate_filename_candidate(const char *id_str, size_t id_len, int64_t id_int, uint32_t has_id_int, const char *title, size_t title_len, const char *filename, size_t filename_len, const char *source, size_t source_len);
        ssize_t nntmux_prometheus_format_text(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        ssize_t nntmux_release_plain_search_name(const char *input, size_t input_len, char *out_buf, size_t out_cap);
        int64_t nntmux_datetime_to_unix_utc(const char *input, size_t input_len);
        C;

    public const string W445_FFI_CDEF = 'int64_t nntmux_w445_log_index_parser_search_prometheus_doc(uint32_t mode, const char *input, size_t input_len, int64_t *out, size_t cap);';

    /**
     * The Wave 44.5 summary defaults to Rust: every common and uncommon mean and p99 ratio cleared 1.5x;
     * see tests/Unit/RustLogViewerIndexParserSearchPrometheusDocW44ParityTest.php.
     */
    public const bool W445_RUST_FFI_DEFAULT = true;

    private const string PLAIN_SEPARATORS = '/[._-]+/';

    private const int W445_NATIVE_MAX_PARTS = 1 << 22;

    private const int W445_NATIVE_MAX_BYTES = 67108864;

    private const int W445_OUT_CAP = 32;

    private static FFI|false|null $ffi = null;

    private static bool $useNative = true;

    private static ?bool $useW445RustFfi = null;

    private static FFI|false|null $w445Ffi = null;

    private static ?CData $w445Out = null;

    /**
     * Fields that may be returned to browse/search consumers.
     * Sensitive release data must never be added to this list.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'id', 'guid', 'name', 'searchname', 'plainsearchname', 'fromname', 'filename',
            'categories_id', 'category_name', 'parent_category', 'sub_category',
            'groups_id', 'group_name', 'imdbid', 'tmdbid', 'traktid', 'tvdb', 'tvmaze',
            'tvrage', 'trakt', 'imdb', 'tmdb', 'videos_id', 'tv_episodes_id', 'movieinfo_id', 'anidbid',
            'parentid', 'episode_title', 'series', 'episode', 'firstaired_ts',
            'size', 'postdate_ts', 'adddate_ts', 'totalpart', 'grabs', 'comments',
            'passwordstatus', 'nzbstatus', 'nfostatus', 'haspreview', 'jpgstatus',
            'nfoid', 'reid',
            'media_movie_name', 'media_file_name', 'media_unique_id',
            'media_container_format', 'media_video_format', 'media_video_codec',
            'media_video_width', 'media_video_height', 'media_audio_format',
            'media_audio_channels', 'media_audio_language', 'media_subtitle_language',
            'has_media_info',
        ];
    }

    /**
     * Normalize a release row for bulk indexing. Rows already produced by {@see normalize()}
     * (e.g. from release search populate) lack `postdate` / `adddate` keys;
     * calling {@see normalize()} again would zero `postdate_ts` / `adddate_ts` unless those sources are restored.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function normalizeForBulk(array $row): array
    {
        if (array_key_exists('postdate_ts', $row) && ! array_key_exists('postdate', $row)) {
            $row = array_merge($row, [
                'postdate' => $row['postdate_ts'] ?? null,
                'adddate' => $row['adddate_ts'] ?? null,
            ]);
        }

        return self::normalize($row);
    }

    /**
     * @param  array<string, mixed>  $row  Keys from DB or insert parameters
     * @return array<string, mixed>
     */
    public static function normalize(array $row): array
    {
        $postdateTs = self::datetimeToUnix($row['postdate'] ?? null);
        $adddateTs = self::datetimeToUnix($row['adddate'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'guid' => (string) ($row['guid'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'searchname' => (string) ($row['searchname'] ?? ''),
            'plainsearchname' => (string) ($row['plainsearchname'] ?? self::plainSearchName($row['searchname'] ?? '')),
            'fromname' => (string) ($row['fromname'] ?? ''),
            'categories_id' => (int) ($row['categories_id'] ?? 0),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'parent_category' => (string) ($row['parent_category'] ?? ''),
            'sub_category' => (string) ($row['sub_category'] ?? ''),
            'group_name' => (string) ($row['group_name'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'imdbid' => (string) ($row['imdbid'] ?? ''),
            'tmdbid' => (int) ($row['tmdbid'] ?? 0),
            'traktid' => (int) ($row['traktid'] ?? 0),
            'tvdb' => (int) ($row['tvdb'] ?? 0),
            'tvmaze' => (int) ($row['tvmaze'] ?? 0),
            'tvrage' => (int) ($row['tvrage'] ?? 0),
            'trakt' => (int) ($row['trakt'] ?? 0),
            'imdb' => (string) ($row['imdb'] ?? ''),
            'tmdb' => (int) ($row['tmdb'] ?? 0),
            'videos_id' => (int) ($row['videos_id'] ?? 0),
            'tv_episodes_id' => (int) ($row['tv_episodes_id'] ?? 0),
            'movieinfo_id' => (int) ($row['movieinfo_id'] ?? 0),
            'anidbid' => (int) ($row['anidbid'] ?? 0),
            'parentid' => (int) ($row['parentid'] ?? 0),
            'episode_title' => (string) ($row['episode_title'] ?? ''),
            'series' => (int) ($row['series'] ?? 0),
            'episode' => (int) ($row['episode'] ?? 0),
            'firstaired_ts' => self::datetimeToUnix($row['firstaired'] ?? ($row['firstaired_ts'] ?? null)),
            'size' => (int) ($row['size'] ?? 0),
            'postdate_ts' => $postdateTs,
            'adddate_ts' => $adddateTs,
            'totalpart' => (int) ($row['totalpart'] ?? 0),
            'grabs' => (int) ($row['grabs'] ?? 0),
            'comments' => (int) ($row['comments'] ?? 0),
            'passwordstatus' => (int) ($row['passwordstatus'] ?? 0),
            'groups_id' => (int) ($row['groups_id'] ?? 0),
            'nzbstatus' => (int) ($row['nzbstatus'] ?? 0),
            'nfostatus' => (int) ($row['nfostatus'] ?? 0),
            'haspreview' => (int) ($row['haspreview'] ?? 0),
            'jpgstatus' => (int) ($row['jpgstatus'] ?? 0),
            'nfoid' => (int) ($row['nfoid'] ?? 0),
            'reid' => (int) ($row['reid'] ?? 0),
            'media_movie_name' => (string) ($row['media_movie_name'] ?? ''),
            'media_file_name' => (string) ($row['media_file_name'] ?? ''),
            'media_unique_id' => (string) ($row['media_unique_id'] ?? ''),
            'media_container_format' => (string) ($row['media_container_format'] ?? ''),
            'media_video_format' => (string) ($row['media_video_format'] ?? ''),
            'media_video_codec' => (string) ($row['media_video_codec'] ?? ''),
            'media_video_width' => (int) ($row['media_video_width'] ?? 0),
            'media_video_height' => (int) ($row['media_video_height'] ?? 0),
            'media_audio_format' => (string) ($row['media_audio_format'] ?? ''),
            'media_audio_channels' => (string) ($row['media_audio_channels'] ?? ''),
            'media_audio_language' => (string) ($row['media_audio_language'] ?? ''),
            'media_subtitle_language' => (string) ($row['media_subtitle_language'] ?? ''),
            'has_media_info' => (int) ($row['has_media_info'] ?? 0),
        ];
    }

    /**
     * Convert an indexed document into the date/attribute names expected by the views.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function toReleaseRow(array $document): array
    {
        $row = self::normalizeForBulk($document);
        $row['postdate'] = self::unixToDatetime((int) $row['postdate_ts']);
        $row['adddate'] = self::unixToDatetime((int) $row['adddate_ts']);

        return $row;
    }

    private static function datetimeToUnix(mixed $value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $str = (string) $value;
        $len = strlen($str);
        if (self::$useNative && ($len === 19 || $len === 10 || $len === 20) && date_default_timezone_get() === 'UTC') {
            $ffi = self::$ffi ??= (RustPipelineLibrary::resolveDomain('search_doc_metrics', self::FFI_CDEF) ?? false);
            if ($ffi !== false) {
                try {
                    // @phpstan-ignore method.notFound
                    $ts = $ffi->nntmux_datetime_to_unix_utc($str, $len);
                    if (is_int($ts) && $ts !== PHP_INT_MIN) {
                        return $ts;
                    }
                } catch (Throwable) {
                }
            }
        }

        return self::datetimeToUnixPhp($value);
    }

    private static function datetimeToUnixPhp(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);

        return $ts !== false ? $ts : 0;
    }

    /**
     * Retains native PHP `preg_replace` under the < 1.5x FFI bailout rule (`1.04x` with per-call
     * `FFI::string` allocation overhead); `nntmux_release_plain_search_name` remains exported in
     * `rust/pipeline-ffi/src/search.rs` for C-ABI parity.
     */
    private static function plainSearchName(mixed $value): string
    {
        return trim((string) preg_replace(self::PLAIN_SEPARATORS, ' ', (string) $value));
    }

    /** Pass null to restore {@see W445_RUST_FFI_DEFAULT}. */
    public static function useW445RustFfi(?bool $enable = true): void
    {
        self::$useW445RustFfi = $enable;
    }

    public static function usesW445RustFfi(): bool
    {
        return self::$useW445RustFfi ?? self::W445_RUST_FFI_DEFAULT;
    }

    public static function resetW445RustFfi(): void
    {
        self::$useW445RustFfi = null;
    }

    /**
     * Summarizes the `plainsearchname` values {@see normalize()} would index. Rows with a non-null
     * `plainsearchname` count as `explicit_plain`; the others derive it from `searchname` (cast to
     * string) and count `changed` / `empty` results, `separator_runs` replaced, `plain_bytes`,
     * `max_plain_bytes` and `distinct_plain`. Non-array rows and non-scalar searchnames are invalid.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, explicit_plain: int, derived: int, changed: int, empty: int, separator_runs: int, plain_bytes: int, max_plain_bytes: int, distinct_plain: int}
     */
    public static function summarizePlainSearchNameBatch(array $rows): array
    {
        return self::usesW445RustFfi() ? self::summarizePlainSearchNameBatchNative($rows) : self::summarizePlainSearchNameBatchPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, explicit_plain: int, derived: int, changed: int, empty: int, separator_runs: int, plain_bytes: int, max_plain_bytes: int, distinct_plain: int}
     */
    public static function summarizePlainSearchNameBatchPhp(array $rows): array
    {
        $summary = ['rows' => \count($rows), 'invalid' => 0, 'explicit_plain' => 0, 'derived' => 0, 'changed' => 0, 'empty' => 0, 'separator_runs' => 0, 'plain_bytes' => 0, 'max_plain_bytes' => 0, 'distinct_plain' => 0];
        $distinct = [];
        foreach (self::plainSearchNameSources($rows, $summary) as $source) {
            $plain = trim((string) preg_replace(self::PLAIN_SEPARATORS, ' ', $source, -1, $runs));
            $length = \strlen($plain);
            $summary['changed'] += (int) ($plain !== $source);
            $summary['empty'] += (int) ($plain === '');
            $summary['separator_runs'] += $runs;
            $summary['plain_bytes'] += $length;
            $summary['max_plain_bytes'] = max($summary['max_plain_bytes'], $length);
            $distinct[$plain] = true;
        }
        $summary['distinct_plain'] = \count($distinct);

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{rows: int, invalid: int, explicit_plain: int, derived: int, changed: int, empty: int, separator_runs: int, plain_bytes: int, max_plain_bytes: int, distinct_plain: int}
     */
    public static function summarizePlainSearchNameBatchNative(array $rows): array
    {
        $summary = ['rows' => \count($rows), 'invalid' => 0, 'explicit_plain' => 0, 'derived' => 0, 'changed' => 0, 'empty' => 0, 'separator_runs' => 0, 'plain_bytes' => 0, 'max_plain_bytes' => 0, 'distinct_plain' => 0];
        $fields = self::evaluateW445Native(5, self::plainSearchNameSources($rows, $summary), 6);
        if ($fields === null) {
            return self::summarizePlainSearchNameBatchPhp($rows);
        }
        [$summary['changed'], $summary['empty'], $summary['separator_runs'], $summary['plain_bytes'], $summary['max_plain_bytes'], $summary['distinct_plain']] = $fields;

        return $summary;
    }

    /**
     * The searchname strings to derive `plainsearchname` from; tallies rows, explicit values and invalid input.
     *
     * @param  array<array-key, mixed>  $rows
     * @param  array{rows: int, invalid: int, explicit_plain: int, derived: int, changed: int, empty: int, separator_runs: int, plain_bytes: int, max_plain_bytes: int, distinct_plain: int}  $summary
     * @return list<string>
     */
    private static function plainSearchNameSources(array $rows, array &$summary): array
    {
        $sources = [];
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                $summary['invalid']++;

                continue;
            }
            if (($row['plainsearchname'] ?? null) !== null) {
                $summary['explicit_plain']++;

                continue;
            }
            $value = $row['searchname'] ?? '';
            if (! \is_scalar($value)) {
                $summary['invalid']++;

                continue;
            }
            $sources[] = (string) $value;
        }
        $summary['derived'] = \count($sources);

        return $sources;
    }

    /**
     * Shared Wave 44.5 bridge for the log viewer, Prometheus and search document summaries: returns
     * exactly $fields counters, or null when the library is unavailable or declined the batch so
     * callers fall back to PHP.
     *
     * @param  list<string>  $parts
     * @return list<int>|null
     */
    public static function evaluateW445Native(int $mode, array $parts, int $fields): ?array
    {
        $ffi = self::w445Ffi();
        $count = \count($parts);
        if ($ffi === null || $mode < 1 || $fields < 1 || $fields > self::W445_OUT_CAP || $count > self::W445_NATIVE_MAX_PARTS) {
            return null;
        }

        try {
            $input = $count === 0 ? pack('V', 0) : pack('V*', $count, ...array_map('strlen', $parts)).implode('', $parts);
            $length = \strlen($input);
            if ($length > self::W445_NATIVE_MAX_BYTES) {
                return null;
            }
            $out = self::$w445Out ??= $ffi->new('int64_t['.self::W445_OUT_CAP.']');
            // @phpstan-ignore method.notFound
            $written = $ffi->nntmux_w445_log_index_parser_search_prometheus_doc($mode, $input, $length, $out, self::W445_OUT_CAP);
            if ($written !== $fields) {
                return null;
            }
            $values = unpack('q*', FFI::string($out, $fields * 8));
        } catch (Throwable) {
            return null;
        }

        /** @var list<int>|null */
        return \is_array($values) ? array_values($values) : null;
    }

    private static function w445Ffi(): ?FFI
    {
        if (self::$w445Ffi === null) {
            $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
            self::$w445Ffi = RustPipelineLibrary::resolveDomain('search_w445', self::W445_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
            self::$w445Out = null;
        }

        return self::$w445Ffi instanceof FFI ? self::$w445Ffi : null;
    }

    private static function unixToDatetime(int $timestamp): ?string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
