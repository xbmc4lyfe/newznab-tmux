<?php

declare(strict_types=1);

namespace App\Services\Search\Support;

use App\Services\Search\SearchService;
use App\Support\ReleaseSearchIndexDocument;
use App\Support\RustPipelineLibrary;
use FFI;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single database projection used to build complete release search documents.
 */
final class ReleaseIndexProjection
{
    public static function query(): Builder
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $mediaInfo = self::mediaInfoQuery();
        [$filename, $audioFormat, $audioChannels, $audioLanguage, $subtitleLanguage, $categoryName] = self::aggregateExpressions($isSqlite);

        return DB::table('releases as r')
            ->leftJoin('usenet_groups as g', 'g.id', '=', 'r.groups_id')
            ->leftJoin('categories as c', 'c.id', '=', 'r.categories_id')
            ->leftJoin('root_categories as cp', 'cp.id', '=', 'c.root_categories_id')
            ->leftJoin('movieinfo as mi', function ($join): void {
                $join->on('mi.id', '=', 'r.movieinfo_id')
                    ->where('r.movieinfo_id', '>', 0);
            })
            ->leftJoin('videos as v', function ($join): void {
                $join->on('v.id', '=', 'r.videos_id')
                    ->where('r.videos_id', '>', 0);
            })
            ->leftJoin('tv_episodes as tve', function ($join): void {
                $join->on('tve.id', '=', 'r.tv_episodes_id')
                    ->where('r.tv_episodes_id', '>', 0);
            })
            ->leftJoin('release_nfos as rn', 'rn.releases_id', '=', 'r.id')
            ->leftJoin('video_data as vd', 'vd.releases_id', '=', 'r.id')
            ->leftJoinSub($mediaInfo, 'mdi', 'mdi.releases_id', '=', 'r.id')
            ->select([
                'r.id', 'r.guid', 'r.name', 'r.searchname', 'r.fromname', 'r.categories_id',
                'r.groups_id', 'r.size', 'r.postdate', 'r.adddate', 'r.totalpart', 'r.grabs',
                'r.comments', 'r.passwordstatus', 'r.nzbstatus', 'r.nfostatus', 'r.haspreview',
                'r.jpgstatus', 'r.videos_id', 'r.tv_episodes_id', 'r.movieinfo_id', 'r.imdbid',
                'r.anidbid', 'g.name as group_name', 'c.root_categories_id as parentid',
                'c.title as sub_category', 'tve.title as episode_title', 'tve.series',
                'tve.episode', 'tve.firstaired',
                'cp.title as parent_category', DB::raw("{$categoryName} AS category_name"),
                DB::raw("{$filename} AS filename"),
                DB::raw('COALESCE(mi.tmdbid, 0) AS tmdbid'),
                DB::raw('COALESCE(mi.traktid, 0) AS traktid'),
                DB::raw('COALESCE(v.tvdb, 0) AS tvdb'),
                DB::raw('COALESCE(v.tvmaze, 0) AS tvmaze'),
                DB::raw('COALESCE(v.tvrage, 0) AS tvrage'),
                DB::raw('COALESCE(v.trakt, 0) AS trakt'),
                DB::raw("COALESCE(v.imdb, '') AS imdb"),
                DB::raw('COALESCE(v.tmdb, 0) AS tmdb'),
                DB::raw('COALESCE(rn.releases_id, 0) AS nfoid'),
                DB::raw('COALESCE(vd.releases_id, 0) AS reid'),
                DB::raw("COALESCE(mdi.movie_name, '') AS media_movie_name"),
                DB::raw("COALESCE(mdi.file_name, '') AS media_file_name"),
                DB::raw("COALESCE(mdi.unique_id, '') AS media_unique_id"),
                DB::raw("COALESCE(vd.containerformat, '') AS media_container_format"),
                DB::raw("COALESCE(vd.videoformat, '') AS media_video_format"),
                DB::raw("COALESCE(vd.videocodec, '') AS media_video_codec"),
                DB::raw('COALESCE(vd.videowidth, 0) AS media_video_width'),
                DB::raw('COALESCE(vd.videoheight, 0) AS media_video_height'),
                DB::raw("{$audioFormat} AS media_audio_format"),
                DB::raw("{$audioChannels} AS media_audio_channels"),
                DB::raw("{$audioLanguage} AS media_audio_language"),
                DB::raw("{$subtitleLanguage} AS media_subtitle_language"),
                DB::raw('CASE WHEN mdi.releases_id IS NOT NULL OR vd.releases_id IS NOT NULL OR EXISTS (SELECT 1 FROM audio_data ad WHERE ad.releases_id = r.id) OR EXISTS (SELECT 1 FROM release_subtitles rs WHERE rs.releases_id = r.id) THEN 1 ELSE 0 END AS has_media_info'),
            ]);
    }

    /**
     * Driver-specific aggregate SQL: filename, audio format, channels and language,
     * subtitle language and category name. Rust is opt-in (slower in the benchmark).
     *
     * @return list<string>
     */
    public static function aggregateExpressions(bool $isSqlite, bool $useRustFfi = false): array
    {
        return ($useRustFfi ? self::aggregateExpressionsNative($isSqlite) : null) ?? self::aggregateExpressionsPhp($isSqlite);
    }

    /** @return list<string> */
    public static function aggregateExpressionsPhp(bool $isSqlite): array
    {
        return $isSqlite
            ? [
                "COALESCE((SELECT GROUP_CONCAT(rf.name, ' ') FROM release_files rf WHERE rf.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audioformat, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audiochannels, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audiolanguage, ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(rs.subslanguage, ' ') FROM release_subtitles rs WHERE rs.releases_id = r.id), '')",
                "cp.title || ' > ' || c.title",
            ]
            : [
                "COALESCE((SELECT GROUP_CONCAT(rf.name SEPARATOR ' ') FROM release_files rf WHERE rf.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audioformat SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audiochannels SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(ad.audiolanguage SEPARATOR ' ') FROM audio_data ad WHERE ad.releases_id = r.id), '')",
                "COALESCE((SELECT GROUP_CONCAT(rs.subslanguage SEPARATOR ' ') FROM release_subtitles rs WHERE rs.releases_id = r.id), '')",
                "CONCAT(cp.title, ' > ', c.title)",
            ];
    }

    /** @return list<string>|null */
    public static function aggregateExpressionsNative(bool $isSqlite): ?array
    {
        static $ffi;
        $ffi ??= RustPipelineLibrary::resolveDomain('search', 'ssize_t nntmux_search_projection_aggregates(uint32_t sqlite, char *out, size_t cap);') ?? false;
        if ($ffi === false) {
            return null;
        }
        try {
            $cap = 0;
            $scratch = RustPipelineLibrary::obtainScratch($ffi, 1024, $cap);
            if ($scratch === null) {
                return null;
            }
            $written = $ffi->nntmux_search_projection_aggregates($isSqlite ? 1 : 0, $scratch, $cap);
            if (! is_int($written) || $written <= 0 || $written > $cap) {
                return null;
            }
            $parts = explode("\0", FFI::string($scratch, $written));

            return count($parts) === 6 ? $parts : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function mediaInfoQuery(): Builder
    {
        return DB::table('media_infos as selected_media_info')
            ->select([
                'selected_media_info.releases_id',
                'selected_media_info.movie_name',
                'selected_media_info.file_name',
                'selected_media_info.unique_id',
            ])
            ->whereRaw(
                'selected_media_info.id = (SELECT MIN(candidate_media_info.id) FROM media_infos candidate_media_info WHERE candidate_media_info.releases_id = selected_media_info.releases_id)'
            );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forId(int $releaseId): ?array
    {
        if ($releaseId <= 0) {
            return null;
        }

        $row = self::query()->where('r.id', $releaseId)->first();

        return $row === null ? null : ReleaseSearchIndexDocument::normalize((array) $row);
    }

    /**
     * Rust stays opt-in for the Wave 40.5 summary: the FFI round trip misses the 1.5x gate; see
     * tests/Unit/RustManticoreElasticSearchServiceCircuitW40ParityTest.php.
     */
    public const bool W405_RUST_FFI_DEFAULT = false;

    /** Columns that link a release to external media metadata. */
    private const array MEDIA_ID_KEYS = ['imdbid', 'tmdbid', 'tvdb', 'videos_id', 'anidbid'];

    private static ?bool $useW405RustFfi = null;

    public static function useW405RustFfi(?bool $use = true): void
    {
        self::$useW405RustFfi = $use;
    }

    public static function usesW405RustFfi(): bool
    {
        return self::$useW405RustFfi ?? self::W405_RUST_FFI_DEFAULT;
    }

    public static function resetW405RustFfi(): void
    {
        self::$useW405RustFfi = null;
    }

    /**
     * Summarizes hydrated release rows. A value is a positive identifier when it is a positive
     * int, or a string of 1-18 ASCII digits that is not all zeros (database drivers may return
     * numeric columns as strings; IMDb ids keep leading zeros). Rows that are not arrays or lack a
     * positive `id` are invalid and excluded from the other counts. `searchname` counts when it is
     * a string that is non-empty after trim(); `categories_id`, the media id columns and `size`
     * use the identifier rule, so a missing or non-positive size is a zero size.
     *
     * @param  array<array-key, mixed>  $rows
     * @return array{total_rows: int, with_searchname: int, with_category: int, with_external_media_id: int, zero_size: int, invalid_rows: int}
     */
    public static function summarizeHydrationBatchCompleteness(array $rows): array
    {
        return self::usesW405RustFfi()
            ? self::summarizeHydrationBatchCompletenessNative($rows)
            : self::summarizeHydrationBatchCompletenessPhp($rows);
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{total_rows: int, with_searchname: int, with_category: int, with_external_media_id: int, zero_size: int, invalid_rows: int}
     */
    private static function summarizeHydrationBatchCompletenessNative(array $rows): array
    {
        $parts = [];
        foreach ($rows as $row) {
            if (! \is_array($row)) {
                array_push($parts, '', '', '', '', '', '', '', '', '');

                continue;
            }
            $name = $row['searchname'] ?? null;
            $parts[] = self::identifierPart($row['id'] ?? null);
            $parts[] = \is_string($name) ? $name : '';
            $parts[] = self::identifierPart($row['categories_id'] ?? null);
            foreach (self::MEDIA_ID_KEYS as $key) {
                $parts[] = self::identifierPart($row[$key] ?? null);
            }
            $parts[] = self::identifierPart($row['size'] ?? null);
        }
        $fields = SearchService::evaluateW405Native(5, $parts);
        if ($fields === null || \count($fields) !== 6) {
            return self::summarizeHydrationBatchCompletenessPhp($rows);
        }

        return [
            'total_rows' => (int) $fields[0],
            'with_searchname' => (int) $fields[1],
            'with_category' => (int) $fields[2],
            'with_external_media_id' => (int) $fields[3],
            'zero_size' => (int) $fields[4],
            'invalid_rows' => (int) $fields[5],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return array{total_rows: int, with_searchname: int, with_category: int, with_external_media_id: int, zero_size: int, invalid_rows: int}
     */
    private static function summarizeHydrationBatchCompletenessPhp(array $rows): array
    {
        $named = $categorized = $media = $zero = $invalid = 0;
        foreach ($rows as $row) {
            if (! \is_array($row) || ! self::isIdentifier($row['id'] ?? null)) {
                $invalid++;

                continue;
            }
            $name = $row['searchname'] ?? null;
            if (\is_string($name) && trim($name) !== '') {
                $named++;
            }
            if (self::isIdentifier($row['categories_id'] ?? null)) {
                $categorized++;
            }
            foreach (self::MEDIA_ID_KEYS as $key) {
                if (self::isIdentifier($row[$key] ?? null)) {
                    $media++;
                    break;
                }
            }
            if (! self::isIdentifier($row['size'] ?? null)) {
                $zero++;
            }
        }

        return [
            'total_rows' => \count($rows),
            'with_searchname' => $named,
            'with_category' => $categorized,
            'with_external_media_id' => $media,
            'zero_size' => $zero,
            'invalid_rows' => $invalid,
        ];
    }

    private static function isIdentifier(mixed $value): bool
    {
        if (\is_int($value)) {
            return $value > 0;
        }

        return \is_string($value) && \strlen($value) <= 18 && preg_match('/^[0-9]*[1-9][0-9]*$/D', $value) === 1;
    }

    /** Rust applies the string rule, so positive ints travel as `1`. */
    private static function identifierPart(mixed $value): string
    {
        if (\is_int($value)) {
            return $value > 0 ? '1' : '0';
        }

        return \is_string($value) ? $value : '';
    }

    /** Latest timestamp {@see self::projectionPlanBatch()} keeps (2100-01-01 UTC). */
    private const int W59_MAX_TIMESTAMP = 4102444800;

    public const string W59_FFI_CDEF = 'int64_t nntmux_w595_release_projection_plan(size_t count, int64_t flags, const char *data, size_t data_len, const char *ints, size_t ints_len, int64_t *out, size_t out_cap);';

    /** Enabled: paired common/uncommon mean and p99 gains clear 1.5x (see the W59.5 parity test's QUALIFICATION). */
    public const bool W59_RUST_FFI_DEFAULT = true;

    /**
     * Wave 59.5 routing and FFI cache, kept in function-local statics (no static properties, so every W59 class
     * follows the same shape). Without $op it reports the routing; 'set' swaps the override for $val and returns the
     * previous one; 'reset' replaces the cached handle with $val (null re-resolves, false disables); 'buffer' returns
     * `[FFI, int64_t[], capacity]` with room for at least $val words, or null without the library.
     */
    private static function w59RustFfiState(?string $op = null, mixed $val = null): mixed
    {
        static $enabled = null;
        static $ffi = null;
        static $out = null;
        static $outCap = 0;

        switch ($op) {
            case 'set':
                $previous = $enabled;
                $enabled = $val;

                return $previous;
            case 'reset':
                $ffi = $val;
                $out = null;
                $outCap = 0;

                return null;
            case 'buffer':
                if ($ffi === null) {
                    $path = getenv('NNTMUX_PIPELINE_FFI_LIB');
                    $ffi = RustPipelineLibrary::resolveDomain('w59_5', self::W59_FFI_CDEF, \is_string($path) && $path !== '' ? $path : null) ?? false;
                }
                if (! $ffi instanceof FFI || ! \is_int($val)) {
                    return null;
                }
                if ($out === null || $outCap < $val) {
                    $outCap = max(1024, $val);
                    $out = $ffi->new('int64_t['.$outCap.']');
                }

                return [$ffi, $out, $outCap];
            default:
                return $enabled ?? self::W59_RUST_FFI_DEFAULT;
        }
    }

    public static function usesW59RustFfi(): bool
    {
        return self::w59RustFfiState() === true;
    }

    /**
     * Runs $callback with projectionPlanBatch() routed to Rust ($enabled) or PHP, then restores the previous routing.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withW59RustFfi(bool $enabled, callable $callback): mixed
    {
        $previous = self::w59RustFfiState('set', $enabled);

        try {
            return $callback();
        } finally {
            self::w59RustFfiState('set', $previous);
        }
    }

    /**
     * Exactly $words output words from the Wave 59.5 Rust helper over $count NUL-joined records, or null
     * to fall back to PHP.
     *
     * @return list<int>|null
     */
    private static function w59Words(int $count, string $data, int $words): ?array
    {
        try {
            $handle = self::w59RustFfiState('buffer', $words);
            if (! \is_array($handle)) {
                return null;
            }
            [$ffi, $out, $cap] = $handle;
            $written = $ffi->nntmux_w595_release_projection_plan($count, 0, $data, \strlen($data), '', 0, $out, $cap);
            if ($written !== $words) {
                return null;
            }
            /** @var array<int, int>|false $values */
            $values = unpack('q*', FFI::string($out, $written * 8));

            return \is_array($values) && \count($values) === $words ? array_values($values) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Batch release projection over `id\tsearchname\tsize\tpostdate\tadddate\tgrabs\tcategories_id\tpasswordstatus`.
     * Nine words per record: `[id, name_len, tokens, size, postdate, adddate, grabs, score, flags]`. Integers are
     * trimmed optional `-` plus 1-18 digits (empty reads 0). The id is 0 unless positive; name length and the
     * `[A-Za-z0-9]+` token count cover the trimmed searchname. Size floors at 0, dates clamp to 0-4102444800 and a post
     * date after a positive add date drops to it, grabs clamp to 0-2147483647 and categories floor at 0. Score (0
     * without an id) is `5 * min(tokens, 20)`, plus 10 for at least 1 MiB, `min(grabs, 1000) / 10`, 15 for a category
     * and 5 for a post date, minus 50 for a positive password status. Flags: 1 id not positive, 2 empty searchname, 4
     * negative size, 8 clamped date, 16 post date after add date, 32 clamped grabs, 64 negative category, 128 invalid
     * integer field, 256 not exactly eight fields.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function projectionPlanBatch(array $records): array
    {
        return self::usesW59RustFfi() ? self::projectionPlanBatchNative($records) : self::projectionPlanBatchPhp($records);
    }

    /**
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function projectionPlanBatchPhp(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $fields = explode("\t", $record);
            $flags = \count($fields) === 8 ? 0 : 256;
            $invalid = false;
            $id = self::w59Integer($fields[0], $invalid);
            if ($id <= 0) {
                $flags |= 1;
            }
            $name = trim($fields[1] ?? '');
            if ($name === '') {
                $flags |= 2;
            }
            $tokens = (int) preg_match_all('/[A-Za-z0-9]+/', $name);
            $size = self::w59Integer($fields[2] ?? '', $invalid);
            if ($size < 0) {
                $size = 0;
                $flags |= 4;
            }
            $post = self::w59Integer($fields[3] ?? '', $invalid);
            if ($post < 0 || $post > self::W59_MAX_TIMESTAMP) {
                $post = max(0, min(self::W59_MAX_TIMESTAMP, $post));
                $flags |= 8;
            }
            $add = self::w59Integer($fields[4] ?? '', $invalid);
            if ($add < 0 || $add > self::W59_MAX_TIMESTAMP) {
                $add = max(0, min(self::W59_MAX_TIMESTAMP, $add));
                $flags |= 8;
            }
            if ($add > 0 && $post > $add) {
                $post = $add;
                $flags |= 16;
            }
            $grabs = self::w59Integer($fields[5] ?? '', $invalid);
            if ($grabs < 0 || $grabs > 2147483647) {
                $grabs = max(0, min(2147483647, $grabs));
                $flags |= 32;
            }
            $category = self::w59Integer($fields[6] ?? '', $invalid);
            if ($category < 0) {
                $category = 0;
                $flags |= 64;
            }
            $password = self::w59Integer($fields[7] ?? '', $invalid);
            if ($invalid) {
                $flags |= 128;
            }
            $score = $id > 0
                ? min($tokens, 20) * 5 + ($size >= 1048576 ? 10 : 0) + intdiv(min($grabs, 1000), 10)
                    + ($category > 0 ? 15 : 0) - ($password > 0 ? 50 : 0) + ($post > 0 ? 5 : 0)
                : 0;
            array_push($out, max(0, $id), \strlen($name), $tokens, $size, $post, $add, $grabs, $score, $flags);
        }

        return $out;
    }

    /**
     * Rust batch over the NUL-joined records; falls back to PHP without the library or when a record holds
     * a NUL.
     *
     * @param  list<string>  $records
     * @return list<int>
     */
    public static function projectionPlanBatchNative(array $records): array
    {
        $count = \count($records);
        if ($count === 0) {
            return [];
        }

        return self::w59Words($count, implode("\0", $records), 9 * $count) ?? self::projectionPlanBatchPhp($records);
    }

    /** Trimmed optional `-` plus 1-18 digits; empty reads 0, anything else sets $invalid and reads 0. */
    private static function w59Integer(string $field, bool &$invalid): int
    {
        $field = trim($field);
        if ($field === '') {
            return 0;
        }
        $negative = $field[0] === '-';
        $digits = $negative ? substr($field, 1) : $field;
        if ($digits === '' || \strlen($digits) > 18 || ! ctype_digit($digits)) {
            $invalid = true;

            return 0;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }
}
